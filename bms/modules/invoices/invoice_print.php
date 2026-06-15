<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}
require_once BASE_PATH . 'includes/db_connection.php';

$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$company = getCompanyInfo($conn);

// Fetch invoice data
$invoice = null;
$items = [];
if ($invoice_id > 0) {
    $stmt = $conn->prepare("SELECT i.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address
        FROM invoices i LEFT JOIN customers c ON i.customer_id = c.customer_id WHERE i.invoice_id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();

    $itemStmt = $conn->prepare("SELECT ii.*, p.name as product_name, p.description as product_description
        FROM invoice_items ii JOIN products p ON ii.product_id = p.id WHERE ii.invoice_id = ?");
    $itemStmt->bind_param("i", $invoice_id);
    $itemStmt->execute();
    $itemsResult = $itemStmt->get_result();
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= $invoice_id ?: 'Print' ?><?= !empty($company['company_name']) ? ' - ' . htmlspecialchars($company['company_name']) : '' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="<?= BASE_URL ?>css/styles.css" rel="stylesheet" />
    <style>
        .table-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 20px 0;
            position: relative;
        }

        .alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25em 0.6em;
            font-size: 0.75rem;
            font-weight: 700;
            border-radius: 0.25rem;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
        }

        .message-cell {
            max-width: 200px;
            position: relative;
        }

        .message-content {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .spinner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        body {
            background-color: #f4f6f9;
            /* Light grey background */
            color: #333;
            /* Dark text color for readability */
        }

        .table-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 20px 0;
            position: relative;
        }

        .table {
            border-collapse: collapse;
            width: 100%;
            background: white;
        }

        .table th {
            background: linear-gradient(to right, #4CAF50, #17a2b8);
            /* Green to blue gradient */
            color: white;
            text-align: left;
            padding: 10px;
        }

        .table thead {
            background: linear-gradient(to right, #4CAF50, #17a2b8);
            /* Green to blue gradient */
        }

        .table thead th {
            color: white;
            text-align: left;
            padding: 10px;
            border: none;
            /* Remove individual column borders */
            background: none;
            /* Ensure no override from individual th */
        }


        .table td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f9f9f9;
            /* Light grey for alternating rows */
        }

        .table-hover tbody tr:hover {
            background-color: #e9ecef;
            /* Highlight on hover */
        }

        .action-buttons .btn {
            padding: 5px 10px;
            font-size: 0.875rem;
            border-radius: 4px;
        }

        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }

        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
        }

        /* Sidebar Customization */
        .sb-sidenav {
            background-color: #343a40;
            /* Dark grey sidebar */
            color: white;
        }

        .sb-sidenav .nav-link {
            color: rgba(255, 255, 255, 0.75);
        }

        .sb-sidenav .nav-link:hover {
            color: white;
        }

        .sb-topnav {
            background-color: #343a40;
            /* Dark navbar */
        }

        @media print {
    @page {
        size: A4;
        margin: 0.4in;
    }

    body {
        background-color: #fff;
        margin: 0;
        padding: 0;
    }

    .table thead {
        display: table-header-group;
    }

    .table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .table thead tr {
        background: #4a4a4a !important;
        color: white !important;
    }

    .btn {
        border: 1px solid #000 !important;
        color: black !important;
    }

    .d-md-flex {
        display: flex !important;
    }
}

    </style>
