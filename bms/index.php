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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Dashboard</title>
    <link href="<?= BASE_URL ?>css/dashboard.css" rel="stylesheet" />
</head>

<body class="sb-nav-fixed">

<?php require_once BASE_PATH . 'includes/navbar.php'; ?>

<div id="layoutSidenav">
    <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main>
            <div class="welcome-section">
                    <svg class="welcome-illustration" viewBox="0 0 300 200" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="250" cy="50" r="40" fill="#eef4ff" opacity="0.6" />
                        <rect x="20" y="150" width="100" height="40" rx="10" fill="#ecfdf3" opacity="0.5" transform="rotate(-15)" />
                        <path d="M50,180 C50,120 250,120 250,180" fill="#eef4ff" />
                        <rect x="100" y="60" width="100" height="100" rx="12" fill="#0b3354" />
                        <rect x="115" y="75" width="70" height="15" rx="4" fill="#ffffff" opacity="0.2" />
                        <rect x="115" y="100" width="70" height="15" rx="4" fill="#ffffff" opacity="0.1" />
                        <rect x="115" y="125" width="40" height="15" rx="4" fill="#ffffff" opacity="0.1" />
                        <circle cx="210" cy="40" r="15" fill="#f59e0b" />
                        <path d="M80,120 L110,120 L95,150 Z" fill="#10b981" />
                    </svg>
                    <h1 class="welcome-title">Hello, <?= htmlspecialchars($_SESSION['name'] ?? 'User') ?>!</h1>
                    <p class="welcome-subtitle">Everything is running smoothly. Hope you have a productive day.</p>
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