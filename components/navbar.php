<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../connection.php';

// ---------- Pull the current user's 5 most recent notifications ----------
$navUnread = 0;
$navNotifs = [];
$navInitials = 'U';

if (isset($_SESSION['user_id'])) {
    $navStmt = mysqli_prepare(
        $connection,
        "SELECT type, message, link, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 5"
    );
    mysqli_stmt_bind_param($navStmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($navStmt);
    $navResult = mysqli_stmt_get_result($navStmt);
    while ($row = mysqli_fetch_assoc($navResult)) {
        $navNotifs[] = $row;
        if (!$row['is_read']) $navUnread++;
    }
    mysqli_stmt_close($navStmt);

    // ---------- Current user's name/email, for initials (same logic as dashboard.php) ----------
    $navUserStmt = mysqli_prepare($connection, "SELECT name, email FROM users WHERE id = ?");
    mysqli_stmt_bind_param($navUserStmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($navUserStmt);
    $navUser = mysqli_fetch_assoc(mysqli_stmt_get_result($navUserStmt));
    mysqli_stmt_close($navUserStmt);

    if ($navUser) {
        $navDisplayName = $navUser['name'] ?: explode('@', $navUser['email'])[0];
        $navInitials = strtoupper(
            substr($navDisplayName, 0, 1) .
            (strpos($navDisplayName, ' ') !== false
                ? substr(strstr($navDisplayName, ' '), 1, 1)
                : substr($navDisplayName, 1, 1))
        );
    }
}

// ---------- Same icon mapping used on notifications.php ----------
function navNotificationIcon($type) {
    switch ($type) {
        case 'trade':    return ['bi-arrow-left-right', 'text-primary'];
        case 'review':   return ['bi-star-fill', 'text-warning'];
        case 'wishlist': return ['bi-heart-fill', 'text-danger'];
        case 'comment':  return ['bi-chat-dots-fill', 'text-primary'];
        case 'system':   return ['bi-shield-check', 'text-secondary'];
        default:         return ['bi-bell', 'text-secondary'];
    }
}

// ---------- Same "time ago" format used on notifications.php ----------
function navTimeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return floor($diff / 604800) . ' weeks ago';
}

// ---------- Which nav item is "active" — based on the actual current page,
// not a hardcoded class, so the highlight moves as you navigate ----------
$currentPage = basename($_SERVER['SCRIPT_NAME']);
function navLinkClass($page, $currentPage) {
    return 'nav-link nav-link-swapify' . ($page === $currentPage ? ' active' : '');
}
?>
<!-- ============================== NAVBAR ============================== -->
<nav class="navbar navbar-expand-lg navbar-swapify sticky-top">
  <div class="container">
    <a class="navbar-brand navbar-brand-swapify" href="<?= asset('index.php') ?>">
      <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
      Swapify
    </a>

    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#swapifyNav">
      <i class="bi bi-list fs-2"></i>
    </button>

    <div class="collapse navbar-collapse" id="swapifyNav">
      <ul class="navbar-nav mx-lg-3 mb-3 mb-lg-0">
        <li class="nav-item"><a class="<?= navLinkClass('marketplace.php', $currentPage) ?>" href="<?= asset('marketplace.php') ?>">Marketplace</a></li>
        <li class="nav-item"><a class="<?= navLinkClass('trending.php', $currentPage) ?>" href="<?= asset('trending.php') ?>">Trending</a></li>
        <li class="nav-item"><a class="<?= navLinkClass('leaderboard.php', $currentPage) ?>" href="<?= asset('leaderboard.php') ?>">Leaderboard</a></li>
        <li class="nav-item"><button type="button" class="nav-link nav-link-swapify border-0 bg-transparent" data-bs-toggle="modal" data-bs-target="#howItWorksModal">How it works</button></li>
      </ul>

      <!-- Search (desktop) -->
      <form action="<?= asset('marketplace.php') ?>" method="get"
            class="d-none d-lg-flex align-items-center navbar-search px-3 py-1 me-3" style="width:320px;">
        <i class="bi bi-search text-muted-swap me-2"></i>
        <input type="search" name="q" class="form-control form-control-sm py-2"
               placeholder="Search items to swap..." id="navSearchInput" autocomplete="off">
      </form>

      <div class="d-flex align-items-center gap-2 ms-auto">
        <?php if (!isset($_SESSION['user_id'])): ?>

          <!-- Logged-out state -->
          <div class="d-flex align-items-center gap-2" id="navGuestActions">
            <a href="<?= asset('login.php') ?>" class="btn btn-light-swap btn-sm">Log in</a>
            <a href="<?= asset('register.php') ?>" class="btn btn-primary btn-sm">Start Swapping</a>
          </div>

        <?php else: ?>

          <!-- Logged-in state -->
          <div class="d-flex align-items-center gap-2" id="navUserActions">
            <a href="<?= asset('create_listing.php') ?>" class="btn btn-amber btn-sm d-none d-sm-inline-flex">
              <i class="bi bi-plus-lg me-1"></i>List an item
            </a>

            <!-- Notifications -->
            <div class="dropdown">
              <button class="icon-btn" data-bs-toggle="dropdown" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <?php if ($navUnread > 0): ?>
                  <span class="dot-badge"></span>
                <?php endif; ?>
              </button>
              <div class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="width:320px;">
                <div class="d-flex justify-content-between align-items-center px-2 pb-2">
                  <strong class="small">Notifications</strong>
                  <a href="<?= asset('notifications.php') ?>" class="small text-decoration-none">View all</a>
                </div>
                <?php if (empty($navNotifs)): ?>
                  <div class="text-center text-muted-swap small py-3">No notifications yet</div>
                <?php else: ?>
                  <?php foreach ($navNotifs as $n): ?>
                    <?php [$icon, $color] = navNotificationIcon($n['type']); ?>
                    <a href="<?= htmlspecialchars($n['link'] ?: asset('notifications.php')) ?>"
                       class="dropdown-item rounded-3 py-2 small <?= $n['is_read'] ? '' : 'fw-semibold' ?>">
                      <div class="d-flex align-items-start gap-2">
                        <i class="bi <?= $icon ?> <?= $color ?> mt-1 flex-shrink-0"></i>
                        <div style="min-width:0; flex:1;">
                          <div style="white-space:normal; overflow-wrap:anywhere; word-break:break-word;">
                            <?= htmlspecialchars($n['message']) ?>
                          </div>
                          <div class="text-muted-swap" style="font-size:0.72rem; margin-top:0.2rem;"><?= navTimeAgo($n['created_at']) ?></div>
                        </div>
                      </div>
                    </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- Profile -->
            <div class="dropdown">
              <button class="btn btn-light-swap d-flex align-items-center gap-2 rounded-pill" data-bs-toggle="dropdown">
                <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:0.75rem;">
                  <?= htmlspecialchars($navInitials) ?>
                </span>
                <i class="bi bi-chevron-down small"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                <li><a class="dropdown-item rounded-3" href="<?= asset('dashboard.php') ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                <li><a class="dropdown-item rounded-3" href="<?= asset('profile.php') ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><a class="dropdown-item rounded-3" href="<?= asset('wishlist.php') ?>"><i class="bi bi-heart me-2"></i>Wishlist</a></li>
                <li><a class="dropdown-item rounded-3" href="<?= asset('settings.php') ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item rounded-3 text-danger" href="<?= asset('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
              </ul>
            </div>
          </div>

        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<!-- ============================== HOW IT WORKS MODAL ============================== -->
<div class="modal fade" id="howItWorksModal" tabindex="-1" aria-labelledby="howItWorksModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:24px; border:none;">
      <div class="modal-header border-0 pb-0">
        <div>
          <span class="eyebrow">The process</span>
          <h4 class="modal-title mt-1" id="howItWorksModalLabel">How Swapify works</h4>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-3">
        <p class="text-muted-swap mb-4">Three steps, zero cash.</p>
        <div class="row g-3">
          <div class="col-md-4">
            <div class="step-card">
              <div class="step-index">01</div>
              <h6>List what you own</h6>
              <p class="text-muted-swap mb-0 small">Snap a few photos, describe the item and its condition, and tell the community what you'd like in return.</p>
            </div>
          </div>
          <div class="col-md-4">
            <div class="step-card">
              <div class="step-index">02</div>
              <h6>Get trade offers</h6>
              <p class="text-muted-swap mb-0 small">Browse offers from other traders, message them, and negotiate the swap that works for both of you.</p>
            </div>
          </div>
          <div class="col-md-4">
            <div class="step-card">
              <div class="step-index">03</div>
              <h6>Meet up &amp; trade</h6>
              <p class="text-muted-swap mb-0 small">Confirm the exchange, meet locally or ship, then rate each other to build trust on the platform.</p>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a href="<?= asset('marketplace.php') ?>" class="btn btn-primary" data-bs-dismiss="modal">Start browsing</a>
      </div>
    </div>
  </div>
</div>