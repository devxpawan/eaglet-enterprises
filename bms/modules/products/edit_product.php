<?php
require_once __DIR__ . '/../../config/paths.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 3])) {
    header("Location: " . BASE_URL . "modules/products/product_list.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: " . BASE_URL . "modules/products/product_list.php");
    exit();
}

$product_id = $_GET['id'];

$sql = "SELECT * FROM products WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: " . BASE_URL . "modules/products/product_list.php");
    exit();
}

$product = $result->fetch_assoc();
$original_product = $product;

$error_message = null;

// Fetch categories
$categories = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $sku = trim($_POST['sku'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $lkr_price = floatval($_POST['lkr_price']);
    $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
    $reorder_level = (int)($_POST['reorder_level'] ?? 5);
    $unit = trim($_POST['unit'] ?? 'pcs');
    
    $updateSql = "UPDATE products SET 
                 name = ?, sku = ?, category_id = ?, description = ?, 
                 lkr_price = ?, stock_quantity = ?, reorder_level = ?, unit = ? 
                 WHERE id = ?";
                  
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("ssissdssi", $name, $sku, $category_id, $description, $lkr_price, $stock_quantity, $reorder_level, $unit, $product_id);
    
    if ($stmt->execute()) {
        $product_updated = true;
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        $user_id = $_SESSION['user_id'];
        $user_name = $_SESSION['user_name'] ?? 'Unknown User';
        $action_type = "edit_product";
        
        $changes = array();
        
        if ($original_product['name'] != $name) {
            $changes[] = "Name changed from '" . htmlspecialchars($original_product['name']) . "' to '" . htmlspecialchars($name) . "'";
        }
        
        if ($original_product['description'] != $description) {
            $old_desc = strlen($original_product['description']) > 30 ? 
                substr(htmlspecialchars($original_product['description']), 0, 30) . '...' : 
                htmlspecialchars($original_product['description']);
            $new_desc = strlen($description) > 30 ? 
                substr(htmlspecialchars($description), 0, 30) . '...' : 
                htmlspecialchars($description);
            $changes[] = "Description was updated from '$old_desc' to '$new_desc'";
        }
        
        $original_lkr = $original_product['lkr_price'];
        
        if ($original_lkr != $lkr_price) {
            $old_price = is_null($original_lkr) ? 'not set' : number_format($original_lkr, 2) . ' LKR';
            $new_price = number_format($lkr_price, 2) . ' LKR';
            $changes[] = "LKR Price changed from $old_price to $new_price";
        }
        
        if (empty($changes)) {
            $changes[] = "No changes were made to the product";
        }
        
        $changes_text = "Product ID #$product_id (" . htmlspecialchars($name) . ") was updated by user $user_name($user_id). Changes:\n* " . implode("\n* ", $changes);
        
        $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logQuery);
        $inquiry_id = 0;
        $logStmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $changes_text);
        $logStmt->execute();
        $logStmt->close();
        
        $_SESSION['success_message'] = "Product Updated Successfully!";
        header("Location: " . BASE_URL . "modules/products/edit_product.php?id=" . $product_id);
        exit();
        
    } else {
        $error_message = 'Error: ' . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Edit Product</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8fafc;
        }
    </style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5>Edit Product</h5>
                        <p class="text-muted">Update product information</p>
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
                                <form method="POST" action="<?= BASE_URL ?>modules/products/edit_product.php?id=<?= $product_id ?>" id="editProductForm">
                                    <!-- Product Details Section -->
                                    <div class="premium-section-header">
                                        <i class="fas fa-tag"></i> Product Details
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="name" name="name"
                                                placeholder="Enter Product Name" required
                                                value="<?= htmlspecialchars($product['name']) ?>" data-original="<?= htmlspecialchars($product['name']) ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="lkr_price" class="form-label">LKR Price <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" class="form-control" id="lkr_price" 
                                                name="lkr_price" placeholder="Enter LKR Price" required
                                                value="<?= ($product['lkr_price'] !== NULL) ? htmlspecialchars($product['lkr_price']) : '' ?>" data-original="<?= ($product['lkr_price'] !== NULL) ? htmlspecialchars($product['lkr_price']) : '' ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="sku" class="form-label">SKU (Stock Keeping Unit)</label>
                                            <input type="text" class="form-control" id="sku" name="sku"
                                                placeholder="e.g. ELEC-001"
                                                value="<?= htmlspecialchars($product['sku'] ?? '') ?>" data-original="<?= htmlspecialchars($product['sku'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="category_id" class="form-label">Category</label>
                                            <select name="category_id" class="form-select" id="category_id" data-original="<?= $product['category_id'] ?? '' ?>">
                                                <option value="">— No Category —</option>
                                                <?php if ($categories): while ($cat = $categories->fetch_assoc()): ?>
                                                    <option value="<?= $cat['id'] ?>" <?= ($product['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                                <?php endwhile; endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 mb-3">
                                            <label for="description" class="form-label">Description</label>
                                            <textarea class="form-control" id="description" name="description"
                                                placeholder="Enter Product Description" rows="3" data-original="<?= htmlspecialchars($product['description']) ?>"><?= 
                                                htmlspecialchars($product['description']) 
                                            ?></textarea>
                                        </div>
                                    </div>

                                    <!-- Inventory Section -->
                                    <div class="premium-section-header mt-4">
                                        <i class="fas fa-boxes"></i> Inventory Details
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="stock_quantity" class="form-label">Stock Quantity</label>
                                            <input type="number" class="form-control" id="stock_quantity" name="stock_quantity"
                                                min="0" value="<?= $product['stock_quantity'] ?? 0 ?>" data-original="<?= $product['stock_quantity'] ?? 0 ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="reorder_level" class="form-label">Reorder Level</label>
                                            <input type="number" class="form-control" id="reorder_level" name="reorder_level"
                                                min="1" value="<?= $product['reorder_level'] ?? 5 ?>" data-original="<?= $product['reorder_level'] ?? 5 ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="unit" class="form-label">Unit of Measure</label>
                                            <select name="unit" class="form-select" id="unit" data-original="<?= $product['unit'] ?? 'pcs' ?>">
                                                <option value="pcs" <?= ($product['unit'] ?? 'pcs') == 'pcs' ? 'selected' : '' ?>>Pieces (pcs)</option>
                                                <option value="kg" <?= ($product['unit'] ?? '') == 'kg' ? 'selected' : '' ?>>Kilograms (kg)</option>
                                                <option value="g" <?= ($product['unit'] ?? '') == 'g' ? 'selected' : '' ?>>Grams (g)</option>
                                                <option value="l" <?= ($product['unit'] ?? '') == 'l' ? 'selected' : '' ?>>Liters (l)</option>
                                                <option value="ml" <?= ($product['unit'] ?? '') == 'ml' ? 'selected' : '' ?>>Milliliters (ml)</option>
                                                <option value="m" <?= ($product['unit'] ?? '') == 'm' ? 'selected' : '' ?>>Meters (m)</option>
                                                <option value="box" <?= ($product['unit'] ?? '') == 'box' ? 'selected' : '' ?>>Box</option>
                                                <option value="pack" <?= ($product['unit'] ?? '') == 'pack' ? 'selected' : '' ?>>Pack</option>
                                                <option value="dozen" <?= ($product['unit'] ?? '') == 'dozen' ? 'selected' : '' ?>>Dozen</option>
                                                <option value="set" <?= ($product['unit'] ?? '') == 'set' ? 'selected' : '' ?>>Set</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="row mt-4 pt-3 border-top">
                                        <div class="col-12 d-flex justify-content-end gap-3">
                                            <a href="<?= BASE_URL ?>modules/products/product_list.php" class="back-btn text-decoration-none d-flex align-items-center">
                                                <i class="fas fa-arrow-left me-2"></i> Cancel
                                            </a>
                                            <button type="submit" class="save-btn" id="submitBtn" disabled>
                                                <i class="fas fa-save"></i> Update Product
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        document.getElementById('editProductForm').addEventListener('submit', function(event) {
            const lkrPrice = document.getElementById('lkr_price').value.trim();
            if (lkrPrice === '') {
                event.preventDefault();
                showToast('warning', 'LKR price is required.');
                return false;
            }
            if (parseFloat(lkrPrice) < 0 || parseFloat(lkrPrice) > 1000000) {
                event.preventDefault();
                showToast('warning', 'Please enter a valid LKR price between 0 and 1,000,000');
                return false;
            }
        });

        function checkForChanges() {
            const fields = ['name', 'description', 'lkr_price', 'sku', 'category_id', 'stock_quantity', 'reorder_level', 'unit'];
            let hasChanged = false;
            for (const id of fields) {
                const el = document.getElementById(id);
                if (el && el.value !== el.getAttribute('data-original')) {
                    hasChanged = true;
                    break;
                }
            }
            document.getElementById('submitBtn').disabled = !hasChanged;
        }

        ['name', 'description', 'lkr_price', 'sku', 'category_id', 'stock_quantity', 'reorder_level', 'unit'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', checkForChanges);
                el.addEventListener('change', checkForChanges);
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