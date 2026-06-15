<?php
require_once __DIR__ . '/../../config/paths.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Start session
session_start();
// Include database connection
require_once BASE_PATH . 'includes/db_connection.php';
// JSON response function
function sendJsonResponse($success, $message, $newStatus = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'new_status' => $newStatus
    ]);
    exit();
}
// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    sendJsonResponse(false, "Please log in to continue.");
}
// Only Admin and Moderator can toggle customer status
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 3])) {
    sendJsonResponse(false, "Access denied. Admin or Moderator privileges required.");
}
// Validate POST parameters
if (!isset($_POST['customer_id']) || !isset($_POST['action'])) {
    sendJsonResponse(false, "Invalid request parameters.");
}
$customer_id = intval($_POST['customer_id']);
$action = $_POST['action'];
// Get current user ID from session
$user_id = $_SESSION['user_id']; // Make sure this is set during login

// Validate action
if (!in_array($action, ['activate', 'deactivate'])) {
    sendJsonResponse(false, "Invalid action specified.");
}
// Prepare the update query
try {
    // Start transaction
    $conn->begin_transaction();
    
    // Determine new status based on action
    $new_status = ($action === 'activate') ? 'Active' : 'Inactive';
    
    // First, get the customer name for the log entry
    $stmt_get_name = $conn->prepare("SELECT name FROM customers WHERE customer_id = ?");
    $stmt_get_name->bind_param("i", $customer_id);
    $stmt_get_name->execute();
    $result = $stmt_get_name->get_result();
    $customer_name = "";
    if ($row = $result->fetch_assoc()) {
        $customer_name = $row['name'];
    }
    $stmt_get_name->close();
    
    // Prepare and execute update statement for customer status
    $stmt = $conn->prepare("UPDATE customers SET status = ? WHERE customer_id = ?");
    $stmt->bind_param("si", $new_status, $customer_id);
    
    if ($stmt->execute()) {
        // Check if any rows were actually updated
        if ($stmt->affected_rows > 0) {
            // Determine action type for logging
            $action_type = ($action === 'activate') ? 'activate_customer' : 'deactivate_customer';
            
            // Create details message for log
            $details = "User ID #{$customer_id} ({$customer_name}) was " . 
                      ($action === 'activate' ? "activated" : "deactivated") . 
                      " by user ID #{$user_id}";
            
            // Insert into user_logs table
            $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, 0, ?)");
            $log_stmt->bind_param("iss", $user_id, $action_type, $details);
            
            if ($log_stmt->execute()) {
                // Commit transaction
                $conn->commit();
                
                $success_message = ($action === 'activate') 
                    ? "Customer successfully activated." 
                    : "Customer successfully deactivated.";
                
                sendJsonResponse(true, $success_message, $new_status);
            } else {
                // Rollback if log insert fails
                $conn->rollback();
                sendJsonResponse(false, "Failed to log the action.");
            }
            $log_stmt->close();
        } else {
            $conn->rollback();
            sendJsonResponse(false, "No customer found with the specified ID.");
        }
    } else {
        $conn->rollback();
        sendJsonResponse(false, "Failed to update customer status.");
    }
    // Close statement
    $stmt->close();
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn) {
        $conn->rollback();
    }
    // Log the error (in a real-world scenario, use proper logging)
    error_log("Toggle status error: " . $e->getMessage());
    sendJsonResponse(false, "An unexpected error occurred: " . $e->getMessage());
} finally {
    // Close connection
    if ($conn) {
        $conn->close();
    }
}
?>