<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
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

// Initialize filter parameters
$filter_user = isset($_GET['filter_user']) ? trim($_GET['filter_user']) : '';
$filter_action_type = isset($_GET['filter_action_type']) ? trim($_GET['filter_action_type']) : '';
$filter_from_date = isset($_GET['filter_from_date']) ? trim($_GET['filter_from_date']) : '';
$filter_to_date = isset($_GET['filter_to_date']) ? trim($_GET['filter_to_date']) : '';
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$conditions = [];
if (!empty($filter_user)) {
    $s = (int) $filter_user;
    $conditions[] = "user_logs.user_id = $s";
}
if ($filter_action_type !== '') {
    $s = $conn->real_escape_string($filter_action_type);
    $conditions[] = "user_logs.action_type = '$s'";
}
if (!empty($filter_from_date)) {
    $d = $conn->real_escape_string($filter_from_date);
    $conditions[] = "user_logs.created_at >= '$d'";
}
if (!empty($filter_to_date)) {
    $d = $conn->real_escape_string($filter_to_date);
    $conditions[] = "user_logs.created_at <= '$d 23:59:59'";
}
$whereClause = '';
if (!empty($conditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $conditions);
}

// Fetch distinct action types for filter dropdown
$actionTypesResult = $conn->query("SELECT DISTINCT action_type FROM user_logs ORDER BY action_type");

// Fetch all users for the user filter dropdown
$usersResult = $conn->query("SELECT id, name FROM users ORDER BY name");

// Build SQL queries
$countSql = "SELECT COUNT(*) as total FROM user_logs LEFT JOIN users ON user_logs.user_id = users.id$whereClause";
$sql = "SELECT user_logs.*, users.name as user_name FROM user_logs 
        LEFT JOIN users ON user_logs.user_id = users.id$whereClause 
        ORDER BY user_logs.created_at DESC LIMIT $limit OFFSET $offset";

// Execute the queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);

$result = $conn->query($sql);

// Enhanced function to format details text with changes shown directly
function formatDetailsText($details, $userId, $userName, $actionType) {
    // Replace user ID with user name and ID
    $userInfo = !empty($userName) ? "$userName($userId)" : "ID #$userId";
    
    // For edit actions, show the changes directly as bullet points
    if (strpos($actionType, 'edit_') !== false) {
        // Check if the details mention changes
        if (strpos($details, 'Changes:') !== false) {
            // Split the text at "Changes:" to separate the action from the changes
            $parts = explode('Changes:', $details, 2);
            $actionPart = trim($parts[0]);
            $changesPart = isset($parts[1]) ? trim($parts[1]) : '';
            
            // Replace "by user ID #X" with "by user Name(ID)"
            $actionPart = preg_replace("/by user ID #(\d+)/i", "by user $userInfo", $actionPart);
            
            $output = $actionPart . ' ';
            
            // Format the changes as bullet points directly
            if (!empty($changesPart)) {
                // Split changes by semicolon or comma
                $changes = preg_split('/[;,]\s*/', $changesPart);
                
                $changesList = "<ul class='mb-0 ps-3 mt-1'>";
                foreach ($changes as $change) {
                    $change = trim($change);
                    if (!empty($change)) {
                        $changesList .= "<li>" . htmlspecialchars($change) . "</li>";
                    }
                }
                $changesList .= "</ul>";
                
                $output .= $changesList;
                
                return $output;
            }
        }
    }
    
    // For other action types, just replace the user ID with name
    return preg_replace("/by user ID #(\d+)/i", "by user $userInfo", $details);
}

