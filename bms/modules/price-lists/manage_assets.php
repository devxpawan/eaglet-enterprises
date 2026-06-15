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

// Count total assets
$countSql = "SELECT COUNT(*) as total FROM assets$whereClause";
$countResult = $conn->query($countSql);
$totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalRows / $limit);

// Fetch assets with limit
$sql = "SELECT * FROM assets$whereClause ORDER BY id DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Manage Assets</title>
    <link href="<?= BASE_URL ?>css/invoice-list.css" rel="stylesheet" />
</head>
<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Asset Management</h5>
                        <p class="text-muted">Manage assets used in price lists</p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assetModal" onclick="prepareAdd()">
                        <i class="fas fa-plus me-1"></i> Add New Asset
                    </button>
                </div>

                    <div class="card invoice-card mb-4">
                        <div class="card-body">
                            <!-- Filter Bar -->
                            <div class="invoice-filter-bar">
                                <form method="get" id="filterForm">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Name</label>
                                            <input type="text" name="filter_name" class="form-control" placeholder="Asset name"
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
                                            <a href="<?= BASE_URL ?>modules/price-lists/manage_assets.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> Asset<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-invoice align-middle mb-0" id="asset_table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Asset Name</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        if ($result && $result->num_rows > 0): 
                                            while($row = $result->fetch_assoc()): ?>
                                                <tr id="asset-row-<?= $row['id'] ?>">
                                                    <td><span class="fw-semibold"><?= $row['id'] ?></span></td>
                                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($row['name']) ?></td>
                                                    <td class="text-center">
                                                        <span class="asset-status-badge badge-soft badge-soft-<?= $row['status'] == 'active' ? 'success' : 'danger' ?>">
                                                            <?= ucfirst($row['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="action-btn-group d-flex justify-content-center gap-1">
                                                            <button class="btn btn-edit"
                                                                    title="Edit"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#assetModal"
                                                                    data-asset-id="<?= $row['id'] ?>"
                                                                    data-asset-name="<?= htmlspecialchars($row['name']) ?>"
                                                                    data-asset-status="<?= $row['status'] ?>">
                                                                <i class="fas fa-pen"></i>
                                                            </button>
                                                            <button class="btn btn-view view-asset-btn"
                                                                    title="View Details"
                                                                    data-asset-id="<?= $row['id'] ?>"
                                                                    data-asset-name="<?= htmlspecialchars($row['name']) ?>"
                                                                    data-asset-status="<?= $row['status'] ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <button class="btn <?= $row['status'] == 'active' ? 'btn-cancel' : 'btn-view' ?> toggle-asset-status-btn"
                                                                    title="<?= $row['status'] == 'active' ? 'Deactivate' : 'Activate' ?>"
                                                                    data-asset-id="<?= $row['id'] ?>"
                                                                    data-current-status="<?= $row['status'] ?>"
                                                                    data-asset-name="<?= htmlspecialchars($row['name']) ?>">
                                                                <i class="fas <?= $row['status'] == 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; 
                                        else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-5 text-muted">No assets found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="pagination-container d-flex justify-content-between align-items-center mt-4">
                                <div class="entries-info">
                                    Showing <strong><?= ($offset + 1) ?></strong> to <strong><?= min($offset + $limit, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong> entries
                                </div>
                                <?= renderPagination($page, $totalPages) ?>
                            </div>
                        </div>
                    </div>
            </main>
        </div>
    </div>

    <!-- Asset Modal -->
    <div class="modal fade" id="assetModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle">Add Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="assetForm">
                    <div class="modal-body py-4">
                        <input type="hidden" name="id" id="asset_id">
                        <input type="hidden" name="action" id="asset_action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Asset Name</label>
                            <input type="text" name="name" id="asset_name" class="form-control" placeholder="e.g. Laptop, Printer, Router" required>
                        </div>
                        <div class="mb-0" id="statusField" style="display:none;">
                            <label class="form-label">Status</label>
                            <select name="status" id="asset_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="btnSave">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Asset Modal -->
    <div class="modal fade" id="viewAssetModal" tabindex="-1" aria-labelledby="viewAssetModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewAssetModalLabel"><i class="fas fa-box me-2"></i>Asset Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewAssetModalBody">
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Add Asset Modal
        function prepareAdd() {
            $('#modalTitle').text('Add New Asset');
            $('#asset_id').val('');
            $('#asset_name').val('');
            $('#asset_action').val('add');
            $('#statusField').hide();
            $('#btnSave').text('Add Asset');
        }

        $('#assetForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();

            $.ajax({
                url: '<?= BASE_URL ?>modules/api/ajax_asset_handler.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#assetModal').modal('hide');
                        showToast('success', response.message);
                        setTimeout(() => { location.reload(); }, 1500);
                    } else {
                        showToast('error', response.message);
                    }
                },
                error: function() {
                    showToast('error', 'Something went wrong. Please try again.');
                }
            });
        });

        // Edit button: populate modal with asset data
        document.addEventListener('DOMContentLoaded', function() {
            const editButtons = document.querySelectorAll('.btn-edit[data-asset-id]');
            editButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-asset-id');
                    const name = this.getAttribute('data-asset-name');
                    const status = this.getAttribute('data-asset-status');

                    $('#modalTitle').text('Edit Asset');
                    $('#asset_id').val(id);
                    $('#asset_name').val(name);
                    $('#asset_status').val(status);
                    $('#asset_action').val('edit');
                    $('#statusField').show();
                    $('#btnSave').text('Save Changes');
                });
            });

            // View Asset Modal Handling
            const viewButtons = document.querySelectorAll('.view-asset-btn');
            const viewAssetModal = new bootstrap.Modal(document.getElementById('viewAssetModal'));
            const viewAssetModalBody = document.getElementById('viewAssetModalBody');

            viewButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const assetData = {
                        id: this.getAttribute('data-asset-id'),
                        name: this.getAttribute('data-asset-name'),
                        status: this.getAttribute('data-asset-status')
                    };

                    viewAssetModalBody.innerHTML = `
                        <div class="detail-grid">
                            <div class="detail-card">
                                <span class="detail-label"><i class="fas fa-hashtag"></i>Asset ID</span>
                                <p class="detail-value">${assetData.id}</p>
                            </div>
                            <div class="detail-card full-width">
                                <span class="detail-label"><i class="fas fa-tag"></i>Asset Name</span>
                                <p class="detail-value">${assetData.name}</p>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label"><i class="fas fa-flag"></i>Status</span>
                                <p class="detail-value">
                                    ${assetData.status === 'active'
                                        ? '<span class="badge-soft badge-soft-success">Active</span>'
                                        : '<span class="badge-soft badge-soft-danger">Inactive</span>'}
                                </p>
                            </div>
                        </div>
                    `;

                    viewAssetModal.show();
                });
            });

            // Status Toggle Button Handling with SweetAlert
            const toggleStatusButtons = document.querySelectorAll('.toggle-asset-status-btn');

            toggleStatusButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const assetId = this.getAttribute('data-asset-id');
                    const currentStatus = this.getAttribute('data-current-status');
                    const assetName = this.getAttribute('data-asset-name');
                    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
                    const actionText = currentStatus === 'active' ? 'deactivate' : 'activate';
                    const actionColor = currentStatus === 'active' ? '#d33' : '#28a745';
                    const actionLabel = currentStatus === 'active' ? 'Deactivate' : 'Activate';

                    Swal.fire({
                        title: 'Are you sure?',
                        html: `You are about to <strong>${actionText}</strong> asset: <br><strong>${assetName}</strong>`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: actionColor,
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: `Yes, ${actionText} asset!`,
                        cancelButtonText: 'Cancel',
                        didOpen: (popup) => {
                            const confirmBtn = popup.querySelector('.swal2-confirm');
                            if (confirmBtn) {
                                confirmBtn.style.setProperty('background-color', actionColor, 'important');
                            }
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Swal.fire({
                                title: 'Processing...',
                                html: `Updating asset status to ${actionLabel}`,
                                allowOutsideClick: false,
                                didOpen: (popup) => {
                                    const confirmBtn = popup.querySelector('.swal2-confirm');
                                    if (confirmBtn) {
                                        confirmBtn.style.setProperty('background-color', actionColor, 'important');
                                    }
                                    Swal.showLoading();
                                }
                            });

                            fetch('<?= BASE_URL ?>modules/api/ajax_asset_handler.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=toggle_status&id=${assetId}&status=${newStatus}`
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    const assetRow = document.getElementById(`asset-row-${assetId}`);
                                    const statusBadge = assetRow.querySelector('.asset-status-badge');
                                    const toggleButton = assetRow.querySelector('.toggle-asset-status-btn');

                                    if (data.new_status === 'active' || newStatus === 'active') {
                                        statusBadge.classList.remove('badge-soft-danger');
                                        statusBadge.classList.add('badge-soft-success');
                                        toggleButton.classList.remove('btn-view');
                                        toggleButton.classList.add('btn-cancel');
                                        toggleButton.innerHTML = '<i class="fas fa-ban"></i>';
                                        toggleButton.setAttribute('title', 'Deactivate');
                                    } else {
                                        statusBadge.classList.remove('badge-soft-success');
                                        statusBadge.classList.add('badge-soft-danger');
                                        toggleButton.classList.remove('btn-cancel');
                                        toggleButton.classList.add('btn-view');
                                        toggleButton.innerHTML = '<i class="fas fa-check"></i>';
                                        toggleButton.setAttribute('title', 'Activate');
                                    }

                                    statusBadge.textContent = data.new_status ? data.new_status.charAt(0).toUpperCase() + data.new_status.slice(1) : (newStatus === 'active' ? 'Active' : 'Inactive');
                                    toggleButton.setAttribute('data-current-status', newStatus);

                                    showToast('success', `Asset ${assetName} has been ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully.`);
                                } else {
                                    showToast('error', data.message || 'Failed to update asset status');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showToast('error', 'An error occurred while updating asset status');
                            });
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>