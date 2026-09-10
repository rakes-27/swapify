<?php
/**
 * Swapify — Trending Items
 * Trending score = (likes * 3) + views, snapshotted once per calendar day.
 * "Likes" = count of wishlist saves per listing. Weighted 3x over views
 * since an active save is a stronger signal than a passive view.
 *
 * Daily refresh without a cron job: the first page load of a new day
 * checks whether today's snapshot exists; if not, it computes and stores
 * one from current listings/wishlists/views data. All visits that day
 * read from that snapshot, so ranking stays stable within the day and
 * changes once the next day's first visitor arrives.
 *
 * No minimum-offer threshold — the top-scored listing always shows,
 * even if it's the only listing and its score is 0.
 *
 * toggle_favorite now checks affected_rows + mysqli_error so a silent
 * insert failure is reported back to the client instead of always
 * claiming success.
 *
 * Ranking: featured item = #1 (spotlight). Grid items are tagged with
 * their overall rank (#2, #3, ...) based on snapshot score order.
 *
 * Category pills (?category=slug): same URL serves two purposes, same
 * pattern as marketplace.php —
 *   - Normal GET request  -> renders the full HTML page
 *   - AJAX GET request (X-Requested-With: XMLHttpRequest) -> returns JSON
 *     with just the results HTML (spotlight + grid, or empty state), so
 *     trending.js can swap it in without a full page reload.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

// ===================== HANDLE FAVORITE TOGGLE (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_favorite') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to save favorites.']);
        exit;
    }

    $itemId = (int) ($_POST['item_id'] ?? 0);
    $userId = $_SESSION['user_id'];

    if ($itemId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item.']);
        exit;
    }

    $checkStmt = mysqli_prepare($connection, "SELECT id FROM wishlists WHERE user_id = ? AND listing_id = ?");
    mysqli_stmt_bind_param($checkStmt, "ii", $userId, $itemId);
    mysqli_stmt_execute($checkStmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
    mysqli_stmt_close($checkStmt);

    if ($existing) {
        $delStmt = mysqli_prepare($connection, "DELETE FROM wishlists WHERE id = ?");
        mysqli_stmt_bind_param($delStmt, "i", $existing['id']);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
        echo json_encode(['success' => true, 'active' => false]);
    } else {
        $insStmt = mysqli_prepare($connection, "INSERT INTO wishlists (user_id, listing_id, created_at) VALUES (?, ?, NOW())");
        mysqli_stmt_bind_param($insStmt, "ii", $userId, $itemId);
        $ok = mysqli_stmt_execute($insStmt);
        $affected = mysqli_stmt_affected_rows($insStmt);
        $error = mysqli_error($connection);
        mysqli_stmt_close($insStmt);

        if (!$ok || $affected === 0) {
            echo json_encode(['success' => false, 'message' => 'Could not save to wishlist.', 'debug' => $error]);
            exit;
        }
        echo json_encode(['success' => true, 'active' => true]);
    }
    exit;
}

$selectedCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$currentUserId = $_SESSION['user_id'] ?? 0;
$today = date('Y-m-d');

// ===================== Ensure today's snapshot exists (lazy daily refresh) =====================
$snapCheckStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM trending_snapshot WHERE snapshot_date = ?");
mysqli_stmt_bind_param($snapCheckStmt, "s", $today);
mysqli_stmt_execute($snapCheckStmt);
$snapExists = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($snapCheckStmt))['cnt'] > 0;
mysqli_stmt_close($snapCheckStmt);

if (!$snapExists) {
    $computeStmt = mysqli_query(
        $connection,
        "SELECT l.id, l.views_count, (SELECT COUNT(*) FROM wishlists w WHERE w.listing_id = l.id) AS likes_count
         FROM listings l
         WHERE l.status = 'active'"
    );

    $insSnapStmt = mysqli_prepare(
        $connection,
        "INSERT INTO trending_snapshot (listing_id, likes_count, views_count, score, snapshot_date)
         VALUES (?, ?, ?, ?, ?)"
    );

    while ($row = mysqli_fetch_assoc($computeStmt)) {
        $likes = (int) $row['likes_count'];
        $views = (int) $row['views_count'];
        $score = ($likes * 3) + $views;
        mysqli_stmt_bind_param($insSnapStmt, "iiiis", $row['id'], $likes, $views, $score, $today);
        mysqli_stmt_execute($insSnapStmt);
    }
    mysqli_stmt_close($insSnapStmt);
}

// ===================== Build category pills from real categories table =====================
$catStmt = mysqli_query($connection, "SELECT DISTINCT c.name, c.slug FROM categories c INNER JOIN listings l ON l.category_id = c.id WHERE l.status = 'active' ORDER BY c.name ASC");
$categoryPills = [];
while ($row = mysqli_fetch_assoc($catStmt)) {
    $categoryPills[] = $row;
}

// ===================== Pull today's snapshot, ranked, no threshold =====================
$whereCategory = '';
$params = [$today];
$types = 's';

if ($selectedCategory !== '') {
    $whereCategory = "AND c.slug = ?";
    $params[] = $selectedCategory;
    $types .= 's';
}

$sql = "SELECT
            l.id, l.title, l.looking_for, l.condition_type, l.location,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image,
            w.id AS wishlist_id,
            ts.likes_count, ts.views_count, ts.score
        FROM trending_snapshot ts
        INNER JOIN listings l ON l.id = ts.listing_id
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN wishlists w ON w.listing_id = l.id AND w.user_id = ?
        WHERE ts.snapshot_date = ? AND l.status = 'active' $whereCategory
        ORDER BY ts.score DESC, ts.likes_count DESC
        LIMIT 9";

$stmt = mysqli_prepare($connection, $sql);
$allParams = array_merge([$currentUserId], $params);
$allTypes = 'i' . $types;
mysqli_stmt_bind_param($stmt, $allTypes, ...$allParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$trendingItems = [];
while ($row = mysqli_fetch_assoc($result)) {
    $trendingItems[] = [
        'id'        => $row['id'],
        'image'     => $row['image'],
        'title'     => $row['title'],
        'for'       => $row['looking_for'],
        'cond'      => $row['condition_type'],
        'loc'       => $row['location'],
        'favorited' => !empty($row['wishlist_id']),
        'likes'     => (int) $row['likes_count'],
        'views'     => (int) $row['views_count'],
        'score'     => (int) $row['score'],
    ];
}
mysqli_stmt_close($stmt);

$featured = array_shift($trendingItems); // top-scored item = #1, rest go in the grid

// Tag each remaining item with its overall rank (#2, #3, ...) since the
// featured spotlight above already claimed #1.
foreach ($trendingItems as $i => &$item) {
    $item['rank'] = $i + 2;
}
unset($item);

// ===================== Reusable results builder (used by both full page + AJAX) =====================
// Renders the "empty state" OR "spotlight + grid" block — everything below
// the category pills. Kept as one function so the normal page render and
// the AJAX category-switch response can never drift out of sync.
function buildTrendingResultsHtml($featured, $trendingItems) {
    ob_start();

    if (!$featured):
        ?>
        <!-- No listings at all -->
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-fire"></i></div>
            <h5>Nothing to show yet</h5>
            <p>Once listings exist, the most liked and viewed item will show up here.</p>
            <a href="<?= asset('marketplace.php') ?>" class="btn btn-primary btn-sm">Browse marketplace</a>
        </div>
        <?php
    else:
        ?>
        <!-- Featured #1 spotlight -->
        <div class="trade-card mb-4" style="max-width: 100%; cursor:pointer;" data-listing-id="<?= $featured['id'] ?>">
            <div class="row g-0">
                <div class="col-md-4">
                    <div class="trade-card-img" style="height:100%; min-height:220px; border-radius:18px 0 0 18px;">
                        <?php if (!empty($featured['image'])): ?>
                            <img src="<?= asset(htmlspecialchars($featured['image'])) ?>" alt="<?= htmlspecialchars($featured['title']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:18px 0 0 18px;">
                        <?php else: ?>
                            <i class="bi bi-box-seam" style="font-size:3rem;"></i>
                        <?php endif; ?>
                        <button class="trade-card-fav <?= $featured['favorited'] ? 'active' : '' ?>" data-item-id="<?= $featured['id'] ?>" aria-label="Add to wishlist">
                            <i class="bi <?= $featured['favorited'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="trade-card-body h-100 d-flex flex-column justify-content-center p-4">
                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                            <span class="badge-trending">🔥 #1 Trending</span>
                            <span class="text-muted-swap small"><i class="bi bi-heart-fill text-danger"></i> <?= $featured['likes'] ?> like<?= $featured['likes'] === 1 ? '' : 's' ?></span>
                            <span class="text-muted-swap small"><i class="bi bi-eye"></i> <?= $featured['views'] ?> view<?= $featured['views'] === 1 ? '' : 's' ?></span>
                            <span class="badge-condition"><?= htmlspecialchars($featured['cond']) ?></span>
                        </div>
                        <h4 class="mb-2"><?= htmlspecialchars($featured['title']) ?></h4>
                        <div class="trade-card-for mb-3">Looking for: <span class="amber"><?= htmlspecialchars($featured['for']) ?></span></div>
                        <div class="d-flex align-items-center gap-3">
                            <a href="<?= asset('items_details.php') ?>?id=<?= $featured['id'] ?>" class="btn btn-primary btn-sm">View item</a>
                            <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($featured['loc']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trending grid -->
        <?php if (!empty($trendingItems)): ?>
            <div class="row g-4">
                <?php foreach ($trendingItems as $item): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="trade-card" style="cursor:pointer;" data-listing-id="<?= $item['id'] ?>">
                            <div class="trade-card-img">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?= asset(htmlspecialchars($item['image'])) ?>" alt="<?= htmlspecialchars($item['title']) ?>" style="width:100%; height:100%; object-fit:cover;">
                                <?php else: ?>
                                    <i class="bi bi-box-seam"></i>
                                <?php endif; ?>
                                <button class="trade-card-fav <?= $item['favorited'] ? 'active' : '' ?>" data-item-id="<?= $item['id'] ?>" aria-label="Add to wishlist">
                                    <i class="bi <?= $item['favorited'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                </button>
                            </div>
                            <div class="trade-card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="text-muted-swap small">
                                        <span class="fw-semibold text-dark">#<?= $item['rank'] ?></span>
                                        &middot; <i class="bi bi-heart-fill text-danger"></i> <?= $item['likes'] ?> &middot; <i class="bi bi-eye"></i> <?= $item['views'] ?>
                                    </span>
                                    <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($item['loc']) ?></span>
                                </div>
                                <a href="<?= asset('items_details.php') ?>?id=<?= $item['id'] ?>" class="text-decoration-none text-reset">
                                    <div class="trade-card-title"><?= htmlspecialchars($item['title']) ?></div>
                                </a>
                                <div class="trade-card-for">Looking for: <span class="amber"><?= htmlspecialchars($item['for']) ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    endif;

    return ob_get_clean();
}

// ===================== AJAX branch: return JSON, skip the HTML page entirely =====================
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'     => true,
        'resultsHtml' => buildTrendingResultsHtml($featured, $trendingItems),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trending Items — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="mb-4">
                <span class="eyebrow"><i class="bi bi-fire me-1"></i> Hot right now</span>
                <h2 class="mt-2 mb-1">Trending items</h2>
                <p class="text-muted-swap mb-0">Ranked daily by likes and views &mdash; updated <?= date('F j, Y') ?>.</p>
            </div>

            <!-- Trending categories -->
            <div class="category-scroll mb-4" id="categoryScroll">
                <a href="<?= asset('trending.php') ?>" class="category-pill <?= $selectedCategory === '' ? 'active' : '' ?>">
                    <i class="bi bi-fire"></i> All trending
                </a>
                <?php foreach ($categoryPills as $cat): ?>
                    <a href="<?= asset('trending.php') ?>?category=<?= urlencode($cat['slug']) ?>"
                       class="category-pill <?= $selectedCategory === $cat['slug'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div id="trendingResults">
                <?= buildTrendingResultsHtml($featured, $trendingItems) ?>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('trending'); ?>
</body>
</html>