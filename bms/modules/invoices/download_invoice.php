<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

// Include database connection and functions
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Check if invoice ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Invoice ID is required");
}

$invoice_id = $_GET['id'];
$show_payment_details = isset($_GET['show_payment']) && $_GET['show_payment'] === 'true';
$format = isset($_GET['format']) ? $_GET['format'] : 'view'; // 'view' or 'html' (for modal)
$download = isset($_GET['download']) && $_GET['download'] === 'true'; // Trigger actual download

// Fetch invoice details from database with individual item discounts
$invoice_query = "SELECT i.*, i.pay_status AS invoice_pay_status, c.name as customer_name, 
                c.address as customer_address, c.email as customer_email, c.phone as customer_phone,
                c.business_name as customer_business_name,
                p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
                u2.name as paid_by_name, u.name as user_name
                FROM invoices i 
                LEFT JOIN customers c ON i.customer_id = c.customer_id
                LEFT JOIN payments p ON i.invoice_id = p.invoice_id
                LEFT JOIN users u2 ON p.pay_by = u2.id
                LEFT JOIN users u ON i.user_id = u.id
                WHERE i.invoice_id = ?";

$stmt = $conn->prepare($invoice_query);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Invoice not found");
}

$invoice = $result->fetch_assoc();

// Get currency from invoice
$currency = 'lkr';
$currencySymbol = 'LKR';

// Modified item query to include item-level discounts
$itemSql = "SELECT ii.*, ii.pay_status, ii.product_name as ii_product_name,
            ii.description as product_description,
            ii.total_amount as item_subtotal,
            ii.quantity as item_qty,
            COALESCE(ii.discount, 0) as item_discount
            FROM invoice_items ii
            WHERE ii.invoice_id = ?";

$stmt = $conn->prepare($itemSql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$items = [];

while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}

// Determine overall invoice payment status
if (isset($invoice['invoice_pay_status']) && !empty($invoice['invoice_pay_status'])) {
    $invoicePayStatus = strtolower($invoice['invoice_pay_status']);
} else {
    $allItemsPaid = true;
    $anyItemPaid = false;

    foreach ($items as $item) {
        if (strtolower($item['pay_status']) == 'paid') {
            $anyItemPaid = true;
        } else {
            $allItemsPaid = false;
        }
    }

    if ($allItemsPaid && count($items) > 0) {
        $invoicePayStatus = 'paid';
    } elseif ($anyItemPaid) {
        $invoicePayStatus = 'partial';
    } else {
        $invoicePayStatus = 'unpaid';
    }
}

// Company information
$company = getCompanyInfo($conn);

// Function to get badge class for payment status
function getPaymentStatusBadge($status)
{
    $status = strtolower($status ?? 'unpaid');
    switch ($status) {
        case 'paid':
            return "bg-success";
        case 'partial':
            return "bg-warning";
        case 'unpaid':
        default:
            return "bg-danger";
    }
}

// Calculate total item-level discounts
$total_item_discounts = 0;
foreach ($items as $item) {
    $total_item_discounts += floatval($item['item_discount']);
}

// Check if there are any discounts at all
$has_any_discount = $total_item_discounts > 0 || floatval($invoice['discount']) > 0;

// Calculate total before discounts
$total_before_discounts = 0;
foreach ($items as $item) {
    $item_subtotal = $item['item_subtotal'] ?? 0;
    $item_discount = $item['item_discount'] ?? 0;
    $total_before_discounts += ($item_subtotal + $item_discount);
}

// Determine if we should show buttons (only when format is 'view')
$showButtons = ($format === 'view');
$isModalView = ($format === 'html');

