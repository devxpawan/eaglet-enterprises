<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning
session_start();

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



// Include necessary files
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Check if the user ID is provided in the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "User ID is required.";
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

$user_id = intval($_GET['id']);

// Fetch user details from the database
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // No user found with the given ID
        $_SESSION['error_message'] = "User not found.";
        header("Location: " . BASE_URL . "modules/users/users.php");
        exit();
    }

    $user = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header("Location: " . BASE_URL . "modules/users/users.php");
    exit();
}

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

    // Determine values to display (prioritize GET parameters for form repopulation)
    $name = isset($_GET['name']) ? urldecode($_GET['name']) : $user['name'];
    $username = isset($_GET['username']) ? urldecode($_GET['username']) : $user['username'];
    $email = isset($_GET['email']) ? urldecode($_GET['email']) : $user['email'];
    $mobile = isset($_GET['mobile']) ? urldecode($_GET['mobile']) : $user['mobile'];
    $nic = isset($_GET['nic']) ? urldecode($_GET['nic']) : $user['nic'];
    $address = isset($_GET['address']) ? urldecode($_GET['address']) : $user['address'];
    $position_id = isset($_GET['position_id']) ? $_GET['position_id'] : ($user['position_id'] ?? '');

    // Check if current user is editing their own account
    $is_editing_self = ($user_id == $_SESSION['user_id']);

    // Fetch available positions dynamically
    $positions = [];
    $positionQuery = "SELECT id, name FROM positions WHERE status = 'active'";
    $positionResult = $conn->query($positionQuery);

    while ($positionRow = $positionResult->fetch_assoc()) {
        $positions[] = $positionRow;
    }

    ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Edit User</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <style></style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5>Edit User</h5>
                        <p class="text-muted">Update user information</p>
                    </div>
                </div>

                <!-- Success Message Display -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($_SESSION["success_message"]); ?>'); });</script>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <!-- Error Message Display -->
                <?php if (isset($_SESSION['error_message'])): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($_SESSION["error_message"]); ?>'); });</script>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <div class="card" style="margin: 0 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                    <div class="card-body" style="padding: 28px 32px;">
                        <form method="POST" action="<?= BASE_URL ?>modules/users/update_edit_user.php" id="editUserForm" novalidate>
                            <!-- CSRF Token and User ID -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
                            <input type="hidden" name="edit_mode" value="1">

                            <div class="row">
                                <!-- User Details Section -->
                                <div class="col-md-6">
                                    <div class="premium-section-header">
                                        <i class="fas fa-user-circle"></i> User Details
                                    </div>

                                        <!-- Name Field -->
                                        <div class="mb-3">
                                            <label for="name" class="form-label">Full Name</label>
                                            <input type="text" class="form-control" id="name" name="name"
                                                placeholder="Full Name" 
                                                value="<?php echo htmlspecialchars($name); ?>" data-original="<?php echo htmlspecialchars($name); ?>" required>
                                            <div class="error-feedback" id="name-error"></div>
                                        </div>

                                        <!-- Username Field -->
                                        <div class="mb-3">
                                            <label for="username" class="form-label">Username</label>
                                            <input type="text" class="form-control" id="username" name="username"
                                                placeholder="Username"
                                                value="<?php echo htmlspecialchars($username); ?>" data-original="<?php echo htmlspecialchars($username); ?>" required>
                                            <div class="error-feedback" id="username-error"></div>
                                        </div>

                                        <!-- Email Field -->
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" id="email" name="email"
                                                placeholder="name@example.com" 
                                                value="<?php echo htmlspecialchars($email); ?>" data-original="<?php echo htmlspecialchars($email); ?>" required>
                                            <div class="error-feedback" id="email-error"></div>
                                        </div>

                                        <!-- Password Field (Optional for Edit) -->
                                        <div class="mb-3">
                                            <label for="password" class="form-label">Password (Leave blank to keep current)</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="password"
                                                    name="password" placeholder="New Password">
                                                <button class="btn btn-outline-secondary toggle-password"
                                                    type="button">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Mobile Field -->
                                        <div class="mb-3">
                                            <label for="mobile" class="form-label">Mobile Number</label>
                                            <input type="tel" class="form-control" id="mobile" name="mobile"
                                                placeholder="Enter Mobile Number" 
                                                value="<?php echo htmlspecialchars($mobile); ?>" data-original="<?php echo htmlspecialchars($mobile); ?>">
                                            <div class="error-feedback" id="mobile-error"></div>
                                        </div>
                                    </div>

                                    <!-- Additional Details Section -->
                                    <div class="col-md-6">
                                        <div class="premium-section-header">
                                            <i class="fas fa-user-shield"></i> Configuration Details
                                        </div>
                                        
                                        <!-- NIC Field -->
                                        <div class="mb-3">
                                            <label for="nic" class="form-label">NIC Number</label>
                                            <input type="text" class="form-control" id="nic" name="nic"
                                                placeholder="Enter NIC Number" 
                                                value="<?php echo htmlspecialchars($nic); ?>" data-original="<?php echo htmlspecialchars($nic); ?>">
                                            <div class="error-feedback" id="nic-error"></div>
                                        </div>

                                        <!-- Address Field -->
                                        <div class="mb-3">
                                            <label for="address" class="form-label">Address</label>
                                            <textarea class="form-control" id="address" name="address"
                                                placeholder="Enter Full Address" rows="3" data-original="<?php echo htmlspecialchars($address); ?>"><?php echo htmlspecialchars($address); ?></textarea>
                                        </div>

                                        <!-- Position Field - Dynamically Populated -->
                                        <div class="mb-3">
                                            <label for="position_id" class="form-label">Position</label>
                                            <select class="form-select" id="position_id" name="position_id" data-original="<?php echo htmlspecialchars($position_id); ?>">
                                                <option value="">Select Position (Optional)...</option>
                                                <?php foreach ($positions as $position): ?>
                                                    <option value="<?= htmlspecialchars($position['id']) ?>" 
                                                            <?php echo (!empty($position_id) && $position['id'] == $position_id) ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars($position['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Buttons -->
                                <div class="row mt-4 pt-3 border-top">
                                    <div class="col-12 d-flex justify-content-end gap-2">
                                        <a href="<?= BASE_URL ?>modules/users/users.php" class="back-btn text-decoration-none d-flex align-items-center">
                                            <i class="fas fa-arrow-left me-1"></i> Cancel
                                        </a>
                                        <button type="submit" class="save-btn" id="submitBtn" disabled>
                                            <i class="fas fa-save me-1"></i> Save Changes
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    // Initialize Select2
    $(document).ready(function() {
        $('#status').select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumResultsForSearch: Infinity
        });

        $('#position_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumResultsForSearch: Infinity,
            placeholder: 'Select Position (Optional)...',
            allowClear: true
        });

        // Trigger change detection for Select2-managed dropdowns
        $('#status, #position_id').on('select2:select select2:unselect', function () {
            checkForChanges();
        });
    });

     // Password toggle visibility
