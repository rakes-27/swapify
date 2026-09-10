<?php
/**
 * Swapify — Marketplace (also serves as Search Results: /marketplace.php?q=camera)
 * Same URL serves two purposes:
 *   - Normal GET request  -> renders the full HTML page
 *   - AJAX GET request (X-Requested-With: XMLHttpRequest) -> returns JSON
 *     with just the grid + pagination HTML, so marketplace.js can swap
 *     content in without a full page reload.
 * Also handles POST action=toggle_favorite (wishlists table), same pattern
 * as items_details.php, so the heart icon on each card works here too.
 *
 * Traded listings: once a trade_request is accepted (see trade_requests.php),
 * both listings involved get status='traded'. They still show here (with a
 * "Traded" badge) for 1 day, then a lazy sweep below flips them to
 * status='deleted' so they stop appearing anywhere. We soft-delete rather
 * than hard-DELETE so trade_history.php's join against listings still works.
 *
 * Category checkboxes are pulled live from the `categories` table (all
 * categories, not just ones with active listings) so the filter sidebar
 * always matches what the "category[]" WHERE clause below can actually
 * match against — no more hardcoded list drifting out of sync with the DB.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

// ===================== SWEEP: soft-delete listings traded >1 day ago =====================
mysqli_query(
    $connection,
    "UPDATE listings SET status = 'deleted'
     WHERE status = 'traded'
       AND (
            id IN (SELECT target_listing_id FROM trade_requests WHERE status = 'accepted' AND updated_at < (NOW() - INTERVAL 1 DAY))
         OR id IN (SELECT offered_listing_id FROM trade_requests WHERE status = 'accepted' AND updated_at < (NOW() - INTERVAL 1 DAY))
       )"
);

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
        mysqli_stmt_execute($insStmt);
        mysqli_stmt_close($insStmt);
        echo json_encode(['success' => true, 'active' => true]);
    }
    exit;
}

// ---------- Read filters from the query string ----------
$query               = isset($_GET['q']) ? trim($_GET['q']) : '';
$isSearch            = $query !== '';
$selectedCategories  = isset($_GET['category']) && is_array($_GET['category']) ? $_GET['category'] : [];
$selectedConditions  = isset($_GET['condition']) && is_array($_GET['condition']) ? $_GET['condition'] : [];
$selectedLocation    = isset($_GET['location']) ? trim($_GET['location']) : '';
$sort                = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$trustedOnly         = isset($_GET['trusted']) && $_GET['trusted'] === '1';

$currentUserId = $_SESSION['user_id'] ?? 0;

$perPage = 9;
$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// ---------- Build WHERE clause dynamically ----------
// Active listings show normally; traded listings still show (with a badge)
// until the sweep above soft-deletes them a day later.
$conditionsSql = ["l.status IN ('active', 'traded')"];
$params = [];
$types  = '';

if ($isSearch) {
    $conditionsSql[] = "(l.title LIKE ? OR l.description LIKE ? OR l.looking_for LIKE ?)";
    $likeTerm = '%' . $query . '%';
    $params = array_merge($params, [$likeTerm, $likeTerm, $likeTerm]);
    $types .= 'sss';
}

if (!empty($selectedCategories)) {
    $placeholders = implode(',', array_fill(0, count($selectedCategories), '?'));
    $conditionsSql[] = "c.name IN ($placeholders)";
    foreach ($selectedCategories as $cat) {
        $params[] = $cat;
        $types .= 's';
    }
}

if (!empty($selectedConditions)) {
    $placeholders = implode(',', array_fill(0, count($selectedConditions), '?'));
    $conditionsSql[] = "l.condition_type IN ($placeholders)";
    foreach ($selectedConditions as $cond) {
        $params[] = $cond;
        $types .= 's';
    }
}

if ($selectedLocation !== '') {
    $conditionsSql[] = "l.location = ?";
    $params[] = $selectedLocation;
    $types .= 's';
}

$whereClause = 'WHERE ' . implode(' AND ', $conditionsSql);

// ---------- Sort ----------
// NOTE: "Nearby" would need real geolocation data we don't have yet,
// so it currently falls back to newest-first like the default.
// Traded items always sort after active ones, regardless of chosen sort.
switch ($sort) {
    case 'trending':
        $orderBy = "(l.status = 'traded') ASC, l.views_count DESC, l.created_at DESC";
        break;
    default:
        $orderBy = "(l.status = 'traded') ASC, l.created_at DESC";
}

// ---------- Count total matching rows ----------
$countSql = "SELECT COUNT(*) AS total
             FROM listings l
             LEFT JOIN categories c ON l.category_id = c.id
             $whereClause";
$countStmt = mysqli_prepare($connection, $countSql);
if ($types !== '') {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalItems = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
mysqli_stmt_close($countStmt);

// ---------- Fetch this page's listings ----------
// LEFT JOIN wishlists so each row knows if the current user already favorited it
// (wishlist_id is NULL for guests, since $currentUserId is 0 and never matches a real user).
$sql = "SELECT
            l.id,
            l.title,
            l.looking_for,
            l.condition_type,
            l.location,
            l.status,
            (SELECT image_path FROM listing_images
             WHERE listing_id = l.id
             ORDER BY sort_order ASC LIMIT 1) AS image,
            w.id AS wishlist_id
        FROM listings l
        LEFT JOIN categories c ON l.category_id = c.id
        LEFT JOIN wishlists w ON w.listing_id = l.id AND w.user_id = ?
        $whereClause
        ORDER BY $orderBy
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($connection, $sql);

$allParams = array_merge([$currentUserId], $params, [$perPage, $offset]);
$allTypes  = 'i' . $types . 'ii';

mysqli_stmt_bind_param($stmt, $allTypes, ...$allParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$items = [];
while ($row = mysqli_fetch_assoc($result)) {
    $items[] = [
        'id'        => $row['id'],
        'image'     => $row['image'],
        'title'     => $row['title'],
        'for'       => $row['looking_for'],
        'cond'      => $row['condition_type'],
        'loc'       => $row['location'],
        'favorited' => !empty($row['wishlist_id']),
        'status'    => $row['status'],
    ];
}
mysqli_stmt_close($stmt);

$totalPages = (int) ceil($totalItems / $perPage);

// ---------- Reusable HTML builders (used by both full page + AJAX) ----------
function buildGridHtml($items, $isSearch) {
    ob_start();
    if (empty($items)) {
        echo '<div class="col-12 text-center text-muted-swap py-5">'
            . ($isSearch ? 'No listings match your search.' : 'No listings yet — be the first to list an item!')
            . '</div>';
    } else {
        foreach ($items as $item) {
            $isTraded = ($item['status'] ?? '') === 'traded';
            echo '<div class="grid-col col-md-6 col-lg-4' . ($isTraded ? ' position-relative' : '') . '">';
            if ($isTraded) {
                echo '<span class="badge bg-dark position-absolute" style="top:12px; left:12px; z-index:5; opacity:0.85;">'
                    . '<i class="bi bi-check-circle-fill me-1"></i>Traded</span>';
            }
            tradeItemCard($item);
            echo '</div>';
        }
    }
    return ob_get_clean();
}

function buildPaginationHtml($page, $totalPages, $baseParams) {
    if ($totalPages <= 1) return '';
    ob_start();
    ?>
    <nav class="mt-5" aria-label="Marketplace pagination">
        <ul class="pagination pagination-swap justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($baseParams, ['page' => max(1, $page - 1)])) ?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($baseParams, ['page' => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($baseParams, ['page' => min($totalPages, $page + 1)])) ?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul>
    </nav>
    <?php
    return ob_get_clean();
}

function buildCountText($isSearch, $query, $totalItems, $offset, $perPage) {
    if ($isSearch) {
        return $totalItems . ' result' . ($totalItems === 1 ? '' : 's') . ' for "' . htmlspecialchars($query) . '"';
    }
    $shown = $totalItems > 0 ? ($offset + 1) : 0;
    return 'Showing ' . $shown . '–' . min($offset + $perPage, $totalItems) . ' of ' . $totalItems . ' items';
}

$baseParams = $_GET;
unset($baseParams['page']);

// ---------- AJAX branch: return JSON, skip the HTML page entirely ----------
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'        => true,
        'gridHtml'       => buildGridHtml($items, $isSearch),
        'paginationHtml' => buildPaginationHtml($page, $totalPages, $baseParams),
        'countText'      => buildCountText($isSearch, $query, $totalItems, $offset, $perPage),
    ]);
    exit;
}

// ---------- Normal request: fall through and render the full page ----------
// Category checkboxes are pulled live from the categories table (all
// categories, not just ones with active listings) so this list can never
// drift out of sync with what "category[]" actually matches against above.
$catRows = mysqli_query($connection, "SELECT name FROM categories ORDER BY name ASC");
$cats = [];
while ($row = mysqli_fetch_assoc($catRows)) {
    $cats[] = $row['name'];
}
$conditionOptions = ['New', 'Like New', 'Good', 'Used'];
$locationOptions = ['Kathmandu', 'Pokhara', 'Lalitpur', 'Bhaktapur'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isSearch ? 'Search: ' . htmlspecialchars($query) : 'Marketplace' ?> — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <!-- ============================== HEADER + SEARCH ============================== -->
    <section class="marketplace-header">
        <div class="container">
            <?php if (!$isSearch): ?>
                <h2 class="mb-3">Browse the marketplace</h2>
            <?php endif; ?>
            <div class="hero-search d-flex align-items-center mb-3" style="max-width:640px;">
                <i class="bi bi-search text-muted-swap ms-3"></i>
                <input type="search" id="marketplaceSearchInput" class="form-control py-2 border-0"
                       value="<?= htmlspecialchars($query) ?>" placeholder="Search for cameras, books, sneakers, guitars...">
                <button type="submit" class="btn btn-primary px-4" id="marketplaceSearchBtn">Search</button>
            </div>
        </div>
    </section>

    <!-- ============================== BODY ============================== -->
    <section class="section-py pt-4">
        <div class="container">
            <div class="row g-4">

                <!-- Filter sidebar -->
                <div class="col-lg-3">
                    <div class="filter-panel" id="filterPanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold">Filters</span>
                            <a href="/marketplace.php" class="small text-decoration-none" id="clearFiltersBtn">Clear all</a>
                        </div>

                        <div class="filter-group">
                            <div class="filter-group-title">Category</div>
                            <?php foreach ($cats as $i => $cat): ?>
                                <div class="form-check filter-check mb-1">
                                    <input class="form-check-input filter-input" type="checkbox" id="cat<?= $i ?>"
                                           name="category[]" value="<?= htmlspecialchars($cat) ?>"
                                           <?= in_array($cat, $selectedCategories, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cat<?= $i ?>"><?= htmlspecialchars($cat) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="filter-group">
                            <div class="filter-group-title">Condition</div>
                            <?php foreach ($conditionOptions as $i => $cond): ?>
                                <div class="form-check filter-check mb-1">
                                    <input class="form-check-input filter-input" type="checkbox" id="cond<?= $i ?>"
                                           name="condition[]" value="<?= htmlspecialchars($cond) ?>"
                                           <?= in_array($cond, $selectedConditions, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cond<?= $i ?>"><?= $cond ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="filter-group">
                            <div class="filter-group-title">Location</div>
                            <select class="form-select filter-input" id="locationSelect" name="location" style="border-radius:12px;">
                                <option value="" <?= $selectedLocation === '' ? 'selected' : '' ?>>All locations</option>
                                <?php foreach ($locationOptions as $loc): ?>
                                    <option value="<?= htmlspecialchars($loc) ?>" <?= $selectedLocation === $loc ? 'selected' : '' ?>><?= $loc ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="form-check form-switch filter-check">
                                <input class="form-check-input filter-input" type="checkbox" id="trustedOnly" name="trusted" value="1"
                                       <?= $trustedOnly ? 'checked' : '' ?>>
                                <label class="form-check-label" for="trustedOnly">Trusted traders only</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Results -->
                <div class="col-lg-9">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <span class="text-muted-swap small" id="resultsCountText">
                            <?= buildCountText($isSearch, $query, $totalItems, $offset, $perPage) ?>
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <select class="sort-select filter-input" id="sortSelect" name="sort">
                                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                                <option value="trending" <?= $sort === 'trending' ? 'selected' : '' ?>>Trending</option>
                                <option value="nearby" <?= $sort === 'nearby' ? 'selected' : '' ?>>Nearby</option>
                            </select>
                            <div class="view-toggle">
                                <button class="view-toggle-btn active" id="viewGridBtn" aria-label="Grid view"><i class="bi bi-grid-3x3-gap"></i></button>
                                <button class="view-toggle-btn" id="viewListBtn" aria-label="List view"><i class="bi bi-list-ul"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4" id="marketplaceGrid">
                        <?= buildGridHtml($items, $isSearch) ?>
                    </div>

                    <div id="paginationWrap">
                        <?= buildPaginationHtml($page, $totalPages, $baseParams) ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('marketplace'); ?>
</body>
</html>