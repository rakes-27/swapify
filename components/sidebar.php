<!-- ============================== DASHBOARD SIDEBAR ============================== -->
<aside class="dashboard-sidebar">
    <a href="<?= asset('index.php') ?>" class="navbar-brand-swapify mb-4 d-inline-flex">
        <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
        Swapify
    </a>

    <div class="sidebar-section-label">Menu</div>
    <a href="<?= asset('dashboard.php') ?>" class="sidebar-link active"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <a href="<?= asset('marketplace.php') ?>" class="sidebar-link"><i class="bi bi-shop"></i> Marketplace</a>
    <a href="<?= asset('my_listings.php') ?>" class="sidebar-link"><i class="bi bi-plus-square"></i> My Listings</a>
    <a href="<?= asset('wishlist.php') ?>" class="sidebar-link"><i class="bi bi-heart"></i> Wishlist</a>

    <div class="sidebar-section-label">Trading</div>
    <a href="<?= asset('trade_requests.php') ?>" class="sidebar-link"><i class="bi bi-arrow-left-right"></i> Trade Requests</a>
    <a href="<?= asset('trade_history.php') ?>" class="sidebar-link"><i class="bi bi-clock-history"></i> Trade History</a>
    <a href="<?= asset('reviews.php') ?>" class="sidebar-link"><i class="bi bi-star"></i> Reviews</a>

    <div class="sidebar-section-label">Account</div>
    <a href="<?= asset('profile.php') ?>" class="sidebar-link"><i class="bi bi-person"></i> Profile</a>
    <a href="<?= asset('notifications.php') ?>" class="sidebar-link"><i class="bi bi-bell"></i> Notifications</a>
    <a href="<?= asset('settings.php') ?>" class="sidebar-link"><i class="bi bi-gear"></i> Settings</a>
</aside>