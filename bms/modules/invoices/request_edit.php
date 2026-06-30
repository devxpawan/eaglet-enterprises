<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['message'] = "Invoice ID is required.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    exit();
}

$invoice_id = (int) $_GET['id'];

$invoiceSql = "SELECT i.*, c.name as customer_name, c.email as customer_email,
               c.phone as customer_phone, c.address as customer_address, c.business_name as customer_business_name
               FROM invoices i
               LEFT JOIN customers c ON i.customer_id = c.customer_id
               WHERE i.invoice_id = ?";
$stmt = $conn->prepare($invoiceSql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoiceResult = $stmt->get_result();

if ($invoiceResult->num_rows === 0) {
    $_SESSION['message'] = "Invoice not found.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    exit();
}

$invoice = $invoiceResult->fetch_assoc();

if ($invoice['status'] !== 'pending' && $invoice['status'] !== null && $invoice['status'] !== '') {
    $_SESSION['message'] = "Only pending invoices can be edited.";
    $_SESSION['message_type'] = "warning";
    header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$hasPendingRequest = hasPendingEditRequest($conn, $invoice_id, $user_id);
$hasApprovedRequest = hasApprovedEditRequest($conn, $invoice_id, $user_id);

if ($hasApprovedRequest) {
    header("Location: " . BASE_URL . "modules/invoices/invoice_edit.php?id=" . $invoice_id);
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Request Edit Approval — Invoice #<?= htmlspecialchars($invoice_id) ?></title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <style>
        body { background: #f5f6fa; font-family: 'Inter', sans-serif; }
        .request-card { max-width: 640px; margin: 40px auto; border-radius: 16px; border: 1px solid #eaecf0; background: #fff; box-shadow: 0 1px 3px rgba(16,24,40,0.06); overflow: hidden; }
        .request-header { background: linear-gradient(135deg, #f9fafb 0%, #f3f4ff 100%); padding: 24px 28px; border-bottom: 1px solid #eaecf0; }
        .request-header h5 { font-weight: 700; color: #101828; margin: 0; }
        .request-body { padding: 24px 28px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: #f9fafb; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
        .info-grid .label { font-size: 11px; font-weight: 600; color: #667085; text-transform: uppercase; letter-spacing: 0.04em; }
        .info-grid .value { font-size: 14px; font-weight: 600; color: #101828; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .status-pending { background: #fff8e1; color: #b78103; }
    </style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="request-card">
                    <div class="request-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-pen me-2 text-primary"></i>Request Edit Access</h5>
                        <span class="status-badge status-pending"><i class="fas fa-clock"></i> Pending</span>
                    </div>
                    <div class="request-body">
                        <?php if ($hasPendingRequest): ?>
                            <div class="alert alert-warning d-flex align-items-center gap-2">
                                <i class="fas fa-info-circle"></i>
                                You already have a pending edit request for this invoice. Please wait for an approver to review it.
                            </div>
                            <div class="text-center mt-3">
                                <a href="<?= BASE_URL ?>modules/invoices/invoice_list.php" class="btn btn-outline-secondary">Back to Invoices</a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-4" style="font-size: 14px;">
                                You are requesting permission to edit <strong>Invoice #<?= $invoice_id ?></strong>.
                                A director-level user will review your request. Once approved, you can proceed with editing.
                            </p>

                            <div class="info-grid">
                                <div>
                                    <div class="label">Invoice ID</div>
                                    <div class="value">#<?= htmlspecialchars($invoice['invoice_id']) ?></div>
                                </div>
                                <div>
                                    <div class="label">Total Amount</div>
                                    <div class="value">Rs. <?= htmlspecialchars(number_format((float)$invoice['total_amount'], 2)) ?></div>
                                </div>
                                <div>
                                    <div class="label">Customer</div>
                                    <div class="value"><?= htmlspecialchars($invoice['customer_name'] ?? '-') ?></div>
                                </div>
                                <div>
                                    <div class="label">Issue Date</div>
                                    <div class="value"><?= htmlspecialchars(date('d/m/Y', strtotime($invoice['issue_date']))) ?></div>
                                </div>
                            </div>

                            <form method="post" action="<?= BASE_URL ?>modules/invoices/process_request_edit.php">
                                <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                                <div class="mb-3">
                                    <label class="form-label">Reason for Editing <span class="text-muted">(optional)</span></label>
                                    <textarea name="reason" class="form-control" rows="3" placeholder="Briefly explain why you need to edit this invoice..."></textarea>
                                </div>
                                <div class="d-flex justify-content-end gap-2 pt-2">
                                    <a href="<?= BASE_URL ?>modules/invoices/pending_invoice_list.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-1"></i> Submit Request
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>

</html>
