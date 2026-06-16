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
        if (empty($_POST['customer_name'])) { throw new Exception("Customer name is required."); }
        if (empty($_POST['quotation_product'])) { throw new Exception("At least one product must be added."); }

        $conn->begin_transaction();
        
        $user_id = $_SESSION['user_id'] ?? 1;
        $customer_name = trim($_POST['customer_name']);
        $customer_email = !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null;
        $customer_address = $_POST['customer_address'] ?? '';
        $customer_phone = $_POST['customer_phone'] ?? '';
        
        // Customer handling
        $customer_id = $_POST['customer_id'] ?? 0;
        if (empty($customer_id)) {
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
        
        $subtotal = 0;
        $total_discount = 0;
        $items_to_insert = [];
        
        foreach ($products as $key => $product_val) {
            $price = floatval($prices[$key] ?? 0);
            $qty = intval($qtys[$key] ?? 1);
            $discount_val = floatval($discounts[$key] ?? 0);
            $discount_type = $discount_types[$key] ?? 'flat';
            $desc = $descriptions[$key] ?? '';
            
            $row_total = $price * $qty;
            
            if ($discount_type === 'percentage') {
                $discount = $row_total * $discount_val / 100;
            } else {
                $discount = $discount_val;
            }
            
            $subtotal += $row_total;
            $total_discount += $discount;
            
            $product_name = $product_val;
            
            $items_to_insert[] = [
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
        
        // Insert Quotation
        $insertQSql = "INSERT INTO quotations (customer_id, user_id, quotation_date, expiry_date, subject, subtotal, discount, vat, total_amount, notes, currency, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQSql);
        $stmt->bind_param("iisssddddsssi", $customer_id, $user_id, $q_date, $e_date, $subject, $subtotal, $total_discount, $vat_amount, $total_amount, $notes, $currency, $status, $user_id);
        $stmt->execute();
        $quotation_id = $conn->insert_id;
        
        // Insert Items
        $insertItemSql = "INSERT INTO quotation_items (quotation_id, product_name, quantity, description, price, discount, discount_type, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertItemSql);
        
        foreach ($items_to_insert as $item) {
            $stmt->bind_param("isisddsd", $quotation_id, $item['product_name'], $item['qty'], $item['description'], $item['price'], $item['discount'], $item['discount_type'], $item['total']);
            $stmt->execute();
        }
        
        $conn->commit();

        $ref_no = generateRefNo($conn, $quotation_id, $q_date, 'QT');
        $updSql = "UPDATE quotations SET ref_no = ? WHERE quotation_id = ?";
        $updStmt = $conn->prepare($updSql);
        $updStmt->bind_param("si", $ref_no, $quotation_id);
        $updStmt->execute();

        while (ob_get_level()) { ob_end_clean(); }
        $_SESSION['quotation_success'] = "Quotation #" . $quotation_id . " created successfully!";
        header("Location: " . BASE_URL . "modules/quotations/download_quotation.php?id=" . $quotation_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        while (ob_get_level()) { ob_end_clean(); }
        $_SESSION['quotation_error'] = $e->getMessage();
        header("Location: " . BASE_URL . "modules/quotations/quotation_create.php");
        exit();
    }
} else {
    header("Location: " . BASE_URL . "modules/quotations/quotation_create.php");
    exit();
}
?>