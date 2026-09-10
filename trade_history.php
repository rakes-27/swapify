<?php
/**
 * Swapify — Trade History
 * Loads real completed ('accepted') and cancelled ('rejected'/'cancelled')
 * trade_requests involving the logged-in user (as either requester or
 * owner), plus a few summary stats. Read-only page — no POST handlers.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];

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
    if (!empty($email)) {
        $name = ucfirst(explode('@', $email)[0]);
    }
    return $name;
}

/**
 * Fetches trade_requests involving $currentUserId (either side) whose
 * status is in $statuses, resolved to "my item" / "their item" from
 * the current user's point of view.
 */
function fetchTradeHistory($connection, $currentUserId, array $statuses) {
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $types = 'ii' . str_repeat('s', count($statuses));

    $sql =
        "SELECT tr.id, tr.requester_id, tr.owner_id, tr.status, tr.updated_at,
                ru.email AS requester_email, ou.email AS owner_email,
                tl.title AS target_title, tc.name AS target_category,
                ol.title AS offered_title, oc.name AS offered_category
         FROM trade_requests tr
         JOIN users ru ON ru.id = tr.requester_id
         JOIN users ou ON ou.id = tr.owner_id
         JOIN listings tl ON tl.id = tr.target_listing_id
         LEFT JOIN categories tc ON tc.id = tl.category_id
         JOIN listings ol ON ol.id = tr.offered_listing_id
         LEFT JOIN categories oc ON oc.id = ol.category_id
         WHERE (tr.requester_id = ? OR tr.owner_id = ?)
           AND tr.status IN ($placeholders)
         ORDER BY tr.updated_at DESC";

    $stmt = mysqli_prepare($connection, $sql);
    $params = array_merge([$currentUserId, $currentUserId], $statuses);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $iAmRequester = (int) $row['requester_id'] === (int) $currentUserId;

        $rows[] = [
            'id'         => $row['id'],
            'name'       => authorInfo($iAmRequester ? $row['owner_email'] : $row['requester_email']),
            'give_icon'  => categoryIcon($iAmRequester ? $row['offered_category'] : $row['target_category']),
            'give'       => $iAmRequester ? $row['offered_title'] : $row['target_title'],
            'get_icon'   => categoryIcon($iAmRequester ? $row['target_category'] : $row['offered_category']),
            'get'        => $iAmRequester ? $row['target_title'] : $row['offered_title'],
            'status'     => $row['status'],
            'date'       => date('F j, Y', strtotime($row['updated_at'])),
            'updated_at' => $row['updated_at'],
        ];
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

$completed = fetchTradeHistory($connection, $currentUserId, ['accepted']);
$cancelled = fetchTradeHistory($connection, $currentUserId, ['rejected', 'cancelled']);

// ---------- Stats ----------
$completedCount = count($completed);
$cancelledCount = count($cancelled);

$thisMonthCount = 0;
$curMonth = date('n');
$curYear = date('Y');
foreach ($completed as $t) {
    if ((int) date('n', strtotime($t['updated_at'])) === (int) $curMonth
        && (int) date('Y', strtotime($t['updated_at'])) === (int) $curYear) {
        $thisMonthCount++;
    }
}

$totalResolved = $completedCount + $cancelledCount;
$successRate = $totalResolved > 0 ? round(($completedCount / $totalResolved) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade History — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:820px;">
            <div class="mb-4">
                <h2 class="mb-1">Trade history</h2>
                <p class="text-muted-swap mb-0">A timeline of everything you've traded.</p>
            </div>

            <!-- Stats -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3"><?php statCard('bi-check-circle', 'Completed', (string) $completedCount, null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-x-circle', 'Cancelled', (string) $cancelledCount, null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-calendar-week', 'This Month', (string) $thisMonthCount, null); ?></div>
                <div class="col-6 col-lg-3"><?php statCard('bi-graph-up', 'Success Rate', $successRate . '%', null); ?></div>
            </div>

            <ul class="nav trade-request-tabs mb-4">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabCompleted" type="button">Completed (<?= $completedCount ?>)</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCancelled" type="button">Cancelled (<?= $cancelledCount ?>)</button></li>
            </ul>

            <div class="tab-content">

                <!-- Completed timeline -->
                <div class="tab-pane fade show active" id="tabCompleted">
                    <?php if (empty($completed)): ?>
                        <p class="text-muted-swap text-center py-4">No completed trades yet.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($completed as $trade): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot completed"><i class="bi bi-check-lg"></i></div>
                                    <div class="timeline-date"><?= htmlspecialchars($trade['date']) ?></div>
                                    <div class="trade-history-card">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-semibold small">Traded with <?= htmlspecialchars($trade['name']) ?></span>
                                            <span class="status-badge accepted"><i class="bi bi-check-circle-fill"></i> Completed</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($trade['give_icon']) ?>"></i></div>
                                            <span class="small text-muted-swap"><?= htmlspecialchars($trade['give']) ?></span>
                                            <i class="bi bi-arrow-left-right text-muted-swap mx-1"></i>
                                            <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($trade['get_icon']) ?>"></i></div>
                                            <span class="small text-muted-swap"><?= htmlspecialchars($trade['get']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Cancelled timeline -->
                <div class="tab-pane fade" id="tabCancelled">
                    <?php if (empty($cancelled)): ?>
                        <p class="text-muted-swap text-center py-4">No cancelled trades.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($cancelled as $trade): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot cancelled"><i class="bi bi-x-lg"></i></div>
                                    <div class="timeline-date"><?= htmlspecialchars($trade['date']) ?></div>
                                    <div class="trade-history-card">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-semibold small">With <?= htmlspecialchars($trade['name']) ?></span>
                                            <span class="status-badge cancelled"><i class="bi bi-x-circle-fill"></i> <?= ucfirst($trade['status']) ?></span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($trade['give_icon']) ?>"></i></div>
                                            <span class="small text-muted-swap"><?= htmlspecialchars($trade['give']) ?></span>
                                            <i class="bi bi-arrow-left-right text-muted-swap mx-1"></i>
                                            <div class="swap-side-icon"><i class="bi <?= htmlspecialchars($trade['get_icon']) ?>"></i></div>
                                            <span class="small text-muted-swap"><?= htmlspecialchars($trade['get']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts(); ?>
</body>
</html>