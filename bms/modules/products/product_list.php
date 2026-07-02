<?php
require_once __DIR__ . '/../../config/paths.php';

// File name: product_list.php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
// This check must happen before ANY output is sent to the browser
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

// Process status toggle if submitted
if(isset($_POST['toggle_status'])) {
    $product_id = $_POST['product_id'];
    $new_status = $_POST['new_status'];
    $user_id = $_SESSION['user_id']; // Get the current user's ID from session
    $product_name = ''; // Initialize product name variable
    
    // First, get the product name for the log
    $productQuery = "SELECT name FROM products WHERE id = ?";
    $stmt = $conn->prepare($productQuery);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $productResult = $stmt->get_result();
    
    if ($productResult->num_rows > 0) {
        $productData = $productResult->fetch_assoc();
        $product_name = $productData['name'];
    }
    $stmt->close();
    
    // Use prepared statement to prevent SQL injection
    $updateQuery = "UPDATE products SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("si", $new_status, $product_id);
    
    if($stmt->execute()) {
        // Set success message based on the new status
        $action = $new_status == 'active' ? 'activated' : 'deactivated';
        $_SESSION['success_message'] = "Product successfully $action!";
        
        // Log the action to user_logs table
        $action_type = $new_status == 'active' ? 'activate_product' : 'deactivate_product';
        $details = "Product ID #$product_id ($product_name) was $action by user ID #$user_id";
        
        $logQuery = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logQuery);
        $inquiry_id = 0; // Not applicable for product actions
        $logStmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
        $logStmt->execute();
        $logStmt->close();
    } else {
        $_SESSION['error_message'] = "Error updating product status: " . $conn->error;
    }
    
    $stmt->close();
    
    // Redirect to the same URL to clear POST data and preserve GET parameters (PRG pattern)
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

