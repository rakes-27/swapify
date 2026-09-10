/**
 * Swapify — comments.js
 * Drives the item-discussion component against the real backend in
 * items_details.php: posting, replies (1 level), likes, delete own,
 * report, sort, and paginated "Load more". Content is server-rendered
 * on first load — this file only handles what happens after that.
 */

document.addEventListener('DOMContentLoaded', function () {
  initCharCounter();
  initMentionDropdown();
  initPostComment();
  initReplyToggle();
  initLikeButtons();
  initDeleteButtons();
  initReportComment();
  initCommentSort();
  initLoadMoreComments();
});

function endpoint() {
  return window.location.pathname + window.location.search;
}

/* ---------- Character counter ---------- */
function initCharCounter() {
  const input = document.getElementById('commentInput');
  const counter = document.getElementById('commentCharCounter');
  if (!input || !counter) return;

  input.addEventListener('input', function () {
    const len = this.value.length;
    counter.textContent = `${len} / 500`;
    counter.classList.toggle('limit-near', len > 400 && len <= 500);
    counter.classList.toggle('limit-over', len > 500);
  });
}

/* ---------- @mention dropdown (client-side insert only, no lookup call) ---------- */
function initMentionDropdown() {
  const input = document.getElementById('commentInput');
  const dropdown = document.getElementById('mentionDropdown');
  if (!input || !dropdown) return;

  input.addEventListener('input', function () {
    const lastWord = this.value.split(/\s/).pop();
    dropdown.classList.toggle('d-none', !(lastWord.startsWith('@') && lastWord.length > 1));
  });

  dropdown.querySelectorAll('.mention-dropdown-item').forEach((item) => {
    item.addEventListener('click', function () {
      const words = input.value.split(/\s/);
      words[words.length - 1] = '@' + this.dataset.username + ' ';
      input.value = words.join(' ');
      dropdown.classList.add('d-none');
      input.focus();
    });
  });

  document.addEventListener('click', function (e) {
    if (!dropdown.contains(e.target) && e.target !== input) dropdown.classList.add('d-none');
  });
}

/* ---------- Post a new top-level comment ---------- */
function initPostComment() {
  const btn = document.getElementById('postCommentBtn');
  const input = document.getElementById('commentInput');
  const panel = document.getElementById('discussionPanel');
  if (!btn || !input || !panel) return;

  btn.addEventListener('click', function () {
    const content = input.value.trim();
    if (!content || content.length > 500) return;

    submitComment(panel.dataset.listingId, content, null, btn, () => {
      input.value = '';
      const counter = document.getElementById('commentCharCounter');
      if (counter) counter.textContent = '0 / 500';
    });
  });
}

