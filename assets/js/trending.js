/**
 * Swapify — trending.js
 *
 * - Favorite toggling (.trade-card-fav): POSTs to toggle_favorite and
 *   optimistically bumps the visible like count by ±1. Ranking/score stays
 *   frozen until tomorrow's snapshot recalculates.
 * - Whole-card click-through: clicking anywhere on a .trade-card (except
 *   the favorite button or an actual <a>/link inside it) navigates to that
 *   item's details page.
 * - Category pills (#categoryScroll): clicking a pill fetches trending.php
 *   with X-Requested-With so the server returns just the results HTML
 *   (see buildTrendingResultsHtml() in trending.php), which replaces
 *   #trendingResults in place — no full page reload.
 *
 * All three use event delegation (listeners on document / stable
 * containers, not on individual cards) so cards swapped in by the AJAX
 * category switch keep working without re-binding anything.
 */

document.addEventListener('DOMContentLoaded', function () {
  wireFavoriteButtons();
  wireCardNavigation();
  wireCategoryPills();
  wireBackForward();
});

/* ---------- Favorite toggle (delegated) ---------- */
function wireFavoriteButtons() {
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.trade-card-fav');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const itemId = btn.dataset.itemId;
    const icon = btn.querySelector('i');

    const formData = new FormData();
    formData.append('action', 'toggle_favorite');
    formData.append('item_id', itemId);

    fetch('trending.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) {
          if (data.message) swapifyToast(data.message, 'danger');
          return;
        }

        btn.classList.toggle('active', data.active);
        icon.classList.toggle('bi-heart', !data.active);
        icon.classList.toggle('bi-heart-fill', data.active);

        bumpVisibleLikeCount(btn, data.active ? 1 : -1);
      })
      .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
  });
}

/* ---------- Whole-card click-through (delegated) ---------- */
function wireCardNavigation() {
  document.addEventListener('click', function (e) {
    if (e.target.closest('.trade-card-fav')) return; // handled by wireFavoriteButtons
    if (e.target.closest('a')) return; // let real links (title, "View item") behave normally

    const card = e.target.closest('.trade-card[data-listing-id]');
    if (!card) return;

    window.location.href = 'items_details.php?id=' + card.dataset.listingId;
  });
}

/* ---------- Category pills -> AJAX swap ---------- */
function wireCategoryPills() {
  const scroll = document.getElementById('categoryScroll');
  if (!scroll) return;

  scroll.addEventListener('click', function (e) {
    const pill = e.target.closest('.category-pill');
    if (!pill) return;

    e.preventDefault();
    loadTrendingResults(pill.getAttribute('href'), true);
  });
}

/* ---------- Browser back/forward after a pushState swap ---------- */
function wireBackForward() {
  window.addEventListener('popstate', function () {
    loadTrendingResults(window.location.href, false);
  });
}

function loadTrendingResults(url, updateHistory) {
  const results = document.getElementById('trendingResults');
  const scroll = document.getElementById('categoryScroll');
  if (!results) return;

  fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then((res) => res.json())
    .then((data) => {
      if (!data.success) {
        swapifyToast('Could not load that category. Please try again.', 'danger');
        return;
      }

      results.innerHTML = data.resultsHtml;

      if (scroll) {
        const activeCategory = new URL(url, window.location.origin).searchParams.get('category') || '';
        scroll.querySelectorAll('.category-pill').forEach(function (p) {
          const pillCategory = new URL(p.href, window.location.origin).searchParams.get('category') || '';
          p.classList.toggle('active', pillCategory === activeCategory);
        });
      }

      if (updateHistory) {
        history.pushState({}, '', url);
      }
    })
    .catch(() => swapifyToast('Could not load that category. Please try again.', 'danger'));
}

/* ---------- Cosmetic-only: bump the "N likes" text next to this card ---------- */
function bumpVisibleLikeCount(favBtn, delta) {
  const card = favBtn.closest('.trade-card');
  if (!card) return;

  const likeEls = card.querySelectorAll('.text-muted-swap');
  likeEls.forEach(function (el) {
    if (!el.querySelector('.bi-heart-fill')) return; // only the like-count span, not views/location

    const match = el.textContent.match(/(\d+)/);
    if (!match) return;

    const newCount = Math.max(0, parseInt(match[1], 10) + delta);
    el.innerHTML = el.innerHTML.replace(/\d+/, newCount);
  });
}