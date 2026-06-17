<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) { ob_end_clean(); }
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

$current_user_role = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
$canEditRecords = ($current_user_role === 1 || $current_user_role === 3);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_customer = isset($_GET['filter_customer']) ? trim($_GET['filter_customer']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$status_filter = "Draft";

$baseFrom = "FROM quotations q 
             LEFT JOIN customers c ON q.customer_id = c.customer_id
             LEFT JOIN users u ON q.created_by = u.id
             WHERE q.status = '$status_filter'";

$selectCols = "q.*, c.name as customer_name, c.business_name as customer_business_name, 
               u.name as creator_name";

$conditions = [];
if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(q.quotation_id LIKE '%$s%' OR q.ref_no LIKE '%$s%')";
}
if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $conditions[] = "q.quotation_date >= '$d'";
}
if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "q.quotation_date <= '$d'";
}
if (!empty($filter_customer)) {
    $c = $conn->real_escape_string($filter_customer);
    $conditions[] = "(c.name LIKE '%$c%' OR c.business_name LIKE '%$c%')";
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

// Note: baseFrom already has WHERE for status, so use AND for additional conditions
$fullWhere = '';
if (!empty($conditions)) {
    $fullWhere = ' AND ' . implode(' AND ', $conditions);
}

$countSql = "SELECT COUNT(*) as total $baseFrom $fullWhere";
$sql = "SELECT $selectCols $baseFrom $fullWhere ORDER BY q.quotation_id DESC LIMIT $limit OFFSET $offset";

$countResult = $conn->query($countSql);
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Draft Quotations</title>
    <link href="<?= BASE_URL ?>css/quotation-list.css" rel="stylesheet" />
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
                        <h5>Draft Quotations</h5>
                        <p class="text-muted">Manage and review draft quotations</p>
                    </div>
                </div>
                    
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

                    <div class="card quotation-card">
                        <div class="card-body">
                            <div class="quotation-filter-bar">
                                <form method="get" id="filterForm">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Search</label>
                                            <input type="text" name="search" class="form-control" placeholder="Quotation ID or Ref No"
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
                                            <a href="<?= BASE_URL ?>modules/quotations/draft_quotation_list.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                    <input type="hidden" name="limit" value="<?= $limit ?>">
                                </form>
                            </div>
                            
                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Draft Quotation<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-quotation">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Quotation ID</th>
                                            <th>Ref No</th>
                                            <th>Customer</th>
                                            <th>Date</th>
                                            <th>Expiry</th>
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
                                                    <td><?php echo htmlspecialchars($row['quotation_id']); ?></td>
                                                    <td>
                                                        <?php
                                                        $refNo = !empty($row['ref_no'])
                                                            ? htmlspecialchars($row['ref_no'])
                                                            : htmlspecialchars(generateRefNo($conn, $row['quotation_id'], $row['quotation_date'], 'QT'));
                                                        echo $refNo;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['customer_business_name'] ?: $row['customer_name']); ?></div>
                                                        <div class="text-muted small"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($row['quotation_date'])); ?></td>
                                                    <td><?php echo $row['expiry_date'] ? date('d/m/Y', strtotime($row['expiry_date'])) : 'N/A'; ?></td>
                                                    <td>
                                                        <div class="amount-text"><?php echo number_format($row['total_amount'], 2); ?> <span class="currency-symbol">(<?php echo $row['currency'] == 'usd' ? '$' : 'Rs'; ?>)</span></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge-soft badge-soft-draft">
                                                            Draft
                                                        </span>
                                                    </td>
<td><?php echo htmlspecialchars($row['creator_name']); ?></td>
                            <td>
                                <div class="action-btn-group d-flex gap-1">
                                    <a href="#" class="btn btn-view view-quotation" title="View Quotation"
                                        data-id="<?php echo $row['quotation_id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>modules/quotations/revise_quotation.php?id=<?php echo $row['quotation_id']; ?>" class="btn btn-edit" title="Revise Quotation">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <a href="javascript:void(0);" class="btn btn-view revision-history" title="Revision History"
                                        data-id="<?php echo $row['quotation_id']; ?>">
                                        <i class="fas fa-code-branch"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>modules/quotations/download_quotation.php?id=<?php echo $row['quotation_id']; ?>" class="btn btn-download" title="Download Quotation" target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="javascript:void(0);" onclick="convertAndOpen('convert_to_invoice.php?id=<?php echo $row['quotation_id']; ?>')" class="btn btn-process" title="Convert to Invoice">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                    <a href="javascript:void(0);" class="btn btn-cancel cancel-quotation" title="Cancel Quotation"
                                        data-id="<?php echo $row['quotation_id']; ?>"
                                        data-customer="<?php echo htmlspecialchars($row['customer_business_name'] ?: $row['customer_name']); ?>">
                                        <i class="fas fa-times-circle"></i>
                                    </a>
                                </div>
                            </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="9" class="text-center empty-state"><i class="fas fa-file-alt"></i><p>No draft quotations found</p></td></tr>
                                        <?php endif; ?>
