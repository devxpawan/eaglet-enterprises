<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    // Force redirect to login page
    header("Location: " . BASE_URL . "signin.php");
    exit(); // Stop execution immediately
}

// Include the database connection file
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php'; // Include helper functions

// Initialize filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_pay_status = isset($_GET['filter_pay_status']) ? trim($_GET['filter_pay_status']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$filter_customer = isset($_GET['filter_customer']) ? trim($_GET['filter_customer']) : '';
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build base SQL
$baseFrom = "FROM invoices i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN (
                 SELECT p1.invoice_id, p1.payment_method, p1.payment_date, p1.pay_by, u1.name as paid_by_name
                 FROM payments p1
                 LEFT JOIN users u1 ON p1.pay_by = u1.id
                 WHERE p1.payment_id = (
                     SELECT MAX(p2.payment_id) FROM payments p2 WHERE p2.invoice_id = p1.invoice_id
                 )
             ) p ON i.invoice_id = p.invoice_id
             LEFT JOIN users u2 ON i.created_by = u2.id";

$selectCols = "i.*, c.name as customer_name, c.business_name as customer_business_name,
               p.payment_method, p.payment_date, p.pay_by, p.paid_by_name,
               u2.name as creator_name";

// Build WHERE conditions
$conditions = [];
if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(i.invoice_id LIKE '%$s%' OR i.quotation_ref_no LIKE '%$s%')";
}
if ($filter_status !== '') {
    $s = $conn->real_escape_string($filter_status);
    $conditions[] = "i.status = '$s'";
}
if ($filter_pay_status !== '') {
    $s = $conn->real_escape_string($filter_pay_status);
    $conditions[] = "i.pay_status = '$s'";
}
if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $conditions[] = "i.issue_date >= '$d'";
}
if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "i.issue_date <= '$d'";
}
if (!empty($filter_customer)) {
    $c = $conn->real_escape_string($filter_customer);
    $conditions[] = "(c.name LIKE '%$c%' OR c.business_name LIKE '%$c%')";
}
$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

$countSql = "SELECT COUNT(*) as total $baseFrom $whereClause";
$sql = "SELECT $selectCols $baseFrom $whereClause ORDER BY i.invoice_id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Invoice List</title>
    <link href="<?= BASE_URL ?>css/invoice-list.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Invoice List</h5>
                        <p class="text-muted">Manage and review all invoices</p>
                    </div>
                </div>
                    

                    
                    <div class="card invoice-card">
                        <div class="card-body">
                            <!-- Filter Bar -->
                            <div class="invoice-filter-bar">
                                <form method="get" id="filterForm">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Search</label>
                                            <input type="text" name="search" class="form-control" placeholder="Invoice ID or Ref No"
                                                value="<?php echo htmlspecialchars($search); ?>">
                                        </div>
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Customer</label>
                                            <input type="text" name="filter_customer" class="form-control" placeholder="Name or business..."
                                                value="<?= htmlspecialchars($filter_customer) ?>">
                                        </div>
                                        <div class="col-md-2 col-lg-1">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Status</label>
                                            <select name="filter_status" class="form-select">
                                                <option value="">All</option>
                                                <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="done" <?= $filter_status === 'done' ? 'selected' : '' ?>>Complete</option>
                                                <option value="cancel" <?= $filter_status === 'cancel' ? 'selected' : '' ?>>Canceled</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 col-lg-1">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Pay Status</label>
                                            <select name="filter_pay_status" class="form-select">
                                                <option value="">All</option>
                                                <option value="paid" <?= $filter_pay_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                                <option value="partial" <?= $filter_pay_status === 'partial' ? 'selected' : '' ?>>Partial</option>
                                                <option value="unpaid" <?= $filter_pay_status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">From Date</label>
                                            <input type="date" name="filter_from_date" class="form-control"
                                                value="<?= htmlspecialchars($filter_from_date) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">To Date</label>
                                            <input type="date" name="filter_to_date" class="form-control"
                                                value="<?= htmlspecialchars($filter_to_date) ?>">
                                        </div>
                                        <div class="col-md-2 col-lg-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter">
                                                <i class="fas fa-search me-1"></i> Search
                                            </button>
