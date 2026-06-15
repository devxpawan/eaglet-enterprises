<?php
require_once __DIR__ . '/../../config/paths.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    exit('Unauthorized');
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($po_id <= 0) {
    exit('Invalid PO ID');
}

// Fetch PO
$po = $conn->query("SELECT * FROM purchase_orders WHERE id = $po_id")->fetch_assoc();
if (!$po) {
    exit('Purchase order not found');
}

// Fetch items
$itemsResult = $conn->query("SELECT * FROM purchase_order_items WHERE po_id = $po_id");
$po_items = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $po_items[] = $row;
    }
}

// Fetch suppliers
$suppliers = $conn->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name ASC");

// Fetch products
$productsResult = $conn->query("SELECT id, name, sku, lkr_price, stock_quantity, unit FROM products WHERE status = 'active' ORDER BY name ASC");
$all_products = [];
if ($productsResult) {
    while ($row = $productsResult->fetch_assoc()) {
        $all_products[] = $row;
    }
}
?>

<input type="hidden" name="action" value="edit">
<input type="hidden" name="po_id" value="<?= $po_id ?>">
<div class="row mb-3">
    <div class="col-md-4">
        <label class="form-label">Supplier <span class="text-danger">*</span></label>
        <select name="supplier_id" class="form-select" required>
            <option value="">— Select Supplier —</option>
            <?php if ($suppliers): while ($sup = $suppliers->fetch_assoc()): ?>
                <option value="<?= $sup['id'] ?>" <?= $po['supplier_id'] == $sup['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sup['company_name']) ?></option>
            <?php endwhile; endif; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Order Date <span class="text-danger">*</span></label>
        <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d', strtotime($po['order_date'])) ?>" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Expected Date</label>
        <input type="date" name="expected_date" class="form-control" value="<?= $po['expected_date'] ? date('Y-m-d', strtotime($po['expected_date'])) : '' ?>">
    </div>
</div>

<hr>
<h6 class="fw-bold mb-3"><i class="fas fa-boxes me-2"></i>Order Items</h6>

<div id="edit-po-items-container">
    <?php 
    if (count($po_items) > 0):
        foreach ($po_items as $index => $item): 
            $line_total = $item['quantity'] * $item['unit_price'];
    ?>
    <div class="row g-2 po-item-row mb-2 align-items-end">
        <div class="col-md-5">
            <select name="product_id[]" class="form-select" required onchange="autoFillPrice(this)">
                <option value="">— Select Product —</option>
                <?php foreach ($all_products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= $p['lkr_price'] ?>" <?= $item['product_id'] == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" name="quantity[]" class="form-control qty-input" placeholder="Qty" min="1" required oninput="updatePOTotals()" value="<?= $item['quantity'] ?>">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" name="unit_price[]" class="form-control price-input" placeholder="Unit Price" min="0" step="0.01" required oninput="updatePOTotals()" value="<?= $item['unit_price'] ?>">
        </div>
        <div class="col-md-1">
            <div class="item-total-display text-end fw-bold pt-2"><?= number_format($line_total, 2, '.', '') ?></div>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger remove-po-item-btn" <?= count($po_items) == 1 ? 'style="display:none;"' : '' ?>><i class="fas fa-trash"></i></button>
        </div>
    </div>
    <?php 
        endforeach; 
    else: 
    ?>
    <!-- Empty row if no items somehow -->
    <div class="row g-2 po-item-row mb-2 align-items-end">
        <div class="col-md-5">
            <select name="product_id[]" class="form-select" required onchange="autoFillPrice(this)">
                <option value="">— Select Product —</option>
                <?php foreach ($all_products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= $p['lkr_price'] ?>">
                        <?= htmlspecialchars($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)
                    </option>
                <?php endforeach; ?>
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
    <?php endif; ?>
</div>

<button type="button" class="btn btn-outline-primary btn-sm mt-2" id="edit-add-po-item-btn">
    <i class="fas fa-plus"></i> Add Item
</button>

<div class="row mt-3">
    <div class="col-md-6 offset-md-6">
        <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
            <span>Sub Total:</span>
            <span id="po-subtotal"><?= number_format($po['sub_total'], 2, '.', '') ?></span>
        </div>
        <div class="d-flex justify-content-between fw-bold fs-5">
            <span>Total:</span>
            <span id="po-total"><?= number_format($po['total_amount'], 2, '.', '') ?></span>
        </div>
    </div>
</div>

<div class="mt-3">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"><?= htmlspecialchars($po['notes'] ?? '') ?></textarea>
</div>

<script>
// We need to attach event to the dynamic add item button for edit form
$('#edit-add-po-item-btn').click(function() {
    // Clone the first row inside the edit container
    const row = $('#edit-po-items-container .po-item-row:first').clone();
    row.find('select').val('');
    row.find('input').val('');
    row.find('.item-total-display').text('0.00');
    row.find('.remove-po-item-btn').show(); // newly added rows can be removed
    
    // Ensure the original first row's remove button is also shown if there are > 1 rows now
    $('#edit-po-items-container .remove-po-item-btn').show();
    
    $('#edit-po-items-container').append(row);
    updatePOTotals();
});
</script>
