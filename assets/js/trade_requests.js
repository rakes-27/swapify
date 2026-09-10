/**
 * Swapify — trade-requests.js
 * Accept / reject / cancel actions on trade request cards. Posts via
 * FormData to this same page (trade_requests.php, action=update_trade_status)
 * — no separate API endpoint. UI only updates after the server confirms.
 */

document.addEventListener('DOMContentLoaded', function () {
  initTradeActions();
});

function initTradeActions() {
  document.querySelectorAll('.trade-request-card').forEach((card) => {
    const acceptBtn = card.querySelector('.action-accept');
    const rejectBtn = card.querySelector('.action-reject');
    const cancelBtn = card.querySelector('.action-cancel');

    if (acceptBtn) acceptBtn.addEventListener('click', () => submitStatusUpdate(card, 'accepted', 'Trade accepted', acceptBtn));
    if (rejectBtn) rejectBtn.addEventListener('click', () => submitStatusUpdate(card, 'rejected', 'Trade request rejected', rejectBtn));
    if (cancelBtn) cancelBtn.addEventListener('click', () => submitStatusUpdate(card, 'cancelled', 'Trade request cancelled', cancelBtn));
  });
}

function submitStatusUpdate(card, status, message, triggerBtn) {
  const tradeId = card.dataset.tradeId;
  const actions = card.querySelector('.trade-request-actions');

  // Disable all buttons in this card while the request is in flight
  if (actions) actions.querySelectorAll('button').forEach((b) => (b.disabled = true));

  const formData = new FormData();
  formData.append('action', 'update_trade_status');
  formData.append('trade_id', tradeId);
  formData.append('status', status);

  fetch(window.location.pathname + window.location.search, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: formData
  })
    .then((res) => res.json())
    .then((data) => {
      if (!data.success) {
        if (actions) actions.querySelectorAll('button').forEach((b) => (b.disabled = false));
        swapifyToast(data.message || 'Something went wrong.', 'danger');
        return;
      }

      const badge = card.querySelector('.status-badge');
      badge.className = 'status-badge ' + status;
      badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
      if (actions) actions.remove();

      swapifyToast(message, status === 'accepted' ? 'success' : 'secondary');
    })
    .catch(() => {
      if (actions) actions.querySelectorAll('button').forEach((b) => (b.disabled = false));
      swapifyToast('Something went wrong. Please try again.', 'danger');
    });
}