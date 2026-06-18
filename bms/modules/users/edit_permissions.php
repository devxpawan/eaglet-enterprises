<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
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

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "User ID is required.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

$user_id = intval($_GET['id']);

try {
    $stmt = $conn->prepare("SELECT id, name, username, access FROM users WHERE id = ?");
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
} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

$userAccess = [];
if (!empty($user['access'])) {
    $decoded = json_decode($user['access'], true);
    if (is_array($decoded)) {
        $userAccess = $decoded;
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$is_editing_self = ($user_id == $_SESSION['user_id']);

$permissionGroups = [
    'Main' => [
        'dashboard' => 'Dashboard',
    ],
    'Business' => [
        'invoices'               => 'Invoices - Basic Access',
        'invoices.pending'       => 'Invoices - Pending',
        'invoices.complete'      => 'Invoices - Complete',
        'invoices.cancel'        => 'Invoices - Cancelled',
        'invoices.edit_requests' => 'Invoices - Edit Requests',
        'quotations'             => 'Quotations - Basic Access',
        'quotations.draft'       => 'Quotations - Draft',
        'quotations.accepted'    => 'Quotations - Accepted',
        'quotations.cancelled'   => 'Quotations - Cancelled',
        'quotations.revised'     => 'Quotations - Revised',
        'price_lists'            => 'Price Lists - Basic Access',
        'price_lists.manage_assets' => 'Price Lists - Manage Assets',
        'customers'              => 'Customers - Basic Access',
        'customers.add'          => 'Customers - Add/Edit',
    ],
    'Administration' => [
        'users'             => 'Users - View',
        'users.add'         => 'Users - Add/Edit',
        'users.permissions' => 'Users - Manage Permissions',
        'users.logs' => 'Users - Activity Logs',
    ],
    'Catalog' => [
        'products'            => 'Products - Basic Access',
        'products.categories' => 'Products - Categories',
        'products.add'        => 'Products - Add/Edit',
    ],
    'Inventory' => [
        'inventory'                 => 'Inventory - Basic Access',
        'inventory.suppliers'       => 'Inventory - Suppliers',
        'inventory.purchase_orders' => 'Inventory - Purchase Orders',
        'inventory.stock_movements' => 'Inventory - Stock Movements',
    ],
    'Settings' => [
        'settings' => 'Settings',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Edit Permissions - <?= htmlspecialchars($user['name']) ?></title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <style>
        .select-all-bar {
            background: #f9fafb;
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
    </style>
</head>
<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <?php if (isset($_SESSION['success_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($_SESSION["success_message"]); ?>'); });</script>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['error_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($_SESSION["error_message"]); ?>'); });</script>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Access Permissions</h5>
                        <p class="text-muted">Manage permissions for <strong><?= htmlspecialchars($user['name']) ?></strong> (<?= htmlspecialchars($user['username']) ?>)</p>
                    </div>
                </div>

                <div class="card" style="margin: 0 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                    <div class="card-body" style="padding: 28px 32px;">
                        <form method="POST" action="<?= BASE_URL ?>modules/users/update_permissions.php" id="permForm">
                            <input type="hidden" name="user_id" value="<?= $user_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="premium-section-header">
                                <i class="fas fa-lock"></i> Access Permissions
                            </div>
                            <p class="text-muted small mb-3">Select the navigation sections and actions this user can access.</p>

                            <div class="select-all-bar mb-4">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label" for="selectAll" style="font-size: 13px; font-weight: 500;">Select / Deselect All</label>
                            </div>

                            <?php foreach ($permissionGroups as $groupName => $perms): ?>
                            <div class="mb-4">
                                <h6 class="text-muted text-uppercase small fw-semibold mb-2 pb-1 border-bottom"><?= htmlspecialchars($groupName) ?></h6>
                                <div class="row g-3">
                                    <?php foreach ($perms as $permKey => $permLabel): ?>
                                    <div class="col-md-4 col-lg-3">
                                        <div class="form-check">
                                            <input class="form-check-input permission-check" type="checkbox"
                                                name="access[]" value="<?= $permKey ?>"
                                                id="perm_<?= $permKey ?>"
                                                <?= in_array($permKey, $userAccess) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="perm_<?= $permKey ?>">
                                                <?= htmlspecialchars($permLabel) ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <div class="row mt-4 pt-3 border-top">
                                <div class="col-12 d-flex justify-content-end gap-2">
                                    <a href="<?= BASE_URL ?>modules/users/users.php" class="back-btn text-decoration-none d-flex align-items-center">
                                        <i class="fas fa-arrow-left me-1"></i> Back to All Users
                                    </a>
                                    <button type="submit" class="save-btn d-flex align-items-center gap-1">
                                        <i class="fas fa-save"></i> Save Permissions
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        document.getElementById('selectAll').addEventListener('change', function() {
            document.querySelectorAll('.permission-check').forEach(function(cb) {
                cb.checked = this.checked;
            }, this);
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
