<?php
require_once __DIR__ . '/../../config/paths.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    $_SESSION['error_message'] = "Access denied. Admin privileges required.";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

$upload_dir = BASE_PATH . 'uploads/company/';
$upload_url = BASE_URL . 'uploads/company/';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid CSRF token. Please try again.";
        header("Location: " . BASE_URL . "modules/settings/company_settings.php");
        exit();
    }

    $company_name = trim($_POST['company_name'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $bank_name    = trim($_POST['bank_name'] ?? '');
    $bank_branch  = trim($_POST['bank_branch'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_type = trim($_POST['account_type'] ?? '');

    $errors = [];
    if ($company_name === '' || strlen($company_name) < 2) {
        $errors[] = "Company name is required.";
    }
    if ($phone === '') {
        $errors[] = "Phone number is required.";
    }
    if ($email === '') {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address format.";
    }
    if ($address === '') {
        $errors[] = "Company address is required.";
    }
    $remove_logo    = ($_POST['remove_logo'] === '1');
    $remove_favicon = ($_POST['remove_favicon'] === '1');

    $current = $conn->query("SELECT logo_path, favicon_path FROM company_settings WHERE id = 1")->fetch_assoc();

    // Start with existing values, then override if needed
    $logo_path    = $current['logo_path'] ?? null;
    $favicon_path = $current['favicon_path'] ?? null;

    // Handle removal flags (if no new file uploaded)
    if ($remove_logo && (!isset($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE)) {
        if (!empty($current['logo_path']) && file_exists(BASE_PATH . $current['logo_path'])) {
            @unlink(BASE_PATH . $current['logo_path']);
        }
        $logo_path = null;
    }
    if ($remove_favicon && (!isset($_FILES['favicon']) || $_FILES['favicon']['error'] === UPLOAD_ERR_NO_FILE)) {
        if (!empty($current['favicon_path']) && file_exists(BASE_PATH . $current['favicon_path'])) {
            @unlink(BASE_PATH . $current['favicon_path']);
        }
        $favicon_path = null;
    }

    $allowed_mime = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'];
    $max_size = 2 * 1024 * 1024;

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['logo']['size'] > $max_size) {
            $errors[] = "Logo file is too large (max 2MB).";
        } elseif (!in_array(mime_content_type($_FILES['logo']['tmp_name']), $allowed_mime)) {
            $errors[] = "Logo must be an image (PNG, JPG, GIF, SVG, WEBP).";
        } else {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])) {
                $errors[] = "Invalid logo file extension.";
            } else {
                $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                    if (!empty($current['logo_path']) && file_exists(BASE_PATH . $current['logo_path'])) {
                        @unlink(BASE_PATH . $current['logo_path']);
                    }
                    $logo_path = 'uploads/company/' . $filename;
                } else {
                    $errors[] = "Failed to save logo file.";
                }
            }
        }
    } elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = "Logo upload error (code " . intval($_FILES['logo']['error']) . ").";
    }

    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['favicon']['size'] > $max_size) {
            $errors[] = "Favicon file is too large (max 2MB).";
        } elseif (!in_array(mime_content_type($_FILES['favicon']['tmp_name']), $allowed_mime)) {
            $errors[] = "Favicon must be an image (PNG, ICO, JPG, SVG).";
        } else {
            $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'ico', 'svg'])) {
                $errors[] = "Invalid favicon file extension.";
            } else {
                $filename = 'favicon_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['favicon']['tmp_name'], $dest)) {
                    if (!empty($current['favicon_path']) && file_exists(BASE_PATH . $current['favicon_path'])) {
                        @unlink(BASE_PATH . $current['favicon_path']);
                    }
                    $favicon_path = 'uploads/company/' . $filename;
                } else {
                    $errors[] = "Failed to save favicon file.";
                }
            }
        }
    } elseif (isset($_FILES['favicon']) && $_FILES['favicon']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = "Favicon upload error (code " . intval($_FILES['favicon']['error']) . ").";
    }

    if (!empty($errors)) {
        $_SESSION['error_message'] = implode(' ', $errors);
        header("Location: " . BASE_URL . "modules/settings/company_settings.php");
        exit();
    }

    $conn->begin_transaction();
    try {
        // Check if logo/favicon paths changed from current values
        $logo_changed    = ($logo_path !== ($current['logo_path'] ?? null));
        $favicon_changed = ($favicon_path !== ($current['favicon_path'] ?? null));
        
        $sql = "UPDATE company_settings SET
                    company_name = ?, address = ?, phone = ?, email = ?,
                    logo_path = ?,
                    favicon_path = ?,
                    bank_name = ?, bank_branch = ?, account_name = ?, account_number = ?, account_type = ?,
                    updated_at = NOW(), updated_by = ?
                WHERE id = 1";
        $stmt = $conn->prepare($sql);
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param("sssssssssssi",
            $company_name, $address, $phone, $email,
            $logo_path, $favicon_path,
            $bank_name, $bank_branch, $account_name, $account_number, $account_type,
            $uid
        );

        if (!$stmt->execute()) {
            throw new Exception("Update failed: " . $stmt->error);
        }
        $stmt->close();

        $log_sql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, 'update_company_settings', 0, ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $details = "Company settings updated by user ID #{$_SESSION['user_id']} ({$_SESSION['name']})";
        $log_stmt->bind_param("is", $_SESSION['user_id'], $details);
        $log_stmt->execute();
        $log_stmt->close();

        $conn->commit();
        $_SESSION['success_message'] = "Company settings updated successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Failed to save settings: " . $e->getMessage();
    }

    header("Location: " . BASE_URL . "modules/settings/company_settings.php");
    exit();
}

