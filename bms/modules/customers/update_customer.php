<?php
require_once __DIR__ . '/../../config/paths.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Start session
session_start();
// Include necessary files
require_once BASE_PATH . 'includes/db_connection.php';
// CSRF Token Validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_message'] = "Invalid CSRF token. Please try again.";
    header("Location: " . BASE_URL . "modules/customers/customer_list.php");
    exit();
}
// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    $_SESSION['error_message'] = "Please log in to continue.";
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
// Get the current user's ID for logging
$current_user_id = $_SESSION['user_id']; // Make sure this matches your session variable name for user ID

// Validate required parameters
if (!isset($_POST['customer_id'])) {
    $_SESSION['error_message'] = "Invalid customer ID.";
    header("Location: " . BASE_URL . "modules/customers/customer_list.php");
    exit();
}
$customer_id = intval($_POST['customer_id']);

// Basic input validation
$errors = [];
// Name validation
$name = trim($_POST['name'] ?? '');
if (empty($name) || strlen($name) < 2 || strlen($name) > 100) {
    $errors[] = "Invalid name. Must be between 2-100 characters.";
}
// Email validation
$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email address format.";
}
// Phone validation
$phone = trim($_POST['phone'] ?? '');
if (empty($phone) || !preg_match('/^(\+94|0)[0-9]{9}$/', $phone)) {
    $errors[] = "Invalid phone number. Must be a valid Sri Lankan phone number.";
}
// Address validation
$address = trim($_POST['address'] ?? '');
if (empty($address) || strlen($address) < 5 || strlen($address) > 255) {
    $errors[] = "Invalid address. Must be between 5-255 characters.";
}
// Status validation
$status = trim($_POST['status'] ?? '');
if (!in_array($status, ['Active', 'Inactive'])) {
    $errors[] = "Invalid status. Must be either Active or Inactive.";
}

// Store original status for comparison
$original_status = isset($_POST['original_status']) ? $_POST['original_status'] : '';

// If there are validation errors, redirect back to edit page
if (!empty($errors)) {
    // Construct URL with existing values for form repopulation
    $redirect_url = sprintf(
        "edit_customer.php?id=%d&name=%s&email=%s&phone=%s&address=%s&status=%s", 
        $customer_id, 
        urlencode($name), 
        urlencode($email), 
        urlencode($phone), 
        urlencode($address), 
        urlencode($status)
    );
    
    $_SESSION['error_message'] = implode(" ", $errors);
    header("Location: " . $redirect_url);
    exit();
}

try {
    // Start a transaction
    $conn->begin_transaction();
    
    // Update customer information
    $stmt = $conn->prepare("UPDATE customers SET
        name = ?,
        email = ?,
        phone = ?,
        address = ?,
        status = ?
        WHERE customer_id = ?");

    $stmt->bind_param("sssssi",
        $name, $email, $phone, $address, $status, $customer_id);
    
    // Execute the update
    $stmt->execute();
    $stmt->close();
    
    // Determine action type based on status change
    $action_type = "edit_customer";
    if ($status == "Inactive" && $original_status == "Active") {
        $action_type = "deactivate_customer";
    } elseif ($status == "Active" && $original_status == "Inactive") {
        $action_type = "activate_customer";
    }
    
    // Format log details based on your example format
    $log_details = "User ID #{$customer_id} ({$name}) was ";
    if ($action_type == "edit_customer") {
        $log_details .= "updated by user ID #{$current_user_id}";
    } elseif ($action_type == "deactivate_customer") {
        $log_details .= "deactivated by user ID #{$current_user_id}";
    } elseif ($action_type == "activate_customer") {
        $log_details .= "activated by user ID #{$current_user_id}";
    }
    
    // Insert log entry
    $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, 0, ?, NOW())");
    $log_stmt->bind_param("iss", $current_user_id, $action_type, $log_details);
    $result = $log_stmt->execute();
    
    // Add debug information
    if (!$result) {
        error_log("Failed to insert log entry: " . $log_stmt->error);
    }
    
    $log_stmt->close();
    
    // Commit the transaction
    $conn->commit();
    
    // Set success message
    $_SESSION['success_message'] = "Customer profile updated successfully.";
    
} catch (Exception $e) {
    // Rollback the transaction if there's an error
    $conn->rollback();
    
    // Log the error for debugging
    error_log("Error in customer update: " . $e->getMessage());
    
    // Set error message
    $_SESSION['error_message'] = "An unexpected error occurred: " . $e->getMessage();
} finally {
    $conn->close();
    
    // Redirect to customers list page
    header("Location: " . BASE_URL . "modules/customers/customer_list.php");
    exit();
}
?>