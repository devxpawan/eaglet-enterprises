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

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $supplier_id = (int)$_POST['supplier_id'];
        $order_date = $_POST['order_date'];
        $expected_date = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $products = $_POST['product_id'] ?? [];
        $qtys = $_POST['quantity'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        
        if ($supplier_id > 0 && !empty($products)) {
            $conn->begin_transaction();
            try {
                // Generate PO number
                $prefix = 'PO-' . date('Ymd');
                $countResult = $conn->query("SELECT COUNT(*) as cnt FROM purchase_orders WHERE po_number LIKE '$prefix%'");
                $cnt = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['cnt'] + 1 : 1;
                $po_number = $prefix . '-' . str_pad($cnt, 3, '0', STR_PAD_LEFT);
                
                $sub_total = 0;
                $items_data = [];
                foreach ($products as $key => $pid) {
                    if (!empty($pid) && isset($qtys[$key]) && $qtys[$key] > 0 && isset($prices[$key]) && $prices[$key] >= 0) {
                        $qty = (int)$qtys[$key];
                        $price = (float)$prices[$key];
                        $total = $qty * $price;
                        $sub_total += $total;
                        $items_data[] = ['product_id' => (int)$pid, 'quantity' => $qty, 'unit_price' => $price];
                    }
                }
                
                if ($sub_total <= 0) {
                    throw new Exception("Total amount must be greater than 0");
                }
                
                $tax_amount = 0;
                $discount = 0;
                $total_amount = $sub_total + $tax_amount - $discount;
                
                $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_date, status, sub_total, tax_amount, discount, total_amount, notes, created_by) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sissddddsi", $po_number, $supplier_id, $order_date, $expected_date, $sub_total, $tax_amount, $discount, $total_amount, $notes, $_SESSION['user_id']);
                $stmt->execute();
                $po_id = $stmt->insert_id;
                $stmt->close();
                
                $itemStmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
                foreach ($items_data as $item) {
                    $itemStmt->bind_param("iiid", $po_id, $item['product_id'], $item['quantity'], $item['unit_price']);
                    $itemStmt->execute();
                }
                $itemStmt->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Purchase Order #$po_number created successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error creating PO: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "Please select a supplier and at least one product.";
        }
        header("Location: " . BASE_URL . "modules/inventory/purchase_orders.php");
        exit();
    }
    
    if ($action === 'edit') {
        $po_id = (int)$_POST['po_id'];
        $supplier_id = (int)$_POST['supplier_id'];
        $order_date = $_POST['order_date'];
        $expected_date = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $products = $_POST['product_id'] ?? [];
        $qtys = $_POST['quantity'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        
        if ($po_id > 0 && $supplier_id > 0 && !empty($products)) {
            $conn->begin_transaction();
            try {
                $sub_total = 0;
                $items_data = [];
                foreach ($products as $key => $pid) {
                    if (!empty($pid) && isset($qtys[$key]) && $qtys[$key] > 0 && isset($prices[$key]) && $prices[$key] >= 0) {
                        $qty = (int)$qtys[$key];
                        $price = (float)$prices[$key];
                        $total = $qty * $price;
                        $sub_total += $total;
                        $items_data[] = ['product_id' => (int)$pid, 'quantity' => $qty, 'unit_price' => $price];
                    }
                }
                
                if ($sub_total <= 0) {
                    throw new Exception("Total amount must be greater than 0");
                }
                
                $tax_amount = 0;
                $discount = 0;
                $total_amount = $sub_total + $tax_amount - $discount;
                
                $stmt = $conn->prepare("UPDATE purchase_orders SET supplier_id = ?, order_date = ?, expected_date = ?, sub_total = ?, tax_amount = ?, discount = ?, total_amount = ?, notes = ? WHERE id = ? AND status IN ('draft', 'pending')");
                $stmt->bind_param("issddddsi", $supplier_id, $order_date, $expected_date, $sub_total, $tax_amount, $discount, $total_amount, $notes, $po_id);
                $stmt->execute();
                $stmt->close();
                
                $conn->query("DELETE FROM purchase_order_items WHERE po_id = $po_id");
                
                $itemStmt = $conn->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
                foreach ($items_data as $item) {
                    $itemStmt->bind_param("iiid", $po_id, $item['product_id'], $item['quantity'], $item['unit_price']);
                    $itemStmt->execute();
                }
                $itemStmt->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Purchase Order updated successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error updating PO: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "Please select a supplier and at least one product.";
        }
        header("Location: " . BASE_URL . "modules/inventory/purchase_orders.php");
        exit();
    }
    
    if ($action === 'update_status') {
        $po_id = (int)$_POST['po_id'];
        $new_status = $_POST['status'];
        $conn->query("UPDATE purchase_orders SET status='$new_status' WHERE id=$po_id");
        $_SESSION['success_message'] = "Purchase Order status updated to '$new_status'!";
        header("Location: " . BASE_URL . "modules/inventory/purchase_orders.php");
        exit();
    }
    
    if ($action === 'receive_stock') {
        $po_id = (int)$_POST['po_id'];
        $product_ids = $_POST['product_id'] ?? [];
        $received_qtys = $_POST['received_qty'] ?? [];
        
        $conn->begin_transaction();
        try {
            $itemStmt = $conn->prepare("UPDATE purchase_order_items SET received_quantity = ? WHERE id = ?");
            $movementStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'in', ?, 'purchase_order', ?, ?, ?)");
            $updateStockStmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
            
            foreach ($product_ids as $item_id => $product_id) {
                $qty = (int)($received_qtys[$item_id] ?? 0);
                if ($qty > 0) {
                    $itemStmt->bind_param("ii", $qty, $item_id);
                    $itemStmt->execute();
                    
                    $note = "Received from PO #$po_id";
                    $movementStmt->bind_param("iiiss", $product_id, $qty, $po_id, $note, $_SESSION['user_id']);
                    $movementStmt->execute();
                    
                    $updateStockStmt->bind_param("ii", $qty, $product_id);
                    $updateStockStmt->execute();
                }
            }
            
            $conn->query("UPDATE purchase_orders SET status='received' WHERE id=$po_id");
            
            $conn->commit();
            $_SESSION['success_message'] = "Stock received and inventory updated!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error receiving stock: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "modules/inventory/purchase_orders.php");
        exit();
    }
}

// Fetch POs
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_supplier = isset($_GET['filter_supplier']) ? trim($_GET['filter_supplier']) : '';
$filter_po = isset($_GET['filter_po']) ? trim($_GET['filter_po']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';

$where = [];
if ($filter_status !== '') {
    $s = $conn->real_escape_string($filter_status);
    $where[] = "po.status = '$s'";
}
if (!empty($filter_supplier)) {
    $s = $conn->real_escape_string($filter_supplier);
    $where[] = "sup.company_name LIKE '%$s%'";
}
if (!empty($filter_po)) {
    $s = $conn->real_escape_string($filter_po);
    $where[] = "po.po_number LIKE '%$s%'";
}
if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $where[] = "po.order_date >= '$d'";
}
if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $where[] = "po.order_date <= '$d'";
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$countResult = $conn->query("SELECT COUNT(*) as total FROM purchase_orders po LEFT JOIN suppliers sup ON po.supplier_id = sup.id $whereClause");
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);

