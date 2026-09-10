<?php
/**
 * Swapify — Comment helpers
 * Shared by items_details.php (POST handlers) and components/comments.php
 * (server-rendered first page). Requires $connection (mysqli) to exist
 * wherever these are called.
 *
 * comments table:        id, listing_id, user_id, parent_id, content, is_edited, created_at, updated_at
 * comment_likes table:   id, comment_id, user_id, created_at
 * comment_reports table: id, comment_id, user_id, reason, details, created_at
 */

const COMMENTS_PAGE_SIZE = 5;

/**
 * "3 days ago" style relative time. Shared with the rest of items_details.php.
 */
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minute' . (floor($diff / 60) == 1 ? '' : 's') . ' ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
    $days = floor($diff / 86400);
    if ($days < 30) return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    return date('M j, Y', strtotime($datetime));
}

/**
 * Turns a user's email into display name / initials / a mention-friendly username.
 * (Swapify doesn't have a display_name column yet, so this mirrors the pattern
 * already used for the listing owner in items_details.php.)
 */
function commentAuthorInfo($email) {
    $local = $email ? explode('@', $email)[0] : 'user';
    return [
        'name'     => ucfirst($local),
        'initials' => strtoupper(substr($local, 0, 2)),
        'username' => $local,
    ];
}

/**
 * Builds the JSON-ready shape comments.js / comments.php expect for one comment row.
 * $row needs: id, parent_id, user_id, content, is_edited, created_at, email
 */
function buildCommentPayload($connection, $row, $currentUserId, $listingOwnerId) {
    $author = commentAuthorInfo($row['email'] ?? '');

    $likeCountStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM comment_likes WHERE comment_id = ?");
    mysqli_stmt_bind_param($likeCountStmt, "i", $row['id']);
    mysqli_stmt_execute($likeCountStmt);
    $likeCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($likeCountStmt))['cnt'];
    mysqli_stmt_close($likeCountStmt);

    $liked = false;
    if ($currentUserId) {
        $likedStmt = mysqli_prepare($connection, "SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($likedStmt, "ii", $row['id'], $currentUserId);
        mysqli_stmt_execute($likedStmt);
        $liked = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($likedStmt));
        mysqli_stmt_close($likedStmt);
    }

    return [
        'id'         => (int) $row['id'],
        'parentId'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        'name'       => $author['name'],
        'initials'   => $author['initials'],
        'content'    => $row['content'],
        'timeAgo'    => timeAgo($row['created_at']),
        'isEdited'   => (bool) $row['is_edited'],
        'isOwner'    => ((int) $row['user_id'] === (int) $listingOwnerId),
        'isMine'     => ($currentUserId && (int) $row['user_id'] === (int) $currentUserId),
        'liked'      => $liked,
        'likeCount'  => $likeCount,
    ];
}

/**
 * Replies (1 level of nesting) for one top-level comment, oldest first.
 */
function fetchCommentReplies($connection, $parentId, $currentUserId, $listingOwnerId) {
    $stmt = mysqli_prepare(
        $connection,
        "SELECT c.id, c.parent_id, c.user_id, c.content, c.is_edited, c.created_at, u.email
         FROM comments c
         LEFT JOIN users u ON c.user_id = u.id
         WHERE c.parent_id = ?
         ORDER BY c.created_at ASC"
    );
    mysqli_stmt_bind_param($stmt, "i", $parentId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $replies = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $replies[] = buildCommentPayload($connection, $row, $currentUserId, $listingOwnerId);
    }
    mysqli_stmt_close($stmt);
    return $replies;
}

/**
 * One page of top-level comments (with their replies attached) for a listing.
 * $total = ALL comments for the listing (top-level + replies), used for the
 * "Discussion (n)" header count. hasMore is based on top-level pagination only.
 */
function fetchListingComments($connection, $listingId, $listingOwnerId, $currentUserId, $limit, $offset, $sort = 'newest') {
    $order = ($sort === 'oldest') ? 'ASC' : 'DESC';

    $totalStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM comments WHERE listing_id = ?");
    mysqli_stmt_bind_param($totalStmt, "i", $listingId);
    mysqli_stmt_execute($totalStmt);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($totalStmt))['cnt'];
    mysqli_stmt_close($totalStmt);

    $topTotalStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM comments WHERE listing_id = ? AND parent_id IS NULL");
    mysqli_stmt_bind_param($topTotalStmt, "i", $listingId);
    mysqli_stmt_execute($topTotalStmt);
    $topTotal = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($topTotalStmt))['cnt'];
    mysqli_stmt_close($topTotalStmt);

    // $order is a fixed 'ASC'/'DESC' string above, never user input — safe to inline.
    $sql = "SELECT c.id, c.parent_id, c.user_id, c.content, c.is_edited, c.created_at, u.email
            FROM comments c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.listing_id = ? AND c.parent_id IS NULL
            ORDER BY c.created_at $order
            LIMIT ? OFFSET ?";
    $stmt = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($stmt, "iii", $listingId, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $comments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $comment = buildCommentPayload($connection, $row, $currentUserId, $listingOwnerId);
        $comment['replies'] = fetchCommentReplies($connection, $row['id'], $currentUserId, $listingOwnerId);
        $comments[] = $comment;
    }
    mysqli_stmt_close($stmt);

    return [
        'comments' => $comments,
        'hasMore'  => ($offset + $limit) < $topTotal,
        'total'    => $total,
    ];
}