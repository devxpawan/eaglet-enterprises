<?php
require_once __DIR__ . '/../config/paths.php';

function showAlert($message, $type = 'success') {
    $toastType = $type === 'danger' ? 'error' : $type;
    echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast('" . addslashes($toastType) . "', '" . addslashes($message) . "'); });</script>";
}

function sanitizeInput($data) {
    return htmlspecialchars(trim($data));
}

// RBAC Helper Functions
function getUserRoleId() {
    return isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
}

function isAdmin() {
    return getUserRoleId() === 1;
}

function isModerator() {
    return getUserRoleId() === 3;
}

function isUser() {
    return getUserRoleId() === 2;
}

function canManageUsers() {
    return isAdmin();
}

function canManageSettings() {
    return isAdmin();
}

function canViewLogs() {
    return isAdmin();
}

function canApproveRejectInquiries() {
    return isAdmin() || isModerator();
}

function canEditRecords() {
    return isAdmin() || isModerator();
}

function canDeleteRecords() {
    return isAdmin();
}

function canAddCustomers() {
    return isAdmin() || isModerator();
}

function canAddProducts() {
    return isAdmin() || isModerator();
}

function canEditProducts() {
    return isAdmin() || isModerator();
}

function canCreateInvoices() {
    return true; // All roles can create invoices
}

function canCancelInvoices() {
    return isAdmin() || isModerator();
}

// Redirect if user lacks required role, with optional error message
function requireRole($allowedRoles, $redirectPage = 'index.php') {
    if (!in_array(getUserRoleId(), $allowedRoles)) {
        header("Location: $redirectPage");
        exit();
    }
}

// Deny access if not Admin
function requireAdmin($redirectPage = 'index.php') {
    if (!isAdmin()) {
        header("Location: $redirectPage");
        exit();
    }
}

// Load company settings (single-row). Returns array; empty fields stay empty.
function getCompanyInfo($conn) {
    $defaults = [
        'company_name'   => '',
        'address'        => '',
        'phone'          => '',
        'email'          => '',
        'currency'       => 'LKR',
        'logo_path'      => '',
        'favicon_path'   => '',
        'bank_name'      => '',
        'bank_branch'    => '',
        'account_name'   => '',
        'account_number' => '',
        'account_type'   => '',
    ];
    if (!$conn || !($conn instanceof mysqli) || $conn->connect_error) {
        return $defaults;
    }
    $r = @$conn->query("SELECT company_name, address, phone, email, logo_path, favicon_path, bank_name, bank_branch, account_name, account_number, account_type FROM company_settings WHERE id = 1");
    if ($r && $row = $r->fetch_assoc()) {
        return array_merge($defaults, array_map(function($v){ return $v ?? ''; }, $row));
    }
    return $defaults;
}

// Build a query string from $_GET params, excluding specified keys and empty values
function buildQueryString($exclude = [], $extra = []) {
    $parts = [];
    foreach ($_GET as $key => $value) {
        if (!in_array($key, $exclude) && $value !== '' && $value !== null) {
            $parts[] = urlencode($key) . '=' . urlencode($value);
        }
    }
    foreach ($extra as $key => $value) {
        if ($value !== '' && $value !== null) {
            $parts[] = urlencode($key) . '=' . urlencode($value);
        }
    }
    return implode('&', $parts);
}

// Render pagination HTML
function renderPagination($page, $totalPages, $search = '') {
    if ($totalPages <= 1) return '';
    $html = '<nav aria-label="Page navigation"><ul class="pagination mb-0">';
    
    // Previous
    $prevDisabled = $page <= 1 ? 'disabled' : '';
    $html .= '<li class="page-item ' . $prevDisabled . '">
        <a class="page-link" href="?' . buildQueryString(['page'], ['page' => $page - 1]) . '">
            <i class="fas fa-chevron-left"></i>
        </a>
    </li>';
    
    $maxPagesToShow = 5;
    $startPage = max(1, min($page - floor($maxPagesToShow / 2), $totalPages - $maxPagesToShow + 1));
    $endPage = min($totalPages, $startPage + $maxPagesToShow - 1);
    
    if ($startPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?' . buildQueryString(['page'], ['page' => 1]) . '">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $active = $page == $i ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '">
            <a class="page-link" href="?' . buildQueryString(['page'], ['page' => $i]) . '">' . $i . '</a>
        </li>';
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="?' . buildQueryString(['page'], ['page' => $totalPages]) . '">' . $totalPages . '</a></li>';
    }
    
    // Next
    $nextDisabled = $page >= $totalPages ? 'disabled' : '';
    $html .= '<li class="page-item ' . $nextDisabled . '">
        <a class="page-link" href="?' . buildQueryString(['page'], ['page' => $page + 1]) . '">
            <i class="fas fa-chevron-right"></i>
        </a>
    </li>';
    
    $html .= '</ul></nav>';
    return $html;
}

// Generate a reference number based on company initials, type, year, and id
// Example: ABC/QT/26/001
function generateRefNo($conn, $id, $date, $type = 'QT') {
    $company = getCompanyInfo($conn);
    $words = explode(' ', preg_replace('/[^a-zA-Z0-9 ]/', '', $company['company_name']));
    $initials = '';
    foreach ($words as $w) {
        if (!empty($w) && strtolower($w) !== 'and' && strtolower($w) !== 'pvt' && strtolower($w) !== 'ltd') {
            $initials .= strtoupper($w[0]);
        }
    }
    if (empty($initials)) {
        $initials = strtoupper($type);
    }
    return $initials . '/' . strtoupper($type) . '/' . date('y', strtotime($date)) . '/' . str_pad(intval($id), 3, '0', STR_PAD_LEFT);
}
?>
