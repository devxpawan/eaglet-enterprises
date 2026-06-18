<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

// Include database connection
require_once BASE_PATH . 'includes/db_connection.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $status = $_POST['status'] ?? '';
    
    // Validate required fields
    if (empty($name) || empty($email) || empty($status)) {
        $_SESSION['error_message'] = "All fields are required!";
        header("Location: profile.php");
        exit();
    }
    
    // Get user ID from session
    $userId = $_SESSION['user_id'];
    
    // Check if the table has a phone column
    $checkColumnSql = "SHOW COLUMNS FROM users LIKE 'phone'";
    $checkColumnResult = $conn->query($checkColumnSql);
    $hasPhoneColumn = $checkColumnResult->num_rows > 0;
    
    // Prepare SQL statement based on whether phone column exists
    if ($hasPhoneColumn) {
        // If phone column exists, include it in the update
        $phone = $_POST['phone'] ?? '';
        $sql = "UPDATE users SET 
                name = ?, 
                email = ?, 
                phone = ?, 
                status = ?, 
                updated_at = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", $name, $email, $phone, $status, $userId);
    } else {
        // If phone column doesn't exist, exclude it from the update
        $sql = "UPDATE users SET 
                name = ?, 
                email = ?, 
                status = ?, 
                updated_at = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $name, $email, $status, $userId);
    }
    
    if ($stmt === false) {
        $_SESSION['error_message'] = "Error preparing statement: " . $conn->error;
        header("Location: profile.php");
        exit();
    }
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Profile updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating profile: " . $conn->error;
    }
    
    $stmt->close();
    header("Location: profile.php");
    exit();
}

// If not POST request, redirect to profile page
header("Location: profile.php");
exit();
?>