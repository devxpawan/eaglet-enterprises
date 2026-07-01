<?php
require_once __DIR__ . '/../../config/paths.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Handle CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasAccess('products.categories')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (!empty($name)) {
            $stmt = $conn->prepare("INSERT INTO categories (name, description, parent_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $desc, $parent);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Category added successfully!";
            } else {
                $_SESSION['error_message'] = "Error adding category: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (!empty($name) && $id > 0) {
            $stmt = $conn->prepare("UPDATE categories SET name=?, description=?, parent_id=?, status=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $desc, $parent, $status, $id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Category updated successfully!";
            } else {
                $_SESSION['error_message'] = "Error updating category: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        $status = $_POST['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE categories SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'new_status' => $status]);
        exit();
    }
    
    header("Location: " . BASE_URL . "modules/products/categories.php");
    exit();
}

// Fetch categories
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';

$where = [];
if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $where[] = "(c.name LIKE '%$s%' OR c.description LIKE '%$s%')";
}
if ($filter_status !== '') {
    $s = $conn->real_escape_string($filter_status);
    $where[] = "c.status = '$s'";
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$countResult = $conn->query("SELECT COUNT(*) as total FROM categories c $whereClause");
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);

$cats = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM categories WHERE parent_id = c.id) as child_count,
    (SELECT COUNT(*) FROM products WHERE category_id = c.id) as product_count
    FROM categories c $whereClause ORDER BY c.name ASC LIMIT $limit OFFSET $offset");

// For parent dropdown
$allCats = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Product Categories</title>
    <link href="<?= BASE_URL ?>css/product-list.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/inventory.css" rel="stylesheet" />
