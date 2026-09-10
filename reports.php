<?php
/**
 * Swapify — Reports
 * Frontend only. "Report something" modal is AJAX-ready (reports.js)
 * against a placeholder /api/report.php endpoint.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$myReports = [
    ['type' => 'Listing', 'target' => 'Vintage Camera Bundle', 'reason' => 'Misleading description', 'status' => 'pending', 'date' => '2 days ago'],
    ['type' => 'Comment', 'target' => 'Comment on "PS5 + Controllers"', 'reason' => 'Spam', 'status' => 'accepted', 'date' => '1 week ago'],
    ['type' => 'User', 'target' => 'user_2847', 'reason' => 'Harassment', 'status' => 'rejected', 'date' => '3 weeks ago'],
];
$statusLabel = ['pending' => 'Under review', 'accepted' => 'Resolved', 'rejected' => 'Dismissed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:760px;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">Reports</h2>
                    <p class="text-muted-swap mb-0">Track reports you've submitted, or flag something new.</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newReportModal">
                    <i class="bi bi-flag me-1"></i> Report something
                </button>
            </div>

            <?php if (empty($myReports)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-flag"></i></div>
                    <h5>No reports yet</h5>
                    <p>If something on Swapify doesn't look right, let us know and we'll take a look.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newReportModal">Report something</button>
                </div>
            <?php else: ?>
                <?php foreach ($myReports as $report): ?>
                    <div class="dash-panel mb-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge-condition"><?= $report['type'] ?></span>
                                    <span class="fw-semibold small"><?= $report['target'] ?></span>
                                </div>
                                <div class="text-muted-swap small">Reason: <?= $report['reason'] ?> &middot; <?= $report['date'] ?></div>
                            </div>
                            <span class="status-badge <?= $report['status'] ?>"><?= $statusLabel[$report['status']] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============================== NEW REPORT MODAL ============================== -->
    <div class="modal fade" id="newReportModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Report something</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label form-label-swap">What are you reporting?</label>
                    <select class="form-select mb-3" id="reportType" style="border-radius:12px;">
                        <option value="listing">A listing</option>
                        <option value="user">A user</option>
                        <option value="comment">A comment</option>
                    </select>

                    <label for="reportTarget" class="form-label form-label-swap">Name or link</label>
                    <input type="text" class="form-control mb-3" id="reportTarget" style="border-radius:12px;" placeholder="e.g. Canon AE-1 Film Camera">
                    <div class="invalid-feedback">Tell us what you're reporting.</div>

                    <label for="reportReason" class="form-label form-label-swap">Reason</label>
                    <select class="form-select mb-3" id="reportReason" style="border-radius:12px;">
                        <option>Spam</option>
                        <option>Misleading description</option>
                        <option>Harassment</option>
                        <option>Prohibited item</option>
                        <option>Suspected scam</option>
                        <option>Other</option>
                    </select>

                    <label for="reportDescription" class="form-label form-label-swap">Additional details</label>
                    <textarea class="form-control" id="reportDescription" rows="3" maxlength="500" style="border-radius:12px;"
                              placeholder="Add any details that will help our team review this"></textarea>
                    <div class="text-end mt-1">
                        <span class="comment-char-counter" id="reportDescCounter">0 / 500</span>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" id="submitNewReportBtn">Submit report</button>
                </div>
            </div>
        </div>
    </div>

    <?php component('footer'); ?>

    <?php loadScripts('reports'); ?>
</body>
</html>