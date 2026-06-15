<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning only if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    // Force redirect to login page
    header("Location: " . BASE_URL . "signin.php");
    exit(); // Stop execution immediately
}

// Only Admin and Moderator can edit customers
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 3])) {
    header("Location: " . BASE_URL . "modules/customers/customer_list.php");
    exit();
}

// Include the database connection file
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Email validation function - enhanced version
function validateEmail($email) {
    if (empty($email)) {
        return "";
    }
    
    // Convert to lowercase for consistent validation
    $email = strtolower($email);
    
    // Check maximum length
    if (strlen($email) > 254) {
        return "Email address is too long (maximum 254 characters allowed).";
    }
    
    // Basic format validation with filter_var
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Please enter a valid email address format (e.g., name@example.com).";
    }
    
    // Advanced structure validation
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return "Email must contain exactly one @ symbol.";
    }
    
    $username = $parts[0];
    $domain = $parts[1];
    
    // Username validation
    if (strlen($username) > 64) {
        return "Username part of email is too long (maximum 64 characters allowed).";
    }
    
    if (preg_match('/^\.|\.\.|\.$/', $username)) {
        return "Username cannot start or end with a period or contain consecutive periods.";
    }
    
    // Domain validation
    if (!strpos($domain, '.')) {
        return "Email domain appears to be invalid. Must contain at least one period.";
    }
    
    $domainParts = explode('.', $domain);
    
    // Check domain part before TLD
    if (strlen($domainParts[0]) > 63) {
        return "Email domain name is too long.";
    }
    
    // Check TLD (last part)
    $tld = end($domainParts);
    if (strlen($tld) < 2 || strlen($tld) > 10) {
        return "Email TLD (domain ending) is invalid.";
    }
    
    // Check for invalid domain patterns
    if (preg_match('/^-|-$/', $domain) || preg_match('/^-|-$/', $tld)) {
        return "Domain parts cannot start or end with hyphens.";
    }
    
    // All checks passed
    return "";
}

// Function to log user activity with point-wise changes
function logActivity($conn, $user_id, $action_type, $customer_id, $details, $changes_array = array()) {
    try {
        // Insert main log entry
        $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())");
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        
        $stmt->bind_param("isis", $user_id, $action_type, $customer_id, $details);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $log_id = $stmt->insert_id;
        $stmt->close();
        
        // If we have change details, store them in a structured format
        if (!empty($changes_array)) {
            foreach ($changes_array as $field => $change) {
                $stmt = $conn->prepare("INSERT INTO change_details (log_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    error_log("Failed to prepare change_details statement: " . $conn->error);
                    continue;
                }
                
                $stmt->bind_param("isss", $log_id, $field, $change['old'], $change['new']);
                if (!$stmt->execute()) {
                    error_log("Failed to execute change_details statement: " . $stmt->error);
                }
                $stmt->close();
            }
        }
        
        return true;
    } catch (Exception $e) {
        // Log error but continue with the main functionality
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

// Function to get point-wise change details for a log entry
function getChangeDetails($conn, $log_id) {
    $changes = array();
    
    try {
        $stmt = $conn->prepare("SELECT field_name, old_value, new_value FROM change_details WHERE log_id = ?");
        if (!$stmt) {
            error_log("Failed to prepare getChangeDetails statement: " . $conn->error);
            return $changes;
        }
        
        $stmt->bind_param("i", $log_id);
        if (!$stmt->execute()) {
            error_log("Failed to execute getChangeDetails statement: " . $stmt->error);
            $stmt->close();
            return $changes;
        }
        
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $changes[] = array(
                'field' => $row['field_name'],
                'old_value' => $row['old_value'],
                'new_value' => $row['new_value']
            );
        }
        
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error getting change details: " . $e->getMessage());
    }
    
    return $changes;
}

// Check if the customer ID is provided in the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Customer ID is required.";
    header("Location: customer_list.php");
    exit();
}

$customer_id = intval($_GET['id']);

// Fetch customer details from the database
try {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error);
    }
    
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) {
        throw new Exception("Database execute error: " . $stmt->error);
    }
    
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // No customer found with the given ID
        $_SESSION['error_message'] = "Customer not found.";
        header("Location: customer_list.php");
        exit();
    }

    $customer = $result->fetch_assoc();
    $stmt->close();

} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header("Location: customer_list.php");
    exit();
}

