<?php
/**
 * Swapify — Item Details
 * Shows the real listing matching ?id=, in the original design.
 * Owner rating + completed-trade count now pull real data from the
 * reviews / trade_requests tables.
 *
 * ADDED: report_listing action + Report button/modal, inserting into the
 * generic `reports` table (target_type='listing').
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/items_details_comment_helpers.php';

// ===================== HANDLE FAVORITE TOGGLE (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_favorite') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to save favorites.']);
        exit;
    }

    $itemId = (int) ($_POST['item_id'] ?? 0);
    $userId = $_SESSION['user_id'];

    if ($itemId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item.']);
        exit;
    }

    $checkStmt = mysqli_prepare($connection, "SELECT id FROM wishlists WHERE user_id = ? AND listing_id = ?");
    mysqli_stmt_bind_param($checkStmt, "ii", $userId, $itemId);
    mysqli_stmt_execute($checkStmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
    mysqli_stmt_close($checkStmt);

    if ($existing) {
        $delStmt = mysqli_prepare($connection, "DELETE FROM wishlists WHERE id = ?");
        mysqli_stmt_bind_param($delStmt, "i", $existing['id']);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
        echo json_encode(['success' => true, 'active' => false]);
    } else {
        $insStmt = mysqli_prepare($connection, "INSERT INTO wishlists (user_id, listing_id, created_at) VALUES (?, ?, NOW())");
        mysqli_stmt_bind_param($insStmt, "ii", $userId, $itemId);
        mysqli_stmt_execute($insStmt);
        mysqli_stmt_close($insStmt);
        echo json_encode(['success' => true, 'active' => true]);
    }
    exit;
}

// ===================== HANDLE REPORT LISTING (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_listing') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to report a listing.']);
        exit;
    }

    $reporterId  = $_SESSION['user_id'];
    $listingId   = (int) ($_POST['listing_id'] ?? 0);
    $reason      = trim($_POST['reason'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($listingId <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'Please choose a reason.']);
        exit;
    }

    $listStmt = mysqli_prepare($connection, "SELECT user_id FROM listings WHERE id = ?");
    mysqli_stmt_bind_param($listStmt, "i", $listingId);
    mysqli_stmt_execute($listStmt);
    $listingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($listStmt));
    mysqli_stmt_close($listStmt);

    if (!$listingRow) {
        echo json_encode(['success' => false, 'message' => 'This listing no longer exists.']);
        exit;
    }

    if ((int) $listingRow['user_id'] === (int) $reporterId) {
        echo json_encode(['success' => false, 'message' => "You can't report your own listing."]);
        exit;
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO reports (reporter_id, target_type, target_id, reason, description, status, created_at)
         VALUES (?, 'listing', ?, ?, ?, 'pending', NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiss", $reporterId, $listingId, $reason, $description);
    $ok = mysqli_stmt_execute($insStmt);
    mysqli_stmt_close($insStmt);

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Listing reported. Our team will review it.' : 'Something went wrong. Please try again.']);
    exit;
}

// ===================== HANDLE TRADE REQUEST CREATION (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_trade_request') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to propose a trade.']);
        exit;
    }

    $requesterId       = $_SESSION['user_id'];
    $targetListingId   = (int) ($_POST['target_listing_id'] ?? 0);
    $offeredListingId  = (int) ($_POST['offered_listing_id'] ?? 0);
    $message           = trim($_POST['message'] ?? '');

    if ($targetListingId <= 0 || $offeredListingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose an item to offer.']);
        exit;
    }

    $ownerStmt = mysqli_prepare($connection, "SELECT user_id FROM listings WHERE id = ? AND status = 'active'");
    mysqli_stmt_bind_param($ownerStmt, "i", $targetListingId);
    mysqli_stmt_execute($ownerStmt);
    $targetListing = mysqli_fetch_assoc(mysqli_stmt_get_result($ownerStmt));
    mysqli_stmt_close($ownerStmt);

    if (!$targetListing) {
        echo json_encode(['success' => false, 'message' => 'That listing no longer exists.']);
        exit;
    }

    $ownerId = $targetListing['user_id'];

    if ($ownerId == $requesterId) {
        echo json_encode(['success' => false, 'message' => "You can't propose a trade on your own listing."]);
        exit;
    }

    $offerCheckStmt = mysqli_prepare($connection, "SELECT id FROM listings WHERE id = ? AND user_id = ? AND status = 'active'");
    mysqli_stmt_bind_param($offerCheckStmt, "ii", $offeredListingId, $requesterId);
    mysqli_stmt_execute($offerCheckStmt);
    $offerValid = mysqli_fetch_assoc(mysqli_stmt_get_result($offerCheckStmt));
    mysqli_stmt_close($offerCheckStmt);

    if (!$offerValid) {
        echo json_encode(['success' => false, 'message' => 'Please choose one of your own active listings to offer.']);
        exit;
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO trade_requests
            (requester_id, owner_id, target_listing_id, offered_listing_id, message, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiiis", $requesterId, $ownerId, $targetListingId, $offeredListingId, $message);

    if (mysqli_stmt_execute($insStmt)) {
        echo json_encode(['success' => true, 'message' => 'Trade request sent!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    }
    mysqli_stmt_close($insStmt);
    exit;
}

// ===================== HANDLE REVIEW SUBMIT (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to leave a review.']);
        exit;
    }

    $reviewerId     = $_SESSION['user_id'];
    $tradeRequestId = (int) ($_POST['trade_request_id'] ?? 0);
    $rating         = (int) ($_POST['rating'] ?? 0);
    $comment        = trim($_POST['comment'] ?? '');

    if ($tradeRequestId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose which trade this review is for.']);
        exit;
    }
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Please select a star rating.']);
        exit;
    }
    if (mb_strlen($comment) > 400) {
        echo json_encode(['success' => false, 'message' => 'Review is too long (400 characters max).']);
        exit;
    }

    $tradeStmt = mysqli_prepare(
        $connection,
        "SELECT requester_id, owner_id FROM trade_requests WHERE id = ? AND status = 'accepted'"
    );
    mysqli_stmt_bind_param($tradeStmt, "i", $tradeRequestId);
    mysqli_stmt_execute($tradeStmt);
    $trade = mysqli_fetch_assoc(mysqli_stmt_get_result($tradeStmt));
    mysqli_stmt_close($tradeStmt);

    if (!$trade) {
        echo json_encode(['success' => false, 'message' => 'That trade could not be found.']);
        exit;
    }

    if ((int) $trade['requester_id'] === $reviewerId) {
        $revieweeId = (int) $trade['owner_id'];
    } elseif ((int) $trade['owner_id'] === $reviewerId) {
        $revieweeId = (int) $trade['requester_id'];
    } else {
        echo json_encode(['success' => false, 'message' => 'You were not part of this trade.']);
        exit;
    }

    $dupStmt = mysqli_prepare(
        $connection,
        "SELECT id FROM reviews WHERE trade_request_id = ? AND reviewer_id = ?"
    );
    mysqli_stmt_bind_param($dupStmt, "ii", $tradeRequestId, $reviewerId);
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
    mysqli_stmt_bind_param($insStmt, "iiiis", $tradeRequestId, $reviewerId, $revieweeId, $rating, $comment);

    if (mysqli_stmt_execute($insStmt)) {
        echo json_encode(['success' => true, 'message' => 'Review submitted — thank you!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    }
    mysqli_stmt_close($insStmt);
    exit;
}

// ===================== HANDLE COMMENT: ADD / REPLY (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_comment') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to comment.']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'];
    $listingId     = (int) ($_POST['listing_id'] ?? 0);
    $content       = trim($_POST['content'] ?? '');
    $parentId      = (isset($_POST['parent_id']) && $_POST['parent_id'] !== '') ? (int) $_POST['parent_id'] : null;

    if ($listingId <= 0 || $content === '' || mb_strlen($content) > 500) {
        echo json_encode(['success' => false, 'message' => 'Please enter a comment up to 500 characters.']);
        exit;
    }

    $listStmt = mysqli_prepare($connection, "SELECT user_id FROM listings WHERE id = ? AND status = 'active'");
    mysqli_stmt_bind_param($listStmt, "i", $listingId);
    mysqli_stmt_execute($listStmt);
    $listingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($listStmt));
    mysqli_stmt_close($listStmt);

    if (!$listingRow) {
        echo json_encode(['success' => false, 'message' => 'This listing no longer exists.']);
        exit;
    }
    $listingOwnerId = $listingRow['user_id'];

    if ($parentId) {
        $parentStmt = mysqli_prepare($connection, "SELECT id, parent_id FROM comments WHERE id = ? AND listing_id = ?");
        mysqli_stmt_bind_param($parentStmt, "ii", $parentId, $listingId);
        mysqli_stmt_execute($parentStmt);
        $parentRow = mysqli_fetch_assoc(mysqli_stmt_get_result($parentStmt));
        mysqli_stmt_close($parentStmt);

        if (!$parentRow || $parentRow['parent_id'] !== null) {
            echo json_encode(['success' => false, 'message' => 'Unable to reply to this comment.']);
            exit;
        }
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO comments (listing_id, user_id, parent_id, content, is_edited, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, NOW(), NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiis", $listingId, $currentUserId, $parentId, $content);

    if (!mysqli_stmt_execute($insStmt)) {
        mysqli_stmt_close($insStmt);
        echo json_encode(['success' => false, 'message' => 'Could not post your comment. Please try again.']);
        exit;
    }
    $newCommentId = mysqli_insert_id($connection);
    mysqli_stmt_close($insStmt);

    $rowStmt = mysqli_prepare(
        $connection,
        "SELECT c.id, c.parent_id, c.user_id, c.content, c.is_edited, c.created_at, u.email
         FROM comments c LEFT JOIN users u ON c.user_id = u.id
         WHERE c.id = ?"
    );
    mysqli_stmt_bind_param($rowStmt, "i", $newCommentId);
    mysqli_stmt_execute($rowStmt);
    $newRow = mysqli_fetch_assoc(mysqli_stmt_get_result($rowStmt));
    mysqli_stmt_close($rowStmt);

    $comment = buildCommentPayload($connection, $newRow, $currentUserId, $listingOwnerId);

    echo json_encode(['success' => true, 'comment' => $comment]);
    exit;
}

// ===================== HANDLE COMMENT: TOGGLE LIKE (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_comment_like') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to like comments.']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'];
    $commentId     = (int) ($_POST['comment_id'] ?? 0);

    if ($commentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid comment.']);
        exit;
    }

    $checkStmt = mysqli_prepare($connection, "SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($checkStmt, "ii", $commentId, $currentUserId);
    mysqli_stmt_execute($checkStmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
    mysqli_stmt_close($checkStmt);

    if ($existing) {
        $delStmt = mysqli_prepare($connection, "DELETE FROM comment_likes WHERE id = ?");
        mysqli_stmt_bind_param($delStmt, "i", $existing['id']);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
        $liked = false;
    } else {
        $insStmt = mysqli_prepare($connection, "INSERT INTO comment_likes (comment_id, user_id, created_at) VALUES (?, ?, NOW())");
        mysqli_stmt_bind_param($insStmt, "ii", $commentId, $currentUserId);
        mysqli_stmt_execute($insStmt);
        mysqli_stmt_close($insStmt);
        $liked = true;
    }

    $countStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM comment_likes WHERE comment_id = ?");
    mysqli_stmt_bind_param($countStmt, "i", $commentId);
    mysqli_stmt_execute($countStmt);
    $count = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['cnt'];
    mysqli_stmt_close($countStmt);

    echo json_encode(['success' => true, 'liked' => $liked, 'count' => $count]);
    exit;
}

// ===================== HANDLE COMMENT: DELETE OWN (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_comment') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'];
    $commentId     = (int) ($_POST['comment_id'] ?? 0);

    $ownStmt = mysqli_prepare($connection, "SELECT user_id FROM comments WHERE id = ?");
    mysqli_stmt_bind_param($ownStmt, "i", $commentId);
    mysqli_stmt_execute($ownStmt);
    $ownRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ownStmt));
    mysqli_stmt_close($ownStmt);

    if (!$ownRow) {
        echo json_encode(['success' => false, 'message' => 'Comment not found.']);
        exit;
    }
    if ((int) $ownRow['user_id'] !== (int) $currentUserId) {
        echo json_encode(['success' => false, 'message' => 'You can only delete your own comments.']);
        exit;
    }

    $idsToDelete = [$commentId];
    $replyStmt = mysqli_prepare($connection, "SELECT id FROM comments WHERE parent_id = ?");
    mysqli_stmt_bind_param($replyStmt, "i", $commentId);
    mysqli_stmt_execute($replyStmt);
    $replyResult = mysqli_stmt_get_result($replyStmt);
    while ($r = mysqli_fetch_assoc($replyResult)) {
        $idsToDelete[] = (int) $r['id'];
    }
    mysqli_stmt_close($replyStmt);

    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $types = str_repeat('i', count($idsToDelete));

    foreach (['comment_likes', 'comment_reports', 'comments'] as $table) {
        $col = ($table === 'comments') ? 'id' : 'comment_id';
        $delStmt = mysqli_prepare($connection, "DELETE FROM $table WHERE $col IN ($placeholders)");
        mysqli_stmt_bind_param($delStmt, $types, ...$idsToDelete);
        mysqli_stmt_execute($delStmt);
        mysqli_stmt_close($delStmt);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ===================== HANDLE COMMENT: REPORT (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_comment') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to report a comment.']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'];
    $commentId     = (int) ($_POST['comment_id'] ?? 0);
    $reason        = trim($_POST['reason'] ?? '');
    $details       = trim($_POST['details'] ?? '');

    if ($commentId <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'Please choose a reason.']);
        exit;
    }

    $existsStmt = mysqli_prepare($connection, "SELECT id FROM comments WHERE id = ?");
    mysqli_stmt_bind_param($existsStmt, "i", $commentId);
    mysqli_stmt_execute($existsStmt);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($existsStmt));
    mysqli_stmt_close($existsStmt);

    if (!$exists) {
        echo json_encode(['success' => false, 'message' => 'Comment not found.']);
        exit;
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO comment_reports (comment_id, user_id, reason, details, created_at) VALUES (?, ?, ?, ?, NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiss", $commentId, $currentUserId, $reason, $details);
    mysqli_stmt_execute($insStmt);
    mysqli_stmt_close($insStmt);

    echo json_encode(['success' => true, 'message' => 'Comment reported. Our team will review it.']);
    exit;
}

// ===================== HANDLE COMMENT: ADD / REPLY (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_comment') {

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to comment.']);
        exit;
    }

    $currentUserId = $_SESSION['user_id'];
    $listingId     = (int) ($_POST['listing_id'] ?? 0);
    $content       = trim($_POST['content'] ?? '');
    $parentId      = (isset($_POST['parent_id']) && $_POST['parent_id'] !== '') ? (int) $_POST['parent_id'] : null;

    if ($listingId <= 0 || $content === '' || mb_strlen($content) > 500) {
        echo json_encode(['success' => false, 'message' => 'Please enter a comment up to 500 characters.']);
        exit;
    }

    $listStmt = mysqli_prepare($connection, "SELECT user_id FROM listings WHERE id = ? AND status = 'active'");
    mysqli_stmt_bind_param($listStmt, "i", $listingId);
    mysqli_stmt_execute($listStmt);
    $listingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($listStmt));
    mysqli_stmt_close($listStmt);

    if (!$listingRow) {
        echo json_encode(['success' => false, 'message' => 'This listing no longer exists.']);
        exit;
    }

    $listingOwnerId = $listingRow['user_id'];

    if ($parentId) {
        $parentStmt = mysqli_prepare($connection, "SELECT id, parent_id FROM comments WHERE id = ? AND listing_id = ?");
        mysqli_stmt_bind_param($parentStmt, "ii", $parentId, $listingId);
        mysqli_stmt_execute($parentStmt);
        $parentRow = mysqli_fetch_assoc(mysqli_stmt_get_result($parentStmt));
        mysqli_stmt_close($parentStmt);

        if (!$parentRow || $parentRow['parent_id'] !== null) {
            echo json_encode(['success' => false, 'message' => 'Unable to reply to this comment.']);
            exit;
        }
    }

    $insStmt = mysqli_prepare(
        $connection,
        "INSERT INTO comments (listing_id, user_id, parent_id, content, is_edited, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, NOW(), NOW())"
    );
    mysqli_stmt_bind_param($insStmt, "iiis", $listingId, $currentUserId, $parentId, $content);

    if (!mysqli_stmt_execute($insStmt)) {
        mysqli_stmt_close($insStmt);
        echo json_encode(['success' => false, 'message' => 'Could not post your comment. Please try again.']);
        exit;
    }

    $newCommentId = mysqli_insert_id($connection);
    mysqli_stmt_close($insStmt);

    // ===================== CREATE COMMENT NOTIFICATION =====================

    $actorStmt = mysqli_prepare(
        $connection,
        "SELECT email FROM users WHERE id = ?"
    );
    mysqli_stmt_bind_param($actorStmt, "i", $currentUserId);
    mysqli_stmt_execute($actorStmt);
    $actorRow = mysqli_fetch_assoc(mysqli_stmt_get_result($actorStmt));
    mysqli_stmt_close($actorStmt);

    $actorName = 'Someone';

    if ($actorRow && !empty($actorRow['email'])) {
        $actorName = ucfirst(explode('@', $actorRow['email'])[0]);
    }

    // Normal comment → notify listing owner
    if ($parentId === null) {

        if ((int) $currentUserId !== (int) $listingOwnerId) {

            $notifyStmt = mysqli_prepare(
                $connection,
                "INSERT INTO notifications
                    (user_id, type, title, message, link, is_read, created_at)
                 VALUES (?, 'comment', 'New comment', ?, ?, 0, NOW())"
            );

            $message = $actorName . " commented on your listing.";
            $link = "/items_details.php?id=" . $listingId;

            mysqli_stmt_bind_param(
                $notifyStmt,
                "iss",
                $listingOwnerId,
                $message,
                $link
            );

            mysqli_stmt_execute($notifyStmt);
            mysqli_stmt_close($notifyStmt);
        }

    } else {

        // Reply → notify the author of the parent comment
        $parentAuthorStmt = mysqli_prepare(
            $connection,
            "SELECT user_id FROM comments WHERE id = ?"
        );
        mysqli_stmt_bind_param($parentAuthorStmt, "i", $parentId);
        mysqli_stmt_execute($parentAuthorStmt);
        $parentAuthorRow = mysqli_fetch_assoc(mysqli_stmt_get_result($parentAuthorStmt));
        mysqli_stmt_close($parentAuthorStmt);

        if (
            $parentAuthorRow &&
            (int) $parentAuthorRow['user_id'] !== (int) $currentUserId
        ) {

            $notifyUserId = (int) $parentAuthorRow['user_id'];

            $notifyStmt = mysqli_prepare(
                $connection,
                "INSERT INTO notifications
                    (user_id, type, title, message, link, is_read, created_at)
                 VALUES (?, 'comment_reply', 'New reply', ?, ?, 0, NOW())"
            );

            $message = $actorName . " replied to your comment.";
            $link = "/items_details.php?id=" . $listingId;

            mysqli_stmt_bind_param(
                $notifyStmt,
                "iss",
                $notifyUserId,
                $message,
                $link
            );

            mysqli_stmt_execute($notifyStmt);
            mysqli_stmt_close($notifyStmt);
        }
    }

    $rowStmt = mysqli_prepare(
        $connection,
        "SELECT c.id, c.parent_id, c.user_id, c.content, c.is_edited, c.created_at, u.email
         FROM comments c LEFT JOIN users u ON c.user_id = u.id
         WHERE c.id = ?"
    );
    mysqli_stmt_bind_param($rowStmt, "i", $newCommentId);
    mysqli_stmt_execute($rowStmt);
    $newRow = mysqli_fetch_assoc(mysqli_stmt_get_result($rowStmt));
    mysqli_stmt_close($rowStmt);

    $comment = buildCommentPayload($connection, $newRow, $currentUserId, $listingOwnerId);

    echo json_encode(['success' => true, 'comment' => $comment]);
    exit;
}

// ===================== RENDER PAGE (GET) =====================
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: marketplace.php');
    exit;
}

$stmt = mysqli_prepare(
    $connection,
    "SELECT
        l.id, l.user_id, l.title, l.description, l.looking_for,
        l.condition_type, l.location, l.views_count, l.created_at, l.status,
        c.name AS category_name, c.slug AS category_slug,
        u.email AS owner_email
     FROM listings l
     LEFT JOIN categories c ON l.category_id = c.id
     LEFT JOIN users u ON l.user_id = u.id
     WHERE l.id = ? AND l.status IN ('active', 'traded')"
);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$listing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$listing) {
    header('Location: marketplace.php');
    exit;
}

$isTraded = $listing['status'] === 'traded';

mysqli_query($connection, "UPDATE listings SET views_count = views_count + 1 WHERE id = " . (int) $id);

$imgStmt = mysqli_prepare(
    $connection,
    "SELECT image_path FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC"
);
mysqli_stmt_bind_param($imgStmt, "i", $id);
mysqli_stmt_execute($imgStmt);
$imgResult = mysqli_stmt_get_result($imgStmt);
$images = [];
while ($row = mysqli_fetch_assoc($imgResult)) {
    $images[] = $row['image_path'];
}
mysqli_stmt_close($imgStmt);

$related = [];
$relStmt = mysqli_prepare(
    $connection,
    "SELECT
        l.id, l.title, l.looking_for, l.condition_type, l.location,
        (SELECT image_path FROM listing_images
         WHERE listing_id = l.id ORDER BY sort_order ASC LIMIT 1) AS image
     FROM listings l
     WHERE l.category_id = (SELECT category_id FROM listings WHERE id = ?)
       AND l.id != ?
       AND l.status = 'active'
     ORDER BY l.created_at DESC
     LIMIT 4"
);
mysqli_stmt_bind_param($relStmt, "ii", $id, $id);
mysqli_stmt_execute($relStmt);
$relResult = mysqli_stmt_get_result($relStmt);
while ($row = mysqli_fetch_assoc($relResult)) {
    $related[] = [
        'id'    => $row['id'],
        'image' => $row['image'],
        'title' => $row['title'],
        'for'   => $row['looking_for'],
        'cond'  => $row['condition_type'],
        'loc'   => $row['location'],
    ];
}
mysqli_stmt_close($relStmt);

$isFavorited = false;
if (isset($_SESSION['user_id'])) {
    $favCheckStmt = mysqli_prepare($connection, "SELECT id FROM wishlists WHERE user_id = ? AND listing_id = ?");
    mysqli_stmt_bind_param($favCheckStmt, "ii", $_SESSION['user_id'], $id);
    mysqli_stmt_execute($favCheckStmt);
    $isFavorited = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($favCheckStmt));
    mysqli_stmt_close($favCheckStmt);
}

$myListings = [];
if (isset($_SESSION['user_id'])) {
    $myListStmt = mysqli_prepare(
        $connection,
        "SELECT id, title FROM listings WHERE user_id = ? AND status = 'active' AND id != ? ORDER BY created_at DESC"
    );
    mysqli_stmt_bind_param($myListStmt, "ii", $_SESSION['user_id'], $id);
    mysqli_stmt_execute($myListStmt);
    $myListResult = mysqli_stmt_get_result($myListStmt);
    while ($row = mysqli_fetch_assoc($myListResult)) {
        $myListings[] = $row;
    }
    mysqli_stmt_close($myListStmt);
}

$ownerName = 'Trader';
$ownerInitials = 'U';
if (!empty($listing['owner_email'])) {
    $localPart = explode('@', $listing['owner_email'])[0];
    $ownerName = ucfirst($localPart);
    $ownerInitials = strtoupper(substr($localPart, 0, 2));
}

$ownerRatingAvg = 0;
$ownerRatingCount = 0;
$ratingStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM reviews WHERE reviewee_id = ?");
mysqli_stmt_bind_param($ratingStmt, "i", $listing['user_id']);
mysqli_stmt_execute($ratingStmt);
$ratingRow = mysqli_fetch_assoc(mysqli_stmt_get_result($ratingStmt));
mysqli_stmt_close($ratingStmt);
if ($ratingRow && (int) $ratingRow['cnt'] > 0) {
    $ownerRatingCount = (int) $ratingRow['cnt'];
    $ownerRatingAvg = round((float) $ratingRow['avg_rating'], 1);
}

$ownerCompletedTrades = 0;
$tradeCountStmt = mysqli_prepare(
    $connection,
    "SELECT COUNT(*) AS cnt FROM trade_requests WHERE status = 'accepted' AND (requester_id = ? OR owner_id = ?)"
);
mysqli_stmt_bind_param($tradeCountStmt, "ii", $listing['user_id'], $listing['user_id']);
mysqli_stmt_execute($tradeCountStmt);
$ownerCompletedTrades = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($tradeCountStmt))['cnt'];
mysqli_stmt_close($tradeCountStmt);

$eligibleReviewTrades = [];
$canReview = false;
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $listing['user_id']) {
    $currentUserId = $_SESSION['user_id'];
    $ownerId = $listing['user_id'];

    $eligStmt = mysqli_prepare(
        $connection,
        "SELECT tr.id, l2.title
         FROM trade_requests tr
         LEFT JOIN listings l2 ON l2.id = tr.target_listing_id
         WHERE tr.status = 'accepted'
           AND ((tr.requester_id = ? AND tr.owner_id = ?) OR (tr.owner_id = ? AND tr.requester_id = ?))
           AND tr.id NOT IN (
               SELECT trade_request_id FROM reviews WHERE reviewer_id = ?
           )
         ORDER BY tr.updated_at DESC"
    );
    mysqli_stmt_bind_param(
        $eligStmt,
        "iiiii",
        $currentUserId,
        $ownerId,
        $currentUserId,
        $ownerId,
        $currentUserId
    );
    mysqli_stmt_execute($eligStmt);
    $eligResult = mysqli_stmt_get_result($eligStmt);
    while ($row = mysqli_fetch_assoc($eligResult)) {
        $eligibleReviewTrades[] = $row;
    }
    mysqli_stmt_close($eligStmt);

    $canReview = !empty($eligibleReviewTrades);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($listing['title']) ?> — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">

            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb small mb-0">
                    <li class="breadcrumb-item"><a href="<?= asset('marketplace.php') ?>" class="text-decoration-none">Marketplace</a></li>
                    <?php if (!empty($listing['category_name'])): ?>
                        <li class="breadcrumb-item"><a href="<?= asset('category.php') ?>?c=<?= urlencode($listing['category_slug']) ?>" class="text-decoration-none"><?= htmlspecialchars($listing['category_name']) ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($listing['title']) ?></li>
                </ol>
            </nav>

            <div class="row g-5">

                <div class="col-lg-6">
                    <div class="gallery-main" id="galleryMain">
                        <?php if (!empty($images)): ?>
                            <img src="<?= asset(htmlspecialchars($images[0])) ?>" alt="<?= htmlspecialchars($listing['title']) ?>" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <i class="bi bi-image"></i>
                        <?php endif; ?>
                    </div>
                    <div class="gallery-thumbs">
                        <?php if (!empty($images)): ?>
                            <?php foreach ($images as $i => $img): ?>
                                <div class="gallery-thumb <?= $i === 0 ? 'active' : '' ?>" data-image="<?= asset(htmlspecialchars($img)) ?>">
                                    <img src="<?= asset(htmlspecialchars($img)) ?>" alt="" style="width:100%; height:100%; object-fit:cover; border-radius:inherit;">
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="gallery-thumb active"><i class="bi bi-image"></i></div>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted-swap small mt-2"><i class="bi bi-zoom-in me-1"></i>Click the main image to zoom</p>
                </div>

                <div class="col-lg-6">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge-condition"><?= htmlspecialchars($listing['condition_type']) ?> condition</span>
                        <div class="d-flex gap-2">
                            <button class="icon-action-btn <?= $isFavorited ? 'active' : '' ?>" id="favoriteBtn" data-item-id="<?= $listing['id'] ?>" aria-label="Add to wishlist">
                                <i class="bi <?= $isFavorited ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                            </button>
                            <button class="icon-action-btn" id="shareBtn" aria-label="Share this listing">
                                <i class="bi bi-share"></i>
                            </button>
                            <button class="icon-action-btn" id="reportListingBtn" data-item-id="<?= $listing['id'] ?>" aria-label="Report this listing">
                                <i class="bi bi-flag"></i>
                            </button>
                        </div>
                    </div>

                    <h2 class="mb-2"><?= htmlspecialchars($listing['title']) ?></h2>

                    <?php if ($isTraded): ?>
                        <span class="badge bg-dark mb-3"><i class="bi bi-check-circle-fill me-1"></i>Traded</span>
                    <?php endif; ?>

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <?php if ($ownerRatingCount > 0): ?>
                            <a href="<?= asset('reviews.php') ?>?id=<?= $listing['user_id'] ?>" class="rating-stars text-decoration-none">
                                <?php
                                $fullStars = floor($ownerRatingAvg);
                                $hasHalf = ($ownerRatingAvg - $fullStars) >= 0.5;
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $fullStars) echo '<i class="bi bi-star-fill"></i>';
                                    elseif ($i === $fullStars + 1 && $hasHalf) echo '<i class="bi bi-star-half"></i>';
                                    else echo '<i class="bi bi-star"></i>';
                                }
                                ?>
                                <span class="text-ink small ms-1"><?= number_format($ownerRatingAvg, 1) ?> (<?= $ownerRatingCount ?> review<?= $ownerRatingCount === 1 ? '' : 's' ?>)</span>
                            </a>
                        <?php else: ?>
                            <a href="<?= asset('reviews.php') ?>?id=<?= $listing['user_id'] ?>" class="text-muted-swap small text-decoration-none">No reviews yet</a>
                        <?php endif; ?>
                        <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($listing['location']) ?></span>
                    </div>

                    <p class="text-muted-swap mb-4"><?= nl2br(htmlspecialchars($listing['description'])) ?></p>

                    <div class="dash-panel mb-4">
                        <div class="dash-panel-title">Looking for</div>
                        <p class="mb-0"><?= htmlspecialchars($listing['looking_for']) ?></p>
                    </div>

                    <ul class="item-meta-list mb-4">
                        <li><span class="label">Category</span><span class="value"><?= htmlspecialchars($listing['category_name'] ?? 'Uncategorized') ?></span></li>
                        <li><span class="label">Condition</span><span class="value"><?= htmlspecialchars($listing['condition_type']) ?></span></li>
                        <li><span class="label">Location</span><span class="value"><?= htmlspecialchars($listing['location']) ?></span></li>
                        <li><span class="label">Posted</span><span class="value"><?= timeAgo($listing['created_at']) ?></span></li>
                    </ul>

                    <div class="d-flex gap-2">
                        <?php if ($isTraded): ?>
                            <button class="btn btn-primary flex-grow-1" disabled>
                                <i class="bi bi-check-circle-fill me-1"></i> Already traded
                            </button>
                        <?php else: ?>
                            <button class="btn btn-primary flex-grow-1" data-bs-toggle="modal" data-bs-target="#tradeModal">
                                <i class="bi bi-arrow-left-right me-1"></i> Propose a trade
                            </button>
                        <?php endif; ?>
                        <a href="#" class="btn btn-light-swap"><i class="bi bi-chat-dots"></i></a>
                    </div>

                    <div class="owner-card mt-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="profile-card-avatar" style="width:52px;height:52px;font-size:1rem;"><?= htmlspecialchars($ownerInitials) ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="fw-semibold"><?= htmlspecialchars($ownerName) ?></span>
                                </div>
                                <div class="text-muted-swap small">
                                    <?= $ownerCompletedTrades ?> completed trade<?= $ownerCompletedTrades === 1 ? '' : 's' ?> &middot;
                                    <?php if ($ownerRatingCount > 0): ?>
                                        <a href="<?= asset('reviews.php') ?>?id=<?= $listing['user_id'] ?>" class="text-decoration-none text-muted-swap rating-stars">
                                            <i class="bi bi-star-fill"></i> <?= number_format($ownerRatingAvg, 1) ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= asset('reviews.php') ?>?id=<?= $listing['user_id'] ?>" class="text-decoration-none text-muted-swap">No reviews yet</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="<?= asset('profile.php') ?>?id=<?= $listing['user_id'] ?>" class="btn btn-light-swap btn-sm">View profile</a>
                        </div>
                        <?php if ($canReview): ?>
                            <div class="mt-3 pt-3 border-top">
                                <button class="btn btn-light-swap btn-sm w-100" data-bs-toggle="modal" data-bs-target="#writeReviewModal">
                                    <i class="bi bi-pencil-square me-1"></i> Write a review for <?= htmlspecialchars($ownerName) ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-4">
                <?php component('comments'); ?>
            </div>

            <div class="mt-5 pt-4">
                <h4 class="mb-4">Related items</h4>
                <div class="row g-4">
                    <?php if (!empty($related)): ?>
                        <?php foreach ($related as $item): ?>
                            <div class="col-md-6 col-lg-3"><?php tradeItemCard($item); ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12 text-center text-muted-swap py-4">No related items yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="modal fade" id="tradeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Propose a trade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Choose an item you'll offer</label>
                    <select class="form-select mb-3" id="tradeOfferSelect" style="border-radius:12px;">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <option value="">Log in to propose a trade</option>
                        <?php elseif (empty($myListings)): ?>
                            <option value="">You don't have any active listings to offer</option>
                        <?php else: ?>
                            <option value="">Select one of your listings</option>
                            <?php foreach ($myListings as $mine): ?>
                                <option value="<?= $mine['id'] ?>"><?= htmlspecialchars($mine['title']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <label class="form-label">Add a message (optional)</label>
                    <textarea class="form-control" id="tradeMessage" rows="3" style="border-radius:12px;" placeholder="Hi! I'd love to trade..."></textarea>
                    <input type="hidden" id="tradeTargetListingId" value="<?= $listing['id'] ?>">
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" id="sendTradeRequestBtn">Send trade request</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canReview): ?>
    <div class="modal fade" id="writeReviewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Write a review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Which trade is this for?</label>
                    <select class="form-select mb-3" id="reviewTradeSelect" style="border-radius:12px;">
                        <option value="">Select a completed trade</option>
                        <?php foreach ($eligibleReviewTrades as $trade): ?>
                            <option value="<?= $trade['id'] ?>"><?= htmlspecialchars($trade['title'] ?? ('Trade #' . $trade['id'])) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="form-label">Rating</label>
                    <div class="star-rating-input mb-3" id="starRatingInput" style="font-size:1.5rem; cursor:pointer;">
                        <i class="bi bi-star" data-value="1"></i>
                        <i class="bi bi-star" data-value="2"></i>
                        <i class="bi bi-star" data-value="3"></i>
                        <i class="bi bi-star" data-value="4"></i>
                        <i class="bi bi-star" data-value="5"></i>
                    </div>

                    <label class="form-label">Comment (optional)</label>
                    <textarea class="form-control" id="reviewText" rows="3" maxlength="400" style="border-radius:12px;" placeholder="How was the trade?"></textarea>
                    <div class="text-muted-swap small text-end mt-1" id="reviewCharCounter">0 / 400</div>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" id="submitReviewBtn">Submit review</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="reportListingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Report this listing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Reason</label>
                    <select class="form-select mb-3" id="reportListingReason" style="border-radius:12px;">
                        <option value="">Select a reason</option>
                        <option value="Spam">Spam</option>
                        <option value="Harassment">Harassment</option>
                        <option value="Fake item">Fake item</option>
                        <option value="Scam">Scam</option>
                    </select>
                    <label class="form-label">Additional details (optional)</label>
                    <textarea class="form-control" id="reportListingDescription" rows="3" maxlength="500" style="border-radius:12px;" placeholder="Anything else we should know?"></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" id="submitReportListingBtn">Submit report</button>
                </div>
            </div>
        </div>
    </div>

    <?php component('footer'); ?>

    <?php loadScripts('items_details'); ?>
    <script src="<?= asset('assets/js/comments.js') ?>"></script>
    <?php if ($canReview): ?>
    <script src="<?= asset('assets/js/reviews.js') ?>"></script>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      const reportBtn = document.getElementById('reportListingBtn');
      if (!reportBtn) return;

      const modal = new bootstrap.Modal(document.getElementById('reportListingModal'));
      const reasonSelect = document.getElementById('reportListingReason');
      const descInput = document.getElementById('reportListingDescription');
      const submitBtn = document.getElementById('submitReportListingBtn');

      reportBtn.addEventListener('click', function () {
        reasonSelect.value = '';
        descInput.value = '';
        reasonSelect.classList.remove('is-invalid');
        modal.show();
      });

      submitBtn.addEventListener('click', function () {
        const reason = reasonSelect.value;
        if (!reason) {
          reasonSelect.classList.add('is-invalid');
          return;
        }
        reasonSelect.classList.remove('is-invalid');

        const formData = new FormData();
        formData.append('action', 'report_listing');
        formData.append('listing_id', reportBtn.dataset.itemId);
        formData.append('reason', reason);
        formData.append('description', descInput.value.trim());

        fetch('items_details.php', { method: 'POST', body: formData })
          .then((res) => res.json())
          .then((data) => {
            modal.hide();
            swapifyToast(data.message, data.success ? 'success' : 'danger');
          })
          .catch(() => {
            modal.hide();
            swapifyToast('Something went wrong. Please try again.', 'danger');
          });
      });
    });
    </script>
</body>
</html>