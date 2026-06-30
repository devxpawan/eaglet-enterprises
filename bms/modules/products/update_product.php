<?php
require_once __DIR__ . '/../../config/paths.php';

// update_product.php
session_start();

// Include necessary files
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

// Check if product ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid product ID.";
    header("Location: " . BASE_URL . "modules/products/product_list.php");
    exit();
}

$product_id = intval($_GET['id']);

// Check if form is submitted via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate input
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    
    // Validate prices (handle empty inputs properly)
    $lkr_price = !empty($_POST['lkr_price']) ? floatval($_POST['lkr_price']) : null;
    
    if ($lkr_price !== null && $lkr_price < 0) {
        $_SESSION['error_message'] = "Price cannot be negative.";
        header("Location: " . BASE_URL . "modules/products/update_product.php?id=$product_id");
        exit;
    }
    
    // Validate required fields
    if (empty($name) || empty($description)) {
        $_SESSION['error_message'] = "Name and description are required.";
        header("Location: " . BASE_URL . "modules/products/update_product.php?id=$product_id");
        exit();
    }
    
    // Check if LKR price is provided
    if ($lkr_price === null) {
        $_SESSION['error_message'] = "LKR price is required.";
        header("Location: " . BASE_URL . "modules/products/update_product.php?id=$product_id");
        exit();
    }
    
    // Prepare SQL statement for update
    $sql = "UPDATE products SET 
            name = ?, 
            description = ?, 
            status = ?, 
            lkr_price = ?,
            updated_at = NOW() 
            WHERE id = ?";
    
    // Prepare statement
    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    $stmt->bind_param("sssdi", $name, $description, $status, $lkr_price, $product_id);
    
    // Execute the statement
    try {
        if ($stmt->execute()) {
            // Successful update
            $_SESSION['success_message'] = "Product updated successfully!";
            header("Location: " . BASE_URL . "modules/products/product_list.php"); // Redirect to product list page
            exit();
        } else {
            // Error in update
            $_SESSION['error_message'] = "Error updating product: " . $stmt->error;
            header("Location: " . BASE_URL . "modules/products/update_product.php?id=$product_id");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        header("Location: " . BASE_URL . "modules/products/update_product.php?id=$product_id");
        exit();
    }
    
    // Close statement
    $stmt->close();
} else {
    // Fetch product details for pre-filling the form
    $sql = "SELECT * FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error_message'] = "Product not found.";
        header("Location: " . BASE_URL . "modules/products/product_list.php");
        exit();
    }
    
    $product = $result->fetch_assoc();
    $stmt->close();
}

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Update Product</title>
</head>
<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="container-fluid px-4">
                    <h1 class="mt-4">Update Product</h1>

                    <?php
                    // Display success or error messages
                    if (isset($_SESSION['success_message'])) {
                        echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '" . addslashes($_SESSION['success_message']) . "'); });</script>";
                        unset($_SESSION['success_message']);
                    }
                    if (isset($_SESSION['error_message'])) {
                        echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '" . addslashes($_SESSION['error_message']) . "'); });</script>";
                        unset($_SESSION['error_message']);
                    }
                    ?>

                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="form-container shadow">
                                <form method="POST" action="<?= BASE_URL ?>modules/products/update_product.php?id=<?php echo $product_id; ?>">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="name" class="form-label">Product Name</label>
                                                <input type="text" class="form-control" id="name" name="name" 
                                                    value="<?php echo htmlspecialchars($product['name']); ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label for="description" class="form-label">Product Description</label>
                                                <textarea class="form-control" id="description" name="description" 
                                                    rows="3" required><?php echo htmlspecialchars($product['description']); ?></textarea>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="lkr_price" class="form-label">LKR Price</label>
                                                <input type="number" step="0.01" class="form-control" id="lkr_price" 
                                                    name="lkr_price" min="0" value="<?php echo $product['lkr_price'] !== null ? $product['lkr_price'] : ''; ?>">
                                            </div>

                                            <div class="mb-3">
                                                <label for="status" class="form-label">Status</label>
                                                <select class="form-control" id="status" name="status">
                                                    <option value="active" <?php echo ($product['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo ($product['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary float-end">Update Product</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
</body>
</html>