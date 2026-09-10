/**
 * Swapify — settings.js
 * Each section's Save button posts real data to settings.php via
 * fetch + FormData (no /api). Password section keeps its client-side
 * match validation before submitting.
 */

document.addEventListener('DOMContentLoaded', function () {
  initAccountSave();
  initNotificationsSave();
  initPrivacySave();
  initPasswordChange();
});

/* ---------- Account: email + phone ---------- */
function initAccountSave() {
  const btn = document.getElementById('saveAccountBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const email = document.getElementById('accountEmail').value.trim();
    const phone = document.getElementById('accountPhone').value.trim();

    const formData = new FormData();
    formData.append('action', 'update_account');
    formData.append('email', email);
    formData.append('phone', phone);

    fetch('settings.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        swapifyToast(data.message, data.success ? 'success' : 'danger');
      })
      .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
  });
}

/* ---------- Notifications: 4 switches ---------- */
function initNotificationsSave() {
  const btn = document.getElementById('saveNotificationsBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const formData = new FormData();
    formData.append('action', 'update_notifications');
    if (document.getElementById('notifyTrades').checked)    formData.append('notify_trades', '1');
    if (document.getElementById('notifyReviews').checked)   formData.append('notify_reviews', '1');
    if (document.getElementById('notifyMessages').checked)  formData.append('notify_messages', '1');
    if (document.getElementById('notifyMarketing').checked) formData.append('notify_marketing', '1');

    fetch('settings.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        swapifyToast(data.message, data.success ? 'success' : 'danger');
      })
      .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
  });
}

/* ---------- Privacy: 3 switches ---------- */
function initPrivacySave() {
  const btn = document.getElementById('savePrivacyBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const formData = new FormData();
    formData.append('action', 'update_privacy');
    if (document.getElementById('showExactLocation').checked) formData.append('show_exact_location', '1');
    if (document.getElementById('showWishlist').checked)      formData.append('show_wishlist', '1');
    if (document.getElementById('allowAllRequests').checked)  formData.append('allow_all_requests', '1');

    fetch('settings.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        swapifyToast(data.message, data.success ? 'success' : 'danger');
      })
      .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
  });
}

/* ---------- Password change: match validation, then real submit ---------- */
function initPasswordChange() {
  const btn = document.getElementById('changePasswordBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const current = document.getElementById('currentPassword');
    const next = document.getElementById('newPasswordSettings');
    const confirm = document.getElementById('confirmPasswordSettings');

    const currentValid = current.value.length > 0;
    const nextValid = next.value.length >= 6;
    const matchValid = confirm.value === next.value && confirm.value !== '';

    current.classList.toggle('is-invalid', !currentValid);
    next.classList.toggle('is-invalid', !nextValid);
    confirm.classList.toggle('is-invalid', !matchValid);

    if (!currentValid || !nextValid || !matchValid) return;

    const formData = new FormData();
    formData.append('action', 'change_password');
    formData.append('current_password', current.value);
    formData.append('new_password', next.value);

    fetch('settings.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        swapifyToast(data.message, data.success ? 'success' : 'danger');
        if (data.success) {
          current.value = '';
          next.value = '';
          confirm.value = '';
        }
      })
      .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
  });
}