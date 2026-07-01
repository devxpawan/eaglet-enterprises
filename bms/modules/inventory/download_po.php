<?php
require_once __DIR__ . '/../../config/paths.php';

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: " . BASE_URL . "signin.php");
    exit();
}

// Include database connection and functions
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

// Check if PO ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Purchase Order ID is required");
}

$po_id = $_GET['id'];
$format = isset($_GET['format']) ? $_GET['format'] : 'view'; // 'view' or 'html' (for modal)

// Fetch PO details
$po_query = "SELECT po.*, sup.company_name as supplier_name, sup.contact_person, 
            sup.address as supplier_address, sup.email as supplier_email, sup.phone as supplier_phone,
            u.name as user_name
            FROM purchase_orders po
            LEFT JOIN suppliers sup ON po.supplier_id = sup.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.id = ?";

$stmt = $conn->prepare($po_query);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Purchase Order not found");
}

$po = $result->fetch_assoc();

// Get currency details
$currencySymbol = 'LKR';

// Fetch PO items
$itemSql = "SELECT poi.*, p.name as product_name, p.sku, p.unit, p.description as product_description,
            (poi.quantity * poi.unit_price) as item_subtotal
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.id
            WHERE poi.po_id = ?";

$stmt = $conn->prepare($itemSql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$itemsResult = $stmt->get_result();
$items = [];

while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}

// Company information
$company = getCompanyInfo($conn);

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

$grand_total = floatval($po['total_amount']);
$grand_total_words = convertNumberToWords(floor($grand_total));
if ($grand_total_words) {
    $grand_total_words = ucwords($grand_total_words) . ' Only';
} else {
    $grand_total_words = '';
}
?>

<?php if (!$isModalView): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Order #<?php echo htmlspecialchars($po['po_number']); ?></title>
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
            padding: 0 30px;
            box-sizing: border-box;
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

            .header-table, .info-table, .product-table, .totals-table-wrapper,
            .amount-in-words-box, .validity-terms-box, .bank-details-box,
            .signature-section {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .product-table tr {
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
    <div class="invoice-container">
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
            <h2>PURCHASE ORDER</h2>
        </div>

        <!-- Supplier & PO Meta Info -->
        <table class="info-table">
            <tr>
                <td class="client-info-cell">
                    <span class="text-muted">Supplier (Vendor):</span><br>
                    <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong><br>
                    <?php if (!empty($po['contact_person'])): ?>
                        Attn: <?php echo htmlspecialchars($po['contact_person']); ?><br>
                    <?php endif; ?>
                    <?php echo nl2br(htmlspecialchars($po['supplier_address'] ?? '')); ?>
                </td>
                <td class="invoice-meta-cell">
                    <strong>PO Number :</strong> <?php echo htmlspecialchars($po['po_number']); ?><br>
                    <strong>Date :</strong> <?php echo date('j/n/Y', strtotime($po['order_date'])); ?><br>
                    <?php if ($po['expected_date']): ?>
                        <strong>Expected Date :</strong> <?php echo date('j/n/Y', strtotime($po['expected_date'])); ?><br>
                    <?php endif; ?>
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
                        $qty = $item['quantity'] ?? 1;
                        $unit_price = $item['unit_price'] ?? 0;
                        $subtotal = $item['item_subtotal'] ?? 0;
                ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $i++; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                <?php if (!empty($item['sku'])): ?>
                                    (SKU: <?php echo htmlspecialchars($item['sku']); ?>)
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;"><?php echo number_format($unit_price, 2); ?></td>
                            <td style="text-align: center;"><?php echo $qty; ?> <?php echo htmlspecialchars($item['unit'] ?? ''); ?></td>
                            <td style="text-align: right;"><?php echo number_format($subtotal, 2); ?></td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">No items found for this purchase order</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals Row -->
        <div class="totals-table-wrapper">
            <table class="totals-table">
                <tr>
                    <td width="55%">Subtotal</td>
                    <td width="45%" style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format($po['sub_total'], 2); ?></td>
                </tr>
                <?php if (floatval($po['discount']) > 0): ?>
                    <tr>
                        <td>Total Discount</td>
                        <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format(floatval($po['discount']), 2); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (floatval($po['tax_amount']) > 0): ?>
                    <tr>
                        <td>Tax</td>
                        <td style="text-align: right;"><?php echo $currencySymbol . ' ' . number_format(floatval($po['tax_amount']), 2); ?></td>
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
            <strong>Notes / Instructions :</strong>
            <p style="margin-top: 5px; line-height: 1.5; white-space: pre-line;"><?php echo !empty($po['notes']) ? htmlspecialchars($po['notes']) : 'Please supply the items listed above according to the agreed specifications and delivery terms.'; ?></p>
        </div>

        <!-- Footer Group (Bank, Signatures, Footer Address) to prevent middle-page split -->
        <div class="footer-group-container">
            <!-- Yours Faithfully / Signatures -->
            <div class="signature-section">
                <div class="signature-col-left">
                    <strong>Yours Faithfully,</strong><br>
                    <div class="signature-placeholder">
                        Authorized Signature
                    </div>
                    <div class="signature-placeholder">
                        Prepared By (<?php echo htmlspecialchars($po['user_name'] ?? ''); ?>)
                    </div>
                </div>
                <div class="signature-col-right">
                    <div class="signature-placeholder-right">
                        Supplier Acknowledgment - ..............................
                    </div>
                    <div class="signature-placeholder-right" style="margin-top: 15px;">
                        Signature & Date - ............................................
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
                filename: 'PO_<?php echo htmlspecialchars($po['po_number']); ?>.pdf',
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
    </script>
</body>

</html>
<?php endif; ?>
<?php
$conn->close();
?>
