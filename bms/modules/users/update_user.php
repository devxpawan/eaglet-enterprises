<?php
require_once __DIR__ . '/../../config/paths.php';

// update_user.php
// Enable strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering immediately
ob_start();

// Start session
session_start();

// Require database connection and functions
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output
    ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

// Admin-only access
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    ob_end_clean();
    header("Location: " . BASE_URL . "index.php");
    exit();
}

// Function to handle errors and redirect
function handleError($errors, $user_id = 0) {
    $_SESSION['error_message'] = is_array($errors) ? implode("<br>", $errors) : $errors;
    
    // Clear output buffer
    ob_end_clean();
    
    if ($user_id) {
        header("Location: " . BASE_URL . "modules/users/add_user.php");
    } else {
        header("Location: " . BASE_URL . "modules/users/add_user.php");
    }
    exit();
}

// Validate and sanitize input
try {
    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new Exception("Security token validation failed. Please refresh the page and try again.");
    }

    // Unset the current CSRF token to prevent reuse
    unset($_SESSION['csrf_token']);

    // Sanitize and validate inputs
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $mobile = trim(filter_input(INPUT_POST, 'mobile', FILTER_SANITIZE_SPECIAL_CHARS));
    $nic = trim(filter_input(INPUT_POST, 'nic', FILTER_SANITIZE_SPECIAL_CHARS));
    $address = trim(filter_input(INPUT_POST, 'address', FILTER_SANITIZE_SPECIAL_CHARS));
    $status = 'active';
    $role_id = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT) ?: 0;
    $position_id = filter_input(INPUT_POST, 'position_id', FILTER_VALIDATE_INT) ?: null;
    // If position_id is 0 or empty, set to null for optional field
    if (empty($position_id)) $position_id = null;

    // Input validation
    $errors = [];

    // Name validation
    if (empty($name)) {
        $errors[] = "Name is required.";
    }

    // Email validation
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    }

    // Mobile validation (optional, adjust regex as needed)
    if (!empty($mobile) && !preg_match('/^[0-9]{10}$/', $mobile)) {
        $errors[] = "Invalid mobile number.";
    }

    // Role validation
    if (empty($role_id)) {
        $errors[] = "Role selection is required.";
    }

    // Password handling 
    $password = $_POST['password'] ?? null;

    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long.";
        }
    }

    // Handle username field
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    
    // Validate username
    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $errors[] = "Username must be 3-50 characters and can only contain letters, numbers, and underscores.";
    }

    // Check for duplicate email
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = "Email already in use.";
    }
    $stmt->close();

    // Check for duplicate username
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = "Username already in use.";
    }
    $stmt->close();

    // If validation errors exist, handle and redirect
    if (!empty($errors)) {
        handleError($errors);
    }

    // Begin database transaction
    $conn->begin_transaction();

    // Prepare insert query with username and position_id
    if (!empty($password)) {
        // Insert with password 
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (name, username, email, mobile, nic, address, status, role_id, position_id, password, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssssiss", 
            $name, $username, $email, $mobile, $nic, $address, 
            $status, $role_id, $position_id, $hashed_password
        );
    } else {
        // Insert without password
        $stmt = $conn->prepare("INSERT INTO users (name, username, email, mobile, nic, address, status, role_id, position_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssssii", 
            $name, $username, $email, $mobile, $nic, $address, 
            $status, $role_id, $position_id
        );
    }

    // Execute insert
    if ($stmt->execute()) {
        $conn->commit();
        
        // Clear output buffer
        ob_end_clean();
        
        // Set success message
        $_SESSION['success_message'] = "User added successfully.";
        header("Location: " . BASE_URL . "modules/users/users.php");
        exit();
    } else {
        // Rollback transaction on failure
        $conn->rollback();
        handleError("Failed to add user.", 0);
    }

} catch (Exception $e) {
    // Handle any unexpected errors
    handleError($e->getMessage());
} finally {
    // Ensure connection is closed
    $conn->close();
    
    // Ensure output buffer is sent if not already done
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
?>