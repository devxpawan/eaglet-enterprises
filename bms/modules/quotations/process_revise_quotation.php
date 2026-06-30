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
        if (!isset($_POST['original_quotation_id']) || empty($_POST['original_quotation_id'])) {
            throw new Exception("Original Quotation ID is required.");
        }
        if (empty($_POST['customer_name'])) { throw new Exception("Customer name is required."); }
        if (empty($_POST['quotation_product']) || count(array_filter($_POST['quotation_product'], function($v) { return trim($v) !== ''; })) === 0) {
            throw new Exception("At least one product must be added.");
        }

        $original_quotation_id = (int)$_POST['original_quotation_id'];

        $conn->begin_transaction();

        // Fetch the original quotation
        $checkSql = "SELECT * FROM quotations WHERE quotation_id = ? FOR UPDATE";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $original_quotation_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows === 0) {
            throw new Exception("Original quotation not found.");
        }

        $originalQuotation = $checkResult->fetch_assoc();

        if ($originalQuotation['status'] === 'Revised') {
            throw new Exception("This quotation has already been revised. Please revise the latest revision instead.");
        }
        if ($originalQuotation['status'] === 'Accepted') {
            throw new Exception("Accepted quotations cannot be revised.");
        }

        // Determine the original_ref_no and next revision number
        $original_ref_no = $originalQuotation['original_ref_no'] ?? $originalQuotation['ref_no'];
        if (empty($original_ref_no)) {
            throw new Exception("Cannot revise a quotation without a reference number.");
        }

        $revision_no = getNextRevisionNo($conn, $original_ref_no);
        $new_ref_no = generateRevisedRefNo($original_ref_no, $revision_no);

        // Mark old quotation as Revised
        $updateOldSql = "UPDATE quotations SET status = 'Revised' WHERE quotation_id = ?";
        $updateOldStmt = $conn->prepare($updateOldSql);
        $updateOldStmt->bind_param("i", $original_quotation_id);
        if (!$updateOldStmt->execute()) {
            throw new Exception("Failed to mark old quotation as revised: " . $updateOldStmt->error);
        }
        $updateOldStmt->close();

        $user_id = $_SESSION['user_id'] ?? 1;
        $customer_name = trim($_POST['customer_name']);
        $customer_email = !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null;
        $customer_address = $_POST['customer_address'] ?? '';
        $customer_phone = $_POST['customer_phone'] ?? '';
        $customer_business_name = !empty($_POST['customer_business_name']) ? trim($_POST['customer_business_name']) : null;

        // Customer handling
        $customer_id = $_POST['customer_id'] ?? 0;
        if (!empty($customer_id)) {
            $updateCustomerSql = "UPDATE customers SET name = ?, email = ?, phone = ?, address = ?, business_name = ? WHERE customer_id = ?";
            $stmt = $conn->prepare($updateCustomerSql);
            $stmt->bind_param("sssssi", $customer_name, $customer_email, $customer_phone, $customer_address, $customer_business_name, $customer_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to update customer: " . $stmt->error);
            }
        } else {
            $insertCustomerSql = "INSERT INTO customers (name, email, phone, address, business_name, status) VALUES (?, ?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($insertCustomerSql);
            $stmt->bind_param("sssss", $customer_name, $customer_email, $customer_phone, $customer_address, $customer_business_name);
            if (!$stmt->execute()) {
                throw new Exception("Failed to create customer: " . $stmt->error);
            }
            $customer_id = $conn->insert_id;
        }

        $q_date = $_POST['quotation_date'] ?? date('Y-m-d');
        $e_date = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+14 days'));
        $subject = !empty($_POST['subject']) ? trim($_POST['subject']) : null;
        $notes = $_POST['notes'] ?? '';
        $currency = 'lkr';
        $status = 'Draft';

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

            $subtotal += $row_total;
            $total_discount += $discount;

            $items_to_insert[] = [
                'product_name' => $product_val,
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

        // Insert new revision quotation
        $insertQSql = "INSERT INTO quotations (customer_id, user_id, quotation_date, expiry_date, subject, subtotal, discount, vat, total_amount, notes, currency, status, created_by, ref_no, revision_no, original_ref_no)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQSql);
        $stmt->bind_param("iisssddddsssisis", $customer_id, $user_id, $q_date, $e_date, $subject, $subtotal, $total_discount, $vat_amount, $total_amount, $notes, $currency, $status, $user_id, $new_ref_no, $revision_no, $original_ref_no);
        if (!$stmt->execute()) {
            throw new Exception("Failed to create revision quotation: " . $stmt->error);
        }
        $new_quotation_id = $conn->insert_id;

        // Insert Items
        $insertItemSql = "INSERT INTO quotation_items (quotation_id, product_name, quantity, description, price, discount, discount_type, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertItemSql);

        foreach ($items_to_insert as $item) {
            $stmt->bind_param("isisddsd", $new_quotation_id, $item['product_name'], $item['qty'], $item['description'], $item['price'], $item['discount'], $item['discount_type'], $item['total']);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert item: " . $stmt->error);
            }
        }

        // Log the revision
        $action_type = "revise_quotation";
        $log_details = "Quotation #$original_quotation_id ($originalQuotation[ref_no]) revised to #$new_quotation_id ($new_ref_no) by user ID #$user_id";
        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("isis", $user_id, $action_type, $original_quotation_id, $log_details);
        if (!$log_stmt->execute()) {
            throw new Exception("Failed to log revision: " . $log_stmt->error);
        }
        $log_stmt->close();

        $conn->commit();
        $checkStmt->close();

        while (ob_get_level()) { ob_end_clean(); }
        $_SESSION['quotation_success'] = "Revision created: $new_ref_no";
        $redirect_url = BASE_URL . "modules/quotations/draft_quotation_list.php";
        header("Location: " . $redirect_url);
        echo '<script>window.location.href = "' . $redirect_url . '";</script>';
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        while (ob_get_level()) { ob_end_clean(); }
        $_SESSION['quotation_error'] = $e->getMessage();
        header("Location: " . BASE_URL . "modules/quotations/revise_quotation.php?id=" . ($_POST['original_quotation_id'] ?? 0));
        exit();
    }
} else {
    header("Location: " . BASE_URL . "modules/quotations/draft_quotation_list.php");
    exit();
}
?>