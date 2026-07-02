<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id'])) {
    header("Location: " . BASE_URL . "modules/price-lists/price_list.php");
    exit();
}

$id = intval($_GET['id']);

// Fetch Price List Info
$sql = "SELECT * FROM price_lists WHERE id = $id";
$result = $conn->query($sql);
$price_list = $result->fetch_assoc();

if (!$price_list) {
    header("Location: " . BASE_URL . "modules/price-lists/price_list.php");
    exit();
}

// Fetch existing items grouped by section_name
$itemSql = "SELECT * FROM price_list_items WHERE price_list_id = $id ORDER BY id ASC";
$itemResult = $conn->query($itemSql);

$existingGroups = [];
while ($row = $itemResult->fetch_assoc()) {
    $sectionName = $row['section_name'] ?? '';
    $existingGroups[$sectionName][] = $row;
}

// Fetch active customers for customer selection modal
$customerSql = "SELECT * FROM customers WHERE status = 'active' ORDER BY customer_id DESC";
$customerResult = $conn->query($customerSql);

// Fetch the selected customer data
$selectedCustomer = null;
if (!empty($price_list['customer_id'])) {
    $custSql = "SELECT * FROM customers WHERE customer_id = " . intval($price_list['customer_id']);
    $custResult = $conn->query($custSql);
    $selectedCustomer = $custResult->fetch_assoc();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require_once BASE_PATH . 'includes/header.php'; ?>
    <title>Edit Price List <?= htmlspecialchars($price_list['ref_no'] ?? 'PL-' . str_pad($id, 5, '0', STR_PAD_LEFT)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>css/price-list.css" rel="stylesheet" />
</head>

<body class="sb-nav-fixed">
    <?php require_once BASE_PATH . 'includes/navbar.php'; ?>
    <div id="layoutSidenav">
        <?php require_once BASE_PATH . 'includes/sidebar.php'; ?>
        <div id="layoutSidenav_content">
            <main>
                <div class="page-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5>Edit Price List <?= htmlspecialchars($price_list['ref_no'] ?? 'PL-' . str_pad($id, 5, '0', STR_PAD_LEFT)) ?></h5>
                        <p class="text-muted">Update asset pricing and regenerate the price list</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>modules/price-lists/view_price_list.php?id=<?= $id ?>" class="btn btn-outline-primary" target="_blank">
                            <i class="fas fa-eye me-1"></i> View Current
                        </a>
                        <a href="<?= BASE_URL ?>modules/price-lists/price_list.php" class="btn btn-outline-secondary">
                            <i class="fas fa-list me-1"></i> View All
                        </a>
                    </div>
                </div>
                <div class="price-list-container">
                    <form id="priceListForm" action="<?= BASE_URL ?>modules/price-lists/process_price_list_edit.php" method="POST">
                        <input type="hidden" name="price_list_id" value="<?= $id ?>">
                        
                        <!-- General Info -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="fas fa-cog text-primary" style="font-size: 18px;"></i>
                                    <h6 class="card-title m-0">General Information</h6>
                                </div>
                                 <div class="row g-3">
                                    <div class="col-md-2">
                                        <div>
                                            <label class="form-label">Currency</label>
                                            <select name="currency" class="form-select" disabled>
                                                <option value="lkr" selected>LKR</option>
                                            </select>
                                            <input type="hidden" name="currency" value="lkr">
                                        </div>
                                    </div>
                                    <div class="col-md-10">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Date</label>
                                                    <input type="date" name="price_list_date" class="form-control" value="<?= $price_list['price_list_date'] ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div>
                                                    <label class="form-label">Due Date</label>
                                                    <input type="date" name="due_date" class="form-control" value="<?= $price_list['due_date'] ?? '' ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3 mt-1">
                                    <div class="col-12">
                                        <div>
                                            <label class="form-label">Subject</label>
                                            <input type="text" class="form-control" name="subject" placeholder="Enter subject" value="<?= htmlspecialchars($price_list['subject'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Information -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-user-circle text-primary" style="font-size: 18px;"></i>
                                        <h6 class="card-title m-0">Customer Information </h6>
                                        <span class="text-muted">(Optional)</span>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="select_existing_customer">
                                        <i class="fas fa-users me-1"></i> Select Customer
                                    </button>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <input type="hidden" name="customer_id" id="customer_id" value="<?= $price_list['customer_id'] ?? '' ?>">
                                        <div>
                                            <label class="form-label">Name</label>
                                            <input type="text" class="form-control" name="customer_name"
                                                id="customer_name" placeholder="Enter customer name"
                                                value="<?= htmlspecialchars($price_list['customer_name'] ?? $selectedCustomer['name'] ?? '') ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="customer_email"
                                                id="customer_email" placeholder="Enter email address"
                                                value="<?= htmlspecialchars($price_list['customer_email'] ?? $selectedCustomer['email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="customer_phone"
                                                id="customer_phone" placeholder="Enter phone number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric"
                                                value="<?= htmlspecialchars($price_list['customer_phone'] ?? $selectedCustomer['phone'] ?? '') ?>">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label">Address</label>
                                            <input type="text" class="form-control" name="customer_address"
                                                id="customer_address" placeholder="Enter address"
                                                value="<?= htmlspecialchars($price_list['customer_address'] ?? $selectedCustomer['address'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sections -->
                        <div id="sectionsContainer">
                            <?php 
                            $groupIndex = 0;
                            if (!empty($existingGroups)):
                                foreach ($existingGroups as $sectionName => $items): ?>
                                    <div class="section-group" data-group-index="<?= $groupIndex ?>">
                                        <div class="section-group-header">
                                            <div class="d-flex align-items-center gap-2 flex-grow-1 me-3">
                                                <i class="fas fa-microchip text-primary" style="font-size: 16px;"></i>
                                                <span class="fw-semibold" style="color: #344054; font-size: 14px;">Section Name:</span>
                                                <input type="text" name="section_name[<?= $groupIndex ?>]" class="form-control" value="<?= htmlspecialchars($sectionName) ?>" placeholder="Enter section name" style="max-width: 300px;">
                                            </div>
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-section-group">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <div class="section-group-body">
                                            <div class="items-container">
                                                <div class="row fw-semibold mb-2 d-none d-md-flex" style="font-size: 12px; color: #667085; text-transform: uppercase; letter-spacing: 0.05em;">
                                                    <div class="col-md-1">#</div>
                                                    <div class="col-md-3">Item Name</div>
                                                    <div class="col-md-5">Description</div>
                                                    <div class="col-md-2 text-end">Price</div>
                                                    <div class="col-md-1"></div>
                                                </div>
                                                
                                                <div class="item-list">
                                                    <?php foreach ($items as $index => $item): ?>
                                                        <div class="row item-row">
                                                            <div class="col-md-1 d-flex align-items-center">
                                                                <span class="row-number fw-semibold" style="font-size: 13px; color: #667085;"><?= $index + 1 ?></span>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <input type="text" name="item_name[<?= $groupIndex ?>][]" class="form-control" value="<?= htmlspecialchars($item['item_name']) ?>" required>
                                                            </div>
                                                            <div class="col-md-5">
                                                                <input type="text" name="item_description[<?= $groupIndex ?>][]" class="form-control" value="<?= htmlspecialchars($item['description']) ?>">
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="number" name="item_price[<?= $groupIndex ?>][]" class="form-control text-end" min="0" step="0.01" value="<?= $item['price'] ?>" required>
                                                            </div>
                                                            <div class="col-md-1 text-end">
                                                                <button type="button" class="btn btn-link text-danger remove-item p-0 mt-2">
                                                                    <i class="fas fa-minus-circle"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-outline-primary btn-sm add-item">
                                                        <i class="fas fa-plus me-1"></i> Add Item
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php 
                                    $groupIndex++;
                                endforeach; 
                            else: ?>
                                <div class="section-group" data-group-index="0">
                                    <div class="section-group-header">
                                        <div class="d-flex align-items-center gap-2 flex-grow-1 me-3">
                                            <i class="fas fa-microchip text-primary" style="font-size: 16px;"></i>
                                            <span class="fw-semibold" style="color: #344054; font-size: 14px;">Section Name:</span>
                                            <input type="text" name="section_name[0]" class="form-control" placeholder="Enter section name" style="max-width: 300px;">
                                        </div>
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-section-group">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="section-group-body">
                                        <div class="items-container">
                                            <div class="row fw-semibold mb-2 d-none d-md-flex" style="font-size: 12px; color: #667085; text-transform: uppercase; letter-spacing: 0.05em;">
                                                <div class="col-md-1">#</div>
                                                <div class="col-md-3">Item Name</div>
                                                <div class="col-md-5">Description</div>
                                                <div class="col-md-2 text-end">Price</div>
                                                <div class="col-md-1"></div>
                                            </div>
                                            <div class="item-list">
                                                <div class="row item-row">
                                                    <div class="col-md-1 d-flex align-items-center">
                                                        <span class="row-number fw-semibold" style="font-size: 13px; color: #667085;">1</span>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <input type="text" name="item_name[0][]" class="form-control" placeholder="Enter product or service name" required>
                                                    </div>
                                                    <div class="col-md-5">
                                                        <input type="text" name="item_description[0][]" class="form-control" placeholder="Enter details">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <input type="number" name="item_price[0][]" class="form-control text-end" min="0" step="0.01" placeholder="0.00" required>
                                                    </div>
                                                    <div class="col-md-1 text-end">
                                                        <button type="button" class="btn btn-link text-danger remove-item p-0 mt-2">
                                                            <i class="fas fa-minus-circle"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <button type="button" class="btn btn-outline-primary btn-sm add-item">
                                                    <i class="fas fa-plus me-1"></i> Add Item
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php $groupIndex = 1; ?>
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <button type="button" id="btnAddSectionGroup" class="btn btn-outline-success">
                                <i class="fas fa-plus-circle me-1"></i> Add Another Section
                            </button>
                        </div>

                        <!-- Notes & Footer -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <div>
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="8" placeholder="Enter additional notes"><?= htmlspecialchars($price_list['notes']) ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Payment Terms</label>
                                            <textarea name="payment_terms" class="form-control" rows="6" placeholder="Enter payment terms"><?= htmlspecialchars($price_list['payment_terms']) ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div>
                                            <label class="form-label">Terms & Conditions</label>
                                            <textarea name="terms_conditions" class="form-control" rows="6" placeholder="Enter terms and conditions"><?= htmlspecialchars($price_list['terms_conditions']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2 pt-3">
                                    <a href="<?= BASE_URL ?>modules/price-lists/price_list.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" id="submitBtn" class="btn btn-primary" disabled>
                                        <i class="fas fa-save me-1"></i> Update Price List
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

            <!-- Customer Selection Modal -->
            <div id="customerModal" class="customer-modal">
                <div class="customer-modal-content">
                    <div class="modal-header-sticky d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas fa-users text-primary" style="font-size: 20px;"></i>
                            <h5 class="m-0 fw-bold" style="font-size: 16px;">Select Customer</h5>
                        </div>
                        <span class="close-modal" style="cursor:pointer;font-size:22px;line-height:1;color:#98a2b3;">&times;</span>
                    </div>
                    <div class="modal-body-scroll">
                        <div class="input-group mb-4">
                            <span class="input-group-text"><i class="fas fa-search" style="color:#98a2b3;"></i></span>
                            <input type="text" id="customerSearch" class="form-control"
                                placeholder="Search by name, email, phone, or business...">
                            <button class="btn btn-primary" type="button" id="searchCustomerBtn"><i class="fas fa-search me-1"></i> Search</button>
                            <button class="btn btn-outline-secondary" type="button" id="clearSearchBtn"><i class="fas fa-times me-1"></i> Clear</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th>Business Name</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Address</th>
                                        <th style="width: 80px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $customerResult->data_seek(0);
                                    while ($customer = $customerResult->fetch_assoc()): ?>
                                        <tr class="customer-row" data-id="<?= $customer['customer_id'] ?? '' ?>"
                                            data-name="<?= htmlspecialchars($customer['name'] ?? '') ?>"
                                            data-email="<?= htmlspecialchars($customer['email'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($customer['phone'] ?? '') ?>"
                                            data-address="<?= htmlspecialchars($customer['address'] ?? '') ?>">
                                            <td class="text-muted" style="font-size: 13px;"><?= htmlspecialchars($customer['customer_id'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($customer['business_name'] ?? '') ?></td>
                                            <td><span class="fw-medium"><?= htmlspecialchars($customer['name'] ?? '') ?></span></td>
                                            <td><span style="font-size: 13px;"><?= htmlspecialchars($customer['email'] ?? '') ?></span></td>
                                            <td><span style="font-size: 13px;"><?= htmlspecialchars($customer['phone'] ?? '') ?></span></td>
                                            <td><span style="font-size: 13px;"><?= htmlspecialchars($customer['address'] ?? '') ?></span></td>
                                            <td>
                                                <button type="button"
                                                    class="btn btn-sm btn-primary select-customer-btn px-3"
                                                    style="font-size: 12px;">Select</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/scripts.js"></script>
    <script>
        $(document).ready(function() {
            let groupCount = <?= $groupIndex ?>;

            // Auto-calculate due date when date changes
            $('input[name="price_list_date"]').on('change', function() {
                const qDate = new Date($(this).val());
                if (!isNaN(qDate.getTime())) {
                    const expDate = new Date(qDate);
                    expDate.setDate(expDate.getDate() + 30);
                    $('input[name="due_date"]').val(expDate.toISOString().split('T')[0]);
                }
            });

            $('#priceListForm').on('submit', function(e) {
                e.preventDefault();
                let form = this;
                let formData = new FormData(form);
                formData.append('ajax', '1');
                $.ajax({
                    url: $(form).attr('action'),
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            window.open('view_price_list.php?id=' + response.id + '&updated=1', '_blank');
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated!',
                                text: 'Price list has been updated successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            });
                            initialFormData = $('#priceListForm').serialize();
                            checkFormChanges();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.error || 'Failed to update price list.'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An unexpected error occurred.'
                        });
                    }
                });
            });

            // Customer Selection Modal
            var customerModal = document.getElementById("customerModal");
            $("#select_existing_customer").click(function () { customerModal.style.display = "block"; });
            $(".close-modal").click(function () { customerModal.style.display = "none"; });
            $(window).click(function (event) { if (event.target == customerModal) customerModal.style.display = "none"; });

            function filterCustomers() {
                var value = $("#customerSearch").val().toLowerCase();
                $(".customer-row").filter(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                });
            }
            $("#customerSearch").on("keypress", function (e) {
                if (e.which == 13) filterCustomers();
            });
            $("#searchCustomerBtn").on("click", filterCustomers);
            $("#clearSearchBtn").on("click", function () {
                $("#customerSearch").val("");
                $(".customer-row").show();
            });

            $(document).on("click", ".select-customer-btn", function () {
                var row = $(this).closest('tr');
                $('#customer_id').val(row.data('id'));
                $('#customer_name').val(row.data('name'));
                $('#customer_email').val(row.data('email'));
                $('#customer_phone').val(row.data('phone'));
                $('#customer_address').val(row.data('address'));
                customerModal.style.display = "none";
                checkFormChanges();
            });

            // Add Section
            $('#btnAddSectionGroup').click(function() {
                let index = groupCount++;
                let newGroup = `
                <div class="section-group" data-group-index="${index}">
                    <div class="section-group-header">
                        <div class="d-flex align-items-center gap-2 flex-grow-1 me-3">
                            <i class="fas fa-microchip text-primary" style="font-size: 16px;"></i>
                            <span class="fw-semibold" style="color: #344054; font-size: 14px;">Section Name:</span>
                            <input type="text" name="section_name[${index}]" class="form-control" placeholder="Enter section name" style="max-width: 300px;">
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm remove-section-group">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <div class="section-group-body">
                        <div class="items-container">
                            <div class="row fw-semibold mb-2 d-none d-md-flex" style="font-size: 12px; color: #667085; text-transform: uppercase; letter-spacing: 0.05em;">
                                <div class="col-md-1">#</div>
                                <div class="col-md-3">Item Name</div>
                                <div class="col-md-5">Description</div>
                                <div class="col-md-2 text-end">Price</div>
                                <div class="col-md-1"></div>
                            </div>
                            <div class="item-list">
                                <div class="row item-row">
                                    <div class="col-md-1 d-flex align-items-center">
                                        <span class="row-number fw-semibold" style="font-size: 13px; color: #667085;">1</span>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" name="item_name[${index}][]" class="form-control" placeholder="Enter product or service name" required>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="item_description[${index}][]" class="form-control" placeholder="Enter details">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" name="item_price[${index}][]" class="form-control text-end" min="0" step="0.01" placeholder="0.00" required>
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button type="button" class="btn btn-link text-danger remove-item p-0 mt-2">
                                            <i class="fas fa-minus-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-primary btn-sm add-item">
                                    <i class="fas fa-plus me-1"></i> Add Item
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
                
                $('#sectionsContainer').append(newGroup);
                checkFormChanges();
            });

            // Remove Section
            $(document).on('click', '.remove-section-group', function() {
                if ($('.section-group').length > 1) {
                    $(this).closest('.section-group').remove();
                } else {
                    alert('You must have at least one section.');
                }
                checkFormChanges();
            });

            // Add Item within Group
            $(document).on('click', '.add-item', function() {
                let group = $(this).closest('.section-group');
                let index = group.data('group-index');
                let itemCount = group.find('.item-list .item-row').length + 1;
                let newItem = `
                <div class="row item-row">
                    <div class="col-md-1 d-flex align-items-center">
                        <span class="row-number fw-semibold" style="font-size: 13px; color: #667085;">${itemCount}</span>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="item_name[${index}][]" class="form-control" placeholder="Enter product or service name" required>
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="item_description[${index}][]" class="form-control" placeholder="Enter details">
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="item_price[${index}][]" class="form-control text-end" min="0" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-link text-danger remove-item p-0 mt-2">
                            <i class="fas fa-minus-circle"></i>
                        </button>
                    </div>
                </div>`;
                group.find('.item-list').append(newItem);
                checkFormChanges();
            });

            // Remove Item
            $(document).on('click', '.remove-item', function() {
                let itemList = $(this).closest('.item-list');
                if (itemList.find('.item-row').length > 1) {
                    $(this).closest('.item-row').remove();
                    renumberRows($(this).closest('.section-group'));
                } else {
                    alert('Each section must have at least one item.');
                }
                checkFormChanges();
            });

            // Renumber rows within a group
            function renumberRows(group) {
                group.find('.item-list .item-row').each(function(index) {
                    $(this).find('.row-number').text(index + 1);
                });
            }

            // --- Change detection for submit button ---
            let initialFormData = $('#priceListForm').serialize();

            function checkFormChanges() {
                let current = $('#priceListForm').serialize();
                let hasChanges = current !== initialFormData;
                $('#submitBtn').prop('disabled', !hasChanges);
            }

            $('#priceListForm').on('change input', 'input, select, textarea', checkFormChanges);
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>