<?php
/**
 * Swapify — Landing Page
 * Data-driven: featured trades (newest 4 active listings), trending
 * items (trending_snapshot, same logic as trending.php), stats band
 * (real counts), testimonials (real reviews with comments), category
 * pills (ALL real categories, shown even if nothing's listed in them yet).
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/connection.php';

$currentUserId = $_SESSION['user_id'] ?? 0;

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
        mysqli_stmt_close($insStmt);

        if (!$ok || $affected === 0) {
            echo json_encode(['success' => false, 'message' => 'Could not save to wishlist.']);
            exit;
        }
        echo json_encode(['success' => true, 'active' => true]);
    }
    exit;
}

// ===================== Category pills: ALL categories, even ones with nothing listed yet =====================
$catStmt = mysqli_query($connection, "SELECT name, slug FROM categories ORDER BY name ASC LIMIT 12");
$categoryPills = [];
while ($row = mysqli_fetch_assoc($catStmt)) {
    $categoryPills[] = $row;
}

function landingCategoryIcon($name) {
    $name = strtolower($name);
    if (strpos($name, 'book') !== false) return 'bi-book';
    if (strpos($name, 'game') !== false) return 'bi-controller';
    if (strpos($name, 'electronic') !== false) return 'bi-cpu';
    if (strpos($name, 'cloth') !== false) return 'bi-bag';
    if (strpos($name, 'camera') !== false) return 'bi-camera';
    if (strpos($name, 'sport') !== false) return 'bi-dribbble';
    if (strpos($name, 'instrument') !== false || strpos($name, 'music') !== false) return 'bi-music-note-beamed';
    if (strpos($name, 'collect') !== false) return 'bi-gem';
    if (strpos($name, 'furniture') !== false) return 'bi-lamp';
    if (strpos($name, 'art') !== false) return 'bi-palette';
    if (strpos($name, 'accessor') !== false) return 'bi-watch';
    if (strpos($name, 'gadget') !== false || strpos($name, 'phone') !== false) return 'bi-phone';
    return 'bi-box-seam';
}

/**
 * Same email -> display-name/initials convention used everywhere else in
 * the app (comments, reviews, trade requests, etc.) — no users.name column
 * is assumed to exist.
 */
function landingAuthorInfo($email) {
    $name = 'Trader';
    $initials = 'U';
    if (!empty($email)) {
        $localPart = explode('@', $email)[0];
        $name = ucfirst($localPart);
        $initials = strtoupper(substr($localPart, 0, 2));
    }
    return ['name' => $name, 'initials' => $initials];
}

// ===================== Featured trades: newest 4 active listings =====================
$featuredStmt = mysqli_prepare(
    $connection,
    "SELECT l.id, l.title, l.looking_for, l.condition_type, l.location,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image,
            w.id AS wishlist_id
     FROM listings l
     LEFT JOIN wishlists w ON w.listing_id = l.id AND w.user_id = ?
     WHERE l.status = 'active'
     ORDER BY l.created_at DESC
     LIMIT 4"
);
mysqli_stmt_bind_param($featuredStmt, "i", $currentUserId);
mysqli_stmt_execute($featuredStmt);
$featuredResult = mysqli_stmt_get_result($featuredStmt);
$featured = [];
while ($row = mysqli_fetch_assoc($featuredResult)) {
    $featured[] = [
        'id'        => $row['id'],
        'image'     => $row['image'],
        'title'     => $row['title'],
        'for'       => $row['looking_for'],
        'cond'      => $row['condition_type'],
        'loc'       => $row['location'],
        'favorited' => !empty($row['wishlist_id']),
    ];
}
mysqli_stmt_close($featuredStmt);

// ===================== Trending items: top 4, same daily-snapshot logic as trending.php =====================
$today = date('Y-m-d');
$snapCheckStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM trending_snapshot WHERE snapshot_date = ?");
mysqli_stmt_bind_param($snapCheckStmt, "s", $today);
mysqli_stmt_execute($snapCheckStmt);
$snapExists = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($snapCheckStmt))['cnt'] > 0;
mysqli_stmt_close($snapCheckStmt);

