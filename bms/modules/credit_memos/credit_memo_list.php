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
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$baseFrom = "FROM credit_memos cm
             LEFT JOIN invoices i ON cm.invoice_id = i.invoice_id
             LEFT JOIN customers c ON cm.customer_id = c.customer_id
             LEFT JOIN users u ON cm.created_by = u.id";

$selectCols = "cm.*, i.invoice_ref_no, i.total_amount, i.currency,
               c.name as customer_name, c.business_name as customer_business_name,
               u.name as creator_name";

$conditions = [];

if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(cm.credit_memo_no LIKE '%$s%' OR i.invoice_ref_no LIKE '%$s%' OR c.name LIKE '%$s%' OR c.business_name LIKE '%$s%' OR cm.invoice_id LIKE '%$s%')";
}

if (!empty($filter_status)) {
    $st = $conn->real_escape_string($filter_status);
    $conditions[] = "cm.status = '$st'";
}

if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $conditions[] = "DATE(cm.created_at) >= '$d'";
}

if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "DATE(cm.created_at) <= '$d'";
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

$countSql = "SELECT COUNT(*) as total $baseFrom $whereClause";
$sql = "SELECT $selectCols $baseFrom $whereClause ORDER BY cm.created_at DESC, cm.credit_memo_id DESC LIMIT $limit OFFSET $offset";

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
    <title>Credit Memos</title>
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
                        <h5>Credit Memos</h5>
                        <p class="text-muted">View credit memos issued to customers</p>
                    </div>
                </div>

                <div class="card invoice-card">
                    <div class="card-body">
                        <div class="invoice-filter-bar">
                            <form method="get" id="filterForm">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3 col-lg-2">
                                        <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Search</label>
                                        <input type="text" name="search" class="form-control" placeholder="Memo No, Invoice or Customer"
                                            value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                    <div class="col-md-2 col-lg-1">
                                        <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Status</label>
                                        <select name="filter_status" class="form-select">
                                            <option value="">All</option>
                                            <option value="refund" <?= $filter_status === 'refund' ? 'selected' : '' ?>>Refund</option>
                                            <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
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
                                        <a href="<?= BASE_URL ?>modules/credit_memos/credit_memo_list.php" class="btn btn-outline-secondary btn-clear">
                                            <i class="fas fa-times me-1"></i> Clear
                                        </a>
                                    </div>
                                </div>
                                <input type="hidden" name="page" value="1">
                            </form>
                        </div>

                        <div class="d-flex justify-content-start mt-2 mb-2">
                            <span class="search-count"><?php echo $totalRows; ?> Credit Memo<?= $totalRows !== 1 ? 's' : '' ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-invoice" id="credit_memo_table">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Credit Memo No</th>
                                        <th>Invoice</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Created By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result && $result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()):
                                            $currency = isset($row['currency']) ? $row['currency'] : 'lkr';
                                            $currencySymbol = ($currency == 'usd') ? '$' : 'Rs';
                                            $statusBadge = $row['status'] == 'refund' ? 'badge-soft-success' : 'badge-soft-danger';
                                        ?>
                                            <tr>
                                                <td><?php echo intval($row['credit_memo_id']); ?></td>
                                                <td class="fw-semibold">
                                                    <a href="<?= BASE_URL ?>modules/credit_memos/download_credit_memo.php?id=<?php echo intval($row['credit_memo_id']); ?>" target="_blank" style="color: #1B1C56;">
                                                        <?php echo htmlspecialchars($row['credit_memo_no']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php if ($row['invoice_id']): ?>
                                                        <a href="<?= BASE_URL ?>modules/invoices/download_invoice.php?id=<?php echo intval($row['invoice_id']); ?>"
                                                           target="_blank" style="color: #1B1C56;">
                                                            <?php echo htmlspecialchars($row['invoice_ref_no'] ?? '#' . $row['invoice_id']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $biz = $row['customer_business_name'] ?? '';
                                                    $name = $row['customer_name'] ?? 'N/A';
                                                    if ($biz) {
                                                        echo '<div class="fw-semibold">' . htmlspecialchars($biz) . '</div>';
                                                        echo '<div class="text-muted" style="font-size: 0.82rem;">' . htmlspecialchars($name) . '</div>';
                                                    } else {
                                                        echo '<div class="fw-semibold">' . htmlspecialchars($name) . '</div>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="amount-text"><?php echo number_format(floatval($row['amount']), 2); ?> <span class="currency-symbol">(<?= $currencySymbol ?>)</span></div>
                                                </td>
                                                <td><?php echo !empty($row['reason']) ? htmlspecialchars($row['reason']) : '<span class="text-muted">—</span>'; ?></td>
                                                <td><span class="badge-soft <?= $statusBadge ?>"><?php echo ucfirst(htmlspecialchars($row['status'])); ?></span></td>
                                                <td><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($row['creator_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No credit memos found</td>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>
</html>
<?php $conn->close(); ?>
