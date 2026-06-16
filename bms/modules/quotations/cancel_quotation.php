<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quotation_id'])) {
    $quotation_id = (int)$_POST['quotation_id'];
    $cancel_reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : '';
    
    $sql = "UPDATE quotations SET status = 'Cancelled', cancel_reason = ? WHERE quotation_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $cancel_reason, $quotation_id);
    
    if ($stmt->execute()) {
        $_SESSION['quotation_success'] = "Quotation #$quotation_id has been cancelled.";
        
        // Log the action in user_logs table
        $user_id = $_SESSION['user_id'];
        $action_type = "cancel_quotation";
        $log_details = "Quotation ID #$quotation_id was cancelled by user ID #$user_id";
        if (!empty($cancel_reason)) {
            $log_details .= " Reason: $cancel_reason";
        }
        
        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("isss", $user_id, $action_type, $quotation_id, $log_details);
        $log_stmt->execute();
        $log_stmt->close();
    } else {
        $_SESSION['quotation_error'] = "Error cancelling quotation: " . $conn->error;
    }
    $stmt->close();
} elseif (isset($_GET['id'])) {
    // Fallback for GET requests (backward compatibility)
    $quotation_id = (int)$_GET['id'];
    
    $sql = "UPDATE quotations SET status = 'Cancelled' WHERE quotation_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $quotation_id);
    
    if ($stmt->execute()) {
        $_SESSION['quotation_success'] = "Quotation #$quotation_id has been cancelled.";
        
        // Log the action in user_logs table
        $user_id = $_SESSION['user_id'];
        $action_type = "cancel_quotation";
        $log_details = "Quotation ID #$quotation_id was cancelled by user ID #$user_id";
        
        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("isss", $user_id, $action_type, $quotation_id, $log_details);
        $log_stmt->execute();
        $log_stmt->close();
    } else {
        $_SESSION['quotation_error'] = "Error cancelling quotation: " . $conn->error;
    }
    $stmt->close();
}

header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
exit();
?>
