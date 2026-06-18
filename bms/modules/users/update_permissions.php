<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!hasAccess('users.permissions')) {
    $_SESSION['error_message'] = "You do not have permission to manage user permissions.";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
if ($user_id <= 0) {
    $_SESSION['error_message'] = "Invalid user ID.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

// Verify user exists
$stmt = $conn->prepare("SELECT id, name, access FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "User not found.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}
$user = $result->fetch_assoc();
$stmt->close();

$access = isset($_POST['access']) && is_array($_POST['access']) ? $_POST['access'] : [];
$accessJson = json_encode(array_values($access));

// Track changes for logging
$oldAccess = [];
if (!empty($user['access'])) {
    $decoded = json_decode($user['access'], true);
    if (is_array($decoded)) {
        $oldAccess = $decoded;
    }
}

$changes = [];
$newPerms = array_diff($access, $oldAccess);
$removedPerms = array_diff($oldAccess, $access);
if (!empty($newPerms) || !empty($removedPerms)) {
    if (!empty($newPerms)) {
        $changes[] = "Added permissions: " . implode(', ', $newPerms);
    }
    if (!empty($removedPerms)) {
        $changes[] = "Removed permissions: " . implode(', ', $removedPerms);
    }
}

// Update database
$update_stmt = $conn->prepare("UPDATE users SET access = ?, updated_at = NOW() WHERE id = ?");
$update_stmt->bind_param("si", $accessJson, $user_id);
$update_stmt->execute();

if ($update_stmt->affected_rows <= 0 && $update_stmt->error) {
    $_SESSION['error_message'] = "Database error: " . $update_stmt->error;
    header("Location: " . BASE_URL . "modules/users/edit_permissions.php?id=" . $user_id);
    exit();
}
$update_stmt->close();

// Log the action
if (!empty($changes)) {
    $logged_in_user_id = $_SESSION['user_id'];
    $details = "Permissions updated for user #{$user_id} ({$user['name']}) by user #{$logged_in_user_id}. Changes: " . implode("; ", $changes);
    $created_at = date('Y-m-d H:i:s');

    $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, 'edit_user', 0, ?, ?)");
    $log_stmt->bind_param("iss", $logged_in_user_id, $details, $created_at);
    $log_stmt->execute();
    $log_stmt->close();
}

// Refresh session if editing self
if ($user_id == $_SESSION['user_id']) {
    $_SESSION['user_access'] = $access;
}

$_SESSION['success_message'] = "Permissions updated successfully.";
header("Location: " . BASE_URL . "modules/users/edit_permissions.php?id=" . $user_id);
exit();