// Initialize filter parameters
$filter_name = isset($_GET['filter_name']) ? trim($_GET['filter_name']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$conditions = [];
if (!empty($filter_name)) {
    $s = $conn->real_escape_string($filter_name);
    $conditions[] = "name LIKE '%$s%'";
}
if ($filter_status !== '') {
    $s = $conn->real_escape_string($filter_status);
    $conditions[] = "status = '$s'";
}
$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

// Build SQL queries
$countSql = "SELECT COUNT(*) as total FROM products$whereClause";
$sql = "SELECT * FROM products$whereClause ORDER BY id DESC LIMIT $limit OFFSET $offset";

// Execute the queries
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
    <title>Product List</title>
    <link href="<?= BASE_URL ?>css/product-list.css" rel="stylesheet" />
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
                        <h5>Product List</h5>
                        <p class="text-muted">Manage and review all products</p>
                    </div>
                    <?php if (hasAccess('products.add')): ?>
                    <a href="<?= BASE_URL ?>modules/products/add_product.php" class="btn-add-product">
                        <i class="fas fa-plus"></i> Add Product
                    </a>
                    <?php endif; ?>
                </div>
                    
                    <?php
                    // Check if there's a success message in the session
                    if (isset($_SESSION['success_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("success", "' . addslashes($_SESSION['success_message']) . '"); });</script>';
                        unset($_SESSION['success_message']);
                    }
                    
                    // Check if there's an error message in the session
                    if (isset($_SESSION['error_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("error", "' . addslashes($_SESSION['error_message']) . '"); });</script>';
                        unset($_SESSION['error_message']);
                    }
                    ?>
                    
                    <div class="card product-card">
                        <div class="card-body">
                            <!-- Filter Bar -->
                            <div class="invoice-filter-bar">
                                <form method="get" id="filterForm">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Name</label>
                                            <input type="text" name="filter_name" class="form-control" placeholder="Search by product name..."
                                                value="<?= htmlspecialchars($filter_name) ?>">
                                        </div>
                                        <div class="col-md-2 col-lg-1">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Status</label>
                                            <select name="filter_status" class="form-select">
                                                <option value="">All</option>
                                                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 col-lg-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter">
                                                <i class="fas fa-search me-1"></i> Search
                                            </button>
                                            <a href="<?= BASE_URL ?>modules/products/product_list.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Product<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-product" id="productsTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Description</th>
                                            <th>Price (LKR)</th>
                                            <th>Stock Level</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($result && $result->num_rows > 0): ?>
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><span class="product-id"><?= htmlspecialchars($row['id']) ?></span></td>
                                                    <td><span class="product-name"><?= htmlspecialchars($row['name']) ?></span></td>
                                                    <td>
                                                        <span class="product-desc" title="<?= htmlspecialchars($row['description']) ?>">
                                                            <?= htmlspecialchars($row['description']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        if (isset($row['lkr_price'])) {
                                                            echo '<span class="product-price">' . number_format($row['lkr_price'], 2) . '</span><span class="product-currency"> LKR</span>';
                                                        } elseif (isset($row['price']) && isset($row['currency']) && $row['currency'] == 'LKR') {
                                                            echo '<span class="product-price">' . number_format($row['price'], 2) . '</span><span class="product-currency"> LKR</span>';
                                                        } else {
                                                            echo '<span class="product-currency">-</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="stock-level">
                                                            <?php
                                                            $stock = (int)($row['stock_quantity'] ?? 0);
                                                            $reorder = (int)($row['reorder_level'] ?? 0);
                                                            $unit = htmlspecialchars($row['unit'] ?? 'pcs');
                                                            $barColor = $stock <= 0 ? 'red' : ($stock <= $reorder ? 'yellow' : 'green');
                                                            ?>
                                                            <span class="stock-dot <?= $barColor ?>"></span>
                                                            <?= $stock ?> <?= $unit ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status = isset($row['status']) ? $row['status'] : 'active';
                                                        ?>
                                                        <?php if ($status == 'active'): ?>
                                                            <span class="badge-soft badge-soft-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge-soft badge-soft-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="action-btn-group d-flex gap-1">
                                                            <?php if (hasAccess('products')): ?>
                                                            <a href="#" class="btn btn-view view-product"
                                                                title="View Details"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#viewModal<?= $row['id'] ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            
                                                            <?php if (hasAccess('products.add')): ?>
                                                            <a href="<?= BASE_URL ?>modules/products/edit_product.php?id=<?= htmlspecialchars($row['id']) ?>" class="btn btn-edit" title="Edit Product">
                                                                <i class="fas fa-pen"></i>
                                                            </a>
                                                            
                                                            <?php
                                                            $newStatus = $status == 'active' ? 'inactive' : 'active';
                                                            ?>
                                                            <button type="button" class="btn <?= $status == 'active' ? 'btn-deactivate' : 'btn-activate' ?>"
                                                                    title="<?= $status == 'active' ? 'Deactivate' : 'Activate' ?>"
                                                                    onclick="confirmStatusChange(<?= $row['id'] ?>, '<?= $newStatus ?>', '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>')">
                                                                <i class="fas <?= $status == 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                            </button>
                                                            
                                                            <form id="toggleForm<?= $row['id'] ?>" action="" method="POST" style="display:none;">
                                                                <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $newStatus ?>">
                                                                <input type="hidden" name="toggle_status" value="1">
                                                            </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>

                                                <!-- View Modal -->
                                                <div class="modal fade" id="viewModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered modal-system">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="viewModalLabel"><i class="fas fa-box me-2"></i>Product Details</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="detail-grid">
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-hashtag"></i>ID</span>
                                                                        <p class="detail-value"><?= htmlspecialchars($row['id']) ?></p>
                                                                    </div>
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-tag"></i>Name</span>
                                                                        <p class="detail-value"><?= htmlspecialchars($row['name']) ?></p>
                                                                    </div>
                                                                    <div class="detail-card full-width">
                                                                        <span class="detail-label"><i class="fas fa-align-left"></i>Description</span>
                                                                        <p class="detail-value"><?= htmlspecialchars($row['description']) ?></p>
                                                                    </div>
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-money-bill"></i>Price (LKR)</span>
                                                                        <p class="detail-value">
                                                                            <?php
                                                                            if (isset($row['lkr_price'])) {
                                                                                echo number_format($row['lkr_price'], 2) . ' LKR';
                                                                            } elseif (isset($row['price']) && isset($row['currency']) && $row['currency'] == 'LKR') {
                                                                                echo number_format($row['price'], 2) . ' LKR';
                                                                            } else {
                                                                                echo '<em class="text-muted">-</em>';
                                                                            }
                                                                            ?>
                                                                        </p>
                                                                    </div>
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-boxes"></i>Stock Level</span>
                                                                        <p class="detail-value"><?= htmlspecialchars($row['stock_quantity'] ?? '0') ?> <?= htmlspecialchars($row['unit'] ?? 'pcs') ?></p>
                                                                    </div>
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-triangle-exclamation"></i>Reorder Level</span>
                                                                        <p class="detail-value"><?= htmlspecialchars($row['reorder_level'] ?? '0') ?></p>
                                                                    </div>
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-calendar"></i>Created At</span>
                                                                        <p class="detail-value"><?= htmlspecialchars($row['created_at']) ?></p>
                                                                    </div>
                                                                    <div class="detail-card">
                                                                        <span class="detail-label"><i class="fas fa-flag"></i>Status</span>
                                                                        <p class="detail-value">
                                                                            <?php if ($status == 'active'): ?>
                                                                                <span class="badge-soft badge-soft-success">Active</span>
                                                                            <?php else: ?>
                                                                                <span class="badge-soft badge-soft-danger">Inactive</span>
                                                                            <?php endif; ?>
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <div class="empty-state">
                                                        <i class="fas fa-box-open"></i>
                                                        <p>No products found</p>
                                                    </div>
                                                </td>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="<?= BASE_URL ?>js/select2-init.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        $(document).ready(function() {
            $('select[name="filter_status"]').select2({ minimumResultsForSearch: Infinity });
        });

        // SweetAlert confirmation function
        function confirmStatusChange(productId, newStatus, productName) {
            const action = newStatus === 'active' ? 'activate' : 'deactivate';
            const actionCapitalized = action.charAt(0).toUpperCase() + action.slice(1);
            
            Swal.fire({
                title: `${actionCapitalized} Product?`,
                text: `Are you sure you want to ${action} "${productName}"?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: newStatus === 'active' ? '#28a745' : '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} it!`,
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // If confirmed, submit the form
                    document.getElementById(`toggleForm${productId}`).submit();
                    
                    // Show processing message
                    Swal.fire({
                        title: 'Processing...',
                        text: `${actionCapitalized} the product.`,
                        icon: 'info',
                        showConfirmButton: false,
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>