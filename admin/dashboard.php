<?php
/**
 * Swapify Admin — Dashboard
 * Guarded by $_SESSION['admin_id'] (set by admin/login.php) — anyone
 * without a valid admin session is redirected to login immediately.
 * Data now real: stats, latest users, latest listings, recent activity,
 * open reports — combining BOTH report sources: `reports` (listing/user
 * reports, has a status column) and `comment_reports` (comment reports,
 * no status column — every row counts as open).
 */
session_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../components/cards.php';
require_once __DIR__ . '/../connection.php';

// ===================== AUTH GUARD =====================
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// ===================== Stats =====================
$totalUsersCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM users"))['cnt'];
$todayStart = date('Y-m-d 00:00:00');
$newUsersTodayCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM users WHERE created_at >= '$todayStart'"))['cnt'];

$activeListingsCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM listings WHERE status = 'active'"))['cnt'];
$newListingsTodayCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM listings WHERE status = 'active' AND created_at >= '$todayStart'"))['cnt'];

$tradesCompletedCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted'"))['cnt'];

// Open reports = pending listing/user reports (reports.status = 'pending')
// PLUS every comment report (comment_reports has no status column at all,
// so every row there is inherently still "open").
$pendingListingUserReports = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM reports WHERE status = 'pending'"))['cnt'];
$commentReportsCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM comment_reports"))['cnt'];
$openReportsCount = $pendingListingUserReports + $commentReportsCount;

// ===================== Latest users (5 most recent) =====================
$latestUsersStmt = mysqli_query($connection, "SELECT name, email, created_at FROM users ORDER BY created_at DESC LIMIT 5");
$latestUsers = [];
while ($row = mysqli_fetch_assoc($latestUsersStmt)) {
    $displayName = $row['name'] ?: ucfirst(explode('@', $row['email'])[0]);
    $initials = strtoupper(substr($displayName, 0, 1) . (strpos($displayName, ' ') !== false ? substr(strstr($displayName, ' '), 1, 1) : substr($displayName, 1, 1)));
    $latestUsers[] = [
        'initials' => $initials,
        'name'     => $displayName,
        'email'    => $row['email'],
        'joined'   => $row['created_at'],
    ];
}

// ===================== Latest listings (5 most recent) =====================
$latestListingsStmt = mysqli_query(
    $connection,
    "SELECT l.title, l.status, u.name, u.email
     FROM listings l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC
     LIMIT 5"
);
$latestListings = [];
while ($row = mysqli_fetch_assoc($latestListingsStmt)) {
    $ownerName = $row['name'] ?: ucfirst(explode('@', $row['email'])[0]);
    $latestListings[] = [
        'icon'   => 'bi-box-seam',
        'title'  => $row['title'],
        'user'   => $ownerName,
        'status' => $row['status'],
    ];
}

// Map real listing statuses (active/traded/deleted) to display labels/badge classes.
// Your status-badge CSS defines pending/accepted/rejected/cancelled — listings
// use different statuses, so I mapped the closest visual equivalent.
$statusLabel = ['active' => 'Active', 'traded' => 'Traded', 'deleted' => 'Removed'];
$statusBadgeClass = ['active' => 'accepted', 'traded' => 'accepted', 'deleted' => 'rejected'];

// ===================== Recent activity (merged: new users + trades + BOTH report sources) =====================
$activity = [];

$recentUsersStmt = mysqli_query($connection, "SELECT name, email, created_at FROM users ORDER BY created_at DESC LIMIT 3");
while ($row = mysqli_fetch_assoc($recentUsersStmt)) {
    $displayName = $row['name'] ?: ucfirst(explode('@', $row['email'])[0]);
    $activity[] = ['icon' => 'bi-person-plus', 'title' => 'New user registered', 'desc' => $displayName . ' joined Swapify', 'time' => $row['created_at']];
}

$recentTradesStmt = mysqli_query(
    $connection,
    "SELECT tr.updated_at, ureq.name AS req_name, ureq.email AS req_email, uown.name AS own_name, uown.email AS own_email
     FROM trade_requests tr
     LEFT JOIN users ureq ON ureq.id = tr.requester_id
     LEFT JOIN users uown ON uown.id = tr.owner_id
     WHERE tr.status = 'accepted'
     ORDER BY tr.updated_at DESC LIMIT 3"
);
while ($row = mysqli_fetch_assoc($recentTradesStmt)) {
    $reqName = $row['req_name'] ?: ucfirst(explode('@', $row['req_email'])[0]);
    $ownName = $row['own_name'] ?: ucfirst(explode('@', $row['own_email'])[0]);
    $activity[] = ['icon' => 'bi-check2-circle', 'title' => 'Trade completed', 'desc' => $reqName . ' and ' . $ownName, 'time' => $row['updated_at']];
}