function submitComment(listingId, content, parentId, triggerBtn, onSuccess) {
  const originalLabel = triggerBtn.innerHTML;
  triggerBtn.disabled = true;
  triggerBtn.textContent = 'Posting...';

  const formData = new FormData();
  formData.append('action', 'add_comment');
  formData.append('listing_id', listingId);
  formData.append('content', content);
  if (parentId) formData.append('parent_id', parentId);

  fetch(endpoint(), {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
    .then((res) => res.json())
    .then((data) => {
      triggerBtn.disabled = false;
      triggerBtn.innerHTML = originalLabel;

      if (!data.success) {
        swapifyToast(data.message || 'Could not post your comment.', 'danger');
        return;
      }

      placeNewComment(data.comment);
      bumpDiscussionCount(1);
      if (onSuccess) onSuccess();
      swapifyToast('Comment posted', 'success');
    })
    .catch(() => {
      triggerBtn.disabled = false;
      triggerBtn.innerHTML = originalLabel;
      swapifyToast('Something went wrong. Please try again.', 'danger');
    });
}

function placeNewComment(comment) {
  const list = document.getElementById('commentList');
  const noCommentsMsg = document.getElementById('noCommentsMsg');
  if (noCommentsMsg) noCommentsMsg.remove();

  if (comment.parentId) {
    const parentCard = list.querySelector(`.comment-card[data-comment-id="${comment.parentId}"]`);
    if (!parentCard) return;
    let repliesWrap = parentCard.querySelector(':scope > .comment-body > .comment-replies');
    if (!repliesWrap) {
      repliesWrap = document.createElement('div');
      repliesWrap.className = 'comment-replies';
      const replyBox = parentCard.querySelector(':scope > .comment-body > [data-reply-box]');
      parentCard.querySelector(':scope > .comment-body').insertBefore(repliesWrap, replyBox);
    }
    repliesWrap.appendChild(buildCommentCard(comment, true));
  } else {
    list.insertBefore(buildCommentCard(comment, false), list.firstChild);
    initReplyToggle();
  }

  initLikeButtons();
  initDeleteButtons();
  initReportComment();
}

function buildCommentCard(c, isReply) {
  const card = document.createElement('div');
  card.className = 'comment-card';
  card.dataset.commentId = c.id;
  card.dataset.parentId = c.parentId || '';

  const avatarStyle = isReply ? ' style="width:32px;height:32px;font-size:0.68rem;"' : '';
  const nameSuffix = c.isOwner ? ' (owner)' : '';
  const actionBtn = c.isMine
    ? '<button class="comment-delete-btn text-decoration-none">Delete</button>'
    : '<button class="comment-report-btn text-decoration-none">Report</button>';
  const replyBtn = isReply ? '' : '<button class="comment-reply-btn">Reply</button>';

  card.innerHTML = `
    <div class="comment-avatar"${avatarStyle}>${escapeHtml(c.initials)}</div>
    <div class="comment-body">
      <div class="comment-bubble">
        <strong class="small">${escapeHtml(c.name)}${nameSuffix}</strong>
        <div class="small"></div>
      </div>
      <div class="comment-meta">
        <span>${escapeHtml(c.timeAgo || 'Just now')}</span>
        <button class="comment-like-btn" data-count="0"><i class="bi bi-hand-thumbs-up"></i> <span class="like-count">0</span></button>
        ${replyBtn}
        ${actionBtn}
      </div>
      ${isReply ? '' : `
      <div class="comment-write-box d-none" data-reply-box>
        <div class="comment-avatar" style="width:32px;height:32px;font-size:0.68rem;">${escapeHtml(c.initials)}</div>
        <div class="flex-grow-1">
          <textarea class="form-control form-control-sm" rows="1" maxlength="500" placeholder="Write a reply..."></textarea>
          <div class="text-end mt-1">
            <button class="btn btn-light-swap btn-sm">Cancel</button>
            <button class="btn btn-primary btn-sm">Reply</button>
          </div>
        </div>
      </div>`}
    </div>`;
  card.querySelector('.comment-bubble .small:last-child').textContent = c.content;
  return card;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str || '';
  return div.innerHTML;
}

function bumpDiscussionCount(delta) {
  const el = document.getElementById('discussionCount');
  if (!el) return;
  const current = parseInt(el.textContent.replace(/[()]/g, ''), 10) || 0;
  el.textContent = `(${current + delta})`;
}

/* ---------- Reply toggle + submit (1 level nesting) ---------- */
function initReplyToggle() {
  document.querySelectorAll('.comment-reply-btn').forEach((btn) => {
    if (btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', function () {
      const box = this.closest('.comment-body').querySelector(':scope > [data-reply-box]');
      if (!box) return;
      box.classList.toggle('d-none');
      if (!box.classList.contains('d-none')) box.querySelector('textarea').focus();
    });
  });

  document.querySelectorAll('[data-reply-box]').forEach((box) => {
    if (box.dataset.wired) return;
    box.dataset.wired = '1';

    const cancelBtn = box.querySelector('.btn-light-swap');
    const sendBtn = box.querySelector('.btn-primary');
    if (cancelBtn) cancelBtn.addEventListener('click', () => box.classList.add('d-none'));

    if (sendBtn) {
      sendBtn.addEventListener('click', function () {
        const textarea = box.querySelector('textarea');
        const content = textarea.value.trim();
        if (!content) return;

        const parentCard = box.closest('.comment-card');
        const parentId = parentCard.dataset.commentId;
        const panel = document.getElementById('discussionPanel');

        submitComment(panel.dataset.listingId, content, parentId, sendBtn, () => {
          textarea.value = '';
          box.classList.add('d-none');
        });
      });
    }
  });
}

/* ---------- Like toggle (real POST, server is source of truth for count) ---------- */
function initLikeButtons() {
  document.querySelectorAll('.comment-like-btn').forEach((btn) => {
    if (btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', function () {
      const card = this.closest('.comment-card');
      const commentId = card.dataset.commentId;
      const countEl = this.querySelector('.like-count');
      const wasLiked = this.classList.contains('liked');

      // Optimistic UI
      this.classList.toggle('liked', !wasLiked);
      countEl.textContent = wasLiked ? Math.max(0, parseInt(countEl.textContent, 10) - 1) : parseInt(countEl.textContent, 10) + 1;

      const formData = new FormData();
      formData.append('action', 'toggle_comment_like');
      formData.append('comment_id', commentId);

      fetch(endpoint(), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) {
            this.classList.toggle('liked', wasLiked);
            countEl.textContent = wasLiked ? parseInt(countEl.textContent, 10) + 1 : Math.max(0, parseInt(countEl.textContent, 10) - 1);
            swapifyToast(data.message || 'Could not update like.', 'danger');
            return;
          }
          this.classList.toggle('liked', data.liked);
          countEl.textContent = data.count;
        })
        .catch(() => {
          this.classList.toggle('liked', wasLiked);
          countEl.textContent = wasLiked ? parseInt(countEl.textContent, 10) + 1 : Math.max(0, parseInt(countEl.textContent, 10) - 1);
          swapifyToast('Something went wrong. Please try again.', 'danger');
        });
    });
  });
}

