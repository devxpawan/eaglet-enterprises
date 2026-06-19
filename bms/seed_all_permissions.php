<?php
require_once __DIR__ . '/config/paths.php';
require_once BASE_PATH . 'includes/db_connection.php';

// All possible permission keys
$allPermissions = [
    'dashboard',
    'invoices', 'invoices.create', 'invoices.view_all', 'invoices.pending',
    'invoices.complete', 'invoices.cancel', 'invoices.edit_requests', 'payments.view_all', 'credit_memos.view_all', 'credit_memos.create',
    'quotations', 'quotations.create', 'quotations.view_all', 'quotations.draft',
    'quotations.accepted', 'quotations.cancelled', 'quotations.revised',
    'price_lists', 'price_lists.create', 'price_lists.view_all', 'price_lists.manage_assets',
    'customers', 'customers.view_all', 'customers.add',
    'users', 'users.view_all', 'users.add', 'users.permissions', 'users.logs',
    'products', 'products.view_all', 'products.categories', 'products.add',
    'inventory', 'inventory.suppliers', 'inventory.purchase_orders', 'inventory.stock_movements',
    'settings',
];

$newAccess = json_encode($allPermissions);
$updateStmt = $conn->prepare("UPDATE users SET access = ? WHERE id = ?");
$userId = 1;
$updateStmt->bind_param("si", $newAccess, $userId);
$updateStmt->execute();
$updateStmt->close();
$updated = 1;
$conn->close();

echo "Granted all permissions to $updated users.\n";
?>
