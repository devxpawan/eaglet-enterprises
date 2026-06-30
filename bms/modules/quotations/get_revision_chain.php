<?php
require_once __DIR__ . '/../../config/paths.php';
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    exit();
}
require_once BASE_PATH . 'includes/db_connection.php';
require_once BASE_PATH . 'includes/functions.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo '<p class="text-muted">No quotation ID provided.</p>';
    exit();
}

$quotation_id = (int)$_GET['id'];
$chain = getQuotationRevisionChain($conn, $quotation_id);

if (empty($chain)) {
    echo '<p class="text-muted">No revision history available.</p>';
    $conn->close();
    exit();
}

echo '<ul class="revision-timeline">';
foreach ($chain as $rev) {
    $rev_label_item = ($rev['revision_no'] == 0) ? 'Original' : 'R' . $rev['revision_no'];
    $status_class = strtolower($rev['status']);
    $status_badge_class = $status_class === 'revised' ? 'warning' : $status_class;
    $is_draft = ($rev['quotation_id'] == $quotation_id);
?>
    <li class="revision-timeline-item <?php echo $is_draft ? 'active' : ($rev['status'] === 'Revised' ? 'revised' : ''); ?>">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <strong><?php echo htmlspecialchars($rev['ref_no'] ?? $rev_label_item); ?></strong>
                <span class="badge-soft badge-soft-<?php echo $status_badge_class; ?>" style="font-size: 10px; padding: 1px 8px; margin-left: 6px;">
                    <?php echo htmlspecialchars($rev['status']); ?>
                </span>
                <?php if ($is_draft): ?>
                    <span class="badge-revision" style="font-size: 9px; background: #3B82F6; color: #fff; border-color: #3B82F6;">Current</span>
                <?php endif; ?>
                <br>
                <small class="text-muted">
                    <?php echo date('d/m/Y', strtotime($rev['issue_date'])); ?> |
                    Amount: <?php echo number_format($rev['total_amount'], 2); ?>
                </small>
                <div class="mt-1">
                    <small class="text-muted">
                        Customer: <?php echo htmlspecialchars($rev['customer_business_name'] ?: $rev['customer_name']); ?>
                    </small>
                </div>
            </div>
            <div>
                <a href="<?= BASE_URL ?>modules/quotations/download_quotation.php?id=<?php echo $rev['quotation_id']; ?>" class="btn btn-sm btn-outline-secondary" title="View" target="_blank">
                    <i class="fas fa-eye"></i>
                </a>
            </div>
        </div>
    </li>
<?php
}
echo '</ul>';
$conn->close();
?>