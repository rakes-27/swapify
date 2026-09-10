<?php
/**
 * Swapify — components/cards.php
 * Reusable card markup. Include this file, then call the functions below
 * wherever a card is needed instead of repeating the HTML per page.
 * All data is passed in as plain arrays — no backend calls here.
 */
?>

<?php
/** Small stat card — e.g. dashboard "Active Listings: 8" */
function statCard($icon, $label, $value, $trend = null) {
?>
<div class="stat-card">
    <div class="stat-card-icon"><i class="bi <?= $icon ?>"></i></div>
    <div class="stat-card-value"><?= $value ?></div>
    <div class="stat-card-label"><?= $label ?></div>
    <?php if ($trend): ?>
        <div class="stat-card-trend <?= $trend['positive'] ? 'up' : 'down' ?>">
            <i class="bi <?= $trend['positive'] ? 'bi-arrow-up-right' : 'bi-arrow-down-right' ?>"></i> <?= $trend['text'] ?>
        </div>
    <?php endif; ?>
</div>
<?php } ?>

<?php
/**
 * Trade / item card — used in marketplace grids, wishlist, featured sections.
 * Renders the listing's actual uploaded photo. Falls back to a plain
 * placeholder icon only if no image path was provided.
 * Pass $item['favorited'] = true/false when the caller knows the current
 * user's wishlist state (marketplace.php, wishlist.php, etc.) so the heart
 * renders filled/active on load instead of needing a second round trip.
 */
function tradeItemCard($item) {
    $hasImage = !empty($item['image']);
    $isFavorited = !empty($item['favorited']);
    $detailsUrl = asset('items_details.php') . '?id=' . urlencode($item['id']);
?>
<div class="trade-card" onclick="window.location.href='<?= $detailsUrl ?>'" style="cursor:pointer;">
    <div class="trade-card-img" style="position:relative; overflow:hidden;">
        <?php if ($hasImage): ?>
            <img src="<?= asset(htmlspecialchars($item['image'])) ?>"
                 alt="<?= htmlspecialchars($item['title']) ?>"
                 style="width:100%; height:100%; object-fit:cover; display:block;">
        <?php else: ?>
            <i class="bi bi-image"></i>
        <?php endif; ?>
        <button class="trade-card-fav <?= $isFavorited ? 'active' : '' ?>" data-item-id="<?= $item['id'] ?>" aria-label="Add to wishlist" onclick="event.stopPropagation();">
            <i class="bi <?= $isFavorited ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
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
<?php } ?>

<?php
/** Profile / trader card — used on profile page, leaderboard, trade requests */
function profileCard($user) {
?>
<div class="profile-card">
    <div class="d-flex align-items-center gap-3">
        <div class="profile-card-avatar"><?= $user['initials'] ?></div>
        <div>
            <div class="d-flex align-items-center gap-1">
                <span class="fw-semibold"><?= $user['name'] ?></span>
                <?php if (!empty($user['trusted'])): ?>
                    <i class="bi bi-patch-check-fill text-primary" title="Trusted Trader"></i>
                <?php endif; ?>
            </div>
            <div class="text-muted-swap small"><?= $user['tradeCount'] ?> trades &middot; <i class="bi bi-star-fill text-warning"></i> <?= $user['rating'] ?></div>
        </div>
    </div>
</div>
<?php } ?>

<?php
/** Review card — used on profile page and item details discussion */
function reviewCard($review) {
?>
<div class="review-card">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="d-flex align-items-center gap-2">
            <div class="profile-card-avatar small"><?= $review['initials'] ?></div>
            <div>
                <div class="fw-semibold small"><?= $review['name'] ?></div>
                <div class="text-muted-swap" style="font-size:0.75rem;"><?= $review['date'] ?></div>
            </div>
        </div>
        <div class="text-warning small">
            <?php for ($i = 0; $i < 5; $i++): ?>
                <i class="bi <?= $i < $review['stars'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
            <?php endfor; ?>
        </div>
    </div>
    <p class="small text-muted-swap mb-0"><?= $review['text'] ?></p>
</div>
<?php } ?>