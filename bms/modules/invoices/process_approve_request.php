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

if (!isApprover()) {
    while (ob_get_level()) ob_end_clean();
    $_SESSION['message'] = "You do not have permission to approve edit requests.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    while (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "modules/invoices/edit_requests_list.php");
    exit();
}

try {
    if (empty($_POST['request_id']) || empty($_POST['action'])) {
        throw new Exception("Invalid request.");
    }

    $request_id = (int) $_POST['request_id'];
    $action = $_POST['action'];
    $approver_id = $_SESSION['user_id'];

    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new Exception("Invalid action.");
    }

    $stmt = $conn->prepare("SELECT r.*, i.status as invoice_status FROM invoice_edit_requests r JOIN invoices i ON r.invoice_id = i.invoice_id WHERE r.id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Request not found.");
    }

    $request = $result->fetch_assoc();
    $stmt->close();

    if ($request['status'] !== 'pending') {
        throw new Exception("This request has already been " . $request['status'] . ".");
    }

    if (!in_array($request['invoice_status'], ['pending', null, ''], true)) {
        throw new Exception("The invoice is no longer pending and cannot be edited.");
    }

    if ($action === 'approve') {
        $updateSql = "UPDATE invoice_edit_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($updateSql);
        $stmt->bind_param("ii", $approver_id, $request_id);
        $stmt->execute();

        $action_type = "approve_edit_request";
        $details = "Edit request #$request_id for Invoice #{$request['invoice_id']} was approved by user ID #$approver_id";
    } else {
        $reject_reason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : '';
        $updateSql = "UPDATE invoice_edit_requests SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), reject_reason = ? WHERE id = ?";
        $stmt = $conn->prepare($updateSql);
        $stmt->bind_param("isi", $approver_id, $reject_reason, $request_id);
        $stmt->execute();

        $action_type = "reject_edit_request";
        $details = "Edit request #$request_id for Invoice #{$request['invoice_id']} was rejected by user ID #$approver_id";
        if (!empty($reject_reason)) {
            $details .= ". Reason: $reject_reason";
        }
    }

    $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("isss", $approver_id, $action_type, $request['invoice_id'], $details);
    $log_stmt->execute();

    while (ob_get_level()) ob_end_clean();

    $_SESSION['message'] = "Edit request has been " . ($action === 'approve' ? 'approved' : 'rejected') . " successfully.";
    $_SESSION['message_type'] = "success";

    $status_filter = isset($_POST['status_filter']) ? $_POST['status_filter'] : 'pending';
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $redirect = "edit_requests_list.php?status=" . urlencode($status_filter) . "&page=" . $page;
    header("Location: " . $redirect);
    exit();
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    $_SESSION['message'] = $e->getMessage();
    $_SESSION['message_type'] = "danger";
    $status_filter = isset($_POST['status_filter']) ? $_POST['status_filter'] : 'pending';
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    header("Location: edit_requests_list.php?status=" . urlencode($status_filter) . "&page=" . $page);
    exit();
}
?>
