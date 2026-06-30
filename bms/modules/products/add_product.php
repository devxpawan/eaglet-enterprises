<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning only if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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

$error_message = null;

// Fetch categories for dropdown
$categories = $conn->query("SELECT id, name, parent_id FROM categories WHERE status = 'active' ORDER BY name ASC");

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $sku = trim($_POST['sku'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $lkr_price = floatval($_POST['lkr_price']);
    if ($lkr_price < 0) {
        $_SESSION['error_message'] = "Price cannot be negative.";
        header("Location: " . BASE_URL . "modules/products/add_product.php");
        exit;
    }
    $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
    $reorder_level = (int)($_POST['reorder_level'] ?? 5);
    $unit = trim($_POST['unit'] ?? 'pcs');
    if ($category_id === null) {
        $sql = "INSERT INTO products (name, sku, description, lkr_price, stock_quantity, reorder_level, unit, created_at, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'active')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdiis", $name, $sku, $description, $lkr_price, $stock_quantity, $reorder_level, $unit);
    } else {
        $sql = "INSERT INTO products (name, sku, category_id, description, lkr_price, stock_quantity, reorder_level, unit, created_at, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdiiis", $name, $sku, $category_id, $description, $lkr_price, $stock_quantity, $reorder_level, $unit);
    }
    
    if ($stmt->execute()) {
        $stmt->close();
        $_SESSION['success_message'] = "Product Added Successfully!";
        header("Location: " . BASE_URL . "modules/products/add_product.php");
        exit();
    } else {
        $error_message = 'Error: ' . $stmt->error;
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Add New Product</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <style></style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5>Create New Product</h5>
                        <p class="text-muted">Add a new product to the inventory</p>
                    </div>
                </div>

                    <?php
                    if (isset($error_message) && $error_message) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("error", "' . addslashes($error_message) . '"); });</script>';
                    }
                    if (isset($_SESSION['success_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("success", "' . addslashes($_SESSION['success_message']) . '"); });</script>';
                        unset($_SESSION['success_message']);
                    }
                    ?>

                    <div class="card" style="margin: 0 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                        <div class="card-body" style="padding: 28px 32px;">
                            <form method="POST" action="<?= BASE_URL ?>modules/products/add_product.php" id="addProductForm">
                                <!-- Product Details Section -->
                                <div class="premium-section-header">
                                    <i class="fas fa-tag"></i> Product Details
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="name" name="name"
                                            placeholder="Enter Product Name" required
                                            value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="lkr_price" class="form-label">LKR Price <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" class="form-control" id="lkr_price" 
                                             name="lkr_price" placeholder="Enter LKR Price" min="0" required
                                            value="<?php echo isset($_POST['lkr_price']) ? htmlspecialchars($_POST['lkr_price']) : ''; ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="sku" class="form-label">SKU (Stock Keeping Unit)</label>
                                        <input type="text" class="form-control" id="sku" name="sku"
                                            placeholder="SKU (Stock Keeping Unit)"
                                            value="<?php echo isset($_POST['sku']) ? htmlspecialchars($_POST['sku']) : ''; ?>">
                                        <small class="text-muted">Leave empty to auto-generate</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="category_id" class="form-label">Category</label>
                                        <select name="category_id" class="form-select">
                                            <option value="">— No Category —</option>
                                            <?php if ($categories): while ($cat = $categories->fetch_assoc()): ?>
                                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description"
                                            placeholder="Enter Product Description" rows="3"><?php 
                                            echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; 
                                        ?></textarea>
                                    </div>
                                </div>

                                <!-- Inventory Section -->
                                <div class="premium-section-header mt-4">
                                    <i class="fas fa-boxes"></i> Inventory Details
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="stock_quantity" class="form-label">Initial Stock Quantity</label>
                                        <input type="number" class="form-control" id="stock_quantity" name="stock_quantity"
                                            min="0" value="0" placeholder="Initial Stock Quantity">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="reorder_level" class="form-label">Reorder Level</label>
                                        <input type="number" class="form-control" id="reorder_level" name="reorder_level"
                                            min="1" value="5" placeholder="Reorder Level">
                                        <small class="text-muted">Alert when stock drops to this level</small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="unit" class="form-label">Unit of Measure</label>
                                        <select name="unit" class="form-select">
                                            <option value="pcs">Pieces (pcs)</option>
                                            <option value="kg">Kilograms (kg)</option>
                                            <option value="g">Grams (g)</option>
                                            <option value="l">Liters (l)</option>
                                            <option value="ml">Milliliters (ml)</option>
                                            <option value="m">Meters (m)</option>
                                            <option value="box">Box</option>
                                            <option value="pack">Pack</option>
                                            <option value="dozen">Dozen</option>
                                            <option value="set">Set</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <div class="row mt-4 pt-3 border-top">
                                    <div class="col-12 d-flex justify-content-end gap-2">
                                        <a href="<?= BASE_URL ?>modules/products/product_list.php" class="back-btn text-decoration-none d-flex align-items-center">
                                            <i class="fas fa-arrow-left me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="save-btn">
                                            <i class="fas fa-plus-circle"></i> Add Product
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
            </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        document.getElementById('addProductForm').addEventListener('submit', function(event) {
            const lkrPrice = document.getElementById('lkr_price').value.trim();
            if (lkrPrice === '') {
                event.preventDefault();
                showToast('warning', 'LKR price is required.');
                return false;
            }
            if (parseFloat(lkrPrice) < 0 || parseFloat(lkrPrice) > 1000000) {
                event.preventDefault();
                showToast('warning', 'Please enter a valid LKR price.');
                return false;
            }
        });

        document.getElementById('name').addEventListener('blur', function () {
            const nameRegex = /^[a-zA-Z0-9\s\-_.,()]{3,100}$/;
            if (!nameRegex.test(this.value)) {
                this.setCustomValidity('Product name must be 3-100 characters long and can contain letters, numbers, spaces, and some special characters');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>

    <?php
    $conn->close();
    ?>
</body>
</html>