$settings = $conn->query("SELECT * FROM company_settings WHERE id = 1")->fetch_assoc();
if (!$settings) {
    $conn->query("INSERT IGNORE INTO company_settings (id, company_name, logo_path, favicon_path) VALUES (1, '', NULL, NULL)");
    $settings = $conn->query("SELECT * FROM company_settings WHERE id = 1")->fetch_assoc();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$successMsg = $_SESSION['success_message'] ?? '';
$errorMsg = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Company Settings - BMS</title>
    <link href="<?= BASE_URL ?>css/forms.css" rel="stylesheet" />
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    <style>
        body {
            background-color: #f8fafc;
        }

        .preview-box {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            background-color: #f8fafc;
            margin-top: 8px;
        }

        .preview-img-container {
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #fff;
            overflow: hidden;
        }

        .preview-img-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .preview-info {
            font-size: 12px;
            color: #64748b;
        }

        .preview-title {
            font-weight: 600;
            color: #334155;
            margin-bottom: 2px;
        }

        .custom-file-input {
            border: 1px solid #d8e2ef;
            padding: 8px 12px;
            border-radius: 8px;
            background: #fff;
            width: 100%;
        }

        .custom-file-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .error-feedback {
            display: none;
            color: #dc3545;
            font-size: 12px;
            margin-top: 4px;
        }

        .form-control.is-invalid,
        .form-select.is-invalid,
        textarea.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.1);
        }

        .form-control.is-valid,
        .form-select.is-valid,
        textarea.is-valid {
            border-color: #198754;
        }

        .save-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: blur(0.5px);
            pointer-events: none;
        }

        .remove-btn {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(220, 53, 69, 0.9);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            padding: 0;
            line-height: 1;
        }

        .remove-btn:hover {
            background: #dc3545;
            transform: scale(1.1);
        }

        .preview-img-container {
            position: relative;
        }
    </style>
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>

        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5>Company Settings</h5>
                        <p class="text-muted">Manage company details, upload logo and favicon, and configure bank account info</p>
                    </div>
                </div>

                <!-- Success/Error Toast Messages -->
                <?php if (!empty($successMsg)): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('success', '<?php echo addslashes($successMsg); ?>'); });</script>
                <?php endif; ?>

                <?php if (!empty($errorMsg)): ?>
                    <script>document.addEventListener('DOMContentLoaded', function() { showToast('error', '<?php echo addslashes($errorMsg); ?>'); });</script>
                <?php endif; ?>

                <div class="card" style="margin: 0 32px 32px 32px; border: 1px solid #eaecf0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); background: #fff;">
                    <div class="card-body" style="padding: 28px 32px;">
                        <form method="POST" action="<?= BASE_URL ?>modules/settings/company_settings.php" id="companySettingsForm" enctype="multipart/form-data" novalidate>
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="remove_logo" id="remove_logo" value="">
                            <input type="hidden" name="remove_favicon" id="remove_favicon" value="">

                            <div class="row">
                                <!-- Company Profile Section (Left Column) -->
                                <div class="col-md-6 mb-4">
                                    <div class="premium-section-header">
                                        <i class="fas fa-building me-2"></i> Company Profile
                                    </div>

                                    <!-- Company Name -->
                                    <div class="mb-3">
                                        <label for="company_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="company_name" name="company_name"
                                            value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" placeholder="Enter Company Name" required>
                                        <div class="error-feedback" id="company-name-error"></div>
                                    </div>

                                    <!-- Phone -->
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="phone" name="phone"
                                            value="<?= htmlspecialchars($settings['phone'] ?? '') ?>" placeholder="Enter Contact Number" required>
                                        <div class="error-feedback" id="phone-error"></div>
                                    </div>

                                    <!-- Email -->
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            value="<?= htmlspecialchars($settings['email'] ?? '') ?>" placeholder="info@example.com" required>
                                        <div class="error-feedback" id="email-error"></div>
                                    </div>

                                    <!-- Address -->
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Company Address <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="address" name="address" rows="3"
                                            placeholder="Enter Full Address" required><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
                                        <div class="error-feedback" id="address-error"></div>
                                    </div>
                                </div>

                                <!-- Logo & Icons Section (Right Column) -->
                                <div class="col-md-6 mb-4">
                                    <div class="premium-section-header">
                                        <i class="fas fa-image me-2"></i> Logo & Icons
                                    </div>

                                    <!-- Company Logo File Input -->
                                    <div class="mb-4">
                                        <label for="logo" class="form-label">Company Logo (PNG/JPG/JPEG, max 2MB)</label>
                                        <input type="file" class="custom-file-input" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp,.gif,.svg">

                                        <!-- Logo Preview Box -->
                                        <div class="preview-box">
                                            <div class="preview-img-container" id="logo-preview-container">
                                                <?php if (!empty($settings['logo_path']) && file_exists(BASE_PATH . $settings['logo_path'])): ?>
                                                    <img src="<?= BASE_URL . htmlspecialchars($settings['logo_path']) ?>?v=<?= time() ?>" id="logo-preview" alt="Logo Preview">
                                                    <button type="button" class="remove-btn" onclick="confirmRemove('logo')" title="Remove Logo">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <img src="" id="logo-preview" alt="Logo Preview" style="display:none;">
                                                    <i class="fas fa-image" style="font-size: 24px; color: #cbd5e1;"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="preview-info">
                                                <div class="preview-title" id="logo-filename">
                                                    <?= !empty($settings['logo_path']) ? 'Current Logo' : 'No logo uploaded' ?>
                                                </div>
                                                <div id="logo-filesize">
                                                    <?= !empty($settings['logo_path']) ? 'Using stored logo asset' : 'Upload a company logo' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Favicon File Input -->
                                    <div class="mb-4">
                                        <label for="favicon" class="form-label">Favicon Icon (ICO/PNG/JPG, max 1MB)</label>
                                        <input type="file" class="custom-file-input" id="favicon" name="favicon" accept=".png,.jpg,.jpeg,.ico,.webp,.svg">

                                        <!-- Favicon Preview Box -->
                                        <div class="preview-box">
                                            <div class="preview-img-container" id="favicon-preview-container" style="width: 48px; height: 48px;">
                                                <?php if (!empty($settings['favicon_path']) && file_exists(BASE_PATH . $settings['favicon_path'])): ?>
                                                    <img src="<?= BASE_URL . htmlspecialchars($settings['favicon_path']) ?>?v=<?= time() ?>" id="favicon-preview" alt="Favicon Preview" style="max-height: 24px; max-width: 24px;">
                                                    <button type="button" class="remove-btn" onclick="confirmRemove('favicon')" title="Remove Favicon" style="width: 18px; height: 18px; font-size: 8px;">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <img src="" id="favicon-preview" alt="Favicon Preview" style="display:none; max-height: 24px; max-width: 24px;">
                                                    <i class="fas fa-star" style="font-size: 16px; color: #cbd5e1;"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="preview-info">
                                                <div class="preview-title" id="favicon-filename">
                                                    <?= !empty($settings['favicon_path']) ? 'Current Favicon' : 'No favicon uploaded' ?>
                                                </div>
                                                <div id="favicon-filesize">
                                                    <?= !empty($settings['favicon_path']) ? 'Using stored favicon asset' : 'Upload a favicon icon' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bank Account Details Section (Full Width) -->
                                <div class="col-12 border-top pt-4">
                                    <div class="premium-section-header mb-3">
                                        <i class="fas fa-university me-2"></i> Billing & Bank Details
                                    </div>

                                    <div class="row">
                                        <!-- Bank Name -->
                                        <div class="col-md-6 mb-3">
                                            <label for="bank_name" class="form-label">Bank Name</label>
                                            <input type="text" class="form-control" id="bank_name" name="bank_name"
                                                value="<?= htmlspecialchars($settings['bank_name'] ?? '') ?>" placeholder="Bank Name">
                                        </div>

                                        <!-- Bank Branch -->
                                        <div class="col-md-6 mb-3">
                                            <label for="bank_branch" class="form-label">Bank Branch</label>
                                            <input type="text" class="form-control" id="bank_branch" name="bank_branch"
                                                value="<?= htmlspecialchars($settings['bank_branch'] ?? '') ?>" placeholder="Bank Branch">
                                        </div>

                                        <!-- Account Holder Name -->
                                        <div class="col-md-6 mb-3">
                                            <label for="account_name" class="form-label">Account Holder Name</label>
                                            <input type="text" class="form-control" id="account_name" name="account_name"
                                                value="<?= htmlspecialchars($settings['account_name'] ?? '') ?>" placeholder="Your Company Account Name">
                                        </div>

                                        <!-- Account Number -->
                                        <div class="col-md-6 mb-3">
                                            <label for="account_number" class="form-label">Account Number</label>
                                            <input type="text" class="form-control" id="account_number" name="account_number"
                                                value="<?= htmlspecialchars($settings['account_number'] ?? '') ?>" placeholder="Your Company Account Number">
                                        </div>

                                        <!-- Account Type -->
                                        <div class="col-md-6 mb-3">
                                            <label for="account_type" class="form-label">Account Type</label>
                                            <select class="form-select" id="account_type" name="account_type">
                                                <option value="">-- Select Account Type --</option>
                                                <option value="Current" <?= (isset($settings['account_type']) && $settings['account_type'] === 'Current') ? 'selected' : '' ?>>Current Account</option>
                                                <option value="Savings" <?= (isset($settings['account_type']) && $settings['account_type'] === 'Savings') ? 'selected' : '' ?>>Savings Account</option>
                                                <option value="Checking" <?= (isset($settings['account_type']) && $settings['account_type'] === 'Checking') ? 'selected' : '' ?>>Checking Account</option>
                                                <option value="Business" <?= (isset($settings['account_type']) && $settings['account_type'] === 'Business') ? 'selected' : '' ?>>Business Account</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="row mt-4 pt-3 border-top">
                                <div class="col-12 d-flex justify-content-end gap-3">
                                    <a href="<?= BASE_URL ?>index.php" class="back-btn text-decoration-none d-flex align-items-center">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                    </a>
                                    <button type="submit" class="save-btn" id="submitBtn" disabled>
                                        <i class="fas fa-save me-2"></i> Save Settings
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- jQuery & JS dependencies -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize Select2 Account Type selector
        $('#account_type').select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumResultsForSearch: Infinity
        });

        // Setup File Upload Live Previews
        setupFilePreview('logo', 'logo-preview', 'logo-filename', 'logo-filesize');
        setupFilePreview('favicon', 'favicon-preview', 'favicon-filename', 'favicon-filesize');
    });

    /**
     * File Preview Handler using FileReader
     */
    function setupFilePreview(inputId, previewImgId, nameId, sizeId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewImgId);
        const filenameLabel = document.getElementById(nameId);
        const sizeLabel = document.getElementById(sizeId);

        if (!input || !preview) return;

        input.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // Validate file size
                const maxSize = inputId === 'favicon' ? 1024 * 1024 : 2 * 1024 * 1024; // 1MB or 2MB
                if (file.size > maxSize) {
                    showToast('error', `File size exceeds the limit. Choose a file under ${inputId === 'favicon' ? '1MB' : '2MB'}.`);
                    this.value = ''; // Reset file input
                    return;
                }

                // Read file for preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'inline-block';
                    // Hide placeholder icon if present
                    const container = document.getElementById(inputId + '-preview-container');
                    const icon = container.querySelector('i.fas');
                    if (icon) icon.style.display = 'none';
                };
                reader.readAsDataURL(file);

                // Set filename and size text
                filenameLabel.textContent = file.name;
                const sizeKb = (file.size / 1024).toFixed(1);
                sizeLabel.textContent = `${sizeKb} KB (Ready to upload)`;

                // Update button state
                updateButtonState();
            }
        });
    }

    /**
     * Client-side form validation helper functions
     */
    function setupValidation(inputId, errorId, fieldName) {
        const input = document.getElementById(inputId);
        const errorEl = document.getElementById(errorId);

        if (!input || !errorEl) return () => true;

        const validate = () => {
            input.classList.remove('is-invalid', 'is-valid');
            errorEl.style.display = 'none';

            const val = input.value.trim();
            if (input.hasAttribute('required') && val === '') {
                input.classList.add('is-invalid');
                errorEl.textContent = `${fieldName} is required`;
                errorEl.style.display = 'block';
                return false;
            }

            if (inputId === 'email' && val !== '') {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(val)) {
                    input.classList.add('is-invalid');
                    errorEl.textContent = 'Please enter a valid email address';
                    errorEl.style.display = 'block';
                    return false;
                }
            }

            input.classList.add('is-valid');
            return true;
        };

        input.addEventListener('blur', validate);
        input.addEventListener('keyup', function() {
            if (input.classList.contains('is-invalid')) {
                validate();
            }
        });

        return validate;
    }

    // Initialize validations
    const validateCompanyName = setupValidation('company_name', 'company-name-error', 'Company Name');
    const validatePhone = setupValidation('phone', 'phone-error', 'Phone Number');
    const validateEmail = setupValidation('email', 'email-error', 'Email Address');
    const validateAddress = setupValidation('address', 'address-error', 'Company Address');

    // ---- Change tracking & button state ----
    const form = document.getElementById('companySettingsForm');
    const submitBtn = document.getElementById('submitBtn');

    function getFormData() {
        const data = {};
        const formData = new FormData(form);
        for (let [key, value] of formData.entries()) {
            if (key === 'remove_logo' || key === 'remove_favicon') continue;
            if (value instanceof File) {
                data[key] = value.name || '';
            } else {
                data[key] = value || '';
            }
        }
        return data;
    }

    const initialData = getFormData();

    function hasChanges() {
        const currentData = getFormData();
        const currentRemoveLogo = document.getElementById('remove_logo').value;
        const currentRemoveFavicon = document.getElementById('remove_favicon').value;

        let changed = false;
        for (let key in initialData) {
            if (currentData[key] !== initialData[key]) {
                changed = true;
                break;
            }
        }
        if (!changed && (currentRemoveLogo === '1' || currentRemoveFavicon === '1')) {
            changed = true;
        }
        return changed;
    }

    function updateButtonState() {
        submitBtn.disabled = !hasChanges();
    }

    // Bind change/input events to all form fields
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(function(input) {
        if (input.type === 'file') {
            input.addEventListener('change', function() {
                setTimeout(updateButtonState, 100);
            });
        } else if (input.type === 'hidden' && (input.name === 'remove_logo' || input.name === 'remove_favicon')) {
            const observer = new MutationObserver(updateButtonState);
            observer.observe(input, { attributes: true, attributeFilter: ['value'] });
        } else {
            input.addEventListener('input', updateButtonState);
            input.addEventListener('change', updateButtonState);
        }
    });

    // Remove logo/favicon handler with SweetAlert2
    window.confirmRemove = function(type) {
        const label = type === 'logo' ? 'Logo' : 'Favicon';
        Swal.fire({
            title: `Remove ${label}?`,
            text: `Are you sure you want to remove the ${label.toLowerCase()}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, remove it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('remove_' + type).value = '1';
                const fileInput = document.getElementById(type);
                if (fileInput) fileInput.value = '';
                const container = document.getElementById(type + '-preview-container');
                const preview = document.getElementById(type + '-preview');
                const removeBtn = container.querySelector('.remove-btn');

                if (preview) {
                    preview.src = '';
                    preview.style.display = 'none';
                }
                if (removeBtn) {
                    removeBtn.style.display = 'none';
                }
                // Show placeholder icon
                let icon = container.querySelector('i.fas');
                if (!icon) {
                    icon = document.createElement('i');
                    icon.className = type === 'logo' ? 'fas fa-image' : 'fas fa-star';
                    icon.style.fontSize = type === 'logo' ? '24px' : '16px';
                    icon.style.color = '#cbd5e1';
                    container.appendChild(icon);
                } else {
                    icon.style.display = 'inline-block';
                }

                document.getElementById(type + '-filename').textContent = 'Will be removed on save';
                document.getElementById(type + '-filesize').textContent = 'Click Save Settings to confirm';

                showToast('info', label + ' will be removed when you save.');
                updateButtonState();
            }
        });
    };

    // Submit Validation Check
    document.getElementById('companySettingsForm').addEventListener('submit', function(e) {
        let isValid = true;
        if (!validateCompanyName()) isValid = false;
        if (!validatePhone()) isValid = false;
        if (!validateEmail()) isValid = false;
        if (!validateAddress()) isValid = false;

        if (!isValid) {
            e.preventDefault();
            const firstErr = document.querySelector('.is-invalid');
            if (firstErr) {
                firstErr.focus();
                firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    });
    </script>
</body>

</html>
<?php $conn->close(); ?>
