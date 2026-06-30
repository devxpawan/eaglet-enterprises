<?php
require_once __DIR__ . '/../../config/paths.php';

// Disable error reporting for production
error_reporting(0);

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start a new output buffer
ob_start();

// Include necessary files
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate required fields
        if (empty($_POST['customer_name'])) {
            throw new Exception("Customer name is required.");
        }

        // Check if products are added
        if (empty($_POST['invoice_product'])) {
            throw new Exception("At least one product must be added to the invoice.");
        }

        // Begin transaction
        $conn->begin_transaction();
        
        // Get current user ID from session (default to 1 if not set)
        $user_id = $_SESSION['user_id'] ?? 1;
        
        // Process customer details
        $customer_name = trim($_POST['customer_name']);
        $customer_email = !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null;
        $customer_address = $_POST['customer_address'] ?? '';
        $customer_phone = $_POST['customer_phone'] ?? '';
        $customer_business_name = !empty($_POST['customer_business_name']) ? trim($_POST['customer_business_name']) : null;
        
        // Find or create customer
        $customer_id = 0;
        $checkCustomerSql = "SELECT customer_id, email, phone, address, business_name FROM customers WHERE name = ?";
        $stmt = $conn->prepare($checkCustomerSql);
        $stmt->bind_param("s", $customer_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $customer = $result->fetch_assoc();
            $customer_id = $customer['customer_id'];
            // Update existing customer details with latest info from form
            $updateSql = "UPDATE customers SET email = ?, phone = ?, address = ?, business_name = ? WHERE customer_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("ssssi", $customer_email, $customer_phone, $customer_address, $customer_business_name, $customer_id);
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            // Insert new customer
            $insertCustomerSql = "INSERT INTO customers (name, email, phone, address, business_name, status) 
                                 VALUES (?, ?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($insertCustomerSql);
            $stmt->bind_param("sssss", $customer_name, $customer_email, $customer_phone, $customer_address, $customer_business_name);
            $stmt->execute();
            $customer_id = $conn->insert_id;
        }
        
        // Prepare invoice details
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+30 days'));
        $subject = !empty($_POST['subject']) ? trim($_POST['subject']) : null;
        $notes = $_POST['notes'] ?? 'Thank you for choosing our services. Please review the invoice details and ensure payment is completed by the due date. If there are any discrepancies, kindly inform us immediately.';
        
        // Always use LKR currency
        $currency = 'lkr';
        
        // Get invoice status from form
        $invoice_status = $_POST['invoice_status'] ?? 'Unpaid';
        $pay_status = $invoice_status === 'Paid' ? 'paid' : 'unpaid';
        $pay_date = $invoice_status === 'Paid' ? date('Y-m-d') : null;
        $status = $invoice_status === 'Paid' ? 'done' : 'pending';
        
        // Detailed calculation of totals
        $products = $_POST['invoice_product'];
        $product_prices = $_POST['invoice_product_price'];
        $product_qtys = $_POST['invoice_product_qty'] ?? [];
        $discounts = $_POST['invoice_product_discount'] ?? [];
        $discount_types = $_POST['invoice_product_discount_type'] ?? [];
        $product_descriptions = $_POST['invoice_product_description'] ?? [];
        $subtotal = 0;
        $total_discount = 0;
        
        // Prepare an array to store invoice items
        $invoice_items = [];
        foreach ($products as $key => $product_val) {
            $price = floatval($product_prices[$key] ?? 0);
            $qty = intval($product_qtys[$key] ?? 1);
            $discount_val = floatval($discounts[$key] ?? 0);
            $discount_type = $discount_types[$key] ?? 'flat';
            $description = $product_descriptions[$key] ?? '';
            
            if ($price < 0) {
                throw new Exception("Price cannot be negative for item '$product_val'.");
            }
            if ($discount_val < 0) {
                throw new Exception("Discount cannot be negative for item '$product_val'.");
            }
            
            $row_total = $price * $qty;
            
            // Calculate flat discount based on type
            if ($discount_type === 'percentage') {
                $discount = $row_total * $discount_val / 100;
            } else {
                $discount = $discount_val;
            }
            
            // Ensure discount doesn't exceed row total
            $discount = min($discount, $row_total);
            
            // Accumulate totals
            $subtotal += $row_total;
            $total_discount += $discount;
            
            // Always store as text name (no product table dependency)
            $product_name = $product_val;
            
            // Store item details for insertion
            $invoice_items[] = [
                'product_name' => $product_name,
                'price' => $price,
                'qty' => $qty,
                'discount' => $discount,
                'discount_type' => $discount_type,
                'description' => $description
            ];
        }
        
        // VAT calculation
        $vat_amount = isset($_POST['vat_amount']) ? floatval($_POST['vat_amount']) : 0;
        
        // Final total calculation
        $total_amount = ($subtotal - $total_discount) + $vat_amount;
        
        // Validate total amount is greater than 0
        if ($total_amount <= 0) {
            throw new Exception("Invoice total amount must be greater than 0. Please ensure the products selected have a valid price.");
        }
        
        $amount_paid = $invoice_status === 'Paid' ? $total_amount : 0;

        // Insert invoice
        $insertInvoiceSql = "INSERT INTO invoices (
            customer_id, user_id, issue_date, due_date, subject,
            subtotal, discount, vat, total_amount, amount_paid,
            notes, currency, status, pay_status, pay_date, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insertInvoiceSql);
        $stmt->bind_param(
            "iisssdddddsssssi", 
            $customer_id, $user_id, $invoice_date, $due_date, $subject,
            $subtotal, $total_discount, $vat_amount, $total_amount, $amount_paid,
            $notes, $currency, $status, $pay_status, $pay_date, $user_id
        );
        $stmt->execute();
        $invoice_id = $conn->insert_id;
        
        // Generate and store invoice reference number
        $invoice_ref_no = generateRefNo($conn, $invoice_id, $invoice_date, 'INV');
        $updateRefSql = "UPDATE invoices SET invoice_ref_no = ? WHERE invoice_id = ?";
        $stmt = $conn->prepare($updateRefSql);
        $stmt->bind_param("si", $invoice_ref_no, $invoice_id);
        $stmt->execute();
        
        // Invoice items insertion
        $insertItemSql = "INSERT INTO invoice_items (
            invoice_id, product_name, quantity, discount, discount_type,
            total_amount, pay_status, status, description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertItemSql);
        
        foreach ($invoice_items as $item) {
            $item_total = ($item['price'] * $item['qty']) - $item['discount'];
            $stmt->bind_param(
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
            $stmt->execute();
        }
        
        // If invoice is marked as Paid, insert into payments table
        if ($invoice_status === 'Paid') {
            // Default payment method to 'Cash'
            $payment_method = 'Cash';
            
            // Insert payment record
            $insertPaymentSql = "INSERT INTO payments (
                invoice_id, 
                amount_paid, 
                payment_method, 
                payment_date, 
                pay_by
            ) VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($insertPaymentSql);
            $stmt->bind_param(
                "idsss", 
                $invoice_id, 
                $total_amount, 
                $payment_method, 
                $pay_date, 
                $user_id
            );
            $stmt->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set success message
        $_SESSION['invoice_success'] = "Invoice #" . $invoice_id . " created successfully!";
        
        // Redirect to view invoice page
        header("Location: " . BASE_URL . "modules/invoices/download_invoice.php?id=" . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set error message in session
        $_SESSION['invoice_error'] = $e->getMessage();
        
        // Redirect back to invoice creation page
        header("Location: " . BASE_URL . "modules/invoices/invoice_create.php");
        exit();
    }
} else {
    // Not a POST request
    header("Location: " . BASE_URL . "modules/invoices/invoice_create.php");
    exit();
}
?>