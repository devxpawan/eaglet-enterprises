<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Initialize filter parameters
$filter_ref_no = isset($_GET['filter_ref_no']) ? trim($_GET['filter_ref_no']) : '';

$filter_customer = isset($_GET['filter_customer']) ? trim($_GET['filter_customer']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$conditions = [];
if (!empty($filter_ref_no)) {
    $s = $conn->real_escape_string($filter_ref_no);
    $conditions[] = "pl.ref_no LIKE '%$s%'";
}
if (!empty($filter_customer)) {
    $s = $conn->real_escape_string($filter_customer);
    $conditions[] = "c.name LIKE '%$s%'";
}
if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $conditions[] = "pl.price_list_date >= '$d'";
}
if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "pl.price_list_date <= '$d'";
}
$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

// Count total price lists
$countSql = "SELECT COUNT(*) as total FROM price_lists pl LEFT JOIN customers c ON pl.customer_id = c.customer_id$whereClause";
$countResult = $conn->query($countSql);
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);

// Fetch price lists with limit (JOIN with customers)
$sql = "SELECT pl.*, COALESCE(pl.customer_name, c.name) as display_customer_name 
        FROM price_lists pl 
        LEFT JOIN customers c ON pl.customer_id = c.customer_id 
        $whereClause 
        ORDER BY pl.id DESC 
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Price Lists</title>
    <link href="<?= BASE_URL ?>css/invoice-list.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/price-list.css" rel="stylesheet" />
</head>
<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Price Lists</h5>
                        <p class="text-muted">Manage and review all price lists</p>
                    </div>
                    <?php if (hasAccess('price_lists')): ?>
                    <a href="<?= BASE_URL ?>modules/price-lists/price_list_create.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Create New
                    </a>
                    <?php endif; ?>
                </div>

                    <div class="card invoice-card">
                        <div class="card-body">
                            <!-- Filter Bar -->
                            <div class="invoice-filter-bar">
                                <form method="get" id="filterForm">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Price List No</label>
                                            <input type="text" name="filter_ref_no" class="form-control" placeholder="Search by ref number..."
                                                value="<?= htmlspecialchars($filter_ref_no) ?>">
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
                                        <div class="col-md-2 col-lg-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter">
                                                <i class="fas fa-search me-1"></i> Search
                                            </button>
                                            <a href="<?= BASE_URL ?>modules/price-lists/price_list.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Price List<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-invoice align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Price List No.</th>
                                            <th>Date</th>
                                            <th>Due Date</th>
                                            <th>Customer</th>
                                            <th>Subject</th>
                                            <th class="text-end pe-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($result && $result->num_rows > 0): ?>
                                            <?php while($row = $result->fetch_assoc()): ?>
                                                <tr>
                                                    <td class="text-muted" style="font-weight: 500;"><?= htmlspecialchars($row['ref_no'] ?? 'PL-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT)) ?></td>
                                                    <td><?= date('d M, Y', strtotime($row['price_list_date'])) ?></td>
                                                    <td><?= !empty($row['due_date']) ? date('d M, Y', strtotime($row['due_date'])) : '-' ?></td>
                                                    <td><?= htmlspecialchars($row['display_customer_name'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($row['subject'] ?? '-') ?></td>
                                                    <td class="text-end pe-3">
                                <div class="action-btn-group d-flex justify-content-end gap-1">
                                    <?php if (hasAccess('price_lists')): ?>
                                    <a href="<?= BASE_URL ?>modules/price-lists/price_list_edit.php?id=<?= $row['id'] ?>" class="btn btn-edit" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="#" class="btn btn-view view-price-list" title="View Price List"
                                        data-id="<?php echo $row['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>modules/price-lists/view_price_list.php?id=<?= $row['id'] ?>" class="btn btn-download" title="Download Price List" target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-5 text-muted">No price lists found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="pagination-container d-flex justify-content-end align-items-center mt-4">
                                
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        </div>
                    </div>
            </main>
    </div>
  </div>
  
  <!-- Modal for Viewing Price List -->
  <div class="modal fade" id="viewPriceListModal" tabindex="-1" aria-labelledby="viewPriceListModalLabel"
      aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="viewPriceListModalLabel"><i class="fas fa-file-alt me-2"></i>Price List Details</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body" id="priceListDetails">
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
          $('.view-price-list').click(function (e) {
              e.preventDefault();
              var priceListId = $(this).data('id');
              $('#priceListDetails').html('Loading...');
              $.ajax({
                  url: 'view_price_list.php',
                  type: 'GET',
                  data: { 
                      id: priceListId,
                      format: 'html'
                  },
                  success: function (response) {
                      $('#priceListDetails').html(response);
                      $('#priceListDetails').find('.control-panel').remove();
                      $('#viewPriceListModal').modal('show');
                  },
                  error: function () {
                      $('#priceListDetails').html('Failed to load price list details.');
                  }
              });
          });
      });
  </script>
</body>
</html>
<?php $conn->close(); ?>


