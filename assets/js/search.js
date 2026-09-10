/**
 * Swapify — search.js
 * Same grid/list toggle as marketplace.js, plus a brief skeleton-loading
 * simulation standing in for a real /api/search.php fetch.
 */

document.addEventListener('DOMContentLoaded', function () {
  simulateSearchLoad();
  initViewToggle();
  initFilterChanges();
});

/* ---------- Skeleton -> results ---------- */
function simulateSearchLoad() {
  const skeleton = document.getElementById('searchSkeleton');
  const results = document.getElementById('searchResults');
  if (!skeleton || !results) return;

  // Placeholder endpoint — backend not implemented yet.
  const xhr = new XMLHttpRequest();
  xhr.open('GET', '/api/search.php' + window.location.search, true);
  xhr.onload = finish;
  xhr.onerror = finish;
  xhr.send();

  function finish() {
    setTimeout(() => {
      skeleton.classList.add('d-none');
      results.classList.remove('d-none');
    }, 500);
  }
}

/* ---------- Grid / List toggle ---------- */
function initViewToggle() {
  const gridBtn = document.getElementById('viewGridBtn');
  const listBtn = document.getElementById('viewListBtn');
  const grid = document.getElementById('searchResults');
  if (!gridBtn || !listBtn || !grid) return;

  gridBtn.addEventListener('click', () => setView('grid'));
  listBtn.addEventListener('click', () => setView('list'));

  function setView(view) {
    gridBtn.classList.toggle('active', view === 'grid');
    listBtn.classList.toggle('active', view === 'list');
    grid.querySelectorAll('.trade-card').forEach((card) => card.classList.toggle('list-view', view === 'list'));
    grid.querySelectorAll('.grid-col').forEach((col) => {
      col.className = view === 'grid' ? 'grid-col col-md-6 col-lg-4' : 'grid-col col-12';
    });
  }
}

/* ---------- Filter change (placeholder AJAX) ---------- */
function initFilterChanges() {
  document.querySelectorAll('.filter-check input, #sortSelect').forEach((input) => {
    input.addEventListener('change', function () {
      // Placeholder endpoint — backend not implemented yet.
      const xhr = new XMLHttpRequest();
      xhr.open('GET', '/api/search.php', true);
      xhr.send();
    });
  });
}