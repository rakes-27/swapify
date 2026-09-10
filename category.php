<?php
/**
 * Swapify — Category Page
 * Frontend only. Reuses the marketplace filter sidebar, tradeItemCard(),
 * and marketplace.js (identical grid/list toggle + filter-change behavior).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';

$categories = [
    'cameras' => ['name' => 'Cameras', 'icon' => 'bi-camera', 'desc' => 'Film, digital, and instant cameras from traders who actually use them.'],
    'books' => ['name' => 'Books', 'icon' => 'bi-book', 'desc' => 'Fiction, textbooks, comics, and everything in between.'],
    'games' => ['name' => 'Games', 'icon' => 'bi-controller', 'desc' => 'Consoles, controllers, and game bundles ready to swap.'],
];
$slug = isset($_GET['c']) && isset($categories[$_GET['c']]) ? $_GET['c'] : 'cameras';
$category = $categories[$slug];

$items = [
    ['id' => 1, 'icon' => 'bi-camera', 'title' => 'Canon AE-1 Film Camera', 'for' => 'Switch or gaming laptop', 'cond' => 'Good', 'loc' => 'Kathmandu'],
    ['id' => 2, 'icon' => 'bi-camera2', 'title' => 'Nikon FM2 Film Camera', 'for' => 'Vintage lens or tripod', 'cond' => 'Good', 'loc' => 'Kathmandu'],
    ['id' => 3, 'icon' => 'bi-camera', 'title' => 'GoPro Hero 9', 'for' => 'Drone or action gear', 'cond' => 'Used', 'loc' => 'Pokhara'],
    ['id' => 4, 'icon' => 'bi-aspect-ratio', 'title' => 'Polaroid Instant Camera', 'for' => 'Film rolls or gadgets', 'cond' => 'Like New', 'loc' => 'Lalitpur'],
    ['id' => 5, 'icon' => 'bi-camera2', 'title' => 'Sony Alpha a6000', 'for' => 'Laptop or drone', 'cond' => 'Good', 'loc' => 'Kathmandu'],
    ['id' => 6, 'icon' => 'bi-image', 'title' => 'Tripod + Studio Light Kit', 'for' => 'Camera lenses', 'cond' => 'Good', 'loc' => 'Kathmandu'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $category['name'] ?> — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">

            <!-- ============================== BANNER ============================== -->
            <div class="category-banner mb-4">
                <div class="category-banner-icon"><i class="bi <?= $category['icon'] ?>"></i></div>
                <h2 class="text-white mb-2"><?= $category['name'] ?></h2>
                <p class="mb-3" style="max-width:34rem; opacity:0.9;"><?= $category['desc'] ?></p>
                <div class="d-flex gap-4">
                    <span class="category-banner-stat"><i class="bi bi-box-seam me-1"></i> <?= count($items) * 21 ?> items available</span>
                    <span class="category-banner-stat"><i class="bi bi-arrow-repeat me-1"></i> 340 trades this month</span>
                </div>
            </div>

            <!-- Popular subcategories -->
            <div class="category-scroll mb-4">
                <?php foreach (['All ' . $category['name'], 'Film', 'Digital', 'Instant', 'Lenses', 'Accessories'] as $tag): ?>
                    <a href="#" class="category-pill <?= $tag === 'All ' . $category['name'] ? 'active' : '' ?>"><?= $tag ?></a>
                <?php endforeach; ?>
            </div>

            <div class="row g-4">

                <!-- Filter sidebar -->
                <div class="col-lg-3">
                    <div class="filter-panel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold">Filters</span>
                            <a href="#" class="small text-decoration-none">Clear all</a>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Condition</div>
                            <?php foreach (['New', 'Like New', 'Good', 'Used'] as $i => $cond): ?>
                                <div class="form-check filter-check mb-1">
                                    <input class="form-check-input" type="checkbox" id="cond<?= $i ?>">
                                    <label class="form-check-label" for="cond<?= $i ?>"><?= $cond ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="filter-group">
                            <div class="filter-group-title">Location</div>
                            <select class="form-select" style="border-radius:12px;">
                                <option>All locations</option>
                                <option>Kathmandu</option>
                                <option>Pokhara</option>
                                <option>Lalitpur</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <div class="form-check form-switch filter-check">
                                <input class="form-check-input" type="checkbox" id="trustedOnly">
                                <label class="form-check-label" for="trustedOnly">Trusted traders only</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="col-lg-9">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <span class="text-muted-swap small">Showing <?= count($items) ?> of <?= count($items) * 21 ?> items</span>
                        <div class="d-flex align-items-center gap-2">
                            <select class="sort-select" id="sortSelect">
                                <option>Newest</option>
                                <option>Trending</option>
                                <option>Nearby</option>
                            </select>
                            <div class="view-toggle">
                                <button class="view-toggle-btn active" id="viewGridBtn" aria-label="Grid view"><i class="bi bi-grid-3x3-gap"></i></button>
                                <button class="view-toggle-btn" id="viewListBtn" aria-label="List view"><i class="bi bi-list-ul"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4" id="marketplaceGrid">
                        <?php foreach ($items as $item): ?>
                            <div class="grid-col col-md-6 col-lg-4"><?php tradeItemCard($item); ?></div>
                        <?php endforeach; ?>
                    </div>

                    <nav class="mt-5" aria-label="Category pagination">
                        <ul class="pagination pagination-swap justify-content-center">
                            <li class="page-item disabled"><a class="page-link" href="#"><i class="bi bi-chevron-left"></i></a></li>
                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item"><a class="page-link" href="#"><i class="bi bi-chevron-right"></i></a></li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('marketplace'); ?>
</body>
</html>