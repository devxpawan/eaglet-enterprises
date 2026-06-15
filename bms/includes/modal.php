<?php
require_once __DIR__ . '/../config/paths.php';

$modal_title = $modal_title ?? 'Inquiry Details';
$modal_icon = $modal_icon ?? 'fa-envelope-open-text';
$modal_id = $modal_id ?? ('viewModal' . ($row['id'] ?? ''));
$modal_type = $modal_type ?? 'inquiry';
$modal_body_id = $modal_body_id ?? '';
?>
<!-- View Modal -->
<div class="modal fade" id="<?= $modal_id ?>" tabindex="-1"
    aria-labelledby="<?= $modal_id ?>Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-system">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="<?= $modal_id ?>Label"><i class="fas <?= $modal_icon ?> me-2"></i><?= $modal_title ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"<?= $modal_body_id ? ' id="' . $modal_body_id . '"' : '' ?>>
<?php if ($modal_type === 'customer'): ?>
                <!-- Populated by JS -->
<?php else: ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="view-detail-box">
                            <span class="detail-label">Name</span>
                            <p class="detail-value"><?= htmlspecialchars($row['first_name']) . ' ' . htmlspecialchars($row['last_name']) ?></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-detail-box">
                            <span class="detail-label">Email</span>
                            <p class="detail-value"><?= htmlspecialchars($row['email']) ?></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-detail-box">
                            <span class="detail-label">Company</span>
                            <p class="detail-value"><?= htmlspecialchars($row['company']) ?: '<em class="text-muted">N/A</em>' ?></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="view-detail-box">
                            <span class="detail-label">Created At</span>
                            <p class="detail-value"><?= htmlspecialchars($row['created_at']) ?></p>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="view-detail-box">
                            <span class="detail-label">Status</span>
                            <p class="detail-value">
                                <?php if ($row['status'] === 'approved'): ?>
                                    <span class="badge-soft badge-soft-success">Approved</span>
                                <?php elseif ($row['status'] === 'rejected'): ?>
                                    <span class="badge-soft badge-soft-danger">Rejected</span>
                                <?php else: ?>
                                    <span class="badge-soft badge-soft-warning">Pending</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <hr>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Message</small>
                    <div class="p-3 bg-light rounded border mt-2">
                        <?= nl2br(htmlspecialchars($row['mesage'])) ?>
                    </div>
                </div>
<?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
