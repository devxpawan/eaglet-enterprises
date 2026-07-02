<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) { ob_end_clean(); }
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
    exit();
}

$quotation_id = (int)$_GET['id'];

// Fetch the quotation
$q_query = "SELECT q.*, c.name as customer_name,
                c.address as customer_address, c.email as customer_email, c.phone as customer_phone,
                c.business_name as customer_business_name
                FROM quotations q
                LEFT JOIN customers c ON q.customer_id = c.customer_id
                WHERE q.quotation_id = ?";

$stmt = $conn->prepare($q_query);
$stmt->bind_param("i", $quotation_id);
$stmt->execute();
$q_result = $stmt->get_result();

if ($q_result->num_rows === 0) {
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
    exit();
}

$quotation = $q_result->fetch_assoc();

// Cannot revise an already revised or accepted quotation
if ($quotation['status'] === 'Revised') {
    $_SESSION['quotation_error'] = "This quotation has already been revised. Please revise the latest revision instead.";
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
    exit();
}
if ($quotation['status'] === 'Accepted') {
    $_SESSION['quotation_error'] = "Accepted quotations cannot be revised.";
    header("Location: " . BASE_URL . "modules/quotations/accepted_quotation_list.php");
    exit();
}

// Fetch quotation items
$itemSql = "SELECT qi.* FROM quotation_items qi WHERE qi.quotation_id = ? ORDER BY qi.id ASC";
$stmt = $conn->prepare($itemSql);
$stmt->bind_param("i", $quotation_id);
$stmt->execute();
$items_result = $stmt->get_result();
$quotation_items = [];
while ($row = $items_result->fetch_assoc()) {
    $quotation_items[] = $row;
}

// Get revision chain for this quotation
$revision_chain = getQuotationRevisionChain($conn, $quotation_id);

// Determine display info
$display_ref_no = !empty($quotation['ref_no'])
    ? htmlspecialchars($quotation['ref_no'])
    : htmlspecialchars(generateRefNo($conn, $quotation_id, $quotation['issue_date'], 'QT'));

