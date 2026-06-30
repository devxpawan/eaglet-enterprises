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

// Check if Credit Memo ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Credit Memo ID is required");
}

$credit_memo_id = intval($_GET['id']);
$format = isset($_GET['format']) ? $_GET['format'] : 'view'; // 'view' or 'html'

// Fetch credit memo details
$query = "SELECT cm.*, i.invoice_ref_no, i.total_amount as invoice_total, i.subtotal as invoice_subtotal,
          i.discount as invoice_discount, i.vat as invoice_vat, i.currency, i.quotation_ref_no,
          c.name as customer_name, c.address as customer_address, c.email as customer_email, c.phone as customer_phone,
          c.business_name as customer_business_name,
          u.name as creator_name
          FROM credit_memos cm
          LEFT JOIN invoices i ON cm.invoice_id = i.invoice_id
          LEFT JOIN customers c ON cm.customer_id = c.customer_id
          LEFT JOIN users u ON cm.created_by = u.id
          WHERE cm.credit_memo_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $credit_memo_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Credit Memo not found");
}

$creditMemo = $result->fetch_assoc();

// Determine currency and symbol
$currency = !empty($creditMemo['currency']) ? strtolower($creditMemo['currency']) : 'lkr';
$currencySymbol = strtoupper($currency);

// Calculate VAT percentage if invoice had VAT
$vat_pct = 0;
$has_vat = false;
$invoice_total = floatval($creditMemo['invoice_total'] ?? 0);
$invoice_vat = floatval($creditMemo['invoice_vat'] ?? 0);
$invoice_subtotal = floatval($creditMemo['invoice_subtotal'] ?? 0);
$invoice_discount = floatval($creditMemo['invoice_discount'] ?? 0);

if ($invoice_vat > 0 && ($invoice_subtotal - $invoice_discount) > 0) {
    $vat_pct = ($invoice_vat / ($invoice_subtotal - $invoice_discount)) * 100;
    $has_vat = true;
}

$credit_memo_amount = floatval($creditMemo['amount']);

// Proportional VAT and Subtotal calculation for credit memo
if ($has_vat) {
    // total = subtotal * (1 + vat_pct / 100)
    $cm_subtotal = $credit_memo_amount / (1 + ($vat_pct / 100));
    $cm_vat = $credit_memo_amount - $cm_subtotal;
} else {
    $cm_subtotal = $credit_memo_amount;
    $cm_vat = 0;
}

// Fetch invoice items to see if this is a full or partial credit memo
$items = [];
$is_full_credit = false;

if ($creditMemo['invoice_id']) {
    $itemSql = "SELECT ii.* FROM invoice_items ii WHERE ii.invoice_id = ?";
    $itemStmt = $conn->prepare($itemSql);
    $itemStmt->bind_param("i", $creditMemo['invoice_id']);
    $itemStmt->execute();
    $itemsResult = $itemStmt->get_result();
    while ($item = $itemsResult->fetch_assoc()) {
        $items[] = $item;
    }
    
    // If credit memo amount is close to invoice total, treat as full credit/cancellation
    if (abs($credit_memo_amount - $invoice_total) < 0.1) {
        $is_full_credit = true;
    }
}

// Company information
$company = getCompanyInfo($conn);

