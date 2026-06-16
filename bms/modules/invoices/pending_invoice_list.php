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

// Only Admin and Moderator can access pending invoices
$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
if ($current_user_role !== 1 && $current_user_role !== 3) {
    header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    exit();
}

// Process invoice cancellation
if (isset($_POST['cancel_invoice']) && isset($_POST['invoice_id'])) {
    $invoice_id = $_POST['invoice_id'];
    $user_id = $_SESSION['user_id']; // Current logged-in user ID
    $cancel_reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : '';
    
    // Get invoice details for logging
    $invoice_sql = "SELECT c.name FROM invoices i 
                   LEFT JOIN customers c ON i.customer_id = c.customer_id
                   WHERE i.invoice_id = ?";
    $stmt = $conn->prepare($invoice_sql);
    $stmt->bind_param("s", $invoice_id);
    $stmt->execute();
    $invoice_result = $stmt->get_result();
    $invoice_data = $invoice_result->fetch_assoc();
    $customer_name = isset($invoice_data['name']) ? $invoice_data['name'] : 'Unknown Customer';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Update the invoice status to 'cancel' and save cancel_reason
        $update_invoice_sql = "UPDATE invoices SET status = 'cancel', cancel_reason = ? WHERE invoice_id = ?";
        $stmt = $conn->prepare($update_invoice_sql);
        $stmt->bind_param("ss", $cancel_reason, $invoice_id);
        $stmt->execute();
        
        // 2. Update all related invoice items to 'cancel' status
        $update_items_sql = "UPDATE invoice_items SET status = 'cancel' WHERE invoice_id = ?";
        $stmt_items = $conn->prepare($update_items_sql);
        $stmt_items->bind_param("s", $invoice_id);
        $stmt_items->execute();
        
        // 3. Log the action in user_logs table
        $action_type = "cancel_invoice";
        $details = "Invoice ID #$invoice_id for customer ($customer_name) was canceled by user ID #$user_id";
        if (!empty($cancel_reason)) {
            $details .= " Reason: $cancel_reason";
        }
        
        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("isss", $user_id, $action_type, $invoice_id, $details);
        $log_stmt->execute();
        
        // Commit the transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['message'] = "Invoice #$invoice_id has been canceled successfully.";
        $_SESSION['message_type'] = "success";
    } catch (Exception $e) {
        // Rollback the transaction if something fails
        $conn->rollback();
        
        // Set error message
        $_SESSION['message'] = "Failed to cancel invoice. Error: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }
    
    // Build the redirect URL preserving filter params
    $redirect_url = "pending_invoice_list.php";
    $qp = [];
    foreach (['search','filter_pay_status','filter_from_date','filter_to_date','filter_customer','page'] as $f) {
        if (isset($_POST[$f]) && $_POST[$f] !== '') {
            $qp[] = urlencode($f) . '=' . urlencode($_POST[$f]);
        }
    }
    if (!empty($qp)) {
        $redirect_url .= '?' . implode('&', $qp);
    }
    
    // Make sure we're enforcing the redirect properly
    if (headers_sent()) {
        echo "<script>window.location.href='$redirect_url';</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=$redirect_url'></noscript>";
    } else {
        header("Location: $redirect_url");
    }
    exit();
}

// Initialize filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$filter_customer = isset($_GET['filter_customer']) ? trim($_GET['filter_customer']) : '';
$limit = 15;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build base SQL
$baseFrom = "FROM invoices i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.created_by = u2.id";
$selectCols = "i.*, c.name as customer_name, c.business_name as customer_business_name,
               u2.name as creator_name";

// Build WHERE conditions
$conditions = ["(i.status = 'pending' OR i.status IS NULL OR i.status = '')"];