/* ---------- Delete own comment (real POST) ---------- */
function initDeleteButtons() {
  document.querySelectorAll('.comment-delete-btn').forEach((btn) => {
    if (btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', function () {
      if (!confirm('Delete this comment?')) return;

      const card = this.closest('.comment-card');
      const commentId = card.dataset.commentId;
      const isTopLevel = !card.closest('.comment-replies');

      const formData = new FormData();
      formData.append('action', 'delete_comment');
      formData.append('comment_id', commentId);

      fetch(endpoint(), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) {
            swapifyToast(data.message || 'Could not delete comment.', 'danger');
            return;
          }

          const repliesCount = isTopLevel ? card.querySelectorAll('.comment-replies .comment-card').length : 0;
          card.remove();
          bumpDiscussionCount(-(1 + repliesCount));

          const list = document.getElementById('commentList');
          if (!list.querySelector('.comment-card')) {
            list.innerHTML = '<p class="text-muted-swap text-center py-3" id="noCommentsMsg">No comments yet. Be the first to say something!</p>';
          }
          swapifyToast('Comment deleted', 'secondary');
        })
        .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
    });
  });
}

/* ---------- Report comment (real POST) ---------- */
function initReportComment() {
  document.querySelectorAll('.comment-report-btn').forEach((btn) => {
    if (btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', function () {
      const modalEl = document.getElementById('reportCommentModal');
      modalEl.dataset.activeCommentId = this.closest('.comment-card').dataset.commentId;
      new bootstrap.Modal(modalEl).show();
    });
  });

  const submitBtn = document.getElementById('submitReportBtn');
  if (submitBtn && !submitBtn.dataset.wired) {
    submitBtn.dataset.wired = '1';
    submitBtn.addEventListener('click', function () {
      const modalEl = document.getElementById('reportCommentModal');
      const commentId = modalEl.dataset.activeCommentId;
      const reason = document.getElementById('reportReasonSelect').value;
      const details = document.getElementById('reportDetailsInput').value.trim();

      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';

      const formData = new FormData();
      formData.append('action', 'report_comment');
      formData.append('comment_id', commentId);
      formData.append('reason', reason);
      formData.append('details', details);

      fetch(endpoint(), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((data) => {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
          bootstrap.Modal.getInstance(modalEl).hide();
          document.getElementById('reportDetailsInput').value = '';

          if (!data.success) {
            swapifyToast(data.message || 'Could not submit report.', 'danger');
            return;
          }
          swapifyToast(data.message || 'Comment reported. Our team will review it.', 'secondary');
        })
        .catch(() => {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
          swapifyToast('Something went wrong. Please try again.', 'danger');
        });
    });
  }
}

