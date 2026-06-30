<?php
require_once __DIR__ . '/../../config/paths.php';

error_reporting(0);

while (ob_get_level()) {
    ob_end_clean();
}

ob_start();

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? 0;
$canEditDirectly = isApprover();

if (!$canEditDirectly && empty($_POST['invoice_id'])) {
    while (ob_get_level()) ob_end_clean();
    $_SESSION['message'] = "You do not have permission to edit invoices.";
    $_SESSION['message_type'] = "danger";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
}

if (!$canEditDirectly) {
    $invoice_id_check = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
    if ($invoice_id_check === 0 || !hasApprovedEditRequest($conn, $invoice_id_check, $current_user_id)) {
        while (ob_get_level()) ob_end_clean();
        $_SESSION['message'] = "You do not have an approved edit request for this invoice.";
        $_SESSION['message_type'] = "danger";
        header("Location: " . BASE_URL . "modules/invoices/invoice_list.php");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
}

try {
    if (empty($_POST['invoice_id'])) {
        throw new Exception("Invoice ID is required.");
    }

    $invoice_id = (int) $_POST['invoice_id'];

    if (empty($_POST['customer_name'])) {
        throw new Exception("Customer name is required.");
    }

    if (empty($_POST['invoice_product'])) {
        throw new Exception("At least one product must be included in the invoice.");
    }

    $checkInvoiceSql = "SELECT invoice_id, customer_id, status FROM invoices WHERE invoice_id = ?";
    $stmt = $conn->prepare($checkInvoiceSql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $checkResult = $stmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Invoice not found.");
    }

    $existingInvoice = $checkResult->fetch_assoc();
    if (!in_array($existingInvoice['status'], ['pending', null, ''], true)) {
        throw new Exception("Only pending invoices can be edited.");
    }

    $conn->begin_transaction();

    $user_id = $_SESSION['user_id'] ?? 1;

    $customer_name = trim($_POST['customer_name']);
    $customer_email = !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null;
    $customer_address = $_POST['customer_address'] ?? '';
    $customer_phone = $_POST['customer_phone'] ?? '';
    $customer_business_name = !empty($_POST['customer_business_name']) ? trim($_POST['customer_business_name']) : null;

    $customer_id = 0;
    $incoming_customer_id = isset($_POST['customer_id']) && !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;

    if ($incoming_customer_id > 0) {
        $checkExistingCustomer = $conn->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
        $checkExistingCustomer->bind_param("i", $incoming_customer_id);
        $checkExistingCustomer->execute();
        $existingCustResult = $checkExistingCustomer->get_result();
        if ($existingCustResult->num_rows > 0) {
            $customer_id = $incoming_customer_id;
            $updateExistingCustomer = $conn->prepare("UPDATE customers SET name = ?, email = ?, phone = ?, address = ?, business_name = ? WHERE customer_id = ?");
            $updateExistingCustomer->bind_param("sssssi", $customer_name, $customer_email, $customer_phone, $customer_address, $customer_business_name, $customer_id);
            $updateExistingCustomer->execute();
        }
    }

    if ($customer_id === 0) {
        if (!empty($customer_email)) {
            $checkCustomerSql = "SELECT customer_id FROM customers WHERE name = ? AND email = ?";
            $stmt = $conn->prepare($checkCustomerSql);
            $stmt->bind_param("ss", $customer_name, $customer_email);
        } else {
            $checkCustomerSql = "SELECT customer_id FROM customers WHERE name = ? AND (email IS NULL OR email = '')";
            $stmt = $conn->prepare($checkCustomerSql);
            $stmt->bind_param("s", $customer_name);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $customer = $result->fetch_assoc();
            $customer_id = (int) $customer['customer_id'];
            $updateCustomer = $conn->prepare("UPDATE customers SET phone = ?, address = ?, business_name = ? WHERE customer_id = ?");
            $updateCustomer->bind_param("sssi", $customer_phone, $customer_address, $customer_business_name, $customer_id);
            $updateCustomer->execute();
        } else {
            $insertCustomerSql = "INSERT INTO customers (name, email, phone, address, business_name, status) VALUES (?, ?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($insertCustomerSql);
            $stmt->bind_param("sssss", $customer_name, $customer_email, $customer_phone, $customer_address, $customer_business_name);
            $stmt->execute();
            $customer_id = $conn->insert_id;
        }
    }

    $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+30 days'));
    $subject = !empty($_POST['subject']) ? trim($_POST['subject']) : null;
    $notes = $_POST['notes'] ?? '';
    $currency = 'lkr';

    $products = $_POST['invoice_product'];
    $product_prices = $_POST['invoice_product_price'];
    $product_qtys = $_POST['invoice_product_qty'] ?? [];
    $discounts = $_POST['invoice_product_discount'] ?? [];
    $discount_types = $_POST['invoice_product_discount_type'] ?? [];
    $product_descriptions = $_POST['invoice_product_description'] ?? [];
    $item_ids = $_POST['invoice_item_id'] ?? [];

    $subtotal = 0;
    $total_discount = 0;
    $invoice_items = [];

    foreach ($products as $key => $product_val) {
        if (empty($product_val)) {
            throw new Exception("Please select a product for every invoice line.");
        }
        $price = floatval($product_prices[$key] ?? 0);
        $qty = intval($product_qtys[$key] ?? 1);
        $discount_val = floatval($discounts[$key] ?? 0);
        $discount_type = $discount_types[$key] ?? 'flat';
        $description = $product_descriptions[$key] ?? '';
        $item_id = intval($item_ids[$key] ?? 0);

        if ($price < 0) {
            throw new Exception("Price cannot be negative for item '$product_val'.");
        }
        if ($discount_val < 0) {
            throw new Exception("Discount cannot be negative for item '$product_val'.");
        }

        $row_total = $price * $qty;

        if ($discount_type === 'percentage') {
            $discount = $row_total * $discount_val / 100;
        } else {
            $discount = $discount_val;
        }
        $discount = min($discount, $row_total);

        $subtotal += $row_total;
        $total_discount += $discount;

        // Always store as text name (no product table dependency)
        $product_name = $product_val;

        $invoice_items[] = [
            'item_id' => $item_id,
            'product_name' => $product_name,
            'price' => $price,
            'qty' => $qty,
            'discount' => $discount,
            'discount_type' => $discount_type,
            'description' => $description,
            'total' => $row_total - $discount
        ];
    }

    $vat_amount = isset($_POST['vat_amount']) ? floatval($_POST['vat_amount']) : 0;
    $total_amount = ($subtotal - $total_discount) + $vat_amount;
    if ($total_amount <= 0) {
        throw new Exception("Invoice total amount must be greater than 0. Please ensure the products selected have a valid price.");
    }

    $updateInvoiceSql = "UPDATE invoices SET
        customer_id = ?,
        issue_date = ?,
        due_date = ?,
        subject = ?,
        subtotal = ?,
        discount = ?,
        vat = ?,
        total_amount = ?,
        notes = ?,
        currency = ?
        WHERE invoice_id = ?";

    $stmt = $conn->prepare($updateInvoiceSql);
    $stmt->bind_param(
        "isssddddssi",
        $customer_id,
        $invoice_date,
        $due_date,
        $subject,
        $subtotal,
        $total_discount,
        $vat_amount,
        $total_amount,
        $notes,
        $currency,
        $invoice_id
    );
    $stmt->execute();

    // Get existing item IDs for this invoice
    $existingStmt = $conn->prepare("SELECT item_id FROM invoice_items WHERE invoice_id = ?");
    $existingStmt->bind_param("i", $invoice_id);
    $existingStmt->execute();
    $existingResult = $existingStmt->get_result();
    $existingIds = [];
    while ($row = $existingResult->fetch_assoc()) {
        $existingIds[] = $row['item_id'];
    }
    $existingStmt->close();
    
    // Collect submitted item IDs (only non-zero = existing items to keep)
    $submittedIds = [];
    foreach ($invoice_items as $item) {
        if ($item['item_id'] > 0) {
            $submittedIds[] = $item['item_id'];
        }
    }
    
    // Delete items that were removed from the form (in DB but not in submitted IDs)
    if (!empty($existingIds)) {
        $idsToDelete = array_diff($existingIds, $submittedIds);
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $deleteStmt = $conn->prepare("DELETE FROM invoice_items WHERE item_id IN ($placeholders)");
            $deleteStmt->bind_param(str_repeat('i', count($idsToDelete)), ...$idsToDelete);
            $deleteStmt->execute();
            $deleteStmt->close();
        }
    }
    
    // Update existing items and insert new ones
    $updateItemSql = "UPDATE invoice_items SET product_name = ?, quantity = ?, discount = ?, discount_type = ?, total_amount = ?, description = ? WHERE item_id = ?";
    $insertItemSql = "INSERT INTO invoice_items (
        invoice_id, product_name, quantity, discount, discount_type,
        total_amount, pay_status, status, description
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $updateStmt = $conn->prepare($updateItemSql);
    $insertStmt = $conn->prepare($insertItemSql);
    
    foreach ($invoice_items as $item) {
        $item_total = ($item['price'] * $item['qty']) - $item['discount'];
        
        if ($item['item_id'] > 0) {
            // Update existing item
            $updateStmt->bind_param("sidsdsi", $item['product_name'], $item['qty'], $item['discount'], $item['discount_type'], $item_total, $item['description'], $item['item_id']);
            $updateStmt->execute();
        } else {
            // Insert new item
            $pay_status = 'unpaid';
            $status = 'pending';
            $insertStmt->bind_param(
                "isidsdsss",
                $invoice_id,
                $item['product_name'],
                $item['qty'],
                $item['discount'],
                $item['discount_type'],
                $item_total,
                $pay_status,
                $status,
                $item['description']
            );
            $insertStmt->execute();
        }
    }
    
    $updateStmt->close();
    $insertStmt->close();

    $action_type = "edit_invoice";
    $details = "Invoice ID #$invoice_id was updated by user ID #$user_id. New total: $total_amount $currency.";
    $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("isss", $user_id, $action_type, $invoice_id, $details);
    $log_stmt->execute();

    $conn->commit();

    while (ob_get_level()) {
        ob_end_clean();
    }

    $_SESSION['message'] = "Invoice #$invoice_id has been updated successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    exit();
} catch (Exception $e) {
    $conn->rollback();

    while (ob_get_level()) {
        ob_end_clean();
    }

    $_SESSION['invoice_error'] = $e->getMessage();
    $redirect_id = isset($invoice_id) ? (int) $invoice_id : (isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0);
    if ($redirect_id > 0) {
        header("Location: " . BASE_URL . "modules/invoices/invoice_edit.php?id=" . $redirect_id);
    } else {
        header("Location: " . BASE_URL . "modules/invoices/pending_invoice_list.php");
    }
    exit();
}
?>