</head>
<body>
    <div class="row">
    <div class="col-12 col-md-12 col-lg-12 col-xl-12">
                            <div class="card">
                                <div class="card-header d-md-flex d-block">
                                    <div class="h5 mb-0 d-sm-flex d-bllock align-items-center">
                                        <div class="avatar avatar-sm">
                                            <?php if (!empty($company['logo_path'])): ?>
                                            <img src="<?= BASE_URL . htmlspecialchars($company['logo_path']) ?>" alt="<?= htmlspecialchars($company['company_name']) ?> Logo" style="width: 200px;">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="ms-auto mt-md-0 mt-2">
                                    <div class="avatar avatar-sm">
                                            <div class="h6 fw-semibold mb-0">INVOICE : <span class="text-primary"># <?= htmlspecialchars($invoice['invoice_no'] ?? $invoice_id) ?></span></div>
                                        </div>

                                        <div class="avatar avatar-sm">
                                            <p class="fw-semibold text-muted mb-1">Date Issued :</p>
                                            <p class="fs-15 mb-1"><?php echo date('Y-m-d');?> - <span class="text-muted fs-12"><?php echo date('H:i:s');?></span></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row gy-3">
                                        <div class="col-xl-12">
                                            <div class="row">
                                                <?php if ($company['company_name'] || $company['address'] || $company['email'] || $company['phone']): ?>
                                                <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6">
                                                    <p class="text-muted mb-2">
                                                        Billing From :
                                                    </p>
                                                    <?php if ($company['company_name']): ?>
                                                    <p class="fw-bold mb-1" style="white-space: nowrap;">
                                                        <?= htmlspecialchars($company['company_name']) ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    <?php if ($company['address']): ?>
                                                    <p class="mb-1 text-muted">
                                                        <?= nl2br(htmlspecialchars($company['address'])) ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    <?php if ($company['email']): ?>
                                                    <p class="mb-1 text-muted">
                                                        <?= htmlspecialchars($company['email']) ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    <?php if ($company['phone']): ?>
                                                    <p class="mb-1 text-muted">
                                                        <?= htmlspecialchars($company['phone']) ?>
                                                    </p>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="col-xl-4 col-lg-4 col-md-6 col-sm-6 ms-auto mt-sm-0 mt-3">
                                                    <p class="text-muted mb-2">
                                                        Recipient Details :
                                                    </p>
                                                    <p class="fw-bold mb-1">
                                                        <?= htmlspecialchars($invoice['customer_name'] ?? 'N/A') ?>
                                                    </p>
                                                    <p class="text-muted mb-1">
                                                        <?= nl2br(htmlspecialchars($invoice['customer_address'] ?? 'N/A')) ?>
                                                    </p>
                                                    <p class="text-muted mb-1">
                                                        <?= htmlspecialchars($invoice['customer_email'] ?? '') ?>
                                                    </p>
                                                    <p class="text-muted">
                                                        <?= htmlspecialchars($invoice['customer_phone'] ?? '') ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-xl-12">
                                            <div class="table-responsive">
                                                <table class="table nowrap text-nowrap border mt-4">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>PRODUCT</th>
                                                            <th>DESCRIPTION</th>
                                                            <th style="text-align: right;">TOTAL</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (count($items) > 0): 
                                                            $i = 1; foreach ($items as $item): 
                                                            $qty = $item['quantity'] ?? 1;
                                                            $subtotal = $item['total_amount'] ?? 0;
                                                            $unit_price = ($qty > 0) ? $subtotal / $qty : 0;
                                                        ?>
                                                        <tr>
                                                            <td><?= $i++ ?></td>
                                                            <td>
                                                                <div class="fw-semibold">
                                                                   <?= htmlspecialchars($item['product_name']) ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <div class="text-muted">
                                                                    <?= htmlspecialchars($item['product_description'] ?? '') ?>
                                                                </div>
                                                            </td>
                                                            <td style="text-align: right;">
                                                               <?= number_format($subtotal, 2) ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; else: ?>
                                                        <tr>
                                                            <td colspan="4" class="text-center text-muted py-3">No items found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        <tr>
                                                            <td colspan="2"></td>
                                                            <td colspan="2">
                                                                <table class="table table-sm text-nowrap mb-0 table-borderless">
                                                                    <tbody>
                                                                        <tr>
                                                                            <td scope="row">
                                                                                <p class="mb-0">Sub Total :</p>
                                                                            </td>
                                                                            <td style="text-align: right;">
                                                                                <p class="mb-0 fw-semibold fs-15"><?= number_format($invoice['subtotal'] ?? 0, 2) ?></p>
                                                                            </td>
                                                                        </tr>
                                                                        <tr>
                                                                            <td scope="row">
                                                                                <p class="mb-0">Discount :</p>
                                                                            </td>
                                                                            <td style="text-align: right;">
                                                                                <p class="mb-0 fw-semibold fs-15"><?= number_format($invoice['discount'] ?? 0, 2) ?></p>
                                                                            </td>
                                                                        </tr>
                                                                        <tr>
                                                                            <td scope="row">
                                                                                <p class="mb-0 fs-14">Total :</p>
                                                                            </td>
                                                                            <td style="text-align: right;">
                                                                                <p class="mb-0 fw-semibold fs-16 text-success"><?= number_format($invoice['total_amount'] ?? 0, 2) ?></p>
                                                                            </td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-xl-12">
                                            <div>
                                                <label for="invoice-note" class="form-label">Note:</label>
                                                <textarea class="form-control form-control-light" id="invoice-note" rows="3">Thank you for choosing our services. Please review the invoice details and ensure payment is completed by the due date. If there are any discrepancies, kindly inform us immediately.</textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
    </div>

    <script>
        window.print();
    </script>
</body>
</html>