</head>
<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="inventory-container">
                    <div class="page-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5>Product Categories</h5>
                            <p class="text-muted">Organize products into categories and subcategories</p>
                        </div>
                        <?php if (hasAccess('products.categories')): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php
                    if (isset($_SESSION['success_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("success", "' . addslashes($_SESSION['success_message']) . '"); });</script>';
                        unset($_SESSION['success_message']);
                    }
                    if (isset($_SESSION['error_message'])) {
                        echo '<script>document.addEventListener("DOMContentLoaded", function() { showToast("error", "' . addslashes($_SESSION['error_message']) . '"); });</script>';
                        unset($_SESSION['error_message']);
                    }
                    ?>

                    <div class="inventory-card">
                        <div class="card-body">
                            <div class="invoice-filter-bar mb-3">
                                <form method="get">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-4">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Search</label>
                                            <input type="text" name="search" class="form-control" placeholder="Category name or description" value="<?= htmlspecialchars($search) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Status</label>
                                            <select name="filter_status" class="form-select">
                                                <option value="">All</option>
                                                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <div class="col-md-auto d-flex gap-1 align-items-end">
                                            <button type="submit" class="btn btn-primary btn-filter"><i class="fas fa-search me-1"></i> Search</button>
                                            <a href="<?= BASE_URL ?>modules/products/categories.php" class="btn btn-outline-secondary btn-clear"><i class="fas fa-times me-1"></i> Clear</a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?= $totalRows; ?> Category<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Category Name</th>
                                            <th>Description</th>
                                            <th>Parent</th>
                                            <th>Sub-Categories</th>
                                            <th>Products</th>
                                            <th>Status</th>
                                            <?php if (hasAccess('products.categories')): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($cats && $cats->num_rows > 0): ?>
                                            <?php while ($cat = $cats->fetch_assoc()): ?>
                                            <tr>
                                                <td><span class="fw-semibold"><?= $cat['id'] ?></span></td>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($cat['name']) ?></span></td>
                                                <td><small class="text-muted"><?= htmlspecialchars($cat['description'] ?? '—') ?></small></td>
                                                <td>
                                                    <?php
                                                    if ($cat['parent_id']) {
                                                        $p = $conn->query("SELECT name FROM categories WHERE id = {$cat['parent_id']}");
                                                        echo $p && $p->num_rows > 0 ? htmlspecialchars($p->fetch_assoc()['name']) : '—';
                                                    } else {
                                                        echo '<em class="text-muted">Top Level</em>';
                                                    }
                                                    ?>
                                                </td>
                                                <td><span class="badge bg-secondary bg-opacity-10 text-dark"><?= $cat['child_count'] ?></span></td>
                                                <td><span class="badge bg-info bg-opacity-10 text-info"><?= $cat['product_count'] ?></span></td>
                                                <td>
                                                    <?php if ($cat['status'] === 'active'): ?>
                                                        <span class="badge-soft badge-soft-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge-soft badge-soft-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if (hasAccess('products.categories')): ?>
                                                <td>
                                                    <div class="action-btn-group d-flex gap-1">
                                                        <button class="btn btn-edit edit-cat-btn" title="Edit"
                                                            data-id="<?= $cat['id'] ?>"
                                                            data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                                                            data-desc="<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES) ?>"
                                                            data-parent="<?= $cat['parent_id'] ?? '' ?>"
                                                            data-status="<?= $cat['status'] ?>">
                                                            <i class="fas fa-pen"></i>
                                                        </button>
                                                        <button class="btn <?= $cat['status'] === 'active' ? 'btn-deactivate' : 'btn-activate' ?> toggle-cat-status"
                                                            data-id="<?= $cat['id'] ?>"
                                                            data-status="<?= $cat['status'] ?>"
                                                            data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>">
                                                            <i class="fas <?= $cat['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="<?= hasAccess('products.categories') ? 8 : 7 ?>" class="text-center py-4">No categories found</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="pagination-container d-flex justify-content-end align-items-center mt-4">
                                
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tag me-2"></i>Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="Category Name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Parent Category</label>
                            <select name="parent_id" class="form-select">
                                <option value="">— Top Level (None) —</option>
                                <?php if ($allCats): while ($ac = $allCats->fetch_assoc()): ?>
                                    <option value="<?= $ac['id'] ?>"><?= htmlspecialchars($ac['name']) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn"><i class="fas fa-save me-1"></i> Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_cat_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_cat_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_cat_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Parent Category</label>
                            <select name="parent_id" id="edit_cat_parent" class="form-select">
                                <option value="">— Top Level (None) —</option>
                                <?php 
                                $allCats2 = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC");
                                if ($allCats2): while ($ac = $allCats2->fetch_assoc()): ?>
                                    <option value="<?= $ac['id'] ?>"><?= htmlspecialchars($ac['name']) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_cat_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn"><i class="fas fa-save me-1"></i> Update Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
    $(document).ready(function() {
        // Edit button - populate modal
        $('.edit-cat-btn').click(function() {
            $('#edit_cat_id').val($(this).data('id'));
            $('#edit_cat_name').val($(this).data('name'));
            $('#edit_cat_desc').val($(this).data('desc'));
            $('#edit_cat_parent').val($(this).data('parent'));
            $('#edit_cat_status').val($(this).data('status'));
            $('#editCategoryModal').modal('show');
        });

        // Toggle status via AJAX
        $('.toggle-cat-status').click(function() {
            const btn = $(this);
            const id = btn.data('id');
            const status = btn.data('status');
            const name = btn.data('name');
            const action = status === 'active' ? 'deactivate' : 'activate';

            Swal.fire({
                title: `Are you sure?`,
                text: `You are about to ${action} category "${name}"`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: status === 'active' ? '#d33' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} it!`
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '<?= BASE_URL ?>modules/products/categories.php',
                        method: 'POST',
                        data: { action: 'toggle_status', id: id, status: status },
                        dataType: 'json',
                        success: function(res) {
                            if (res.success) {
                                showToast('success', `Category ${action}d successfully`);
                                setTimeout(() => location.reload(), 1000);
                            }
                        }
                    });
                }
            });
        });
    });
    </script>
</body>
</html>
<?php $conn->close(); ?>