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
    die("ID missing.");
}

$id = intval($_GET['id']);

// Fetch Price List Info
$sql = "SELECT * FROM price_lists WHERE id = $id";
$result = $conn->query($sql);
$price_list = $result->fetch_assoc();

if (!$price_list) {
    die("Price list not found.");
}

// Fetch Items Grouped by Asset
$itemSql = "SELECT pli.*, d.name as asset_name 
            FROM price_list_items pli 
            LEFT JOIN assets d ON pli.asset_id = d.id 
            WHERE pli.price_list_id = $id 
            ORDER BY d.name, pli.id";
$itemResult = $conn->query($itemSql);

$itemsByAsset = [];
while ($row = $itemResult->fetch_assoc()) {
    $itemsByAsset[$row['asset_name']][] = $row;
}

// Fetch customer info if associated
$customerInfo = null;
if (!empty($price_list['customer_id'])) {
    $custSql = "SELECT * FROM customers WHERE customer_id = " . intval($price_list['customer_id']);
    $custResult = $conn->query($custSql);
    $customerInfo = $custResult->fetch_assoc();
}

$company = getCompanyInfo($conn);

$currencySymbol = ($price_list['currency'] == 'usd') ? '$' : 'Rs.';
$format = isset($_GET['format']) ? $_GET['format'] : 'view';
$isModalView = ($format === 'html');
?>

<?php if (!$isModalView): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Price List PL-<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?></title>
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

        .price-list-container {
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

        .pl-title-centered {
            text-align: center;
            margin-bottom: 10px;
        }

        .pl-title-centered h2 {
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

        .pl-meta-cell {
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

        .asset-header {
            background: #f8fafc;
            padding: 5px 8px;
            border-left: 3px solid #1B1C56;
            margin-top: 10px;
            margin-bottom: 5px;
            font-weight: bold;
            font-size: 11px;
            color: #1B1C56;
        }

        .notes-section {
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .notes-section strong {
            display: block;
            margin-bottom: 3px;
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

        .footer-group-container {
            page-break-inside: avoid;
            break-inside: avoid;
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

            .price-list-container {
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

            .header-table, .info-table, .product-table, .notes-section,
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
    </style>
<?php if (!$isModalView): ?>
</head>

<body>
<?php endif; ?>

    <div class="price-list-container">
        <?php if (!$isModalView): ?>
        <div class="control-buttons">
        </div>
        <?php endif; ?>

        <table class="header-table">
            <tr>
                <td class="header-spacer-cell"></td>
                <td class="header-title-cell">
                    <h1 style="white-space: nowrap;"><?= htmlspecialchars($company['company_name']) ?></h1>
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

        <div class="pl-title-centered">
            <h2>PRICE LIST</h2>
        </div>

        <table class="info-table">
            <tr>
                <td class="client-info-cell">
                    <?php if ($customerInfo): ?>
                        <strong>M/S. <?= htmlspecialchars($customerInfo['name'] ?? '') ?></strong><br>
                        <?php if (!empty($customerInfo['business_name'])): ?>
                            <?= htmlspecialchars($customerInfo['business_name']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($customerInfo['email'])): ?>
                            <?= htmlspecialchars($customerInfo['email']) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($customerInfo['phone'])): ?>
                            <?= htmlspecialchars($customerInfo['phone']) ?><br>
                        <?php endif; ?>
                        <?= nl2br(htmlspecialchars($customerInfo['address'] ?? '')) ?>
                    <?php else: ?>
                        <em>No customer associated</em>
                    <?php endif; ?>
                </td>
                <td class="pl-meta-cell">
                    <strong>Date :</strong> <?= date('j/n/Y', strtotime($price_list['price_list_date'])) ?><br>
                    <strong>Ref No :</strong> PL-<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?>
                </td>
            </tr>
        </table>

        <?php foreach ($itemsByAsset as $assetName => $items): ?>
            <div class="asset-header"><?= htmlspecialchars($assetName) ?></div>
            <table class="product-table">
                <thead>
                    <tr>
                        <th width="8%" style="text-align: center;">S.NO</th>
                        <th width="40%">ITEM DETAILS</th>
                        <th width="32%">DESCRIPTION</th>
                        <th width="20%" style="text-align: right;">PRICE (<?= strtoupper($price_list['currency']) ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td style="text-align: center;"><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                            <td><?= htmlspecialchars($item['description'] ?: '-') ?></td>
                            <td style="text-align: right;"><?= $currencySymbol ?> <?= number_format($item['price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <?php if (!empty($price_list['payment_terms']) || !empty($price_list['terms_conditions']) || !empty($price_list['notes'])): ?>
            <div class="notes-section">
                <strong>Notes / Terms :</strong>
                <?php if (!empty($price_list['payment_terms'])): ?>
                    <p style="margin-top: 5px; line-height: 1.5; white-space: pre-line;"><strong>Payment Terms:</strong> <?= nl2br(htmlspecialchars($price_list['payment_terms'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($price_list['terms_conditions'])): ?>
                    <p style="margin-top: 5px; line-height: 1.5; white-space: pre-line;"><strong>Terms & Conditions:</strong> <?= nl2br(htmlspecialchars($price_list['terms_conditions'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($price_list['notes'])): ?>
                    <p style="margin-top: 5px; line-height: 1.5; white-space: pre-line;"><?= nl2br(htmlspecialchars($price_list['notes'])) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer-group-container">
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
</body>

</html>
<?php endif; ?>
<?php
$conn->close();
?>