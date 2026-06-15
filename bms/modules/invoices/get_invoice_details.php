<?php
require_once __DIR__ . '/../../config/paths.php';

require_once BASE_PATH . 'includes/db_connection.php';

$invoice_id = $_GET['id'];

$sql = "SELECT * FROM invoices WHERE invoice_id = $invoice_id";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode($row);
} else {
    echo json_encode([]);
}
?>