<a href="<?= BASE_URL ?>modules/invoices/invoice_list.php" class="btn btn-outline-secondary btn-clear">
                                                        <i class="fas fa-times me-1"></i> Clear
                                                    </a>
                                                </div>
                                            </div>
                                            <input type="hidden" name="page" value="1">
                                        </form>
                                    </div>
                            
                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Invoice<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-invoice" id="invoice_table">
                                    <thead class="table-light">
                                            <tr>
                                                <th>Invoice ID</th>
                                                <th>Ref No</th>
                                                <th>Customer</th>
                                                <th>Issue Date</th>
                                                <th>Due Date</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Paid By</th>
                                                <th>Created By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                    <tbody>
                                        <?php if ($result && $result->num_rows > 0): ?>
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo isset($row['invoice_id']) ? htmlspecialchars($row['invoice_id']) : ''; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $refNo = htmlspecialchars($row['invoice_ref_no'] ?? generateRefNo($conn, $row['invoice_id'], $row['issue_date'], 'INV'));
                                                        echo $refNo;
                                                        if (isset($row['quotation_ref_no']) && !empty($row['quotation_ref_no'])): ?>
                                                            <br><small class="text-muted" style="font-size: 0.75rem;">From: <?php echo htmlspecialchars($row['quotation_ref_no']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $businessName = isset($row['customer_business_name']) ? htmlspecialchars($row['customer_business_name']) : '';
                                                        $customerName = isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : '-';
                                                        $customerId = isset($row['customer_id']) ? htmlspecialchars($row['customer_id']) : '';

                                                        if ($businessName) {
                                                            echo '<div class="fw-semibold">' . $businessName . '</div>';
                                                            echo '<div class="text-muted" style="font-size: 0.82rem;">' . $customerName;
                                                            if ($customerId) echo ' <span class="customer-id">(' . $customerId . ')</span>';
                                                            echo '</div>';
                                                        } else {
                                                            echo '<div class="fw-semibold">' . $customerName . '</div>';
                                                            if ($customerId) echo '<div class="customer-id">(' . $customerId . ')</div>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo isset($row['issue_date']) ? htmlspecialchars(date('d/m/Y', strtotime($row['issue_date']))) : ''; ?>
                                                    </td>
                                                    <td><?php echo (isset($row['due_date']) && !empty($row['due_date']) && $row['due_date'] !== '0000-00-00') ? htmlspecialchars(date('d/m/Y', strtotime($row['due_date']))) : ''; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $totalAmt = isset($row['total_amount']) ? floatval($row['total_amount']) : 0;
                                                        $paidAmt = isset($row['amount_paid']) ? floatval($row['amount_paid']) : 0;
                                                        $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';

                                                        echo '<div class="amount-text">' . number_format($paidAmt, 2) . ' / ' . number_format($totalAmt, 2) . ' <span class="currency-symbol">(Rs)</span></div>';

                                                        if ($payStatus == 'paid'): ?>
                                                            <span class="badge-soft badge-soft-success mt-1">Paid</span>
                                                        <?php elseif ($payStatus == 'partial'): ?>
                                                            <span class="badge-soft badge-soft-warning mt-1">Partial</span>
                                                        <?php else: ?>
                                                            <span class="badge-soft badge-soft-danger mt-1">Unpaid</span>
                                                        <?php endif;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status = isset($row['status']) ? $row['status'] : 'pending';
                                                        if ($status == 'done'): ?>
                                                            <span class="badge-soft badge-soft-success">Complete</span>
                                                        <?php elseif ($status == 'cancel'): ?>
                                                            <span class="badge-soft badge-soft-danger">Canceled</span>
                                                        <?php else: ?>
                                                            <span class="badge-soft badge-soft-warning">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if (isset($row['pay_by']) && isset($row['paid_by_name'])) {
                                                            echo htmlspecialchars($row['paid_by_name']);
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if (isset($row['created_by']) && isset($row['creator_name'])) {
                                                            echo htmlspecialchars($row['creator_name']);
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="action-btn-group d-flex gap-1">
                                                            <?php if (hasAccess('invoices')): ?>
                                                            <a href="#" class="btn btn-view view-invoice"
                                                                title="View Invoice"
                                                                data-id="<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>"
                                                                data-paystatus="<?php echo $payStatus; ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="<?= BASE_URL ?>modules/invoices/download_invoice.php?id=<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>"
                                                                class="btn btn-download"
                                                                title="Download Invoice"
                                                                target="_blank">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center">No invoices found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="pagination-container d-flex justify-content-between align-items-center mt-4">
                                <div class="entries-info">
                                    <?php if ($result && $result->num_rows > 0): ?>
                                        Showing <strong><?php echo ($offset + 1); ?></strong> to
                                        <strong><?php echo min($offset + $limit, $totalRows); ?></strong> of <strong><?php echo $totalRows; ?></strong>
                                        entries
                                    <?php else: ?>
                                        Showing <strong>0</strong> to <strong>0</strong> of <strong>0</strong> entries
                                    <?php endif; ?>
                                </div>
                                <?= renderPagination($page, $totalPages, $search) ?>
                            </div>
                        </div>
                    </div>
            </main>
        </div>
    </div>

    <!-- Modal for Viewing Invoice -->
    <div class="modal fade" id="viewInvoiceModal" tabindex="-1" aria-labelledby="viewInvoiceModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewInvoiceModalLabel"><i class="fas fa-file-invoice"></i>Invoice Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="invoiceDetails">
                    Loading...
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Recording Payment -->
    <div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="markPaidModalLabel"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="markPaidForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="invoice_id" id="invoice_id">

                        <div class="text-center mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 mb-3" style="width: 64px; height: 64px;">
                                <i class="fas fa-money-bill-wave fa-2x text-success"></i>
                            </div>
                            <h6 class="fw-bold mb-1">Record Payment</h6>
                            <p class="text-muted small">Enter the payment amount and details below.</p>
                        </div>

                        <div class="detail-card mb-3">
                            <label class="detail-label mb-2"><i class="fas fa-money-bill"></i> Payment Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="payment_amount" name="amount" placeholder="Enter amount" required>
                            </div>
                            <small class="form-text text-muted mt-1 d-block">Remaining balance: Rs <span id="remainingBalance">0.00</span></small>
                        </div>

                        <div class="detail-card mb-3">
                            <label class="detail-label mb-2"><i class="fas fa-credit-card"></i> Payment Method</label>
                            <div class="d-flex gap-4 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_cash" value="Cash" checked>
                                    <label class="form-check-label" for="payment_cash">Cash</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method" id="payment_bank" value="Bank Transfer">
                                    <label class="form-check-label" for="payment_bank">Bank Transfer</label>
                                </div>
                            </div>
                        </div>

                        <div class="detail-card">
                            <label for="payment_slip" class="detail-label mb-2"><i class="fas fa-upload"></i>Upload Payment Slip</label>
                            <input type="file" class="form-control" id="payment_slip" name="payment_slip" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="form-text text-muted mt-2 d-block">Supported formats: JPG, PNG, PDF (Optional)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check-circle me-1"></i>Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
   
    <script>
        $(document).ready(function () {


            // Handle "View" button click
            $('.view-invoice').click(function (e) {
                e.preventDefault(); // Prevent default link behavior

                var invoiceId = $(this).data('id'); // Get the invoice ID
                var payStatus = $(this).data('paystatus'); // Get the payment status

                // Show loading message in the modal
                $('#invoiceDetails').html('Loading...');

                // Fetch invoice details via AJAX - CHANGED FROM view_invoice.php to download_invoice.php
                $.ajax({
                    url: 'download_invoice.php',
                    type: 'GET',
                    data: { 
                        id: invoiceId,
                        format: 'html' // Request HTML format instead of PDF download
                    },
                    success: function (response) {
                        // Populate the modal with the fetched data
                        $('#invoiceDetails').html(response);

                        // IMPORTANT: Remove any download buttons or unnecessary elements if they exist
                        $('#invoiceDetails').find('button:contains("Print Invoice")').remove();
                        $('#invoiceDetails').find('button:contains("Open in New Tab")').remove();
                        $('#invoiceDetails').find('button:contains("Download")').remove();

                        // Show the modal
                        $('#viewInvoiceModal').modal('show');
                    },
                    error: function () {
                        $('#invoiceDetails').html('Failed to load invoice details.');
                    }
                });
            });
            
            // Handle "Paid" button click
            $('.mark-paid').click(function (e) {
                e.preventDefault();

                var invoiceId = $(this).data('id');

                // Fetch invoice details to pre-fill remaining balance
                $.ajax({
                    url: 'get_payment_details.php',
                    type: 'GET',
                    data: { invoice_id: invoiceId },
                    success: function (response) {
                        if (response.success) {
                            var totalAmount = parseFloat(response.total_amount.replace(/,/g, ''));
                            var totalPaid = parseFloat(response.total_paid.replace(/,/g, ''));
                            var remaining = totalAmount - totalPaid;

                            $('#invoice_id').val(invoiceId);
                            $('#payment_amount').val(remaining > 0 ? remaining.toFixed(2) : '');
                            $('#payment_amount').attr('max', remaining.toFixed(2));
                            $('#remainingBalance').text(remaining.toFixed(2));
                            $('#markPaidModalLabel').html('<i class="fas fa-money-bill-wave me-2"></i>Record Payment - Invoice #' + invoiceId);
                            $('#markPaidModal').modal('show');
                        } else {
                            showToast('error', 'Failed to load invoice details.');
                        }
                    },
                    error: function () {
                        showToast('error', 'Failed to load invoice details.');
                    }
                });
            });

            // Validate amount doesn't exceed remaining
            $('#payment_amount').on('input', function () {
                var maxVal = parseFloat($(this).attr('max'));
                var currentVal = parseFloat($(this).val()) || 0;
                if (currentVal > maxVal) {
                    $(this).val(maxVal.toFixed(2));
                }
            });

            // Handle form submission
            $('#markPaidForm').submit(function (e) {
                e.preventDefault();

                var amount = parseFloat($('#payment_amount').val()) || 0;
                if (amount <= 0) {
                    showToast('warning', 'Please enter a valid payment amount.');
                    return false;
                }

                var formData = new FormData(this);

                $.ajax({
                    url: 'mark_paid.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        showToast('success', 'Payment recorded successfully.');
                        $('#markPaidModal').modal('hide');
                        location.reload();
                    },
                    error: function (xhr) {
                        var errMsg = 'Failed to record payment.';
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.error) errMsg = resp.error;
                        } catch(e) {}
                        showToast('error', errMsg);
                    }
                });
            });
            

        });
    </script>
</body>

</html>