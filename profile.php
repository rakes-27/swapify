<?php
/**
 * Swapify — User Profile
 * ADDED: report_user action + Report button/modal, inserting into the
 * generic `reports` table (target_type='user').
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

// ===================== HANDLE REPORT USER (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_user') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to report a user.']);
        exit;
    }

    $reporterId   = $_SESSION['user_id'];
    $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
    $reason       = trim($_POST['reason'] ?? '');
    $description  = trim($_POST['description'] ?? '');

    if ($targetUserId <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'Please choose a reason.']);
        exit;
    }

    if ($targetUserId === (int) $reporterId) {
        echo json_encode(['success' => false, 'message' => "You can't report your own profile."]);
        exit;
    }

    $userExistsStmt = mysqli_prepare($connection, "SELECT id FROM users WHERE id = ?");
    mysqli_stmt_bind_param($userExistsStmt, "i", $targetUserId);
    mysqli_stmt_execute($userExistsStmt);
    $userExists = mysqli_fetch_assoc(mysqli_stmt_get_result($userExistsStmt));
    mysqli_stmt_close($userExistsStmt);

    if (!$userExists) {
        echo json_encode(['success' => false, 'message' => 'This user no longer exists.']);
        exit;
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO reports (reporter_id, target_type, target_id, reason, description, status, created_at)
         VALUES (?, 'user', ?, ?, ?, 'pending', NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiss", $reporterId, $targetUserId, $reason, $description);
    $ok = mysqli_stmt_execute($insStmt);
    mysqli_stmt_close($insStmt);

    echo json_encode(['success' => $ok, 'message' => $ok ? 'User reported. Our team will review it.' : 'Something went wrong. Please try again.']);
    exit;
}

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) { $m = floor($diff / 60); return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
        if ($diff < 86400) { $h = floor($diff / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
        if ($diff < 604800) { $d = floor($diff / 86400); return $d . ' day' . ($d === 1 ? '' : 's') . ' ago'; }
        if ($diff < 2629800) { $w = floor($diff / 604800); return $w . ' week' . ($w === 1 ? '' : 's') . ' ago'; }
        if ($diff < 31557600) { $mo = floor($diff / 2629800); return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago'; }
        $y = floor($diff / 31557600);
        return $y . ' year' . ($y === 1 ? '' : 's') . ' ago';
    }
}

$profileId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_SESSION['user_id'] ?? 0);

if ($profileId <= 0) {
    header('Location: login.php');
    exit;
}

$userStmt = mysqli_prepare(
    $connection,
    "SELECT id, name, username, avatar_path, banner_path, bio, location, is_trusted,
            show_exact_location, show_wishlist, created_at
     FROM users WHERE id = ?"
);
mysqli_stmt_bind_param($userStmt, "i", $profileId);
mysqli_stmt_execute($userStmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));
mysqli_stmt_close($userStmt);

if (!$user) {
    header('Location: marketplace.php');
    exit;
}

$isOwnProfile = isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === $profileId;
$currentViewerId = $_SESSION['user_id'] ?? 0;

$displayName = $user['name'] ?: ($user['username'] ?: 'Trader');
$nameParts = preg_split('/\s+/', trim($displayName));
$initials = strtoupper(substr($nameParts[0] ?? 'U', 0, 1) . substr($nameParts[1] ?? '', 0, 1));
if (mb_strlen($initials) < 2) {
    $initials = strtoupper(substr($displayName, 0, 2));
}

$memberSinceYear = date('Y', strtotime($user['created_at']));
$showLocation = $isOwnProfile || (bool) $user['show_exact_location'];
$showWishlistTab = $isOwnProfile || (bool) $user['show_wishlist'];

$ratingAvg = 0;
$ratingCount = 0;
$ratingStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM reviews WHERE reviewee_id = ?");
mysqli_stmt_bind_param($ratingStmt, "i", $profileId);
mysqli_stmt_execute($ratingStmt);
$ratingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ratingStmt));
mysqli_stmt_close($ratingStmt);
if ($ratingRow && (int) $ratingRow['cnt'] > 0) {
    $ratingCount = (int) $ratingRow['cnt'];
    $ratingAvg = round((float) $ratingRow['avg_rating'], 1);
}

$completedTrades = 0;
$tradeCountStmt = mysqli_prepare(
    $connection,
    "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted' AND (requester_id = ? OR owner_id = ?)"
);
mysqli_stmt_bind_param($tradeCountStmt, "ii", $profileId, $profileId);
mysqli_stmt_execute($tradeCountStmt);
$completedTrades = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($tradeCountStmt))['cnt'];
mysqli_stmt_close($tradeCountStmt);

$activeListingsStmt = mysqli_prepare(
    $connection,
    "SELECT COUNT(*) AS cnt FROM listings WHERE user_id = ? AND status = 'active'"
);
mysqli_stmt_bind_param($activeListingsStmt, "i", $profileId);
mysqli_stmt_execute($activeListingsStmt);
$activeListingsCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($activeListingsStmt))['cnt'];
mysqli_stmt_close($activeListingsStmt);

$achievements = [
    ['icon' => 'bi-patch-check-fill', 'label' => 'Trusted Trader'],
    ['icon' => 'bi-lightning-fill', 'label' => 'Fast Responder'],
    ['icon' => 'bi-award-fill', 'label' => '10+ Trades'],
    ['icon' => 'bi-hand-thumbs-up-fill', 'label' => 'Top Rated'],
];

$listings = [];
$listingsStmt = mysqli_prepare(
    $connection,
    "SELECT l.id, l.title, l.looking_for, l.condition_type, l.location, l.status,
            (SELECT image_path FROM listing_images
             WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image,
            wv.id AS viewer_wishlist_id
     FROM listings l
     LEFT JOIN wishlists wv ON wv.listing_id = l.id AND wv.user_id = ?
     WHERE l.user_id = ? AND l.status IN ('active', 'traded')
     ORDER BY l.created_at DESC"
);
mysqli_stmt_bind_param($listingsStmt, "ii", $currentViewerId, $profileId);
mysqli_stmt_execute($listingsStmt);
$listingsResult = mysqli_stmt_get_result($listingsStmt);
while ($row = mysqli_fetch_assoc($listingsResult)) {
    $listings[] = [
        'id'        => $row['id'],
        'image'     => $row['image'],
        'title'     => $row['title'],
        'for'       => $row['looking_for'],
        'cond'      => $row['condition_type'],
        'loc'       => $row['location'],
        'status'    => $row['status'],
        'favorited' => !empty($row['viewer_wishlist_id']),
    ];
}
mysqli_stmt_close($listingsStmt);

$wishlist = [];
if ($showWishlistTab) {
    $wishlistStmt = mysqli_prepare(
        $connection,
        "SELECT l.id, l.title, l.looking_for, l.condition_type, l.location,
                (SELECT image_path FROM listing_images
                 WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image,
                wv.id AS viewer_wishlist_id
         FROM wishlists w
         JOIN listings l ON l.id = w.listing_id
         LEFT JOIN wishlists wv ON wv.listing_id = l.id AND wv.user_id = ?
         WHERE w.user_id = ? AND l.status = 'active'
         ORDER BY w.created_at DESC"
    );
    mysqli_stmt_bind_param($wishlistStmt, "ii", $currentViewerId, $profileId);
    mysqli_stmt_execute($wishlistStmt);
    $wishlistResult = mysqli_stmt_get_result($wishlistStmt);
    while ($row = mysqli_fetch_assoc($wishlistResult)) {
        $wishlist[] = [
            'id'        => $row['id'],
            'image'     => $row['image'],
            'title'     => $row['title'],
            'for'       => $row['looking_for'],
            'cond'      => $row['condition_type'],
            'loc'       => $row['location'],
            'favorited' => !empty($row['viewer_wishlist_id']),
        ];
    }
    mysqli_stmt_close($wishlistStmt);
}

$reviews = [];
$reviewsStmt = mysqli_prepare(
    $connection,
    "SELECT r.rating, r.comment, r.created_at,
            ru.name AS reviewer_name, ru.username AS reviewer_username, ru.avatar_path AS reviewer_avatar
     FROM reviews r
     LEFT JOIN users ru ON r.reviewer_id = ru.id
     WHERE r.reviewee_id = ?
     ORDER BY r.created_at DESC
     LIMIT 50"
);
mysqli_stmt_bind_param($reviewsStmt, "i", $profileId);
mysqli_stmt_execute($reviewsStmt);
$reviewsResult = mysqli_stmt_get_result($reviewsStmt);
while ($row = mysqli_fetch_assoc($reviewsResult)) {
    $reviewerName = $row['reviewer_name'] ?: ($row['reviewer_username'] ?: 'Trader');
    $rParts = preg_split('/\s+/', trim($reviewerName));
    $rInitials = strtoupper(substr($rParts[0] ?? 'U', 0, 1) . substr($rParts[1] ?? '', 0, 1));
    if (mb_strlen($rInitials) < 2) {
        $rInitials = strtoupper(substr($reviewerName, 0, 2));
    }
    $reviews[] = [
        'initials' => $rInitials,
        'name'     => $reviewerName,
        'avatar'   => $row['reviewer_avatar'],
        'date'     => timeAgo($row['created_at']),
        'stars'    => (int) $row['rating'],
        'text'     => $row['comment'],
    ];
}
mysqli_stmt_close($reviewsStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($displayName) ?> — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">

            <div class="profile-cover"<?php if (!empty($user['banner_path'])): ?> style="background-image:url('<?= asset(htmlspecialchars($user['banner_path'])) ?>'); background-size:cover; background-position:center;"<?php endif; ?>></div>
            <div class="profile-header-row d-flex flex-wrap align-items-end justify-content-between gap-3">
                <div class="d-flex align-items-end gap-3">
                    <?php if (!empty($user['avatar_path'])): ?>
                        <div class="profile-avatar-lg p-0" style="overflow:hidden;">
                            <img src="<?= asset(htmlspecialchars($user['avatar_path'])) ?>" alt="<?= htmlspecialchars($displayName) ?>" style="width:100%; height:100%; object-fit:cover;">
                        </div>
                    <?php else: ?>
                        <div class="profile-avatar-lg"><?= htmlspecialchars($initials) ?></div>
                    <?php endif; ?>
                    <div class="pb-2">
                        <div class="d-flex align-items-center gap-2">
                            <h3 class="mb-0"><?= htmlspecialchars($displayName) ?></h3>
                            <?php if ($user['is_trusted']): ?>
                                <i class="bi bi-patch-check-fill text-primary" title="Trusted Trader"></i>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted-swap small">
                            <?php if ($showLocation && !empty($user['location'])): ?>
                                <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($user['location']) ?> &middot;
                            <?php endif; ?>
                            Member since <?= htmlspecialchars($memberSinceYear) ?>
                        </div>
                        <?php if (!empty($user['bio'])): ?>
                            <p class="text-muted-swap small mt-2 mb-0"><?= nl2br(htmlspecialchars($user['bio'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isOwnProfile): ?>
                    <a href="<?= asset('edit_profile.php') ?>" class="btn btn-light-swap mb-2"><i class="bi bi-pencil me-1"></i> Edit profile</a>
                <?php elseif (isset($_SESSION['user_id'])): ?>
                    <button class="btn btn-light-swap mb-2" id="reportUserBtn" data-target-user-id="<?= $profileId ?>"><i class="bi bi-flag me-1"></i> Report user</button>
                <?php endif; ?>
            </div>

            <div class="row g-3 mt-1 mb-4">
                <div class="col-6 col-lg-3"><?php statCard('bi-box-seam', 'Active Listings', (string) $activeListingsCount, null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-check-circle', 'Completed Trades', (string) $completedTrades, null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-star', 'Rating', $ratingCount > 0 ? number_format($ratingAvg, 1) : '—', null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-chat-square-text', 'Reviews', (string) $ratingCount, null); ?></div>
            </div>

            <?php if (!empty($achievements)): ?>
                <div class="mb-4 d-flex flex-wrap gap-2">
                    <?php foreach ($achievements as $badge): ?>
                        <span class="achievement-badge"><i class="bi <?= $badge['icon'] ?>"></i> <?= htmlspecialchars($badge['label']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <ul class="nav profile-tabs mb-4" id="profileTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabListings" type="button">Listings (<?= count($listings) ?>)</button></li>
                <?php if ($showWishlistTab): ?>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabWishlist" type="button">Wishlist (<?= count($wishlist) ?>)</button></li>
                <?php endif; ?>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReviews" type="button">Reviews (<?= $ratingCount ?>)</button></li>
            </ul>

            <div class="tab-content">

                <div class="tab-pane fade show active" id="tabListings">
                    <div class="row g-4">
                        <?php if (empty($listings)): ?>
                            <div class="col-12 text-center text-muted-swap py-4">No listings yet.</div>
                        <?php else: ?>
                            <?php foreach ($listings as $item): ?>
                                <?php $isTraded = $item['status'] === 'traded'; ?>
                                <div class="col-md-6 col-lg-4<?= $isTraded ? ' position-relative' : '' ?>">
                                    <?php if ($isTraded): ?>
                                        <span class="badge bg-dark position-absolute" style="top:12px; left:12px; z-index:5; opacity:0.85;">
                                            <i class="bi bi-check-circle-fill me-1"></i>Traded
                                        </span>
                                    <?php endif; ?>
                                    <?php tradeItemCard($item); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($showWishlistTab): ?>
                <div class="tab-pane fade" id="tabWishlist">
                    <div class="row g-4">
                        <?php if (empty($wishlist)): ?>
                            <div class="col-12 text-center text-muted-swap py-4">Nothing in the wishlist yet.</div>
                        <?php else: ?>
                            <?php foreach ($wishlist as $item): ?>
                                <div class="col-md-6 col-lg-4"><?php tradeItemCard($item); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="tab-pane fade" id="tabReviews">
                    <div class="row g-3">
                        <?php if (empty($reviews)): ?>
                            <div class="col-12 text-center text-muted-swap py-4">No reviews yet.</div>
                        <?php else: ?>
                            <?php foreach ($reviews as $review): ?>
                                <div class="col-md-6"><?php reviewCard($review); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!$isOwnProfile && isset($_SESSION['user_id'])): ?>
    <div class="modal fade" id="reportUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Report <?= htmlspecialchars($displayName) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Reason</label>
                    <select class="form-select mb-3" id="reportUserReason" style="border-radius:12px;">
                        <option value="">Select a reason</option>
                        <option value="Spam">Spam</option>
                        <option value="Harassment">Harassment</option>
                        <option value="Fake item">Fake item</option>
                        <option value="Scam">Scam</option>
                    </select>
                    <label class="form-label">Additional details (optional)</label>
                    <textarea class="form-control" id="reportUserDescription" rows="3" maxlength="500" style="border-radius:12px;" placeholder="Anything else we should know?"></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" id="submitReportUserBtn">Submit report</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      const reportBtn = document.getElementById('reportUserBtn');
      if (!reportBtn) return;

      const modal = new bootstrap.Modal(document.getElementById('reportUserModal'));
      const reasonSelect = document.getElementById('reportUserReason');
      const descInput = document.getElementById('reportUserDescription');
      const submitBtn = document.getElementById('submitReportUserBtn');

      reportBtn.addEventListener('click', function () {
        reasonSelect.value = '';
        descInput.value = '';
        reasonSelect.classList.remove('is-invalid');
        modal.show();
      });

      submitBtn.addEventListener('click', function () {
        const reason = reasonSelect.value;
        if (!reason) {
          reasonSelect.classList.add('is-invalid');
          return;
        }
        reasonSelect.classList.remove('is-invalid');

        const formData = new FormData();
        formData.append('action', 'report_user');
        formData.append('target_user_id', reportBtn.dataset.targetUserId);
        formData.append('reason', reason);
        formData.append('description', descInput.value.trim());

        fetch('profile.php', { method: 'POST', body: formData })
          .then((res) => res.json())
          .then((data) => {
            modal.hide();
            swapifyToast(data.message, data.success ? 'success' : 'danger');
          })
          .catch(() => {
            modal.hide();
            swapifyToast('Something went wrong. Please try again.', 'danger');
          });
      });
    });
    </script>
    <?php endif; ?>

    <?php component('footer'); ?>

    <?php loadScripts(); ?>
</body>
</html>