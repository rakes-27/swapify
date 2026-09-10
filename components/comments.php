<?php
/**
 * Swapify — Comments component (Discussion panel)
 * Renders real data from the `comments` / `comment_likes` tables.
 * Expects $connection, $id (listing id) available from items_details.php.
 * Requires buildCommentPayload() / fetchListingComments() / commentAuthorInfo()
 * to already be defined (see items_details_comment_helpers.php).
 */

global $connection, $id, $listing;

$listingId = isset($id) ? (int) $id : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
$listingOwnerId = $listing['user_id'] ?? 0;
$currentUserId = $_SESSION['user_id'] ?? 0;

$PAGE_SIZE = 5;
$page = fetchListingComments($connection, $listingId, $listingOwnerId, $currentUserId, $PAGE_SIZE, 0, 'newest');
$comments = $page['comments'];
$hasMore = $page['hasMore'];
$totalCount = $page['total'];

// Current user's own display info (for the write box avatar)
$myInitials = 'U';
if ($currentUserId) {
    $meStmt = mysqli_prepare($connection, "SELECT email FROM users WHERE id = ?");
    mysqli_stmt_bind_param($meStmt, "i", $currentUserId);
    mysqli_stmt_execute($meStmt);
    $meRow = mysqli_fetch_assoc(mysqli_stmt_get_result($meStmt));
    mysqli_stmt_close($meStmt);
    if (!empty($meRow['email'])) {
        $myInitials = strtoupper(substr(explode('@', $meRow['email'])[0], 0, 2));
    }
}

// Mention suggestions: people who've participated in this discussion + the listing owner
$mentionCandidates = [];
if ($currentUserId) {
    $mStmt = mysqli_prepare(
        $connection,
        "SELECT DISTINCT u.id, u.email FROM users u
         WHERE u.id IN (
            SELECT user_id FROM comments WHERE listing_id = ?
            UNION SELECT ?
         ) AND u.id != ?
         LIMIT 6"
    );
    mysqli_stmt_bind_param($mStmt, "iii", $listingId, $listingOwnerId, $currentUserId);
    mysqli_stmt_execute($mStmt);
    $mResult = mysqli_stmt_get_result($mStmt);
    while ($row = mysqli_fetch_assoc($mResult)) {
        $info = commentAuthorInfo($row['email']);
        $mentionCandidates[] = $info;
    }
    mysqli_stmt_close($mStmt);
}

