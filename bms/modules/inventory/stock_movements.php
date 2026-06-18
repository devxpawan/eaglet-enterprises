<?php
require_once __DIR__ . '/../../config/paths.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

$canEdit = true;

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'adjust_stock') {
        $product_id = (int)$_POST['product_id'];
        $adjustment_type = $_POST['adjustment_type'];
        $quantity = (int)$_POST['quantity'];
        $notes = trim($_POST['notes'] ?? '');
        
        if ($product_id > 0 && $quantity > 0) {
            $conn->begin_transaction();
            try {
                // Fetch current stock
                $pQuery = $conn->prepare("SELECT stock_quantity FROM products WHERE id = ?");
                $pQuery->bind_param("i", $product_id);
                $pQuery->execute();
                $productResult = $pQuery->get_result()->fetch_assoc();
                $pQuery->close();
                
                if (!$productResult) {
                    throw new Exception("Product not found");
                }
                
                $current_stock = (int)$productResult['stock_quantity'];
                
                if ($adjustment_type === 'remove' && $current_stock < $quantity) {
                    throw new Exception("Insufficient stock. Current stock level is only {$current_stock}.");
                }
                
                $movement_type = $adjustment_type === 'add' ? 'in' : 'out';
                $qty = $adjustment_type === 'add' ? $quantity : -$quantity;
                
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, created_by) VALUES (?, ?, ?, 'adjustment', ?, ?)");
                $stmt->bind_param("isisi", $product_id, $movement_type, $quantity, $notes, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->close();
                
                $stmt2 = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                $stmt2->bind_param("ii", $qty, $product_id);
                $stmt2->execute();
                $stmt2->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Stock adjusted successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error adjusting stock: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "Invalid input.";
        }
        header("Location: " . BASE_URL . "modules/inventory/stock_movements.php");
        exit();
    }
}

// Filters
$filter_type = isset($_GET['filter_type']) ? trim($_GET['filter_type']) : '';
$filter_product = isset($_GET['filter_product']) ? trim($_GET['filter_product']) : '';
$filter_from = isset($_GET['filter_from']) ? trim($_GET['filter_from']) : '';
$filter_to = isset($_GET['filter_to']) ? trim($_GET['filter_to']) : '';

$where = [];
if ($filter_type !== '') {
    $s = $conn->real_escape_string($filter_type);
    $where[] = "sm.movement_type = '$s'";
}
if (!empty($filter_product)) {
    $s = $conn->real_escape_string($filter_product);
    $where[] = "p.name LIKE '%$s%'";
}
if (!empty($filter_from)) {
    $d = $conn->real_escape_string($filter_from);
    $where[] = "sm.created_at >= '$d'";
}
if (!empty($filter_to)) {
    $d = $conn->real_escape_string($filter_to);
    $where[] = "sm.created_at <= '$d'";
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$countResult = $conn->query("SELECT COUNT(*) as total FROM stock_movements sm JOIN products p ON sm.product_id = p.id $whereClause");
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);