// Initialize error message variable
$errorMsg = '';
$successMsg = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMsg = "Invalid request. Please try again.";
    } else {
         // Sanitize and validate inputs
         $name = trim($_POST['name']);
         $email = trim($_POST['email']); // Keep original case for validation
         $phone = trim($_POST['phone']);
         $address = trim($_POST['address']);
         $business_name = trim($_POST['business_name']);
        // Enhanced validation checks
        if (empty($name)) {
            $errorMsg = "Name cannot be empty.";
        } elseif (strlen($name) > 100) {
            $errorMsg = "Name is too long (maximum 100 characters allowed).";
        } else {
            if (!empty($email)) {
                $emailError = validateEmail($email);
                if (!empty($emailError)) {
                    $errorMsg = $emailError;
                }
            }
        }
        
         // If no email errors continue with other validations
         if (empty($errorMsg)) {
             if (empty($phone)) {
                 $errorMsg = "Phone number cannot be empty.";
             } elseif (empty($address)) {
                 $errorMsg = "Address cannot be empty.";
             } elseif (strlen($address) > 255) {
                 $errorMsg = "Address is too long (maximum 255 characters allowed).";
             } elseif (!empty($business_name) && strlen($business_name) > 100) {
                 $errorMsg = "Business name is too long (maximum 100 characters allowed).";
             }

            // Updated phone validation - exactly 10 digits
            // Remove all non-digit characters
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (!empty($phone) && strlen($cleanPhone) !== 10) {
                $errorMsg = "Phone number must be exactly 10 digits.";
            }
        }

        // If no errors, proceed with database update
        if (empty($errorMsg)) {
            $email = !empty($email) ? strtolower($email) : null;
            
            // Check if email already exists in database (excluding current customer)
            try {
                if (!empty($email)) {
                    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM customers WHERE email = ? AND customer_id != ?");
                    if (!$checkStmt) {
                        throw new Exception("Database prepare error: " . $conn->error);
                    }
                    
                    $checkStmt->bind_param("si", $email, $customer_id);
                    if (!$checkStmt->execute()) {
                        throw new Exception("Database execute error: " . $checkStmt->error);
                    }
                    
                    $checkStmt->bind_result($emailCount);
                    $checkStmt->fetch();
                    $checkStmt->close();
                    
                    if ($emailCount > 0) {
                        $errorMsg = "This email address is already registered to another customer. Please use a different email.";
                    }
                } else {
                    $emailCount = 0;
                }

                if (empty($errorMsg)) {
                     // Store only clean 10-digit phone number in database
                     $phone = $cleanPhone;
                     
                     // Store original values to check for changes
                     $originalName = $customer['name'];
                     $originalEmail = $customer['email'];
                     $originalPhone = $customer['phone'];
                     $originalAddress = $customer['address'];
                     $originalBusinessName = $customer['business_name'];
                     // Track changes in a structured format for the database
                    $changeDetails = array();
                    $changedFields = array(); // For the log message

                    if ($name !== $originalName) {
                        $changedFields[] = "name: '$originalName' → '$name'";
                        $changeDetails['name'] = array('old' => $originalName, 'new' => $name);
                    }

                     if ($email !== $originalEmail) {
                         $changedFields[] = "email: '$originalEmail' → '$email'";
                         $changeDetails['email'] = array('old' => $originalEmail, 'new' => $email);
                     }

                     if ($business_name !== $originalBusinessName) {
                         $changedFields[] = "business_name: '$originalBusinessName' → '$business_name'";
                         $changeDetails['business_name'] = array('old' => $originalBusinessName, 'new' => $business_name);
                     }

                    if ($phone !== $originalPhone) {
                        $changedFields[] = "phone: '$originalPhone' → '$phone'";
                        $changeDetails['phone'] = array('old' => $originalPhone, 'new' => $phone);
                    }

                    if ($address !== $originalAddress) {
                        $changedFields[] = "address: '$originalAddress' → '$address'";
                        $changeDetails['address'] = array('old' => $originalAddress, 'new' => $address);
                    }

                    // Generate activity log details
                    $changes = !empty($changedFields) ? " Changes: " . implode(", ", $changedFields) : "";
                    $activityDetails = "Customer ID #$customer_id ($name) was updated by user ID #{$_SESSION['user_id']}.{$changes}";

                      // Prepare SQL statement to prevent SQL injection
                      $stmt = $conn->prepare("UPDATE customers SET name = ?, email = ?, phone = ?, address = ?, business_name = ? WHERE customer_id = ?");
                      $stmt->bind_param("sssssi", $name, $email, $phone, $address, $business_name, $customer_id);

                    // Execute the statement
                    if ($stmt->execute()) {
                        $stmt->close();

                        // Log the activity with structured change details
                        logActivity($conn, $_SESSION['user_id'], 'edit_customer', $customer_id, $activityDetails, $changeDetails);

                        // Set success message
                        $_SESSION['success_message'] = "Customer updated successfully!";

                        // Redirect to prevent form resubmission
                        header("Location: " . BASE_URL . "modules/customers/edit_customer.php?id=" . $customer_id);
                        exit();
                    } else {
                        $errorMsg = "Error: " . $stmt->error;
                    }

                    // Close the statement
                    $stmt->close();
                }
            } catch (Exception $e) {
                $errorMsg = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Fetch edit history for this customer
$editHistory = array();
try {
    // First, check if the required tables exist
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_logs'");
    $userLogsExists = $tableCheck && $tableCheck->num_rows > 0;
    
    $tableCheck = $conn->query("SHOW TABLES LIKE 'users'");
    $usersExists = $tableCheck && $tableCheck->num_rows > 0;
    
    $tableCheck = $conn->query("SHOW TABLES LIKE 'change_details'");
    $changeDetailsExists = $tableCheck && $tableCheck->num_rows > 0;
    
    if ($userLogsExists && $usersExists) {
        $sql = "
            SELECT ul.log_id, ul.details, ul.created_at, ul.user_id, u.username 
            FROM user_logs ul
            LEFT JOIN users u ON ul.user_id = u.user_id
            WHERE ul.inquiry_id = ? AND ul.action_type = 'edit_customer'
            ORDER BY ul.created_at DESC
            LIMIT 10
        ";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Failed to prepare edit history statement: " . $conn->error);
        } else {
            $stmt->bind_param("i", $customer_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                while ($row = $result->fetch_assoc()) {
                    // Get point-wise change details for each log entry only if change_details table exists
                    $changeDetails = $changeDetailsExists ? getChangeDetails($conn, $row['log_id']) : array();
                    $row['changes'] = $changeDetails;
                    $editHistory[] = $row;
                }
            } else {
                error_log("Failed to execute edit history statement: " . $stmt->error);
            }
            $stmt->close();
        }
    } else {
        error_log("Required tables (user_logs, users) do not exist for edit history");
    }
} catch (Exception $e) {
    error_log("Error fetching edit history: " . $e->getMessage());
}

// Determine values to display (prioritize POST data for form repopulation)
$name = isset($_POST['name']) ? htmlspecialchars($_POST['name']) : htmlspecialchars($customer['name']);
$email = isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($customer['email']);
$phone = isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : htmlspecialchars($customer['phone']);
$address = isset($_POST['address']) ? htmlspecialchars($_POST['address']) : htmlspecialchars($customer['address']);
$business_name = isset($_POST['business_name']) ? htmlspecialchars($_POST['business_name']) : htmlspecialchars($customer['business_name']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Edit Customer</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8fafc;
        }

        .form-floating .form-control {
            height: calc(3.5rem + 2px);
        }
        
        .alert {
            border-radius: 5px;
            border-left-width: 5px;
        }
        
        .alert-success {
            border-left-color: #198754;
        }
        
        .alert-danger {
            border-left-color: #dc3545;
        }
        
        .form-floating label {
            opacity: 0.65;
        }
        .select2-container--bootstrap-5 .select2-selection {
            border: 1.5px solid var(--premium-input-border);
            border-radius: 8px;
            min-height: 45px;
            padding-top: 5px;
            background-color: #fbfcfd;
        }
        .select2-container--bootstrap-5.select2-container--focus .select2-selection {
            border-color: var(--premium-primary-light);
            box-shadow: 0 0 0 4px var(--premium-input-focus);
            background-color: #fff;
        }
        
        /* Edit history styles */
        .history-card {
            margin-top: 30px;
            border-radius: 5px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
        }
        
        .history-header {
            background-color: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            font-weight: 500;
        }
        
        .history-item {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }
        
        .change-item {
            background-color: #f8f9fc;
            padding: 8px 12px;
            margin: 5px 0;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .change-field {
            font-weight: 500;
            color: #1565C0;
        }
        
        .old-value {
            color: #dc3545;
            text-decoration: line-through;
        }
        
        .new-value {
            color: #198754;
        }
        
        .history-meta {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 8px;
        }

        /* Select2 Bootstrap 5 Theme Adjustments */
        .select2-container--bootstrap-5 .select2-selection {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
            min-height: calc(2.25rem + 2px);
        }
        .select2-container--bootstrap-5.select2-container--focus .select2-selection {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
    </style>
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5>Update Customer</h5>
                        <p class="text-muted">Update the details of the customer</p>
                    </div>
                </div>

                <!-- Success/Error Alert -->
                <?php if (!empty($successMsg)): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($successMsg); ?>'); });</script>
                <?php endif; ?>

                <?php if (!empty($errorMsg)): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($errorMsg); ?>'); });</script>
                <?php endif; ?>

                <!-- Show session messages if they exist -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($_SESSION["success_message"]); ?>'); });</script>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($_SESSION["error_message"]); ?>'); });</script>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <div class="card" style="margin: 0 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                    <div class="card-body" style="padding: 28px 32px;">
                            <form method="POST" action="<?= BASE_URL ?>modules/customers/edit_customer.php?id=<?php echo $customer_id; ?>" id="editCustomerForm" novalidate>
                                <!-- CSRF Token -->
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="premium-section-header">
                                            <i class="fas fa-id-card me-2"></i> Customer Details
                                        </div>

                                        <!-- Business Name Field -->
                                        <div class="mb-3">
                                            <label for="business_name" class="form-label">Business Name</label>
                                             <input type="text" class="form-control" id="business_name" name="business_name"
                                                 placeholder="Business Name" value="<?php echo $business_name; ?>" data-original="<?php echo $business_name; ?>">
                                            <div class="error-feedback" id="business_name-error"></div>
                                        </div>
                                        
                                        <!-- Name Field -->
                                        <div class="mb-3">
                                            <label for="name" class="form-label">Full Name</label>
                                             <input type="text" class="form-control" id="name" name="name"
                                                placeholder="Full Name" value="<?php echo $name; ?>" data-original="<?php echo $name; ?>" required>
                                            <div class="error-feedback" id="name-error"></div>
                                        </div>

                                        <!-- Email Field -->
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address</label>
                                             <input type="email" class="form-control" id="email" name="email"
                                                placeholder="name@example.com" value="<?php echo $email; ?>" data-original="<?php echo $email; ?>">
                                            <div class="error-feedback" id="email-error"></div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="premium-section-header">
                                            <i class="fas fa-address-book me-2"></i> Contact Details
                                        </div>

                                        <!-- Phone Field -->
                                        <div class="mb-3">
                                            <label for="phone" class="form-label">Phone Number</label>
                                             <input type="tel" class="form-control" id="phone" name="phone"
                                                 placeholder="Enter 10-digit phone number" value="<?php echo $phone; ?>" data-original="<?php echo $phone; ?>" required>
                                            <div class="error-feedback" id="phone-error"></div>
                                        </div>

                                        <!-- Address Field -->
                                        <div class="mb-3">
                                            <label for="address" class="form-label">Address</label>
                                             <textarea class="form-control" id="address" name="address"
                                                placeholder="Address" required rows="3" data-original="<?php echo $address; ?>"><?php echo $address; ?></textarea>
                                            <div class="error-feedback" id="address-error"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Submit Button -->
                                <div class="row mt-4 pt-3 border-top">
                                    <div class="col-12 d-flex justify-content-end gap-3">
                                        <a href="<?= BASE_URL ?>modules/customers/customer_list.php" class="back-btn text-decoration-none d-flex align-items-center">
                                            <i class="fas fa-arrow-left me-2"></i> Cancel
                                        </a>
                                        <button type="submit" class="save-btn" id="submitBtn" disabled>
                                            <i class="fas fa-save me-2"></i> Update Customer
                                        </button>
                                    </div>
                                </div>
                            </form>
                    </div>
                </div>
                
                <!-- Customer Edit History Section -->
                <?php if (!empty($editHistory)): ?>
                <div class="card mt-4" style="margin: 0 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                    <div class="card-body" style="padding: 28px 32px;">
                        <div class="premium-section-header">
                            <i class="fas fa-history me-1"></i> Edit History
                        </div>
                        <?php foreach ($editHistory as $history): ?>
                        <div class="history-item" style="padding: 15px; border-bottom: 1px solid #eaecf0;">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-primary me-2">Edit</span>
                                <strong><?php echo htmlspecialchars($history['username']); ?></strong>
                                <span class="ms-auto small text-muted">
                                    <?php echo date('M d, Y h:i A', strtotime($history['created_at'])); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($history['changes'])): ?>
                                <div class="changes-container">
                                    <?php foreach ($history['changes'] as $change): ?>
                                        <div class="change-item" style="background-color: #f9fafb; padding: 8px 12px; margin: 5px 0; border-radius: 8px; font-size: 0.9rem;">
                                            <span class="change-field" style="font-weight: 500; color: #3B82F6;"><?php echo ucfirst(htmlspecialchars($change['field'])); ?>:</span>
                                            <span class="old-value" style="color: #b42318; text-decoration: line-through;"><?php echo htmlspecialchars($change['old_value']); ?></span>
                                            <i class="fas fa-long-arrow-alt-right mx-2" style="color:#667085"></i>
                                            <span class="new-value" style="color: #067647;"><?php echo htmlspecialchars($change['new_value']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted">No detailed changes recorded.</div>
                            <?php endif; ?>
                            
                            <div class="history-meta" style="font-size: 0.8rem; color: #98a2b3; margin-top: 8px;">
                                <i class="fas fa-info-circle me-1"></i>
                                <?php echo "Log ID: #" . $history['log_id']; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    
    <script>
    /**
     * Enhanced Email Validation Function
     * Performs comprehensive email validation with detailed error messages
     */
    function validateEmail(email) {
        if (email.trim() === '') {
            return {
                valid: true,
                message: ''
            };
        }
        
        // Check total length
        if (email.length > 254) {
            return {
                valid: false,
                message: 'Email address is too long (maximum 254 characters allowed)'
            };
        }
        
        // Check if original email contains uppercase letters
        const lowerEmail = email.toLowerCase();
        if (email !== lowerEmail) {
            return {
                valid: false,
                message: 'Email address must be in lowercase only'
            };
        }
        
        // Split email into parts for detailed validation
        const parts = email.split('@');
        if (parts.length !== 2) {
            return {
                valid: false,
                message: 'Email must contain exactly one @ symbol'
            };
        }
        
        const username = parts[0];
        const domain = parts[1];
        
        // Username part validation
        if (username.length === 0) {
            return {
                valid: false,
                message: 'Username part of email cannot be empty'
            };
        }
        
        if (username.length > 64) {
            return {
                valid: false,
                message: 'Username part of email is too long (maximum 64 characters allowed)'
            };
        }
        
        // Check for invalid patterns in username
        if (/^\.|\.$|\.\./.test(username)) {
            return {
                valid: false,
                message: 'Username cannot start or end with a period or contain consecutive periods'
            };
        }
        
        // Check for invalid characters in username
        if (!/^[a-z0-9.!#$%&'*+/=?^_`{|}~-]+$/i.test(username)) {
            return {
                valid: false,
                message: 'Username contains invalid characters'
            };
        }
        
        // Domain part validation
        if (domain.length === 0) {
            return {
                valid: false,
                message: 'Domain part of email cannot be empty'
            };
        }
        
        if (!domain.includes('.')) {
            return {
                valid: false,
                message: 'Email domain must include at least one period'
            };
        }
        
        // Check for invalid patterns in domain
        if (/^-|-$/.test(domain)) {
            return {
                valid: false,
                message: 'Domain cannot start or end with a hyphen'
            };
        }
        
        // Domain parts validation
        const domainParts = domain.split('.');
        
        // Check domain name (part before TLD)
        if (domainParts[0].length > 63) {
            return {
                valid: false,
                message: 'Domain name is too long (maximum 63 characters allowed)'
            };
        }
        
        // Check for invalid characters in domain
        if (!/^[a-z0-9.-]+$/i.test(domain)) {
            return {
                valid: false,
                message: 'Domain contains invalid characters'
            };
        }
        
        // Check TLD (last part)
        const tld = domainParts[domainParts.length - 1];
        if (tld.length < 2 || tld.length > 10) {
            return {
                valid: false,
                message: 'Email TLD (domain ending) is invalid'
            };
        }
        
        // Check if TLD contains only letters (no numbers or special chars)
        if (!/^[a-z]+$/i.test(tld)) {
            return {
                valid: false,
                message: 'TLD can only contain letters'
            };
        }
        
        // Complex email regex pattern for final validation
        const emailRegex = /^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i;
        if (!emailRegex.test(email)) {
            return {
                valid: false,
                message: 'Please enter a valid email address format (e.g., name@example.com)'
            };
        }

        return {
            valid: true,
            message: ''
        };
    }


   // Enhanced phone validation function for Sri Lankan numbers
function validatePhone(phone) {
    // Remove all non-digit characters except the + sign
    const cleanPhone = phone.replace(/[^\d+]/g, '');
    
    // Remove all non-digit characters for digit counting
    const digits = phone.replace(/\D/g, '');
    
    // Check for international format: +94 followed by 9 digits
    if (cleanPhone.startsWith('+94')) {
        if (digits.length === 12) { // +94 (2 digits) + 9 digits = 11 total, but we count all digits including 94
            const localNumber = digits.substring(2); // Remove the 94
            if (localNumber.length === 9) {
                return {
                    valid: true,
                    message: '',
                    format: 'international',
                    countryCode: '+94',
                    localNumber: localNumber
                };
            }
        }
        return {
            valid: false,
            message: 'International format should be +94 followed by 9 digits (e.g., +94729666892)'
        };
    }
    
    // Check for local format: exactly 10 digits
    if (digits.length === 10) {
        return {
            valid: true,
            message: '',
            format: 'local',
            localNumber: digits
        };
    }
    
    // Invalid length
    if (digits.length < 10) {
        return {
            valid: false,
            message: 'Phone number too short. Enter 10 digits for local or +94 followed by 9 digits for international format'
        };
    } else {
        return {
            valid: false,
            message: 'Phone number too long. Enter 10 digits for local or +94 followed by 9 digits for international format'
        };
    }
}

// Test examples
console.log('Testing phone validation:');
console.log(validatePhone('+94729666892')); // Should be valid (international)
console.log(validatePhone('0729666892'));   // Should be valid (local)
console.log(validatePhone('729666892'));    // Should be invalid (9 digits)
console.log(validatePhone('072966689234')); // Should be invalid (too long)
console.log(validatePhone('+947296668'));   // Should be invalid (international but too short)

    // Name validation function
    function validateName(name) {
        if (name.trim() === '') {
            return {
                valid: false,
                message: 'Name cannot be empty'
            };
        }
        
        if (name.length > 100) {
            return {
                valid: false,
                message: 'Name is too long (maximum 100 characters allowed)'
            };
        }
        
        return {
            valid: true,
            message: ''
        };
    }

    // Address validation function
    function validateAddress(address) {
        if (address.trim() === '') {
            return {
                valid: false,
                message: 'Address cannot be empty'
            };
        }
        
        if (address.length > 255) {
            return {
                valid: false,
                message: 'Address is too long (maximum 255 characters allowed)'
            };
        }
        
        return {
            valid: true,
            message: ''
        };
    }

    // Setup validation for input fields with real-time feedback
    function setupValidation(inputId, validationFunction, errorId) {
        const inputElement = document.getElementById(inputId);
        const errorElement = document.getElementById(errorId);
        
        // Real-time validation as user types (with a small delay for better UX)
        let typingTimer;
        const doneTypingInterval = 500; // half a second
        
        inputElement.addEventListener('keyup', function() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                validateField(inputElement, validationFunction, errorElement);
            }, doneTypingInterval);
        });
        
        // Immediate validation on blur (when user leaves the field)
        inputElement.addEventListener('blur', function() {
            clearTimeout(typingTimer);
            validateField(inputElement, validationFunction, errorElement);
        });
        
        // Return a function that can be called to validate the field programmatically
        return function() {
            return validateField(inputElement, validationFunction, errorElement);
        };
    }
    
    function validateField(inputElement, validationFunction, errorElement) {
        // Reset validation state
        inputElement.classList.remove('is-invalid');
        inputElement.classList.remove('is-valid');
        errorElement.style.display = 'none';
        
        const value = inputElement.value.trim();
        
        // Empty check for required fields
        if (inputElement.hasAttribute('required') && value === '') {
            inputElement.classList.add('is-invalid');
            errorElement.textContent = `${inputElement.previousElementSibling.textContent.trim()} is required`;
            errorElement.style.display = 'block';
            return false;
        }
        
        // Skip further validation if empty and not required
        if (value === '' && !inputElement.hasAttribute('required')) {
            return true;
        }
        
        // Format check
        const validationResult = validationFunction(value);
        if (!validationResult.valid) {
            inputElement.classList.add('is-invalid');
            errorElement.textContent = validationResult.message;
            errorElement.style.display = 'block';
            
            
            return false;
        } else {
            // Show valid feedback
            inputElement.classList.add('is-valid');
            return true;
        }
    }

    // Initialize validation functions for each field
    const validateEmailField = setupValidation('email', validateEmail, 'email-error');
    const validatePhoneField = setupValidation('phone', validatePhone, 'phone-error');
    const validateNameField = setupValidation('name', validateName, 'name-error');
    const validateAddressField = setupValidation('address', validateAddress, 'address-error');

    // Auto-convert email to lowercase
    const emailInput = document.getElementById('email');
    emailInput.addEventListener('input', function() {
        // Get cursor position before change
        const start = this.selectionStart;
        const end = this.selectionEnd;
        
        // Convert to lowercase
        this.value = this.value.toLowerCase();
        
        // Restore cursor position
        this.setSelectionRange(start, end);
    });

    // Phone handling - strip non-digits as user types
    const phoneInput = document.getElementById('phone');
    phoneInput.addEventListener('input', function(e) {
        // Get only digits from the input
        let digits = this.value.replace(/\D/g, '');
        
        // Store cursor position
        const cursorPos = this.selectionStart;
        const oldLength = this.value.length;
        
        // Limit to 10 digits
        if (digits.length > 10) {
            digits = digits.substring(0, 10);
        }
        
        // Update the input value with only digits
        this.value = digits;
        
        // Adjust cursor position if text changed
        const newLength = this.value.length;
        const cursorAdjust = newLength - oldLength;
        
        // Only set selection range if the element is focused
        if (document.activeElement === this) {
            let newPos = cursorPos + cursorAdjust;
            if (newPos < 0) newPos = 0;
            if (newPos > this.value.length) newPos = this.value.length;
            this.setSelectionRange(newPos, newPos);
        }
    });

    // Client-side form validation
    document.getElementById('editCustomerForm').addEventListener('submit', function(event) {
        let isValid = true;
        
        // Validate all fields
        if (!validateNameField()) isValid = false;
        if (!validateEmailField()) isValid = false;
        if (!validatePhoneField()) isValid = false;
        if (!validateAddressField()) isValid = false;
        
        if (!isValid) {
            event.preventDefault();
            
            // Scroll to the first error
            const firstError = document.querySelector('.is-invalid');
            if (firstError) {
                firstError.focus();
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        // Check if any field has changed
        const fields = ['business_name', 'name', 'email', 'phone', 'address'];
        let hasChanged = false;
        for (const id of fields) {
            const el = document.getElementById(id);
            if (el && el.value !== el.getAttribute('data-original')) {
                hasChanged = true;
                break;
            }
        }
        if (!hasChanged) {
            event.preventDefault();
        }
    });

    // Enable/disable submit button based on whether any field changed
    function checkForChanges() {
        const fields = ['business_name', 'name', 'email', 'phone', 'address'];
        let hasChanged = false;
        for (const id of fields) {
            const el = document.getElementById(id);
            if (el && el.value !== el.getAttribute('data-original')) {
                hasChanged = true;
                break;
            }
        }
        document.getElementById('submitBtn').disabled = !hasChanged;
    }

    // Attach listeners to static fields
    ['business_name', 'name', 'email', 'phone', 'address'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', checkForChanges);
            el.addEventListener('change', checkForChanges);
        }
    });

    </script>

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#status').select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumResultsForSearch: Infinity
        });

        // Trigger change detection for Select2-managed dropdowns
        $('#status').on('select2:select select2:unselect select2:clear', function () {
            checkForChanges();
        });
    });
    </script>
    
    <?php
    // Close the connection at the end of the script
    $conn->close();
    ?>
</body>

</html>