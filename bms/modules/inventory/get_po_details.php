<?php
require_once __DIR__ . '/../../config/paths.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    exit('Unauthorized');
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'view';

if ($po_id <= 0) {
    exit('Invalid PO ID');
}

if ($mode === 'receive') {
    // Return receive stock form
    $po = $conn->query("SELECT po.*, sup.company_name FROM purchase_orders po LEFT JOIN suppliers sup ON po.supplier_id = sup.id WHERE po.id = $po_id")->fetch_assoc();
    if (!$po) exit('Purchase order not found');
    
    $items = $conn->query("SELECT poi.*, p.name as product_name, p.sku, p.unit, p.stock_quantity 
        FROM purchase_order_items poi 
        JOIN products p ON poi.product_id = p.id 
        WHERE poi.po_id = $po_id");
    ?>
    <div class="mb-3">
        <h6 class="fw-bold">PO: <?= htmlspecialchars($po['po_number']) ?> — <?= htmlspecialchars($po['company_name']) ?></h6>
        <p class="text-muted small">Enter the quantities received for each item</p>
    </div>
    <table class="receive-stock-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Ordered</th>
                <th>Previously Received</th>
                <th>Now Receiving</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items && $items->num_rows > 0): ?>
                <?php while ($item = $items->fetch_assoc()): 
                    $remaining = $item['quantity'] - $item['received_quantity'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><small><?= htmlspecialchars($item['sku'] ?? '—') ?></small></td>
                    <td><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                    <td><?= $item['received_quantity'] ?></td>
                    <td>
                        <input type="hidden" name="product_id[<?= $item['id'] ?>]" value="<?= $item['product_id'] ?>">
                        <input type="number" name="received_qty[<?= $item['id'] ?>]" class="form-control qty-input" 
                            value="<?= $remaining ?>" min="0" max="<?= $remaining ?>" required>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center py-3">No items found</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
} else {
    // View mode - full PO details
    $po = $conn->query("SELECT po.*, sup.company_name as supplier_name, sup.contact_person, sup.email as supplier_email, sup.phone as supplier_phone, sup.address as supplier_address, u.name as created_by_name 
        FROM purchase_orders po 
        LEFT JOIN suppliers sup ON po.supplier_id = sup.id 
        LEFT JOIN users u ON po.created_by = u.id 
        WHERE po.id = $po_id")->fetch_assoc();
    
    if (!$po) exit('Purchase order not found');
    
    $items = $conn->query("SELECT poi.*, p.name as product_name, p.sku, p.unit, p.stock_quantity 
        FROM purchase_order_items poi 
        JOIN products p ON poi.product_id = p.id 
        WHERE poi.po_id = $po_id");
    ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <h6 class="fw-bold mb-1"><?= htmlspecialchars($po['po_number']) ?></h6>
            <p class="text-muted small">Order Date: <?= date('d/m/Y', strtotime($po['order_date'])) ?></p>
            <?php if ($po['expected_date']): ?>
                <p class="text-muted small">Expected: <?= date('d/m/Y', strtotime($po['expected_date'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="col-md-6 text-md-end">
            <h6 class="fw-bold"><?= htmlspecialchars($po['supplier_name'] ?? 'N/A') ?></h6>
            <p class="text-muted small"><?= nl2br(htmlspecialchars($po['supplier_address'] ?? '')) ?></p>
            <p class="text-muted small"><?= htmlspecialchars($po['supplier_email'] ?? '') ?> | <?= htmlspecialchars($po['supplier_phone'] ?? '') ?></p>
        </div>
    </div>
    
    <hr>
    
    <div class="mb-3">
        <span class="inv-badge <?= 'po-badge-' . $po['status'] ?>"><?= ucfirst($po['status']) ?></span>
        <small class="text-muted ms-2">Created by: <?= htmlspecialchars($po['created_by_name'] ?? '—') ?></small>
    </div>
    
    <table class="table table-sm">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>SKU</th>
                <th class="text-center">Quantity</th>
                <th class="text-end">Unit Price</th>
                <th class="text-end">Total</th>
                <th class="text-center">Received</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if ($items && $items->num_rows > 0):
                $idx = 1;
                while ($item = $items->fetch_assoc()): 
            ?>
            <tr>
                <td><?= $idx++ ?></td>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td><small><?= htmlspecialchars($item['sku'] ?? '—') ?></small></td>
                <td class="text-center"><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?></td>
                <td class="text-end"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-end"><?= number_format($item['total_price'] ?? ($item['quantity'] * $item['unit_price']), 2) ?></td>
                <td class="text-center"><?= $item['received_quantity'] ?></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="7" class="text-center">No items</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="text-end fw-bold">Total:</td>
                <td class="text-end fw-bold"><?= number_format($po['total_amount'], 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    
    <?php if (!empty($po['notes'])): ?>
    <div class="mt-3">
        <strong>Notes:</strong>
        <p class="text-muted"><?= nl2br(htmlspecialchars($po['notes'])) ?></p>
    </div>
    <?php endif; ?>
    <?php
}
$conn->close();
?>