$movements = $conn->query("SELECT sm.*, p.name as product_name, p.sku, p.unit, u.name as created_by_name 
    FROM stock_movements sm 
    JOIN products p ON sm.product_id = p.id 
    LEFT JOIN users u ON sm.created_by = u.id 
    $whereClause ORDER BY sm.created_at DESC LIMIT $limit OFFSET $offset");

// For adjustment dropdown
$products = $conn->query("SELECT id, name, sku, stock_quantity, unit FROM products WHERE status = 'active' ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Stock Movements</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/inventory.css" rel="stylesheet" />
</head>
<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="inventory-container">
                    <div class="page-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5>Stock Movements</h5>
                            <p class="text-muted">Track all inventory changes and adjustments</p>
                        </div>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adjustStockModal">
                            <i class="fas fa-sliders-h"></i> Adjust Stock
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php
                    if (isset($_SESSION['success_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("success", "' . addslashes($_SESSION['success_message']) . '"); });</script>';
                        unset($_SESSION['success_message']);
                    }
                    if (isset($_SESSION['error_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("error", "' . addslashes($_SESSION['error_message']) . '"); });</script>';
                        unset($_SESSION['error_message']);
                    }
                    ?>

                    <div class="inventory-card">
                        <div class="card-body">
                            <div class="invoice-filter-bar mb-3">
                                <form method="get">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Product</label>
                                            <input type="text" name="filter_product" class="form-control" placeholder="Product name" value="<?= htmlspecialchars($filter_product) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Type</label>
                                            <select name="filter_type" class="form-select">
                                                <option value="">All</option>
                                                <option value="in" <?= $filter_type === 'in' ? 'selected' : '' ?>>Stock In</option>
                                                <option value="out" <?= $filter_type === 'out' ? 'selected' : '' ?>>Stock Out</option>
                                                <option value="adjustment" <?= $filter_type === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                                                <option value="return" <?= $filter_type === 'return' ? 'selected' : '' ?>>Return</option>
                                                <option value="transfer" <?= $filter_type === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">From</label>
                                            <input type="date" name="filter_from" class="form-control" value="<?= htmlspecialchars($filter_from) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">To</label>
                                            <input type="date" name="filter_to" class="form-control" value="<?= htmlspecialchars($filter_to) ?>">
                                        </div>
                                        <div class="col-md-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter"><i class="fas fa-search me-1"></i> Search</button>
                                            <a href="<?= BASE_URL ?>modules/inventory/stock_movements.php" class="btn btn-outline-secondary btn-clear"><i class="fas fa-times me-1"></i> Clear</a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                                <?php if (!empty($filter_product) || $filter_type !== '' || !empty($filter_from) || !empty($filter_to)): ?>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <div>
                                        <span class="search-count"><?= $totalRows; ?> Stock Movement<?= $totalRows !== 1 ? 's' : '' ?> found</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="table-responsive">
                                <table class="table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th>Type</th>
                                            <th>Qty</th>
                                            <th>Reference</th>
                                            <th>Notes</th>
                                            <th>By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($movements && $movements->num_rows > 0): ?>
                                            <?php while ($m = $movements->fetch_assoc()): 
                                                $typeClasses = ['in' => 'movement-in', 'out' => 'movement-out', 'adjustment' => 'movement-adjustment', 'return' => 'movement-return', 'transfer' => 'movement-adjustment'];
                                                $typeIcons = ['in' => 'fa-arrow-down', 'out' => 'fa-arrow-up', 'adjustment' => 'fa-sliders-h', 'return' => 'fa-undo', 'transfer' => 'fa-exchange-alt'];
                                                $class = $typeClasses[$m['movement_type']] ?? 'movement-adjustment';
                                                $icon = $typeIcons[$m['movement_type']] ?? 'fa-circle';
                                            ?>
                                            <tr>
                                                <td><small><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></small></td>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($m['product_name']) ?></span></td>
                                                <td><small><?= htmlspecialchars($m['sku'] ?? '—') ?></small></td>
                                                <td>
                                                    <span class="inv-badge <?= $class ?>">
                                                        <i class="fas <?= $icon ?> me-1"></i> <?= ucfirst($m['movement_type']) ?>
                                                    </span>
                                                </td>
                                                <td class="fw-bold <?= in_array($m['movement_type'], ['in', 'return']) ? 'text-success' : 'text-danger' ?>">
                                                    <?= in_array($m['movement_type'], ['in', 'return']) ? '+' : '-' ?><?= $m['quantity'] ?> <?= htmlspecialchars($m['unit'] ?? '') ?>
                                                </td>
                                                <td><small><?= htmlspecialchars($m['reference_type'] ?? '—') ?> #<?= $m['reference_id'] ?? '' ?></small></td>
                                                <td><small class="text-muted"><?= htmlspecialchars($m['notes'] ?? '—') ?></small></td>
                                                <td><small><?= htmlspecialchars($m['created_by_name'] ?? '—') ?></small></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="8" class="text-center py-4">No stock movements found</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="pagination-container d-flex justify-content-between align-items-center mt-4">
                                <div class="entries-info">
                                    Showing <strong><?= $totalRows > 0 ? $offset + 1 : 0 ?></strong> to <strong><?= min($offset + $limit, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong> entries
                                </div>
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Adjust Stock Modal -->
    <div class="modal fade" id="adjustStockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i>Adjust Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="adjust_stock">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Product <span class="text-danger">*</span></label>
                            <select name="product_id" class="form-select" required>
                                <option value="">— Select Product —</option>
                                <?php if ($products): while ($p = $products->fetch_assoc()): ?>
                                    <option value="<?= $p['id'] ?>">
                                        <?= htmlspecialchars($p['name']) ?> (SKU: <?= htmlspecialchars($p['sku'] ?? '—') ?>) — Stock: <?= $p['stock_quantity'] ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?>
                                    </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adjustment Type <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="adjustment_type" value="add" id="adj_add" checked>
                                    <label class="form-check-label" for="adj_add"><i class="fas fa-plus-circle text-success me-1"></i> Add Stock</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="adjustment_type" value="remove" id="adj_remove">
                                    <label class="form-check-label" for="adj_remove"><i class="fas fa-minus-circle text-danger me-1"></i> Remove Stock</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" min="1" required placeholder="e.g. 10">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason / Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Why is this adjustment needed?"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn"><i class="fas fa-save me-1"></i> Apply Adjustment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
    $(document).ready(function() {
        // Product select auto-show current stock
        $('select[name="product_id"]').change(function() {
            const text = $(this).find('option:selected').text();
            const match = text.match(/Stock: (\d+)/);
            if (match) {
                $(this).closest('.modal-body').find('.current-stock-display').remove();
                $(this).after(`<small class="current-stock-display form-text text-muted">Current stock level: <strong>${match[1]}</strong></small>`);
            }
        });

        // Form submit validation
        $('#adjustStockModal form').submit(function(e) {
            const type = $('input[name="adjustment_type"]:checked').val();
            if (type === 'remove') {
                const select = $('select[name="product_id"]');
                const selectedOpt = select.find('option:selected');
                if (!selectedOpt.val()) return; // let html5 validation handle it
                
                const text = selectedOpt.text();
                const match = text.match(/Stock: (\d+)/);
                if (match) {
                    const currentStock = parseInt(match[1], 10);
                    const inputQty = parseInt($('input[name="quantity"]').val(), 10) || 0;
                    if (inputQty > currentStock) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Insufficient Stock',
                            text: `Cannot remove ${inputQty} units. Only ${currentStock} units are currently available.`,
                            icon: 'error',
                            confirmButtonColor: '#3085d6'
                        });
                        return false;
                    }
                }
            }
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>