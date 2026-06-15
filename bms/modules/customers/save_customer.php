<?php
require_once __DIR__ . '/../../config/paths.php';

// Authentication check (implied)
if (!authenticated()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'customer_id' => 0
];

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';

    // Basic validation
    if (empty($name)) {
        $response['message'] = 'Customer name is required';
    } else {
        // Check if customer with this email already exists
        $checkSql = "SELECT id FROM customers WHERE email = ? AND email != ''";
        $checkStmt = $conn->prepare($checkSql);

        if ($email) {
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            if ($result->num_rows > 0) {
                // Customer exists, update instead of insert
                $row = $result->fetch_assoc();
                $customerId = $row['id'];

                $updateSql = "UPDATE customers SET name = ?, phone = ?, address = ?, status = 'Active' WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("sssi", $name, $phone, $address, $customerId);

                if ($updateStmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Customer updated successfully';
                    $response['customer_id'] = $customerId;
                } else {
                    $response['message'] = 'Error updating customer: ' . $conn->error;
                }
                $updateStmt->close();
            } else {
                // Insert new customer
                insertNewCustomer($conn, $name, $email, $phone, $address, $response);
            }
            $checkStmt->close();
        } else {
            // Insert new customer without email check
            insertNewCustomer($conn, $name, $email, $phone, $address, $response);
        }
    }
} else {
    $response['message'] = 'Invalid request method';
}

// Function to insert new customer
function insertNewCustomer($conn, $name, $email, $phone, $address, &$response) {
    $insertSql = "INSERT INTO customers (name, email, phone, address, status, created_at) VALUES (?, ?, ?, ?, 'Active', NOW())";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->bind_param("ssss", $name, $email, $phone, $address);

    if ($insertStmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Customer added successfully';
        $response['customer_id'] = $conn->insert_id;
    } else {
        $response['message'] = 'Error adding customer: ' . $conn->error;
    }
    $insertStmt->close();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
