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

if (isset($_GET['email']) && !empty(trim($_GET['email']))) {
    $email = strtolower(trim($_GET['email']));
    $exclude_id = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;

    // Validate format first
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['available'] = false;
        $response['message'] = 'Invalid email format.';
    } else {
        // Check for duplicate
        if ($exclude_id > 0) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $email, $exclude_id);
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $response['available'] = false;
            $response['message'] = 'Email is already in use by another user.';
        } else {
            $response['available'] = true;
            $response['message'] = 'Email is available.';
        }
        $stmt->close();
    }
} else {
    $response['available'] = false;
    $response['message'] = 'Email cannot be empty.';
}

$conn->close();
header('Content-Type: application/json');
echo json_encode($response);