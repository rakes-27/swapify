<?php
/**
 * Swapify — User Dashboard
 * All data below is real, pulled per logged-in user from:
 *   listings, trade_requests, reviews, wishlists, users, notifications
 * Sidebar/topbar shell unchanged (components/sidebar.php, cards.php).
 *
 * Dashboard search: ?search=... filters "Recent listings" down to only
 * this user's own listings matching the term (title / looking_for /
 * description). No term -> falls back to latest 3, as before.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$currentUserId = $_SESSION['user_id'];

// ===================== Dashboard search term =====================
$dashboardSearch = isset($_GET['search']) ? trim($_GET['search']) : '';

// ===================== Current user's basic info (name, initials) =====================
$userStmt = mysqli_prepare($connection, "SELECT name, email, phone, bio, location, avatar_path, is_trusted, rating_avg, rating_count, completed_trades FROM users WHERE id = ?");
mysqli_stmt_bind_param($userStmt, "i", $currentUserId);
mysqli_stmt_execute($userStmt);
$currentUser = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));
mysqli_stmt_close($userStmt);

$displayName = $currentUser['name'] ?? explode('@', $currentUser['email'])[0];
$firstName = explode(' ', trim($displayName))[0];
$initials = strtoupper(substr($displayName, 0, 1) . (strpos($displayName, ' ') !== false ? substr(strstr($displayName, ' '), 1, 1) : substr($displayName, 1, 1)));

// ===================== Stat cards =====================
$activeListingsStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM listings WHERE user_id = ? AND status = 'active'");
mysqli_stmt_bind_param($activeListingsStmt, "i", $currentUserId);
mysqli_stmt_execute($activeListingsStmt);
$activeListingsCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($activeListingsStmt))['cnt'];
mysqli_stmt_close($activeListingsStmt);

// Listings added in the last 7 days, for the "+N this week" trend line.
$newThisWeekStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM listings WHERE user_id = ? AND status = 'active' AND created_at >= (NOW() - INTERVAL 7 DAY)");
mysqli_stmt_bind_param($newThisWeekStmt, "i", $currentUserId);
mysqli_stmt_execute($newThisWeekStmt);
$newThisWeek = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($newThisWeekStmt))['cnt'];
mysqli_stmt_close($newThisWeekStmt);

$pendingTradesStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'pending' AND (requester_id = ? OR owner_id = ?)");
mysqli_stmt_bind_param($pendingTradesStmt, "ii", $currentUserId, $currentUserId);
mysqli_stmt_execute($pendingTradesStmt);
$pendingTradesCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($pendingTradesStmt))['cnt'];
mysqli_stmt_close($pendingTradesStmt);

$completedTradesStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted' AND (requester_id = ? OR owner_id = ?)");
mysqli_stmt_bind_param($completedTradesStmt, "ii", $currentUserId, $currentUserId);
mysqli_stmt_execute($completedTradesStmt);
$completedTradesCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($completedTradesStmt))['cnt'];
mysqli_stmt_close($completedTradesStmt);

// Completed trades in the last 30 days, for the "+N this month" trend line.
$completedThisMonthStmt = mysqli_prepare(
    $connection,
    "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted' AND (requester_id = ? OR owner_id = ?) AND updated_at >= (NOW() - INTERVAL 30 DAY)"
);
mysqli_stmt_bind_param($completedThisMonthStmt, "ii", $currentUserId, $currentUserId);
mysqli_stmt_execute($completedThisMonthStmt);
$completedThisMonth = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($completedThisMonthStmt))['cnt'];
mysqli_stmt_close($completedThisMonthStmt);

$ratingDisplay = $currentUser['rating_count'] > 0 ? number_format((float) $currentUser['rating_avg'], 1) : '—';

// ===================== Pending request count for the welcome banner =====================
$pendingForMeStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'pending' AND owner_id = ?");
mysqli_stmt_bind_param($pendingForMeStmt, "i", $currentUserId);
mysqli_stmt_execute($pendingForMeStmt);
$pendingForMeCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($pendingForMeStmt))['cnt'];
mysqli_stmt_close($pendingForMeStmt);

// ===================== Recent listings (or search results if $dashboardSearch is set) =====================
if ($dashboardSearch !== '') {
    $myListingsStmt = mysqli_prepare(
        $connection,
        "SELECT l.id, l.title, l.looking_for, l.condition_type, l.location,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image
         FROM listings l
         WHERE l.user_id = ? AND l.status IN ('active', 'traded')
           AND (l.title LIKE ? OR l.looking_for LIKE ? OR l.description LIKE ?)
         ORDER BY l.created_at DESC"
    );
    $likeTerm = '%' . $dashboardSearch . '%';
    mysqli_stmt_bind_param($myListingsStmt, "isss", $currentUserId, $likeTerm, $likeTerm, $likeTerm);
} else {
    $myListingsStmt = mysqli_prepare(
        $connection,
        "SELECT l.id, l.title, l.looking_for, l.condition_type, l.location,
                (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image
         FROM listings l
         WHERE l.user_id = ? AND l.status IN ('active', 'traded')
         ORDER BY l.created_at DESC
         LIMIT 3"
    );
    mysqli_stmt_bind_param($myListingsStmt, "i", $currentUserId);
}
mysqli_stmt_execute($myListingsStmt);
$myListingsResult = mysqli_stmt_get_result($myListingsStmt);
$recentListings = [];
while ($row = mysqli_fetch_assoc($myListingsResult)) {
    $recentListings[] = [
        'id'    => $row['id'],
        'image' => $row['image'],
        'title' => $row['title'],
        'for'   => $row['looking_for'],
        'cond'  => $row['condition_type'],
        'loc'   => $row['location'],
    ];
}
mysqli_stmt_close($myListingsStmt);

// ===================== Recent trade activity (merged: trade_requests + reviews) =====================
$activity = [];

$tradeActivityStmt = mysqli_prepare(
    $connection,
    "SELECT tr.status, tr.updated_at, tr.requester_id, tr.owner_id,
            l1.title AS target_title, l2.title AS offered_title,
            ureq.name AS requester_name, uown.name AS owner_name
     FROM trade_requests tr
     LEFT JOIN listings l1 ON l1.id = tr.target_listing_id
     LEFT JOIN listings l2 ON l2.id = tr.offered_listing_id
     LEFT JOIN users ureq ON ureq.id = tr.requester_id
     LEFT JOIN users uown ON uown.id = tr.owner_id
     WHERE tr.requester_id = ? OR tr.owner_id = ?
     ORDER BY tr.updated_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($tradeActivityStmt, "ii", $currentUserId, $currentUserId);
mysqli_stmt_execute($tradeActivityStmt);
$tradeActivityResult = mysqli_stmt_get_result($tradeActivityStmt);
while ($row = mysqli_fetch_assoc($tradeActivityResult)) {
    $isRequester = (int) $row['requester_id'] === (int) $currentUserId;
    $otherName = $isRequester ? $row['owner_name'] : $row['requester_name'];
    $otherName = $otherName ?? 'a trader';

    if ($row['status'] === 'accepted') {
        $icon = 'bi-check2';
        $title = 'Trade completed with ' . $otherName;
        $desc = htmlspecialchars($row['offered_title'] ?? 'item') . ' swapped for ' . htmlspecialchars($row['target_title'] ?? 'item');
    } elseif ($row['status'] === 'pending' && !$isRequester) {
        $icon = 'bi-arrow-left-right';
        $title = 'New trade offer from ' . $otherName;
        $desc = 'Offered ' . htmlspecialchars($row['offered_title'] ?? 'an item') . ' for your ' . htmlspecialchars($row['target_title'] ?? 'listing');
    } elseif ($row['status'] === 'rejected') {
        $icon = 'bi-x-circle';
        $title = 'Trade request declined';
        $desc = 'With ' . $otherName . ' for ' . htmlspecialchars($row['target_title'] ?? 'a listing');
    } else {
        continue;
    }

    $activity[] = ['icon' => $icon, 'title' => $title, 'desc' => $desc, 'time' => $row['updated_at']];
}
mysqli_stmt_close($tradeActivityStmt);

$reviewActivityStmt = mysqli_prepare(
    $connection,
    "SELECT r.rating, r.created_at, u.name AS reviewer_name
     FROM reviews r
     LEFT JOIN users u ON u.id = r.reviewer_id
     WHERE r.reviewee_id = ?
     ORDER BY r.created_at DESC
     LIMIT 5"
);
mysqli_stmt_bind_param($reviewActivityStmt, "i", $currentUserId);
mysqli_stmt_execute($reviewActivityStmt);
$reviewActivityResult = mysqli_stmt_get_result($reviewActivityStmt);
while ($row = mysqli_fetch_assoc($reviewActivityResult)) {
    $activity[] = [
        'icon'  => 'bi-star-fill',
        'title' => 'You received a ' . $row['rating'] . '★ review',
        'desc'  => 'From ' . htmlspecialchars($row['reviewer_name'] ?? 'a trader'),
        'time'  => $row['created_at'],
    ];
}
mysqli_stmt_close($reviewActivityStmt);

usort($activity, function ($a, $b) {
    return strtotime($b['time']) <=> strtotime($a['time']);
});
$activity = array_slice($activity, 0, 5);

function dashboardTimeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return floor($diff / 604800) . ' weeks ago';
}

// ===================== Profile completion (6 fields tracked) =====================
$profileFields = [
    'avatar'   => !empty($currentUser['avatar_path']),
    'bio'      => !empty($currentUser['bio']),
    'phone'    => !empty($currentUser['phone']),
    'location' => !empty($currentUser['location']),
    'trusted'  => !empty($currentUser['is_trusted']),
    'listing'  => $activeListingsCount > 0,
];
$completedFieldsCount = count(array_filter($profileFields));
$totalFields = count($profileFields);
$profileCompletionPct = round(($completedFieldsCount / $totalFields) * 100);

$missingFieldLabels = [
    'avatar'   => 'Add a profile photo',
    'bio'      => 'Write a short bio',
    'phone'    => 'Add a phone number',
    'location' => 'Set your location',
    'trusted'  => 'Get verified as a Trusted Trader',
    'listing'  => 'Create your first listing',
];
// avatar/bio/location are edited on edit_profile.php; phone/trusted route to
// settings.php (phone lives in the Account tab there); listing routes to
// create_listing.php.
$missingFieldLinks = [
    'avatar'   => asset('edit_profile.php'),
    'bio'      => asset('edit_profile.php'),
    'phone'    => asset('settings.php'),
    'location' => asset('edit_profile.php'),
    'trusted'  => asset('settings.php'),
    'listing'  => asset('create_listing.php'),
];

$nextMissingField = null;
$nextMissingLink = null;
foreach ($profileFields as $key => $done) {
    if (!$done) {
        $nextMissingField = $missingFieldLabels[$key];
        $nextMissingLink = $missingFieldLinks[$key];
        break;
    }
}

// ===================== Wishlist (latest 2) =====================
$wishlistStmt = mysqli_prepare(
    $connection,
    "SELECT l.id, l.title, l.condition_type, l.location
     FROM wishlists w
     LEFT JOIN listings l ON l.id = w.listing_id
     WHERE w.user_id = ? AND l.status = 'active'
     ORDER BY w.created_at DESC
     LIMIT 2"
);
mysqli_stmt_bind_param($wishlistStmt, "i", $currentUserId);
mysqli_stmt_execute($wishlistStmt);
$wishlistResult = mysqli_stmt_get_result($wishlistStmt);
$wishlistItems = [];
while ($row = mysqli_fetch_assoc($wishlistResult)) {
    $wishlistItems[] = $row;
}
mysqli_stmt_close($wishlistStmt);

// ===================== Notifications (bell dropdown, latest 5) =====================
$navUnread = 0;
$navNotifs = [];
$navStmt = mysqli_prepare(
    $connection,
    "SELECT id, type, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"
);
mysqli_stmt_bind_param($navStmt, "i", $currentUserId);
mysqli_stmt_execute($navStmt);
$navResult = mysqli_stmt_get_result($navStmt);
while ($row = mysqli_fetch_assoc($navResult)) {
    $navNotifs[] = $row;
    if (!$row['is_read']) $navUnread++;
}
mysqli_stmt_close($navStmt);

function dashboardNotifIcon($type) {
    switch ($type) {
        case 'trade':    return ['bi-arrow-left-right', 'text-primary'];
        case 'review':   return ['bi-star-fill', 'text-warning'];
        case 'wishlist': return ['bi-heart-fill', 'text-danger'];
        case 'comment':  return ['bi-chat-dots-fill', 'text-primary'];
        case 'system':   return ['bi-shield-check', 'text-secondary'];
        default:         return ['bi-bell', 'text-secondary'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

<div class="dashboard-shell">

    <?php component('sidebar'); ?>

    <div class="dashboard-main">

        <!-- ============================== TOPBAR ============================== -->
        <div class="dashboard-topbar d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light-swap d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
                    <i class="bi bi-list"></i>
                </button>
                <div class="navbar-search px-3 py-1 d-none d-md-flex" style="width:280px;">
                    <form method="get" action="" class="d-flex align-items-center w-100">
                        <i class="bi bi-search text-muted-swap me-2"></i>
                        <input type="search" class="form-control form-control-sm border-0 py-2" name="search"
                               autocomplete="off" placeholder="Search your items..."
                               value="<?= htmlspecialchars($dashboardSearch) ?>">
                    </form>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="<?= asset('create_listing.php') ?>" class="btn btn-amber btn-sm d-none d-sm-inline-flex">
                    <i class="bi bi-plus-lg me-1"></i> List an item
                </a>
                <div class="dropdown">
                    <button class="icon-btn" data-bs-toggle="dropdown" aria-label="Notifications">
                        <i class="bi bi-bell"></i>
                        <?php if ($navUnread > 0): ?>
                            <span class="dot-badge"></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="width:300px;">
                        <div class="d-flex justify-content-between align-items-center px-2 pb-2">
                            <strong class="small">Notifications</strong>
                            <a href="<?= asset('notifications.php') ?>" class="small text-decoration-none">View all</a>
                        </div>
                        <?php foreach ($navNotifs as $n): ?>
                            <?php [$icon, $color] = dashboardNotifIcon($n['type']); ?>
                            <a href="<?= htmlspecialchars($n['link'] ?: asset('notifications.php')) ?>"
                               class="dropdown-item rounded-3 py-2 small nav-notif-link <?= $n['is_read'] ? '' : 'fw-semibold' ?>"
                               data-id="<?= $n['id'] ?>" data-read="<?= $n['is_read'] ? '1' : '0' ?>">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi <?= $icon ?> <?= $color ?> mt-1 flex-shrink-0"></i>
                                    <div style="min-width:0; flex:1;">
                                        <div style="white-space:normal; overflow-wrap:anywhere; word-break:break-word;">
                                            <?= htmlspecialchars($n['message']) ?>
                                        </div>
                                        <div class="text-muted-swap" style="font-size:0.72rem; margin-top:0.2rem;"><?= dashboardTimeAgo($n['created_at']) ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light-swap d-flex align-items-center gap-2 rounded-pill" data-bs-toggle="dropdown">
                        <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:0.75rem;"><?= htmlspecialchars($initials) ?></span>
                        <i class="bi bi-chevron-down small"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                        <li><a class="dropdown-item rounded-3" href="<?= asset('profile.php') ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item rounded-3" href="<?= asset('settings.php') ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item rounded-3 text-danger" href="<?= asset('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ============================== CONTENT ============================== -->
        <div class="dashboard-content">

            <!-- Welcome card -->
            <div class="welcome-card mb-4">
                <div class="row align-items-center position-relative">
                    <div class="col-lg-8">
                        <h3 class="text-white mb-2">Welcome back, <?= htmlspecialchars($firstName) ?> 👋</h3>
                        <p class="mb-3" style="opacity:0.85;">
                            <?php if ($pendingForMeCount > 0): ?>
                                You have <?= $pendingForMeCount ?> pending trade request<?= $pendingForMeCount === 1 ? '' : 's' ?> waiting.
                            <?php else: ?>
                                You're all caught up — no pending trade requests right now.
                            <?php endif; ?>
                        </p>
                        <a href="<?= asset('trade_requests.php') ?>" class="btn btn-light btn-sm fw-semibold">Review requests</a>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <?php statCard('bi-box-seam', 'Active Listings', (string) $activeListingsCount, $newThisWeek > 0 ? ['positive' => true, 'text' => "+{$newThisWeek} this week"] : null); ?>
                </div>
                <div class="col-6 col-lg-3">
                    <?php statCard('bi-arrow-left-right', 'Pending Trades', (string) $pendingTradesCount, null); ?>
                </div>
                <div class="col-6 col-lg-3">
                    <?php statCard('bi-check-circle', 'Completed Trades', (string) $completedTradesCount, $completedThisMonth > 0 ? ['positive' => true, 'text' => "+{$completedThisMonth} this month"] : null); ?>
                </div>
                <div class="col-6 col-lg-3">
                    <?php statCard('bi-star', 'Rating', $ratingDisplay, null); ?>
                </div>
            </div>

            <div class="row g-4">
                <!-- Left: recent listings + activity -->
                <div class="col-lg-8">
                    <div class="dash-panel mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="dash-panel-title mb-0">
                                <?= $dashboardSearch !== '' ? 'Results for "' . htmlspecialchars($dashboardSearch) . '"' : 'Recent listings' ?>
                            </div>
                            <a href="<?= asset('my_listings.php') ?>" class="small text-decoration-none">Manage all</a>
                        </div>
                        <div class="row g-3 mt-1">
                            <?php if (empty($recentListings)): ?>
                                <div class="col-12 text-center text-muted-swap py-4">
                                    <?= $dashboardSearch !== '' ? 'No listings of yours match that search.' : "You haven't listed anything yet." ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentListings as $item): ?>
                                    <div class="col-sm-6 col-xl-4"><?php tradeItemCard($item); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dash-panel">
                        <div class="dash-panel-title">Recent trade activity</div>
                        <?php if (empty($activity)): ?>
                            <div class="text-center text-muted-swap py-4">No activity yet — your trades and reviews will show up here.</div>
                        <?php else: ?>
                            <?php foreach ($activity as $item): ?>
                                <div class="activity-item">
                                    <div class="activity-icon"><i class="bi <?= $item['icon'] ?>"></i></div>
                                    <div>
                                        <div class="small fw-semibold"><?= $item['title'] ?></div>
                                        <div class="text-muted-swap small"><?= $item['desc'] ?> &middot; <?= dashboardTimeAgo($item['time']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: profile completion, quick actions, wishlist -->
                <div class="col-lg-4">
                    <div class="dash-panel mb-4">
                        <div class="dash-panel-title">Profile completion</div>
                        <div class="d-flex justify-content-between small mb-2">
                            <span class="text-muted-swap"><?= $profileCompletionPct ?>% complete</span>
                            <span class="text-primary fw-semibold"><?= $completedFieldsCount ?>/<?= $totalFields ?> steps</span>
                        </div>
                        <div class="progress-swap mb-3"><div class="progress-swap-fill" style="width:<?= $profileCompletionPct ?>%;"></div></div>
                        <?php if ($nextMissingField): ?>
                            <a href="<?= $nextMissingLink ?>" class="small text-decoration-none"><i class="bi bi-plus-circle me-1"></i><?= htmlspecialchars($nextMissingField) ?></a>
                        <?php else: ?>
                            <span class="small text-muted-swap"><i class="bi bi-check-circle me-1"></i>Your profile is complete</span>
                        <?php endif; ?>
                    </div>

                    <div class="dash-panel mb-4">
                        <div class="dash-panel-title">Quick actions</div>
                        <div class="row g-2">
                            <div class="col-6">
                                <a href="<?= asset('create_listing.php') ?>" class="quick-action-btn"><i class="bi bi-plus-square"></i><span class="small">List item</span></a>
                            </div>
                            <div class="col-6">
                                <a href="<?= asset('marketplace.php') ?>" class="quick-action-btn"><i class="bi bi-shop"></i><span class="small">Browse</span></a>
                            </div>
                            <div class="col-6">
                                <a href="<?= asset('trade_requests.php') ?>" class="quick-action-btn"><i class="bi bi-arrow-left-right"></i><span class="small">Requests</span></a>
                            </div>
                            <div class="col-6">
                                <a href="<?= asset('wishlist.php') ?>" class="quick-action-btn"><i class="bi bi-heart"></i><span class="small">Wishlist</span></a>
                            </div>
                        </div>
                    </div>

                    <div class="dash-panel">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="dash-panel-title mb-0">Wishlist</div>
                            <a href="<?= asset('wishlist.php') ?>" class="small text-decoration-none">View all</a>
                        </div>
                        <?php if (empty($wishlistItems)): ?>
                            <div class="text-center text-muted-swap small py-3">Your wishlist is empty.</div>
                        <?php else: ?>
                            <?php foreach ($wishlistItems as $w): ?>
                                <a href="<?= asset('items_details.php') ?>?id=<?= $w['id'] ?>" class="wishlist-mini-item text-decoration-none text-reset">
                                    <div class="wishlist-mini-thumb"><i class="bi bi-box"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="small fw-semibold"><?= htmlspecialchars($w['title']) ?></div>
                                        <div class="text-muted-swap" style="font-size:0.75rem;"><?= htmlspecialchars($w['condition_type']) ?> &middot; <?= htmlspecialchars($w['location']) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile sidebar (offcanvas) -->
<div class="offcanvas offcanvas-start" id="mobileSidebar">
    <div class="offcanvas-header">
        <a href="/index.php" class="navbar-brand-swapify">
            <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span> Swapify
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?php component('sidebar'); ?>
    </div>
</div>

<?php loadScripts(); ?>
<script>
document.querySelectorAll('.nav-notif-link').forEach(function (link) {
  link.addEventListener('click', function () {
    if (this.dataset.read === '1') return;
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('notification_id', this.dataset.id);
    fetch('notifications.php', { method: 'POST', body: formData, keepalive: true }).catch(() => {});
  });
});
</script>
</body>
</html>