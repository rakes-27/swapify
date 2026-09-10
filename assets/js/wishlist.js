/**
 * Swapify — wishlist.js
 * Remove-from-wishlist with a fade-out transition, and swaps in the empty
 * state once the grid is cleared. Posts via FormData to this same page
 * (wishlist.php, action=remove_wishlist) — no separate API endpoint.
 */

document.addEventListener('DOMContentLoaded', function () {
  initWishlistRemove();
});

function initWishlistRemove() {
  const grid = document.getElementById('wishlistGrid');
  const emptyState = document.getElementById('wishlistEmptyState');
  if (!grid) return;

  grid.addEventListener('click', function (e) {
    const btn = e.target.closest('.wishlist-card-remove');
    if (!btn) return;

    const col = btn.closest('.wishlist-col');
    const itemId = btn.dataset.itemId;

    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'remove_wishlist');
    formData.append('listing_id', itemId);

    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) {
          btn.disabled = false;
          swapifyToast(data.message || 'Could not remove item.', 'danger');
          return;
        }

        col.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        col.style.opacity = '0';
        col.style.transform = 'scale(0.95)';

        setTimeout(() => {
          col.remove();
          if (!grid.querySelector('.wishlist-col')) {
            grid.classList.add('d-none');
            emptyState.classList.remove('d-none');
          }
        }, 250);

        swapifyToast('Removed from wishlist', 'secondary');
      })
      .catch(() => {
        btn.disabled = false;
        swapifyToast('Something went wrong. Please try again.', 'danger');
      });
  });
}