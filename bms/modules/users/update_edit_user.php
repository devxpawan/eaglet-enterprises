<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    // Force redirect to login page
    header("Location: " . BASE_URL . "signin.php");
    exit(); // Stop execution immediately
}



// Include necessary files
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Check if the form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_message'] = "Invalid security token. Please try again.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

// Basic validation of required fields
$required_fields = ['name', 'username', 'email', 'user_id'];
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
        $_SESSION['error_message'] = ucfirst($field) . " is required.";
        header("Location: " . BASE_URL . "modules/users/users.php");
        exit();
    }
}

// Sanitize and validate inputs
$user_id = intval($_POST['user_id']);
$name = trim($_POST['name']);
$username = trim($_POST['username']);
$email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
$mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
$nic = isset($_POST['nic']) ? trim($_POST['nic']) : '';
$address = isset($_POST['address']) ? trim($_POST['address']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';
$position_id = isset($_POST['position_id']) && !empty($_POST['position_id']) ? intval($_POST['position_id']) : null;

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_message'] = "Invalid email format.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

// Validate username format
if (empty($username)) {
    $_SESSION['error_message'] = "Username is required.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}
if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
    $_SESSION['error_message'] = "Username must be 3-50 characters and can only contain letters, numbers, and underscores.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

// Check for duplicate email (excluding current user when editing)
$email_check_sql = "SELECT id FROM users WHERE email = ? AND id != ?";
$email_stmt = $conn->prepare($email_check_sql);
$email_stmt->bind_param("si", $email, $user_id);
$email_stmt->execute();
$email_result = $email_stmt->get_result();

if ($email_result->num_rows > 0) {
    $_SESSION['error_message'] = "Email address is already in use by another user.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    $email_stmt->close();
    exit();
}
$email_stmt->close();

// Check for duplicate username (excluding current user when editing)
$username_check_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
$username_stmt = $conn->prepare($username_check_sql);
$username_stmt->bind_param("si", $username, $user_id);
$username_stmt->execute();
$username_result = $username_stmt->get_result();

if ($username_result->num_rows > 0) {
    $_SESSION['error_message'] = "Username is already in use by another user.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    $username_stmt->close();
    exit();
}
$username_stmt->close();

// Prepare database operation for updating user
try {
    $conn->begin_transaction();
    
    // Check if user exists
    $user_check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $user_check_stmt->bind_param("i", $user_id);
    $user_check_stmt->execute();
    
    if ($user_check_stmt->get_result()->num_rows === 0) {
        throw new Exception("User not found.");
    }
    $user_check_stmt->close();
    
    // Fetch the original user data to track changes
    $original_user_stmt = $conn->prepare("
        SELECT name, username, email, status, mobile, nic, address, position_id
        FROM users WHERE id = ?
    ");
    $original_user_stmt->bind_param("i", $user_id);
    $original_user_stmt->execute();
    $original_result = $original_user_stmt->get_result();
    $original_user = $original_result->fetch_assoc();
    $original_user_stmt->close();
    
    // Track changes
    $changes = [];
    if ($original_user['name'] !== $name) {
        $changes[] = "Name changed from '{$original_user['name']}' to '{$name}'";
    }
    if ($original_user['username'] !== $username) {
        $changes[] = "Username changed from '{$original_user['username']}' to '{$username}'";
    }
    if ($original_user['email'] !== $email) {
        $changes[] = "Email changed from '{$original_user['email']}' to '{$email}'";
    }
    if (!empty($password)) {
        $changes[] = "Password was updated";
    }
    if ($original_user['mobile'] !== $mobile) {
        $changes[] = "Mobile changed from '{$original_user['mobile']}' to '{$mobile}'";
    }
    if ($original_user['nic'] !== $nic) {
        $changes[] = "NIC changed from '{$original_user['nic']}' to '{$nic}'";
    }
    if ($original_user['address'] !== $address) {
        $changes[] = "Address was updated";
    }
    if (($original_user['position_id'] ?? '') != ($position_id ?? '')) {
        // Get position names for better logging
        $pos_names_stmt = $conn->prepare("
            SELECT COALESCE((SELECT name FROM positions WHERE id = ?), 'None') as old_pos,
                   COALESCE((SELECT name FROM positions WHERE id = ?), 'None') as new_pos
        ");
        $pos_names_stmt->bind_param("ii", $original_user['position_id'], $position_id);
        $pos_names_stmt->execute();
        $pos_result = $pos_names_stmt->get_result();
        $pos_names = $pos_result->fetch_assoc();
        $pos_names_stmt->close();
        
        $changes[] = "Position changed from '{$pos_names['old_pos']}' to '{$pos_names['new_pos']}'";
    }
    
    // Prepare SQL based on whether password is being updated
    if (!empty($password)) {
        // Hash the password if it's being updated
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Update with new password, username, and position_id
        $update_stmt = $conn->prepare("
            UPDATE users 
            SET name = ?, username = ?, email = ?, password = ?, position_id = ?,
                mobile = ?, nic = ?, address = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->bind_param("ssssisssi", $name, $username, $email, $hashed_password, 
                                $position_id, $mobile, $nic, $address, $user_id);
    } else {
        // Update without changing password, but with username and position_id
        $update_stmt = $conn->prepare("
            UPDATE users 
            SET name = ?, username = ?, email = ?, position_id = ?,
                mobile = ?, nic = ?, address = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->bind_param("sssisssi", $name, $username, $email, $position_id, 
                                $mobile, $nic, $address, $user_id);
    }
    
    $update_stmt->execute();
    
    if ($update_stmt->affected_rows <= 0 && $update_stmt->error) {
        throw new Exception("Database error: " . $update_stmt->error);
    }
    
    $update_stmt->close();
    
    // Log the user edit action with detailed changes
    $logged_in_user_id = $_SESSION['user_id'];
    $action_type = 'edit_user';
    $inquiry_id = 0;
    
    // Prepare the details message
    $change_details = !empty($changes) ? 
        implode("; ", $changes) : 
        "No fields were changed";
        
    $details = "User ID #{$user_id} ({$name}) was updated by user ID #{$logged_in_user_id}. Changes: {$change_details}";
    $created_at = date('Y-m-d H:i:s');

    // Insert into user_logs table
    $log_stmt = $conn->prepare("
        INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $log_stmt->bind_param("isiss", $logged_in_user_id, $action_type, $inquiry_id, $details, $created_at);
    $log_result = $log_stmt->execute();

    // Check if logging failed but don't stop the transaction for logging failures
    if (!$log_result) {
        error_log("Failed to log user edit action: " . $log_stmt->error);
    }
    $log_stmt->close();
    
    $conn->commit();
    
    $_SESSION['success_message'] = "User updated successfully.";
    
    // Changed redirect to edit_user.php with user_id parameter
    header("Location: " . BASE_URL . "modules/users/edit_user.php?id=" . $user_id);
    
    // If we reach here, the header redirect didn't work
?>
<!DOCTYPE html>
<html>
<head>
    <title>Redirecting...</title>
    <script>
        // JavaScript fallback redirect - updated to edit_user.php
        window.location.href = "<?= BASE_URL ?>modules/users/edit_user.php?id=<?php echo $user_id; ?>";
    </script>
</head>
<body>
    <p>If you are not redirected automatically, please <a href="<?= BASE_URL ?>modules/users/edit_user.php?id=<?php echo $user_id; ?>">click here</a>.</p>
</body>
</html>
<?php
    exit();
    
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    
    // Try PHP redirect first - keep users.php for errors
    header("Location: " . BASE_URL . "modules/users/users.php");
    
    // If we reach here, the header redirect didn't work
?>
<!DOCTYPE html>
<html>
<head>
    <title>Redirecting...</title>
    <script>
        // JavaScript fallback redirect
        window.location.href = "<?= BASE_URL ?>modules/users/users.php";
    </script>
</head>
<body>
    <p>If you are not redirected automatically, please <a href="<?= BASE_URL ?>modules/users/users.php">click here</a>.</p>
</body>
</html>
<?php
    exit();
}

// Close the connection at the end of the script
$conn->close();
?>