// Helper to convert number to words for the grand total
function convertNumberToWords($number) {
    $hyphen      = ' ';
    $conjunction = ' and ';
    $separator   = ', ';
    $negative    = 'negative ';
    $decimal     = ' point ';
    $dictionary  = array(
        0                   => 'Zero',
        1                   => 'One',
        2                   => 'Two',
        3                   => 'Three',
        4                   => 'Four',
        5                   => 'Five',
        6                   => 'Six',
        7                   => 'Seven',
        8                   => 'Eight',
        9                   => 'Nine',
        10                  => 'Ten',
        11                  => 'Eleven',
        12                  => 'Twelve',
        13                  => 'Thirteen',
        14                  => 'Fourteen',
        15                  => 'Fifteen',
        16                  => 'Sixteen',
        17                  => 'Seventeen',
        18                  => 'Eighteen',
        19                  => 'Nineteen',
        20                  => 'Twenty',
        30                  => 'Thirty',
        40                  => 'Forty',
        50                  => 'Fifty',
        60                  => 'Sixty',
        70                  => 'Seventy',
        80                  => 'Eighty',
        90                  => 'Ninety',
        100                 => 'Hundred',
        1000                => 'Thousand',
        1000000             => 'Million',
        1000000000          => 'Billion',
        1000000000000       => 'Trillion',
        1000000000000000    => 'Quadrillion',
        1000000000000000000 => 'Quintillion'
    );

    if (!is_numeric($number)) {
        return false;
    }

    if (($number >= 0 && (int) $number < 0) || (int) $number < 0) {
        return $negative . convertNumberToWords(abs($number));
    }

    $string = $fraction = null;

    if (strpos($number, '.') !== false) {
        list($number, $fraction) = explode('.', $number);
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens   = ((int) ($number / 10)) * 10;
            $units  = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $number < 1000:
            $hundreds  = $number / 100;
            $remainder = $number % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) {
                $string .= $conjunction . convertNumberToWords($remainder);
            }
            break;
        default:
            $baseUnit = pow(1000, floor(log($number, 1000)));
            $numBaseUnits = (int) ($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = convertNumberToWords($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= convertNumberToWords($remainder);
            }
            break;
    }

    if (null !== $fraction && is_numeric($fraction) && (int)$fraction > 0) {
        $string .= $decimal;
        $words = array();
        foreach (str_split((string) $fraction) as $number) {
            $words[] = $dictionary[$number];
        }
        $string .= implode(' ', $words);
    }

    return $string;
}

$grand_total = floatval($invoice['total_amount']);
$grand_total_words = convertNumberToWords(floor($grand_total));
if ($grand_total_words) {
    $grand_total_words = ucwords($grand_total_words) . ' Only';
} else {
    $grand_total_words = '';
}

// Reference Number - use stored invoice_ref_no or generate one
$ref_no = !empty($invoice['invoice_ref_no']) ? $invoice['invoice_ref_no'] : generateRefNo($conn, $invoice_id, $invoice['issue_date'], 'INV');
// Store quotation ref separately for cross-reference display
$quotation_ref = !empty($invoice['quotation_ref_no']) ? $invoice['quotation_ref_no'] : null;
?>

