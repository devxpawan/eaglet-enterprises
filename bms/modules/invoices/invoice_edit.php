<?php
require_once __DIR__ . '/../../config/paths.php';

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

$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
if ($current_user_role !== 1 && $current_user_role !== 3) {
    $_SESSION['message'] = "You do not have permission to edit invoices.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['message'] = "Invoice ID is required.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
}

$invoice_id = (int) $_GET['id'];

$invoiceSql = "SELECT i.*, c.name as customer_name, c.email as customer_email,
               c.phone as customer_phone, c.address as customer_address
               FROM invoices i
               LEFT JOIN customers c ON i.customer_id = c.customer_id
               WHERE i.invoice_id = ?";
$stmt = $conn->prepare($invoiceSql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoiceResult = $stmt->get_result();

if ($invoiceResult->num_rows === 0) {
    $_SESSION['message'] = "Invoice not found.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
}

$invoice = $invoiceResult->fetch_assoc();

if ($invoice['status'] !== 'pending' && $invoice['status'] !== null && $invoice['status'] !== '') {
    $_SESSION['message'] = "Only pending invoices can be edited.";
    $_SESSION['message_type'] = "warning";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
}

$itemsSql = "SELECT ii.*, p.name as product_name, p.lkr_price, p.usd_price
             FROM invoice_items ii
             LEFT JOIN products p ON ii.product_id = p.id
             WHERE ii.invoice_id = ?
             ORDER BY ii.item_id ASC";
$stmt = $conn->prepare($itemsSql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$invoice_items = [];
while ($row = $itemsResult->fetch_assoc()) {
    $invoice_items[] = $row;
}

$productSql = "SELECT id, name, description, lkr_price, usd_price FROM products WHERE status = 'active' ORDER BY name ASC";
$productResult = $conn->query($productSql);

$customerSql = "SELECT customer_id, name, email, phone, address, business_name FROM customers WHERE status = 'Active' ORDER BY name ASC";
$customerResult = $conn->query($customerSql);

$editItemPrices = [];
foreach ($invoice_items as $item) {
    $productId = $item['product_id'];
    if ($productId) {
        $editItemPrices[$productId] = [
            'lkr_price' => $item['lkr_price'],
            'usd_price' => $item['usd_price']
        ];
    }
}

// Calculate VAT percentage from stored values
$invoice_vat = floatval($invoice['vat'] ?? 0);
$invoice_subtotal = floatval($invoice['subtotal'] ?? 0);
$invoice_discount = floatval($invoice['discount'] ?? 0);
$invoice_net = $invoice_subtotal - $invoice_discount;
$invoice_vat_pct = ($invoice_net > 0) ? ($invoice_vat / $invoice_net * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Edit Invoice #<?= htmlspecialchars($invoice_id) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .invoice-container {
            margin: 0;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06), 0 1px 2px rgba(16, 24, 40, 0.04);
            border: 1px solid #eaecf0;
            padding: 28px 32px;
            transition: box-shadow 0.15s ease;
        }

        .invoice-container:hover {
            box-shadow: 0 4px 8px rgba(16, 24, 40, 0.06), 0 2px 4px rgba(16, 24, 40, 0.04);
        }

        .form-label {
            font-weight: 600;
            color: #344054;
            font-size: 13px;
            margin-bottom: 6px;
            letter-spacing: 0.01em;
        }

        .form-control, .form-select {
            font-size: 14px;
            color: #101828;
            border-color: #d0d5dd;
            border-radius: 8px;
            transition: all 0.15s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .form-control:hover, .form-select:hover {
            border-color: #98a2b3;
        }

        .card {
            border-radius: 12px;
            border: 1px solid #eaecf0;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
            transition: box-shadow 0.15s ease;
        }

        .card:hover {
            box-shadow: 0 4px 8px rgba(16, 24, 40, 0.06);
        }

        .card-title {
            font-size: 15px;
            color: #101828;
            letter-spacing: -0.01em;
        }

        .card-body {
            padding: 20px 24px;
        }

        #invoice_table {
            margin-bottom: 0;
        }

        #invoice_table thead th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #ffffffff;
            font-weight: 600;
            background: #303361;
            border-bottom: 1px solid #eaecf0;
            padding: 10px 12px;
            white-space: nowrap;
        }

        .discount-group .discount-type-btn {
            font-size: 10px;
            padding: 0 6px;
            height: 28px;
            line-height: 26px;
            border-radius: 0;
        }
        .discount-group .discount-type-btn:first-of-type {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .discount-group .discount-type-btn:last-of-type {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        .discount-group .discount-type-btn.active {
            background: #303361;
            border-color: #303361;
            color: #fff;
        }
        .discount-group input.form-control.discount {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            min-width: 50px;
        }

        #invoice_table tbody td {
            padding: 10px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #f2f4f7;
        }

        #invoice_table tbody tr:last-child td {
            border-bottom: none;
        }

        #invoice_table .remove_product {
            width: 30px;
            height: 30px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 13px;
        }

        .validation-error {
            color: #f04438;
            font-size: 12px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .validation-error::before {
            content: "⚠";
            font-size: 11px;
        }

        .customer-modal {
            display: none;
            position: fixed;
            z-index: 1055;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(52, 64, 84, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            animation: fadeIn 0.15s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(12px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .customer-modal-content {
            width: 95%;
            max-width: 1200px;
            margin: 3% auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(16, 24, 40, 0.12), 0 8px 24px rgba(16, 24, 40, 0.08);
            padding: 0;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.2s ease;
        }

        .customer-modal-content .modal-header-sticky {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #fff;
            padding: 20px 24px 16px;
            border-bottom: 1px solid #eaecf0;
        }

        .customer-modal-content .modal-body-scroll {
            padding: 16px 24px 24px;
        }

        .customer-modal-content .input-group {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #fff;
            padding-bottom: 12px;
        }

        .totals-section {
            background: #f9fafb;
            padding: 16px 20px;
            border-radius: 12px;
            border: 1px solid #eaecf0;
            min-width: 280px;
        }

        .totals-section .form-control {
            font-weight: 600;
            background: #fff;
            border-color: #d0d5dd;
        }

        .totals-section .row {
            margin-bottom: 6px;
        }

        .totals-section .row:last-child {
            margin-bottom: 0;
        }

        .totals-section .currency-symbol {
            color: #667085;
        }

        input.form-control,
        select.form-select {
            height: 40px;
            padding: 8px 12px;
        }

        textarea.form-control {
            border-radius: 8px;
            border-color: #d0d5dd;
            font-size: 14px;
            resize: vertical;
        }

        textarea.form-control:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .btn {
            font-weight: 600;
            font-size: 14px;
            padding: 8px 18px;
            border-radius: 8px;
            transition: all 0.15s ease;
            letter-spacing: 0.01em;
        }

        .btn-primary {
            background: #3B82F6;
            border-color: #3B82F6;
        }

        .btn-primary:hover {
            background: #2563EB;
            border-color: #2563EB;
            box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
            transform: translateY(-1px);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-outline-success {
            color: #079455;
            border-color: #d0d5dd;
            background: #fff;
        }

        .btn-outline-success:hover {
            background: #f6fef9;
            border-color: #079455;
            color: #079455;
        }

        .btn-outline-primary {
            color: #3B82F6;
            border-color: #d0d5dd;
            background: #fff;
        }

        .btn-outline-primary:hover {
            background: #f5f5ff;
            border-color: #3B82F6;
            color: #3B82F6;
        }

        .btn-outline-danger {
            color: #f04438;
            border-color: #d0d5dd;
            background: #fff;
        }

        .btn-outline-danger:hover {
            background: #fffbfa;
            border-color: #f04438;
            color: #f04438;
        }

        .btn-outline-secondary {
            color: #344054;
            border-color: #d0d5dd;
            background: #fff;
        }

        .btn-outline-secondary:hover {
            background: #f9fafb;
            border-color: #98a2b3;
            color: #101828;
        }

        .btn-sm {
            font-size: 13px;
            padding: 6px 14px;
        }

        .input-group .input-group-text {
            background: #f9fafb;
            border-color: #d0d5dd;
            color: #667085;
            font-weight: 500;
            font-size: 14px;
        }

        .input-group .form-control {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .input-group:not(.has-validation) > .form-control:not(:last-child) {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 8px !important;
            border-color: #d0d5dd !important;
            min-height: 40px !important;
        }

        .select2-container--bootstrap-5.select2-container--focus .select2-selection {
            border-color: #3B82F6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12) !important;
        }

        .table {
            --bs-table-hover-bg: #f9fafb;
        }

        .table-hover tbody tr {
            transition: background 0.1s ease;
        }

        .select-customer-btn {
            white-space: nowrap;
        }

        .page-header {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4ff 100%);
            border-bottom: 1px solid #eaecf0;
            padding: 20px 32px;
            margin-bottom: 24px;
        }

        .page-header h5 {
            font-size: 22px;
            font-weight: 700;
            color: #101828;
            letter-spacing: -0.02em;
        }

        .page-header .text-muted {
            font-size: 14px;
            color: #667085;
        }

        .close-modal {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.15s ease;
            cursor: pointer;
            color: #667085;
        }

        .close-modal:hover {
            background: #f2f4f7;
            color: #344054;
        }
        .customer-modal .table thead th {
            background: #303361;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 10px 16px;
            white-space: nowrap;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background: #fff8e1;
            color: #b78103;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="alert-container">
                    <?php if (isset($_SESSION['invoice_error'])): ?>
                        <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($_SESSION["invoice_error"]); ?>'); });</script>
                        <?php unset($_SESSION['invoice_error']); ?>
                    <?php endif; ?>
                </div>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">Edit Invoice #<?= htmlspecialchars($invoice_id) ?></h5>
                        <p class="text-muted mb-0">Update customer, products, dates or notes for this pending invoice</p>
                    </div>
                    <span class="status-pill"><i class="fas fa-clock"></i> Pending</span>
                </div>
                <div class="invoice-container">
                    <form method="post" action="<?= BASE_URL ?>modules/invoices/process_invoice_edit.php" id="invoiceEditForm">
                        <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice_id) ?>">

                        <!-- Invoice Details Section -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-file-invoice text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">Invoice Details</h6>
                                </div>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <div>
                                            <label class="form-label">Currency</label>
                                            <select class="form-select bg-light" disabled>
                                                <option value="lkr" selected>LKR</option>
                                            </select>
                                            <input type="hidden" name="invoice_currency" value="lkr">
                                        </div>
                                    </div>
                                    <div class="col-md-9">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Invoice Date</label>
                                                    <input type="date" class="form-control" name="invoice_date"
                                                        value="<?= htmlspecialchars(isset($invoice['issue_date']) ? date('Y-m-d', strtotime($invoice['issue_date'])) : date('Y-m-d')) ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Due Date</label>
                                                    <input type="date" class="form-control" name="due_date"
                                                        value="<?= htmlspecialchars(isset($invoice['due_date']) ? date('Y-m-d', strtotime($invoice['due_date'])) : date('Y-m-d', strtotime('+30 days'))) ?>"
                                                        required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Information Section -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-user-circle text-primary" style="font-size: 18px;"></i>
                                        <h6 class="card-title m-0">Customer Information</h6>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="select_existing_customer">
                                        <i class="fas fa-users me-1"></i> Select Customer
                                    </button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <input type="hidden" name="customer_id" id="customer_id" value="<?= htmlspecialchars($invoice['customer_id'] ?? '') ?>">
                                        <div>
                                            <label class="form-label">Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="customer_name"
                                                id="customer_name" placeholder="Enter customer name"
                                                value="<?= htmlspecialchars($invoice['customer_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="customer_email"
                                                id="customer_email" placeholder="customer@example.com"
                                                value="<?= htmlspecialchars($invoice['customer_email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="customer_phone"
                                                id="customer_phone" placeholder="+94 77 123 4567"
                                                value="<?= htmlspecialchars($invoice['customer_phone'] ?? '') ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Address</label>
                                            <input type="text" class="form-control" name="customer_address"
                                                id="customer_address" placeholder="Enter customer address"
                                                value="<?= htmlspecialchars($invoice['customer_address'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Products Section -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-box text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">Products</h6>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle" id="invoice_table" style="table-layout: fixed; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="width: 46px;">#</th>
                                                <th style="width: 220px;">Product</th>
                                                <th style="width: 200px;">Description</th>
                                                <th style="width: 110px;">Price</th>
                                                <th style="width: 60px;">Qty</th>
                                                <th style="width: 130px;">Discount</th>
                                                <th style="width: 110px;">Subtotal</th>
                                                <th style="width: 36px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                             <?php foreach ($invoice_items as $index => $item): ?>
                                                <tr>
                                                    <td class="text-center text-muted" style="font-size: 13px; font-weight: 500;"><?= $index + 1 ?></td>
                                                    <input type="hidden" name="invoice_item_id[]" value="<?= $item['item_id'] ?? 0 ?>">
                                                    <td>
                                                        <select name="invoice_product[]" class="form-select product-select">
                                                            <option value="">-- Select Product --</option>
                                                            <?php
                                                            $productResult->data_seek(0);
                                                            $listedIds = [];
                                                            while ($product = $productResult->fetch_assoc()):
                                                                $listedIds[] = (int) $product['id']; ?>
                                                                <option value="<?= $product['id'] ?>"
                                                                    data-lkr-price="<?= $product['lkr_price'] ?>"
                                                                    data-description="<?= htmlspecialchars($product['description']) ?>"
                                                                    <?= ((int)$item['product_id'] === (int)$product['id']) ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($product['name']) ?>
                                                                </option>
                                                            <?php endwhile; ?>
                                                            <?php if (!empty($item['product_id']) && !in_array((int) $item['product_id'], $listedIds, true)): ?>
                                                                <option value="<?= (int) $item['product_id'] ?>"
                                                                    data-lkr-price="<?= htmlspecialchars($item['lkr_price'] ?? 0) ?>"
                                                                    data-description="<?= htmlspecialchars($item['product_description'] ?? '') ?>"
                                                                    selected>
                                                                    <?= htmlspecialchars($item['product_name'] ?? 'Unknown Product') ?> (inactive)
                                                                </option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="invoice_product_description[]"
                                                            class="form-control product-description" placeholder="Description"
                                                            value="<?= htmlspecialchars($item['description'] ?? '') ?>">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="number" name="invoice_product_price[]"
                                                                class="form-control price"
                                                                value="<?= htmlspecialchars(number_format((float)($item['total_amount'] / max(1, (int)$item['quantity'])) + ((float)($item['discount'] ?? 0) / max(1, (int)$item['quantity'])), 2, '.', '')) ?>"
                                                                step="0.01">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="invoice_product_qty[]"
                                                            class="form-control qty"
                                                            value="<?= htmlspecialchars((int)($item['quantity'] ?? 1)) ?>" min="1" step="1">
                                                    </td>
                                                    <?php
                                                    $edit_discount_type = $item['discount_type'] ?? 'flat';
                                                    $edit_discount_val = floatval($item['discount'] ?? 0);
                                                    if ($edit_discount_type === 'percentage') {
                                                        $total_row = ($item['total_amount'] ?? 0) + $edit_discount_val;
                                                        $display_val = $total_row > 0 ? round(($edit_discount_val / $total_row) * 100, 2) : 0;
                                                    } else {
                                                        $display_val = $edit_discount_val;
                                                    }
                                                    ?>
                                                    <td>
                                                        <div class="input-group input-group-sm discount-group">
                                                            <input type="number" name="invoice_product_discount[]"
                                                                class="form-control discount"
                                                                value="<?= htmlspecialchars(number_format($display_val, 2, '.', '')) ?>" min="0" step="0.01">
                                                            <button type="button" class="btn btn-outline-secondary discount-type-btn <?= $edit_discount_type === 'flat' ? 'active' : '' ?>" data-type="flat">Rs.</button>
                                                            <button type="button" class="btn btn-outline-secondary discount-type-btn <?= $edit_discount_type === 'percentage' ? 'active' : '' ?>" data-type="percentage">%</button>
                                                        </div>
                                                        <input type="hidden" name="invoice_product_discount_type[]" class="discount-type-hidden" value="<?= htmlspecialchars($edit_discount_type) ?>">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="text" name="invoice_product_sub[]"
                                                                class="form-control subtotal" value="0.00" readonly>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button"
                                                            class="btn btn-outline-danger btn-sm remove_product">
                                                            <i class="fas fa-trash-alt" style="font-size: 12px;"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-between align-items-start mt-3 flex-wrap gap-3">
                                    <button type="button" id="add_product" class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-plus me-1"></i> Add Product
                                    </button>

                                    <div class="totals-section">
                                        <div class="row">
                                            <div class="col-6 text-end py-1"><span class="text-muted">Subtotal:</span></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text currency-symbol">Rs.</span>
                                                    <input type="text" id="subtotal_amount" name="subtotal"
                                                        class="form-control text-end" value="0.00" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 text-end py-1"><span class="text-muted">Discount:</span></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text currency-symbol">Rs.</span>
                                                    <input type="text" id="discount_amount" name="discount"
                                                        class="form-control text-end" value="0.00" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 text-end py-1"><span class="text-muted">VAT %:</span></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" id="vat_percentage" name="vat_percentage"
                                                        class="form-control text-end" value="<?= htmlspecialchars(number_format($invoice_vat_pct, 2, '.', '')) ?>" min="0" step="0.01" style="text-align: right;">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 text-end py-1"><span class="text-muted">VAT Amount:</span></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text currency-symbol">Rs.</span>
                                                    <input type="text" id="vat_amount" name="vat_amount"
                                                        class="form-control text-end" value="0.00" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row pt-2" style="border-top: 1px solid #eaecf0;">
                                            <div class="col-6 text-end py-1"><strong>Total:</strong></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text currency-symbol">Rs.</span>
                                                    <input type="text" id="total_amount" name="total_amount"
                                                        class="form-control text-end fw-bold" value="0.00" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes & Submit Section -->
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-sticky-note text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">Additional Notes</h6>
                                </div>
                                <div class="mb-3">
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Enter any additional notes or terms for this invoice..."><?= htmlspecialchars($invoice['notes'] ?? '') ?></textarea>
                                </div>
                                <div class="d-flex justify-content-end gap-2 pt-2">
                                    <a href="<?= BASE_URL ?>modules/invoices/pending_invoice_list.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary" id="submit_invoice">
                                        <i class="fas fa-save me-1"></i> Update Invoice
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </main>

            <!-- Customer Selection Modal -->
            <div id="customerModal" class="customer-modal">
                <div class="customer-modal-content">
                    <div class="modal-header-sticky d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-users text-primary" style="font-size: 20px;"></i>
                            <h5 class="m-0 fw-bold" style="font-size: 16px;">Select Customer</h5>
                        </div>
                        <span class="close-modal" style="cursor:pointer;font-size:22px;line-height:1;color:#98a2b3;">&times;</span>
                    </div>
                    <div class="modal-body-scroll">
                        <div class="input-group mb-4">
                            <span class="input-group-text"><i class="fas fa-search" style="color:#98a2b3;"></i></span>
                            <input type="text" id="customerSearch" class="form-control"
                                placeholder="Search by name, email, phone, or business...">
                            <button class="btn btn-primary" type="button" id="searchCustomerBtn"><i class="fas fa-search me-1"></i> Search</button>
                            <button class="btn btn-outline-secondary" type="button" id="clearSearchBtn"><i class="fas fa-times me-1"></i> Clear</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th>Business Name</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Address</th>
                                        <th style="width: 80px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $customerResult->data_seek(0);
                                    while ($customer = $customerResult->fetch_assoc()): ?>
                                        <tr class="customer-row" data-id="<?= $customer['customer_id'] ?? '' ?>"
                                            data-name="<?= htmlspecialchars($customer['name'] ?? '') ?>"
                                            data-email="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($customer['phone'] ?? '') ?>"
                                            data-address="<?= htmlspecialchars($customer['address'] ?? '') ?>">
                                            <td class="text-muted" style="font-size: 13px;"><?= htmlspecialchars($customer['customer_id'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($customer['business_name'] ?? '') ?></td>
                                            <td><span class="fw-medium"><?= htmlspecialchars($customer['name'] ?? '') ?></span></td>
                                            <td><span style="font-size: 13px;"><?= htmlspecialchars($customer['email'] ?? '') ?></span></td>
                                            <td><span style="font-size: 13px;"><?= htmlspecialchars($customer['phone'] ?? '') ?></span></td>
                                            <td><span style="font-size: 13px;"><?= htmlspecialchars($customer['address'] ?? '') ?></span></td>
                                            <td>
                                                <button type="button"
                                                    class="btn btn-sm btn-primary select-customer-btn px-3"
                                                    style="font-size: 12px;">Select</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function () {
            // Email validation function
            function isValidEmail(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(email);
            }

            // Phone number validation function (10 digits)
            function isValidPhoneNumber(phone) {
                const phoneRegex = /^\d{10}$/;
                return phoneRegex.test(phone);
            }

            function validateCustomerInfo() {
                const customerName = $('#customer_name').val().trim();
                const customerEmail = $('#customer_email').val().trim();
                const customerPhone = $('#customer_phone').val().trim();

                $('.validation-error').remove();
                let isValid = true;

                if (customerName === '') {
                    $('#customer_name').after('<div class="text-danger validation-error">Customer name is required</div>');
                    isValid = false;
                }

                if (customerEmail !== '' && !isValidEmail(customerEmail)) {
                    $('#customer_email').after('<div class="text-danger validation-error">Invalid email format</div>');
                    isValid = false;
                }

                if (customerPhone !== '' && !isValidPhoneNumber(customerPhone)) {
                    $('#customer_phone').after('<div class="text-danger validation-error">Phone number must be 10 digits</div>');
                    isValid = false;
                }

                return isValid;
            }

            function updateProductPrice(row) {
                var selectedOption = row.find('.product-select option:selected');
                if (selectedOption.val() === "") return;

                var priceField = row.find('.price');
                var descriptionField = row.find('.product-description');
                
                // Check if it's a custom tag or a real product
                if (selectedOption.data('lkr-price') !== undefined) {
                    // Real product
                    var price = parseFloat(selectedOption.data('lkr-price') || 0);
                    var description = selectedOption.data('description') || '';

                    priceField.val(isNaN(price) ? '0.00' : price.toFixed(2));
                    descriptionField.val(description);
                } else {
                    // Custom product - don't overwrite if user has typed something
                }
                updateRowTotal(row);
            }

            function getFlatDiscount(row) {
                let price = parseFloat(row.find('.price').val()) || 0;
                let qty = parseFloat(row.find('.qty').val()) || 0;
                let discountVal = parseFloat(row.find('.discount').val()) || 0;
                let discountType = row.find('.discount-type-hidden').val() || 'flat';
                let row_total = price * qty;
                if (discountType === 'percentage') {
                    return row_total * discountVal / 100;
                }
                return discountVal;
            }

            function updateRowTotal(row) {
                let price = parseFloat(row.find('.price').val()) || 0;
                let qty = parseFloat(row.find('.qty').val()) || 0;
                let row_total = price * qty;
                let flatDiscount = getFlatDiscount(row);

                if (flatDiscount > row_total) {
                    flatDiscount = row_total;
                    if ((row.find('.discount-type-hidden').val() || 'flat') === 'percentage') {
                        let pct = row_total > 0 ? 100 : 0;
                        row.find('.discount').val(pct.toFixed(2));
                    } else {
                        row.find('.discount').val(flatDiscount);
                    }
                }

                let subtotal = row_total - flatDiscount;
                row.find('.subtotal').val(subtotal.toFixed(2));
                updateTotals();
            }

            function updateTotals() {
                let subtotal = 0;
                let totalDiscount = 0;

                $('#invoice_table tbody tr').each(function () {
                    let rowPrice = parseFloat($(this).find('.price').val()) || 0;
                    let rowQty = parseFloat($(this).find('.qty').val()) || 0;
                    let row_total = rowPrice * rowQty;
                    let flatDiscount = getFlatDiscount($(this));

                    if (flatDiscount > row_total) {
                        flatDiscount = row_total;
                        if (($(this).find('.discount-type-hidden').val() || 'flat') === 'percentage') {
                            let pct = row_total > 0 ? 100 : 0;
                            $(this).find('.discount').val(pct.toFixed(2));
                        } else {
                            $(this).find('.discount').val(flatDiscount);
                        }
                    }

                    let rowSubtotal = row_total - flatDiscount;
                    $(this).find('.subtotal').val(rowSubtotal.toFixed(2));

                    subtotal += row_total;
                    totalDiscount += flatDiscount;
                });

                $('#subtotal_amount').val(subtotal.toFixed(2));
                $('#discount_amount').val(totalDiscount.toFixed(2));

                let netTotal = subtotal - totalDiscount;
                let vatPct = parseFloat($('#vat_percentage').val()) || 0;
                let vatAmt = netTotal * vatPct / 100;
                $('#vat_amount').val(vatAmt.toFixed(2));

                let grandTotal = netTotal + vatAmt;
                $('#total_amount').val(grandTotal.toFixed(2));
            }

            // Customer modal
            var customerModal = document.getElementById("customerModal");
            $("#select_existing_customer").click(function () {
                customerModal.style.display = "block";
            });
            $(".close-modal").click(function () {
                customerModal.style.display = "none";
            });
            $(window).click(function (event) {
                if (event.target == customerModal) {
                    customerModal.style.display = "none";
                }
            });

            // Customer search
            function filterCustomers() {
                var value = $("#customerSearch").val().toLowerCase();
                $(".customer-row").filter(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                });
            }
            $("#customerSearch").on("keypress", function (e) {
                if (e.which == 13) filterCustomers();
            });
            $("#searchCustomerBtn").on("click", filterCustomers);
            $("#clearSearchBtn").on("click", function () {
                $("#customerSearch").val("");
                $(".customer-row").show();
            });

            // Select customer
            $(".select-customer-btn").click(function () {
                var row = $(this).closest('tr');
                $('#customer_id').val(row.data('id'));
                $('#customer_name').val(row.data('name'));
                $('#customer_email').val(row.data('email'));
                $('#customer_phone').val(row.data('phone'));
                $('#customer_address').val(row.data('address'));
                customerModal.style.display = "none";
            });

            // Real-time email validation
            $('#customer_email').on('input', function () {
                $('.validation-error').remove();
                const email = $(this).val().trim();
                if (email !== '' && !isValidEmail(email)) {
                    $(this).after('<div class="text-danger validation-error">Invalid email format</div>');
                }
            });

            // Real-time phone validation
            $('#customer_phone').on('input', function () {
                $('.validation-error').remove();
                const phone = $(this).val().trim();
                if (phone !== '' && !isValidPhoneNumber(phone)) {
                    $(this).after('<div class="text-danger validation-error">Phone number must be 10 digits</div>');
                }
            });

            // Form submission validation
            $('#invoiceEditForm').on('submit', function (e) {
                if (!validateCustomerInfo()) {
                    e.preventDefault();
                    return false;
                }

                if ($('#invoice_table tbody tr').length === 0) {
                    showToast('warning', 'Please add at least one product to the invoice.');
                    e.preventDefault();
                    return false;
                }

                let isProductValid = true;
                $('#invoice_table tbody tr').each(function () {
                    let productSelect = $(this).find('.product-select');
                    if (productSelect.val() === "") {
                        showToast('warning', 'Please select a product for all invoice lines.');
                        isProductValid = false;
                        return false;
                    }
                });

                if (!isProductValid) {
                    e.preventDefault();
                    return false;
                }

                let totalAmount = parseFloat($('#total_amount').val()) || 0;
                if (totalAmount <= 0) {
                    showToast('warning', 'Invalid Total Amount!');
                    e.preventDefault();
                    return false;
                }

                return true;
            });

            // Product selection change
            $(document).on('change', '.product-select', function () {
                updateProductPrice($(this).closest('tr'));
            });

            function renumberRows() {
                $('#invoice_table tbody tr').each(function (index) {
                    $(this).find('td:first').text(index + 1);
                });
            }

            // Add product row
            $('#add_product').click(function () {
                let firstSelect = $('#invoice_table tbody tr:first').find('.product-select');
                if (firstSelect.hasClass('select2-hidden-accessible')) {
                    firstSelect.select2('destroy');
                }

                let newRow = $('#invoice_table tbody tr:first').clone();
                newRow.find('input').val('');
                newRow.find('.price').val('0.00');
                newRow.find('.qty').val('1');
                newRow.find('.discount').val('0');
                newRow.find('.discount-type-hidden').val('flat');
                newRow.find('.discount-group .discount-type-btn').removeClass('active');
                newRow.find('.discount-group .discount-type-btn[data-type="flat"]').addClass('active');
                newRow.find('.subtotal').val('0.00');
                newRow.find('.product-select').val('');
                newRow.find('.product-description').val('');
                $('#invoice_table tbody').append(newRow);

                $('.product-select').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    tags: true,
                    createTag: function (params) {
                        var term = $.trim(params.term);
                        if (term === '') {
                            return null;
                        }
                        return {
                            id: term,
                            text: term,
                            newTag: true
                        }
                    }
                });

                renumberRows();
                updateTotals();
            });

            // Remove product row
            $(document).on('click', '.remove_product', function () {
                if ($('#invoice_table tbody tr').length > 1) {
                    $(this).closest('tr').remove();
                    updateTotals();
                    renumberRows();
                } else {
                    showToast('warning', 'At least one product is required.');
                }
            });

            // Discount type toggle
            $(document).on('click', '.discount-type-btn', function () {
                let btnGroup = $(this).closest('.discount-group');
                btnGroup.find('.discount-type-btn').removeClass('active');
                $(this).addClass('active');
                let type = $(this).data('type');
                let row = $(this).closest('tr');
                row.find('.discount-type-hidden').val(type);
                updateRowTotal(row);
            });

            // Update due date when invoice date changes
            $('input[name="invoice_date"]').on('change', function () {
                const invoiceDate = new Date($(this).val());
                if (!isNaN(invoiceDate.getTime())) {
                    const dueDate = new Date(invoiceDate);
                    dueDate.setDate(dueDate.getDate() + 30);

                    const year = dueDate.getFullYear();
                    const month = String(dueDate.getMonth() + 1).padStart(2, '0');
                    const day = String(dueDate.getDate()).padStart(2, '0');

                    $('input[name="due_date"]').val(`${year}-${month}-${day}`);
                }
            });

            $(document).on('input', '#vat_percentage', function () {
                updateTotals();
            });

            $(document).on('input', '.price, .qty, .discount', function () {
                if ($(this).hasClass('qty')) {
                    let value = $(this).val();
                    $(this).val(value.replace(/[^0-9]/g, ''));
                }
                updateRowTotal($(this).closest('tr'));
            });

            // Initialize Select2 for products
            $('.product-select').select2({
                theme: 'bootstrap-5',
                width: '100%',
                tags: true,
                createTag: function (params) {
                    var term = $.trim(params.term);
                    if (term === '') {
                        return null;
                    }
                    return {
                        id: term,
                        text: term,
                        newTag: true
                    }
                }
            });
            // Calculate initial totals from existing items
            updateTotals();
        });
    </script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>
<?php
$productResult->close();
$customerResult->close();
$conn->close();
?>