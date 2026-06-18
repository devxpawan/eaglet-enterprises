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

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasAccess('inventory.suppliers')) {
    $action = $_POST['action'] ?? '';
    
    if (in_array($action, ['add', 'edit'])) {
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $tax_id = trim($_POST['tax_id'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (!empty($company_name)) {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO suppliers (company_name, contact_person, email, phone, mobile, address, city, tax_id, payment_terms, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssssss", $company_name, $contact_person, $email, $phone, $mobile, $address, $city, $tax_id, $payment_terms, $notes);
            } else {
                $id = (int)$_POST['id'];
                $status = $_POST['status'] ?? 'active';
                $stmt = $conn->prepare("UPDATE suppliers SET company_name=?, contact_person=?, email=?, phone=?, mobile=?, address=?, city=?, tax_id=?, payment_terms=?, notes=?, status=? WHERE id=?");
                $stmt->bind_param("sssssssssssi", $company_name, $contact_person, $email, $phone, $mobile, $address, $city, $tax_id, $payment_terms, $notes, $status, $id);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = $action === 'add' ? "Supplier added successfully!" : "Supplier updated successfully!";
            } else {
                $_SESSION['error_message'] = "Error: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        $new_status = $_POST['status'] === 'active' ? 'inactive' : 'active';
        $stmt = $conn->prepare("UPDATE suppliers SET status=? WHERE id=?");
        $stmt->bind_param("si", $new_status, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'new_status' => $new_status]);
        exit();
    }
    
    header("Location: " . BASE_URL . "modules/inventory/suppliers.php");
    exit();
}

// Fetch suppliers
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';

$where = [];
if (!empty($search)) {
    $s = $conn->real_escape_string($search);
    $where[] = "(s.company_name LIKE '%$s%' OR s.contact_person LIKE '%$s%' OR s.email LIKE '%$s%' OR s.phone LIKE '%$s%' OR s.city LIKE '%$s%')";
}
if ($filter_status !== '') {
    $s = $conn->real_escape_string($filter_status);
    $where[] = "s.status = '$s'";
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$countResult = $conn->query("SELECT COUNT(*) as total FROM suppliers s $whereClause");
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);

$suppliers = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.id) as po_count FROM suppliers s $whereClause ORDER BY s.company_name ASC LIMIT $limit OFFSET $offset");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Suppliers</title>
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
                            <h5>Suppliers</h5>
                            <p class="text-muted">Manage your vendors and suppliers</p>
                        </div>
                        <?php if (hasAccess('inventory.suppliers')): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                            <i class="fas fa-plus"></i> Add Supplier
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
                                            <input type="text" name="search" class="form-control" placeholder="Company, contact, email, city..." value="<?= htmlspecialchars($search) ?>">
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
                                            <a href="<?= BASE_URL ?>modules/inventory/suppliers.php" class="btn btn-outline-secondary btn-clear"><i class="fas fa-times me-1"></i> Clear</a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?= $totalRows; ?> Supplier<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Company</th>
                                            <th>Contact Person</th>
                                            <th>Email / Phone</th>
                                            <th>City</th>
                                            <th>Tax ID</th>
                                            <th>Orders</th>
                                            <th>Status</th>
                                            <?php if (hasAccess('inventory.suppliers')): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                                            <?php while ($s = $suppliers->fetch_assoc()): ?>
                                            <tr>
                                                <td><span class="fw-semibold"><?= htmlspecialchars($s['company_name']) ?></span></td>
                                                <td><?= htmlspecialchars($s['contact_person'] ?? '—') ?></td>
                                                <td>
                                                    <div><?= htmlspecialchars($s['email'] ?? '') ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($s['phone'] ?? '') ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($s['city'] ?? '—') ?></td>
                                                <td><small><?= htmlspecialchars($s['tax_id'] ?? '—') ?></small></td>
                                                <td><span class="badge bg-secondary bg-opacity-10 text-dark"><?= $s['po_count'] ?></span></td>
                                                <td>
                                                    <?php if ($s['status'] === 'active'): ?>
                                                        <span class="badge-soft badge-soft-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge-soft badge-soft-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if (hasAccess('inventory.suppliers')): ?>
                                                <td>
                                                    <div class="action-btn-group d-flex gap-1">
                                                        <button class="btn btn-view view-supplier-btn" title="View"
                                                            data-id="<?= $s['id'] ?>"
                                                            data-company="<?= htmlspecialchars($s['company_name'], ENT_QUOTES) ?>"
                                                            data-contact="<?= htmlspecialchars($s['contact_person'] ?? '', ENT_QUOTES) ?>"
                                                            data-email="<?= htmlspecialchars($s['email'] ?? '', ENT_QUOTES) ?>"
                                                            data-phone="<?= htmlspecialchars($s['phone'] ?? '', ENT_QUOTES) ?>"
                                                            data-mobile="<?= htmlspecialchars($s['mobile'] ?? '', ENT_QUOTES) ?>"
                                                            data-address="<?= htmlspecialchars($s['address'] ?? '', ENT_QUOTES) ?>"
                                                            data-city="<?= htmlspecialchars($s['city'] ?? '', ENT_QUOTES) ?>"
                                                            data-tax="<?= htmlspecialchars($s['tax_id'] ?? '', ENT_QUOTES) ?>"
                                                            data-terms="<?= htmlspecialchars($s['payment_terms'] ?? '', ENT_QUOTES) ?>"
                                                            data-notes="<?= htmlspecialchars($s['notes'] ?? '', ENT_QUOTES) ?>"
                                                            data-status="<?= $s['status'] ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-edit edit-supplier-btn" title="Edit"
                                                            data-id="<?= $s['id'] ?>"
                                                            data-company="<?= htmlspecialchars($s['company_name'], ENT_QUOTES) ?>"
                                                            data-contact="<?= htmlspecialchars($s['contact_person'] ?? '', ENT_QUOTES) ?>"
                                                            data-email="<?= htmlspecialchars($s['email'] ?? '', ENT_QUOTES) ?>"
                                                            data-phone="<?= htmlspecialchars($s['phone'] ?? '', ENT_QUOTES) ?>"
                                                            data-mobile="<?= htmlspecialchars($s['mobile'] ?? '', ENT_QUOTES) ?>"
                                                            data-address="<?= htmlspecialchars($s['address'] ?? '', ENT_QUOTES) ?>"
                                                            data-city="<?= htmlspecialchars($s['city'] ?? '', ENT_QUOTES) ?>"
                                                            data-tax="<?= htmlspecialchars($s['tax_id'] ?? '', ENT_QUOTES) ?>"
                                                            data-terms="<?= htmlspecialchars($s['payment_terms'] ?? '', ENT_QUOTES) ?>"
                                                            data-notes="<?= htmlspecialchars($s['notes'] ?? '', ENT_QUOTES) ?>"
                                                            data-status="<?= $s['status'] ?>">
                                                            <i class="fas fa-pen"></i>
                                                        </button>
                                                        <button class="btn <?= $s['status'] === 'active' ? 'btn-deactivate' : 'btn-activate' ?> toggle-supplier-status"
                                                            data-id="<?= $s['id'] ?>"
                                                            data-status="<?= $s['status'] ?>"
                                                            data-name="<?= htmlspecialchars($s['company_name'], ENT_QUOTES) ?>">
                                                            <i class="fas <?= $s['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="<?= hasAccess('inventory.suppliers') ? 8 : 7 ?>" class="text-center py-4">No suppliers found</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="pagination-container d-flex justify-content-between align-items-center mt-4">
                                <div class="entries-info">
                                    Showing <strong><?= $totalRows > 0 ? $offset + 1 : 0 ?></strong> to <strong><?= min($offset + $limit, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong> entries
                                </div>
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Supplier Modal -->
    <div class="modal fade" id="addSupplierModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Add Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                <input type="text" name="company_name" class="form-control" required placeholder="Company name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Person</label>
                                <input type="text" name="contact_person" class="form-control" placeholder="Full name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" placeholder="email@example.com">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" placeholder="Phone">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Mobile</label>
                                <input type="text" name="mobile" class="form-control" placeholder="Mobile">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City</label>
                                <input type="text" name="city" class="form-control" placeholder="City">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tax ID / VAT Number</label>
                                <input type="text" name="tax_id" class="form-control" placeholder="Tax ID">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="Full address"></textarea>
                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Payment Terms</label>
                                                <select name="payment_terms" class="form-select">
                                                    <option value="">— Select —</option>
                                                    <option value="Net 30">Net 30 (Pay within 30 days)</option>
                                                    <option value="Net 15">Net 15 (Pay within 15 days)</option>
                                                    <option value="Net 60">Net 60 (Pay within 60 days)</option>
                                                    <option value="Due on Receipt">Due on Receipt (Pay immediately)</option>
                                                    <option value="Cash on Delivery">Cash on Delivery (COD)</option>
                                                </select>
                                                <small class="text-muted">When is payment expected for this supplier?</small>
                                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn"><i class="fas fa-save me-1"></i> Save Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Supplier Modal -->
    <div class="modal fade" id="editSupplierModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_sup_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                <input type="text" name="company_name" id="edit_sup_company" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Person</label>
                                <input type="text" name="contact_person" id="edit_sup_contact" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_sup_email" class="form-control">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" id="edit_sup_phone" class="form-control">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Mobile</label>
                                <input type="text" name="mobile" id="edit_sup_mobile" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City</label>
                                <input type="text" name="city" id="edit_sup_city" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tax ID</label>
                                <input type="text" name="tax_id" id="edit_sup_tax" class="form-control">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Address</label>
                                <textarea name="address" id="edit_sup_address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Terms</label>
                                <select name="payment_terms" id="edit_sup_terms" class="form-select">
                                    <option value="">— Select —</option>
                                    <option value="Net 30">Net 30 (Pay within 30 days)</option>
                                    <option value="Net 15">Net 15 (Pay within 15 days)</option>
                                    <option value="Net 60">Net 60 (Pay within 60 days)</option>
                                    <option value="Due on Receipt">Due on Receipt (Pay immediately)</option>
                                    <option value="Cash on Delivery">Cash on Delivery (COD)</option>
                                </select>
                                <small class="text-muted">When is payment expected for this supplier?</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_sup_status" class="form-select">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" id="edit_sup_notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="save-btn"><i class="fas fa-save me-1"></i> Update Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Supplier Modal -->
    <div class="modal fade" id="viewSupplierModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Supplier Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="supplierDetailsBody">
                    <!-- Dynamically populated -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
    $(document).ready(function() {
        // View supplier
        $('.view-supplier-btn').click(function() {
            const d = $(this).data();
            let html = `<div class="detail-grid">
                <div class="detail-card"><span class="detail-label">Company</span><p class="detail-value">${d.company}</p></div>
                <div class="detail-card"><span class="detail-label">Contact Person</span><p class="detail-value">${d.contact || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">Email</span><p class="detail-value">${d.email || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">Phone</span><p class="detail-value">${d.phone || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">Mobile</span><p class="detail-value">${d.mobile || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">City</span><p class="detail-value">${d.city || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">Tax ID</span><p class="detail-value">${d.tax || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">Payment Terms</span><p class="detail-value">${d.terms || '—'}</p></div>
                <div class="detail-card full-width"><span class="detail-label">Address</span><p class="detail-value">${d.address || '—'}</p></div>
                <div class="detail-card full-width"><span class="detail-label">Notes</span><p class="detail-value">${d.notes || '—'}</p></div>
                <div class="detail-card"><span class="detail-label">Status</span><p class="detail-value">${d.status === 'active' ? '<span class="badge-soft badge-soft-success">Active</span>' : '<span class="badge-soft badge-soft-danger">Inactive</span>'}</p></div>
            </div>`;
            $('#supplierDetailsBody').html(html);
            $('#viewSupplierModal').modal('show');
        });

        // Edit supplier - populate modal
        $('.edit-supplier-btn').click(function() {
            const d = $(this).data();
            $('#edit_sup_id').val(d.id);
            $('#edit_sup_company').val(d.company);
            $('#edit_sup_contact').val(d.contact);
            $('#edit_sup_email').val(d.email);
            $('#edit_sup_phone').val(d.phone);
            $('#edit_sup_mobile').val(d.mobile);
            $('#edit_sup_address').val(d.address);
            $('#edit_sup_city').val(d.city);
            $('#edit_sup_tax').val(d.tax);
            $('#edit_sup_terms').val(d.terms);
            $('#edit_sup_notes').val(d.notes);
            $('#edit_sup_status').val(d.status);
            $('#editSupplierModal').modal('show');
        });

        // Toggle status
        $('.toggle-supplier-status').click(function() {
            const btn = $(this);
            const id = btn.data('id');
            const status = btn.data('status');
            const name = btn.data('name');
            const action = status === 'active' ? 'deactivate' : 'activate';

            Swal.fire({
                title: `Are you sure?`,
                text: `You are about to ${action} supplier "${name}"`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: status === 'active' ? '#d33' : '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `Yes, ${action} it!`
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '<?= BASE_URL ?>modules/inventory/suppliers.php',
                        method: 'POST',
                        data: { action: 'toggle_status', id: id, status: status },
                        dataType: 'json',
                        success: function(res) {
                            if (res.success) {
                                showToast('success', `Supplier ${action}d successfully`);
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