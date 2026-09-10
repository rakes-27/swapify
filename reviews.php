<?php
/**
 * Swapify — Reviews
 * Shows real reviews for a trader (?id=<user_id>), pulled from the
 * `reviews` table. A review always belongs to a specific completed
 * (accepted) trade_request between the reviewer and the reviewee — so
 * "Write a review" only appears if the logged-in user has at least one
 * completed trade with this person that they haven't reviewed yet.
 * Submitting POSTs FormData back to this same page (action=submit_review)
 * — no separate API endpoint.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

$currentUserId = $_SESSION['user_id'] ?? 0;

// ---------- "3 days ago" style relative time ----------
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minute' . (floor($diff / 60) == 1 ? '' : 's') . ' ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
    $days = floor($diff / 86400);
    if ($days < 30) return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    if ($days < 365) return floor($days / 30) . ' month' . (floor($days / 30) == 1 ? '' : 's') . ' ago';
    return date('M j, Y', strtotime($datetime));
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

// ===================== HANDLE REVIEW SUBMISSION (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {

    header('Content-Type: application/json');

    if (!$currentUserId) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to leave a review.']);
        exit;
    }

    $tradeRequestId = (int) ($_POST['trade_request_id'] ?? 0);
    $rating         = (int) ($_POST['rating'] ?? 0);
    $comment        = trim($_POST['comment'] ?? '');

    if ($tradeRequestId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose which trade you\'re reviewing.']);
        exit;
    }
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Please select a star rating.']);
        exit;
    }
    if (mb_strlen($comment) > 400) {
        echo json_encode(['success' => false, 'message' => 'Review is too long (max 400 characters).']);
        exit;
    }

    // Confirm this trade is accepted, involves the current user, and find the other party
    $tradeStmt = mysqli_prepare($connection, "SELECT requester_id, owner_id, status FROM trade_requests WHERE id = ?");
    mysqli_stmt_bind_param($tradeStmt, "i", $tradeRequestId);
    mysqli_stmt_execute($tradeStmt);
    $trade = mysqli_fetch_assoc(mysqli_stmt_get_result($tradeStmt));
    mysqli_stmt_close($tradeStmt);

    if (!$trade || $trade['status'] !== 'accepted') {
        echo json_encode(['success' => false, 'message' => 'That trade is not eligible for a review.']);
        exit;
    }

    if ((int) $trade['requester_id'] === (int) $currentUserId) {
        $revieweeId = (int) $trade['owner_id'];
    } elseif ((int) $trade['owner_id'] === (int) $currentUserId) {
        $revieweeId = (int) $trade['requester_id'];
    } else {
        echo json_encode(['success' => false, 'message' => "You weren't part of this trade."]);
        exit;
    }

    // Prevent duplicate reviews for the same trade by the same reviewer
    $dupStmt = mysqli_prepare($connection, "SELECT id FROM reviews WHERE trade_request_id = ? AND reviewer_id = ?");
    mysqli_stmt_bind_param($dupStmt, "ii", $tradeRequestId, $currentUserId);
    mysqli_stmt_execute($dupStmt);
    $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
    mysqli_stmt_close($dupStmt);

    if ($dup) {
        echo json_encode(['success' => false, 'message' => 'You already reviewed this trade.']);
        exit;
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO reviews (trade_request_id, reviewer_id, reviewee_id, rating, comment, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiiis", $tradeRequestId, $currentUserId, $revieweeId, $rating, $comment);

    if (mysqli_stmt_execute($insStmt)) {
        echo json_encode(['success' => true, 'message' => 'Review submitted — thank you!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    }
    mysqli_stmt_close($insStmt);
    exit;
}

// ===================== RENDER PAGE (GET) =====================

$revieweeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($revieweeId <= 0) {
    if ($currentUserId) {
        // No ?id= given — default to viewing your own reviews instead of bouncing away
        $revieweeId = $currentUserId;
    }
    // else: no id and not logged in — fall through with $revieweeId = 0.
    // Handled below by rendering an inline "no trader selected" state instead of redirecting.
}

// ---------- Who is this? ----------
$revieweeRow = null;
if ($revieweeId > 0) {
    $revieweeStmt = mysqli_prepare($connection, "SELECT id, email FROM users WHERE id = ?");
    mysqli_stmt_bind_param($revieweeStmt, "i", $revieweeId);
    mysqli_stmt_execute($revieweeStmt);
    $revieweeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($revieweeStmt));
    mysqli_stmt_close($revieweeStmt);
}

$traderNotFound = ($revieweeId > 0 && !$revieweeRow);
$noTraderSelected = ($revieweeId <= 0);
$revieweeInfo = $revieweeRow ? authorInfo($revieweeRow['email']) : ['name' => 'this trader', 'initials' => '?'];

// ---------- Fetch this trader's reviews (only if we have a real trader) ----------
$reviews = [];
$ratingSum = 0;
$distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

if ($revieweeRow) {
    $revStmt = mysqli_prepare(
        $connection,
        "SELECT r.rating, r.comment, r.created_at, u.email AS reviewer_email
         FROM reviews r
         JOIN users u ON u.id = r.reviewer_id
         WHERE r.reviewee_id = ?
         ORDER BY r.created_at DESC"
    );
    mysqli_stmt_bind_param($revStmt, "i", $revieweeId);
    mysqli_stmt_execute($revStmt);
    $revResult = mysqli_stmt_get_result($revStmt);
    while ($row = mysqli_fetch_assoc($revResult)) {
        $author = authorInfo($row['reviewer_email']);
        $reviews[] = [
            'initials' => $author['initials'],
            'name'     => $author['name'],
            'date'     => timeAgo($row['created_at']),
            'stars'    => (int) $row['rating'],
            'text'     => $row['comment'],
        ];
        $ratingSum += (int) $row['rating'];
        if (isset($distribution[(int) $row['rating']])) {
            $distribution[(int) $row['rating']]++;
        }
    }
    mysqli_stmt_close($revStmt);
}

$totalReviews = count($reviews);
$averageRating = $totalReviews > 0 ? round($ratingSum / $totalReviews, 1) : 0;

// Convert distribution counts to percentages for the bar widths
$distributionPct = [];
foreach ($distribution as $star => $count) {
    $distributionPct[$star] = $totalReviews > 0 ? round(($count / $totalReviews) * 100) : 0;
}

// ---------- Eligible trades: accepted trades between current user and this trader,
//            that the current user hasn't already reviewed ----------
$eligibleTrades = [];
if ($revieweeRow && $currentUserId && $currentUserId !== $revieweeId) {
    $eligStmt = mysqli_prepare(
        $connection,
        "SELECT tr.id, tl.title AS target_title, ol.title AS offered_title
         FROM trade_requests tr
         JOIN listings tl ON tl.id = tr.target_listing_id
         JOIN listings ol ON ol.id = tr.offered_listing_id
         WHERE tr.status = 'accepted'
           AND (
                (tr.requester_id = ? AND tr.owner_id = ?)
             OR (tr.owner_id = ? AND tr.requester_id = ?)
           )
           AND tr.id NOT IN (SELECT trade_request_id FROM reviews WHERE reviewer_id = ?)
         ORDER BY tr.updated_at DESC"
    );
    mysqli_stmt_bind_param($eligStmt, "iiiii", $currentUserId, $revieweeId, $currentUserId, $revieweeId, $currentUserId);
    mysqli_stmt_execute($eligStmt);
    $eligResult = mysqli_stmt_get_result($eligStmt);
    while ($row = mysqli_fetch_assoc($eligResult)) {
        $eligibleTrades[] = [
            'id'    => $row['id'],
            'label' => htmlspecialchars($row['offered_title']) . ' ↔ ' . htmlspecialchars($row['target_title']),
        ];
    }
    mysqli_stmt_close($eligStmt);
}
$canReview = !empty($eligibleTrades);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:900px;">
            <?php if ($noTraderSelected): ?>
                <div class="alert alert-light border text-center text-muted-swap mb-4">
                    No trader selected. Visit a listing or profile and follow its "Reviews" link to see reviews here.
                </div>
            <?php elseif ($traderNotFound): ?>
                <div class="alert alert-light border text-center text-muted-swap mb-4">
                    We couldn't find that trader — they may have deleted their account.
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">Reviews</h2>
                    <p class="text-muted-swap mb-0">What other traders say about <?= htmlspecialchars($revieweeInfo['name']) ?>.</p>
                </div>
                <?php if ($canReview): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#writeReviewModal">
                        <i class="bi bi-pencil me-1"></i> Write a review
                    </button>
                <?php elseif ($currentUserId && $currentUserId !== $revieweeId): ?>
                    <button class="btn btn-light-swap" disabled title="Complete a trade with this trader first">
                        <i class="bi bi-pencil me-1"></i> Write a review
                    </button>
                <?php endif; ?>
            </div>

            <!-- Rating summary -->
            <div class="rating-summary-card mb-4">
                <div class="row align-items-center g-4">
                    <div class="col-md-4 text-center text-md-start">
                        <div class="rating-summary-score"><?= number_format($averageRating, 1) ?></div>
                        <div class="rating-stars mb-1">
                            <?php
                            $fullStars = floor($averageRating);
                            $hasHalf = ($averageRating - $fullStars) >= 0.5;
                            for ($i = 1; $i <= 5; $i++) {
                                if ($i <= $fullStars) echo '<i class="bi bi-star-fill"></i>';
                                elseif ($i === $fullStars + 1 && $hasHalf) echo '<i class="bi bi-star-half"></i>';
                                else echo '<i class="bi bi-star"></i>';
                            }
                            ?>
                        </div>
                        <div class="text-muted-swap small">Based on <?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?></div>
                    </div>
                    <div class="col-md-8">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <div class="rating-dist-row">
                                <span class="label"><?= $star ?> star</span>
                                <div class="rating-dist-bar"><div class="rating-dist-fill" style="width:<?= $distributionPct[$star] ?>%;"></div></div>
                                <span class="count"><?= $distributionPct[$star] ?>%</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Review cards -->
            <div class="row g-3">
                <?php if (empty($reviews)): ?>
                    <div class="col-12 text-center text-muted-swap py-5">No reviews yet.</div>
                <?php else: ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="col-md-6"><?php reviewCard($review); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ============================== WRITE REVIEW MODAL ============================== -->
    <?php if ($canReview): ?>
    <div class="modal fade" id="writeReviewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Write a review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (count($eligibleTrades) > 1): ?>
                        <label class="form-label form-label-swap">Which trade is this about?</label>
                        <select class="form-select mb-3" id="reviewTradeSelect" style="border-radius:12px;">
                            <?php foreach ($eligibleTrades as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= $t['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" id="reviewTradeSelect" value="<?= $eligibleTrades[0]['id'] ?>">
                    <?php endif; ?>

                    <label class="form-label form-label-swap d-block mb-2">Your rating</label>
                    <div class="star-rating-input mb-3" id="starRatingInput">
                        <i class="bi bi-star" data-value="1"></i>
                        <i class="bi bi-star" data-value="2"></i>
                        <i class="bi bi-star" data-value="3"></i>
                        <i class="bi bi-star" data-value="4"></i>
                        <i class="bi bi-star" data-value="5"></i>
                    </div>

                    <label for="reviewText" class="form-label form-label-swap">Your review</label>
                    <textarea class="form-control" id="reviewText" rows="4" maxlength="400" style="border-radius:12px;"
                              placeholder="How was your trading experience?"></textarea>
                    <div class="text-end mt-1">
                        <span class="comment-char-counter" id="reviewCharCounter">0 / 400</span>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" id="submitReviewBtn">Submit review</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php component('footer'); ?>

    <?php loadScripts('reviews'); ?>
</body>
</html>