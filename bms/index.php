<?php
require_once __DIR__ . '/config/paths.php';

session_start();

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
            <div class="welcome-page">
                <div class="welcome-image-wrap">
                    <img src="<?= BASE_URL ?>img/welcome.png" alt="Welcome" class="welcome-image">
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
