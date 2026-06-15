<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

  
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php'; // Include helper functions

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_message'] = "Invalid request. Please try again.";
    header("Location: profile.php");
    exit();
}

// Get and validate inputs
$user_id = $_SESSION['user_id'];
$old_password = $_POST['old_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// Validate input
if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
    $_SESSION['error_message'] = "All password fields are required.";
    header("Location: profile.php");
    exit();
}

if ($new_password !== $confirm_password) {
    $_SESSION['error_message'] = "New password and confirm password do not match.";
    header("Location: profile.php");
    exit();
}

// Password strength validation
if (strlen($new_password) < 8 || 
    !preg_match('/[a-z]/', $new_password) || 
    !preg_match('/[A-Z]/', $new_password) || 
    !preg_match('/\d/', $new_password) || 
    !preg_match('/[^a-zA-Z\d]/', $new_password)) {
    
    $_SESSION['error_message'] = "Password must be at least 8 characters and include at least one uppercase letter, one lowercase letter, one number, and one special character.";
    header("Location: profile.php");
    exit();
}

// Get current password from database
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "User not found.";
    header("Location: profile.php");
    exit();
}

$user = $result->fetch_assoc();
$current_hashed_password = $user['password'];

// Check if current password is correct
if (!password_verify($old_password, $current_hashed_password) && 
    $current_hashed_password !== $old_password) { // Fallback for non-hashed passwords like '12345678'
    
    $_SESSION['error_message'] = "Current password is incorrect.";
    header("Location: profile.php");
    exit();
}

// Hash the new password
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

// Update the password
try {
    $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $_SESSION['success_message'] = "Password updated successfully.";
    } else {
        throw new Exception("No changes were made or an error occurred.");
    }
} catch (Exception $e) {
    $_SESSION['error_message'] = "Error updating password: " . $e->getMessage();
}

header("Location: profile.php");
exit();
?>