<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Quotation ID is required");
}

$quotation_id = (int)$_GET['id'];

try {
    $conn->begin_transaction();

    // 1. Fetch quotation data
    $q_query = "SELECT * FROM quotations WHERE quotation_id = ?";
    $stmt = $conn->prepare($q_query);
    $stmt->bind_param("i", $quotation_id);
    $stmt->execute();
    $quotation = $stmt->get_result()->fetch_assoc();

    if (!$quotation) throw new Exception("Quotation not found");
    if ($quotation['status'] === 'Accepted') throw new Exception("Quotation already converted to invoice");
    if ($quotation['status'] === 'Revised') throw new Exception("Cannot convert a revised quotation. Please use the latest revision.");

    // 2. Create Invoice
    $issue_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+7 days'));
    $pay_status = 'unpaid';
    $status = 'pending';
    $quotation_ref_no = $quotation['ref_no'] ?? null;

    $insertInvSql = "INSERT INTO invoices (customer_id, user_id, issue_date, due_date, subject, total_amount, discount, vat, pay_status, status, currency, quotation_ref_no, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insertInvSql);
    $vat_default = 0;
    $subject = !empty($quotation['subject']) ? $quotation['subject'] : null;
    $stmt->bind_param("iisssdddsssss", $quotation['customer_id'], $_SESSION['user_id'], $issue_date, $due_date, $subject, $quotation['total_amount'], $quotation['discount'], $vat_default, $pay_status, $status, $quotation['currency'], $quotation_ref_no, $quotation['notes']);
    $stmt->execute();
    $invoice_id = $conn->insert_id;
    
    // Generate and store invoice reference number
    $invoice_ref_no = generateRefNo($conn, $invoice_id, $issue_date, 'INV');
    $updateRefSql = "UPDATE invoices SET invoice_ref_no = ? WHERE invoice_id = ?";
    $stmt = $conn->prepare($updateRefSql);
    $stmt->bind_param("si", $invoice_ref_no, $invoice_id);
    $stmt->execute();

    // 3. Create Invoice Items
    $items_query = "SELECT * FROM quotation_items WHERE quotation_id = ?";
    $stmt = $conn->prepare($items_query);
    $stmt->bind_param("i", $quotation_id);
    $stmt->execute();
    $items = $stmt->get_result();

    $insertItemSql = "INSERT INTO invoice_items (invoice_id, product_name, quantity, description, discount, discount_type, total_amount, status, pay_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'unpaid')";
    $item_stmt = $conn->prepare($insertItemSql);

    while ($item = $items->fetch_assoc()) {
        $discount_type = $item['discount_type'] ?? 'flat';
        $item_stmt->bind_param("isisdsd", $invoice_id, $item['product_name'], $item['quantity'], $item['description'], $item['discount'], $discount_type, $item['total_amount']);
        $item_stmt->execute();
    }

    // 4. Mark Quotation as Accepted
    $updateQSql = "UPDATE quotations SET status = 'Accepted' WHERE quotation_id = ?";
    $stmt = $conn->prepare($updateQSql);
    $stmt->bind_param("i", $quotation_id);
    $stmt->execute();

    // 5. Log the action in user_logs table
    $user_id = $_SESSION['user_id'];
    $action_type = "convert_quotation_to_invoice";
    $log_details = "Quotation ID #$quotation_id was converted to Invoice ID #$invoice_id by user ID #$user_id";
    
    $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) 
               VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("isss", $user_id, $action_type, $quotation_id, $log_details);
    $log_stmt->execute();
    $log_stmt->close();

    $conn->commit();
    $_SESSION['invoice_success'] = "Quotation converted to Invoice #$invoice_id successfully!";
    header("Location: " . BASE_URL . "modules/invoices/download_invoice.php?id=$invoice_id");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['quotation_error'] = "Error converting to invoice: " . $e->getMessage();
    header("Location: " . BASE_URL . "modules/quotations/quotation_list.php");
    exit();
}
?>
