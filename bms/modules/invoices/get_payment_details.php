<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';

if (isset($_GET['invoice_id'])) {
    $invoice_id = intval($_GET['invoice_id']);

    if ($invoice_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
        exit();
    }

    // Get invoice header with amount_paid
    $invSql = "SELECT total_amount, amount_paid, pay_status FROM invoices WHERE invoice_id = ?";
    $invStmt = $conn->prepare($invSql);
    $invStmt->bind_param("i", $invoice_id);
    $invStmt->execute();
    $invResult = $invStmt->get_result();

    if ($invResult->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Invoice not found']);
        exit();
    }

    $invData = $invResult->fetch_assoc();
    $total_amount = floatval($invData['total_amount']);
    $amount_paid = floatval($invData['amount_paid']);
    $balance = $total_amount - $amount_paid;

    // Get all payment records for this invoice
    $sql = "SELECT p.*, u.name as processor_name
            FROM payments p
            LEFT JOIN users u ON p.pay_by = u.id
            WHERE p.invoice_id = ?
            ORDER BY p.payment_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];

    if ($result && $result->num_rows > 0) {
        while ($payment = $result->fetch_assoc()) {
            $payments[] = [
                'payment_id' => $payment['payment_id'],
                'amount_paid' => number_format($payment['amount_paid'], 2),
                'payment_method' => $payment['payment_method'],
                'payment_date' => date('d/m/Y H:i', strtotime($payment['payment_date'])),
                'processed_by' => isset($payment['processor_name']) ? $payment['processor_name'] : 'N/A',
                'pay_by' => $payment['pay_by'],
                'slip' => $payment['slip'] ?? null
            ];
        }
    }

    $response = [
        'success' => true,
        'payments' => $payments,
        'total_paid' => number_format($amount_paid, 2),
        'total_amount' => number_format($total_amount, 2),
        'balance_due' => number_format($balance, 2),
        'pay_status' => $invData['pay_status']
    ];

    echo json_encode($response);
} else {
    echo json_encode(['success' => false, 'error' => 'Invoice ID is required']);
}
?>