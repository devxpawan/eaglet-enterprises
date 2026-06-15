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

// Only Admin and Moderator can add customers
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 3])) {
    header("Location: " . BASE_URL . "modules/customers/customer_list.php");
    exit();
}

// Include the database connection file
require_once BASE_PATH . 'includes/db_connection.php';

require_once BASE_PATH . 'includes/functions.php'; // Include helper functions

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

        // If no errors, proceed with database insertion
        if (empty($errorMsg)) {
            $email = !empty($email) ? strtolower($email) : null;
            
            if (!empty($email)) {
                // Check if email already exists in database
                $checkStmt = $conn->prepare("SELECT COUNT(*) FROM customers WHERE email = ?");
                $checkStmt->bind_param("s", $email);
                $checkStmt->execute();
                $checkStmt->bind_result($emailCount);
                $checkStmt->fetch();
                $checkStmt->close();
                
                if ($emailCount > 0) {
                    $errorMsg = "This email address is already registered. Please use a different email.";
                }
            } else {
                $emailCount = 0;
            }

            if (empty($errorMsg)) {
                 // Store only clean 10-digit phone number in database
                $phone = $cleanPhone;

                 // Prepare SQL statement to prevent SQL injection
                  $stmt = $conn->prepare("INSERT INTO customers (name, email, phone, address, business_name, status) VALUES (?, ?, ?, ?, ?, 'active')");
                  $stmt->bind_param("sssss", $name, $email, $phone, $address, $business_name);

                // Execute the statement
                if ($stmt->execute()) {
                    $stmt->close();
                    
                    // Use session for success message to prevent form resubmission
                    $_SESSION['success_message'] = "New customer added successfully!";
                    header("Location: " . BASE_URL . "modules/customers/add_customer.php");
                    exit();
                } else {
                    $errorMsg = "Error: " . $stmt->error;
                    $stmt->close();
                }

            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Add Customer</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8fafc;
        }
        
        .form-floating .form-control {
            height: calc(3.5rem + 2px);
        }
        
        .is-valid {
            border-color: #198754;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
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
                        <h5>Add New Customer</h5>
                        <p class="text-muted">Fill in the details to add a new customer</p>
                    </div>
                </div>
                
                <!-- Success/Error Alert -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($_SESSION['success_message']); ?>'); });</script>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (!empty($errorMsg)): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($errorMsg); ?>'); });</script>
                <?php endif; ?>
                
                <div class="card" style="margin: 0 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                    <div class="card-body" style="padding: 28px 32px;">
                        <form method="POST" action="<?= BASE_URL ?>modules/customers/add_customer.php" id="addCustomerForm" novalidate>
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="premium-section-header">
                                        <i class="fas fa-id-card me-2"></i> Customer Details
                                    </div>

                                        <!-- Business Name Field -->
                                        <div class="mb-3">
                                            <label for="business_name" class="form-label">Business Name</label>
                                            <input type="text" class="form-control" id="business_name" name="business_name"
                                                 placeholder="Business Name" value="<?php echo isset($business_name) ? htmlspecialchars($business_name) : ''; ?>">
                                            <div class="error-feedback" id="business_name-error"></div>
                                        </div>
                                        
                                        <!-- Name Field -->
                                        <div class="mb-3">
                                            <label for="name" class="form-label">Full Name</label>
                                            <input type="text" class="form-control" id="name" name="name"
                                                placeholder="Full Name" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" required>
                                            <div class="error-feedback" id="name-error"></div>
                                        </div>

                                        <!-- Email Field -->
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email"
                                                placeholder="name@example.com" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
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
                                                 placeholder="Enter 10-digit phone number" value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>" required>
                                            <div class="error-feedback" id="phone-error"></div>
                                        </div>

                                        <!-- Address Field -->
                                        <div class="mb-3">
                                            <label for="address" class="form-label">Address</label>
                                            <textarea class="form-control" id="address" name="address"
                                                 placeholder="Address" required rows="3"><?php echo isset($address) ? htmlspecialchars($address) : ''; ?></textarea>
                                            <div class="error-feedback" id="address-error"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-4 pt-3 border-top">
                                    <div class="col-12 d-flex justify-content-end gap-3">
                                        <a href="<?= BASE_URL ?>modules/customers/customer_list.php" class="back-btn text-decoration-none d-flex align-items-center">
                                            <i class="fas fa-arrow-left me-2"></i> Cancel
                                        </a>
                                        <button type="submit" class="save-btn" id="submitBtn">
                                            <i class="fas fa-save me-2"></i> Add Customer
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
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


    // Updated phone validation function - strict 10 digits only
    function validatePhone(phone) {
        // Remove all non-digit characters for validation
        const digits = phone.replace(/\D/g, '');
        
        if (digits.length !== 10) {
            return {
                valid: false,
                message: 'Please enter exactly 10 digits for the phone number'
            };
        }
        
        return {
            valid: true,
            message: ''
        };
    }

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
    document.getElementById('addCustomerForm').addEventListener('submit', function(event) {
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
        }
    });

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    
    <?php
    // Close the connection at the end of the script
    $conn->close();
    ?>
</body>

</html>