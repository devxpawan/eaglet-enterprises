<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: " . BASE_URL . "signin.php");
    exit();
}



// Include the database connection file
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

$requestAction = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

if ($requestAction === 'list_positions') {
    $positionsResult = $conn->query("SELECT p.id, p.name, p.description, p.status, p.created_at, COUNT(u.id) AS user_count FROM positions p LEFT JOIN users u ON u.position_id = p.id GROUP BY p.id ORDER BY p.status ASC, p.name ASC");
    $positions = [];

    if ($positionsResult) {
        while ($position = $positionsResult->fetch_assoc()) {
            $positions[] = $position;
        }
    }

    sendJsonResponse(['success' => true, 'positions' => $positions]);
}

if ($requestAction === 'list_position_users') {
    $positionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;
    if ($positionId <= 0) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid position.']);
    }

    $stmt = $conn->prepare("SELECT u.id, u.name, u.username, u.email, u.mobile, u.status FROM users u WHERE u.position_id = ? ORDER BY u.name ASC");
    $stmt->bind_param("i", $positionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();

    sendJsonResponse(['success' => true, 'users' => $users]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($requestAction, ['add_position', 'update_position', 'toggle_position'], true)) {
    try {
        if ($requestAction === 'add_position') {
            $name = trim($_POST['position_name'] ?? '');
            $description = trim($_POST['position_description'] ?? '');

            if ($name === '') {
                sendJsonResponse(['success' => false, 'message' => 'Position name is required.']);
            }

            if (strlen($name) > 100) {
                sendJsonResponse(['success' => false, 'message' => 'Position name must be 100 characters or less.']);
            }

            $duplicateStmt = $conn->prepare("SELECT id FROM positions WHERE name = ?");
            $duplicateStmt->bind_param("s", $name);
            $duplicateStmt->execute();
            if ($duplicateStmt->get_result()->num_rows > 0) {
                $duplicateStmt->close();
                sendJsonResponse(['success' => false, 'message' => 'Position already exists.']);
            }
            $duplicateStmt->close();

            $stmt = $conn->prepare("INSERT INTO positions (name, description, status) VALUES (?, ?, 'active')");
            $stmt->bind_param("ss", $name, $description);
            $stmt->execute();
            $positionId = $conn->insert_id;
            $stmt->close();

            sendJsonResponse([
                'success' => true,
                'message' => 'Position added successfully.',
                'position' => [
                    'id' => $positionId,
                    'name' => $name,
                    'description' => $description,
                    'status' => 'active'
                ]
            ]);
        }

        if ($requestAction === 'update_position') {
            $positionId = intval($_POST['position_id'] ?? 0);
            $name = trim($_POST['position_name'] ?? '');
            $description = trim($_POST['position_description'] ?? '');

            if ($positionId <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid position.']);
            }

            if ($name === '') {
                sendJsonResponse(['success' => false, 'message' => 'Position name is required.']);
            }

            if (strlen($name) > 100) {
                sendJsonResponse(['success' => false, 'message' => 'Position name must be 100 characters or less.']);
            }

            $checkStmt = $conn->prepare("SELECT id FROM positions WHERE id = ?");
            $checkStmt->bind_param("i", $positionId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows === 0) {
                $checkStmt->close();
                sendJsonResponse(['success' => false, 'message' => 'Position not found.']);
            }
            $checkStmt->close();

            $duplicateStmt = $conn->prepare("SELECT id FROM positions WHERE name = ? AND id != ?");
            $duplicateStmt->bind_param("si", $name, $positionId);
            $duplicateStmt->execute();
            if ($duplicateStmt->get_result()->num_rows > 0) {
                $duplicateStmt->close();
                sendJsonResponse(['success' => false, 'message' => 'Position already exists.']);
            }
            $duplicateStmt->close();

            $stmt = $conn->prepare("UPDATE positions SET name = ?, description = ? WHERE id = ?");
            $stmt->bind_param("ssi", $name, $description, $positionId);
            $stmt->execute();
            $stmt->close();

            sendJsonResponse([
                'success' => true,
                'message' => 'Position updated successfully.',
                'position' => [
                    'id' => $positionId,
                    'name' => $name,
                    'description' => $description
                ]
            ]);
        }

        if ($requestAction === 'toggle_position') {
            $positionId = intval($_POST['position_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if ($positionId <= 0 || !in_array($newStatus, ['active', 'inactive'], true)) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid position status.']);
            }

            $stmt = $conn->prepare("UPDATE positions SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $newStatus, $positionId);
            $stmt->execute();
            $stmt->close();

            sendJsonResponse([
                'success' => true,
                'message' => 'Position status updated successfully.',
                'position' => [
                    'id' => $positionId,
                    'status' => $newStatus
                ]
            ]);
        }
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

        // Handle user status update via AJAX if it's a POST request
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
            $response = ['success' => false, 'message' => 'Unknown error'];

            if (isset($_POST['user_id']) && isset($_POST['new_status'])) {
                $user_id = intval($_POST['user_id']);
                $new_status = $_POST['new_status'];
                $current_user_id = $_SESSION['user_id'];

                // Prevent self-deactivation: admin cannot deactivate their own account
                if ($user_id == $current_user_id && $new_status === 'inactive') {
                    $response = [
                        'success' => false, 
                        'message' => 'You cannot deactivate your own account.'
                    ];
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit();
                }

                if (in_array($new_status, ['active', 'inactive'])) {
                    $conn->begin_transaction();

                    try {
                        $user_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                        $user_stmt->bind_param("i", $user_id);
                        $user_stmt->execute();
                        $user_result = $user_stmt->get_result();
                        $user_name = "";

                        if ($user_result && $user_result->num_rows > 0) {
                            $user_name = $user_result->fetch_assoc()['name'];
                        }
                        $user_stmt->close();

                        $update_stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
                        $update_stmt->bind_param("si", $new_status, $user_id);
                        $update_success = $update_stmt->execute();
                        $affected = $update_stmt->affected_rows;
                        $update_stmt->close();

                if ($update_success) {
                    if ($affected > 0) {
                        $action_type = ($new_status === 'active') ? 'activate_user' : 'deactivate_user';
                        $details = "User ID #$user_id ($user_name) was " .
                                   ($new_status === 'active' ? 'activated' : 'deactivated') .
                                   " by user ID #$current_user_id";

                        $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, '0', ?)");
                        $log_stmt->bind_param("iss", $current_user_id, $action_type, $details);
                        $log_success = $log_stmt->execute();
                        $log_stmt->close();

                        if (!$log_success) {
                            $conn->rollback();
                            $response = [
                                'success' => false, 
                                'message' => "Error logging status change: " . $conn->error
                            ];
                            header('Content-Type: application/json');
                            echo json_encode($response);
                            exit();
                        }
                    }

                    $conn->commit();
                    
                    $response = [
                        'success' => true, 
                        'message' => "User status updated to $new_status successfully",
                        'new_status' => $new_status
                    ];
                } else {
                    // Rollback if update failed
                    $conn->rollback();
                    $response = [
                        'success' => false, 
                        'message' => "Error updating status: " . $conn->error
                    ];
                }
            } catch (Exception $e) {
                // Rollback on any exception
                $conn->rollback();
                $response = [
                    'success' => false, 
                    'message' => "Transaction failed: " . $e->getMessage()
                ];
            }
        } else {
            $response = [
                'success' => false, 
                'message' => "Invalid status value"
            ];
        }
    } else {
        $response = [
            'success' => false, 
            'message' => "Missing required parameters"
        ];
    }

    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Initialize filter parameters
$filter_name = isset($_GET['filter_name']) ? trim($_GET['filter_name']) : '';
$filter_email = isset($_GET['filter_email']) ? trim($_GET['filter_email']) : '';
$filter_mobile = isset($_GET['filter_mobile']) ? trim($_GET['filter_mobile']) : '';
$filter_position = isset($_GET['filter_position']) ? (int)$_GET['filter_position'] : 0;
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$conditions = [];
if (!empty($filter_name)) {
    $s = $conn->real_escape_string($filter_name);
    $conditions[] = "u.name LIKE '%$s%'";
}
if (!empty($filter_email)) {
    $s = $conn->real_escape_string($filter_email);
    $conditions[] = "u.email LIKE '%$s%'";
}
if (!empty($filter_mobile)) {
    $s = $conn->real_escape_string($filter_mobile);
    $conditions[] = "u.mobile LIKE '%$s%'";
}
if ($filter_position > 0) {
    $conditions[] = "u.position_id = $filter_position";
}
if ($filter_status !== '') {
    $s = $conn->real_escape_string($filter_status);
    $conditions[] = "u.status = '$s'";
}

$whereConditions = '';
if (!empty($conditions)) {
    $whereConditions = ' AND ' . implode(' AND ', $conditions);
}

// Fetch positions for filter dropdowns
$positionsResult = $conn->query("SELECT id, name FROM positions ORDER BY name");

// Build base query parts
$baseFrom = "FROM users u LEFT JOIN positions p ON u.position_id = p.id";
$selectCols = "u.*, p.name AS position_name";

$baseWhere = "1=1";

$whereClause = $baseWhere . $whereConditions;

$countQuery = "SELECT COUNT(*) as total $baseFrom WHERE $whereClause";
$sql = "SELECT $selectCols $baseFrom WHERE $whereClause ORDER BY u.id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countQuery);
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
    <title>All Users</title>
    <link href="<?= BASE_URL ?>css/users-list.css" rel="stylesheet" />
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
                        <h5>Users List</h5>
                        <p class="text-muted">Manage and review all system users</p>
                    </div>
                    <button type="button" class="btn btn-primary btn-manage-positions" id="openPositionManagementModal">
                        <i class="fas fa-briefcase me-1"></i> Manage Positions
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
                                            <input type="text" name="filter_name" class="form-control" placeholder="User Name"
                                                value="<?= htmlspecialchars($filter_name) ?>">
                                        </div>
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Email</label>
                                            <input type="text" name="filter_email" class="form-control" placeholder="Email"
                                                value="<?= htmlspecialchars($filter_email) ?>">
                                        </div>
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Phone</label>
                                            <input type="text" name="filter_mobile" class="form-control" placeholder="Phone Number"
                                                value="<?= htmlspecialchars($filter_mobile) ?>">
                                        </div>
                                        <div class="col-md-2 col-lg-1">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Position</label>
                                            <select name="filter_position" class="form-select">
                                                <option value="0">All</option>
                                                <?php if ($positionsResult): while ($position = $positionsResult->fetch_assoc()): ?>
                                                    <option value="<?= $position['id'] ?>" <?= $filter_position == $position['id'] ? 'selected' : '' ?>><?= htmlspecialchars($position['name']) ?></option>
                                                <?php endwhile; endif; ?>
                                            </select>
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
                                            <a href="<?= BASE_URL ?>modules/users/users.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                            </div>

                            <div class="d-flex justify-content-start mt-2 mb-2">
                                <span class="search-count"><?php echo $totalRows; ?> User<?= $totalRows !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-users" id="users_table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>User ID</th>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Contact</th>
                                            <th>Position</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($result && $result->num_rows > 0): ?>
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                                <tr id="user-row-<?= $row['id'] ?>">
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($row['id']) ?></span>
                                                        <br>
                                                    </td>
                                                    <td>
                                                        <div class="user-name"><?= htmlspecialchars($row['name']) ?></div>
                                                    </td>
                                                    <td><?= htmlspecialchars($row['username']) ?></td>
                                                    <td>
                                                        <div><i class="fas fa-phone me-1 text-muted"></i><?= isset($row['mobile']) ? htmlspecialchars($row['mobile']) : '-' ?></div>
                                                        <?php if (!empty($row['email'])): ?>
                                                            <div class="text-muted" style="font-size: 0.82rem;"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($row['email']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                     <td>
                                                        <?php if (!empty($row['position_name'])): ?>
                                                            <span class="badge badge-soft badge-soft-info"><?= htmlspecialchars($row['position_name']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['status'] == 'active'): ?>
                                                            <span class="user-status-badge badge-soft badge-soft-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="user-status-badge badge-soft badge-soft-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="action-btn-group d-flex gap-1">
                                                            <?php if (hasAccess('users.add')): ?>
                                                            <a href="<?= BASE_URL ?>modules/users/edit_user.php?id=<?= htmlspecialchars($row['id']) ?>&name=<?= urlencode($row['name']) ?>&username=<?= urlencode($row['username']) ?>&email=<?= urlencode($row['email']) ?>&status=<?= htmlspecialchars($row['status']) ?>"
                                                                class="btn btn-edit"
                                                                title="Edit User">
                                                                <i class="fas fa-pen"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (hasAccess('users.permissions')): ?>
                                                            <a href="<?= BASE_URL ?>modules/users/edit_permissions.php?id=<?= $row['id'] ?>"
                                                                class="btn btn-edit"
                                                                title="Manage Permissions"
                                                                style="color: #6366f1; border-color: rgba(99, 102, 241, 0.2); background: rgba(99, 102, 241, 0.05);">
                                                                <i class="fas fa-shield-alt"></i>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (hasAccess('users')): ?>
                                                            <button class="btn btn-view view-user-btn"
                                                                title="View Details"
                                                                data-user-id="<?= $row['id'] ?>"
                                                                data-user-name="<?= htmlspecialchars($row['name']) ?>"
                                                                data-user-username="<?= htmlspecialchars($row['username']) ?>"
                                                                data-user-email="<?= htmlspecialchars($row['email']) ?>"
                                                                data-user-mobile="<?= isset($row['mobile']) ? htmlspecialchars($row['mobile']) : '-' ?>"
                                                                data-user-nic="<?= isset($row['nic']) ? htmlspecialchars($row['nic']) : '-' ?>"
                                                                data-user-status="<?= htmlspecialchars($row['status']) ?>"
                                                                 data-user-position="<?= isset($row['position_name']) ? htmlspecialchars($row['position_name']) : '-' ?>"
                                                                data-user-created="<?= htmlspecialchars($row['created_at']) ?>">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            <?php if (hasAccess('users.add')): ?>
                                                            <button class="btn <?= $row['status'] == 'active' ? 'btn-deactivate' : 'btn-activate' ?> toggle-status-btn"
                                                                title="<?= $row['status'] == 'active' ? 'Deactivate' : 'Activate' ?>"
                                                                data-user-id="<?= $row['id'] ?>"
                                                                data-current-status="<?= $row['status'] ?>"
                                                                data-user-name="<?= htmlspecialchars($row['name']) ?>">
                                                                <i class="fas <?= $row['status'] == 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                             <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="empty-state">
                                                        <i class="fas fa-users"></i>
                                                        <p>No users found</p>
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

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1" aria-labelledby="viewUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-system">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewUserModalLabel"><i class="fas fa-user-circle me-2"></i>User Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewUserModalBody">
                    <!-- Dynamic content will be inserted here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Position Management Modal -->
    <div class="modal fade" id="positionManagementModal" tabindex="-1" aria-labelledby="positionManagementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="positionManagementModalLabel"><i class="fas fa-briefcase me-2"></i>Position Management</h5>
                        <p class="text-muted small mb-0">Add, edit, activate, or deactivate user positions.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="positionForm" class="mb-4">
                        <input type="hidden" id="positionId" name="position_id" value="">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label for="positionName" class="form-label">Position Name</label>
                                <input type="text" id="positionName" name="position_name" class="form-control" maxlength="100" placeholder="Position Name" required>
                            </div>
                            <div class="col-md-5">
                                <label for="positionDescription" class="form-label">Description</label>
                                <input type="text" id="positionDescription" name="position_description" class="form-control" placeholder="Description">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill" id="savePositionBtn">
                                    <i class="fas fa-plus me-1"></i> Add
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="cancelPositionFormBtn">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-text position-form-status" id="positionFormStatus">Add a new position.</div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-users position-modal-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Position</th>
                                    <th>Description</th>
                                    <th>Users</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="positionsTableBody">
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {



        // View User Modal Handling
        const viewButtons = document.querySelectorAll('.view-user-btn');
        const viewUserModal = new bootstrap.Modal(document.getElementById('viewUserModal'));
        const viewUserModalBody = document.getElementById('viewUserModalBody');

        viewButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const userData = {
                    name: this.getAttribute('data-user-name'),
                    username: this.getAttribute('data-user-username'),
                    email: this.getAttribute('data-user-email'),
                    mobile: this.getAttribute('data-user-mobile'),
                    nic: this.getAttribute('data-user-nic'),
                    status: this.getAttribute('data-user-status'),
                    position: this.getAttribute('data-user-position'),
                    created: this.getAttribute('data-user-created')
                };

                viewUserModalBody.innerHTML = `
                    <div class="detail-grid">
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-user"></i>Name</span>
                            <p class="detail-value">${userData.name}</p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-user-tag"></i>Username</span>
                            <p class="detail-value">${userData.username}</p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-envelope"></i>Email</span>
                            <p class="detail-value">${userData.email}</p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-phone"></i>Mobile</span>
                            <p class="detail-value">${userData.mobile}</p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-id-card"></i>NIC</span>
                            <p class="detail-value">${userData.nic}</p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-flag"></i>Status</span>
                            <p class="detail-value">
                                ${userData.status === 'active' 
                                    ? '<span class="badge-soft badge-soft-success">Active</span>' 
                                    : '<span class="badge-soft badge-soft-danger">Inactive</span>'}
                            </p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label"><i class="fas fa-briefcase"></i>Position</span>
                            <p class="detail-value">${userData.position}</p>
                        </div>
                        <div class="detail-card full-width">
                            <span class="detail-label"><i class="fas fa-calendar"></i>Created At</span>
                            <p class="detail-value">${userData.created}</p>
                        </div>
                    </div>
                `;

                viewUserModal.show();
            });
        });

        const positionModal = new bootstrap.Modal(document.getElementById('positionManagementModal'));
        const positionForm = document.getElementById('positionForm');
        const positionsTableBody = document.getElementById('positionsTableBody');
        const positionName = document.getElementById('positionName');
        const positionDescription = document.getElementById('positionDescription');
        const positionId = document.getElementById('positionId');
        const savePositionBtn = document.getElementById('savePositionBtn');
        const positionFormStatus = document.getElementById('positionFormStatus');

        function resetPositionForm() {
            positionForm.reset();
            positionId.value = '';
            savePositionBtn.innerHTML = '<i class="fas fa-plus me-1"></i> Add';
            positionFormStatus.textContent = 'Add a new position.';
        }

        function showPositionLoading() {
            positionsTableBody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </td>
                </tr>
            `;
        }

        function showPositionMessage(message, isError = false) {
            positionsTableBody.innerHTML = '';

            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = `text-center py-4 ${isError ? 'text-danger' : 'text-muted'}`;
            cell.textContent = message;
            row.appendChild(cell);
            positionsTableBody.appendChild(row);
        }

        function updatePositionFilterOptions(positions) {
            const filterSelect = document.querySelector('select[name="filter_position"]');
            if (!filterSelect) return;

            const currentValue = filterSelect.value;
            filterSelect.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = 'All';
            filterSelect.appendChild(allOption);

            positions.forEach(position => {
                const option = document.createElement('option');
                option.value = position.id;
                option.textContent = position.name;
                filterSelect.appendChild(option);
            });

            if (Array.from(filterSelect.options).some(option => option.value === currentValue)) {
                filterSelect.value = currentValue;
            }
        }

        async function fetchPositions() {
            showPositionLoading();

            try {
                const response = await fetch('<?= BASE_URL ?>modules/users/users.php?action=list_positions', {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Failed to load positions.');
                }

                renderPositions(data.positions || []);
                updatePositionFilterOptions(data.positions || []);
            } catch (error) {
                showPositionMessage(error.message || 'Failed to load positions.', true);
                showToast('error', error.message || 'Failed to load positions.');
            }
        }

        function renderPositions(positions) {
            positionsTableBody.innerHTML = '';

            if (!positions.length) {
                showPositionMessage('No positions found.');
                return;
            }

            positions.forEach(position => {
                const row = document.createElement('tr');

                const nameCell = document.createElement('td');
                const nameText = document.createElement('strong');
                nameText.textContent = position.name;
                nameCell.appendChild(nameText);

                const descriptionCell = document.createElement('td');
                descriptionCell.textContent = position.description || '—';

                const usersCell = document.createElement('td');
                const usersCount = document.createElement('span');
                usersCount.className = 'fw-semibold';
                usersCount.textContent = position.user_count || 0;
                usersCell.appendChild(usersCount);

                const statusCell = document.createElement('td');
                const statusBadge = document.createElement('span');
                statusBadge.className = `badge-soft ${position.status === 'active' ? 'badge-soft-success' : 'badge-soft-danger'}`;
                statusBadge.textContent = position.status === 'active' ? 'Active' : 'Inactive';
                statusCell.appendChild(statusBadge);

                const actionCell = document.createElement('td');
                actionCell.className = 'position-actions';

                const editButton = document.createElement('button');
                editButton.type = 'button';
                editButton.className = 'btn btn-edit btn-sm';
                editButton.title = 'Edit Position';
                editButton.innerHTML = '<i class="fas fa-pen"></i>';
                editButton.addEventListener('click', () => {
                    positionId.value = position.id;
                    positionName.value = position.name;
                    positionDescription.value = position.description || '';
                    savePositionBtn.innerHTML = '<i class="fas fa-save me-1"></i> Update';
                    positionFormStatus.textContent = 'Editing position.';
                    positionName.focus();
                });

                const toggleButton = document.createElement('button');
                const nextStatus = position.status === 'active' ? 'inactive' : 'active';
                toggleButton.type = 'button';
                toggleButton.className = `btn ${nextStatus === 'active' ? 'btn-activate' : 'btn-deactivate'} btn-sm`;
                toggleButton.title = nextStatus === 'active' ? 'Activate Position' : 'Deactivate Position';
                toggleButton.innerHTML = `<i class="fas ${nextStatus === 'active' ? 'fa-check' : 'fa-ban'}"></i>`;
                toggleButton.addEventListener('click', () => {
                    const actionText = nextStatus === 'active' ? 'activate' : 'deactivate';
                    Swal.fire({
                        title: `${nextStatus === 'active' ? 'Activate' : 'Deactivate'} position?`,
                        html: `You are about to <strong>${actionText}</strong> <strong>${position.name}</strong>.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: nextStatus === 'active' ? '#079455' : '#d33',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: `Yes, ${actionText} position`,
                        cancelButtonText: 'Cancel'
                    }).then(async (result) => {
                        if (!result.isConfirmed) return;

                        Swal.fire({
                            title: 'Processing...',
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });

                        try {
                            const data = await submitPositionForm('toggle_position', {
                                position_id: position.id,
                                new_status: nextStatus
                            });
                            // Close the processing Swal before showing the toast
                            Swal.close();
                            await fetchPositions();
                            showToast('success', data.message);
                        } catch (error) {
                            showToast('error', error.message || 'Failed to update position status.');
                        }
                    });
                });

                actionCell.append(editButton, toggleButton);
                row.append(nameCell, descriptionCell, usersCell, statusCell, actionCell);
                positionsTableBody.appendChild(row);
            });
        }

        async function submitPositionForm(action, payload) {
            const formData = new FormData();
            formData.append('action', action);

            Object.keys(payload).forEach(key => {
                formData.append(key, payload[key]);
            });

            const response = await fetch('<?= BASE_URL ?>modules/users/users.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to save position.');
            }

            return data;
        }

        document.getElementById('openPositionManagementModal').addEventListener('click', () => {
            resetPositionForm();
            positionModal.show();
            fetchPositions();
        });

        document.getElementById('positionManagementModal').addEventListener('hidden.bs.modal', resetPositionForm);

        document.getElementById('cancelPositionFormBtn').addEventListener('click', resetPositionForm);

        positionForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!positionName.value.trim()) {
                showToast('warning', 'Position name is required.');
                positionName.focus();
                return;
            }

            const action = positionId.value ? 'update_position' : 'add_position';
            const payload = {
                position_name: positionName.value.trim(),
                position_description: positionDescription.value.trim()
            };

            if (positionId.value) {
                payload.position_id = positionId.value;
            }

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                const data = await submitPositionForm(action, payload);
                // Close the processing Swal before showing the toast
                Swal.close();
                resetPositionForm();
                await fetchPositions();
                showToast('success', data.message);
            } catch (error) {
                showToast('error', error.message || 'Failed to save position.');
            }
        });

        // Status Toggle Handling
        const toggleStatusButtons = document.querySelectorAll('.toggle-status-btn');
        const currentUserId = <?php echo $_SESSION['user_id']; ?>;

        toggleStatusButtons.forEach(btn => {
            const userId = parseInt(btn.getAttribute('data-user-id'));
            const currentStatus = btn.getAttribute('data-current-status');
            
            // Hide the toggle button for the current user (self) to prevent self-deactivation
            if (userId === currentUserId && currentStatus === 'active') {
                btn.style.display = 'none';
                return;
            }

            btn.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const currentStatus = this.getAttribute('data-current-status');
                const userName = this.getAttribute('data-user-name');
                const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
                const actionText = currentStatus === 'active' ? 'deactivate' : 'activate';
                const actionColor = currentStatus === 'active' ? '#d33' : '#28a745';
                
                // SweetAlert confirmation before status change
                Swal.fire({
                    title: `Are you sure?`,
                    html: `You are about to <strong>${actionText}</strong> user: <br><strong>${userName}</strong>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: actionColor,
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: `Yes, ${actionText} user!`,
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading state
                        Swal.fire({
                            title: 'Processing...',
                            html: `Updating user status to ${newStatus}`,
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        // AJAX call to update status
                        fetch('<?= BASE_URL ?>modules/users/users.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=update_status&user_id=${userId}&new_status=${newStatus}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            // Close the processing Swal first
                            Swal.close();

                            if (data.success) {
                                // Update button and badge
                                const userRow = document.getElementById(`user-row-${userId}`);
                                const statusBadge = userRow.querySelector('.user-status-badge');
                                const toggleButton = userRow.querySelector('.toggle-status-btn');

                                if (newStatus === 'active') {
                                    statusBadge.classList.remove('badge-soft-danger');
                                    statusBadge.classList.add('badge-soft-success');
                                    statusBadge.textContent = 'Active';
                                    toggleButton.classList.remove('btn-activate');
                                    toggleButton.classList.add('btn-deactivate');
                                    toggleButton.innerHTML = '<i class="fas fa-ban"></i>';
                                    toggleButton.setAttribute('title', 'Deactivate');
                                } else {
                                    statusBadge.classList.remove('badge-soft-success');
                                    statusBadge.classList.add('badge-soft-danger');
                                    statusBadge.textContent = 'Inactive';
                                    toggleButton.classList.remove('btn-deactivate');
                                    toggleButton.classList.add('btn-activate');
                                    toggleButton.innerHTML = '<i class="fas fa-check"></i>';
                                    toggleButton.setAttribute('title', 'Activate');
                                }
                                toggleButton.setAttribute('data-current-status', newStatus);

                                // Show success message
                                showToast('success', `User ${userName} has been ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully.`);
                            } else {
                                // Show error message
                                showToast('error', data.message || 'Failed to update user status');
                            }
                        })
                        .catch(error => {
                            // Close the processing Swal first
                            Swal.close();
                            console.error('Error:', error);
                            showToast('error', 'An error occurred while updating user status');
                        });
                    }
                });
            });
        });
    });
    </script>
</body>
</html>

<?php
$conn->close();
?>