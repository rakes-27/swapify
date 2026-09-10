<?php
/**
 * Swapify — Search Results
 * Frontend only. Reuses the marketplace filter sidebar and tradeItemCard().
 * Results briefly show a skeleton state before "loading" in (search.js),
 * simulating a real /api/search.php fetch.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : 'camera';
$results = [
    ['id' => 1, 'icon' => 'bi-camera', 'title' => 'Canon AE-1 Film Camera', 'for' => 'Switch or gaming laptop', 'cond' => 'Good', 'loc' => 'Kathmandu'],
    ['id' => 2, 'icon' => 'bi-camera2', 'title' => 'Nikon FM2 Film Camera', 'for' => 'Vintage lens or tripod', 'cond' => 'Good', 'loc' => 'Kathmandu'],
    ['id' => 3, 'icon' => 'bi-camera', 'title' => 'GoPro Hero 9', 'for' => 'Drone or action gear', 'cond' => 'Used', 'loc' => 'Pokhara'],
    ['id' => 4, 'icon' => 'bi-aspect-ratio', 'title' => 'Polaroid Instant Camera', 'for' => 'Film rolls or gadgets', 'cond' => 'Like New', 'loc' => 'Lalitpur'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search: <?= htmlspecialchars($query) ?> — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <!-- ============================== SEARCH HEADER ============================== -->
    <section class="marketplace-header">
        <div class="container">
            <div class="hero-search d-flex align-items-center mb-3" style="max-width:640px;">
                <i class="bi bi-search text-muted-swap ms-3"></i>
                <input type="search" class="form-control py-2 border-0" value="<?= htmlspecialchars($query) ?>" placeholder="Search for items...">
                <button type="submit" class="btn btn-primary px-4">Search</button>
            </div>
            <p class="text-muted-swap mb-0">
                <?= count($results) ?> results for "<strong class="text-ink"><?= htmlspecialchars($query) ?></strong>"
            </p>
        </div>
    </section>

    <!-- ============================== BODY ============================== -->
    <section class="section-py pt-4">
        <div class="container">
            <div class="row g-4">

                <!-- Filter sidebar -->
                <div class="col-lg-3">
                    <div class="filter-panel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold">Filters</span>
                            <a href="#" class="small text-decoration-none">Clear all</a>
                        </div>

                        <div class="filter-group">
                            <div class="filter-group-title">Category</div>
                            <?php foreach (['Cameras', 'Electronics', 'Gadgets', 'Accessories'] as $i => $cat): ?>
                                <div class="form-check filter-check mb-1">
                                    <input class="form-check-input" type="checkbox" id="cat<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cat<?= $i ?>"><?= $cat ?></label>
                                </div>
                            <?php endforeach; ?>
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
                    </div>
                </div>

                <!-- Results -->
                <div class="col-lg-9">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <span class="text-muted-swap small">Showing <?= count($results) ?> of <?= count($results) ?> items</span>
                        <div class="d-flex align-items-center gap-2">
                            <select class="sort-select" id="sortSelect">
                                <option>Relevance</option>
                                <option>Newest</option>
                                <option>Trending</option>
                            </select>
                            <div class="view-toggle">
                                <button class="view-toggle-btn active" id="viewGridBtn" aria-label="Grid view"><i class="bi bi-grid-3x3-gap"></i></button>
                                <button class="view-toggle-btn" id="viewListBtn" aria-label="List view"><i class="bi bi-list-ul"></i></button>
                            </div>
                        </div>
                    </div>

                    <!-- Skeleton loading (shown briefly, then swapped by search.js) -->
                    <div class="row g-4" id="searchSkeleton">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="skeleton-card">
                                    <div class="skeleton skeleton-img"></div>
                                    <div class="skeleton-body">
                                        <div class="skeleton skeleton-line" style="width:40%;"></div>
                                        <div class="skeleton skeleton-line" style="width:80%;"></div>
                                        <div class="skeleton skeleton-line" style="width:60%;"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Results grid -->
                    <div class="row g-4 d-none" id="searchResults">
                        <?php if (empty($results)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="bi bi-search"></i></div>
                                <h5>No results found</h5>
                                <p>Try a different search term or adjust your filters.</p>
                                <a href="/marketplace.php" class="btn btn-primary">Browse marketplace</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($results as $item): ?>
                                <div class="grid-col col-md-6 col-lg-4"><?php tradeItemCard($item); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('search'); ?>
</body>
</html>