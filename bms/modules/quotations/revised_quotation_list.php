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

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_customer = isset($_GET['filter_customer']) ? trim($_GET['filter_customer']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$status_filter = "Revised";

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
    $conditions[] = "q.issue_date >= '$d'";
}
if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "q.issue_date <= '$d'";
}
if (!empty($filter_customer)) {
    $c = $conn->real_escape_string($filter_customer);
    $conditions[] = "(c.name LIKE '%$c%' OR c.business_name LIKE '%$c%')";
}

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
    <title>Revised Quotations</title>
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
                        <h5>Revised Quotations</h5>
                        <p class="text-muted">Previously revised quotations that have been superseded by newer versions</p>
                    </div>
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
                                            <a href="<?= BASE_URL ?>modules/quotations/revised_quotation_list.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                    <input type="hidden" name="limit" value="<?= $limit ?>">
                                </form>
                            </div>
                            
                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Revised Quotation<?= $totalRows !== 1 ? 's' : '' ?></span>
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
                                                            : htmlspecialchars(generateRefNo($conn, $row['quotation_id'], $row['issue_date'], 'QT'));
                                                        echo $refNo;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['customer_business_name'] ?: $row['customer_name']); ?></div>
                                                        <div class="text-muted small"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($row['issue_date'])); ?></td>
                                                    <td><?php echo $row['due_date'] ? date('d/m/Y', strtotime($row['due_date'])) : '-'; ?></td>
                                                    <td>
                                                        <div class="amount-text"><?php echo number_format($row['total_amount'], 2); ?> <span class="currency-symbol">(Rs)</span></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge-soft badge-soft-revised">
                                                            Revised
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['creator_name']); ?></td>
<td>
                            <div class="action-btn-group d-flex gap-1">
                                <?php if (hasAccess('quotations.revised')): ?>
                                <a href="#" class="btn btn-view view-quotation" title="View Quotation"
                                    data-id="<?php echo $row['quotation_id']; ?>">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= BASE_URL ?>modules/quotations/download_quotation.php?id=<?php echo $row['quotation_id']; ?>" class="btn btn-download" title="Download Quotation" target="_blank">
                                    <i class="fas fa-download"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="9" class="text-center empty-state"><i class="fas fa-file-alt"></i><p>No revised quotations found</p></td></tr>
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
     
     <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
     <script src="<?= BASE_URL ?>js/scripts.js"></script>
     
     <script>
         $(document).ready(function () {
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
     </script>
 </body>
 </html>
 <?php $conn->close(); ?>