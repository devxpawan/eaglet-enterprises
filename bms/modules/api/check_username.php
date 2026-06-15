<?php
require_once __DIR__ . '/../../config/paths.php';
session_start();

// Allow only AJAX requests
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode(['available' => false, 'error' => 'Invalid request']);
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';

$response = ['available' => true, 'message' => ''];

if (isset($_GET['username']) && !empty(trim($_GET['username']))) {
    $username = trim($_GET['username']);
    $exclude_id = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;

    // Validate format first
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $response['available'] = false;
        $response['message'] = 'Username must be 3-50 characters and can only contain letters, numbers, and underscores.';
    } else {
        // Check for duplicate
        if ($exclude_id > 0) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $username, $exclude_id);
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $response['available'] = false;
            $response['message'] = 'Username is already taken. Please choose another.';
        } else {
            $response['available'] = true;
            $response['message'] = 'Username is available.';
        }
        $stmt->close();
    }
} else {
    $response['available'] = false;
    $response['message'] = 'Username cannot be empty.';
}

$conn->close();
header('Content-Type: application/json');
echo json_encode($response);