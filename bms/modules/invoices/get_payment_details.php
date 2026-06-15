<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session
session_start();

// Set content type for AJAX responses
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once BASE_PATH . 'includes/db_connection.php';

// Check if invoice ID is provided
if (isset($_GET['invoice_id'])) {
    $invoice_id = intval($_GET['invoice_id']);
    
    if ($invoice_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
        exit();
    }
    
    // Query to get payment details
    $sql = "SELECT p.*, u.name as processor_name, i.slip
            FROM payments p
            LEFT JOIN users u ON p.pay_by = u.id
            LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
            WHERE p.invoice_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $payment = $result->fetch_assoc();
        
        // Format data for response
        $response = [
            'success' => true,
            'payment_id' => $payment['payment_id'],
            'invoice_id' => $payment['invoice_id'],
            'amount_paid' => number_format($payment['amount_paid'], 2),
            'payment_method' => $payment['payment_method'],
            'payment_date' => date('d/m/Y H:i', strtotime($payment['payment_date'])),
            'pay_by' => $payment['pay_by'],
            'processed_by' => isset($payment['processor_name']) ? $payment['processor_name'] : 'N/A',
            'slip' => $payment['slip']
        ];
        
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'error' => 'No payment record found for this invoice']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invoice ID is required']);
}
?>