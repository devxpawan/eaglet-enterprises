<?php require_once __DIR__ . '/../config/paths.php';

$_company_favicon = '';
$_company_logo    = '';
$_company_name    = '';
if (!isset($conn) && file_exists(__DIR__ . '/db_connection.php')) {
    @include_once __DIR__ . '/db_connection.php';
}
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $_cs = @$conn->query("SELECT logo_path, favicon_path, company_name FROM company_settings WHERE id = 1");
    if ($_cs && $_row = $_cs->fetch_assoc()) {
        if (!empty($_row['favicon_path'])) {
            $_company_favicon = BASE_URL . $_row['favicon_path'];
        }
        if (!empty($_row['logo_path'])) {
            $_company_logo = BASE_URL . $_row['logo_path'];
        }
        $_company_name = $_row['company_name'] ?? '';
    }
}
?>
<!-- Meta tag for character encoding -->
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<?php if (!empty($_company_favicon)): ?>
<!-- FAVICON -->
<link rel="icon" href="<?= htmlspecialchars($_company_favicon) ?>" type="image/png">
<?php endif; ?>

<!-- Meta tag for IE compatibility -->
<meta http-equiv="X-UA-Compatible" content="IE=edge" />

<!-- Meta tag for page description (empty) -->
<meta name="description" content="" />

<!-- Meta tag for author information (empty) -->
<meta name="author" content="" />

<!-- Link to SimpleDataTables CSS stylesheet -->
<link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />

<!-- Link to local styles.css stylesheet -->
<link href="<?= BASE_URL ?>css/styles.css" rel="stylesheet" />

<!-- Link to Bootstrap 5.3.0 CSS from CDN (Standardized version) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />

<!-- Link to Google Fonts for Inter font family -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Link to Font Awesome CSS from CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Mobile Responsive Styles -->
<link href="<?= BASE_URL ?>css/mobile-responsive.css" rel="stylesheet" />

<!-- SweetAlert2 -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>
<script src="<?= BASE_URL ?>js/toast.js"></script>

<style>
    html {
        overflow-y: scroll;
        scroll-behavior: smooth;
    }
    body {
        font-family: 'Inter', sans-serif !important;
        overflow-x: hidden;
        background: #f1f5f9;
        color: #1e293b;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
</style>