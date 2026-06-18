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

// Determine which field to check
$field = isset($_GET['field']) ? $_GET['field'] : '';
$exclude_id = isset($_GET['exclude_id']) ? intval($_GET['exclude_id']) : 0;

switch ($field) {
    case 'username':
        if (isset($_GET['username']) && !empty(trim($_GET['username']))) {
            $username = trim($_GET['username']);

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
        break;

    case 'email':
        if (isset($_GET['email']) && !empty(trim($_GET['email']))) {
            $email = strtolower(trim($_GET['email']));

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
        break;

    case 'nic':
        if (isset($_GET['nic']) && !empty(trim($_GET['nic']))) {
            $nic = trim($_GET['nic']);

            // Validate NIC format first
            $nicRegex = '/^([0-9]{9}[vVxX]?|[0-9]{12})$/';
            if (!preg_match($nicRegex, $nic)) {
                $response['available'] = false;
                $response['message'] = 'Please enter a valid NIC number (9 digits + V/X or 12 digits).';
            } else {
                // Check for duplicate NIC
                if ($exclude_id > 0) {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE nic = ? AND id != ?");
                    $stmt->bind_param("si", $nic, $exclude_id);
                } else {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE nic = ?");
                    $stmt->bind_param("s", $nic);
                }
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $response['available'] = false;
                    $response['message'] = 'NIC number is already in use by another user.';
                } else {
                    $response['available'] = true;
                    $response['message'] = 'NIC number is available.';
                }
                $stmt->close();
            }
        } else {
            $response['available'] = false;
            $response['message'] = 'NIC number cannot be empty.';
        }
        break;

    case 'mobile':
        if (isset($_GET['mobile']) && !empty(trim($_GET['mobile']))) {
            $mobile = trim($_GET['mobile']);

            // Clean the mobile number - remove all non-digit characters
            $digits = preg_replace('/\D/', '', $mobile);

            // Validate mobile format first
            if (!preg_match('/^[0-9]{10}$/', $digits)) {
                $response['available'] = false;
                $response['message'] = 'Mobile number must be exactly 10 digits.';
            } else {
                // Check for duplicate mobile
                if ($exclude_id > 0) {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
                    $stmt->bind_param("si", $digits, $exclude_id);
                } else {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
                    $stmt->bind_param("s", $digits);
                }
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $response['available'] = false;
                    $response['message'] = 'Mobile number is already in use by another user.';
                } else {
                    $response['available'] = true;
                    $response['message'] = 'Mobile number is available.';
                }
                $stmt->close();
            }
        } else {
            $response['available'] = false;
            $response['message'] = 'Mobile number cannot be empty.';
        }
        break;

    default:
        $response['available'] = false;
        $response['message'] = 'Invalid field specified.';
        break;
}

$conn->close();
header('Content-Type: application/json');
echo json_encode($response);
