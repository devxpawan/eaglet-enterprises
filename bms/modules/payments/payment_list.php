<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_method = isset($_GET['filter_method']) ? trim($_GET['filter_method']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$filter_processed_by = isset($_GET['filter_processed_by']) ? (int)$_GET['filter_processed_by'] : 0;
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$baseFrom = "FROM payments p 
             LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u ON p.pay_by = u.id";

$selectCols = "p.*, i.invoice_ref_no, i.total_amount, i.pay_status, i.currency,
               c.name as customer_name, c.business_name as customer_business_name,
               u.name as paid_by_name";

$conditions = [];

if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(i.invoice_ref_no LIKE '%$s%' OR c.name LIKE '%$s%' OR c.business_name LIKE '%$s%' OR p.invoice_id LIKE '%$s%')";
}

if (!empty($filter_method)) {
    $m = $conn->real_escape_string($filter_method);
    $conditions[] = "p.payment_method = '$m'";
}

if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $conditions[] = "DATE(p.payment_date) >= '$d'";
}

if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "DATE(p.payment_date) <= '$d'";
}

if ($filter_processed_by > 0) {
    $conditions[] = "p.pay_by = $filter_processed_by";
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

$countSql = "SELECT COUNT(*) as total $baseFrom $whereClause";
$sql = "SELECT $selectCols $baseFrom $whereClause ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT $limit OFFSET $offset";

$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Users list for processed_by filter
$processedUsers = [];
$pu = $conn->query("SELECT id, name FROM users WHERE status = 'active' ORDER BY name");
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
    <title>All Payments</title>
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
                        <h5>All Payments</h5>
                        <p class="text-muted">View all payment records across invoices</p>
                    </div>
                </div>

                <div class="card invoice-card">
                    <div class="card-body">
                        <div class="invoice-filter-bar">
                            <form method="get" id="filterForm">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3 col-lg-2">
                                        <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Search</label>
                                        <input type="text" name="search" class="form-control" placeholder="Invoice Ref or Customer"
                                            value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                    <div class="col-md-2 col-lg-1">
                                        <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Method</label>
                                        <select name="filter_method" class="form-select">
                                            <option value="">All</option>
                                            <option value="Cash" <?= $filter_method === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                            <option value="Bank Transfer" <?= $filter_method === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
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
                                        <a href="<?= BASE_URL ?>modules/payments/payment_list.php" class="btn btn-outline-secondary btn-clear">
                                            <i class="fas fa-times me-1"></i> Clear
                                        </a>
                                    </div>
                                </div>
                                <input type="hidden" name="page" value="1">
                            </form>
                        </div>

                        <div class="d-flex justify-content-start mt-2 mb-2">
                            <span class="search-count"><?php echo $totalRows; ?> Payment<?= $totalRows !== 1 ? 's' : '' ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-invoice" id="payment_table">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Date</th>
                                        <th>Paid By</th>
                                        <th>Slip</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result && $result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()): 
                                            $currencySymbol = 'Rs';
                                        ?>
                                            <tr>
                                                <td><?php echo intval($row['payment_id']); ?></td>
                                                <td>
                                                    <a href="<?= BASE_URL ?>modules/invoices/download_invoice.php?id=<?php echo intval($row['invoice_id']); ?>"
                                                       target="_blank" class="fw-semibold" style="color: #1B1C56;">
                                                        <?php echo htmlspecialchars($row['invoice_ref_no'] ?? '#' . $row['invoice_id']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $biz = $row['customer_business_name'] ?? '';
                                                    $name = $row['customer_name'] ?? '-';
                                                    if ($biz) {
                                                        echo '<div class="fw-semibold">' . htmlspecialchars($biz) . '</div>';
                                                        echo '<div class="text-muted" style="font-size: 0.82rem;">' . htmlspecialchars($name) . '</div>';
                                                    } else {
                                                        echo '<div class="fw-semibold">' . htmlspecialchars($name) . '</div>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="amount-text"><?php echo number_format(floatval($row['amount_paid']), 2); ?> <span class="currency-symbol">(<?= $currencySymbol ?>)</span></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['payment_method'] ?? '-'); ?></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($row['payment_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($row['paid_by_name'] ?? '-'); ?></td>
                                                <td>
                                                    <?php 
                                                    $slip = $row['slip'] ?? '';
                                                    if (!empty($slip)): 
                                                    ?>
                                                        <a href="#" class="btn btn-receipt view-receipt"
                                                           title="View Receipt"
                                                           data-slip="<?php echo htmlspecialchars($slip); ?>"
                                                           data-id="<?php echo intval($row['invoice_id']); ?>">
                                                            <i class="fas fa-receipt"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No payments found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

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

    <!-- Receipt View Modal -->
    <div class="modal fade" id="viewReceiptModal" tabindex="-1" aria-labelledby="viewReceiptModalLabel" aria-hidden="true">
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
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        $(document).ready(function () {
            $(document).on('click', '.view-receipt', function (e) {
                e.preventDefault();

                var slipName = $(this).data('slip');
                var invoiceId = $(this).data('id');

                $('#receiptLoading').removeClass('d-none');
                $('#receiptDisplay').addClass('d-none');
                $('#receiptNotFound').addClass('d-none');
                $('#receiptDownloadLink').attr('href', '#');

                var receiptUrl = '<?= BASE_URL ?>uploads/payments/' + encodeURIComponent(slipName);

                var ext = slipName.split('.').pop().toLowerCase();
                if (ext === 'pdf') {
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

                $('#receiptDownloadLink').attr('href', receiptUrl);
                $('#viewReceiptModal').modal('show');
            });

            $('#viewReceiptModal').on('hidden.bs.modal', function () {
                $('#receiptLoading').addClass('d-none');
                $('#receiptDisplay').addClass('d-none');
                $('#receiptNotFound').addClass('d-none');
                $('#receiptImage').attr('src', '');
                $('#receiptPdf').attr('src', '');
                $('#receiptPdfContainer').addClass('d-none');
                $('#receiptImage').removeClass('d-none');
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>