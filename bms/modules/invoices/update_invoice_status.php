<?php
require_once __DIR__ . '/../../config/paths.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
    $pay_status = isset($_POST['pay_status']) ? $_POST['pay_status'] : '';

    if ($invoice_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
        exit();
    }

    $conn->begin_transaction();

    try {
        if ($pay_status === 'paid') {
            // Update invoice status
            $currentDate = date('Y-m-d');
            $user_id = $_SESSION['user_id'];
            
            $stmt = $conn->prepare("UPDATE invoices SET pay_status = 'paid', pay_date = ?, status = 'done', pay_by = ? WHERE invoice_id = ?");
            $stmt->bind_param("sii", $currentDate, $user_id, $invoice_id);
            $stmt->execute();

            // Update items
            $itemStmt = $conn->prepare("UPDATE invoice_items SET status = 'done', pay_status = 'paid' WHERE invoice_id = ?");
            $itemStmt->bind_param("i", $invoice_id);
            $itemStmt->execute();

            // Log activity
            $action_type = "mark_paid_invoice";
            $details = "Invoice ID #$invoice_id was marked as paid by user ID #$user_id";
            $logStmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
            $logStmt->bind_param("isss", $user_id, $action_type, $invoice_id, $details);
            $logStmt->execute();

            $conn->commit();
            echo json_encode(['success' => true]);
        } else {
            throw new Exception("Unsupported status update");
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
