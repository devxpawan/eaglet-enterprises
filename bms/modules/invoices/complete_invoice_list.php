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
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$filter_customer = isset($_GET['filter_customer']) ? trim($_GET['filter_customer']) : '';
$filter_processed_by = isset($_GET['filter_processed_by']) ? (int)$_GET['filter_processed_by'] : 0;
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
$conditions = ["i.status = 'done'"];

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
if ($filter_processed_by > 0) {
    $conditions[] = "p.pay_by = $filter_processed_by";
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

// Get users list for processed_by dropdown
$processedUsers = [];
$pu = $conn->query("SELECT DISTINCT u.id, u.name FROM users u INNER JOIN payments p ON u.id = p.pay_by WHERE u.status = 'active' ORDER BY u.name");
if ($pu) {
    while ($row = $pu->fetch_assoc()) {
        $processedUsers[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Complete Invoices</title>
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
                        <h5>Complete Invoices</h5>
                        <p class="text-muted">View finalized and completed invoices</p>
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
                                            <input type="text" name="search" class="form-control" placeholder="Search by invoice ID or ref..."
                                                value="<?php echo htmlspecialchars($search); ?>">
                                        </div>
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Customer</label>
                                            <input type="text" name="filter_customer" class="form-control" placeholder="Search by customer name..."
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
                                        <div class="col-md-2 col-lg-1">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Paid By</label>
                                            <select name="filter_processed_by" class="form-select">
                                                <option value="0">All</option>
                                                <?php foreach ($processedUsers as $u): ?>
                                                    <option value="<?= $u['id'] ?>" <?= $filter_processed_by == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 col-lg-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter">
                                                <i class="fas fa-search me-1"></i> Search
                                            </button>
<a href="<?= BASE_URL ?>modules/invoices/complete_invoice_list.php" class="btn btn-outline-secondary btn-clear">
                                                        <i class="fas fa-times me-1"></i> Clear
                                                    </a>
                                                </div>
                                            </div>
                                            <input type="hidden" name="page" value="1">
                                        </form>
                                    </div>
                            
                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Complete Invoice<?= $totalRows !== 1 ? 's' : '' ?></span>
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
                                                <th>Payment Method</th>
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
                                                        $refNo = htmlspecialchars($row['invoice_ref_no'] ?? generateRefNo($conn, $row['invoice_id'], $row['issue_date'], 'IN'));
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
                                                        $currencySymbol = 'Rs';
                                                        $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';

                                                        echo '<div class="amount-text">' . number_format($paidAmt, 2) . ' / ' . number_format($totalAmt, 2) . ' <span class="currency-symbol">(' . $currencySymbol . ')</span></div>';

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
                                                        <?= htmlspecialchars($row['payment_method'] ?? '-') ?>
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
                                                            <?php if (hasAccess('invoices.complete')): ?>
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
                                                             <?php if (!empty($row['slip'])): ?>
                                                             <a href="#" class="btn btn-receipt view-receipt"
                                                                title="View Receipt"
                                                                data-slip="<?php echo htmlspecialchars($row['slip']); ?>"
                                                                data-id="<?php echo isset($row['invoice_id']) ? $row['invoice_id'] : ''; ?>">
                                                                <i class="fas fa-receipt"></i>
                                                             </a>
                                                             <?php endif; ?>
                                                             <?php endif; ?>
                                                         </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center">No complete invoices found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="pagination-container d-flex justify-content-end align-items-center mt-4">
                                
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
                            <small class="form-text text-muted mt-2 d-block">Supported formats: JPG, PNG, PDF (Optional)</small>
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

    <!-- Modal for Viewing Receipt -->
    <div class="modal fade" id="viewReceiptModal" tabindex="-1" aria-labelledby="viewReceiptModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewReceiptModalLabel"><i class="fas fa-receipt"></i> Payment Receipt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center" id="receiptContent">
                    <div id="receiptLoading" class="py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading receipt...</p>
                    </div>
                    <div id="receiptDisplay" class="d-none">
                        <img id="receiptImage" src="" alt="Payment Receipt" class="img-fluid rounded border" style="max-height: 500px; object-fit: contain;">
                        <div id="receiptPdfContainer" class="d-none">
                            <iframe id="receiptPdf" src="" style="width:100%;height:500px;border:none;" allowfullscreen></iframe>
                        </div>
                    </div>
                    <div id="receiptNotFound" class="d-none py-5">
                        <i class="fas fa-file-excel fa-4x text-muted mb-3"></i>
                        <p class="text-muted">Receipt file not found.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <a id="receiptDownloadLink" href="#" class="btn btn-primary" target="_blank">
                        <i class="fas fa-download me-1"></i>Download Receipt
                    </a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="<?= BASE_URL ?>js/select2-init.js"></script>
    <script>
        $(document).ready(function () {
            $('select[name="filter_processed_by"]').select2({ minimumResultsForSearch: Infinity });


            // Handle "View" button click
            $('.view-invoice').click(function (e) {
                e.preventDefault(); // Prevent default link behavior

                var invoiceId = $(this).data('id'); // Get the invoice ID
                var payStatus = $(this).data('paystatus'); // Get the payment status

                // Show loading message in the modal
                $('#invoiceDetails').html('Loading...');

                // Fetch invoice details via AJAX
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

                        // IMPORTANT: Remove the Print Invoice and Open in New Tab buttons
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
                e.preventDefault(); // Prevent default link behavior

                var invoiceId = $(this).data('id'); // Get the invoice ID

                // Directly set the invoice ID in the form without fetching other details
                $('#invoice_id').val(invoiceId);

                // Show the modal
                $('#markPaidModal').modal('show');
            });

            // Handle "View Receipt" button click
            $(document).on('click', '.view-receipt', function (e) {
                e.preventDefault();

                var slipName = $(this).data('slip');
                var invoiceId = $(this).data('id');

                // Reset modal content
                $('#receiptLoading').removeClass('d-none');
                $('#receiptDisplay').addClass('d-none');
                $('#receiptNotFound').addClass('d-none');
                $('#receiptDownloadLink').attr('href', '#');

                // Build the receipt URL from root uploads folder
                var receiptUrl = '<?= BASE_URL ?>uploads/payments/' + encodeURIComponent(slipName);

                // Check if the file exists by trying to load it
                var ext = slipName.split('.').pop().toLowerCase();
                if (ext === 'pdf') {
                    // For PDF - try to load in iframe
                    $('#receiptImage').addClass('d-none');
                    $('#receiptPdfContainer').removeClass('d-none');
                    $('#receiptPdf').attr('src', receiptUrl);
                    $('#receiptPdf').on('load', function () {
                        $('#receiptLoading').addClass('d-none');
                        $('#receiptDisplay').removeClass('d-none');
                    });
                    $('#receiptPdf').on('error', function () {
                        $('#receiptLoading').addClass('d-none');
                        $('#receiptNotFound').removeClass('d-none');
                    });
                } else {
                    // For images - preload to check if exists
                    var img = new Image();
                    img.onload = function () {
                        $('#receiptPdfContainer').addClass('d-none');
                        $('#receiptImage').removeClass('d-none');
                        $('#receiptImage').attr('src', receiptUrl);
                        $('#receiptLoading').addClass('d-none');
                        $('#receiptDisplay').removeClass('d-none');
                    };
                    img.onerror = function () {
                        $('#receiptLoading').addClass('d-none');
                        $('#receiptNotFound').removeClass('d-none');
                    };
                    img.src = receiptUrl;
                }

                // Set download link
                $('#receiptDownloadLink').attr('href', receiptUrl);

                // Show the modal
                $('#viewReceiptModal').modal('show');
            });

            // Reset receipt modal content when hidden
            $('#viewReceiptModal').on('hidden.bs.modal', function () {
                $('#receiptLoading').addClass('d-none');
                $('#receiptDisplay').addClass('d-none');
                $('#receiptNotFound').addClass('d-none');
                $('#receiptImage').attr('src', '');
                $('#receiptPdf').attr('src', '');
                $('#receiptPdfContainer').addClass('d-none');
                $('#receiptImage').removeClass('d-none');
            });

            // Handle form submission
            $('#markPaidForm').submit(function (e) {
                e.preventDefault(); // Prevent default form submission

                var formData = new FormData(this);

                $.ajax({
                    url: 'mark_paid.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        showToast('success', 'Invoice marked as paid successfully.');
                        $('#markPaidModal').modal('hide');
                        location.reload(); // Reload the page to reflect changes
                    },
                    error: function () {
                        showToast('error', 'Failed to mark invoice as paid.');
                    }
                });
            });
        });
    </script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>