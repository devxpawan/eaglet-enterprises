<?php
require_once __DIR__ . '/../../config/paths.php';

error_reporting(0);
while (ob_get_level()) { ob_end_clean(); }
ob_start();

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (session_status() == PHP_SESSION_NONE) { session_start(); }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (!isset($_POST['quotation_id']) || empty($_POST['quotation_id'])) {
            throw new Exception("Quotation ID is required.");
        }
        if (empty($_POST['customer_name'])) { throw new Exception("Customer name is required."); }
        if (empty($_POST['quotation_product']) || count(array_filter($_POST['quotation_product'], function($v) { return trim($v) !== ''; })) === 0) { throw new Exception("At least one product must be added."); }

        $quotation_id = (int)$_POST['quotation_id'];
        
        $conn->begin_transaction();
        
        // Verify the quotation exists and is in Draft status
        $checkSql = "SELECT status FROM quotations WHERE quotation_id = ? FOR UPDATE";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $quotation_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            throw new Exception("Quotation not found.");
        }
        
        $quotationData = $checkResult->fetch_assoc();
        if ($quotationData['status'] !== 'Draft') {
            throw new Exception("Only draft quotations can be edited.");
        }
        
        $user_id = $_SESSION['user_id'] ?? 1;
        $customer_name = trim($_POST['customer_name']);
        $customer_email = !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null;
        $customer_address = $_POST['customer_address'] ?? '';
        $customer_phone = $_POST['customer_phone'] ?? '';
        
        // Customer handling - update existing or create new
        $customer_id = $_POST['customer_id'] ?? 0;
        if (!empty($customer_id)) {
            // Update existing customer
            $updateCustomerSql = "UPDATE customers SET name = ?, email = ?, phone = ?, address = ? WHERE customer_id = ?";
            $stmt = $conn->prepare($updateCustomerSql);
            $stmt->bind_param("ssssi", $customer_name, $customer_email, $customer_phone, $customer_address, $customer_id);
            $stmt->execute();
        } else {
            // Create new customer
            $insertCustomerSql = "INSERT INTO customers (name, email, phone, address, status) VALUES (?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($insertCustomerSql);
            $stmt->bind_param("ssss", $customer_name, $customer_email, $customer_phone, $customer_address);
            $stmt->execute();
            $customer_id = $conn->insert_id;
        }
        
        $q_date = $_POST['quotation_date'] ?? date('Y-m-d');
        $e_date = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+14 days'));
        $subject = !empty($_POST['subject']) ? trim($_POST['subject']) : null;
        $notes = $_POST['notes'] ?? '';
        $currency = 'lkr';
        $status = $_POST['quotation_status'] ?? 'Draft';
        
        $products = $_POST['quotation_product'];
        $prices = $_POST['quotation_product_price'];
        $qtys = $_POST['quotation_product_qty'] ?? [];
        $discounts = $_POST['quotation_product_discount'] ?? [];
        $discount_types = $_POST['quotation_product_discount_type'] ?? [];
        $descriptions = $_POST['quotation_product_description'] ?? [];
        $item_ids = $_POST['quotation_item_id'] ?? [];
        
        $subtotal = 0;
        $total_discount = 0;
        $items_to_process = [];
        
        foreach ($products as $key => $product_val) {
            $price = floatval($prices[$key] ?? 0);
            $qty = intval($qtys[$key] ?? 1);
            $discount_val = floatval($discounts[$key] ?? 0);
            $discount_type = $discount_types[$key] ?? 'flat';
            $desc = $descriptions[$key] ?? '';
            $item_id = intval($item_ids[$key] ?? 0);
            
            $row_total = $price * $qty;
            
            if ($discount_type === 'percentage') {
                $discount = $row_total * $discount_val / 100;
            } else {
                $discount = $discount_val;
            }
            
            $subtotal += $row_total;
            $total_discount += $discount;
            
            // Always store as text name (no product table dependency)
            $product_name = $product_val;
            
            $items_to_process[] = [
                'item_id' => $item_id,
                'product_name' => $product_name,
                'price' => $price,
                'qty' => $qty,
                'discount' => $discount,
                'discount_type' => $discount_type,
                'description' => $desc,
                'total' => $row_total - $discount
            ];
        }
        
        $vat_amount = isset($_POST['vat_amount']) ? floatval($_POST['vat_amount']) : 0;
        $total_amount = ($subtotal - $total_discount) + $vat_amount;
        
        // Update the quotation
        $updateQSql = "UPDATE quotations SET 
                        customer_id = ?,
                        quotation_date = ?,
                        expiry_date = ?,
                        subject = ?,
                        subtotal = ?,
                        discount = ?,
                        vat = ?,
                        total_amount = ?,
                        notes = ?,
                        currency = ?
                       WHERE quotation_id = ?";
        $stmt = $conn->prepare($updateQSql);
        $stmt->bind_param("issddddddsi", $customer_id, $q_date, $e_date, $subject, $subtotal, $total_discount, $vat_amount, $total_amount, $notes, $currency, $quotation_id);
        $stmt->execute();
        
        // Get existing item IDs for this quotation
        $existingStmt = $conn->prepare("SELECT id FROM quotation_items WHERE quotation_id = ?");
        $existingStmt->bind_param("i", $quotation_id);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();
        $existingIds = [];
        while ($row = $existingResult->fetch_assoc()) {
            $existingIds[] = $row['id'];
        }
        $existingStmt->close();
        
        // Collect submitted item IDs (only non-zero = existing items to keep)
        $submittedIds = [];
        foreach ($items_to_process as $item) {
            if ($item['item_id'] > 0) {
                $submittedIds[] = $item['item_id'];
            }
        }
        
        // Delete items that were removed from the form (in DB but not in submitted IDs)
        if (!empty($existingIds)) {
            $idsToDelete = array_diff($existingIds, $submittedIds);
            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $deleteStmt = $conn->prepare("DELETE FROM quotation_items WHERE id IN ($placeholders)");
                $deleteStmt->bind_param(str_repeat('i', count($idsToDelete)), ...$idsToDelete);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
        
        // Update existing items and insert new ones
        $updateItemSql = "UPDATE quotation_items SET product_name = ?, quantity = ?, description = ?, price = ?, discount = ?, discount_type = ?, total_amount = ? WHERE id = ?";
        $insertItemSql = "INSERT INTO quotation_items (quotation_id, product_name, quantity, description, price, discount, discount_type, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $updateStmt = $conn->prepare($updateItemSql);
        $insertStmt = $conn->prepare($insertItemSql);
        
        foreach ($items_to_process as $item) {
            if ($item['item_id'] > 0) {
                // Update existing item
                $updateStmt->bind_param("sisddsdi", $item['product_name'], $item['qty'], $item['description'], $item['price'], $item['discount'], $item['discount_type'], $item['total'], $item['item_id']);
                $updateStmt->execute();
            } else {
                // Insert new item
                $insertStmt->bind_param("isisddsd", $quotation_id, $item['product_name'], $item['qty'], $item['description'], $item['price'], $item['discount'], $item['discount_type'], $item['total']);
                $insertStmt->execute();
            }
        }
        
        $updateStmt->close();
        $insertStmt->close();
        
        $conn->commit();
        
        while (ob_get_level()) { ob_end_clean(); }
        $_SESSION['quotation_success'] = "Quotation #" . $quotation_id . " updated successfully!";
        header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        while (ob_get_level()) { ob_end_clean(); }
        $_SESSION['quotation_error'] = $e->getMessage();
        header("Location: " . BASE_URL . "modules/quotations/quotation_edit.php?id=" . ($_POST['quotation_id'] ?? 0));
        exit();
    }
} else {
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
    exit();
}
?>