<?php
/**
 * Swapify Admin — Manage Reports
 * Now wired to real data:
 *   - `reports` table (target_type = 'listing' | 'user') — has status,
 *     resolved_by, resolved_at, so Resolve is a real state change.
 *   - `comment_reports` table — no status column, so Resolve and Dismiss
 *     both just delete the row (nothing persists as "resolved" there yet).
 *
 * Place this file at admin/reports.php. Guarded the same way as
 * dashboard.php: $_SESSION['admin_id'] set by admin/login.php.
 */
session_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../connection.php';

// ===================== AUTH GUARD =====================
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$currentAdminId = (int) $_SESSION['admin_id'];

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) { $m = floor($diff / 60); return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
        if ($diff < 86400) { $h = floor($diff / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
        if ($diff < 604800) { $d = floor($diff / 86400); return $d . ' day' . ($d === 1 ? '' : 's') . ' ago'; }
        $w = floor($diff / 604800); return $w . ' week' . ($w === 1 ? '' : 's') . ' ago';
    }
}

// ===================== HANDLE RESOLVE (reports table — listing/user) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve_report') {
    header('Content-Type: application/json');

    $reportId = (int) ($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid report.']);
        exit;
    }

    $stmt = mysqli_prepare(
        $connection,
        "UPDATE reports SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $currentAdminId, $reportId);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not resolve report: ' . mysqli_stmt_error($stmt)]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

// ===================== HANDLE DISMISS/DELETE (reports table — listing/user) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_report') {
    header('Content-Type: application/json');

    $reportId = (int) ($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid report.']);
        exit;
    }

    $stmt = mysqli_prepare($connection, "DELETE FROM reports WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $reportId);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not delete report: ' . mysqli_stmt_error($stmt)]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

// ===================== HANDLE RESOLVE/DISMISS (comment_reports — no status column, both delete) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['resolve_comment_report', 'delete_comment_report'], true)) {
    header('Content-Type: application/json');

    $reportId = (int) ($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid report.']);
        exit;
    }

    $stmt = mysqli_prepare($connection, "DELETE FROM comment_reports WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $reportId);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not update comment report: ' . mysqli_stmt_error($stmt)]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

// ===================== RENDER PAGE (GET) =====================

// ---------- Reported listings ----------
$reportedListings = [];
$listStmt = mysqli_prepare(
    $connection,
    "SELECT r.id, r.reason, r.description, r.created_at,
            l.title AS listing_title,
            reporter.name AS reporter_name, reporter.username AS reporter_username
     FROM reports r
     LEFT JOIN listings l ON l.id = r.target_id
     LEFT JOIN users reporter ON reporter.id = r.reporter_id
     WHERE r.target_type = 'listing' AND r.status = 'pending'
     ORDER BY r.created_at DESC"
);
mysqli_stmt_execute($listStmt);
$listResult = mysqli_stmt_get_result($listStmt);
while ($row = mysqli_fetch_assoc($listResult)) {
    $reportedListings[] = [
        'id'       => $row['id'],
        'target'   => $row['listing_title'] ?: 'Listing #' . $row['id'] . ' (deleted)',
        'reporter' => $row['reporter_name'] ?: ($row['reporter_username'] ?: 'Unknown'),
        'reason'   => $row['reason'],
        'detail'   => $row['description'],
        'date'     => timeAgo($row['created_at']),
    ];
}
mysqli_stmt_close($listStmt);

// ---------- Reported users ----------
$reportedUsers = [];
$userStmt = mysqli_prepare(
    $connection,
    "SELECT r.id, r.reason, r.description, r.created_at,
            target.name AS target_name, target.username AS target_username,
            reporter.name AS reporter_name, reporter.username AS reporter_username
     FROM reports r
     LEFT JOIN users target ON target.id = r.target_id
     LEFT JOIN users reporter ON reporter.id = r.reporter_id
     WHERE r.target_type = 'user' AND r.status = 'pending'
     ORDER BY r.created_at DESC"
);
mysqli_stmt_execute($userStmt);
$userResult = mysqli_stmt_get_result($userStmt);
while ($row = mysqli_fetch_assoc($userResult)) {
    $reportedUsers[] = [
        'id'       => $row['id'],
        'target'   => $row['target_name'] ?: ($row['target_username'] ?: ('User #' . $row['id'] . ' (deleted)')),
        'reporter' => $row['reporter_name'] ?: ($row['reporter_username'] ?: 'Unknown'),
        'reason'   => $row['reason'],
        'detail'   => $row['description'],
        'date'     => timeAgo($row['created_at']),
    ];
}
mysqli_stmt_close($userStmt);

// ---------- Reported comments ----------
$reportedComments = [];
$commentStmt = mysqli_prepare(
    $connection,
    "SELECT cr.id, cr.reason, cr.details, cr.created_at,
            l.title AS listing_title,
            reporter.name AS reporter_name, reporter.username AS reporter_username
     FROM comment_reports cr
     LEFT JOIN comments c ON c.id = cr.comment_id
     LEFT JOIN listings l ON l.id = c.listing_id
     LEFT JOIN users reporter ON reporter.id = cr.user_id
     ORDER BY cr.created_at DESC"
);
mysqli_stmt_execute($commentStmt);
$commentResult = mysqli_stmt_get_result($commentStmt);
while ($row = mysqli_fetch_assoc($commentResult)) {
    $reportedComments[] = [
        'id'       => $row['id'],
        'target'   => $row['listing_title'] ? ('Comment on "' . $row['listing_title'] . '"') : 'Comment (deleted)',
        'reporter' => $row['reporter_name'] ?: ($row['reporter_username'] ?: 'Unknown'),
        'reason'   => $row['reason'],
        'detail'   => $row['details'],
        'date'     => timeAgo($row['created_at']),
    ];
}
mysqli_stmt_close($commentStmt);

/** Renders one report table. $type controls which JS actions/data attrs get wired. */
function renderReportsTable($reports, $targetLabel, $type) {
    ?>
    <div class="admin-table-card">
        <div class="table-responsive">
            <table class="table admin-table">
                <thead>
                    <tr>
                        <th><?= $targetLabel ?></th>
                        <th>Reported by</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr><td colspan="6" class="text-center text-muted-swap py-4">No reports in this category.</td></tr>
                    <?php else: foreach ($reports as $r): ?>
                        <tr data-report-id="<?= $r['id'] ?>" data-report-type="<?= $type ?>">
                            <td class="fw-semibold"><?= htmlspecialchars($r['target']) ?></td>
                            <td class="text-muted-swap"><?= htmlspecialchars($r['reporter']) ?></td>
                            <td class="text-muted-swap" title="<?= htmlspecialchars($r['detail'] ?? '') ?>"><?= htmlspecialchars($r['reason']) ?></td>
                            <td class="text-muted-swap"><?= htmlspecialchars($r['date']) ?></td>
                            <td><span class="status-badge pending">Pending</span></td>
                            <td>
                                <div class="d-flex justify-content-end gap-1">
                                    <button class="admin-row-icon-btn success resolve-report-btn" aria-label="Resolve"><i class="bi bi-check-lg"></i></button>
                                    <button class="admin-row-icon-btn danger delete-report-btn" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reports — Swapify Admin</title>
    <?php loadBootstrap(); ?>
</head>
<body>

<div class="dashboard-shell">

    <?php component('admin_sidebar'); ?>

    <div class="dashboard-main">
        <div class="dashboard-topbar d-flex align-items-center gap-2">
            <button class="btn btn-light-swap d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileAdminSidebar">
                <i class="bi bi-list"></i>
            </button>
            <span class="fw-semibold">Manage Reports</span>
        </div>

        <div class="dashboard-content">
            <div class="mb-4">
                <h2 class="mb-1">Reports</h2>
                <p class="text-muted-swap mb-0"><?= count($reportedListings) + count($reportedUsers) + count($reportedComments) ?> reports awaiting review</p>
            </div>

            <ul class="nav trade-request-tabs mb-4">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tabRepListings" type="button">Listings (<?= count($reportedListings) ?>)</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabRepUsers" type="button">Users (<?= count($reportedUsers) ?>)</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabRepComments" type="button">Comments (<?= count($reportedComments) ?>)</button></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tabRepListings">
                    <?php renderReportsTable($reportedListings, 'Listing', 'listing'); ?>
                </div>
                <div class="tab-pane fade" id="tabRepUsers">
                    <?php renderReportsTable($reportedUsers, 'User', 'user'); ?>
                </div>
                <div class="tab-pane fade" id="tabRepComments">
                    <?php renderReportsTable($reportedComments, 'Comment', 'comment'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div class="modal fade" id="deleteReportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px; border:none;">
            <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                    <i class="bi bi-trash text-danger fs-4"></i>
                </div>
                <h5 class="mb-2">Dismiss this report?</h5>
                <p class="text-muted-swap mb-4">This removes the report without taking action on the reported content.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-light-swap px-4" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger px-4" id="confirmDeleteReportBtn">Dismiss report</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile sidebar -->
<div class="offcanvas offcanvas-start" id="mobileAdminSidebar">
    <div class="offcanvas-header">
        <span class="navbar-brand-swapify"><span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span> Swapify Admin</span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0"><?php component('admin_sidebar'); ?></div>
</div>

<?php loadScripts('admin_reports'); ?>
</body>
</html>