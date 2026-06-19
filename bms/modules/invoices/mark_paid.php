<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;

    if ($invoice_id <= 0) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Invalid invoice ID']);
        exit();
    }

    // File upload handling
    $filename = null;
    $uploadPath = null;

    if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['payment_slip'];
        $filename = 'payment_slip_' . $invoice_id . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        $uploadDir = BASE_PATH . 'uploads/payments/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $uploadPath = $uploadDir . $filename;
    }

    $pay_by = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

    $conn->begin_transaction();

    try {
        // Move uploaded file
        if ($filename && $uploadPath) {
            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new Exception('Failed to upload payment slip');
            }
        }

        // Get invoice details
        $invStmt = $conn->prepare("SELECT total_amount, amount_paid, pay_status FROM invoices WHERE invoice_id = ?");
        $invStmt->bind_param("i", $invoice_id);
        $invStmt->execute();
        $invResult = $invStmt->get_result();

        if ($invResult->num_rows === 0) {
            throw new Exception('Invoice not found');
        }

        $invoiceData = $invResult->fetch_assoc();
        $total_amount = floatval($invoiceData['total_amount']);
        $currently_paid = floatval($invoiceData['amount_paid']);
        $remaining = $total_amount - $currently_paid;

        // Amount from form - default to remaining if not provided or if value exceeds remaining
        $amount_paid_this = isset($_POST['amount']) ? floatval($_POST['amount']) : $remaining;

        if ($amount_paid_this <= 0) {
            throw new Exception('Payment amount must be greater than 0');
        }

        if ($amount_paid_this > $remaining) {
            throw new Exception('Payment amount exceeds remaining balance of ' . number_format($remaining, 2));
        }

        $currentDateTime = date('Y-m-d H:i:s');
        $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'Cash';

        // Insert payment record
        $paymentStmt = $conn->prepare("INSERT INTO payments (invoice_id, amount_paid, payment_method, payment_date, pay_by, slip) VALUES (?, ?, ?, ?, ?, ?)");
        $paymentStmt->bind_param("idssis", $invoice_id, $amount_paid_this, $payment_method, $currentDateTime, $pay_by, $filename);
        if (!$paymentStmt->execute()) {
            throw new Exception('Failed to record payment: ' . $conn->error);
        }

        // Recalculate total amount_paid from all payments
        $sumStmt = $conn->prepare("SELECT SUM(amount_paid) as total_paid FROM payments WHERE invoice_id = ?");
        $sumStmt->bind_param("i", $invoice_id);
        $sumStmt->execute();
        $sumResult = $sumStmt->get_result();
        $sumRow = $sumResult->fetch_assoc();
        $new_amount_paid = floatval($sumRow['total_paid']);

        // Determine new pay_status and status
        if ($new_amount_paid >= $total_amount) {
            // Fully paid
            $new_pay_status = 'paid';
            $new_status = 'done';

            // Update all items as done/paid
            $itemsStmt = $conn->prepare("UPDATE invoice_items SET status = 'done', pay_status = 'paid' WHERE invoice_id = ?");
            $itemsStmt->bind_param("i", $invoice_id);
            $itemsStmt->execute();
        } elseif ($new_amount_paid > 0) {
            // Partial payment
            $new_pay_status = 'partial';
            $new_status = 'pending';
        } else {
            $new_pay_status = 'unpaid';
            $new_status = 'pending';
        }

        // Update invoice header
        $invoiceStmt = $conn->prepare("UPDATE invoices SET amount_paid = ?, pay_status = ?, status = ? WHERE invoice_id = ?");
        $invoiceStmt->bind_param("dssi", $new_amount_paid, $new_pay_status, $new_status, $invoice_id);
        if (!$invoiceStmt->execute()) {
            throw new Exception('Failed to update invoice: ' . $conn->error);
        }

        $conn->commit();

        // Log the action
        try {
            $action_type = "mark_paid_invoice";
            $log_details = "Invoice ID #$invoice_id - Payment of $amount_paid_this received. Total paid: $new_amount_paid / $total_amount. Status: $new_pay_status";
            $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                       VALUES (?, ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("isss", $pay_by, $action_type, $invoice_id, $log_details);
            $log_stmt->execute();
            $log_stmt->close();
        } catch (Exception $log_e) {
            error_log("Failed to log activity: " . $log_e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'amount_paid' => $new_amount_paid,
            'pay_status' => $new_pay_status,
            'remaining' => $total_amount - $new_amount_paid
        ]);

    } catch (Exception $e) {
        $conn->rollback();

        // Remove uploaded file if payment failed
        if ($filename && $uploadPath && file_exists($uploadPath)) {
            unlink($uploadPath);
        }

        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => $e->getMessage()]);
    }

} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
}
?>