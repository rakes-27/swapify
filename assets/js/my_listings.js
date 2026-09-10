/**
 * Swapify — my-listings.js
 * Client-side status filter (All/Active/Pending/Traded) and a delete
 * flow with confirmation. Delete posts FormData (action=delete, item_id)
 * back to my_listings.php itself and is handled via $_POST there — the
 * row is only removed from the DOM after the server confirms success.
 */

document.addEventListener('DOMContentLoaded', function () {
  initStatusFilter();
  initDeleteListing();
});

/* ---------- Status filter pills ---------- */
function initStatusFilter() {
  const pills = document.querySelectorAll('.my-listings-filter .category-pill');
  const cards = document.querySelectorAll('.my-listing-row');
  if (!pills.length) return;

  pills.forEach((pill) => {
    pill.addEventListener('click', function (e) {
      e.preventDefault();
      pills.forEach((p) => p.classList.remove('active'));
      this.classList.add('active');
      const filter = this.dataset.status;

      cards.forEach((card) => {
        card.style.display = (filter === 'all' || card.dataset.status === filter) ? '' : 'none';
      });
    });
  });
}

/* ---------- Delete with confirmation (real AJAX) ---------- */
let pendingDeleteListingRow = null;

function initDeleteListing() {
  document.querySelectorAll('.my-listing-delete-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      pendingDeleteListingRow = this.closest('.my-listing-row');
      document.getElementById('deleteMyListingTitle').textContent =
        pendingDeleteListingRow.dataset.title || 'this listing';
      new bootstrap.Modal(document.getElementById('deleteMyListingModal')).show();
    });
  });

  const confirmBtn = document.getElementById('confirmDeleteMyListingBtn');
  if (!confirmBtn) return;

  confirmBtn.addEventListener('click', function () {
    if (!pendingDeleteListingRow) return;

    const itemId = pendingDeleteListingRow.dataset.itemId;
    const spinner = confirmBtn.querySelector('.spinner-border');
    const label = confirmBtn.querySelector('.btn-label');

    confirmBtn.disabled = true;
    if (spinner) spinner.classList.remove('d-none');
    if (label) label.textContent = 'Deleting...';

    // FormData + $_POST, submitted back to my_listings.php itself
    // (no separate /api endpoint or JSON body).
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('item_id', itemId);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'my_listings.php', true);
    // Do NOT set Content-Type manually — the browser sets the correct
    // multipart/form-data boundary automatically for FormData bodies.

    xhr.onload = function () {
      confirmBtn.disabled = false;
      if (spinner) spinner.classList.add('d-none');
      if (label) label.textContent = 'Delete';

      let response = {};
      try {
        response = JSON.parse(xhr.responseText);
      } catch (err) {
        swapifyToast('Unexpected server response.', 'danger');
        return;
      }

      bootstrap.Modal.getInstance(document.getElementById('deleteMyListingModal')).hide();

      if (xhr.status >= 200 && xhr.status < 300 && response.success) {
        const rowToRemove = pendingDeleteListingRow;
        rowToRemove.remove();
        swapifyToast(response.message || 'Listing deleted', 'secondary');

        if (!document.querySelectorAll('.my-listing-row').length) {
          document.getElementById('myListingsGrid').classList.add('d-none');
          document.getElementById('myListingsEmptyState').classList.remove('d-none');
        }
      } else {
        swapifyToast(response.message || 'Could not delete this listing. Please try again.', 'danger');
      }

      pendingDeleteListingRow = null;
    };

    xhr.onerror = function () {
      confirmBtn.disabled = false;
      if (spinner) spinner.classList.add('d-none');
      if (label) label.textContent = 'Delete';
      swapifyToast('Something went wrong. Please try again.', 'danger');
    };

    xhr.send(formData);
  });
}