$rev_label = ($quotation['revision_no'] == 0) ? 'Original' : 'R' . $quotation['revision_no'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Revise Quotation <?php echo $display_ref_no; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/quotation-list.css" rel="stylesheet" />
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="alert-container">
                    <?php if (isset($_SESSION['quotation_error'])): ?>
                        <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($_SESSION["quotation_error"]); ?>'); });</script>
                        <?php unset($_SESSION['quotation_error']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['quotation_success'])): ?>
                        <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($_SESSION["quotation_success"]); ?>'); });</script>
                        <?php unset($_SESSION['quotation_success']); ?>
                    <?php endif; ?>
                </div>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">Revise Quotation <?php echo $display_ref_no; ?></h5>
                        <p class="text-muted mb-0">Create a new revision of this quotation. The previous version will be preserved.</p>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge-revision"><?php echo $rev_label; ?></span>
                        <span class="status-pill"><i class="fas fa-history"></i> Creating Revision</span>
                    </div>
                </div>

                <div class="revise-banner">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>You are creating Revision #<?php echo count($revision_chain) + 1; ?></strong>
                        for this quotation. The current version will be marked as "Revised" and preserved. The new revision will become the active "Draft".
                    </div>
                </div>

                <div class="quotation-container">
                    <form method="post" action="<?= BASE_URL ?>modules/quotations/process_revise_quotation.php" id="quotationForm">
                        <input type="hidden" name="original_quotation_id" value="<?php echo $quotation_id; ?>">

                        <!-- Quotation Details Section -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-file-invoice text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">Quotation Details</h6>
                                </div>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <div>
                                            <label class="form-label">Status</label>
                                            <select class="form-select bg-light" disabled>
                                                <option value="Draft" selected>Draft (New Revision)</option>
                                            </select>
                                            <input type="hidden" name="quotation_status" value="Draft">
                                        </div>
                                    </div>

                                    <div class="col-md-2">
                                        <div>
                                            <label class="form-label">Currency</label>
                                            <select class="form-select bg-light" disabled>
                                                <option value="lkr" selected>LKR</option>
                                            </select>
                                            <input type="hidden" name="quotation_currency" value="lkr">
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Quotation Date</label>
                                                    <input type="date" class="form-control" name="issue_date"
                                                        value="<?php echo htmlspecialchars($quotation['issue_date']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Expiry Date</label>
                                                    <input type="date" class="form-control" name="due_date"
                                                        value="<?php echo htmlspecialchars($quotation['due_date']); ?>"
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
                                                value="<?php echo htmlspecialchars($quotation['subject'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Information Section -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-user-circle text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">Customer Information</h6>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <input type="hidden" name="customer_id" id="customer_id" value="<?php echo htmlspecialchars($quotation['customer_id']); ?>">
                                        <div>
                                            <label class="form-label">Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="customer_name"
                                                id="customer_name" placeholder="Enter customer name" required
                                                value="<?php echo htmlspecialchars($quotation['customer_name']); ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Business Name</label>
                                            <input type="text" class="form-control" name="customer_business_name"
                                                id="customer_business_name" placeholder="Enter business name"
                                                value="<?php echo htmlspecialchars($quotation['customer_business_name'] ?? ''); ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="customer_email"
                                                id="customer_email" placeholder="Enter email address"
                                                value="<?php echo htmlspecialchars($quotation['customer_email'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="customer_phone"
                                                id="customer_phone" placeholder="Enter phone number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric"
                                                value="<?php echo htmlspecialchars($quotation['customer_phone'] ?? ''); ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Address</label>
                                            <input type="text" class="form-control" name="customer_address"
                                                id="customer_address" placeholder="Enter address"
                                                value="<?php echo htmlspecialchars($quotation['customer_address'] ?? ''); ?>">
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
                                    <h6 class="card-title m-0">Items</h6>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle" id="quotation_table" style="table-layout: fixed; width: 100%;">
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
                                            <?php if (!empty($quotation_items)): ?>
                                                 <?php
                                                $itemIndex = 0;
                                                foreach ($quotation_items as $item):
                                                    $itemIndex++;
                                                    $product_name = $item['product_name'] ?? '';
                                                    $description = $item['description'] ?? '';
                                                    $price = $item['price'] ?? 0;
                                                    $qty = $item['quantity'] ?? 1;
                                                    $discount = $item['discount'] ?? 0;
                                                    $discount_type_item = $item['discount_type'] ?? 'flat';
                                                    $subtotal = $item['total_amount'] ?? 0;
                                                ?>
                                                <tr>
                                                    <td class="text-center text-muted" style="font-size: 13px; font-weight: 500;"><?php echo $itemIndex; ?></td>
                                                    <td>
                                                        <input type="text" name="quotation_product[]" class="form-control item-name" placeholder="Enter item name" value="<?php echo htmlspecialchars($product_name); ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="quotation_product_description[]"
                                                            class="form-control product-description" placeholder="Description"
                                                            value="<?php echo htmlspecialchars($description); ?>">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="number" name="quotation_product_price[]"
                                                                class="form-control price" value="<?php echo number_format($price, 2, '.', ''); ?>" min="0" step="0.01">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="quotation_product_qty[]"
                                                            class="form-control qty" value="<?php echo $qty; ?>" min="1" step="1">
                                                    </td>
                                                    <?php
                                                    if ($discount_type_item === 'percentage') {
                                                        $total_row = ($item['total_amount'] ?? 0) + $discount;
                                                        $display_val = $total_row > 0 ? round(($discount / $total_row) * 100, 2) : 0;
                                                    } else {
                                                        $display_val = $discount;
                                                    }
                                                    ?>
                                                    <td>
                                                        <div class="input-group input-group-sm discount-group">
                                                            <input type="number" name="quotation_product_discount[]"
                                                                class="form-control discount" value="<?php echo number_format($display_val, 2, '.', ''); ?>" min="0" step="0.01">
                                                            <button type="button" class="btn btn-outline-secondary discount-type-btn <?php echo $discount_type_item === 'flat' ? 'active' : ''; ?>" data-type="flat">Rs.</button>
                                                            <button type="button" class="btn btn-outline-secondary discount-type-btn <?php echo $discount_type_item === 'percentage' ? 'active' : ''; ?>" data-type="percentage">%</button>
                                                        </div>
                                                        <input type="hidden" name="quotation_product_discount_type[]" class="discount-type-hidden" value="<?php echo htmlspecialchars($discount_type_item); ?>">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="text" name="quotation_product_sub[]"
                                                                class="form-control subtotal" value="<?php echo number_format($subtotal, 2, '.', ''); ?>" readonly>
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
                                            <?php else: ?>
                                                <tr>
                                                    <td class="text-center text-muted" style="font-size: 13px; font-weight: 500;">1</td>
                                                    <td>
                                                        <input type="text" name="quotation_product[]" class="form-control item-name" placeholder="Enter item name">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="quotation_product_description[]"
                                                            class="form-control product-description" placeholder="Description">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="number" name="quotation_product_price[]"
                                                                class="form-control price" value="0.00" min="0" step="0.01">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <input type="number" name="quotation_product_qty[]"
                                                            class="form-control qty" value="1" min="1" step="1">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm discount-group">
                                                            <input type="number" name="quotation_product_discount[]"
                                                                class="form-control discount" value="0" min="0" step="0.01">
                                                            <button type="button" class="btn btn-outline-secondary discount-type-btn active" data-type="flat">Rs.</button>
                                                            <button type="button" class="btn btn-outline-secondary discount-type-btn" data-type="percentage">%</button>
                                                        </div>
                                                        <input type="hidden" name="quotation_product_discount_type[]" class="discount-type-hidden" value="flat">
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text currency-symbol">Rs.</span>
                                                            <input type="text" name="quotation_product_sub[]"
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
                                            <?php endif; ?>
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
                                                        class="form-control text-end" value="<?php echo number_format($quotation['subtotal'], 2, '.', ''); ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 text-end py-1"><span class="text-muted">Discount:</span></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text currency-symbol">Rs.</span>
                                                    <input type="text" id="discount_amount" name="discount"
                                                        class="form-control text-end" value="<?php echo number_format($quotation['discount'], 2, '.', ''); ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        $qvat = floatval($quotation['vat'] ?? 0);
                                        $qnet = floatval($quotation['subtotal'] ?? 0) - floatval($quotation['discount'] ?? 0);
                                        $qvat_pct = ($qnet > 0) ? ($qvat / $qnet * 100) : 0;
                                        ?>
                                        <div class="row">
                                            <div class="col-6 text-end py-1"><span class="text-muted">VAT %:</span></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" id="vat_percentage" name="vat_percentage"
                                                        class="form-control text-end" value="<?php echo number_format($qvat_pct, 2, '.', ''); ?>" min="0" step="0.01" style="text-align: right;">
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
                                                        class="form-control text-end" value="<?php echo number_format($qvat, 2, '.', ''); ?>" readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row pt-2" style="border-top: 1px solid #eaecf0;">
                                            <div class="col-6 text-end py-1"><strong>Total:</strong></div>
                                            <div class="col-6 py-1">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text currency-symbol">Rs.</span>
                                                    <input type="text" id="total_amount" name="total_amount"
                                                        class="form-control text-end fw-bold" value="<?php echo number_format($quotation['total_amount'], 2, '.', ''); ?>" readonly>
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
                                    <textarea name="notes" class="form-control" rows="8" placeholder="Enter any additional notes or terms for this quotation..."><?php echo htmlspecialchars($quotation['notes'] ?? ''); ?></textarea>
                                </div>
                                <div class="d-flex justify-content-end gap-2 pt-2">
                                    <a href="<?= BASE_URL ?>modules/quotations/draft_quotation_list.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary" id="submit_quotation">
                                        <i class="fas fa-history me-1"></i> Create Revision
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

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

            $('#quotation_table tbody tr').each(function () {
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

            $('#total_amount').val((netTotal + vatAmt).toFixed(2));
        }

        function renumberRows() {
            $('#quotation_table tbody tr').each(function(index) {
                $(this).find('td:first').text(index + 1);
            });
        }

        $('#add_product').click(function () {
            let firstRow = $('#quotation_table tbody tr:first');
            let newRow = firstRow.clone();
            newRow.find('input').val('');
            newRow.find('.item-name').val('');
            newRow.find('.price').val('0.00');
            newRow.find('.qty').val('1');
            newRow.find('.discount').val('0');
            newRow.find('.discount-type-hidden').val('flat');
            newRow.find('.discount-group .discount-type-btn').removeClass('active');
            newRow.find('.discount-group .discount-type-btn[data-type="flat"]').addClass('active');
            newRow.find('.subtotal').val('0.00');
            $('#quotation_table tbody').append(newRow);
            renumberRows();
        });

        $(document).on('click', '.remove_product', function () {
            if ($('#quotation_table tbody tr').length > 1) {
                $(this).closest('tr').remove();
                updateTotals();
                renumberRows();
            } else {
                showToast('warning', 'At least one item is required.');
            }
        });

        $(document).on('input', '#vat_percentage', function () { updateTotals(); });
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

        $(document).on('click', '.discount-type-btn', function () {
            let btnGroup = $(this).closest('.discount-group');
            btnGroup.find('.discount-type-btn').removeClass('active');
            $(this).addClass('active');
            let type = $(this).data('type');
            let row = $(this).closest('tr');
            row.find('.discount-type-hidden').val(type);
            updateRowTotal(row);
        });

        $('input[name="issue_date"]').on('change', function() {
            const qDate = new Date($(this).val());
            if (!isNaN(qDate.getTime())) {
                const expDate = new Date(qDate);
                expDate.setDate(expDate.getDate() + 14);
                $('input[name="due_date"]').val(expDate.toISOString().split('T')[0]);
            }
        });

        $('#customer_phone').on('input', function () {
            $('.validation-error').remove();
            const phone = $(this).val().trim();
            if (phone !== '' && !isValidPhoneNumber(phone)) {
                $(this).after('<div class="text-danger validation-error">Phone number must be 10 digits</div>');
            }
        });

        $('#quotationForm').on('submit', function(e) {
            if (!validateCustomerInfo()) {
                e.preventDefault();
                return false;
            }

            if ($('#quotation_table tbody tr').length === 0) {
                showToast('warning', 'Please add at least one item to the quotation.');
                e.preventDefault();
                return false;
            }

            let isItemValid = true;
            $('#quotation_table tbody tr').each(function () {
                let itemName = $(this).find('.item-name');
                if (itemName.val().trim() === "") {
                    showToast('warning', 'Please enter a name for all quotation items.');
                    isItemValid = false;
                    return false;
                }
            });

            if (!isItemValid) {
                e.preventDefault();
                return false;
            }
        });

        var initialFormData = $('#quotationForm').serialize();
        function checkFormChanges() {
            var currentFormData = $('#quotationForm').serialize();
            if (currentFormData === initialFormData) {
                $('#submit_quotation').prop('disabled', true).addClass('disabled');
            } else {
                $('#submit_quotation').prop('disabled', false).removeClass('disabled');
            }
        }
        checkFormChanges();
        $('#quotationForm').on('change input', 'input, select, textarea', checkFormChanges);
    </script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>
<?php
$conn->close();
?>
</html>