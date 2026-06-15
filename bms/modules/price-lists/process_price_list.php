<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
require_once BASE_PATH . 'includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $price_list_date = $_POST['price_list_date'];
    $currency = $_POST['currency'];
    $notes = $_POST['notes'];
    $payment_terms = $_POST['payment_terms'];
    $terms_conditions = $_POST['terms_conditions'];
    $created_by = $_SESSION['user_id'] ?? null;

    // Customer handling
    $customer_id = $_POST['customer_id'] ?? 0;
    if (empty($customer_id) && !empty(trim($_POST['customer_name'] ?? ''))) {
        $customer_name = trim($_POST['customer_name']);
        $customer_email = !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null;
        $customer_phone = $_POST['customer_phone'] ?? '';
        $customer_address = $_POST['customer_address'] ?? '';
        $insertCustomerSql = "INSERT INTO customers (name, email, phone, address, status) VALUES (?, ?, ?, ?, 'Active')";
        $stmtCust = $conn->prepare($insertCustomerSql);
        $stmtCust->bind_param("ssss", $customer_name, $customer_email, $customer_phone, $customer_address);
        $stmtCust->execute();
        $customer_id = $conn->insert_id;
    }

    $conn->begin_transaction();

    try {
        // Insert into price_lists
        $stmt = $conn->prepare("INSERT INTO price_lists (price_list_date, currency, notes, payment_terms, terms_conditions, created_by, customer_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssii", $price_list_date, $currency, $notes, $payment_terms, $terms_conditions, $created_by, $customer_id);
        $stmt->execute();
        $price_list_id = $conn->insert_id;

        // Process Asset Groups and Items
        if (isset($_POST['asset_id']) && is_array($_POST['asset_id'])) {
            foreach ($_POST['asset_id'] as $groupIndex => $asset_id) {
                if (isset($_POST['item_name'][$groupIndex]) && is_array($_POST['item_name'][$groupIndex])) {
                    $itemNames = $_POST['item_name'][$groupIndex];
                    $itemDescriptions = $_POST['item_description'][$groupIndex];
                    $itemPrices = $_POST['item_price'][$groupIndex];

                    for ($i = 0; $i < count($itemNames); $i++) {
                        $itemName = $itemNames[$i];
                        $itemDesc = $itemDescriptions[$i];
                        $itemPrice = $itemPrices[$i];

                        if (!empty($itemName)) {
                            $stmtItem = $conn->prepare("INSERT INTO price_list_items (price_list_id, asset_id, item_name, price, description) VALUES (?, ?, ?, ?, ?)");
                            $stmtItem->bind_param("iisds", $price_list_id, $asset_id, $itemName, $itemPrice, $itemDesc);
                            $stmtItem->execute();
                        }
                    }
                }
            }
        }

        $conn->commit();
        header("Location: " . BASE_URL . "modules/price-lists/view_price_list.php?id=" . $price_list_id);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}
$conn->close();
?>