/** Renders one comment card (used for both top-level and replies) */
function renderCommentCard($c, $isReply = false) {
    $avatarStyle = $isReply ? ' style="width:32px;height:32px;font-size:0.68rem;"' : '';
    ?>
    <div class="comment-card" data-comment-id="<?= $c['id'] ?>" data-parent-id="<?= $c['parentId'] ?? '' ?>">
        <div class="comment-avatar"<?= $avatarStyle ?>><?= htmlspecialchars($c['initials']) ?></div>
        <div class="comment-body">
            <div class="comment-bubble">
                <strong class="small"><?= htmlspecialchars($c['name']) ?><?= $c['isOwner'] ? ' (owner)' : '' ?></strong>
                <div class="small"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
            </div>
            <div class="comment-meta">
                <span><?= htmlspecialchars($c['timeAgo']) ?></span>
                <?php if ($c['isEdited']): ?><span class="comment-edited-badge">edited</span><?php endif; ?>
                <button class="comment-like-btn <?= $c['liked'] ? 'liked' : '' ?>" data-count="<?= $c['likeCount'] ?>">
                    <i class="bi bi-hand-thumbs-up"></i> <span class="like-count"><?= $c['likeCount'] ?></span>
                </button>
                <?php if (!$isReply): ?>
                    <button class="comment-reply-btn">Reply</button>
                <?php endif; ?>
                <?php if ($c['isMine']): ?>
                    <button class="comment-delete-btn text-decoration-none">Delete</button>
                <?php else: ?>
                    <button class="comment-report-btn text-decoration-none">Report</button>
                <?php endif; ?>
            </div>

            <?php if (!$isReply): ?>
                <?php if (!empty($c['replies'])): ?>
                    <div class="comment-replies">
                        <?php foreach ($c['replies'] as $reply): ?>
                            <?php renderCommentCard($reply, true); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="comment-write-box d-none" data-reply-box>
                    <div class="comment-avatar" style="width:32px;height:32px;font-size:0.68rem;"><?= htmlspecialchars($GLOBALS['myInitials'] ?? 'U') ?></div>
                    <div class="flex-grow-1">
                        <textarea class="form-control form-control-sm" rows="1" maxlength="500" placeholder="Write a reply..."></textarea>
                        <div class="text-end mt-1">
                            <button class="btn btn-light-swap btn-sm">Cancel</button>
                            <button class="btn btn-primary btn-sm">Reply</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<!-- ============================== ITEM DISCUSSION ============================== -->
<div class="dash-panel" id="discussionPanel" data-listing-id="<?= $listingId ?>" data-offset="<?= count($comments) ?>" data-has-more="<?= $hasMore ? '1' : '0' ?>">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <div class="dash-panel-title mb-0">Discussion <span class="text-muted-swap" id="discussionCount">(<?= $totalCount ?>)</span></div>
        <select class="sort-select" id="commentSortSelect" style="width:auto;">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
        </select>
    </div>

    <!-- Write a comment -->
    <?php if ($currentUserId): ?>
        <div class="comment-write-box position-relative">
            <div class="comment-avatar"><?= htmlspecialchars($myInitials) ?></div>
            <div class="flex-grow-1">
                <textarea class="form-control" id="commentInput" rows="2" maxlength="500"
                          placeholder="Ask a question or leave a comment... use @ to mention someone"></textarea>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="comment-char-counter" id="commentCharCounter">0 / 500</span>
                    <button class="btn btn-primary btn-sm" id="postCommentBtn">Post</button>
                </div>
                <?php if (!empty($mentionCandidates)): ?>
                    <div class="mention-dropdown d-none" id="mentionDropdown">
                        <?php foreach ($mentionCandidates as $m): ?>
                            <div class="mention-dropdown-item" data-username="<?= htmlspecialchars($m['username']) ?>">
                                <span class="comment-avatar" style="width:26px;height:26px;font-size:0.65rem;"><?= htmlspecialchars($m['initials']) ?></span> <?= htmlspecialchars($m['username']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="text-center text-muted-swap py-2">
            <a href="<?= asset('login.php') ?>" class="text-decoration-none">Log in</a> to join the discussion.
        </div>
    <?php endif; ?>

    <hr class="my-3">

    <!-- Comment list (real data, server-rendered) -->
    <div id="commentList">
        <?php if (empty($comments)): ?>
            <p class="text-muted-swap text-center py-3" id="noCommentsMsg">No comments yet. Be the first to say something!</p>
        <?php else: ?>
            <?php foreach ($comments as $comment): ?>
                <?php renderCommentCard($comment, false); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="text-center mt-3">
        <button class="btn btn-light-swap btn-sm <?= $hasMore ? '' : 'd-none' ?>" id="loadMoreCommentsBtn">Load more comments</button>
    </div>
</div>

<!-- Report comment modal -->
<div class="modal fade" id="reportCommentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px; border:none;">
            <div class="modal-header border-0">
                <h5 class="modal-title">Report comment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label form-label-swap">Reason</label>
                <select class="form-select mb-3" id="reportReasonSelect" style="border-radius:12px;">
                    <option>Spam</option>
                    <option>Harassment</option>
                    <option>Inappropriate content</option>
                    <option>Other</option>
                </select>
                <label class="form-label form-label-swap">Additional details (optional)</label>
                <textarea class="form-control" id="reportDetailsInput" rows="3" style="border-radius:12px;"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="submitReportBtn">Submit report</button>
            </div>
        </div>
    </div>
</div>