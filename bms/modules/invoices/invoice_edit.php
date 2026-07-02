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

$current_user_id = $_SESSION['user_id'] ?? 0;
$canEditDirectly = isApprover();

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['message'] = "Invoice ID is required.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
}

$invoice_id = (int) $_GET['id'];

if (!$canEditDirectly) {
    $hasApprovedRequest = hasApprovedEditRequest($conn, $invoice_id, $current_user_id);
    if (!$hasApprovedRequest) {
        $hasPendingRequest = hasPendingEditRequest($conn, $invoice_id, $current_user_id);
        if ($hasPendingRequest) {
            $_SESSION['message'] = "Your edit request is pending approval. Please wait for a director to review it.";
            $_SESSION['message_type'] = "warning";
        } else {
            $_SESSION['message'] = "You need approval to edit this invoice. Please submit an edit request.";
            $_SESSION['message_type'] = "info";
            header("Location: " . BASE_URL . "modules/invoices/request_edit.php?id=" . $invoice_id);
            exit();
        }
        header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
        exit();
    }
}

$invoiceSql = "SELECT i.*, c.name as customer_name, c.email as customer_email,
               c.phone as customer_phone, c.address as customer_address, c.business_name as customer_business_name
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

$itemsSql = "SELECT ii.*
             FROM invoice_items ii
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

$customerSql = "SELECT customer_id, name, email, phone, address, business_name FROM customers WHERE status = 'Active' ORDER BY name ASC";
$customerResult = $conn->query($customerSql);

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
    <title>Edit Invoice <?= htmlspecialchars($invoice['invoice_ref_no']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/invoice-list.css" rel="stylesheet" />
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
                        <h5 class="mb-1">Edit Invoice</h5>
                        <p class="text-muted mb-0">Update customer, items, dates or notes for this pending invoice</p>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge bg-primary">Ref No: <?= htmlspecialchars($invoice['invoice_ref_no'] ?? 'N/A') ?></span>
                        <span class="status-pill"><i class="fas fa-clock"></i> Pending</span>
                    </div>
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
                                                        value="<?= htmlspecialchars((!empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00') ? date('Y-m-d', strtotime($invoice['due_date'])) : date('Y-m-d', strtotime('+14 days'))) ?>"
                                                        required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-12">
                                        <div>
                                            <label class="form-label">Subject</label>
                                            <input type="text" class="form-control" name="subject" placeholder="Enter subject"
                                                value="<?= htmlspecialchars($invoice['subject'] ?? '') ?>">
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
                                            <label class="form-label">Business Name</label>
                                            <input type="text" class="form-control" name="customer_business_name"
                                                id="customer_business_name" placeholder="Enter business name"
                                                value="<?= htmlspecialchars($invoice['customer_business_name'] ?? '') ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="customer_email"
                                                id="customer_email" placeholder="Enter email address"
                                                value="<?= htmlspecialchars($invoice['customer_email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="customer_phone"
                                                id="customer_phone" placeholder="Enter phone number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric"
                                                value="<?= htmlspecialchars($invoice['customer_phone'] ?? '') ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Address</label>
                                            <input type="text" class="form-control" name="customer_address"
                                                id="customer_address" placeholder="Enter address"
                                                value="<?= htmlspecialchars($invoice['customer_address'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Items Section -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-box text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">Items</h6>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle" id="invoice_table" style="table-layout: fixed; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="width: 46px;">#</th>
                                                <th style="width: 220px;">Item</th>
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
                                                        <input type="text" name="invoice_product[]" class="form-control item-name" placeholder="Enter product or service name" value="<?= htmlspecialchars($item['product_name'] ?? '') ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="invoice_product_description[]"
                                                            class="form-control product-description" placeholder="Enter description"
                                                            value="<?= htmlspecialchars($item['description'] ?? '') ?>">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="number" name="invoice_product_price[]"
                                                                class="form-control price"
                                                                value="<?= htmlspecialchars(number_format((float)($item['total_amount'] / max(1, (int)$item['quantity'])) + ((float)($item['discount'] ?? 0) / max(1, (int)$item['quantity'])), 2, '.', '')) ?>"
                                                                min="0" step="0.01">
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
                                        <i class="fas fa-plus me-1"></i> Add Item
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
                                    <textarea name="notes" class="form-control" rows="8" placeholder="Enter any additional notes or terms for this invoice..."><?= htmlspecialchars($invoice['notes'] ?? '') ?></textarea>
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
                                            data-business-name="<?= htmlspecialchars($customer['business_name'] ?? '') ?>"
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
                $('#customer_business_name').val(row.data('business-name'));
                $('#customer_email').val(row.data('email'));
                $('#customer_phone').val(row.data('phone'));
                $('#customer_address').val(row.data('address'));
                customerModal.style.display = "none";
            });

            // Form submission validation
            $('#invoiceEditForm').on('submit', function (e) {
                if (!validateCustomerInfo()) {
                    e.preventDefault();
                    return false;
                }

                if ($('#invoice_table tbody tr').length === 0) {
                    showToast('warning', 'Please add at least one item to the invoice.');
                    e.preventDefault();
                    return false;
                }

                let isItemValid = true;
                $('#invoice_table tbody tr').each(function () {
                    let itemName = $(this).find('.item-name');
                    if (itemName.val().trim() === "") {
                        showToast('warning', 'Please enter a name for all invoice items.');
                        isItemValid = false;
                        return false;
                    }
                });

                if (!isItemValid) {
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

            function renumberRows() {
                $('#invoice_table tbody tr').each(function (index) {
                    $(this).find('td:first').text(index + 1);
                });
            }

            // Add item row
            $('#add_product').click(function () {
                let newRow = $('#invoice_table tbody tr:first').clone();
                newRow.find('input').val('');
                newRow.find('.price').val('0.00');
                newRow.find('.qty').val('1');
                newRow.find('.discount').val('0');
                newRow.find('.discount-type-hidden').val('flat');
                newRow.find('.discount-group .discount-type-btn').removeClass('active');
                newRow.find('.discount-group .discount-type-btn[data-type="flat"]').addClass('active');
                newRow.find('.subtotal').val('0.00');
                $('#invoice_table tbody').append(newRow);

                renumberRows();
                updateTotals();
            });

            // Remove item row
            $(document).on('click', '.remove_product', function () {
                if ($('#invoice_table tbody tr').length > 1) {
                    $(this).closest('tr').remove();
                    updateTotals();
                    renumberRows();
                } else {
                    showToast('warning', 'At least one item is required.');
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
                if ($(this).hasClass('price') || $(this).hasClass('discount')) {
                    let value = parseFloat($(this).val());
                    if (value < 0) {
                        $(this).val(0);
                    }
                }
                updateRowTotal($(this).closest('tr'));
            });

            // Calculate initial totals from existing items
            updateTotals();

            // Disable submit button when no changes are made
            var initialFormData = $('#invoiceEditForm').serialize();
            function checkFormChanges() {
                var currentFormData = $('#invoiceEditForm').serialize();
                if (currentFormData === initialFormData) {
                    $('#submit_invoice').prop('disabled', true).addClass('disabled');
                } else {
                    $('#submit_invoice').prop('disabled', false).removeClass('disabled');
                }
            }
            checkFormChanges();
            $('#invoiceEditForm').on('change input', 'input, select, textarea', checkFormChanges);
        });
    </script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>
<?php
$conn->close();
?>