<?php if (!$isModalView): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice #<?php echo $invoice_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>css/style.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>js/toast.js"></script>
<?php endif; ?>

    <style>
        <?php if (!$isModalView): ?>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-size: 13px;
            color: #333;
        }
        <?php endif; ?>

        .invoice-container {
            max-width: 900px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }

        .header-spacer-cell {
            width: 33%;
            vertical-align: middle;
        }

        .header-logo-cell {
            width: 33%;
            vertical-align: middle;
            text-align: right;
        }

        .header-logo-cell img {
            max-height: 60px;
            max-width: 120px;
        }

        .header-title-cell {
            width: 34%;
            text-align: center;
            vertical-align: middle;
        }

        .header-title-cell h1 {
            color: #1B1C56;
            font-size: 18px;
            font-weight: 800;
            margin: 0;
            text-decoration: underline;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header-divider {
            border-bottom: 3px solid #1B1C56;
            margin-top: 5px;
            margin-bottom: 8px;
        }

        .invoice-title-centered {
            text-align: center;
            margin-bottom: 10px;
        }

        .invoice-title-centered h2 {
            font-size: 15px;
            font-weight: bold;
            color: #1B1C56;
            text-decoration: underline;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .info-table {
            width: 100%;
            margin-bottom: 8px;
        }

        .info-table td {
            vertical-align: top;
            padding: 2px 0;
        }

        .client-info-cell {
            width: 60%;
            line-height: 1.4;
        }

        .invoice-meta-cell {
            width: 40%;
            text-align: right;
            line-height: 1.4;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 10.5px;
        }

        .product-table th {
            border: 1.5px solid #1B1C56;
            padding: 4px 6px;
            font-weight: bold;
            color: #1B1C56;
            background-color: #f8f9fa;
        }

        .product-table td {
            border: 1.5px solid #1B1C56;
            padding: 4px 6px;
            vertical-align: top;
        }

        .totals-table-wrapper {
            float: right;
            width: 300px;
            margin-bottom: 8px;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }

        .totals-table td {
            border: 1.5px solid #1B1C56;
            padding: 4px 6px;
            font-weight: bold;
        }

        .amount-in-words-box {
            font-weight: bold;
            font-size: 10.5px;
            color: #333;
            margin-top: 5px;
            margin-bottom: 10px;
            clear: both;
        }

        .validity-terms-box {
            line-height: 1.4;
            margin-bottom: 10px;
        }

        .bank-details-box {
            border: 1px solid #ddd;
            padding: 8px 10px;
            background-color: #fafafa;
            border-radius: 4px;
            margin-bottom: 10px;
            width: 350px;
            font-size: 10px;
            line-height: 1.4;
        }

        .bank-details-title {
            font-weight: bold;
            margin-bottom: 4px;
            color: #1B1C56;
            text-decoration: underline;
        }

        .signature-section {
            margin-top: 15px;
            margin-bottom: 20px;
            width: 100%;
            display: flex;
            justify-content: space-between;
        }

        .signature-col-left {
            width: 45%;
        }

        .signature-col-right {
            width: 45%;
            text-align: right;
        }

        .signature-placeholder {
            margin-top: 20px;
            border-top: 1px dashed #333;
            width: 180px;
            padding-top: 3px;
            font-size: 10px;
        }

        .signature-placeholder-right {
            margin-top: 20px;
            width: 180px;
            padding-top: 3px;
            font-size: 10px;
            display: inline-block;
            text-align: left;
        }

        .footer-line {
            border-bottom: 1.5px solid #1B1C56;
            margin-top: 10px;
            margin-bottom: 5px;
        }

        .footer-text {
            text-align: center;
            font-size: 9px;
            color: #555;
            line-height: 1.3;
        }

        .control-buttons {
            margin: 20px 0;
            text-align: center;
        }

        .control-buttons button {
            margin: 0 5px;
            padding: 8px 15px;
            cursor: pointer;
        }

        .payment-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            color: white !important;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
            margin-top: 3px;
        }

        .invoice-container .bg-success {
            background-color: #28a745;
        }

        .invoice-container .bg-warning {
            background-color: #fd7e14;
        }

        .invoice-container .bg-danger {
            background-color: #dc3545;
        }

        .print-footer {
            /* normal screen: just flows in place */
        }

        @media print {
            @page {
                size: A4;
                margin: 0.3in;
            }

            body {
                background-color: white;
                padding: 0;
                margin: 0;
            }

            .invoice-container {
                box-shadow: none;
                padding: 0;
                padding-bottom: 60px; /* space for the fixed footer */
                margin: 0;
                width: 100%;
                max-width: 100%;
            }

            .control-buttons {
                display: none !important;
            }

            .header-table, .info-table {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .product-table {
                font-size: 11px;
            }

            .product-table thead {
                display: table-header-group;
            }

            .product-table thead tr {
                background-color: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .product-table tbody tr {
                page-break-inside: auto;
                break-inside: auto;
            }

            .product-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .totals-table-wrapper {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .amount-in-words-box {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .validity-terms-box {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .bank-details-box {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .signature-section {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .footer-group-container {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: white;
                padding: 0 0.3in;
            }

            .payment-badge {
                background-color: #e0e0e0 !important;
                color: #000 !important;
                border: 1px solid #000 !important;
            }

            .payment-badge.bg-success,
            .payment-badge.bg-warning,
            .payment-badge.bg-danger {
                background-color: #e0e0e0 !important;
                color: #000 !important;
            }
        }

        .footer-group-container {
            page-break-inside: avoid;
            break-inside: avoid;
        }
    </style>
<?php if (!$isModalView): ?>
</head>

<body>
<?php endif; ?>
    <div class="invoice-container">
        <?php if ($showButtons): ?>
            <div class="control-buttons">
                <?php if ($show_payment_details && $invoicePayStatus != 'paid'): ?>
                    <button id="markAsPaidBtn" class="btn btn-success">Mark as Paid</button>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-primary">Print</button>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <table class="header-table">
            <tr>
                <td class="header-spacer-cell"></td>
                <td class="header-title-cell">
                    <h1 style="white-space: nowrap;"><?php echo htmlspecialchars($company['company_name']); ?></h1>
                </td>
                <td class="header-logo-cell">
                    <?php if (!empty($company['logo_path']) && file_exists(BASE_PATH . $company['logo_path'])): ?>
                        <img src="<?= BASE_URL . htmlspecialchars($company['logo_path']) ?>" alt="Logo">
                    <?php else: ?>
                        <img src="<?= BASE_URL ?>assets/img/logo.png" onerror="this.style.display='none';" alt="">
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <div class="header-divider"></div>

        <!-- Title -->
        <div class="invoice-title-centered">
            <h2>INVOICE</h2>
        </div>

        <!-- Client & Invoice Meta Info -->
        <table class="info-table">
            <tr>
                <td class="client-info-cell">
                    <?php if (!empty($invoice['customer_business_name'])): ?>
                        <strong>M/S. <?php echo htmlspecialchars($invoice['customer_business_name']); ?></strong><br>
                    <?php else: ?>
                        <strong>M/S. <?php echo htmlspecialchars($invoice['customer_name']); ?></strong><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['customer_business_name']) && !empty($invoice['customer_name'])): ?>
                        Attn: <?php echo htmlspecialchars($invoice['customer_name']); ?><br>
                    <?php endif; ?>
                    <?php echo nl2br(htmlspecialchars($invoice['customer_address'])); ?>
                </td>
                <td class="invoice-meta-cell">
                    <strong>Date :</strong> <?php echo date('j/n/Y', strtotime($invoice['issue_date'])); ?><br>
                    <strong>Due Date :</strong> <?php echo (!empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00') ? date('j/n/Y', strtotime($invoice['due_date'])) : ''; ?><br>
                    <strong>Ref No :</strong> <?php echo htmlspecialchars($ref_no); ?><br>
                    <?php if (!empty($quotation_ref)): ?>
                        <strong>From Quotation :</strong> <?php echo htmlspecialchars($quotation_ref); ?><br>
                    <?php endif; ?>
                    <span class="payment-badge <?php echo getPaymentStatusBadge($invoicePayStatus); ?>">
                        <?php echo ucfirst($invoicePayStatus); ?>
                    </span>
                </td>
            </tr>
        </table>

        <!-- Table of Products -->
        <table class="product-table">
            <thead>
                <tr>
                    <th width="8%" style="text-align: center;">S.NO</th>
                    <th width="52%">DESCRIPTION</th>
                    <th width="15%" style="text-align: right;">UNIT PRICE</th>
                    <th width="12%" style="text-align: center;">QTY</th>
                    <th width="13%" style="text-align: right;">AMOUNT</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                
                if (count($items) > 0):
                    foreach ($items as $item):
                        $qty = $item['item_qty'] ?? 1;
                        $subtotal = $item['item_subtotal'] ?? 0;
                        $discount = $item['item_discount'] ?? 0;
                        $unit_price = ($subtotal + $discount) / $qty;
                ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $i++; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['ii_product_name']); ?></strong>
                                <?php if (!empty($item['product_description'])): ?>
                                    <br><span style="font-size: 11px; color: #555;"><?php echo nl2br(htmlspecialchars($item['product_description'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;"><?php echo number_format($unit_price, 2); ?></td>
                            <td style="text-align: center;"><?php echo $qty; ?></td>
                            <td style="text-align: right;"><?php echo number_format($subtotal, 2); ?></td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">No items found for this invoice</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals Row -->
        <div class="totals-table-wrapper">
            <table class="totals-table">
                <tr>
                    <td width="55%">Subtotal</td>
                    <td width="45%" style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format($total_before_discounts, 2); ?></td>
                </tr>
                <?php if ($has_any_discount): ?>
                    <tr>
                        <td>Total Discount</td>
                        <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format($total_item_discounts, 2); ?></td>
                    </tr>
                <?php endif; ?>
                <?php
                $invoice_vat = floatval($invoice['vat'] ?? 0);
                if ($invoice_vat > 0):
                    $vat_pct = 0;
                    if ($total_before_discounts > 0) {
                        $vat_pct = ($invoice_vat / $total_before_discounts) * 100;
                    }
                ?>
                    <tr>
                        <td>Vat (<?php echo number_format($vat_pct, 1); ?>%)</td>
                        <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format($invoice_vat, 2); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td>Total</td>
                    <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format($grand_total, 2); ?></td>
                </tr>
            </table>
        </div>

        <!-- Amount in Words -->
        <div class="amount-in-words-box">
            <?php if (!empty($grand_total_words)): ?>
                <?php echo htmlspecialchars($grand_total_words); ?>
            <?php endif; ?>
        </div>

        <!-- Notes if any -->
        <div style="margin-bottom: 25px;">
            <strong>Notes / Terms :</strong>
            <p style="margin-top: 5px; line-height: 1.5; white-space: pre-line;"><?php echo !empty($invoice['notes']) ? htmlspecialchars($invoice['notes']) : 'Thank you for choosing our services. Please review the invoice details and ensure payment is completed by the due date. If there are any discrepancies, kindly inform us immediately.'; ?></p>
        </div>

        <!-- Footer Group (Bank, Signatures, Footer Address) to prevent middle-page split -->
        <div class="footer-group-container">
            <!-- Bank Details Section -->
            <?php 
            // Bank details shown only if unpaid
            $hasBank = !empty($company['bank_name']) || !empty($company['account_name']) || !empty($company['account_number']);
            if ($hasBank && $invoicePayStatus != 'paid' && $invoice['status'] != 'cancel'):
            ?>
                <div class="bank-details-box">
                    <div class="bank-details-title">Bank Details</div>
                    <strong>Accounts Name :</strong> <?php echo htmlspecialchars($company['account_name']); ?><br>
                    <strong>Account number :</strong> <?php echo htmlspecialchars($company['account_number']); ?><br>
                    <strong>Bank Name :</strong> <?php echo htmlspecialchars($company['bank_name']); ?><br>
                    <?php if (!empty($company['bank_branch'])): ?>
                        <strong>Branch Name :</strong> <?php echo htmlspecialchars($company['bank_branch']); ?><br>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Yours Faithfully / Signatures -->
            <div class="signature-section">
                <div class="signature-col-left">
                    <strong>Yours Faithfully,</strong><br>
                    <div class="signature-placeholder">
                        Authorized Signature
                    </div>
                    <div class="signature-placeholder">
                        Authorized Signature
                    </div>
                </div>
                <div class="signature-col-right">
                    <div class="signature-placeholder-right">
                        Accepted By - ........................................
                    </div>
                    <div class="signature-placeholder-right" style="margin-top: 15px;">
                        Signature & Date - ................................
                    </div>
                </div>
            </div>

        </div>

        <!-- Footer (fixed to bottom of paper when printing) -->
        <div class="print-footer">
            <div class="footer-line"></div>
            <div class="footer-text">
                <?php 
                $footer_addr = $company['address'] ? htmlspecialchars(str_replace("\n", ", ", $company['address'])) : '';
                echo $footer_addr;
                if (!empty($company['phone']) || !empty($company['email'])):
                    echo "<br>";
                    if ($company['phone']) echo "Hot line / Tel: " . htmlspecialchars($company['phone']) . " ";
                    if ($company['email']) echo "| E-Mail: " . htmlspecialchars($company['email']) . " ";
                endif;
                ?>
            </div>
        </div>
    </div>

    <?php if (!$isModalView): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function downloadPDF() {
            // Hide the buttons before generating PDF
            const buttons = document.querySelector('.control-buttons');
            if (buttons) {
                buttons.style.display = 'none';
            }

            const element = document.querySelector('.invoice-container');
            const opt = {
                margin: 0.4,
                filename: 'Invoice_<?php echo $invoice_id; ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    scrollY: 0
                },
                jsPDF: {
                    unit: 'in',
                    format: 'a4',
                    orientation: 'portrait'
                }
            };

            // Generate PDF
            html2pdf().set(opt).from(element).save().then(function() {
                // Show buttons again after PDF is generated
                if (buttons) {
                    buttons.style.display = 'block';
                }
            });
        }

        // Handle Mark as Paid button click
        document.addEventListener('DOMContentLoaded', function () {
            const markAsPaidBtn = document.getElementById('markAsPaidBtn');
            if (markAsPaidBtn) {
                markAsPaidBtn.addEventListener('click', function () {
                    const formData = new FormData();
                    formData.append('invoice_id', '<?php echo $invoice_id; ?>');
                    formData.append('pay_status', 'paid');

                    fetch('<?= BASE_URL ?>modules/invoices/update_invoice_status.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            showToast('error', 'Error updating payment status: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('error', 'An error occurred while updating the payment status.');
                    });
                });
            }
        });
    </script>
</body>

</html>
<?php endif; ?>
<?php
$conn->close();
?>