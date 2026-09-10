/**
 * Swapify — main.js
 * Site-wide behaviors shared by every page: search suggestions,
 * favorite toggling, and a reusable toast helper other pages/scripts call.
 */

document.addEventListener('DOMContentLoaded', function () {
  initSearchSuggestions();
  initFavoriteButtons();
});

/* ---------- Toast helper (used across all pages) ---------- */
function swapifyToast(message, type = 'primary') {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    document.body.appendChild(container);
  }

  const toastEl = document.createElement('div');
  toastEl.className = `toast align-items-center text-bg-${type} border-0`;
  toastEl.setAttribute('role', 'alert');
  toastEl.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${message}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  container.appendChild(toastEl);

  const toast = new bootstrap.Toast(toastEl, { delay: 3500 });
  toast.show();
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

/* ---------- Navbar search suggestions (AJAX-ready, placeholder endpoint) ---------- */
function initSearchSuggestions() {
  const input = document.getElementById('navSearchInput');
  if (!input) return;

  let debounceTimer;
  input.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    const query = this.value.trim();
    if (query.length < 2) return;

    debounceTimer = setTimeout(() => {
      fetchSearchSuggestions(query);
    }, 300);
  });
}

function fetchSearchSuggestions(query) {
  // Placeholder endpoint — backend not implemented yet.
  const xhr = new XMLHttpRequest();
  xhr.open('GET', `/api/search-suggestions.php?q=${encodeURIComponent(query)}`, true);
  xhr.onload = function () {
    if (xhr.status === 200) {
      // Backend will return JSON suggestions here once implemented.
      // const suggestions = JSON.parse(xhr.responseText);
    }
  };
  xhr.send();
}

/* ---------- Favorite / wishlist toggle on trade cards ---------- */
function initFavoriteButtons() {
  document.querySelectorAll('.trade-card-fav').forEach((btn) => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const icon = this.querySelector('i');
      const nowActive = icon.classList.toggle('bi-heart-fill');
      icon.classList.toggle('bi-heart', !nowActive);
      this.style.color = nowActive ? '#ff8a3d' : '';

      // Placeholder AJAX call — wire to /api/wishlist.php once backend exists.
      const itemId = this.dataset.itemId || null;
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/wishlist.php', true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify({ itemId, action: nowActive ? 'add' : 'remove' }));

      swapifyToast(nowActive ? 'Added to your wishlist' : 'Removed from wishlist', nowActive ? 'primary' : 'secondary');
    });
  });
}