if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(i.invoice_id LIKE '%$s%' OR i.quotation_ref_no LIKE '%$s%')";
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
$whereClause = ' WHERE ' . implode(' AND ', $conditions);
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
    <title>Pending Invoices</title>
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
                        <h5>Pending Invoices</h5>
                        <p class="text-muted">Review and process pending invoices</p>
                    </div>
                </div>
                    
                    <?php if (isset($_SESSION['invoice_success'])): ?>
                        <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($_SESSION["invoice_success"]); ?>'); });</script>
                        <?php unset($_SESSION['invoice_success']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['invoice_error'])): ?>
                        <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($_SESSION["invoice_error"]); ?>'); });</script>
                        <?php unset($_SESSION['invoice_error']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['message'])): ?>
                        <script>document.addEventListener('DOMContentLoaded', function() { showToast('<?php echo $_SESSION["message_type"] === "danger" ? "error" : $_SESSION["message_type"]; ?>', '<?php echo addslashes($_SESSION["message"]); ?>'); });</script>
                        <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
                    <?php endif; ?>
                    
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
                                            <a href="<?= BASE_URL ?>modules/invoices/pending_invoice_list.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>
                            
                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Pending Invoice<?= $totalRows !== 1 ? 's' : '' ?></span>
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
                                                        $customerName = isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A';
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
                                                    <td><?php echo isset($row['due_date']) ? htmlspecialchars(date('d/m/Y', strtotime($row['due_date']))) : ''; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $amount = isset($row['total_amount']) ? htmlspecialchars(number_format((float) $row['total_amount'], 2)) : '0.00';
                                                        $currency = isset($row['currency']) ? $row['currency'] : 'lkr';
                                                        $currencySymbol = ($currency == 'usd') ? '$' : 'Rs';
                                                        $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';

                                                        echo '<div class="amount-text">' . $amount . ' <span class="currency-symbol">(' . $currencySymbol . ')</span></div>';

                                                        if ($payStatus == 'paid'): ?>
                                                            <span class="badge-soft badge-soft-success mt-1">Paid</span>
                                                        <?php else: ?>
                                                            <span class="badge-soft badge-soft-danger mt-1">Unpaid</span>
                                                        <?php endif;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge-soft badge-soft-warning">Pending</span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if (isset($row['created_by']) && isset($row['creator_name'])) {
                                                            echo htmlspecialchars($row['creator_name']);
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="action-btn-group d-flex gap-1">
                                                            <a href="#" class="btn btn-view view-invoice"
                                                                title="View Invoice"
                                                                data-id="<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="<?= BASE_URL ?>modules/invoices/download_invoice.php?id=<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>"
                                                                class="btn btn-download"
                                                                title="Download Invoice"
                                                                target="_blank">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php if ($payStatus == 'paid'): ?>

                                                            <?php else: ?>
                                                                <a href="<?= BASE_URL ?>modules/invoices/invoice_edit.php?id=<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>"
                                                                    class="btn btn-edit"
                                                                    title="Edit Invoice">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="#" class="btn btn-view mark-paid"
                                                                    title="Mark as Paid"
                                                                    data-id="<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>">
                                                                    <i class="fas fa-check"></i>
                                                                </a>
                                                                <button type="button" class="btn btn-cancel cancel-invoice"
                                                                    title="Cancel Invoice"
                                                                    data-id="<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>"
                                                                    data-customer="<?php echo htmlspecialchars($customerName); ?>">
                                                                    <i class="fas fa-times-circle"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