// Listing/user reports (reports table) — target_type tells us which it is
$recentListingUserReportsStmt = mysqli_query(
    $connection,
    "SELECT reason, target_type, created_at FROM reports WHERE status = 'pending' ORDER BY created_at DESC LIMIT 3"
);
while ($row = mysqli_fetch_assoc($recentListingUserReportsStmt)) {
    $label = $row['target_type'] === 'user' ? 'User' : 'Listing';
    $activity[] = ['icon' => 'bi-flag', 'title' => 'New report submitted', 'desc' => $label . ' flagged: ' . $row['reason'], 'time' => $row['created_at']];
}

// Comment reports (comment_reports table)
$recentCommentReportsStmt = mysqli_query($connection, "SELECT reason, created_at FROM comment_reports ORDER BY created_at DESC LIMIT 3");
while ($row = mysqli_fetch_assoc($recentCommentReportsStmt)) {
    $activity[] = ['icon' => 'bi-flag', 'title' => 'New report submitted', 'desc' => 'Comment flagged: ' . $row['reason'], 'time' => $row['created_at']];
}

usort($activity, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
$activity = array_slice($activity, 0, 5);

function adminTimeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return floor($diff / 604800) . ' weeks ago';
}

// ===================== Open reports (BOTH sources, merged, latest 3) =====================
$openReports = [];

// Listing/user reports
$openListingUserStmt = mysqli_query(
    $connection,
    "SELECT r.reason, r.target_type, r.target_id, r.created_at,
            l.title AS listing_title, tu.name AS target_user_name, tu.username AS target_user_username
     FROM reports r
     LEFT JOIN listings l ON l.id = r.target_id AND r.target_type = 'listing'
     LEFT JOIN users tu ON tu.id = r.target_id AND r.target_type = 'user'
     WHERE r.status = 'pending'
     ORDER BY r.created_at DESC
     LIMIT 3"
);
while ($row = mysqli_fetch_assoc($openListingUserStmt)) {
    if ($row['target_type'] === 'user') {
        $title = $row['target_user_name'] ?: ($row['target_user_username'] ?: 'User #' . $row['target_id']);
    } else {
        $title = $row['listing_title'] ?: ('Listing #' . $row['target_id']);
    }
    $openReports[] = [
        'title'  => $title,
        'reason' => $row['reason'],
        'time'   => $row['created_at'],
    ];
}

// Comment reports
$openCommentReportsStmt = mysqli_query(
    $connection,
    "SELECT cr.reason, cr.created_at, c.content
     FROM comment_reports cr
     LEFT JOIN comments c ON c.id = cr.comment_id
     ORDER BY cr.created_at DESC
     LIMIT 3"
);
while ($row = mysqli_fetch_assoc($openCommentReportsStmt)) {
    $openReports[] = [
        'title'  => $row['content'] ? (mb_strlen($row['content']) > 40 ? mb_substr($row['content'], 0, 40) . '...' : $row['content']) : 'Comment removed',
        'reason' => $row['reason'],
        'time'   => $row['created_at'],
    ];
}

