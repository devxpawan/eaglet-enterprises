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

if (!isApprover()) {
    $_SESSION['message'] = "You do not have permission to view edit requests.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$filter_status = isset($_GET['status']) ? trim($_GET['status']) : 'pending';
$limit = 20;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$conditions = [];
if (!empty($filter_status) && $filter_status !== 'all') {
    $s = $conn->real_escape_string($filter_status);
    $conditions[] = "r.status = '$s'";
}
$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

$baseFrom = "FROM invoice_edit_requests r
             JOIN invoices i ON r.invoice_id = i.invoice_id
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u ON r.requester_id = u.id
             LEFT JOIN users ap ON r.approved_by = ap.id
             LEFT JOIN users rj ON r.rejected_by = rj.id";

$selectCols = "r.*, i.invoice_ref_no, i.total_amount, i.currency,
               c.name as customer_name, c.business_name as customer_business_name,
               u.name as requester_name, u.username as requester_username,
               ap.name as approver_name, rj.name as rejector_name";

$countSql = "SELECT COUNT(*) as total $baseFrom $whereClause";
$sql = "SELECT $selectCols $baseFrom $whereClause ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";

$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

$pendingCount = $conn->query("SELECT COUNT(*) as c FROM invoice_edit_requests WHERE status='pending'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Invoice Edit Requests</title>
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
                        <h5>Invoice Edit Requests</h5>
                        <p class="text-muted">Review and manage edit requests from users</p>
                    </div>
                </div>

                <div class="card invoice-card">
                    <div class="card-body">
                        <!-- Filter Bar -->
                        <div class="invoice-filter-bar mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex gap-2">
                                    <a href="?status=pending" class="btn btn-sm <?= $filter_status === 'pending' ? 'btn-warning' : 'btn-outline-secondary' ?>">
                                        Pending <?php if ($pendingCount > 0): ?><span class="badge bg-light text-dark ms-1"><?= $pendingCount ?></span><?php endif; ?>
                                    </a>
                                    <a href="?status=approved" class="btn btn-sm <?= $filter_status === 'approved' ? 'btn-success' : 'btn-outline-secondary' ?>">Approved</a>
                                    <a href="?status=rejected" class="btn btn-sm <?= $filter_status === 'rejected' ? 'btn-danger' : 'btn-outline-secondary' ?>">Rejected</a>
                                    <a href="?status=all" class="btn btn-sm <?= $filter_status === 'all' ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
                                </div>
                                <span class="search-count"><?= $totalRows ?> Request<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-invoice">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Ref No</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Requested By</th>
                                        <th>Date</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Approved/Rejected By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result && $result->num_rows > 0): ?>
                                        <?php while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $row['invoice_id'] ?></td>
                                                <td><?= htmlspecialchars($row['invoice_ref_no'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($row['customer_name'] ?? '-') ?></td>
                                                <td>Rs. <?= htmlspecialchars(number_format((float)$row['total_amount'], 2)) ?></td>
                                                <td><?= htmlspecialchars($row['requester_name'] ?? $row['requester_username'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['created_at']))) ?></td>
                                                <td style="max-width:250px;">
                                                    <?php if (!empty($row['reason'])): ?>
                                                        <span class="text-muted" style="font-size:0.85rem;"><?= htmlspecialchars($row['reason']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-size:0.85rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['status'] === 'pending'): ?>
                                                        <span class="status-badge status-pending"><i class="fas fa-clock"></i> Pending</span>
                                                    <?php elseif ($row['status'] === 'approved'): ?>
                                                        <span class="status-badge status-approved"><i class="fas fa-check-circle"></i> Approved</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['status'] === 'approved'): ?>
                                                        <?= htmlspecialchars($row['approver_name'] ?? '-') ?>
                                                        <small class="text-muted d-block"><?= $row['approved_at'] ? date('d/m/Y H:i', strtotime($row['approved_at'])) : '' ?></small>
                                                    <?php elseif ($row['status'] === 'rejected'): ?>
                                                        <?= htmlspecialchars($row['rejector_name'] ?? '-') ?>
                                                        <small class="text-muted d-block"><?= $row['rejected_at'] ? date('d/m/Y H:i', strtotime($row['rejected_at'])) : '' ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-btn-group d-flex gap-1">
                                                        <a href="#" class="btn btn-view view-invoice" data-id="<?= $row['invoice_id'] ?>" title="View Invoice">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="<?= BASE_URL ?>modules/invoices/download_invoice.php?id=<?= $row['invoice_id'] ?>" class="btn btn-download" title="Download Invoice" target="_blank">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <?php if ($row['status'] === 'pending' && hasAccess('invoices.edit_requests')): ?>
                                                            <form method="post" action="<?= BASE_URL ?>modules/invoices/process_approve_request.php" style="display:inline;">
                                                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                                <input type="hidden" name="action" value="approve">
                                                                <input type="hidden" name="status_filter" value="<?= htmlspecialchars($filter_status) ?>">
                                                                <input type="hidden" name="page" value="<?= $page ?>">
                                                                <button type="submit" class="btn btn-success btn-sm action-btn" onclick="return confirm('Approve this edit request?')">
                                                                    <i class="fas fa-check"></i> Approve
                                                                </button>
                                                            </form>
                                                            <button type="button" class="btn btn-danger btn-sm action-btn" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $row['id'] ?>">
                                                                <i class="fas fa-times"></i> Reject
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($row['status'] === 'pending' && hasAccess('invoices.edit_requests')): ?>
                                                        <div class="modal fade" id="rejectModal<?= $row['id'] ?>" tabindex="-1">
                                                            <div class="modal-dialog modal-dialog-centered">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Reject Edit Request</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <form method="post" action="<?= BASE_URL ?>modules/invoices/process_approve_request.php">
                                                                        <div class="modal-body">
                                                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                                            <input type="hidden" name="action" value="reject">
                                                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($filter_status) ?>">
                                                                            <input type="hidden" name="page" value="<?= $page ?>">
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Reason for Rejection <span class="text-muted">(optional)</span></label>
                                                                                <textarea name="reject_reason" class="form-control" rows="3" placeholder="Explain why this request is being rejected..."></textarea>
                                                                            </div>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                            <button type="submit" class="btn btn-danger">Reject Request</button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="10" class="text-center">No edit requests found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-container d-flex justify-content-between align-items-center mt-4">
                                <div class="entries-info">
                                    Showing <strong><?= $offset + 1 ?></strong> to
                                    <strong><?= min($offset + $limit, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong>
                                </div>
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        <?php endif; ?>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        $(document).ready(function () {
            // Handle "View" button click
            $('.view-invoice').click(function (e) {
                e.preventDefault(); 
                var invoiceId = $(this).data('id'); 
                $('#invoiceDetails').html('Loading...');
                $.ajax({
                    url: '<?= BASE_URL ?>modules/invoices/download_invoice.php',
                    type: 'GET',
                    data: { 
                        id: invoiceId,
                        format: 'html'
                    },
                    success: function (response) {
                        $('#invoiceDetails').html(response);
                        $('#viewInvoiceModal').modal('show');
                    },
                    error: function () {
                        $('#invoiceDetails').html('Failed to load invoice details.');
                    }
                });
            });
        });
    </script>
</body>

</html>
