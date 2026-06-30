<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
require_once BASE_PATH . 'includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $price_list_date = $_POST['price_list_date'];
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $currency = $_POST['currency'];
    $subject = trim($_POST['subject'] ?? '');
    $notes = $_POST['notes'];
    $payment_terms = $_POST['payment_terms'];
    $terms_conditions = $_POST['terms_conditions'];
    $created_by = $_SESSION['user_id'] ?? null;
    $ref_no = 'PL-' . date('ymdHi');

    // Customer handling - store directly, no auto-creation
    $customer_id = !empty($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $customer_name = !empty(trim($_POST['customer_name'] ?? '')) ? trim($_POST['customer_name']) : null;
    $customer_email = !empty(trim($_POST['customer_email'] ?? '')) ? trim($_POST['customer_email']) : null;
    $customer_phone = !empty(trim($_POST['customer_phone'] ?? '')) ? trim($_POST['customer_phone']) : null;
    $customer_address = !empty(trim($_POST['customer_address'] ?? '')) ? trim($_POST['customer_address']) : null;

    $conn->begin_transaction();

    try {
        // Insert into price_lists
        $stmt = $conn->prepare("INSERT INTO price_lists (ref_no, price_list_date, due_date, subject, currency, notes, payment_terms, terms_conditions, created_by, customer_id, customer_name, customer_email, customer_phone, customer_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssiissss", $ref_no, $price_list_date, $due_date, $subject, $currency, $notes, $payment_terms, $terms_conditions, $created_by, $customer_id, $customer_name, $customer_email, $customer_phone, $customer_address);
        $stmt->execute();
        $price_list_id = $conn->insert_id;

        // Process Sections and Items
        if (isset($_POST['section_name']) && is_array($_POST['section_name'])) {
            foreach ($_POST['section_name'] as $groupIndex => $section_name) {
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
                            $stmtItem = $conn->prepare("INSERT INTO price_list_items (price_list_id, section_name, item_name, price, description) VALUES (?, ?, ?, ?, ?)");
                            $stmtItem->bind_param("issds", $price_list_id, $section_name, $itemName, $itemPrice, $itemDesc);
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