usort($openReports, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
$openReports = array_slice($openReports, 0, 3);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

<div class="dashboard-shell">

    <?php component('admin_sidebar'); ?>

    <div class="dashboard-main">

        <!-- ============================== TOPBAR ============================== -->
        <div class="dashboard-topbar d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light-swap d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileAdminSidebar">
                    <i class="bi bi-list"></i>
                </button>
                <span class="fw-semibold">Dashboard</span>
            </div>
            <div class="dropdown">
                <button class="btn btn-light-swap d-flex align-items-center gap-2 rounded-pill" data-bs-toggle="dropdown">
                    <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:0.75rem;">AD</span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                    <li><a class="dropdown-item rounded-3" href="<?= asset('index.php') ?>"><i class="bi bi-box-arrow-up-right me-2"></i>View site</a></li>
                    <li><a class="dropdown-item rounded-3 text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
                </ul>
            </div>
        </div>

        <!-- ============================== CONTENT ============================== -->
        <div class="dashboard-content">

            <!-- Stats -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3"><?php statCard('bi-people', 'Total Users', number_format($totalUsersCount), $newUsersTodayCount > 0 ? ['positive' => true, 'text' => "+{$newUsersTodayCount} today"] : null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-box-seam', 'Active Listings', number_format($activeListingsCount), $newListingsTodayCount > 0 ? ['positive' => true, 'text' => "+{$newListingsTodayCount} today"] : null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-arrow-repeat', 'Trades Completed', number_format($tradesCompletedCount), null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-flag', 'Open Reports', number_format($openReportsCount), null); ?></div>
            </div>

            <!-- Charts (still placeholders — no charting library wired up) -->
            <div class="row g-3 mb-4">
                <div class="col-lg-8">
                    <div class="dash-panel h-100">
                        <div class="dash-panel-title">Trade activity (last 30 days)</div>
                        <div class="chart-placeholder">
                            <i class="bi bi-bar-chart-line"></i>
                            <span>Chart will render here once analytics are connected</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="dash-panel h-100">
                        <div class="dash-panel-title">User growth</div>
                        <div class="chart-placeholder">
                            <i class="bi bi-graph-up-arrow"></i>
                            <span>Chart placeholder</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Latest users -->
                <div class="col-lg-6">
                    <div class="dash-panel mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="dash-panel-title mb-0">Latest users</div>
                            <a href="users.php" class="small text-decoration-none">View all</a>
                        </div>
                        <?php if (empty($latestUsers)): ?>
                            <div class="text-center text-muted-swap small py-3">No users yet.</div>
                        <?php else: ?>
                            <?php foreach ($latestUsers as $user): ?>
                                <div class="admin-activity-row">
                                    <div class="profile-card-avatar"><?= htmlspecialchars($user['initials']) ?></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold small"><?= htmlspecialchars($user['name']) ?></div>
                                        <div class="text-muted-swap small"><?= htmlspecialchars($user['email']) ?></div>
                                    </div>
                                    <span class="text-muted-swap small"><?= adminTimeAgo($user['joined']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Recent activity -->
                    <div class="dash-panel">
                        <div class="dash-panel-title">Recent activity</div>
                        <?php if (empty($activity)): ?>
                            <div class="text-center text-muted-swap small py-3">No recent activity.</div>
                        <?php else: ?>
                            <?php foreach ($activity as $item): ?>
                                <div class="activity-item">
                                    <div class="activity-icon"><i class="bi <?= $item['icon'] ?>"></i></div>
                                    <div>
                                        <div class="small fw-semibold"><?= htmlspecialchars($item['title']) ?></div>
                                        <div class="text-muted-swap small"><?= htmlspecialchars($item['desc']) ?> &middot; <?= adminTimeAgo($item['time']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Latest listings + reports -->
                <div class="col-lg-6">
                    <div class="dash-panel mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="dash-panel-title mb-0">Latest listings</div>
                            <a href="listings.php" class="small text-decoration-none">View all</a>
                        </div>
                        <?php if (empty($latestListings)): ?>
                            <div class="text-center text-muted-swap small py-3">No listings yet.</div>
                        <?php else: ?>
                            <?php foreach ($latestListings as $item): ?>
                                <div class="admin-activity-row">
                                    <div class="admin-mini-thumb"><i class="bi <?= $item['icon'] ?>"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold small"><?= htmlspecialchars($item['title']) ?></div>
                                        <div class="text-muted-swap small">by <?= htmlspecialchars($item['user']) ?></div>
                                    </div>
                                    <span class="status-badge <?= $statusBadgeClass[$item['status']] ?? 'pending' ?>"><?= $statusLabel[$item['status']] ?? ucfirst($item['status']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="dash-panel">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="dash-panel-title mb-0">Open reports</div>
                            <a href="reports.php" class="small text-decoration-none">View all</a>
                        </div>
                        <?php if (empty($openReports)): ?>
                            <div class="text-center text-muted-swap small py-3">No open reports.</div>
                        <?php else: ?>
                            <?php foreach ($openReports as $report): ?>
                                <div class="admin-activity-row">
                                    <div class="admin-mini-thumb text-danger"><i class="bi bi-flag-fill"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold small"><?= htmlspecialchars($report['title']) ?></div>
                                        <div class="text-muted-swap small">Reason: <?= htmlspecialchars($report['reason']) ?></div>
                                    </div>
                                    <span class="status-badge pending">Pending</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile sidebar -->
<div class="offcanvas offcanvas-start" id="mobileAdminSidebar">
    <div class="offcanvas-header">
        <span class="navbar-brand-swapify">
            <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span> Swapify Admin
        </span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?php component('admin_sidebar'); ?>
    </div>
</div>

<?php loadScripts(); ?>
</body>
</html>