<tr>
                                                        <td colspan="9" class="text-center">No pending invoices found</td>
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

    <!-- Modal for Marking Invoice as Paid -->
    <div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="markPaidModalLabel"><i class="fas fa-money-bill-wave me-2"></i>Payment Sheet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="markPaidForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="invoice_id" id="invoice_id">

                        <div class="text-center mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 mb-3" style="width: 64px; height: 64px;">
                                <i class="fas fa-money-bill-wave fa-2x text-success"></i>
                            </div>
                            <h6 class="fw-bold mb-1">Confirm Payment</h6>
                            <p class="text-muted small">Upload the payment slip to mark this invoice as paid.</p>
                        </div>

                        <div class="detail-card">
                            <label for="payment_slip" class="detail-label mb-2"><i class="fas fa-upload"></i>Upload Payment Slip</label>
                            <input type="file" class="form-control" id="payment_slip" name="payment_slip" accept=".jpg,.jpeg,.png,.pdf">
                            <small class="form-text text-muted mt-2 d-block">Supported formats: JPG, PNG, PDF (Max 2MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check-circle me-1"></i>Mark as Paid
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Cancel Invoice Confirmation -->
    <div class="modal fade" id="cancelInvoiceModal" tabindex="-1" aria-labelledby="cancelInvoiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-light border-bottom-0 py-3">
                    <h5 class="modal-title fw-bold" id="cancelInvoiceModalLabel">
                        <i class="fas fa-times-circle me-2 text-danger"></i>Cancel Invoice
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="icon-box bg-danger-soft rounded-circle mx-auto mb-3" style="width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; background-color: rgba(220, 53, 69, 0.1);">
                            <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                        </div>
                        <h6 class="fw-bold mb-1">Are you sure?</h6>
                        <p class="text-muted small">This action will cancel the invoice. This cannot be undone.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-3 border-start border-danger border-4">
                                <div class="mb-2">
                                    <small class="text-muted text-uppercase fw-semibold letter-spacing-1 d-block">Invoice ID</small>
                                    <span class="fw-bold text-dark" id="cancel_invoice_id"></span>
                                </div>
                                <div>
                                    <small class="text-muted text-uppercase fw-semibold letter-spacing-1 d-block">Customer</small>
                                    <span class="fw-bold text-dark" id="cancel_customer_name"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="cancel_reason" class="form-label fw-semibold">Cancel Reason <span class="text-muted">(Optional)</span></label>
                            <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="3" placeholder="Enter the reason for cancellation..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 py-3">
                    <button type="button" class="btn btn-link text-muted text-decoration-none fw-semibold" data-bs-dismiss="modal">Close</button>
                    <form method="post" id="cancelInvoiceForm" class="m-0">
                        <input type="hidden" name="invoice_id" id="confirm_cancel_invoice_id">
                        <input type="hidden" name="cancel_invoice" value="1">
                        <input type="hidden" name="cancel_reason" id="confirm_cancel_reason">
                            <?php foreach (['search','filter_from_date','filter_to_date','filter_customer'] as $f):
                                    if (!empty($$f)): ?>
                                    <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars($$f) ?>">
                                <?php endif; endforeach; ?>
                                <input type="hidden" name="page" value="<?php echo $page; ?>">
                                <button type="submit" class="btn btn-danger px-4 rounded-pill fw-bold">
                            <i class="fas fa-times me-1"></i>Confirm Cancel
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function () {


            // Handle "View" button click
            $('.view-invoice').click(function (e) {
                e.preventDefault(); // Prevent default link behavior

                var invoiceId = $(this).data('id'); // Get the invoice ID

                // Show loading message in the modal
                $('#invoiceDetails').html('Loading...');

                // Fetch invoice details via AJAX
                $.ajax({
                    url: 'download_invoice.php',
                    type: 'GET',
                    data: { 
                        id: invoiceId,
                        format: 'html'
                    },
                    success: function (response) {
                        // Populate the modal with the fetched data
                        $('#invoiceDetails').html(response);

                        // IMPORTANT: Remove the Print Invoice and Open in New Tab buttons
                        $('#invoiceDetails').find('button:contains("Print Invoice")').remove();
                        $('#invoiceDetails').find('button:contains("Open in New Tab")').remove();

                        // Show the modal
                        $('#viewInvoiceModal').modal('show');
                    },
                    error: function () {
                        $('#invoiceDetails').html('Failed to load invoice details.');
                    }
                });
            });
        });

        // Updated JavaScript for the payment modal
        $(document).ready(function () {
            // Handle "Paid" button click
            $('.mark-paid').click(function (e) {
                e.preventDefault(); // Prevent default link behavior

                var invoiceId = $(this).data('id'); // Get the invoice ID

                // Get additional information about the invoice
                var invoiceRow = $(this).closest('tr');
                var invoiceAmount = invoiceRow.find('td:eq(4)').text().trim();
                var customerName = invoiceRow.find('td:eq(1)').text().trim();

                // Directly set the invoice ID in the form
                $('#invoice_id').val(invoiceId);

                // Optionally, you could update the modal with invoice details
                $('#markPaidModalLabel').html('<i class="fas fa-money-bill-wave me-2"></i>Payment Sheet - Invoice #' + invoiceId);

                // Show the modal
                $('#markPaidModal').modal('show');
            });

            // Handle form submission with validation
            $('#markPaidForm').submit(function (e) {
                e.preventDefault(); // Prevent default form submission

                // Simple validation
                var fileInput = $('#payment_slip')[0];

                if (fileInput.files.length > 0) {
                    var fileSize = fileInput.files[0].size / 1024 / 1024; // in MB
                    if (fileSize > 2) {
                        showToast('warning', 'File size exceeds 2MB. Please choose a smaller file.');
                        return false;
                    }
                }

                // Show loading state
                var submitBtn = $(this).find('button[type="submit"]');
                var originalText = submitBtn.html();
                submitBtn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');
                submitBtn.prop('disabled', true);

                var formData = new FormData(this);

                $.ajax({
                    url: 'mark_paid.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        // Show success message with showToast
                        showToast('success', 'Invoice has been marked as paid successfully.');
                        
                        setTimeout(() => {
                            $('#markPaidModal').modal('hide');
                            location.reload(); // Reload the page to reflect changes
                        }, 2000);
                    },
                    error: function (xhr, status, error) {
                        // Reset button state
                        submitBtn.html(originalText);
                        submitBtn.prop('disabled', false);

                        // Show error message with showToast
                        showToast('error', 'Failed to mark invoice as paid. Please try again.');
                    }
                });
            });

            $('#markPaidModal').on('hidden.bs.modal', function () {
                $('#markPaidForm')[0].reset();
            });

            // Handle Cancel Invoice button click
            $('.cancel-invoice').click(function() {
                var invoiceId = $(this).data('id');
                var customerName = $(this).data('customer');
                $('#cancel_invoice_id').text(invoiceId);
                $('#cancel_customer_name').text(customerName);
                $('#confirm_cancel_invoice_id').val(invoiceId);
                $('#cancel_reason').val('');
                $('#cancelInvoiceModal').modal('show');
            });

            $('#cancelInvoiceForm').on('submit', function() {
                $('#confirm_cancel_reason').val($('#cancel_reason').val());
            });
            

        });
    </script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>