<?php
/**
 * Swapify — Notifications
 * Same URL, three purposes (same pattern as marketplace.php):
 *   - Normal GET                  -> renders the full HTML page (page 1 from DB)
 *   - AJAX GET (X-Requested-With) -> returns JSON { html, hasMore } for Load More
 *   - POST action=mark_read       -> marks one notification read
 *   - POST action=mark_all_read   -> marks all of the user's notifications read
 *
 * Notification rows themselves are created by DB triggers on wishlists,
 * trade_requests, comments, and reviews — this file never INSERTs into
 * the notifications table, only SELECTs and UPDATEs is_read.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$currentUserId = $_SESSION['user_id'];

// ===================== POST: mark_read / mark_all_read =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        if ($notificationId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
            exit;
        }
        $stmt = mysqli_prepare($connection, "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $notificationId, $currentUserId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $stmt = mysqli_prepare($connection, "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        mysqli_stmt_bind_param($stmt, "i", $currentUserId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ===================== Icon mapping (DB only stores type) =====================
function notificationIcon($type) {
    switch ($type) {
        case 'trade':    return 'bi-arrow-left-right';
        case 'review':   return 'bi-star-fill';
        case 'system':   return 'bi-shield-check';
        case 'wishlist': return 'bi-heart-fill';
        case 'comment':  return 'bi-chat-dots-fill';
        default:         return 'bi-bell';
    }
}

// ===================== Fetch a page of notifications =====================
function fetchNotifications($connection, $userId, $page, $perPage) {
    $offset = ($page - 1) * $perPage;
    $stmt = mysqli_prepare(
        $connection,
        "SELECT id, type, title, message, link, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $userId, $perPage, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return floor($diff / 604800) . ' weeks ago';
}

function buildNotificationsHtml($notifications) {
    ob_start();
    foreach ($notifications as $n) {
        $unread = !$n['is_read'];
        ?>
        <div class="notification-card <?= $unread ? 'unread' : '' ?>" data-id="<?= $n['id'] ?>" data-type="<?= $n['type'] ?>" role="button">
            <div class="notification-icon <?= $n['type'] ?>"><i class="bi <?= notificationIcon($n['type']) ?>"></i></div>
            <div class="flex-grow-1">
                <div class="small"><?= htmlspecialchars($n['message']) ?></div>
                <div class="text-muted-swap" style="font-size:0.78rem;"><?= timeAgo($n['created_at']) ?></div>
            </div>
            <?php if ($unread): ?>
                <div class="notification-unread-dot" title="Unread"></div>
            <?php endif; ?>
        </div>
        <?php
    }
    return ob_get_clean();
}

$perPage = 5;

// ===================== AJAX branch: Load More =====================
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $notifications = fetchNotifications($connection, $currentUserId, $page, $perPage);

    $nextPageRows = fetchNotifications($connection, $currentUserId, $page + 1, 1);

    echo json_encode([
        'success' => true,
        'html'    => buildNotificationsHtml($notifications),
        'hasMore' => !empty($nextPageRows),
    ]);
    exit;
}

// ===================== Normal request: render full page =====================
$notifications = fetchNotifications($connection, $currentUserId, 1, $perPage);

$countStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
mysqli_stmt_bind_param($countStmt, "i", $currentUserId);
mysqli_stmt_execute($countStmt);
$unreadCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
mysqli_stmt_close($countStmt);

$nextPageRows = fetchNotifications($connection, $currentUserId, 2, 1);
$hasMoreInitially = !empty($nextPageRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:760px;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">Notifications</h2>
                    <p class="text-muted-swap mb-0" id="unreadCountText"><?= $unreadCount === 0 ? 'All caught up' : $unreadCount . ' unread' ?></p>
                </div>
                <a href="#" class="small text-decoration-none" id="markAllReadBtn">Mark all as read</a>
            </div>

            <ul class="nav notification-tabs mb-4 flex-nowrap" id="notificationTabs" style="overflow-x:auto;">
                <li class="nav-item"><button class="nav-link active" type="button" data-filter="all">All</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-filter="trade">Trade updates</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-filter="review">Reviews</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-filter="system">System</button></li>
            </ul>

            <div id="notificationList">
                <?= buildNotificationsHtml($notifications) ?>
            </div>

            <div class="text-center mt-4">
                <button class="btn btn-light-swap d-inline-flex align-items-center gap-2" id="loadMoreBtn" data-page="1" <?= $hasMoreInitially ? '' : 'style="display:none;"' ?>>
                    <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                    Load more
                </button>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('notifications'); ?>
</body>
</html>