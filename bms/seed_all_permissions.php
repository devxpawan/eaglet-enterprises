<?php
require_once __DIR__ . '/config/paths.php';
require_once BASE_PATH . 'includes/db_connection.php';

// All possible permission keys
$allPermissions = [
    'dashboard',
    'invoices', 'invoices.create', 'invoices.view_all', 'invoices.pending',
    'invoices.complete', 'invoices.cancel', 'invoices.edit_requests', 'payments.view_all',
    'quotations', 'quotations.create', 'quotations.view_all', 'quotations.draft',
    'quotations.accepted', 'quotations.cancelled', 'quotations.revised',
    'price_lists', 'price_lists.create', 'price_lists.view_all', 'price_lists.manage_assets',
    'customers', 'customers.view_all', 'customers.add',
    'users', 'users.view_all', 'users.add', 'users.permissions', 'users.logs',
    'products', 'products.view_all', 'products.categories', 'products.add',
    'inventory', 'inventory.suppliers', 'inventory.purchase_orders', 'inventory.stock_movements',
    'settings',
];

$stmt = $conn->prepare("SELECT id FROM users");
$stmt->execute();
$result = $stmt->get_result();

$updated = 0;
while ($row = $result->fetch_assoc()) {
    $newAccess = json_encode($allPermissions);
    $updateStmt = $conn->prepare("UPDATE users SET access = ? WHERE id = ?");
    $updateStmt->bind_param("si", $newAccess, $row['id']);
    $updateStmt->execute();
    $updateStmt->close();
    $updated++;
}

$stmt->close();
$conn->close();

echo "Granted all permissions to $updated users.\n";
?>
