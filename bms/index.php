<?php
require_once __DIR__ . '/config/paths.php';

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Role checks
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$isAdmin = ($current_user_role === 1);
$isModerator = ($current_user_role === 3);
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Helper function to safely query the database
function safeQuery($conn, $query) {
    try {
        $result = $conn->query($query);
        if ($result) {
            $row = $result->fetch_assoc();
            return isset($row['count']) ? (int)$row['count'] : 0;
        }
    } catch (Exception $e) {
        return 0;
    }
    return 0;
}

// Initialize statistics
$stats = [
    'total_users' => 0,
    'total_customers' => 0,
    'total_products' => 0,
    'total_invoices' => 0,
    'complete_invoices' => 0,
    'pending_invoices' => 0,
    'cancel_invoices' => 0,
    'my_invoices' => 0,
    'total_quotations' => 0,
    'draft_quotations' => 0,
    'accepted_quotations' => 0,
    'cancelled_quotations' => 0,
    'total_price_lists' => 0
];

if ($isAdmin) {
    $stats['total_users'] = safeQuery($conn, "SELECT COUNT(*) as count FROM users");
}

$tableExists = $conn->query("SHOW TABLES LIKE 'customers'");
if ($tableExists && $tableExists->num_rows > 0) {
    $stats['total_customers'] = safeQuery($conn, "SELECT COUNT(*) as count FROM customers");
}

$tableExists = $conn->query("SHOW TABLES LIKE 'products'");
if ($tableExists && $tableExists->num_rows > 0) {
    $stats['total_products'] = safeQuery($conn, "SELECT COUNT(*) as count FROM products");
}

