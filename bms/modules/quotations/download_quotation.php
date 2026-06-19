<?php
require_once __DIR__ . '/../../config/paths.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Quotation ID is required");
}

$quotation_id = $_GET['id'];
$format = isset($_GET['format']) ? $_GET['format'] : 'view'; 

$q_query = "SELECT q.*, c.name as customer_name, 
                c.address as customer_address, c.email as customer_email, c.phone as customer_phone,
                c.business_name as customer_business_name,
                u.name as user_name
                FROM quotations q 
                LEFT JOIN customers c ON q.customer_id = c.customer_id
                LEFT JOIN users u ON q.user_id = u.id
                WHERE q.quotation_id = ?";

$stmt = $conn->prepare($q_query);
$stmt->bind_param("i", $quotation_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Quotation not found");
}

$quotation = $result->fetch_assoc();
$currency = 'lkr';
$currencySymbol = 'LKR';

$itemSql = "SELECT qi.*, qi.product_name as qi_product_name,
            qi.description as product_description
            FROM quotation_items qi
            WHERE qi.quotation_id = ?";

$stmt = $conn->prepare($itemSql);
$stmt->bind_param("i", $quotation_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$items = [];
$has_any_discount = false;
while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
    if (floatval($item['discount']) > 0) {
        $has_any_discount = true;
    }
}

if (floatval($quotation['discount']) > 0) {
    $has_any_discount = true;
}

$company = getCompanyInfo($conn);

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

$grand_total = floatval($quotation['total_amount']);
$grand_total_words = convertNumberToWords(floor($grand_total));
if ($grand_total_words) {
    $grand_total_words = ucwords($grand_total_words) . ' Only';
} else {
    $grand_total_words = '';
}

// Generate Reference Number
if (!empty($quotation['ref_no'])) {
    $ref_no = $quotation['ref_no'];
} else {
    $ref_no = generateRefNo($conn, $quotation_id, $quotation['quotation_date'], 'QT');
}
?>

<?php if (!$isModalView): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation #<?php echo $quotation_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>css/style.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>
    <script src="<?= BASE_URL ?>js/toast.js"></script>
<?php endif; ?>

    <style>
        <?php if (!$isModalView): ?>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-size: 11px;
            color: #333;
        }
        <?php endif; ?>

        .quotation-container {
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

        .quotation-title-centered {
            text-align: center;
            margin-bottom: 10px;
        }

        .quotation-title-centered h2 {
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

        .quote-meta-cell {
            width: 40%;
            text-align: right;
            line-height: 1.4;
        }

        .subject-line {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
            color: #1B1C56;
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

            .quotation-container {
                box-shadow: none;
                padding: 0;
                padding-bottom: 60px;
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
    <div class="quotation-container">
        <?php if ($showButtons): ?>
            <div class="control-buttons">
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
                        <!-- Fallback default logo if available -->
                        <img src="<?= BASE_URL ?>assets/img/logo.png" onerror="this.style.display='none';" alt="">
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <div class="header-divider"></div>

        <!-- Title -->
        <div class="quotation-title-centered">
            <h2>QUOTATION</h2>
        </div>

        <!-- Client & Quotation Meta Info -->
        <table class="info-table">
            <tr>
                <td class="client-info-cell">
                    <?php if (!empty($quotation['customer_business_name'])): ?>
                        <strong>M/S. <?php echo htmlspecialchars($quotation['customer_business_name']); ?></strong><br>
                    <?php else: ?>
                        <strong>M/S. <?php echo htmlspecialchars($quotation['customer_name']); ?></strong><br>
                    <?php endif; ?>
                    <?php if (!empty($quotation['customer_business_name']) && !empty($quotation['customer_name'])): ?>
                        Attn: <?php echo htmlspecialchars($quotation['customer_name']); ?><br>
                    <?php endif; ?>
                    <?php echo nl2br(htmlspecialchars($quotation['customer_address'])); ?>
                </td>
                <td class="quote-meta-cell">
                    <strong>Date :</strong> <?php echo date('j/n/Y', strtotime($quotation['quotation_date'])); ?><br>
                    <strong>Ref No :</strong> <?php echo htmlspecialchars($ref_no); ?>
                    <?php if (!empty($quotation['revision_no']) && $quotation['revision_no'] > 0): ?>
                        <br><strong>Revision :</strong> R<?php echo $quotation['revision_no']; ?>
                    <?php endif; ?>

                </td>
            </tr>
        </table>

        <!-- Subject Line -->
        <?php if (!empty($quotation['subject'])): ?>
            <div class="subject-line">
                Subject : <?php echo htmlspecialchars($quotation['subject']); ?>
            </div>
        <?php endif; ?>

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
                $total_before_discounts = 0;
                $total_item_discounts = 0;
                
                if (count($items) > 0):
                    foreach ($items as $item):
                        $qty = $item['quantity'] ?? 1;
                        $subtotal = $item['total_amount'] ?? 0;
                        $discount = $item['discount'] ?? 0;
                        $unit_price = $item['price'] ?? 0;
                        
                        $total_before_discounts += ($qty * $unit_price);
                        $total_item_discounts += $discount;
                ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $i++; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['qi_product_name']); ?></strong>
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
                        <td colspan="5" style="text-align: center;">No items found for this quotation</td>
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
                        <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format((floatval($quotation['discount']) > 0 ? floatval($quotation['discount']) : $total_item_discounts), 2); ?></td>
                    </tr>
                <?php endif; ?>
                <?php
                $qvat = floatval($quotation['vat'] ?? 0);
                if ($qvat > 0):
                    $qvat_pct = 0;
                    if ($total_before_discounts > 0) {
                        $qvat_pct = ($qvat / $total_before_discounts) * 100;
                    }
                ?>
                    <tr>
                        <td>Vat (<?php echo number_format($qvat_pct, 1); ?>%)</td>
                        <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format($qvat, 2); ?></td>
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

        <!-- Validity & Terms -->
        <div class="validity-terms-box">
            <?php 
            $validity_days = 14;
            if (!empty($quotation['expiry_date']) && !empty($quotation['quotation_date'])) {
                $diff = strtotime($quotation['expiry_date']) - strtotime($quotation['quotation_date']);
                $validity_days = round($diff / (60 * 60 * 24));
            }
            ?>
            <strong>Quotation Validity :</strong> This quotation is valid for <?php echo $validity_days; ?> days.<br>
            <strong>Payment Terms     :</strong> Full payment in advance.
        </div>

        <!-- Notes if any -->
        <?php if (!empty($quotation['notes'])): ?>
            <div style="margin-bottom: 25px;">
                <strong>Notes / Additional Terms :</strong>
                <p style="margin-top: 5px; line-height: 1.5; white-space: pre-line;"><?php echo htmlspecialchars($quotation['notes']); ?></p>
            </div>
        <?php endif; ?>

        <!-- Footer Group (Bank, Signatures, Footer Address) to prevent middle-page split -->
        <div class="footer-group-container">
            <!-- Bank Details Section -->
            <?php 
            $hasBank = !empty($company['bank_name']) || !empty($company['account_name']) || !empty($company['account_number']);
            if ($hasBank):
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
    
</body>

</html>
<?php endif; ?>
<?php
$conn->close();
?>