$pos = $conn->query("SELECT po.*, sup.company_name as supplier_name, u.name as created_by_name 
    FROM purchase_orders po 
    LEFT JOIN suppliers sup ON po.supplier_id = sup.id 
    LEFT JOIN users u ON po.created_by = u.id 
    $whereClause ORDER BY po.id DESC LIMIT $limit OFFSET $offset");

// Fetch active suppliers and products for the create form
$suppliers = $conn->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name ASC");
$products = $conn->query("SELECT id, name, sku, lkr_price, stock_quantity, unit FROM products WHERE status = 'active' ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Purchase Orders</title>
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
                            <h5>Purchase Orders</h5>
                            <p class="text-muted">Create and manage purchase orders to suppliers</p>
                        </div>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPOModal">
                            <i class="fas fa-plus"></i> New Purchase Order
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
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">PO Number</label>
                                            <input type="text" name="filter_po" class="form-control" placeholder="PO number" value="<?= htmlspecialchars($filter_po) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Supplier</label>
                                            <input type="text" name="filter_supplier" class="form-control" placeholder="Supplier name" value="<?= htmlspecialchars($filter_supplier) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">From Date</label>
                                            <input type="date" name="filter_from_date" class="form-control" value="<?= htmlspecialchars($filter_from_date) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">To Date</label>
                                            <input type="date" name="filter_to_date" class="form-control" value="<?= htmlspecialchars($filter_to_date) ?>">
                                        </div>
                                        
                                        <div class="col-md-1">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Status</label>
                                            <select name="filter_status" class="form-select">
                                                <option value="">All</option>
                                                <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                                                <option value="received" <?= $filter_status === 'received' ? 'selected' : '' ?>>Received</option>
                                                <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            </select>
                                        </div>
                                        <div class="col-md-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter"><i class="fas fa-search me-1"></i> Search</button>
                                            <a href="<?= BASE_URL ?>modules/inventory/purchase_orders.php" class="btn btn-outline-secondary btn-clear"><i class="fas fa-times me-1"></i> Clear</a>
                                        </div>
                                        
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?= $totalRows; ?> Purchase Order<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>PO #</th>
                                            <th>Supplier</th>
                                            <th>Order Date</th>
                                            <th>Expected</th>
                                            <th>Items</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                            <th>Created By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($pos && $pos->num_rows > 0): ?>
                                            <?php while ($po = $pos->fetch_assoc()): 
                                                $itemsCount = $conn->query("SELECT COUNT(*) as cnt FROM purchase_order_items WHERE po_id = {$po['id']}")->fetch_assoc()['cnt'];
                                                $canReceive = $po['status'] === 'approved';
                                            ?>
                                            <tr>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($po['po_number']) ?></span></td>
                                                <td><?= htmlspecialchars($po['supplier_name'] ?? 'N/A') ?></td>
                                                <td><?= date('d/m/Y', strtotime($po['order_date'])) ?></td>
                                                <td><?= $po['expected_date'] ? date('d/m/Y', strtotime($po['expected_date'])) : '—' ?></td>
                                                <td><span class="badge bg-secondary bg-opacity-10 text-dark"><?= $itemsCount ?></span></td>
                                                <td><strong><?= number_format($po['total_amount'], 2) ?></strong></td>
                                                <td>
                                                    <?php
                                                    $statusClasses = ['draft' => 'po-badge-draft', 'pending' => 'po-badge-pending', 'approved' => 'po-badge-approved', 'received' => 'po-badge-received', 'cancelled' => 'po-badge-cancelled'];
                                                    $class = $statusClasses[$po['status']] ?? 'po-badge-draft';
                                                    ?>
                                                    <span class="inv-badge <?= $class ?>"><?= ucfirst($po['status']) ?></span>
                                                </td>
                                                <td><small><?= htmlspecialchars($po['created_by_name'] ?? '—') ?></small></td>
                                                <td>
                                                     <div class="action-btn-group d-flex gap-1">
                                                         <button class="btn btn-view view-po-btn" title="View Details"
                                                             data-id="<?= $po['id'] ?>">
                                                             <i class="fas fa-eye"></i>
                                                         </button>
                                                         <a href="<?= BASE_URL ?>modules/inventory/download_po.php?id=<?= $po['id'] ?>" target="_blank" class="btn btn-view" title="Download / Print" style="color: #6366f1; border-color: rgba(99, 102, 241, 0.2); background: rgba(99, 102, 241, 0.05);">
                                                             <i class="fas fa-file-pdf"></i>
                                                         </a>
                                                         <?php if ($canEdit && in_array($po['status'], ['draft', 'pending'])): ?>
                                                         <button class="btn btn-warning edit-po-btn" title="Edit Purchase Order"
                                                             data-id="<?= $po['id'] ?>" style="color: #d97706; border-color: rgba(217, 119, 6, 0.2); background: rgba(217, 119, 6, 0.05);">
                                                             <i class="fas fa-edit"></i>
                                                         </button>
                                                         <?php endif; ?>
                                                        <?php if ($canEdit && $canReceive): ?>
                                                        <button class="btn btn-success receive-po-btn" title="Receive Stock"
                                                            data-id="<?= $po['id'] ?>">
                                                            <i class="fas fa-truck-loading"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($canEdit && in_array($po['status'], ['draft', 'pending'])): 
                                                            $isSubmit = $po['status'] === 'draft';
                                                            $confirmMsg = $isSubmit ? 'Submit this purchase order for approval?' : 'Cancel this purchase order?';
                                                            $confirmTitle = $isSubmit ? 'Submit Purchase Order' : 'Cancel Purchase Order';
                                                            $confirmIcon = $isSubmit ? 'question' : 'warning';
                                                        ?>
                                                        <form method="POST" style="display:inline" class="confirm-po-form">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                                                            <input type="hidden" name="status" value="<?= $isSubmit ? 'pending' : 'cancelled' ?>">
                                                            <input type="hidden" name="confirm_title" value="<?= $confirmTitle ?>">
                                                            <input type="hidden" name="confirm_msg" value="<?= $confirmMsg ?>">
                                                            <input type="hidden" name="confirm_icon" value="<?= $confirmIcon ?>">
                                                            <button type="button" class="btn <?= $isSubmit ? 'btn-activate' : 'btn-deactivate' ?> btn-confirm-po"
                                                                title="<?= $isSubmit ? 'Submit' : 'Cancel' ?>">
                                                                <i class="fas <?= $isSubmit ? 'fa-paper-plane' : 'fa-ban' ?>"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        <?php if ($canEdit && $po['status'] === 'pending'): ?>
                                                        <form method="POST" style="display:inline" class="confirm-po-form">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                                                            <input type="hidden" name="status" value="approved">
                                                            <input type="hidden" name="confirm_title" value="Approve Purchase Order">
                                                            <input type="hidden" name="confirm_msg" value="Approve this purchase order?">
                                                            <input type="hidden" name="confirm_icon" value="question">
                                                            <button type="button" class="btn btn-view btn-confirm-po" title="Approve"><i class="fas fa-check-circle"></i></button>
                                                        </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="9" class="text-center py-4">No purchase orders found</td></tr>
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

    <!-- Create PO Modal -->
    <div class="modal fade" id="createPOModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>Create Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                <select name="supplier_id" class="form-select" required>
                                    <option value="">— Select Supplier —</option>
                                    <?php if ($suppliers): while ($sup = $suppliers->fetch_assoc()): ?>
                                        <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['company_name']) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Order Date <span class="text-danger">*</span></label>
                                <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Expected Date</label>
                                <input type="date" name="expected_date" class="form-control">
                            </div>
                        </div>
                        
                        <hr>
                        <h6 class="fw-bold mb-3"><i class="fas fa-boxes me-2"></i>Order Items</h6>
                        
                        <div id="po-items-container">
                            <div class="row g-2 po-item-row mb-2 align-items-end">
                                <div class="col-md-5">
                                    <select name="product_id[]" class="form-select" required onchange="autoFillPrice(this)">
                                        <option value="">— Select Product —</option>
                                        <?php 
                                        $products2 = $conn->query("SELECT id, name, sku, lkr_price, stock_quantity, unit FROM products WHERE status = 'active' ORDER BY name ASC");
                                        if ($products2): while ($p = $products2->fetch_assoc()): ?>
                                            <option value="<?= $p['id'] ?>" data-price="<?= $p['lkr_price'] ?>">
                                                <?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)
                                            </option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" name="quantity[]" class="form-control qty-input" placeholder="Qty" min="1" required oninput="updatePOTotals()">
                                </div>
                                <div class="col-md-3">
                                    <input type="number" step="0.01" name="unit_price[]" class="form-control price-input" placeholder="Unit Price" min="0" step="0.01" required oninput="updatePOTotals()">
                                </div>
                                <div class="col-md-1">
                                    <div class="item-total-display text-end fw-bold pt-2">0.00</div>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-outline-danger remove-po-item-btn" style="display:none;"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-po-item-btn">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                        
                        <div class="row mt-3">
                            <div class="col-md-6 offset-md-6">
                                <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                    <span>Sub Total:</span>
                                    <span id="po-subtotal">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between fw-bold fs-5">
                                    <span>Total:</span>
                                    <span id="po-total">0.00</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn"><i class="fas fa-save me-1"></i> Create Purchase Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit PO Modal -->
    <div class="modal fade" id="editPOModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body" id="editPOBody">
                        Loading...
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning save-btn" style="color: #fff;"><i class="fas fa-save me-1"></i> Update Purchase Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View PO Modal -->
    <div class="modal fade" id="viewPOModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>Purchase Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="poDetailsBody">
                    Loading...
                </div>
            </div>
        </div>
    </div>

    <!-- Receive Stock Modal -->
    <div class="modal fade" id="receiveStockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-truck-loading me-2"></i>Receive Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="receive_stock">
                    <input type="hidden" name="po_id" id="receive_po_id">
                    <div class="modal-body" id="receiveStockBody">
                        Loading...
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Receive Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
    // Auto-fill unit price when a product is selected
    function autoFillPrice(select) {
        const opt = select.options[select.selectedIndex];
        const price = opt.getAttribute('data-price') || 0;
        const row = select.closest('.po-item-row');
        row.querySelector('.price-input').value = price;
        updatePOTotals();
    }
    
    function updatePOTotals() {
        // Create Modal Totals
        let createTotal = 0;
        $('#po-items-container .po-item-row').each(function() {
            const qty = parseFloat($(this).find('.qty-input').val()) || 0;
            const price = parseFloat($(this).find('.price-input').val()) || 0;
            const lineTotal = qty * price;
            $(this).find('.item-total-display').text(lineTotal.toFixed(2));
            createTotal += lineTotal;
        });
        $('#po-subtotal').text(createTotal.toFixed(2));
        $('#po-total').text(createTotal.toFixed(2));
        
        // Edit Modal Totals
        if ($('#edit-po-items-container').length > 0) {
            let editTotal = 0;
            $('#edit-po-items-container .po-item-row').each(function() {
                const qty = parseFloat($(this).find('.qty-input').val()) || 0;
                const price = parseFloat($(this).find('.price-input').val()) || 0;
                const lineTotal = qty * price;
                $(this).find('.item-total-display').text(lineTotal.toFixed(2));
                editTotal += lineTotal;
            });
            $('#edit-po-subtotal').text(editTotal.toFixed(2));
            $('#edit-po-total').text(editTotal.toFixed(2));
        }
    }
    
    $(document).ready(function() {
        // Add PO item row
        $('#add-po-item-btn').click(function() {
            const row = $('#po-items-container .po-item-row:first').clone();
            row.find('select').val('');
            row.find('input').val('');
            row.find('.item-total-display').text('0.00');
            row.find('.remove-po-item-btn').show();
            $('#po-items-container').append(row);
            updatePOTotals();
        });
        
        $(document).on('click', '.remove-po-item-btn', function() {
            const container = $(this).closest('.modal-body').find('.po-item-row').parent();
            if (container.find('.po-item-row').length > 1) {
                $(this).closest('.po-item-row').remove();
                updatePOTotals();
            }
        });
        
        // View PO
        $('.view-po-btn').click(function() {
            const poId = $(this).data('id');
            $('#print-po-modal-btn').attr('href', '<?= BASE_URL ?>modules/inventory/download_po.php?id=' + poId);
            $('#poDetailsBody').html('Loading...');
            $('#viewPOModal').modal('show');
            
            $.ajax({
                url: '<?= BASE_URL ?>modules/inventory/download_po.php',
                method: 'GET',
                data: { 
                    id: poId,
                    format: 'html'
                },
                success: function(resp) {
                    $('#poDetailsBody').html(resp);
                    
                    // Remove standalone buttons inside the modal if they exist
                    $('#poDetailsBody').find('button:contains("Print")').remove();
                    $('#poDetailsBody').find('button:contains("Download")').remove();
                },
                error: function() {
                    $('#poDetailsBody').html('Failed to load PO details.');
                }
            });
        });
        
        // Edit PO
        $('.edit-po-btn').click(function() {
            const poId = $(this).data('id');
            $('#editPOBody').html('Loading...');
            $('#editPOModal').modal('show');
            
            $.ajax({
                url: '<?= BASE_URL ?>modules/inventory/get_po_edit_form.php',
                method: 'GET',
                data: { id: poId },
                success: function(resp) {
                    $('#editPOBody').html(resp);
                },
                error: function() {
                    $('#editPOBody').html('Failed to load edit form.');
                }
            });
        });
        
        // Confirm PO status change (Submit/Cancel) with SweetAlert2
        $(document).on('click', '.btn-confirm-po', function() {
            const btn = $(this);
            const form = btn.closest('.confirm-po-form');
            const title = form.find('input[name="confirm_title"]').val();
            const text = form.find('input[name="confirm_msg"]').val();
            const icon = form.find('input[name="confirm_icon"]').val();

            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                showCancelButton: true,
                confirmButtonColor: icon === 'warning' ? '#dc3545' : '#1B1C56',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });

        // Receive Stock
        $('.receive-po-btn').click(function() {
            const poId = $(this).data('id');
            $('#receive_po_id').val(poId);
            $('#receiveStockBody').html('Loading...');
            $('#receiveStockModal').modal('show');
            
            $.ajax({
                url: '<?= BASE_URL ?>modules/inventory/get_po_details.php',
                method: 'GET',
                data: { id: poId, mode: 'receive' },
                success: function(resp) {
                    $('#receiveStockBody').html(resp);
                },
                error: function() {
                    $('#receiveStockBody').html('Failed to load PO items.');
                }
            });
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>