</tbody>
                                </table>
                            </div>

                            <div class="pagination-container d-flex justify-content-between align-items-center mt-4">
                                <div class="entries-info">
                                    Showing <strong><?php echo ($offset + 1); ?></strong> to <strong><?php echo min($offset + $limit, $totalRows); ?></strong> of <strong><?php echo $totalRows; ?></strong> entries
                                </div>
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        </div>
                    </div>
            </main>
        </div>
    </div>
    
    <!-- Modal for Revision History -->
    <div class="modal fade" id="revisionHistoryModal" tabindex="-1" aria-labelledby="revisionHistoryModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="revisionHistoryModalLabel"><i class="fas fa-code-branch me-2"></i>Revision History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="revisionChainContent">
                    Loading...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Viewing Quotation -->
    <div class="modal fade" id="viewQuotationModal" tabindex="-1" aria-labelledby="viewQuotationModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewQuotationModalLabel"><i class="fas fa-file-alt"></i>Quotation Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="quotationDetails">
                    Loading...
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal for Cancel Quotation Confirmation -->
    <div class="modal fade" id="cancelQuotationModal" tabindex="-1" aria-labelledby="cancelQuotationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-light border-bottom-0 py-3">
                    <h5 class="modal-title fw-bold" id="cancelQuotationModalLabel">
                        <i class="fas fa-times-circle me-2 text-danger"></i>Cancel Quotation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="icon-box bg-danger-soft rounded-circle mx-auto mb-3" style="width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; background-color: rgba(220, 53, 69, 0.1);">
                            <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                        </div>
                        <h6 class="fw-bold mb-1">Are you sure?</h6>
                        <p class="text-muted small">This action will cancel the quotation. This cannot be undone.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="p-3 bg-light rounded-3 border-start border-danger border-4">
                                <div class="mb-2">
                                    <small class="text-muted text-uppercase fw-semibold letter-spacing-1 d-block">Quotation ID</small>
                                    <span class="fw-bold text-dark" id="cancel_quotation_id"></span>
                                </div>
                                <div>
                                    <small class="text-muted text-uppercase fw-semibold letter-spacing-1 d-block">Customer</small>
                                    <span class="fw-bold text-dark" id="cancel_quotation_customer"></span>
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
                    <form method="post" action="cancel_quotation.php" id="cancelQuotationForm" class="m-0">
                        <input type="hidden" name="quotation_id" id="confirm_cancel_quotation_id">
                        <input type="hidden" name="cancel_reason" id="confirm_cancel_reason">
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
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    
    <script>
        $(document).ready(function () {
            $('.revision-history').click(function (e) {
                e.preventDefault();
                var quotationId = $(this).data('id');
                $('#revisionChainContent').html('Loading...');
                $.ajax({
                    url: 'get_revision_chain.php',
                    type: 'GET',
                    data: { id: quotationId },
                    success: function (response) {
                        $('#revisionChainContent').html(response);
                        $('#revisionHistoryModal').modal('show');
                    },
                    error: function () {
                        $('#revisionChainContent').html('Failed to load revision history.');
                    }
                });
            });

            $('.view-quotation').click(function (e) {
                e.preventDefault();
                var quotationId = $(this).data('id');
                $('#quotationDetails').html('Loading...');
                $.ajax({
                    url: 'download_quotation.php',
                    type: 'GET',
                    data: { 
                        id: quotationId,
                        format: 'html'
                    },
                    success: function (response) {
                        $('#quotationDetails').html(response);
                        $('#quotationDetails').find('button:contains("Print Quotation")').remove();
                        $('#quotationDetails').find('button:contains("Download PDF")').remove();
                        $('#viewQuotationModal').modal('show');
                    },
                    error: function () {
                        $('#quotationDetails').html('Failed to load quotation details.');
                    }
                });
            });
        });

        function convertAndOpen(url) {
            Swal.fire({
                title: 'Convert to Invoice?',
                text: 'Are you sure you want to convert this quotation to an invoice?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, convert it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.open(url, '_blank');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                }
            });
        }

        $(document).on('click', '.cancel-quotation', function(e) {
            e.preventDefault();
            var quotationId = $(this).data('id');
            var customerName = $(this).data('customer');
            $('#cancel_quotation_id').text(quotationId);
            $('#cancel_quotation_customer').text(customerName);
            $('#confirm_cancel_quotation_id').val(quotationId);
            $('#cancel_reason').val('');
            $('#cancelQuotationModal').modal('show');
        });

        $('#cancelQuotationForm').on('submit', function() {
            $('#confirm_cancel_reason').val($('#cancel_reason').val());
        });
    </script>
</body>
</html>
 <?php $conn->close(); ?>
