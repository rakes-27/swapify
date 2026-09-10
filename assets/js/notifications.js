/**
 * Swapify — notifications.js
 * Click-to-mark-read, and a Load More button that fetches older
 * notifications live from notifications.php (fetch + FormData, no /api).
 */

document.addEventListener('DOMContentLoaded', function () {
  initMarkAsRead();
  initMarkAllAsRead();
  initLoadMore();
  initTabFilter();
  initNavDropdownMarkRead();
});

/* ---------- Filter tabs (All / Trade updates / Reviews / System) ---------- */
function initTabFilter() {
  const tabs = document.querySelectorAll('#notificationTabs .nav-link');
  if (!tabs.length) return;

  tabs.forEach((tab) => {
    tab.addEventListener('click', function () {
      tabs.forEach((t) => t.classList.remove('active'));
      this.classList.add('active');

      const filter = this.dataset.filter;
      document.querySelectorAll('.notification-card').forEach((card) => {
        card.style.display = (filter === 'all' || card.dataset.type === filter) ? '' : 'none';
      });
    });
  });
}

/* ---------- Mark a single notification as read (click the card) ---------- */
function initMarkAsRead() {
  const list = document.getElementById('notificationList');
  if (!list) return;

  // Delegated on the list container so cards added by Load More are covered too.
  list.addEventListener('click', function (e) {
    const card = e.target.closest('.notification-card.unread');
    if (!card) return;

    const notificationId = card.dataset.id;
    markCardRead(card);
    updateUnreadCount();

    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('notification_id', notificationId);

    fetch('notifications.php', {
      method: 'POST',
      body: formData,
    }).catch(() => {});
  });
}

function markCardRead(card) {
  card.classList.remove('unread');
  const dot = card.querySelector('.notification-unread-dot');
  if (dot) dot.remove();
}

/* ---------- Mark all as read ---------- */
function initMarkAllAsRead() {
  const link = document.getElementById('markAllReadBtn');
  if (!link) return;

  link.addEventListener('click', function (e) {
    e.preventDefault();
    document.querySelectorAll('.notification-card.unread').forEach(markCardRead);
    updateUnreadCount();

    const formData = new FormData();
    formData.append('action', 'mark_all_read');

    fetch('notifications.php', {
      method: 'POST',
      body: formData,
    })
      .then(() => swapifyToast('All notifications marked as read', 'success'))
      .catch(() => {});
  });
}

/* ---------- Keep the "N unread" text accurate ---------- */
function updateUnreadCount() {
  const countText = document.getElementById('unreadCountText');
  if (!countText) return;
  const remaining = document.querySelectorAll('.notification-card.unread').length;
  countText.textContent = remaining === 0 ? 'All caught up' : `${remaining} unread`;
}

/* ---------- Load more (real pagination against notifications.php) ---------- */
function initLoadMore() {
  const btn = document.getElementById('loadMoreBtn');
  const list = document.getElementById('notificationList');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const spinner = btn.querySelector('.spinner-border');
    spinner.classList.remove('d-none');
    btn.disabled = true;

    const nextPage = parseInt(btn.dataset.page, 10) + 1;

    fetch(`notifications.php?page=${nextPage}`, {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((res) => res.json())
      .then((data) => {
        spinner.classList.add('d-none');
        btn.disabled = false;

        if (!data.success) return;

        list.insertAdjacentHTML('beforeend', data.html);
        btn.dataset.page = nextPage;

        if (!data.hasMore) {
          btn.classList.add('d-none');
        }

        const activeFilter = document.querySelector('#notificationTabs .nav-link.active')?.dataset.filter || 'all';
        if (activeFilter !== 'all') {
          list.querySelectorAll('.notification-card').forEach((card) => {
            card.style.display = card.dataset.type === activeFilter ? '' : 'none';
          });
        }
      })
      .catch(() => {
        spinner.classList.add('d-none');
        btn.disabled = false;
      });
  });
}

/* ---------- Navbar bell dropdown: mark read on click, keepalive so it
   completes even as the browser navigates to the notification's link ---------- */
function initNavDropdownMarkRead() {
  document.querySelectorAll('.nav-notif-link').forEach(function (link) {
    link.addEventListener('click', function () {
      if (this.dataset.read === '1') return;

      const formData = new FormData();
      formData.append('action', 'mark_read');
      formData.append('notification_id', this.dataset.id);

      fetch('notifications.php', {
        method: 'POST',
        body: formData,
        keepalive: true,
      }).catch(() => {});
    });
  });
}