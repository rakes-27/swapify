/**
 * Swapify — marketplace.js
 * Filters/search/sort/pagination all fetch from this same page
 * (marketplace.php) with an AJAX header, and swap the returned grid +
 * pagination HTML in — no full page reload, no separate API endpoint.
 * Favorite (heart) buttons on each card POST action=toggle_favorite to
 * this same page (wishlists table), same pattern as items_details.js.
 */

document.addEventListener('DOMContentLoaded', function () {
  initViewToggle();
  initFilterChanges();
  initMarketplaceSearch();
  initPaginationDelegation();
  initClearFilters();
  bindFavoriteButtons(document);
});

/* ---------- Build query params from current filter state ---------- */
function collectFilterParams() {
  const params = new URLSearchParams();

  const searchInput = document.getElementById('marketplaceSearchInput');
  if (searchInput && searchInput.value.trim()) {
    params.set('q', searchInput.value.trim());
  }

  document.querySelectorAll('input[name="category[]"]:checked').forEach((el) => {
    params.append('category[]', el.value);
  });

  document.querySelectorAll('input[name="condition[]"]:checked').forEach((el) => {
    params.append('condition[]', el.value);
  });

  const locationSelect = document.getElementById('locationSelect');
  if (locationSelect && locationSelect.value) {
    params.set('location', locationSelect.value);
  }

  const sortSelect = document.getElementById('sortSelect');
  if (sortSelect && sortSelect.value) {
    params.set('sort', sortSelect.value);
  }

  const trustedOnly = document.getElementById('trustedOnly');
  if (trustedOnly && trustedOnly.checked) {
    params.set('trusted', '1');
  }

  return params;
}

/* ---------- Core fetch + DOM swap ---------- */
function fetchFilteredResults(extraParams) {
  const params = collectFilterParams();

  if (extraParams) {
    Object.keys(extraParams).forEach((key) => params.set(key, extraParams[key]));
  }

  const grid = document.getElementById('marketplaceGrid');
  const paginationWrap = document.getElementById('paginationWrap');
  const countText = document.getElementById('resultsCountText');

  if (grid) grid.style.opacity = '0.5';

  fetch('marketplace.php?' + params.toString(), {
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then((res) => res.json())
    .then((data) => {
      if (!data.success) return;

      if (grid) {
        grid.innerHTML = data.gridHtml;
        grid.style.opacity = '1';
        bindFavoriteButtons(grid);
      }
      if (paginationWrap) paginationWrap.innerHTML = data.paginationHtml;
      if (countText) countText.textContent = data.countText;

      // Keep the URL shareable/bookmarkable without reloading the page.
      const newUrl = window.location.pathname + '?' + params.toString();
      window.history.pushState({}, '', newUrl);
    })
    .catch(() => {
      if (grid) grid.style.opacity = '1';
    });
}

/* ---------- Search box (now fetches instead of navigating) ---------- */
function initMarketplaceSearch() {
  const btn = document.getElementById('marketplaceSearchBtn');
  const input = document.getElementById('marketplaceSearchInput');
  if (!btn || !input) return;

  function go() {
    fetchFilteredResults({ page: 1 });
  }
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    go();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      go();
    }
  });
}

/* ---------- Grid / List toggle ---------- */
function initViewToggle() {
  const gridBtn = document.getElementById('viewGridBtn');
  const listBtn = document.getElementById('viewListBtn');
  const grid = document.getElementById('marketplaceGrid');
  if (!gridBtn || !listBtn || !grid) return;

  gridBtn.addEventListener('click', () => setView('grid'));
  listBtn.addEventListener('click', () => setView('list'));

  function setView(view) {
    gridBtn.classList.toggle('active', view === 'grid');
    listBtn.classList.toggle('active', view === 'list');
    grid.querySelectorAll('.trade-card').forEach((card) => {
      card.classList.toggle('list-view', view === 'list');
    });
    grid.querySelectorAll('.grid-col').forEach((col) => {
      col.className = view === 'grid' ? 'grid-col col-md-6 col-lg-4' : 'grid-col col-12';
    });
  }
}

/* ---------- Filter checkboxes / location / sort / trusted toggle ---------- */
function initFilterChanges() {
  document.querySelectorAll('.filter-input').forEach((input) => {
    input.addEventListener('change', () => fetchFilteredResults({ page: 1 }));
  });
}

/* ---------- Clear all filters ---------- */
function initClearFilters() {
  const clearBtn = document.getElementById('clearFiltersBtn');
  if (!clearBtn) return;

  clearBtn.addEventListener('click', function (e) {
    e.preventDefault();

    document.querySelectorAll('input[name="category[]"], input[name="condition[]"]').forEach((el) => {
      el.checked = false;
    });
    const locationSelect = document.getElementById('locationSelect');
    if (locationSelect) locationSelect.value = '';
    const trustedOnly = document.getElementById('trustedOnly');
    if (trustedOnly) trustedOnly.checked = false;
    const searchInput = document.getElementById('marketplaceSearchInput');
    if (searchInput) searchInput.value = '';

    fetchFilteredResults({ page: 1 });
  });
}

/* ---------- Pagination links (delegated, since the HTML is replaced each fetch) ---------- */
function initPaginationDelegation() {
  document.addEventListener('click', function (e) {
    const link = e.target.closest('#paginationWrap a.page-link');
    if (!link) return;

    e.preventDefault();

    const url = new URL(link.href, window.location.origin);
    const page = url.searchParams.get('page') || 1;

    fetchFilteredResults({ page });
  });
}

/* ---------- Favorite (heart) buttons on each card ----------
 * Bound directly to each button (NOT delegated on document). The button
 * markup has onclick="event.stopPropagation()" so clicking it doesn't also
 * trigger the card's own onclick navigation — but that also stops the click
 * from ever bubbling up to document, so a document-level delegated listener
 * never sees it. Binding straight to the button avoids that, and this gets
 * called again on every AJAX grid refresh since those buttons are brand new
 * DOM nodes with no listeners yet.
 */
function bindFavoriteButtons(scope) {
  (scope || document).querySelectorAll('.trade-card-fav').forEach((btn) => {
    if (btn.dataset.favWired) return;
    btn.dataset.favWired = '1';

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const itemId = btn.dataset.itemId;
      const icon = btn.querySelector('i');
      const wasActive = btn.classList.contains('active');

      // Optimistic UI
      setFavoriteState(btn, icon, !wasActive);

      const formData = new FormData();
      formData.append('action', 'toggle_favorite');
      formData.append('item_id', itemId);

      fetch('marketplace.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) {
            setFavoriteState(btn, icon, wasActive);
            if (typeof swapifyToast === 'function') {
              swapifyToast(data.message || 'Could not update favorite.', 'danger');
            }
            return;
          }
          setFavoriteState(btn, icon, data.active);
        })
        .catch(() => {
          setFavoriteState(btn, icon, wasActive);
          if (typeof swapifyToast === 'function') {
            swapifyToast('Something went wrong. Please try again.', 'danger');
          }
        });
    });
  });
}

function setFavoriteState(btn, icon, active) {
  btn.classList.toggle('active', active);
  icon.classList.toggle('bi-heart', !active);
  icon.classList.toggle('bi-heart-fill', active);
}