<?php
/**
 * Swapify — Trade Requests
 * Loads real incoming/outgoing trade requests from the `trade_requests`
 * table (joined against `listings` / `users` / `categories`). Accept,
 * reject, and cancel all go through a FormData POST back to this same
 * page (action=update_trade_status) — no separate API endpoint.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];

// ---------- "3 days ago" style relative time ----------
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minute' . (floor($diff / 60) == 1 ? '' : 's') . ' ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
    $days = floor($diff / 86400);
    if ($days < 30) return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    return date('M j, Y', strtotime($datetime));
}

// ---------- Rough category -> icon mapping (listings have no icon column) ----------
function categoryIcon($categoryName) {
    $map = [
        'gaming'      => 'bi-controller',
        'game'        => 'bi-controller',
        'camera'      => 'bi-camera',
        'photo'       => 'bi-camera',
        'bike'        => 'bi-bicycle',
        'cycle'       => 'bi-bicycle',
        'scooter'     => 'bi-bicycle',
        'computer'    => 'bi-laptop',
        'laptop'      => 'bi-laptop',
        'electronic'  => 'bi-cpu',
        'audio'       => 'bi-headphones',
        'headphone'   => 'bi-headphones',
        'music'       => 'bi-music-note-beamed',
        'instrument'  => 'bi-music-note-beamed',
        'watch'       => 'bi-watch',
        'wearable'    => 'bi-watch',
        'art'         => 'bi-easel',
        'draw'        => 'bi-easel',
    ];
    $key = strtolower($categoryName ?? '');
    foreach ($map as $needle => $icon) {
        if (strpos($key, $needle) !== false) return $icon;
    }
    return 'bi-box-seam';
}

function authorInfo($email) {
    $name = 'Trader';
    $initials = 'U';
    if (!empty($email)) {
        $localPart = explode('@', $email)[0];
        $name = ucfirst($localPart);
        $initials = strtoupper(substr($localPart, 0, 2));
    }
    return ['name' => $name, 'initials' => $initials];
}

// ===================== HANDLE ACCEPT / REJECT / CANCEL (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_trade_status') {

    header('Content-Type: application/json');

    $tradeId = (int) ($_POST['trade_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if ($tradeId <= 0 || !in_array($newStatus, ['accepted', 'rejected', 'cancelled'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $stmt = mysqli_prepare($connection, "SELECT requester_id, owner_id, status, target_listing_id, offered_listing_id FROM trade_requests WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $tradeId);
    mysqli_stmt_execute($stmt);
    $trade = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$trade) {
        echo json_encode(['success' => false, 'message' => 'Trade request not found.']);
        exit;
    }

    if ($trade['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'This request has already been resolved.']);
        exit;
    }

    // Accept/reject: only the listing owner (the recipient of the request)
    if (in_array($newStatus, ['accepted', 'rejected'], true) && (int) $trade['owner_id'] !== (int) $currentUserId) {
        echo json_encode(['success' => false, 'message' => "You can't respond to this request."]);
        exit;
    }

    // Cancel: only the requester (the one who sent it)
    if ($newStatus === 'cancelled' && (int) $trade['requester_id'] !== (int) $currentUserId) {
        echo json_encode(['success' => false, 'message' => "You can't cancel this request."]);
        exit;
    }

    $updStmt = mysqli_prepare($connection, "UPDATE trade_requests SET status = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($updStmt, "si", $newStatus, $tradeId);

    if (!mysqli_stmt_execute($updStmt)) {
        mysqli_stmt_close($updStmt);
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
        exit;
    }
    mysqli_stmt_close($updStmt);

    // Accepting a trade: mark both listings as traded, and auto-reject any
    // other still-pending requests touching either item (they're no longer available).
    if ($newStatus === 'accepted') {
        $targetListingId  = (int) $trade['target_listing_id'];
        $offeredListingId = (int) $trade['offered_listing_id'];

        $markTradedStmt = mysqli_prepare($connection, "UPDATE listings SET status = 'traded' WHERE id IN (?, ?) AND status = 'active'");
        mysqli_stmt_bind_param($markTradedStmt, "ii", $targetListingId, $offeredListingId);
        mysqli_stmt_execute($markTradedStmt);
        mysqli_stmt_close($markTradedStmt);

        $autoRejectStmt = mysqli_prepare(
            $connection,
            "UPDATE trade_requests SET status = 'rejected', updated_at = NOW()
             WHERE status = 'pending' AND id != ?
               AND (target_listing_id IN (?, ?) OR offered_listing_id IN (?, ?))"
        );
        mysqli_stmt_bind_param($autoRejectStmt, "iiiii", $tradeId, $targetListingId, $offeredListingId, $targetListingId, $offeredListingId);
        mysqli_stmt_execute($autoRejectStmt);
        mysqli_stmt_close($autoRejectStmt);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ===================== RENDER PAGE (GET) =====================

$reqSql =
    "SELECT tr.id, tr.requester_id, tr.owner_id, tr.status, tr.created_at,
            u.email AS other_email,
            tl.title AS target_title, tc.name AS target_category,
            ol.title AS offered_title, oc.name AS offered_category
     FROM trade_requests tr
     JOIN users u ON u.id = %s
     JOIN listings tl ON tl.id = tr.target_listing_id
     LEFT JOIN categories tc ON tc.id = tl.category_id
     JOIN listings ol ON ol.id = tr.offered_listing_id
     LEFT JOIN categories oc ON oc.id = ol.category_id
     WHERE tr.%s = ?
     ORDER BY tr.created_at DESC";

// ---------- Incoming: requests made TO me (I own the target listing) ----------
$incoming = [];
$incStmt = mysqli_prepare($connection, sprintf($reqSql, 'tr.requester_id', 'owner_id'));
mysqli_stmt_bind_param($incStmt, "i", $currentUserId);
mysqli_stmt_execute($incStmt);
$incResult = mysqli_stmt_get_result($incStmt);
while ($row = mysqli_fetch_assoc($incResult)) {
    $author = authorInfo($row['other_email']);
    $incoming[] = [
        'id'        => $row['id'],
        'name'      => $author['name'],
        'initials'  => $author['initials'],
        'give_icon' => categoryIcon($row['offered_category']),
        'give'      => $row['offered_title'],
        'get_icon'  => categoryIcon($row['target_category']),
        'get'       => $row['target_title'],
        'status'    => $row['status'],
        'time'      => timeAgo($row['created_at']),
    ];
}
mysqli_stmt_close($incStmt);

// ---------- Outgoing: requests I made (I am the requester) ----------
$outgoing = [];
$outStmt = mysqli_prepare($connection, sprintf($reqSql, 'tr.owner_id', 'requester_id'));
mysqli_stmt_bind_param($outStmt, "i", $currentUserId);
mysqli_stmt_execute($outStmt);
$outResult = mysqli_stmt_get_result($outStmt);
while ($row = mysqli_fetch_assoc($outResult)) {
    $author = authorInfo($row['other_email']);
    $outgoing[] = [
        'id'        => $row['id'],
        'name'      => $author['name'],
        'initials'  => $author['initials'],
        'give_icon' => categoryIcon($row['offered_category']),
        'give'      => $row['offered_title'],
        'get_icon'  => categoryIcon($row['target_category']),
        'get'       => $row['target_title'],
        'status'    => $row['status'],
        'time'      => timeAgo($row['created_at']),
    ];
}
mysqli_stmt_close($outStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Requests — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:820px;">
            <div class="mb-4">
                <h2 class="mb-1">Trade requests</h2>
                <p class="text-muted-swap mb-0">Manage offers you've received and sent.</p>
            </div>

            <ul class="nav trade-request-tabs mb-4" id="tradeTabs">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabIncoming" type="button">Incoming (<?= count($incoming) ?>)</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOutgoing" type="button">Outgoing (<?= count($outgoing) ?>)</button></li>
            </ul>

            <div class="tab-content">

                <!-- ============================== INCOMING ============================== -->
                <div class="tab-pane fade show active" id="tabIncoming">
                    <?php if (empty($incoming)): ?>
                        <p class="text-muted-swap text-center py-4">No incoming trade requests yet.</p>
                    <?php endif; ?>
                    <?php foreach ($incoming as $req): ?>
                        <div class="trade-request-card" data-trade-id="<?= $req['id'] ?>">
                            <div class="trade-request-header">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="profile-card-avatar"><?= htmlspecialchars($req['initials']) ?></div>
                                    <div>
                                        <div class="fw-semibold small"><?= htmlspecialchars($req['name']) ?></div>
                                        <div class="text-muted-swap" style="font-size:0.75rem;"><?= htmlspecialchars($req['time']) ?></div>
                                    </div>
                                </div>
                                <span class="status-badge <?= htmlspecialchars($req['status']) ?>">
                                    <?php if ($req['status'] === 'accepted'): ?><i class="bi bi-check-circle-fill"></i><?php elseif ($req['status'] === 'pending'): ?><i class="bi bi-clock-fill"></i><?php endif; ?>
                                    <?= ucfirst($req['status']) ?>
                                </span>
                            </div>

                            <div class="trade-request-swap">
                                <div class="swap-side give-side">
                                    <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($req['give_icon']) ?>"></i></div>
                                    <div class="swap-side-text">
                                        <div class="swap-side-label">They offer</div>
                                        <div class="swap-side-name"><?= htmlspecialchars($req['give']) ?></div>
                                    </div>
                                </div>
                                <div class="swap-connector"><div class="swap-connector-badge"><i class="bi bi-arrow-down-up"></i></div></div>
                                <div class="swap-side get-side">
                                    <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($req['get_icon']) ?>"></i></div>
                                    <div class="swap-side-text">
                                        <div class="swap-side-label">For your item</div>
                                        <div class="swap-side-name"><?= htmlspecialchars($req['get']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($req['status'] === 'pending'): ?>
                                <div class="trade-request-actions">
                                    <button class="btn btn-primary btn-sm flex-grow-1 action-accept"><i class="bi bi-check-lg me-1"></i>Accept</button>
                                    <button class="btn btn-light-swap btn-sm flex-grow-1 action-reject"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                    <button class="trade-request-msg-btn" aria-label="Message"><i class="bi bi-chat-dots"></i></button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ============================== OUTGOING ============================== -->
                <div class="tab-pane fade" id="tabOutgoing">
                    <?php if (empty($outgoing)): ?>
                        <p class="text-muted-swap text-center py-4">No outgoing trade requests yet.</p>
                    <?php endif; ?>
                    <?php foreach ($outgoing as $req): ?>
                        <div class="trade-request-card" data-trade-id="<?= $req['id'] ?>">
                            <div class="trade-request-header">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="profile-card-avatar"><?= htmlspecialchars($req['initials']) ?></div>
                                    <div>
                                        <div class="fw-semibold small">Sent to <?= htmlspecialchars($req['name']) ?></div>
                                        <div class="text-muted-swap" style="font-size:0.75rem;"><?= htmlspecialchars($req['time']) ?></div>
                                    </div>
                                </div>
                                <span class="status-badge <?= htmlspecialchars($req['status']) ?>">
                                    <?php if ($req['status'] === 'pending'): ?><i class="bi bi-clock-fill"></i><?php elseif ($req['status'] === 'rejected'): ?><i class="bi bi-x-circle-fill"></i><?php elseif ($req['status'] === 'accepted'): ?><i class="bi bi-check-circle-fill"></i><?php endif; ?>
                                    <?= ucfirst($req['status']) ?>
                                </span>
                            </div>

                            <div class="trade-request-swap">
                                <div class="swap-side give-side">
                                    <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($req['give_icon']) ?>"></i></div>
                                    <div class="swap-side-text">
                                        <div class="swap-side-label">You offer</div>
                                        <div class="swap-side-name"><?= htmlspecialchars($req['give']) ?></div>
                                    </div>
                                </div>
                                <div class="swap-connector"><div class="swap-connector-badge"><i class="bi bi-arrow-down-up"></i></div></div>
                                <div class="swap-side get-side">
                                    <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($req['get_icon']) ?>"></i></div>
                                    <div class="swap-side-text">
                                        <div class="swap-side-label">For their item</div>
                                        <div class="swap-side-name"><?= htmlspecialchars($req['get']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($req['status'] === 'pending'): ?>
                                <div class="trade-request-actions">
                                    <button class="btn btn-outline-primary btn-sm flex-grow-1 action-cancel"><i class="bi bi-x-circle me-1"></i>Cancel request</button>
                                    <button class="trade-request-msg-btn" aria-label="Message"><i class="bi bi-chat-dots"></i></button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('trade_requests'); ?>
</body>
</html>