<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
require_once BASE_PATH . 'includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $price_list_id = intval($_POST['price_list_id']);
    $price_list_date = $_POST['price_list_date'];
    $currency = $_POST['currency'];
    $notes = $_POST['notes'];
    $payment_terms = $_POST['payment_terms'];
    $terms_conditions = $_POST['terms_conditions'];

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
    } elseif (empty($customer_id)) {
        $customer_id = null;
    }

    $conn->begin_transaction();

    try {
        // Update price_lists
        $stmt = $conn->prepare("UPDATE price_lists SET price_list_date = ?, currency = ?, notes = ?, payment_terms = ?, terms_conditions = ?, customer_id = ? WHERE id = ?");
        $stmt->bind_param("sssssii", $price_list_date, $currency, $notes, $payment_terms, $terms_conditions, $customer_id, $price_list_id);
        $stmt->execute();

        // Delete existing items
        $deleteStmt = $conn->prepare("DELETE FROM price_list_items WHERE price_list_id = ?");
        $deleteStmt->bind_param("i", $price_list_id);
        $deleteStmt->execute();

        // Re-insert Asset Groups and Items
        if (isset($_POST['asset_name']) && is_array($_POST['asset_name'])) {
            foreach ($_POST['asset_name'] as $groupIndex => $asset_name) {
                if (isset($_POST['item_name'][$groupIndex]) && is_array($_POST['item_name'][$groupIndex])) {
                    $itemNames = $_POST['item_name'][$groupIndex];
                    $itemDescriptions = $_POST['item_description'][$groupIndex];
                    $itemPrices = $_POST['item_price'][$groupIndex];

                    for ($i = 0; $i < count($itemNames); $i++) {
                        $itemName = $itemNames[$i];
                        $itemDesc = $itemDescriptions[$i];
                        $itemPrice = floatval($itemPrices[$i]);

                        if ($itemPrice < 0) {
                            throw new Exception("Price cannot be negative for item '$itemName'.");
                        }

                        if (!empty($itemName)) {
                            $stmtItem = $conn->prepare("INSERT INTO price_list_items (price_list_id, asset_name, item_name, price, description) VALUES (?, ?, ?, ?, ?)");
                            $stmtItem->bind_param("issds", $price_list_id, $asset_name, $itemName, $itemPrice, $itemDesc);
                            $stmtItem->execute();
                        }
                    }
                }
            }
        }

        $conn->commit();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['success' => true, 'id' => $price_list_id]);
            exit();
        } else {
            header("Location: " . BASE_URL . "modules/price-lists/view_price_list.php?id=" . $price_list_id . "&updated=1");
            exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        } else {
            echo "Error: " . $e->getMessage();
        }
    }
}
$conn->close();
?>