if (!$snapExists) {
    $computeStmt = mysqli_query(
        $connection,
        "SELECT l.id, l.views_count, (SELECT COUNT(*) FROM wishlists w WHERE w.listing_id = l.id) AS likes_count
         FROM listings l WHERE l.status = 'active'"
    );
    $insSnapStmt = mysqli_prepare(
        $connection,
        "INSERT INTO trending_snapshot (listing_id, likes_count, views_count, score, snapshot_date) VALUES (?, ?, ?, ?, ?)"
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

$trendStmt = mysqli_prepare(
    $connection,
    "SELECT l.id, l.title, l.looking_for, l.condition_type, l.location,
            (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image,
            w.id AS wishlist_id,
            (SELECT COUNT(*) FROM wishlists w2 WHERE w2.listing_id = l.id) AS live_likes
     FROM trending_snapshot ts
     INNER JOIN listings l ON l.id = ts.listing_id
     LEFT JOIN wishlists w ON w.listing_id = l.id AND w.user_id = ?
     WHERE ts.snapshot_date = ? AND l.status = 'active'
     ORDER BY ts.score DESC, ts.likes_count DESC
     LIMIT 4"
);
mysqli_stmt_bind_param($trendStmt, "is", $currentUserId, $today);
mysqli_stmt_execute($trendStmt);
$trendResult = mysqli_stmt_get_result($trendStmt);
$trending = [];
while ($row = mysqli_fetch_assoc($trendResult)) {
    $trending[] = [
        'id'        => $row['id'],
        'image'     => $row['image'],
        'title'     => $row['title'],
        'for'       => $row['looking_for'],
        'cond'      => $row['condition_type'],
        'loc'       => $row['location'],
        'favorited' => !empty($row['wishlist_id']),
        'likes'     => (int) $row['live_likes'],
    ];
}
mysqli_stmt_close($trendStmt);

// ===================== Stats band: real counts =====================
$activeTradersCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM users WHERE status = 'active'"))['cnt'];
$successfulTradesCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted'"))['cnt'];
$itemsListedCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM listings WHERE status IN ('active', 'traded')"))['cnt'];
$citiesCoveredCount = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(DISTINCT location) AS cnt FROM listings WHERE location IS NOT NULL AND location != ''"))['cnt'];

// ===================== Testimonials: real reviews with a comment, highest rated first =====================
// Uses email-based naming (landingAuthorInfo), same as the rest of the app — no users.name column assumed.
$testimonialStmt = mysqli_query(
    $connection,
    "SELECT r.rating, r.comment, u.email,
            (SELECT COUNT(*) FROM trade_requests tr WHERE tr.status = 'accepted' AND (tr.requester_id = u.id OR tr.owner_id = u.id)) AS reviewer_trades
     FROM reviews r
     LEFT JOIN users u ON u.id = r.reviewer_id
     WHERE r.comment IS NOT NULL AND r.comment != ''
     ORDER BY r.rating DESC, r.created_at DESC
     LIMIT 3"
);
$testimonials = [];
while ($row = mysqli_fetch_assoc($testimonialStmt)) {
    $author = landingAuthorInfo($row['email']);
    $testimonials[] = [
        'initials' => $author['initials'],
        'name'     => $author['name'],
        'comment'  => $row['comment'],
        'trades'   => (int) $row['reviewer_trades'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Swapify — Trade Smarter. Own Better.</title>
    <meta name="description" content="Swapify is a community barter marketplace where you exchange goods instead of buying or selling them.">
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <!-- ============================== HERO ============================== -->
    <section class="hero-swapify">
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <!-- Hero copy + search -->
                <div class="col-lg-6">
                    <span class="eyebrow"><i class="bi bi-arrow-left-right me-1"></i> No money changes hands</span>
                    <h1 class="hero-headline mt-3 mb-3">
                        Don't sell it. <span class="accent">Swap it</span> for something you actually want.
                    </h1>
                    <p class="hero-lead mb-4">
                        Swapify connects you with people nearby who have what you need — and want
                        what you have. List an item, browse offers, and trade directly. No listing fees, no cash required.
                    </p>

                    <form id="heroSearchForm" class="hero-search d-flex align-items-center mb-3">
                        <i class="bi bi-search text-muted-swap ms-3"></i>
                        <input type="search" id="heroSearchInput" class="form-control py-2 border-0"
                               placeholder="Search for cameras, books, sneakers, guitars...">
                        <button type="submit" class="btn btn-primary px-4">Search</button>
                    </form>

                    <div class="hero-trustbar d-flex align-items-center gap-3">
                        <span><i class="bi bi-people-fill text-primary me-1"></i> <?= number_format($activeTradersCount) ?>+ traders</span>
                        <span><i class="bi bi-shield-check text-primary me-1"></i> Verified profiles</span>
                    </div>
                </div>

                <!-- Signature swap visual (illustrative, not tied to a real listing) -->
                <div class="col-lg-6">
                    <div class="swap-visual mx-auto" style="max-width:420px;">
                        <div class="swap-card">
                            <div class="swap-item-row give">
                                <div class="swap-item-thumb"><i class="bi bi-camera"></i></div>
                                <div>
                                    <div class="swap-item-label">You give</div>
                                    <div class="swap-item-name">Canon AE-1 Film Camera</div>
                                </div>
                            </div>

                            <div class="swap-arc-badge"><i class="bi bi-arrow-down-up"></i></div>

                            <div class="swap-item-row get">
                                <div class="swap-item-thumb"><i class="bi bi-controller"></i></div>
                                <div>
                                    <div class="swap-item-label">You get</div>
                                    <div class="swap-item-name">Nintendo Switch OLED</div>
                                </div>
                            </div>

                            <button class="btn btn-primary w-100 mt-1">Propose this trade</button>
                        </div>

                        <div class="swap-float-badge" style="top:-18px; right:-22px;">
                            <i class="bi bi-star-fill text-warning"></i> 4.9 rating
                        </div>
                        <div class="swap-float-badge d-none d-sm-flex" style="bottom:10px; left:-30px; animation-delay:1s;">
                            <i class="bi bi-check-circle-fill text-primary"></i> Trade completed
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================= CATEGORY PILLS ========================= -->
    <section class="pb-2">
        <div class="container">
            <div class="category-scroll">
                <?php if (empty($categoryPills)): ?>
                    <span class="text-muted-swap small">No categories yet.</span>
                <?php else: ?>
                    <?php foreach ($categoryPills as $i => $cat): ?>
                        <a href="<?= asset('marketplace.php') ?>?category[]=<?= urlencode($cat['name']) ?>" class="category-pill <?= $i === 0 ? 'active' : '' ?>">
                            <i class="bi <?= landingCategoryIcon($cat['name']) ?>"></i> <?= htmlspecialchars($cat['name']) ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ========================= FEATURED TRADES ========================= -->
    <section class="section-py">
        <div class="container">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <span class="eyebrow">Handpicked</span>
                    <h2 class="mt-2 mb-0">Featured trades</h2>
                </div>
                <a href="<?= asset('marketplace.php') ?>" class="btn btn-light-swap">Browse all <i class="bi bi-arrow-right ms-1"></i></a>
            </div>

            <?php if (empty($featured)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
                    <h5>No listings yet</h5>
                    <p>Be the first to list something to trade.</p>
                    <a href="<?= asset('create_listing.php') ?>" class="btn btn-primary btn-sm">List an item</a>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($featured as $item): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="trade-card">
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
                                    <span class="badge-condition"><?= htmlspecialchars($item['cond']) ?></span>
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
        </div>
    </section>

    <!-- ========================= TRENDING ITEMS ========================= -->
    <section class="section-py section-tint">
        <div class="container">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <span class="eyebrow">Right now</span>
                    <h2 class="mt-2 mb-0">Trending items</h2>
                </div>
                <a href="<?= asset('trending.php') ?>" class="btn btn-light-swap">See what's hot <i class="bi bi-arrow-right ms-1"></i></a>
            </div>

            <?php if (empty($trending)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-fire"></i></div>
                    <h5>Nothing trending yet</h5>
                    <p>Once listings get likes and views, the hottest items will show up here.</p>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($trending as $item): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="trade-card">
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
                                    <span class="badge-trending">🔥 <?= $item['likes'] ?> like<?= $item['likes'] === 1 ? '' : 's' ?></span>
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
        </div>
    </section>

    <!-- ========================= HOW SWAPIFY WORKS ========================= -->
    <section class="section-py">
        <div class="container">
            <div class="text-center mb-5">
                <span class="eyebrow">The process</span>
                <h2 class="mt-2">How Swapify works</h2>
                <p class="text-muted-swap">Three steps, zero cash.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-index">01</div>
                        <h5>List what you own</h5>
                        <p class="text-muted-swap mb-0">Snap a few photos, describe the item and its condition, and tell the community what you'd like in return.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-index">02</div>
                        <h5>Get trade offers</h5>
                        <p class="text-muted-swap mb-0">Browse offers from other traders, message them, and negotiate the swap that works for both of you.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-index">03</div>
                        <h5>Meet up &amp; trade</h5>
                        <p class="text-muted-swap mb-0">Confirm the exchange, meet locally or ship, then rate each other to build trust on the platform.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================= STATS ========================= -->
    <section class="pb-5">
        <div class="container">
            <div class="stats-band">
                <div class="row text-center g-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-number" data-count-to="<?= $activeTradersCount ?>" data-suffix="+">0</div>
                        <div class="stat-label">Active traders</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-number" data-count-to="<?= $successfulTradesCount ?>" data-suffix="+">0</div>
                        <div class="stat-label">Successful trades</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-number" data-count-to="<?= $itemsListedCount ?>" data-suffix="+">0</div>
                        <div class="stat-label">Items listed</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-number" data-count-to="<?= $citiesCoveredCount ?>" data-suffix="+">0</div>
                        <div class="stat-label">Cities covered</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================= TESTIMONIALS ========================= -->
    <?php if (!empty($testimonials)): ?>
    <section class="section-py section-tint">
        <div class="container">
            <div class="text-center mb-5">
                <span class="eyebrow">Community</span>
                <h2 class="mt-2">What traders are saying</h2>
            </div>

            <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner">
                    <?php foreach ($testimonials as $i => $t): ?>
                    <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
                        <div class="testimonial-card">
                            <div class="testimonial-avatar"><?= htmlspecialchars($t['initials']) ?></div>
                            <p class="fs-5 mb-3">"<?= htmlspecialchars($t['comment']) ?>"</p>
                            <strong><?= htmlspecialchars($t['name']) ?></strong>
                            <div class="text-muted-swap small"><?= $t['trades'] ?> completed trade<?= $t['trades'] === 1 ? '' : 's' ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($testimonials) > 1): ?>
                <div class="d-flex justify-content-center gap-2 mt-4">
                    <button class="btn btn-light-swap btn-sm rounded-circle" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev" style="width:40px;height:40px;">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button class="btn btn-light-swap btn-sm rounded-circle" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next" style="width:40px;height:40px;">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========================= FAQ ========================= -->
    <section class="section-py">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <span class="eyebrow">Questions</span>
                    <h2 class="mt-2">Frequently asked</h2>
                    <p class="text-muted-swap">Can't find what you're looking for? <a href="#">Contact support</a>.</p>
                </div>
                <div class="col-lg-8">
                    <div class="accordion accordion-swapify" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    Is Swapify really free to use?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-muted-swap">Yes. Listing items and making trade offers is completely free. There are no hidden fees for completing a swap.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    Can I add cash to balance an uneven trade?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-muted-swap">Some traders agree to a small cash top-up when item values differ. That arrangement is between the two traders and isn't processed by Swapify.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    How do trusted trader badges work?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-muted-swap">Badges are earned from completed trades, response time, and review ratings. They help other traders know who's reliable before they commit.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    What happens if a trade goes wrong?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body text-muted-swap">You can report the listing or the user directly from the trade page. Our team reviews every report and can suspend accounts that break community guidelines.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========================= CTA ========================= -->
    <section class="pb-5">
        <div class="container">
            <div class="cta-band text-center">
                <h2 class="text-white mb-2">Got something to trade?</h2>
                <p class="mb-4" style="opacity:0.9;">Join <?= number_format($activeTradersCount) ?>+ traders and list your first item in under two minutes.</p>
                <a href="<?= asset('register.php') ?>" class="btn btn-light fw-semibold px-4">Create your free account</a>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('index'); ?>
</body>
</html>