$tableExists = $conn->query("SHOW TABLES LIKE 'quotations'");
if ($tableExists && $tableExists->num_rows > 0) {
    if ($isAdmin) {
        $stats['total_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations");
        $stats['draft_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status = 'draft'");
        $stats['accepted_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status = 'accepted'");
        $stats['cancelled_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status = 'cancelled'");
    } else {
        $stats['total_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE created_by = $currentUserId");
        $stats['draft_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE created_by = $currentUserId AND status = 'draft'");
        $stats['accepted_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE created_by = $currentUserId AND status = 'accepted'");
        $stats['cancelled_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE created_by = $currentUserId AND status = 'cancelled'");
    }
}

$tableExists = $conn->query("SHOW TABLES LIKE 'price_lists'");
if ($tableExists && $tableExists->num_rows > 0) {
    $stats['total_price_lists'] = safeQuery($conn, "SELECT COUNT(*) as count FROM price_lists");
}

// Fetch recent invoices
$recent_invoices = [];
if ($conn->query("SHOW TABLES LIKE 'invoices'")->num_rows > 0) {
    $ri_query = $isAdmin
        ? "SELECT i.*, c.name as customer_name FROM invoices i LEFT JOIN customers c ON i.customer_id = c.customer_id ORDER BY i.created_at DESC LIMIT 5"
        : "SELECT i.*, c.name as customer_name FROM invoices i LEFT JOIN customers c ON i.customer_id = c.customer_id WHERE i.created_by = $currentUserId ORDER BY i.created_at DESC LIMIT 5";
    $ri_result = $conn->query($ri_query);
    if ($ri_result) {
        while ($row = $ri_result->fetch_assoc()) {
            $recent_invoices[] = $row;
        }
    }
}

$tableExists = $conn->query("SHOW TABLES LIKE 'invoices'");
if ($tableExists && $tableExists->num_rows > 0) {
    if ($isAdmin) {
        $stats['total_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices");
        $stats['complete_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE status = 'done'");
        $stats['pending_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE status = 'pending'");
        $stats['cancel_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE status = 'cancel'");
    } else {
        $stats['my_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE created_by = $currentUserId");
        $stats['complete_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE created_by = $currentUserId AND status = 'done'");
        $stats['pending_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE created_by = $currentUserId AND status = 'pending'");
        $stats['cancel_invoices'] = safeQuery($conn, "SELECT COUNT(*) as count FROM invoices WHERE created_by = $currentUserId AND status = 'cancel'");
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Dashboard</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/invoice-list.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/dashboard.css" rel="stylesheet" />
</head>

<body class="sb-nav-fixed">

<?php require_once BASE_PATH . 'includes/navbar.php'; ?>

<div id="layoutSidenav">
    <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main>
            <div class="container-fluid px-4">

                <!-- Page Header -->
                <div class="dash-header d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h4>Dashboard</h4>
                        <p class="dash-subtitle">Welcome back, <strong><?= htmlspecialchars($_SESSION['name'] ?? 'User') ?></strong>. Here's what's happening today.</p>
                    </div>
                    <div class="date-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <?= date('l, d M Y') ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dash-actions d-flex flex-wrap gap-2">
                    <a href="<?= BASE_URL ?>modules/invoices/invoice_create.php" class="dash-action-btn">
                        <span class="dash-action-icon" style="background: #eef4ff; color: #3538cd;"><i class="fas fa-plus"></i></span>
                        New Invoice
                    </a>
                    <?php if ($isAdmin || $isModerator): ?>
                    <a href="<?= BASE_URL ?>modules/customers/add_customer.php" class="dash-action-btn">
                        <span class="dash-action-icon" style="background: #ecfdf3; color: #067647;"><i class="fas fa-user-plus"></i></span>
                        Add Customer
                    </a>
                    <a href="<?= BASE_URL ?>modules/products/add_product.php" class="dash-action-btn">
                        <span class="dash-action-icon" style="background: #eef4ff; color: #3538cd;"><i class="fas fa-box-open"></i></span>
                        Add Product
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Invoices Section -->
                <div class="dash-card">
                    <div class="dash-card-body">
                        <h6 class="dash-card-title"><i class="fas fa-file-invoice"></i>Invoices <?php if (!$isAdmin): ?><small class="fw-normal text-muted" style="font-size: 12px;">(Your invoices)</small><?php endif; ?></h6>
                        <div class="dash-stats">
                            <div class="row g-3">
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-indigo">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label"><?= $isAdmin ? 'Total' : 'My Invoices' ?></p>
                                                <div class="stat-value"><?= number_format($isAdmin ? $stats['total_invoices'] : $stats['my_invoices']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #eef4ff; color: #3538cd;"><i class="fas fa-file-invoice"></i></div>
                                        </div>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/invoices/invoice_list.php" class="stat-link" style="color:#3538cd;">View all <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-amber">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Pending</p>
                                                <div class="stat-value"><?= number_format($stats['pending_invoices']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #fffaeb; color: #b54708;"><i class="fas fa-clock"></i></div>
                                        </div>
                                        <?php if ($isAdmin || $isModerator): ?>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/invoices/pending_invoice_list.php" class="stat-link" style="color:#b54708;">Review <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-green">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Completed</p>
                                                <div class="stat-value"><?= number_format($stats['complete_invoices']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #ecfdf3; color: #067647;"><i class="fas fa-check-circle"></i></div>
                                        </div>
                                        <?php if ($isAdmin || $isModerator): ?>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/invoices/complete_invoice_list.php" class="stat-link" style="color:#067647;">View <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-red">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Cancelled</p>
                                                <div class="stat-value"><?= number_format($stats['cancel_invoices']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #fef3f2; color: #b42318;"><i class="fas fa-ban"></i></div>
                                        </div>
                                        <?php if ($isAdmin || $isModerator): ?>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/invoices/cancel_invoice_list.php" class="stat-link" style="color:#b42318;">View <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overview Section -->
                <div class="dash-card">
                    <div class="dash-card-body">
                        <h6 class="dash-card-title"><i class="fas fa-chart-pie"></i>Overview</h6>
                        <div class="dash-stats">
                            <div class="row g-3">
                                <?php if ($isAdmin): ?>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-indigo">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Total Users</p>
                                                <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #eef4ff; color: #3538cd;"><i class="fas fa-users-cog"></i></div>
                                        </div>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/users/users.php" class="stat-link" style="color:#3538cd;">Manage <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-blue">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Customers</p>
                                                <div class="stat-value"><?= number_format($stats['total_customers']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #f2f4f7; color: #344054;"><i class="fas fa-users"></i></div>
                                        </div>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/customers/customer_list.php" class="stat-link" style="color:#344054;">View all <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-indigo">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Products</p>
                                                <div class="stat-value"><?= number_format($stats['total_products']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #eef4ff; color: #3538cd;"><i class="fas fa-boxes-stacked"></i></div>
                                        </div>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/products/product_list.php" class="stat-link" style="color:#3538cd;">View all <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!$isAdmin): ?>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card stat-card-accent-indigo">
                                        <div class="stat-top">
                                            <div>
                                                <p class="stat-label">Total Quotations</p>
                                                <div class="stat-value"><?= number_format($stats['total_quotations']) ?></div>
                                            </div>
                                            <div class="stat-icon" style="background: #eef4ff; color: #3538cd;"><i class="fas fa-file-alt"></i></div>
                                        </div>
                                        <div class="stat-footer">
                                            <a href="<?= BASE_URL ?>modules/quotations/quotation_list.php" class="stat-link" style="color:#3538cd;">View all <i class="fas fa-arrow-right"></i></a>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>


            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>

<?php
$conn->close();
?>