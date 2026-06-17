<?php
require_once __DIR__ . '/../../config/paths.php';

error_reporting(0);
while (ob_get_level()) ob_end_clean();
ob_start();

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    while (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    while (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    exit();
}

try {
    if (empty($_POST['invoice_id'])) {
        throw new Exception("Invoice ID is required.");
    }

    $invoice_id = (int) $_POST['invoice_id'];
    $user_id = $_SESSION['user_id'];
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

    $checkSql = "SELECT invoice_id, status FROM invoices WHERE invoice_id = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Invoice not found.");
    }

    $invoice = $result->fetch_assoc();
    if (!in_array($invoice['status'], ['pending', null, ''], true)) {
        throw new Exception("Only pending invoices can be edited.");
    }

    $checkPending = $conn->prepare("SELECT id FROM invoice_edit_requests WHERE invoice_id = ? AND requester_id = ? AND status = 'pending' LIMIT 1");
    $checkPending->bind_param("ii", $invoice_id, $user_id);
    $checkPending->execute();
    if ($checkPending->get_result()->num_rows > 0) {
        throw new Exception("You already have a pending edit request for this invoice.");
    }
    $checkPending->close();

    $insertSql = "INSERT INTO invoice_edit_requests (invoice_id, requester_id, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($insertSql);
    $reason = !empty($reason) ? $reason : null;
    $stmt->bind_param("iis", $invoice_id, $user_id, $reason);
    $stmt->execute();

    $action_type = "request_edit_invoice";
    $details = "User ID #$user_id requested to edit Invoice ID #$invoice_id";
    if (!empty($reason)) {
        $details .= ". Reason: $reason";
    }
    $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("isss", $user_id, $action_type, $invoice_id, $details);
    $log_stmt->execute();

    while (ob_get_level()) ob_end_clean();

    $_SESSION['message'] = "Your edit request for Invoice #$invoice_id has been submitted for approval.";
    $_SESSION['message_type'] = "success";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    $_SESSION['message'] = $e->getMessage();
    $_SESSION['message_type'] = "danger";
    $redirect_id = isset($invoice_id) ? $invoice_id : (isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0);
    if ($redirect_id > 0) {
        header("Location: " . BASE_URL . "modules/invoices/request_edit.php?id=" . $redirect_id);
    } else {
        header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    }
    exit();
}
?>
