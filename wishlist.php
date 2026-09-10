<?php
/**
 * Swapify — Wishlist
 * Loads the logged-in user's real wishlist from the `wishlists` table
 * (joined against `listings` / `listing_images`). Remove is handled by
 * a FormData POST back to this same page (same pattern as items_details.php:
 * toggle_favorite / create_trade_request) — no separate API endpoint.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/connection.php';

// Wishlist is a logged-in-only page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// ===================== HANDLE REMOVE FROM WISHLIST (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_wishlist') {

    header('Content-Type: application/json');

    $listingId = (int) ($_POST['listing_id'] ?? 0);

    if ($listingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item.']);
        exit;
    }

    $delStmt = mysqli_prepare($connection, "DELETE FROM wishlists WHERE user_id = ? AND listing_id = ?");
    mysqli_stmt_bind_param($delStmt, "ii", $userId, $listingId);

    if (mysqli_stmt_execute($delStmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    }
    mysqli_stmt_close($delStmt);
    exit;
}

// ===================== RENDER PAGE (GET) =====================

// ---------- Fetch the user's wishlisted listings ----------
$wishlistItems = [];
$stmt = mysqli_prepare(
    $connection,
    "SELECT
        l.id, l.title, l.looking_for, l.condition_type, l.location,
        (SELECT image_path FROM listing_images
         WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image
     FROM wishlists w
     JOIN listings l ON w.listing_id = l.id
     WHERE w.user_id = ? AND l.status = 'active'
     ORDER BY w.created_at DESC"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $wishlistItems[] = [
        'id'    => $row['id'],
        'image' => $row['image'],
        'title' => $row['title'],
        'for'   => $row['looking_for'],
        'cond'  => $row['condition_type'],
        'loc'   => $row['location'],
    ];
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wishlist — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="mb-4">
                <h2 class="mb-1">Your wishlist</h2>
                <p class="text-muted-swap mb-0">Items you've saved to trade for later.</p>
            </div>

            <!-- Grid -->
            <div class="row g-4 <?= empty($wishlistItems) ? 'd-none' : '' ?>" id="wishlistGrid">
                <?php foreach ($wishlistItems as $item): ?>
                    <div class="col-sm-6 col-lg-3 wishlist-col">
                        <div class="trade-card">
                            <div class="trade-card-img">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?= asset(htmlspecialchars($item['image'])) ?>" alt="<?= htmlspecialchars($item['title']) ?>" style="width:100%; height:100%; object-fit:cover;">
                                <?php else: ?>
                                    <i class="bi bi-image"></i>
                                <?php endif; ?>
                                <button class="wishlist-card-remove" data-item-id="<?= $item['id'] ?>" aria-label="Remove from wishlist">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <div class="trade-card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge-condition"><?= htmlspecialchars($item['cond']) ?></span>
                                    <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($item['loc']) ?></span>
                                </div>
                                <div class="trade-card-title"><?= htmlspecialchars($item['title']) ?></div>
                                <div class="trade-card-for">Looking for: <span class="amber"><?= htmlspecialchars($item['for']) ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Empty state -->
            <div class="empty-state <?= empty($wishlistItems) ? '' : 'd-none' ?>" id="wishlistEmptyState">
                <div class="empty-state-icon"><i class="bi bi-heart"></i></div>
                <h5>Your wishlist is empty</h5>
                <p>Save items you're interested in trading for and they'll show up here.</p>
                <a href="/marketplace.php" class="btn btn-primary">Browse marketplace</a>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('wishlist'); ?>
</body>
</html>