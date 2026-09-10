/**
 * Swapify — index.js
 * Stat counters, hero search, and real wishlist toggling for the
 * featured/trending cards on the landing page. Category pills now
 * navigate for real (the old handler called preventDefault on every
 * click, which silently blocked their hrefs from ever working).
 */

document.addEventListener('DOMContentLoaded', function () {
  initStatCounters();
  initHeroSearchForm();
  initWishlistButtons();
});

/* ---------- Animated stats (Users, Trades, Items, Cities) ---------- */
function initStatCounters() {
  const counters = document.querySelectorAll('[data-count-to]');
  if (!counters.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        animateCount(entry.target);
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.4 });

  counters.forEach((el) => observer.observe(el));
}

function animateCount(el) {
  const target = parseInt(el.dataset.countTo, 10);
  const suffix = el.dataset.suffix || '';
  const duration = 1200;
  const start = performance.now();

  function tick(now) {
    const progress = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    el.textContent = Math.floor(eased * target).toLocaleString() + suffix;
    if (progress < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

/* ---------- Hero search: submit routes to marketplace.php with query ---------- */
function initHeroSearchForm() {
  const form = document.getElementById('heroSearchForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const query = document.getElementById('heroSearchInput').value.trim();
    window.location.href = 'marketplace.php' + (query ? `?q=${encodeURIComponent(query)}` : '');
  });
}

/* ---------- Wishlist heart: real toggle against index.php ---------- */
function initWishlistButtons() {
  document.querySelectorAll('.trade-card-fav').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const itemId = this.dataset.itemId;
      const icon = this.querySelector('i');

      const formData = new FormData();
      formData.append('action', 'toggle_favorite');
      formData.append('item_id', itemId);

      fetch('index.php', { method: 'POST', body: formData })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) {
            if (data.message) swapifyToast(data.message, 'danger');
            return;
          }
          this.classList.toggle('active', data.active);
          icon.classList.toggle('bi-heart', !data.active);
          icon.classList.toggle('bi-heart-fill', data.active);
        })
        .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
    });
  });
}