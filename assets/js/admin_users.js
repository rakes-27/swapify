/**
 * Swapify Admin — admin_users.js
 * Search submits server-side. Suspend/Reactivate/Delete are real POSTs
 * to admin/users.php, matching the real enum (active/suspended/deactivated).
 */

document.addEventListener('DOMContentLoaded', function () {
  initSearchDebounce();
  initSimpleAction('.suspend-user-btn', 'suspend');
  initSimpleAction('.reactivate-user-btn', 'reactivate');
  initDeleteButtons();
});

function initSearchDebounce() {
  const input = document.getElementById('userSearchInput');
  if (!input) return;

  let timeout;
  input.addEventListener('input', function () {
    clearTimeout(timeout);
    timeout = setTimeout(() => { this.form.submit(); }, 500);
  });
}

function initSimpleAction(selector, action) {
  document.querySelectorAll(selector).forEach((btn) => {
    btn.addEventListener('click', function () {
      const row = this.closest('tr');
      const userId = row.dataset.userId;

      const formData = new FormData();
      formData.append('action', action);
      formData.append('user_id', userId);

      fetch('users.php', { method: 'POST', body: formData })
        .then((res) => res.json())
        .then((data) => {
          if (data.success) {
            swapifyToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 600);
          } else {
            swapifyToast(data.message, 'danger');
          }
        })
        .catch(() => swapifyToast('Something went wrong.', 'danger'));
    });
  });
}

function initDeleteButtons() {
  const modalEl = document.getElementById('deleteUserModal');
  const modal = new bootstrap.Modal(modalEl);
  const titleSpan = document.getElementById('deleteUserName');
  const confirmBtn = document.getElementById('confirmDeleteUserBtn');
  let pendingUserId = null;

  document.querySelectorAll('.delete-user-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      const row = this.closest('tr');
      pendingUserId = row.dataset.userId;
      titleSpan.textContent = row.dataset.userName || 'this user';
      modal.show();
    });
  });

  confirmBtn.addEventListener('click', function () {
    if (!pendingUserId) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('user_id', pendingUserId);

    fetch('users.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        modal.hide();
        if (data.success) {
          swapifyToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 600);
        } else {
          swapifyToast(data.message, 'danger');
        }
      })
      .catch(() => {
        modal.hide();
        swapifyToast('Something went wrong.', 'danger');
      });
  });
}