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

$stats['total_users'] = safeQuery($conn, "SELECT COUNT(*) as count FROM users");

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
    $stats['total_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status != 'Revised'");
    $stats['draft_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status = 'draft'");
    $stats['accepted_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status = 'accepted'");
    $stats['cancelled_quotations'] = safeQuery($conn, "SELECT COUNT(*) as count FROM quotations WHERE status = 'cancelled'");
}

$tableExists = $conn->query("SHOW TABLES LIKE 'price_lists'");
if ($tableExists && $tableExists->num_rows > 0) {
    $stats['total_price_lists'] = safeQuery($conn, "SELECT COUNT(*) as count FROM price_lists");
}

// Fetch recent invoices
$recent_invoices = [];
if ($conn->query("SHOW TABLES LIKE 'invoices'")->num_rows > 0) {
    $ri_query = "SELECT i.*, c.name as customer_name FROM invoices i LEFT JOIN customers c ON i.customer_id = c.customer_id ORDER BY i.created_at DESC LIMIT 5";
    $ri_result = $conn->query($ri_query);
    if ($ri_result) {
        while ($row = $ri_result->fetch_assoc()) {
            $recent_invoices[] = $row;
        }
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
                
                <!-- Welcome Section -->
                <div class="welcome-container mt-4">
                    <svg class="welcome-illustration" viewBox="0 0 300 200" xmlns="http://www.w3.org/2000/svg">
                        <!-- Abstract Background Shapes -->
                        <circle cx="250" cy="50" r="40" fill="#eef4ff" opacity="0.6" />
                        <rect x="20" y="150" width="100" height="40" rx="10" fill="#ecfdf3" opacity="0.5" transform="rotate(-15)" />
                        
                        <!-- Main Illustration -->
                        <path d="M50,180 C50,120 250,120 250,180" fill="#eef4ff" />
                        
                        <!-- Main Box -->
                        <rect x="100" y="60" width="100" height="100" rx="12" fill="#0b3354" />
                        
                        <!-- Details inside Main Box -->
                        <rect x="115" y="75" width="70" height="15" rx="4" fill="#ffffff" opacity="0.2" />
                        <rect x="115" y="100" width="70" height="15" rx="4" fill="#ffffff" opacity="0.1" />
                        <rect x="115" y="125" width="40" height="15" rx="4" fill="#ffffff" opacity="0.1" />
                        
                        <!-- Accent Elements -->
                        <circle cx="210" cy="40" r="15" fill="#f59e0b" />
                        <path d="M80,120 L110,120 L95,150 Z" fill="#10b981" />
                    </svg>
                    <h1 class="welcome-title">Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'User') ?>!</h1>
                    <p class="welcome-subtitle">Everything is running smoothly. Hope you have a productive day.</p>
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