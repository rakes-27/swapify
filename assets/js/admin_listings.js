/**
 * Swapify Admin — admin_listings.js
 * Approve/Reject/Cancel/Reactivate/Delete — all real POSTs matching the
 * actual enum (pending, active, rejected, traded, cancelled).
 */

document.addEventListener('DOMContentLoaded', function () {
  initSearchDebounce();
  initSimpleAction('.approve-listing-btn', 'approve');
  initSimpleAction('.reject-listing-btn', 'reject');
  initSimpleAction('.cancel-listing-btn', 'cancel');
  initSimpleAction('.reactivate-listing-btn', 'reactivate');
  initDeleteButtons();
});

function initSearchDebounce() {
  const input = document.getElementById('listingSearchInput');
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
      const listingId = row.dataset.listingId;

      const formData = new FormData();
      formData.append('action', action);
      formData.append('listing_id', listingId);

      fetch('listings.php', { method: 'POST', body: formData })
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
  const modalEl = document.getElementById('deleteListingModal');
  const modal = new bootstrap.Modal(modalEl);
  const titleSpan = document.getElementById('deleteListingTitle');
  const confirmBtn = document.getElementById('confirmDeleteListingBtn');
  let pendingListingId = null;

  document.querySelectorAll('.delete-listing-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      const row = this.closest('tr');
      pendingListingId = row.dataset.listingId;
      titleSpan.textContent = `"${row.dataset.listingTitle}"`;
      modal.show();
    });
  });

  confirmBtn.addEventListener('click', function () {
    if (!pendingListingId) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('listing_id', pendingListingId);

    fetch('listings.php', { method: 'POST', body: formData })
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