document.querySelector('.toggle-password').addEventListener('click', function () {
    const passwordInput = document.getElementById('password');
    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    passwordInput.setAttribute('type', type);
    this.querySelector('i').classList.toggle('fa-eye');
    this.querySelector('i').classList.toggle('fa-eye-slash');
});

/**
 * Enhanced Email Validation Function
 * Performs comprehensive email validation with detailed error messages
 */
function validateEmail(email) {
    // First check if email is empty
    if (email.trim() === '') {
        return {
            valid: false,
            message: 'Email address cannot be empty'
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
    if (address.trim() === '' && !document.getElementById('address').hasAttribute('required')) {
        return {
            valid: true,
            message: ''
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

// Password validation function
function validatePassword(password) {
    // For edit user, empty password is valid (means no change)
    if (password.trim() === '') {
        return {
            valid: true,
            message: ''
        };
    }
    
    if (password.length < 8) {
        return {
            valid: false,
            message: 'Password must be at least 8 characters long'
        };
    }
    
    return {
        valid: true,
        message: ''
    };
}

// Mobile validation function
function validateMobile(mobile) {
    if (mobile.trim() === '' && !document.getElementById('mobile').hasAttribute('required')) {
        return {
            valid: true,
            message: ''
        };
    }
    
    // Clean the mobile number - remove all non-digit characters
    const digits = mobile.replace(/\D/g, '');
    
    if (digits.length !== 10) {
        return {
            valid: false,
            message: 'Mobile number must be exactly 10 digits'
        };
    }
    
    return {
        valid: true,
        message: ''
    };
}

// NIC validation function
function validateNIC(nic) {
    if (nic.trim() === '' && !document.getElementById('nic').hasAttribute('required')) {
        return {
            valid: true,
            message: ''
        };
    }
    
    const nicRegex = /^([0-9]{9}[vVxX]?|[0-9]{12})$/;
    if (!nicRegex.test(nic)) {
        return {
            valid: false,
            message: 'Please enter a valid NIC number (9 digits + V/X or 12 digits)'
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
    
    if (!inputElement || !errorElement) return () => true;
    
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
        errorElement.textContent = `${inputElement.previousElementSibling ? inputElement.previousElementSibling.textContent.trim() : 'This field'} is required`;
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

// Auto-convert email to lowercase in real-time
const emailInput = document.getElementById('email');
emailInput.addEventListener('input', function() {
    const start = this.selectionStart;
    const end = this.selectionEnd;
    this.value = this.value.toLowerCase();
    this.setSelectionRange(start, end);
});

// Mobile handling - strip non-digits as user types
const mobileInput = document.getElementById('mobile');
mobileInput.addEventListener('input', function(e) {
    let digits = this.value.replace(/\D/g, '');
    const cursorPos = this.selectionStart;
    const oldLength = this.value.length;
    if (digits.length > 10) {
        digits = digits.substring(0, 10);
    }
    this.value = digits;
    const newLength = this.value.length;
    const cursorAdjust = newLength - oldLength;
    if (document.activeElement === this) {
        let newPos = cursorPos + cursorAdjust;
        if (newPos < 0) newPos = 0;
        if (newPos > this.value.length) newPos = this.value.length;
        this.setSelectionRange(newPos, newPos);
    }
});

// Real-time availability check via AJAX
function checkFieldAvailability(endpoint, paramName, value, excludeId, callback) {
    const xhr = new XMLHttpRequest();
    let url = '<?= BASE_URL ?>modules/api/' + endpoint + '?' + paramName + '=' + encodeURIComponent(value);
    if (excludeId) {
        url += '&exclude_id=' + encodeURIComponent(excludeId);
    }
    xhr.open('GET', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                callback(response);
            } catch(e) {
                callback({ available: true });
            }
        }
    };
    xhr.send();
}

// Initialize validation functions for each field
const validateEmailField = setupValidation('email', validateEmail, 'email-error');
const validateNameField = setupValidation('name', validateName, 'name-error');
const validatePasswordField = setupValidation('password', validatePassword, 'password-error');
const validateMobileField = setupValidation('mobile', validateMobile, 'mobile-error');
const validateNICField = setupValidation('nic', validateNIC, 'nic-error');
const validateAddressField = setupValidation('address', validateAddress, 'address-error');
const currentUserId = <?php echo $user_id; ?>;

// Enhanced username validation with real-time availability check
const usernameInput = document.getElementById('username');
const usernameError = document.getElementById('username-error');
let usernameAvailable = true;
let usernameChecked = true;
let usernameCheckTimer;

const validateUsernameField = function() {
    return new Promise((resolve) => {
        const value = usernameInput.value.trim();
        const originalValue = usernameInput.getAttribute('data-original');
        usernameInput.classList.remove('is-invalid');
        usernameInput.classList.remove('is-valid');
        usernameError.style.display = 'none';

        if (value === '') {
            usernameInput.classList.add('is-invalid');
            usernameError.textContent = 'Username is required';
            usernameError.style.display = 'block';
            usernameChecked = false;
            resolve(false);
            return;
        }

        const usernameRegex = /^[a-zA-Z0-9_]{3,50}$/;
        if (!usernameRegex.test(value)) {
            usernameInput.classList.add('is-invalid');
            usernameError.textContent = 'Username must be 3-50 characters and can only contain letters, numbers, and underscores';
            usernameError.style.display = 'block';
            usernameChecked = false;
            resolve(false);
            return;
        }

        if (value === originalValue) {
            usernameInput.classList.add('is-valid');
            usernameAvailable = true;
            usernameChecked = true;
            resolve(true);
            return;
        }

        checkFieldAvailability('check_username.php', 'username', value, currentUserId, function(response) {
            usernameChecked = true;
            if (response.available) {
                usernameInput.classList.add('is-valid');
                usernameAvailable = true;
                resolve(true);
            } else {
                usernameInput.classList.add('is-invalid');
                usernameError.textContent = response.message || 'Username is already taken';
                usernameError.style.display = 'block';
                usernameAvailable = false;
                resolve(false);
            }
        });
    });
};

usernameInput.addEventListener('input', function() {
    clearTimeout(usernameCheckTimer);
    usernameCheckTimer = setTimeout(() => {
        validateUsernameField();
        checkForChanges();
    }, 600);
});

usernameInput.addEventListener('blur', function() {
    clearTimeout(usernameCheckTimer);
    validateUsernameField();
});

// Enhanced email validation with real-time availability check
const emailError = document.getElementById('email-error');
let emailAvailable = true;
let emailChecked = true;
let emailCheckTimer;

const validateEmailFieldAsync = function() {
    return new Promise((resolve) => {
        const value = emailInput.value.trim().toLowerCase();
        const originalValue = emailInput.getAttribute('data-original');
        emailInput.classList.remove('is-invalid');
        emailInput.classList.remove('is-valid');
        emailError.style.display = 'none';

        if (value === '') {
            emailInput.classList.add('is-invalid');
            emailError.textContent = 'Email address cannot be empty';
            emailError.style.display = 'block';
            emailChecked = false;
            resolve(false);
            return;
        }

        const formatResult = validateEmail(value);
        if (!formatResult.valid) {
            emailInput.classList.add('is-invalid');
            emailError.textContent = formatResult.message;
            emailError.style.display = 'block';
            emailChecked = false;
            resolve(false);
            return;
        }

        if (value === originalValue) {
            emailInput.classList.add('is-valid');
            emailAvailable = true;
            emailChecked = true;
            resolve(true);
            return;
        }

        checkFieldAvailability('check_email.php', 'email', value, currentUserId, function(response) {
            emailChecked = true;
            if (response.available) {
                emailInput.classList.add('is-valid');
                emailAvailable = true;
                resolve(true);
            } else {
                emailInput.classList.add('is-invalid');
                emailError.textContent = response.message || 'Email is already in use';
                emailError.style.display = 'block';
                emailAvailable = false;
                resolve(false);
            }
        });
    });
};

emailInput.addEventListener('input', function() {
    clearTimeout(emailCheckTimer);
    emailCheckTimer = setTimeout(() => {
        validateEmailFieldAsync();
        checkForChanges();
    }, 600);
});

emailInput.addEventListener('blur', function() {
    clearTimeout(emailCheckTimer);
    validateEmailFieldAsync();
});

// Client-side form validation
document.getElementById('editUserForm').addEventListener('submit', function(event) {
    let isValid = true;
    
    if (!validateNameField()) isValid = false;
    if (!validatePasswordField()) isValid = false;
    if (!validateMobileField()) isValid = false;
    if (!validateNICField()) isValid = false;
    if (!validateAddressField()) isValid = false;
    const usernameVal = usernameInput.value.trim();
    const usernameRegex = /^[a-zA-Z0-9_]{3,50}$/;
    if (!usernameVal || !usernameRegex.test(usernameVal)) {
        isValid = false;
    }

    const emailVal = emailInput.value.trim().toLowerCase();
    const emailFormatResult = validateEmail(emailVal);
    if (!emailVal || !emailFormatResult.valid) {
        isValid = false;
    }
    
    if (!isValid) {
        event.preventDefault();
        const firstError = document.querySelector('.is-invalid');
        if (firstError) {
            firstError.focus();
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
    }

    const needUsernameCheck = !usernameChecked || !usernameAvailable;
    const needEmailCheck = !emailChecked || !emailAvailable;

    if (needUsernameCheck || needEmailCheck) {
        event.preventDefault();
        const promises = [];
        if (needUsernameCheck) promises.push(validateUsernameField());
        if (needEmailCheck) promises.push(validateEmailFieldAsync());
        Promise.all(promises).then(function(results) {
            if (results.every(r => r === true)) {
                document.getElementById('editUserForm').submit();
            }
        });
        return;
    }

    // Check if any field has changed
    let hasChanged = false;
    const fields = ['name', 'username', 'email', 'mobile', 'nic', 'address', 'position_id'];
    for (const id of fields) {
        const el = document.getElementById(id);
        if (el && !el.disabled && el.value !== el.getAttribute('data-original')) {
            hasChanged = true;
            break;
        }
    }
    const pwEl = document.getElementById('password');
    if (pwEl && pwEl.value.trim() !== '') {
        hasChanged = true;
    }

    if (!hasChanged) {
        event.preventDefault();
    }
});

// Enable/disable submit button based on whether any field changed
function checkForChanges() {
    let hasChanged = false;
    const fields = ['name', 'username', 'email', 'mobile', 'nic', 'address', 'position_id'];
    for (const id of fields) {
        const el = document.getElementById(id);
        if (el && !el.disabled && el.value !== el.getAttribute('data-original')) {
            hasChanged = true;
            break;
        }
    }
    const pwEl = document.getElementById('password');
    if (pwEl && pwEl.value.trim() !== '') {
        hasChanged = true;
    }
    document.getElementById('submitBtn').disabled = !hasChanged;
}

// Attach listeners to all tracked fields
['name', 'username', 'email', 'mobile', 'nic', 'address', 'position_id', 'password'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', checkForChanges);
        el.addEventListener('change', checkForChanges);
    }
});


    </script>
</body>
</html>

<?php
// Close the connection at the end of the script
$conn->close();
?>