/* ---------- Sort newest / oldest (re-fetches page 1 in the new order) ---------- */
function initCommentSort() {
  const select = document.getElementById('commentSortSelect');
  const panel = document.getElementById('discussionPanel');
  if (!select || !panel) return;

  select.addEventListener('change', function () {
    reloadFirstPage(panel, this.value);
  });
}

function reloadFirstPage(panel, sort) {
  const formData = new FormData();
  formData.append('action', 'load_comments');
  formData.append('listing_id', panel.dataset.listingId);
  formData.append('offset', 0);
  formData.append('sort', sort);

  fetch(endpoint(), {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
    .then((res) => res.json())
    .then((data) => {
      if (!data.success) return;
      const list = document.getElementById('commentList');
      list.innerHTML = '';
      if (data.comments.length === 0) {
        list.innerHTML = '<p class="text-muted-swap text-center py-3" id="noCommentsMsg">No comments yet. Be the first to say something!</p>';
      } else {
        data.comments.forEach((c) => {
          list.appendChild(buildTreeFromServer(c));
        });
      }
      panel.dataset.offset = data.comments.length;
      panel.dataset.hasMore = data.hasMore ? '1' : '0';
      document.getElementById('loadMoreCommentsBtn').classList.toggle('d-none', !data.hasMore);

      initReplyToggle();
      initLikeButtons();
      initDeleteButtons();
      initReportComment();
    })
    .catch(() => swapifyToast('Could not load comments.', 'danger'));
}

function buildTreeFromServer(c) {
  const card = buildCommentCard(c, false);
  if (c.replies && c.replies.length) {
    const repliesWrap = document.createElement('div');
    repliesWrap.className = 'comment-replies';
    c.replies.forEach((r) => repliesWrap.appendChild(buildCommentCard(r, true)));
    const replyBox = card.querySelector('[data-reply-box]');
    card.querySelector('.comment-body').insertBefore(repliesWrap, replyBox);
  }
  // sync real like state (buildCommentCard defaults to 0/unliked)
  const likeBtn = card.querySelector(':scope > .comment-body > .comment-meta .comment-like-btn');
  if (likeBtn) {
    likeBtn.classList.toggle('liked', !!c.liked);
    likeBtn.querySelector('.like-count').textContent = c.likeCount;
  }
  return card;
}

/* ---------- Load more (real pagination) ---------- */
function initLoadMoreComments() {
  const btn = document.getElementById('loadMoreCommentsBtn');
  const panel = document.getElementById('discussionPanel');
  if (!btn || !panel) return;

  btn.addEventListener('click', function () {
    const listingId = panel.dataset.listingId;
    const offset = panel.dataset.offset || 0;
    const sort = document.getElementById('commentSortSelect').value;

    btn.disabled = true;
    btn.textContent = 'Loading...';

    const formData = new FormData();
    formData.append('action', 'load_comments');
    formData.append('listing_id', listingId);
    formData.append('offset', offset);
    formData.append('sort', sort);

    fetch(endpoint(), {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then((res) => res.json())
      .then((data) => {
        btn.disabled = false;
        btn.textContent = 'Load more comments';

        if (!data.success) {
          swapifyToast('Could not load more comments.', 'danger');
          return;
        }

        const list = document.getElementById('commentList');
        data.comments.forEach((c) => list.appendChild(buildTreeFromServer(c)));

        panel.dataset.offset = parseInt(offset, 10) + data.comments.length;
        panel.dataset.hasMore = data.hasMore ? '1' : '0';
        btn.classList.toggle('d-none', !data.hasMore);

        initReplyToggle();
        initLikeButtons();
        initDeleteButtons();
        initReportComment();
      })
      .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Load more comments';
        swapifyToast('Something went wrong. Please try again.', 'danger');
      });
  });
}