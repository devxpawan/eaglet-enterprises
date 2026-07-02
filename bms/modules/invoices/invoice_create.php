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

// Fetch customers only (no products needed)
$customerSql = "SELECT * FROM customers WHERE status = 'active' ORDER BY customer_id DESC";
$customerResult = $conn->query($customerSql);

// Predict the next invoice reference number
$todayDate = date('Y-m-d');
$predictedRefNo = predictInvoiceRefNo($conn, $todayDate);
$nextInvoiceId = getNextAutoIncrement($conn, 'invoices');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Create Invoice</title>

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
                        <h5 class="mb-1">Create Invoice</h5>
                        <p class="text-muted mb-0">Fill in the details below to generate a new invoice</p>
                    </div>
                    <span class="badge bg-primary" id="predictedRefNo">Ref No: <?= htmlspecialchars($predictedRefNo) ?></span>
                </div>
                <div class="invoice-container">
                    <form method="post" action="<?= BASE_URL ?>modules/invoices/process_invoice.php" id="invoiceForm" target="_blank">
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
                                            <label class="form-label">Status</label>
                                            <select name="invoice_status" id="invoice_status" class="form-select">
                                                <option value="Paid">Paid</option>
                                                <option value="Unpaid" selected>Unpaid</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div>
                                            <label class="form-label">Currency</label>
                                            <select class="form-select bg-light" disabled>
                                                <option value="lkr" selected>LKR</option>
                                            </select>
                                            <input type="hidden" name="invoice_currency" value="lkr">
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Invoice Date</label>
                                                    <input type="date" class="form-control" name="invoice_date"
                                                        value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Due Date</label>
                                                    <input type="date" class="form-control" name="due_date"
                                                        value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>"
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
                                            <input type="text" class="form-control" name="subject" placeholder="Enter subject">
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
                                        <input type="hidden" name="customer_id" id="customer_id" value="">
                                        <div>
                                            <label class="form-label">Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="customer_name"
                                                id="customer_name" placeholder="Enter customer name" required>
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Business Name</label>
                                            <input type="text" class="form-control" name="customer_business_name"
                                                id="customer_business_name" placeholder="Enter business name">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="customer_email"
                                                id="customer_email" placeholder="Enter email address">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="customer_phone"
                                                id="customer_phone" placeholder="Enter phone number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Address</label>
                                            <input type="text" class="form-control" name="customer_address"
                                                id="customer_address" placeholder="Enter address">
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
                                            <tr>
                                                <td class="text-center text-muted" style="font-size: 13px; font-weight: 500;">1</td>
                                                <td>
                                                    <input type="text" name="invoice_product[]" class="form-control item-name" placeholder="Enter product or service name">
                                                </td>
                                                <td>
                                                    <input type="text" name="invoice_product_description[]"
                                                        class="form-control product-description" placeholder="Enter description">
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text currency-symbol">Rs.</span>
                                                        <input type="number" name="invoice_product_price[]"
                                                            class="form-control price" value="0.00" min="0" step="0.01">
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="number" name="invoice_product_qty[]"
                                                        class="form-control qty" value="1" min="1" step="1">
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm discount-group">
                                                        <input type="number" name="invoice_product_discount[]"
                                                            class="form-control discount" value="0" min="0" step="0.01">
                                                        <button type="button" class="btn btn-outline-secondary discount-type-btn active" data-type="flat">Rs.</button>
                                                        <button type="button" class="btn btn-outline-secondary discount-type-btn" data-type="percentage">%</button>
                                                    </div>
                                                    <input type="hidden" name="invoice_product_discount_type[]" class="discount-type-hidden" value="flat">
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
                                                        class="form-control text-end" value="0" min="0" step="0.01" style="text-align: right;">
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
                                                    <input type="hidden" id="lkr_total_amount" name="lkr_price"
                                                        value="0.00">
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
                                    <textarea name="notes" class="form-control" rows="8" placeholder="Enter any additional notes or terms for this invoice..."></textarea>
                                </div>
                                <div class="d-flex justify-content-end gap-2 pt-2">
                                    <a href="invoices.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary" id="submit_invoice">
                                        <i class="fas fa-file-invoice me-1"></i> Create Invoice
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
                                    // Reset the pointer for $customerResult
                                    $customerResult->data_seek(0);
                                    while ($customer = $customerResult->fetch_assoc()): ?>
                                        <tr class="customer-row" data-id="<?= $customer['id'] ?? '' ?>"
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

            <!-- Add Customer Success Modal -->
            <div class="modal fade" id="customerAddedModal" tabindex="-1" aria-labelledby="customerAddedModalLabel"
                aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="customerAddedModalLabel">Success</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            Customer has been successfully added to the database.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="<?= BASE_URL ?>js/select2-init.js"></script>
    <script>
        var NEXT_INVOICE_ID = <?= $nextInvoiceId ?>;
        var COMPANY_PREFIX = '<?= htmlspecialchars(strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', getCompanyInfo($conn)['company_name']), 0, 3))) ?>';
        if (!COMPANY_PREFIX) COMPANY_PREFIX = 'IN';
    </script>
    <script>
        $(document).ready(function () {
            // Initialize Select2 for status dropdown
            $('#invoice_status').select2({ minimumResultsForSearch: Infinity });
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

            // Validate customer information
            function validateCustomerInfo() {
                const customerName = $('#customer_name').val().trim();
                const customerEmail = $('#customer_email').val().trim();
                const customerPhone = $('#customer_phone').val().trim();

                // Clear previous error messages
                $('.validation-error').remove();

                let isValid = true;

                // Name validation (required)
                if (customerName === '') {
                    $('#customer_name').after('<div class="text-danger validation-error">Customer name is required</div>');
                    isValid = false;
                }

                // Email validation (optional, but if provided must be valid)
                if (customerEmail !== '' && !isValidEmail(customerEmail)) {
                    $('#customer_email').after('<div class="text-danger validation-error">Invalid email format</div>');
                    isValid = false;
                }

                // Phone validation (optional, but if provided must be 10 digits)
                if (customerPhone !== '' && !isValidPhoneNumber(customerPhone)) {
                    $('#customer_phone').after('<div class="text-danger validation-error">Phone number must be 10 digits</div>');
                    isValid = false;
                }

                return isValid;
            }



            // Get the flat discount amount for a row based on discount type
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

            // Updated Row Total Calculation Function
            function updateRowTotal(row) {
                let price = parseFloat(row.find('.price').val()) || 0;
                let qty = parseFloat(row.find('.qty').val()) || 0;
                let row_total = price * qty;
                let flatDiscount = getFlatDiscount(row);

                // Ensure discount doesn't exceed row total
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

            // Updated Totals Calculation Function
            function updateTotals() {
                let subtotal = 0;
                let totalDiscount = 0;

                $('#invoice_table tbody tr').each(function () {
                    let rowPrice = parseFloat($(this).find('.price').val()) || 0;
                    let rowQty = parseFloat($(this).find('.qty').val()) || 0;
                    let row_total = rowPrice * rowQty;
                    let flatDiscount = getFlatDiscount($(this));

                    // Ensure discount doesn't exceed row total
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

                // Set hidden LKR currency value
                $('#lkr_total_amount').val(grandTotal.toFixed(2));
            }

            // Update status and pay_date when payment status changes
            $("select[name='pay_status']").change(function () {
                if ($(this).val() === "paid") {
                    $('#invoice_status').val("done");
                    $('#pay_date').val(new Date().toISOString().split('T')[0]); // Current date in YYYY-MM-DD format
                } else {
                    $('#invoice_status').val("pending");
                    $('#pay_date').val("");
                }
            });

            // Set initial values based on default selection
            $("select[name='pay_status']").trigger('change');

            // Customer modal functionality
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

            // Customer search functionality
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

            // Select customer functionality
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

            // Real-time validation for email
            $('#customer_email').on('input', function () {
                $('.validation-error').remove();
                const email = $(this).val().trim();
                if (email !== '' && !isValidEmail(email)) {
                    $(this).after('<div class="text-danger validation-error">Invalid email format</div>');
                }
            });

            // Real-time validation for phone
            $('#customer_phone').on('input', function () {
                $('.validation-error').remove();
                const phone = $(this).val().trim();
                if (phone !== '' && !isValidPhoneNumber(phone)) {
                    $(this).after('<div class="text-danger validation-error">Phone number must be 10 digits</div>');
                }
            });

            // Form submission validation
            $('#invoiceForm').on('submit', function (e) {
                // Validate customer information
                if (!validateCustomerInfo()) {
                    e.preventDefault();
                    return false;
                }

                // Validate at least one item is added
                if ($('#invoice_table tbody tr').length === 0) {
                    showToast('warning', 'Please add at least one item to the invoice.');
                    e.preventDefault();
                    return false;
                }

                // Validate item name is filled
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

                // Validate total amount is greater than 0
                let totalAmount = parseFloat($('#total_amount').val()) || 0;
                if (totalAmount <= 0) {
                    showToast('warning', 'Invalid Total Amount!');
                    e.preventDefault();
                    return false;
                }

                // If all validations pass, refresh this page after the new tab opens
                setTimeout(function() {
                    location.reload();
                }, 1500);
                return true;
            });



            // Renumber table rows
            function renumberRows() {
                $('#invoice_table tbody tr').each(function(index) {
                    $(this).find('td:first').text(index + 1);
                });
            }

            // Add item row
            $('#add_product').click(function () {
                let newRow = $('#invoice_table tbody tr:first').clone();
                newRow.find('input').val('');
                newRow.find('.item-name').val('');
                newRow.find('.price').val('0.00');
                newRow.find('.qty').val('1');
                newRow.find('.discount').val('0');
                newRow.find('.discount-type-hidden').val('flat');
                newRow.find('.discount-group .discount-type-btn').removeClass('active');
                newRow.find('.discount-group .discount-type-btn[data-type="flat"]').addClass('active');
                newRow.find('.subtotal').val('0.00');
                $('#invoice_table tbody').append(newRow);

                renumberRows();
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
            $('input[name="invoice_date"]').on('change', function() {
                const invoiceDate = new Date($(this).val());
                if (!isNaN(invoiceDate.getTime())) {
                    const dueDate = new Date(invoiceDate);
                    dueDate.setDate(dueDate.getDate() + 30);
                    
                    const year = dueDate.getFullYear();
                    const month = String(dueDate.getMonth() + 1).padStart(2, '0');
                    const day = String(dueDate.getDate()).padStart(2, '0');
                    
                    $('input[name="due_date"]').val(`${year}-${month}-${day}`);
                }
                // Update predicted ref number
                const dateVal = $(this).val();
                if (dateVal) {
                    const yr = dateVal.substring(2, 4);
                    const padded = String(NEXT_INVOICE_ID).padStart(3, '0');
                    $('#predictedRefNo').text('Ref No: ' + COMPANY_PREFIX + '/IN/J' + yr + '/' + padded);
                }
            });

            // Recalculate on VAT percentage change
            $(document).on('input', '#vat_percentage', function () {
                updateTotals();
            });

            // Update on price, qty or discount change
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
        });
    </script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>
<?php
// Close database connections
$customerResult->close();
$conn->close();
?>