<?php
/**
 * Swapify — Favorites
 * Frontend only. Same quick-remove behavior as wishlist.php (reuses
 * wishlist.js via matching element IDs), with pagination added since
 * a full favorites list can span multiple pages.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$favoriteItems = [
    ['id' => 1, 'icon' => 'bi-controller', 'title' => 'Nintendo Switch OLED', 'for' => 'Cameras or bikes', 'cond' => 'Like New', 'loc' => 'Kathmandu'],
    ['id' => 2, 'icon' => 'bi-cpu', 'title' => 'DJI Mini Drone', 'for' => 'Anything interesting', 'cond' => 'Like New', 'loc' => 'Kathmandu'],
    ['id' => 3, 'icon' => 'bi-laptop', 'title' => 'MacBook Air M1', 'for' => 'iPad Pro or top-up', 'cond' => 'Good', 'loc' => 'Kathmandu'],
    ['id' => 4, 'icon' => 'bi-watch', 'title' => 'Apple Watch Series 8', 'for' => 'Fitness gear', 'cond' => 'Like New', 'loc' => 'Pokhara'],
    ['id' => 5, 'icon' => 'bi-headphones', 'title' => 'Sony WH-1000XM5', 'for' => 'Mechanical keyboard', 'cond' => 'Like New', 'loc' => 'Kathmandu'],
    ['id' => 6, 'icon' => 'bi-easel', 'title' => 'Wacom Drawing Tablet', 'for' => 'Art supplies bundle', 'cond' => 'Good', 'loc' => 'Lalitpur'],
    ['id' => 7, 'icon' => 'bi-bicycle', 'title' => 'Trek Mountain Bike', 'for' => 'Electric scooter', 'cond' => 'Used', 'loc' => 'Lalitpur'],
    ['id' => 8, 'icon' => 'bi-music-note-beamed', 'title' => 'Fender Acoustic Guitar', 'for' => 'DJ controller or synth', 'cond' => 'Good', 'loc' => 'Bhaktapur'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorites — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="mb-4">
                <h2 class="mb-1">Favorites</h2>
                <p class="text-muted-swap mb-0"><?= count($favoriteItems) ?> items you've favorited across the marketplace.</p>
            </div>

            <!-- Grid -->
            <div class="row g-4 <?= empty($favoriteItems) ? 'd-none' : '' ?>" id="wishlistGrid">
                <?php foreach ($favoriteItems as $item): ?>
                    <div class="col-sm-6 col-lg-3 wishlist-col">
                        <div class="trade-card">
                            <div class="trade-card-img">
                                <i class="bi <?= $item['icon'] ?>"></i>
                                <button class="wishlist-card-remove" data-item-id="<?= $item['id'] ?>" aria-label="Remove from favorites">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <div class="trade-card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge-condition"><?= $item['cond'] ?></span>
                                    <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <?= $item['loc'] ?></span>
                                </div>
                                <div class="trade-card-title"><?= $item['title'] ?></div>
                                <div class="trade-card-for">Looking for: <span class="amber"><?= $item['for'] ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Empty state -->
            <div class="empty-state <?= empty($favoriteItems) ? '' : 'd-none' ?>" id="wishlistEmptyState">
                <div class="empty-state-icon"><i class="bi bi-heart"></i></div>
                <h5>No favorites yet</h5>
                <p>Tap the heart icon on any item to save it here for quick access.</p>
                <a href="/marketplace.php" class="btn btn-primary">Browse marketplace</a>
            </div>

            <!-- Pagination -->
            <nav class="mt-5" aria-label="Favorites pagination">
                <ul class="pagination pagination-swap justify-content-center">
                    <li class="page-item disabled"><a class="page-link" href="#"><i class="bi bi-chevron-left"></i></a></li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-right"></i></a></li>
                </ul>
            </nav>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('wishlist'); ?>
</body>
</html>