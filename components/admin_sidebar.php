<?php
/**
 * Swapify Admin — Sidebar component
 * `active` is now computed from the current script's filename, so it
 * correctly highlights whichever admin page you're actually on (previously
 * it was hardcoded onto "Dashboard" and never changed).
 */
$adminCurrentPage = basename($_SERVER['SCRIPT_NAME']);

function adminNavClass($page, $currentPage) {
    return 'sidebar-link' . ($page === $currentPage ? ' active' : '');
}
?>
<!-- ============================== ADMIN SIDEBAR ============================== -->
<aside class="dashboard-sidebar">
    <a href="<?= url('admin/dashboard.php') ?>" class="navbar-brand-swapify mb-1 d-inline-flex">
        <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
        Swapify
    </a>
    <div class="text-muted-swap small mb-4" style="margin-left:44px;">Admin Panel</div>

    <div class="sidebar-section-label">Overview</div>
    <a href="<?= url('admin/dashboard.php') ?>" class="<?= adminNavClass('dashboard.php', $adminCurrentPage) ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>

    <div class="sidebar-section-label">Manage</div>
    <a href="<?= url('admin/users.php') ?>" class="<?= adminNavClass('users.php', $adminCurrentPage) ?>"><i class="bi bi-people"></i> Users</a>
    <a href="<?= url('admin/listings.php') ?>" class="<?= adminNavClass('listings.php', $adminCurrentPage) ?>"><i class="bi bi-box-seam"></i> Listings</a>
    <a href="<?= url('admin/categories.php') ?>" class="<?= adminNavClass('categories.php', $adminCurrentPage) ?>"><i class="bi bi-tags"></i> Categories</a>
    <a href="<?= url('admin/reports.php') ?>" class="<?= adminNavClass('reports.php', $adminCurrentPage) ?>"><i class="bi bi-flag"></i> Reports</a>

    <div class="sidebar-section-label">Other</div>
    <a href="<?= url('index.php') ?>" class="sidebar-link"><i class="bi bi-box-arrow-up-right"></i> View site</a>
    <a href="<?= url('admin/logout.php') ?>" class="sidebar-link"><i class="bi bi-box-arrow-right"></i> Log out</a>
</aside>