// Function to check if the details text contains multiple changes
function hasMultipleChanges($details) {
    // Count the number of semicolons which separate changes
    return substr_count($details, ';') >= 1;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>User Activity Logs</title>
    <link href="<?= BASE_URL ?>css/users-list.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= BASE_URL ?>css/forms.css">
    <style>
        .details-cell {
            max-width: 300px;
        }
        .details-cell ul {
            margin-top: 5px;
            padding-left: 18px;
        }
        .details-cell li {
            text-align: left;
            font-size: 12px;
            line-height: 1.4;
            margin-bottom: 2px;
        }
        .action-type-text {
            min-width: 100px;
            display: inline-block;
        }
        .table-users {
            table-layout: fixed;
        }
        .table-users th:nth-child(1),
        .table-users td:nth-child(1) {
            width: 80px;
        }
        .table-users th:nth-child(2),
        .table-users td:nth-child(2) {
            width: 120px;
        }
        .table-users th:nth-child(3),
        .table-users td:nth-child(3) {
            width: 110px;
        }
        .table-users th:nth-child(4),
        .table-users td:nth-child(4) {
            width: 300px;
        }
        .table-users th:nth-child(5),
        .table-users td:nth-child(5) {
            width: 120px;
        }
    </style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5>User Activity Logs</h5>
                        <p class="text-muted">Track all user activities within the system</p>
                    </div>
                </div>
                    <div class="card invoice-card mb-4">
                        <div class="card-body">
                            <!-- Filter Bar -->
                            <div class="invoice-filter-bar">
                                <form method="get" id="filterForm">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">User</label>
                                            <select name="filter_user" class="form-select">
                                                <option value="">All Users</option>
                                                <?php if ($usersResult): while ($u = $usersResult->fetch_assoc()): ?>
                                                    <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                                <?php endwhile; endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3 col-lg-2">
                                            <label class="form-label mb-1" style="font-size:11px;font-weight:600;color:#667085;">Action Type</label>
                                            <select name="filter_action_type" class="form-select">
                                                <option value="">All</option>
                                                <?php if ($actionTypesResult): while ($at = $actionTypesResult->fetch_assoc()): ?>
                                                    <option value="<?= htmlspecialchars($at['action_type']) ?>" <?= $filter_action_type === $at['action_type'] ? 'selected' : '' ?>><?= htmlspecialchars($at['action_type']) ?></option>
                                                <?php endwhile; endif; ?>
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
                                            <a href="<?= BASE_URL ?>modules/users/user_logs.php" class="btn btn-outline-secondary btn-clear">
                                                <i class="fas fa-times me-1"></i> Clear
                                            </a>
                                        </div>
                                    </div>
                                    <input type="hidden" name="page" value="1">
                                </form>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <div>
                                        <?php if (!empty($filter_user) || $filter_action_type !== '' || !empty($filter_from_date) || !empty($filter_to_date)): ?>
                                            <span class="search-count"><?php echo $totalRows; ?> User Activity <?= $totalRows !== 1 ? 's' : '' ?> found</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-users">
                                    <thead>
                                        <tr>
                                            <th>Action ID</th>
                                            <th>User</th>
                                            <th>Action Type</th>
                                            <th>Details</th>
                                            <th>Action Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($result && $result->num_rows > 0): ?>
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <span class="user-id-text"><?php echo htmlspecialchars($row['id']); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="user-name"><?php echo !empty($row['user_name']) ? htmlspecialchars($row['user_name']) : '-'; ?></div>
                                                        <div class="user-role">ID: <?php echo htmlspecialchars($row['user_id']); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $action = strtolower($row['action_type']);
                                                        if (strpos($action, 'create') !== false || strpos($action, 'add') !== false || strpos($action, 'approve') !== false || strpos($action, 'confirm') !== false || strpos($action, 'sent') !== false) {
                                                            $badgeClass = 'badge-soft-success';
                                                        } elseif (strpos($action, 'delete') !== false || strpos($action, 'cancel') !== false || strpos($action, 'reject') !== false || strpos($action, 'decline') !== false || strpos($action, 'deactivate') !== false) {
                                                            $badgeClass = 'badge-soft-danger';
                                                        } elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'change') !== false) {
                                                            $badgeClass = 'badge-soft-warning';
                                                        } else {
                                                            $badgeClass = 'badge-soft-info';
                                                        }
                                                        ?>
                                                        <span class="badge-soft <?php echo $badgeClass; ?>">
                                                            <?php echo htmlspecialchars($row['action_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="details-cell">
                                                        <?php 
                                                        $formattedDetails = formatDetailsText(
                                                            $row['details'], 
                                                            $row['user_id'], 
                                                            isset($row['user_name']) ? $row['user_name'] : '',
                                                            $row['action_type']
                                                        );
                                                        echo $formattedDetails;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="user-name"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                                        <div class="user-role"><?php echo date('h:i:s A', strtotime($row['created_at'])); ?></div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-5">
                                                    <div style="color: #a0aec0;">
                                                        <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                                        No user logs found
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
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
                                <?= renderPagination($page, $totalPages) ?>
                            </div>

                        </div>
                    </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
    // No additional scripts needed
    </script>
</body>

</html>