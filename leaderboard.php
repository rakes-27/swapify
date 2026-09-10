<?php
/**
 * Swapify — Leaderboard
 * Ranking = completed (accepted) trade_requests count within the selected
 * period, tiebreak by live average rating from reviews. Rank-change arrows
 * compare today's computed rank to the most recent prior day's snapshot
 * (leaderboard_snapshot table), same lazy-daily pattern as trending.php.
 * No design/markup changes — only data sourcing.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

$currentUserId = $_SESSION['user_id'] ?? 0;
$today = date('Y-m-d');

// ===================== Period selection =====================
$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['week', 'month', 'all'], true)) {
    $period = 'month';
}

switch ($period) {
    case 'week':
        $periodStart = date('Y-m-d H:i:s', strtotime('-7 days'));
        break;
    case 'all':
        $periodStart = '2000-01-01 00:00:00'; // effectively no lower bound
        break;
    default: // month
        $periodStart = date('Y-m-d H:i:s', strtotime('-30 days'));
}

// ===================== Pull every user with trades_count (period-filtered) + live rating =====================
$usersStmt = mysqli_prepare(
    $connection,
    "SELECT u.id, u.name, u.email, u.is_trusted,
            (SELECT COUNT(*) FROM trade_requests tr
             WHERE tr.status = 'accepted'
               AND (tr.requester_id = u.id OR tr.owner_id = u.id)
               AND tr.updated_at >= ?) AS trades_count,
            (SELECT AVG(r.rating) FROM reviews r WHERE r.reviewee_id = u.id) AS rating_avg,
            (SELECT COUNT(*) FROM reviews r WHERE r.reviewee_id = u.id) AS rating_count
     FROM users u
     WHERE u.status = 'active'"
);
mysqli_stmt_bind_param($usersStmt, "s", $periodStart);
mysqli_stmt_execute($usersStmt);
$usersResult = mysqli_stmt_get_result($usersStmt);

$allUsers = [];
while ($row = mysqli_fetch_assoc($usersResult)) {
    $displayName = $row['name'] ?: ucfirst(explode('@', $row['email'])[0]);
    $initials = strtoupper(substr($displayName, 0, 1) . (strpos($displayName, ' ') !== false ? substr(strstr($displayName, ' '), 1, 1) : substr($displayName, 1, 1)));

    $allUsers[] = [
        'id'       => (int) $row['id'],
        'name'     => $displayName,
        'initials' => $initials,
        'trades'   => (int) $row['trades_count'],
        'rating'   => $row['rating_count'] > 0 ? round((float) $row['rating_avg'], 1) : 0,
        'trusted'  => (bool) $row['is_trusted'],
    ];
}
mysqli_stmt_close($usersStmt);

// ===================== Rank in PHP (works on any MySQL version) =====================
usort($allUsers, function ($a, $b) {
    if ($a['trades'] !== $b['trades']) return $b['trades'] <=> $a['trades'];
    return $b['rating'] <=> $a['rating'];
});

foreach ($allUsers as $i => &$u) {
    $u['rank'] = $i + 1;
}
unset($u);

// Only users with at least 1 trade appear on the visible leaderboard list.
$rankedUsers = array_values(array_filter($allUsers, fn($u) => $u['trades'] > 0));

// ===================== Ensure today's snapshot exists for this period (lazy daily refresh) =====================
$snapCheckStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM leaderboard_snapshot WHERE period = ? AND snapshot_date = ?");
mysqli_stmt_bind_param($snapCheckStmt, "ss", $period, $today);
mysqli_stmt_execute($snapCheckStmt);
$snapExists = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($snapCheckStmt))['cnt'] > 0;
mysqli_stmt_close($snapCheckStmt);

if (!$snapExists && !empty($rankedUsers)) {
    $insSnapStmt = mysqli_prepare(
        $connection,
        "INSERT INTO leaderboard_snapshot (user_id, period, rnk, trades_count, snapshot_date) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($rankedUsers as $u) {
        mysqli_stmt_bind_param($insSnapStmt, "isiis", $u['id'], $period, $u['rank'], $u['trades'], $today);
        mysqli_stmt_execute($insSnapStmt);
    }
    mysqli_stmt_close($insSnapStmt);
}

// ===================== Pull most recent PRIOR snapshot for this period, to compute rank-change =====================
$priorDateStmt = mysqli_prepare(
    $connection,
    "SELECT MAX(snapshot_date) AS prior_date FROM leaderboard_snapshot WHERE period = ? AND snapshot_date < ?"
);
mysqli_stmt_bind_param($priorDateStmt, "ss", $period, $today);
mysqli_stmt_execute($priorDateStmt);
$priorDate = mysqli_fetch_assoc(mysqli_stmt_get_result($priorDateStmt))['prior_date'] ?? null;
mysqli_stmt_close($priorDateStmt);

$priorRanks = []; // user_id => rank
if ($priorDate) {
    $priorStmt = mysqli_prepare($connection, "SELECT user_id, rnk FROM leaderboard_snapshot WHERE period = ? AND snapshot_date = ?");
    mysqli_stmt_bind_param($priorStmt, "ss", $period, $priorDate);
    mysqli_stmt_execute($priorStmt);
    $priorResult = mysqli_stmt_get_result($priorStmt);
    while ($row = mysqli_fetch_assoc($priorResult)) {
        $priorRanks[(int) $row['user_id']] = (int) $row['rnk'];
    }
    mysqli_stmt_close($priorStmt);
}

function computeChange($currentRank, $userId, $priorRanks) {
    if (!isset($priorRanks[$userId])) {
        return ['change' => 'same', 'delta' => 0];
    }
    $priorRank = $priorRanks[$userId];
    if ($currentRank < $priorRank) {
        return ['change' => 'up', 'delta' => $priorRank - $currentRank];
    }
    if ($currentRank > $priorRank) {
        return ['change' => 'down', 'delta' => $currentRank - $priorRank];
    }
    return ['change' => 'same', 'delta' => 0];
}

// ===================== Split into top3 / rest / yourRank, matching original structure =====================
$top3 = array_slice($rankedUsers, 0, 3);
$rest = array_slice($rankedUsers, 3, 5);
foreach ($rest as &$r) {
    $c = computeChange($r['rank'], $r['id'], $priorRanks);
    $r['change'] = $c['change'];
    $r['delta'] = $c['delta'];
}
unset($r);

$yourRank = null;
if ($currentUserId) {
    foreach ($allUsers as $u) {
        if ($u['id'] === $currentUserId) {
            $c = computeChange($u['rank'], $u['id'], $priorRanks);
            $yourRank = $u;
            $yourRank['change'] = $c['change'];
            $yourRank['delta'] = $c['delta'];
            break;
        }
    }
}

// ===================== Overall stat cards (always "this month", independent of period pill) =====================
$activeTradersStmt = mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM users WHERE status = 'active'");
$activeTradersCount = (int) mysqli_fetch_assoc($activeTradersStmt)['cnt'];

$monthStart = date('Y-m-d H:i:s', strtotime('-30 days'));
$tradesThisMonthStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted' AND updated_at >= ?");
mysqli_stmt_bind_param($tradesThisMonthStmt, "s", $monthStart);
mysqli_stmt_execute($tradesThisMonthStmt);
$tradesThisMonthCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($tradesThisMonthStmt))['cnt'];
mysqli_stmt_close($tradesThisMonthStmt);

$trustedTradersStmt = mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM users WHERE is_trusted = 1");
$trustedTradersCount = (int) mysqli_fetch_assoc($trustedTradersStmt)['cnt'];

function formatCount($n) {
    return $n >= 1000 ? number_format($n / 1000, 1) . 'k+' : (string) $n;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:820px;">

            <!-- Banner -->
            <div class="leaderboard-banner mb-0">
                <div class="leaderboard-banner-icon"><i class="bi bi-trophy-fill"></i></div>
                <h2 class="text-white mb-1">Top traders</h2>
                <p class="mb-0" style="opacity:0.85;">Ranked by completed trades and trader rating.</p>
                <div class="leaderboard-period-pills">
                    <a href="<?= asset('leaderboard.php') ?>?period=week" class="<?= $period === 'week' ? 'active' : '' ?>">This Week</a>
                    <a href="<?= asset('leaderboard.php') ?>?period=month" class="<?= $period === 'month' ? 'active' : '' ?>">This Month</a>
                    <a href="<?= asset('leaderboard.php') ?>?period=all" class="<?= $period === 'all' ? 'active' : '' ?>">All Time</a>
                </div>
            </div>

            <?php if (empty($top3)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-trophy"></i></div>
                    <h5>No completed trades yet<?= $period !== 'all' ? ' this period' : '' ?></h5>
                    <p>Once trades are completed, the top traders will show up here.</p>
                    <a href="<?= asset('marketplace.php') ?>" class="btn btn-primary btn-sm">Browse marketplace</a>
                </div>
            <?php else: ?>

                <!-- Podium: true heights -->
                <div class="podium-track">
                    <?php if (isset($top3[1])): ?>
                    <div class="podium-col second">
                        <div class="podium-card rank-2">
                            <div class="podium-avatar-ring"><div class="podium-avatar"><?= htmlspecialchars($top3[1]['initials']) ?></div></div>
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <span class="fw-semibold small"><?= htmlspecialchars($top3[1]['name']) ?></span>
                                <?php if ($top3[1]['trusted']): ?><i class="bi bi-patch-check-fill text-primary small"></i><?php endif; ?>
                            </div>
                            <div class="text-muted-swap small"><?= $top3[1]['trades'] ?> trades</div>
                            <div class="rating-stars small"><i class="bi bi-star-fill"></i> <?= number_format($top3[1]['rating'], 1) ?></div>
                        </div>
                        <div class="podium-base"><div class="podium-base-rank">2</div></div>
                    </div>
                    <?php endif; ?>

                    <div class="podium-col first">
                        <div class="podium-crown"><i class="bi bi-crown-fill"></i></div>
                        <div class="podium-card rank-1">
                            <div class="podium-avatar-ring"><div class="podium-avatar" style="font-size:1.4rem;"><?= htmlspecialchars($top3[0]['initials']) ?></div></div>
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <span class="fw-semibold"><?= htmlspecialchars($top3[0]['name']) ?></span>
                                <?php if ($top3[0]['trusted']): ?><i class="bi bi-patch-check-fill text-primary"></i><?php endif; ?>
                            </div>
                            <div class="text-muted-swap small"><?= $top3[0]['trades'] ?> trades</div>
                            <div class="rating-stars small"><i class="bi bi-star-fill"></i> <?= number_format($top3[0]['rating'], 1) ?></div>
                        </div>
                        <div class="podium-base"><div class="podium-base-rank">1</div></div>
                    </div>

                    <?php if (isset($top3[2])): ?>
                    <div class="podium-col third">
                        <div class="podium-card rank-3">
                            <div class="podium-avatar-ring"><div class="podium-avatar"><?= htmlspecialchars($top3[2]['initials']) ?></div></div>
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <span class="fw-semibold small"><?= htmlspecialchars($top3[2]['name']) ?></span>
                                <?php if ($top3[2]['trusted']): ?><i class="bi bi-patch-check-fill text-primary small"></i><?php endif; ?>
                            </div>
                            <div class="text-muted-swap small"><?= $top3[2]['trades'] ?> trades</div>
                            <div class="rating-stars small"><i class="bi bi-star-fill"></i> <?= number_format($top3[2]['rating'], 1) ?></div>
                        </div>
                        <div class="podium-base"><div class="podium-base-rank">3</div></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Overall stats -->
                <div class="row g-3 mb-4">
                    <div class="col-4"><?php statCard('bi-people', 'Active Traders', formatCount($activeTradersCount), null); ?></div>
                    <div class="col-4"><?php statCard('bi-arrow-repeat', 'Trades This Month', number_format($tradesThisMonthCount), null); ?></div>
                    <div class="col-4"><?php statCard('bi-patch-check', 'Trusted Traders', formatCount($trustedTradersCount), null); ?></div>
                </div>

                <!-- Ranked list: 4 onward -->
                <div>
                    <?php foreach ($rest as $trader): ?>
                        <div class="leaderboard-row">
                            <div class="leaderboard-rank">#<?= $trader['rank'] ?></div>
                            <div class="profile-card-avatar"><?= htmlspecialchars($trader['initials']) ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="fw-semibold small"><?= htmlspecialchars($trader['name']) ?></span>
                                    <?php if ($trader['trusted']): ?><i class="bi bi-patch-check-fill text-primary small" title="Trusted Trader"></i><?php endif; ?>
                                </div>
                                <div class="text-muted-swap small"><?= $trader['trades'] ?> completed trades</div>
                            </div>
                            <?php if ($trader['change'] === 'up'): ?>
                                <span class="rank-change up"><i class="bi bi-caret-up-fill"></i> <?= $trader['delta'] ?></span>
                            <?php elseif ($trader['change'] === 'down'): ?>
                                <span class="rank-change down"><i class="bi bi-caret-down-fill"></i> <?= $trader['delta'] ?></span>
                            <?php else: ?>
                                <span class="rank-change same"><i class="bi bi-dash"></i></span>
                            <?php endif; ?>
                            <div class="rating-stars small ms-3"><i class="bi bi-star-fill"></i> <?= number_format($trader['rating'], 1) ?></div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Your rank -->
                    <?php if ($yourRank): ?>
                        <div class="leaderboard-row your-row mt-3">
                            <div class="leaderboard-rank">#<?= $yourRank['rank'] ?></div>
                            <div class="profile-card-avatar"><?= htmlspecialchars($yourRank['initials']) ?></div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">You</div>
                                <div class="text-muted-swap small"><?= $yourRank['trades'] ?> completed trades</div>
                            </div>
                            <?php if ($yourRank['change'] === 'up'): ?>
                                <span class="rank-change up"><i class="bi bi-caret-up-fill"></i> <?= $yourRank['delta'] ?></span>
                            <?php elseif ($yourRank['change'] === 'down'): ?>
                                <span class="rank-change down"><i class="bi bi-caret-down-fill"></i> <?= $yourRank['delta'] ?></span>
                            <?php else: ?>
                                <span class="rank-change same"><i class="bi bi-dash"></i></span>
                            <?php endif; ?>
                            <div class="rating-stars small ms-3"><i class="bi bi-star-fill"></i> <?= number_format($yourRank['rating'], 1) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts(); ?>
</body>
</html>