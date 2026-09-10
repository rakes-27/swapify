<?php
/**
 * Swapify — My Listings
 * Now backend-wired: pulls the logged-in user's own listings from MySQL
 * (join on categories + first image + offers count) instead of the old
 * hardcoded $myListings array. Delete is AJAX (FormData/$_POST) back to
 * this same file with action=delete — see my-listings.js.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/connection.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . asset('login.php'));
    exit;
}
$userId = $_SESSION['user_id'];

// ===================== HANDLE AJAX DELETE (POST, action=delete) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    header('Content-Type: application/json');

    $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

    if ($itemId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid item id.']);
        exit;
    }

    // Confirm the listing exists and belongs to this user before touching anything.
    $ownerStmt = mysqli_prepare($connection, "SELECT user_id FROM listings WHERE id = ?");
    mysqli_stmt_bind_param($ownerStmt, "i", $itemId);
    mysqli_stmt_execute($ownerStmt);
    $ownerResult = mysqli_stmt_get_result($ownerStmt);
    $owner = mysqli_fetch_assoc($ownerResult);
    mysqli_stmt_close($ownerStmt);

    if (!$owner) {
        echo json_encode(['success' => false, 'message' => 'Listing not found.']);
        exit;
    }

    if ((int) $owner['user_id'] !== (int) $userId) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this listing.']);
        exit;
    }

    mysqli_begin_transaction($connection);

    try {
        // ASSUMPTION: listing_images and listing_tags have a listing_id
        // column. If your schema uses ON DELETE CASCADE foreign keys
        // instead, these deletes are redundant but harmless.
        foreach (['listing_images', 'listing_tags'] as $table) {
            $delStmt = mysqli_prepare($connection, "DELETE FROM {$table} WHERE listing_id = ?");
            mysqli_stmt_bind_param($delStmt, "i", $itemId);
            mysqli_stmt_execute($delStmt);
            mysqli_stmt_close($delStmt);
        }

        // trade_requests references this listing via target_listing_id
        // (offers made on it) or offered_listing_id (it was offered up
        // in a trade on something else) — clear both.
        $delOffersStmt = mysqli_prepare(
            $connection,
            "DELETE FROM trade_requests WHERE target_listing_id = ? OR offered_listing_id = ?"
        );
        mysqli_stmt_bind_param($delOffersStmt, "ii", $itemId, $itemId);
        mysqli_stmt_execute($delOffersStmt);
        mysqli_stmt_close($delOffersStmt);

        $delListingStmt = mysqli_prepare($connection, "DELETE FROM listings WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($delListingStmt, "ii", $itemId, $userId);
        mysqli_stmt_execute($delListingStmt);
        $deleted = mysqli_stmt_affected_rows($delListingStmt);
        mysqli_stmt_close($delListingStmt);

        if ($deleted === 0) {
            throw new mysqli_sql_exception('Listing was not deleted.');
        }

        mysqli_commit($connection);
        echo json_encode(['success' => true, 'message' => 'Listing deleted.']);

    } catch (mysqli_sql_exception $e) {
        mysqli_rollback($connection);
        echo json_encode(['success' => false, 'message' => 'Something went wrong while deleting the listing. Please try again.']);
    }

    exit;
}

// ===================== RENDER PAGE (GET) =====================

// Maps category name -> bootstrap icon, since listings no longer carry a
// hand-picked icon field. Falls back to a generic box icon.
function categoryIcon(string $categoryName): string
{
    $map = [
        'Books'                => 'bi-book',
        'Games'                => 'bi-controller',
        'Electronics'          => 'bi-cpu',
        'Clothes'              => 'bi-bag',
        'Cameras'              => 'bi-camera',
        'Sports Equipment'     => 'bi-bicycle',
        'Musical Instruments'  => 'bi-music-note-beamed',
        'Collectibles'         => 'bi-gem',
        'Furniture'            => 'bi-lamp',
        'Art Supplies'         => 'bi-palette',
        'Accessories'          => 'bi-watch',
        'Gadgets'              => 'bi-phone',
    ];
    return $map[$categoryName] ?? 'bi-box-seam';
}

// Offers count comes from trade_requests — a listing's incoming offers are
// rows where target_listing_id matches this listing's id.
$sql = "SELECT
            l.id,
            l.title,
            l.looking_for AS `for`,
            l.condition_type AS cond,
            l.location AS loc,
            l.status,
            l.views_count AS views,
            c.name AS category_name,
            (SELECT image_path FROM listing_images li
                WHERE li.listing_id = l.id
                ORDER BY li.sort_order ASC LIMIT 1) AS thumb,
            (SELECT COUNT(*) FROM trade_requests tr WHERE tr.target_listing_id = l.id) AS offers
        FROM listings l
        LEFT JOIN categories c ON c.id = l.category_id
        WHERE l.user_id = ?
        ORDER BY l.created_at DESC";

$stmt = mysqli_prepare($connection, $sql);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$myListings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['icon'] = categoryIcon($row['category_name'] ?? '');
    $myListings[] = $row;
}
mysqli_stmt_close($stmt);

$statusMeta = [
    'active'  => ['label' => 'Active',  'class' => 'accepted'],
    'pending' => ['label' => 'Pending review', 'class' => 'pending'],
    'traded'  => ['label' => 'Traded', 'class' => 'cancelled'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Listings — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <h2 class="mb-1">My listings</h2>
                    <p class="text-muted-swap mb-0"><?= count($myListings) ?> items you've listed on Swapify</p>
                </div>
                <a href="<?= asset('create_listing.php') ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> New listing
                </a>
            </div>

            <!-- Status filter -->
            <div class="category-scroll my-listings-filter mb-4">
                <a href="#" class="category-pill active" data-status="all">All</a>
                <a href="#" class="category-pill" data-status="active">Active</a>
                <a href="#" class="category-pill" data-status="pending">Pending review</a>
                <a href="#" class="category-pill" data-status="traded">Traded</a>
            </div>

            <div class="<?= empty($myListings) ? 'd-none' : '' ?>" id="myListingsGrid">
                <?php foreach ($myListings as $item): $meta = $statusMeta[$item['status']] ?? $statusMeta['active']; ?>
                    <div class="my-listing-row" data-status="<?= htmlspecialchars($item['status']) ?>" data-item-id="<?= (int) $item['id'] ?>" data-title="<?= htmlspecialchars($item['title']) ?>">
                        <div class="my-listing-thumb"<?= $item['thumb'] ? ' style="background-image:url(' . htmlspecialchars(asset($item['thumb'])) . ');background-size:cover;background-position:center;"' : '' ?>>
                            <?php if (!$item['thumb']): ?><i class="bi <?= $item['icon'] ?>"></i><?php endif; ?>
                        </div>

                        <div class="my-listing-info">
                            <div class="my-listing-title-row">
                                <span class="my-listing-title"><?= htmlspecialchars($item['title']) ?></span>
                                <span class="status-badge <?= $meta['class'] ?>"><?= $meta['label'] ?></span>
                            </div>
                            <div class="my-listing-meta">
                                <span><?= htmlspecialchars($item['cond']) ?></span>
                                <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($item['loc']) ?></span>
                                <span>For: <?= htmlspecialchars($item['for']) ?></span>
                            </div>
                        </div>

                        <div class="my-listing-stats">
                            <div class="my-listing-stat">
                                <div class="my-listing-stat-value"><?= (int) $item['views'] ?></div>
                                <div class="my-listing-stat-label">Views</div>
                            </div>
                            <div class="my-listing-stat">
                                <div class="my-listing-stat-value"><?= (int) $item['offers'] ?></div>
                                <div class="my-listing-stat-label">Offers</div>
                            </div>
                        </div>

                        <div class="my-listing-actions">
                            <a href="<?= asset('edit_listing.php?id=' . (int) $item['id']) ?>" class="admin-row-icon-btn" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="<?= asset('items_details.php?id=' . (int) $item['id']) ?>" class="admin-row-icon-btn" aria-label="View"><i class="bi bi-eye"></i></a>
                            <button class="admin-row-icon-btn danger my-listing-delete-btn" aria-label="Delete listing"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="empty-state <?= empty($myListings) ? '' : 'd-none' ?>" id="myListingsEmptyState">
                <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
                <h5>No listings yet</h5>
                <p>List your first item and start getting trade offers from the community.</p>
                <a href="<?= asset('create_listing.php') ?>" class="btn btn-primary">Create a listing</a>
            </div>
        </div>
    </section>

    <!-- Delete confirm modal -->
    <div class="modal fade" id="deleteMyListingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-body text-center p-4">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                        <i class="bi bi-trash text-danger fs-4"></i>
                    </div>
                    <h5 class="mb-2">Delete <span id="deleteMyListingTitle">this listing</span>?</h5>
                    <p class="text-muted-swap mb-4">This can't be undone. Any pending trade offers on this item will be cancelled.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button class="btn btn-light-swap px-4" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-danger px-4" id="confirmDeleteMyListingBtn">
                            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-label">Delete</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php component('footer'); ?>

    <?php loadScripts('my_listings'); ?>
</body>
</html>