// Helper to convert number to words for the credit memo total
function convertNumberToWords($number) {
    $hyphen      = ' ';
    $conjunction = ' And ';
    $separator   = ', ';
    $negative    = 'Negative ';
    $decimal     = ' Point ';
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

    if ($number < 0) {
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

    return $string;
}

function convertAmountToWords($amount) {
    $amount = round($amount, 2);
    $parts = explode('.', number_format($amount, 2, '.', ''));
    $whole = intval($parts[0]);
    $cents = intval($parts[1]);
    
    $whole_words = convertNumberToWords($whole);
    $words = ucwords($whole_words);
    
    if ($cents > 0) {
        $cents_words = convertNumberToWords($cents);
        $words .= " And Cents " . ucwords($cents_words);
    }
    
    return $words . " Only";
}

$grand_total_words = convertAmountToWords($credit_memo_amount);

$showButtons = ($format === 'view');
$isModalView = ($format === 'html');
?>

<?php if (!$isModalView): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Credit Memo #<?php echo htmlspecialchars($creditMemo['credit_memo_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>css/style.css" rel="stylesheet" />
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

        .credit-memo-container {
            max-width: 900px;
            margin: 20px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        .company-header-centered {
            text-align: center;
            margin-bottom: 5px;
        }

        .company-header-centered h1 {
            color: #007bff;
            font-size: 20px;
            font-weight: 800;
            margin: 0;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header-divider-blue {
            border-bottom: 2.5px solid #007bff;
            margin-top: 5px;
            margin-bottom: 15px;
        }

        .credit-memo-title-centered {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }

        .credit-memo-title-centered h2 {
            font-size: 22px;
            font-weight: bold;
            color: #1B1C56;
            margin: 0;
            letter-spacing: 1.5px;
        }

        .watermark {
            position: absolute;
            top: 25px;
            left: 36%;
            transform: rotate(-15deg);
            border: 3px dashed #000;
            color: #000;
            font-size: 26px;
            font-weight: 900;
            padding: 4px 18px;
            letter-spacing: 3px;
            opacity: 0.75;
            z-index: 10;
            pointer-events: none;
            text-transform: uppercase;
        }

        .info-layout-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .info-layout-table td {
            vertical-align: top;
            padding: 0;
        }

        .customer-card-box {
            border: 1.5px solid #333;
            width: 450px;
        }

        .customer-card-header {
            border-bottom: 1.5px solid #333;
            padding: 4px 8px;
            font-weight: bold;
            font-size: 13px;
            background-color: #f8f9fa;
        }

        .customer-card-body {
            padding: 8px;
            line-height: 1.5;
            font-size: 12.5px;
        }

        .meta-boxes-col {
            text-align: right;
        }

        .meta-card-table {
            border-collapse: collapse;
            margin-left: auto;
            width: 250px;
            margin-bottom: 10px;
        }

        .meta-card-table th {
            border: 1.5px solid #333;
            padding: 4px 8px;
            text-align: center;
            font-weight: bold;
            background-color: #f8f9fa;
            width: 50%;
            font-size: 12px;
        }

        .meta-card-table td {
            border: 1.5px solid #333;
            padding: 4px 8px;
            text-align: center;
            font-size: 12px;
        }

        .item-details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .item-details-table th {
            border: 1.5px solid #333;
            padding: 6px 8px;
            font-weight: bold;
            background-color: #f8f9fa;
            font-size: 12px;
        }

        .item-details-table td {
            border: 1.5px solid #333;
            padding: 6px 8px;
            vertical-align: top;
            font-size: 12px;
        }

        .table-words-cell {
            border: 1.5px solid #333;
            padding: 8px;
            font-weight: bold;
            font-size: 11px;
            vertical-align: middle;
        }

        .totals-sidebar-table {
            border-collapse: collapse;
            width: 100%;
        }

        .totals-sidebar-table td {
            border: 1.5px solid #333;
            padding: 6px 8px;
            font-weight: bold;
            font-size: 12px;
        }

        .signature-text-section {
            margin-top: 30px;
            margin-bottom: 40px;
            font-size: 13px;
        }

        .bottom-footer-address {
            text-align: center;
            font-size: 10.5px;
            color: #007bff;
            line-height: 1.4;
        }

        .footer-line-blue {
            border-bottom: 2px solid #007bff;
            margin-top: 15px;
            margin-bottom: 8px;
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

        @media print {
            @page {
                size: A4;
                margin: 0.4in;
            }

            body {
                background-color: white;
                padding: 0;
                margin: 0;
            }

            .credit-memo-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                width: 100%;
                max-width: 100%;
            }

            .control-buttons {
                display: none !important;
            }

            .bottom-footer-address {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: white;
            }
        }
    </style>
<?php if (!$isModalView): ?>
</head>

<body>
<?php endif; ?>

    <div class="credit-memo-container">
        <?php if ($showButtons): ?>
            <div class="control-buttons">
                <button onclick="window.print()" class="btn btn-primary">Print Credit Memo</button>
            </div>
        <?php endif; ?>

        <!-- Company Header -->
        <table class="header-table" style="width: 100%; border-collapse: collapse; margin-bottom: 5px;">
            <tr>
                <td style="width: 25%; vertical-align: middle; text-align: left;">
                    <?php if (!empty($company['logo_path']) && file_exists(BASE_PATH . $company['logo_path'])): ?>
                        <img src="<?= BASE_URL . htmlspecialchars($company['logo_path']) ?>" alt="Logo" style="max-height: 70px; max-width: 140px;">
                    <?php else: ?>
                        <img src="<?= BASE_URL ?>assets/img/logo.png" onerror="this.style.display='none';" alt="" style="max-height: 70px; max-width: 140px;">
                    <?php endif; ?>
                </td>
                <td style="width: 50%; text-align: center; vertical-align: middle;">
                    <h1 style="color: #1B1C56; font-size: 22px; font-weight: 900; margin: 0; letter-spacing: 0.5px; text-transform: uppercase; white-space: nowrap;">
                        <?php echo htmlspecialchars($company['company_name']); ?>
                    </h1>
                </td>
                <td style="width: 25%;"></td>
            </tr>
        </table>
        <div class="header-divider-blue"></div>

        <!-- Title and Watermark -->
        <div class="credit-memo-title-centered">
            <h2>Credit Memo</h2>
            <div class="watermark">
                <?php echo ($creditMemo['status'] === 'cancelled') ? 'CANCELLED' : 'REFUNDED'; ?>
            </div>
        </div>

        <!-- Info Grid -->
        <table class="info-layout-table">
            <tr>
                <td>
                    <!-- Customer Details Box -->
                    <div class="customer-card-box">
                        <div class="customer-card-header">Customer</div>
                        <div class="customer-card-body">
                            <?php if (!empty($creditMemo['customer_business_name'])): ?>
                                <strong><?php echo htmlspecialchars($creditMemo['customer_business_name']); ?></strong><br>
                            <?php else: ?>
                                <strong><?php echo htmlspecialchars($creditMemo['customer_name']); ?></strong><br>
                            <?php endif; ?>
                            <?php if (!empty($creditMemo['customer_business_name']) && !empty($creditMemo['customer_name'])): ?>
                                Attn: <?php echo htmlspecialchars($creditMemo['customer_name']); ?><br>
                            <?php endif; ?>
                            <?php echo nl2br(htmlspecialchars($creditMemo['customer_address'])); ?>
                        </div>
                    </div>
                </td>
                <td class="meta-boxes-col">
                    <!-- Date & Credit No -->
                    <table class="meta-card-table">
                        <tr>
                            <th>Date</th>
                            <th>Credit No.</th>
                        </tr>
                        <tr>
                            <td><?php echo date('j/n/Y', strtotime($creditMemo['created_at'])); ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($creditMemo['credit_memo_no']); ?></td>
                        </tr>
                    </table>

                    <!-- Invoice & Project Ref -->
                    <table class="meta-card-table">
                        <tr>
                            <th>IN No.</th>
                            <th>Project</th>
                        </tr>
                        <tr>
                            <td><?php echo htmlspecialchars($creditMemo['invoice_ref_no'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($creditMemo['quotation_ref_no'] ?? ''); ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Main Items Table -->
        <table class="item-details-table">
            <thead>
                <tr>
                    <th width="55%" style="text-align: left;">Description</th>
                    <th width="10%" style="text-align: center;">Qty</th>
                    <th width="15%" style="text-align: right;">Rate</th>
                    <th width="20%" style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($is_full_credit && count($items) > 0): ?>
                    <?php foreach ($items as $item): 
                        $qty = $item['quantity'] ?? 1;
                        $subtotal = floatval($item['total_amount'] ?? 0);
                        $discount = floatval($item['discount'] ?? 0);
                        $unit_price = ($subtotal + $discount) / $qty;
                    ?>
                        <tr>
                            <td style="text-align: left;">
                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <br><span style="font-size: 11px; color: #555;"><?php echo nl2br(htmlspecialchars($item['description'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;"></td>
                            <td style="text-align: right;"><?php echo number_format($unit_price, 2); ?></td>
                            <td style="text-align: right;"><?php echo '-' . number_format($subtotal, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td style="text-align: left;">
                            <strong>Credit/Refund of payment made against Invoice <?php echo htmlspecialchars($creditMemo['invoice_ref_no'] ?? '#' . $creditMemo['invoice_id']); ?></strong>
                            <?php if (!empty($creditMemo['reason'])): ?>
                                <br><span style="font-size: 11px; color: #555;">Reason: <?php echo htmlspecialchars($creditMemo['reason']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;"></td>
                        <td style="text-align: right;"><?php echo number_format($cm_subtotal, 2); ?></td>
                        <td style="text-align: right;"><?php echo '-' . number_format($cm_subtotal, 2); ?></td>
                    </tr>
                <?php endif; ?>

                <!-- Bottom row splitting words on left, totals on right -->
                <tr>
                    <td colspan="2" class="table-words-cell">
                        <?php echo htmlspecialchars($grand_total_words); ?>
                    </td>
                    <td colspan="2" style="padding: 0; border: 1.5px solid #333; vertical-align: top;">
                        <table class="totals-sidebar-table">
                            <tr>
                                <td width="43%">Subtotal</td>
                                <td width="57%" style="text-align: right;"><?php echo $currencySymbol . ' -' . number_format($cm_subtotal, 2); ?></td>
                            </tr>
                            <?php if ($cm_vat > 0): ?>
                                <tr>
                                    <td>Sales Tax (<?php echo number_format($vat_pct, 1); ?>%)</td>
                                    <td style="text-align: right;"><?php echo $currencySymbol . ' -' . number_format($cm_vat, 2); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td>Total</td>
                                <td style="text-align: right;"><?php echo $currencySymbol . ' -' . number_format($credit_memo_amount, 2); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Yours Faithfully / Sender Section -->
        <div class="signature-text-section">
            <strong>For <?php echo htmlspecialchars($company['company_name']); ?></strong>
        </div>

        <!-- Footer Address & Contacts -->
        <div class="bottom-footer-address">
            <div class="footer-line-blue"></div>
            <?php 
            $footer_addr = $company['address'] ? htmlspecialchars(str_replace("\n", ", ", $company['address'])) : '';
            echo $footer_addr;
            if (!empty($company['phone']) || !empty($company['email'])):
                echo "<br>";
                if ($company['phone']) echo "Hot line: " . htmlspecialchars($company['phone']) . " ";
                if ($company['email']) echo "E-Mail: " . htmlspecialchars($company['email']) . " ";
            endif;
            ?>
        </div>
    </div>

<?php if (!$isModalView): ?>
</body>
</html>
<?php endif; ?>
<?php
$conn->close();
?>
