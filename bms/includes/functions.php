<?php
require_once __DIR__ . '/../config/paths.php';

// Permission check helper
function hasAccess($permission) {
    $access = $_SESSION['user_access'] ?? [];
    return in_array($permission, $access, true);
}

// Approval helper
function isApprover() {
    return isset($_SESSION['is_approver']) && $_SESSION['is_approver'] === true;
}

function hasApprovedEditRequest($conn, $invoice_id, $user_id) {
    $stmt = $conn->prepare("SELECT id FROM invoice_edit_requests WHERE invoice_id = ? AND requester_id = ? AND status = 'approved' LIMIT 1");
    $stmt->bind_param("ii", $invoice_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

function hasPendingEditRequest($conn, $invoice_id, $user_id) {
    $stmt = $conn->prepare("SELECT id FROM invoice_edit_requests WHERE invoice_id = ? AND requester_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("ii", $invoice_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

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

function renderPagination($page, $totalPages) {
    if ($totalPages <= 1) return '';
    $html = '<nav aria-label="Page navigation"><ul class="pagination mb-0">';
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
    $nextDisabled = $page >= $totalPages ? 'disabled' : '';
    $html .= '<li class="page-item ' . $nextDisabled . '">
        <a class="page-link" href="?' . buildQueryString(['page'], ['page' => $page + 1]) . '">
            <i class="fas fa-chevron-right"></i>
        </a>
    </li>';
    $html .= '</ul></nav>';
    return $html;
}

// --- Quotation Revision Functions ---

function generateRevisedRefNo($original_ref_no, $revision_no) {
    return $original_ref_no . '-R' . $revision_no;
}

function getNextRevisionNo($conn, $original_ref_no) {
    if (empty($original_ref_no)) return 1;
    $sql = "SELECT MAX(revision_no) as max_rev FROM quotations WHERE original_ref_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $original_ref_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return ($row['max_rev'] ?? 0) + 1;
}

function getQuotationRevisionChain($conn, $quotation_id) {
    $sql = "SELECT original_ref_no, ref_no FROM quotations WHERE quotation_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $quotation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if (!$row) return [];
    $original_ref_no = $row['original_ref_no'] ?? $row['ref_no'];
    if (empty($original_ref_no)) return [];
    $chainSql = "SELECT q.*, c.name as customer_name, c.business_name as customer_business_name,
                 u.name as creator_name
                 FROM quotations q
                 LEFT JOIN customers c ON q.customer_id = c.customer_id
                 LEFT JOIN users u ON q.created_by = u.id
                 WHERE (q.original_ref_no = ? OR (q.ref_no = ? AND q.original_ref_no IS NULL))
                 ORDER BY q.revision_no ASC";
    $stmt = $conn->prepare($chainSql);
    $stmt->bind_param("ss", $original_ref_no, $original_ref_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $chain = [];
    while ($r = $result->fetch_assoc()) {
        $chain[] = $r;
    }
    $stmt->close();
    return $chain;
}

// --- End Quotation Revision Functions ---

function generateRefNo($conn, $id, $date, $type = 'QT') {
    $company = getCompanyInfo($conn);
    $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $company['company_name']);
    $prefix = strtoupper(substr($cleanName, 0, 3));
    if (empty($prefix)) {
        $prefix = strtoupper($type);
    }
    return $prefix . '/' . strtoupper($type) . '/J' . date('y', strtotime($date)) . '/' . str_pad(intval($id), 3, '0', STR_PAD_LEFT);
}

function getNextAutoIncrement($conn, $table) {
    $result = $conn->query("SHOW TABLE STATUS LIKE '" . $conn->real_escape_string($table) . "'");
    if ($result && $row = $result->fetch_assoc()) {
        return intval($row['Auto_increment']);
    }
    return 1;
}

function predictInvoiceRefNo($conn, $date) {
    $nextId = getNextAutoIncrement($conn, 'invoices');
    return generateRefNo($conn, $nextId, $date, 'IN');
}
?>