<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Asset name is required']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO assets (name, status) VALUES (?, 'active')");
    $stmt->bind_param("s", $name);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Asset added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
    }
} 
elseif ($action === 'edit') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (empty($name) || $id === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE assets SET name = ?, status = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $status, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Asset updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
    }
}
elseif ($action === 'toggle_status') {
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'active';

    if ($id === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE assets SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
    }
}
else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>
