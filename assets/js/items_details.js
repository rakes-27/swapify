/**
 * Swapify — item-details.js
 * Thumbnail gallery switching (real photos), click-to-zoom on the main
 * image, favorite toggle (FormData POST to this same page), and a share
 * button that copies the page link.
 */

document.addEventListener('DOMContentLoaded', function () {
  initGalleryThumbs();
  initZoom();
  initFavoriteToggle();
  initShareButton();
  initTradeRequest();
});

/* ---------- Thumbnail gallery (swaps the main <img> src) ---------- */
function initGalleryThumbs() {
  const thumbs = document.querySelectorAll('.gallery-thumb[data-image]');
  const mainContainer = document.getElementById('galleryMain');
  if (!thumbs.length || !mainContainer) return;

  thumbs.forEach((thumb) => {
    thumb.addEventListener('click', function () {
      thumbs.forEach((t) => t.classList.remove('active'));
      this.classList.add('active');

      let mainImg = mainContainer.querySelector('img');
      if (!mainImg) {
        mainContainer.innerHTML = '';
        mainImg = document.createElement('img');
        mainImg.style.width = '100%';
        mainImg.style.height = '100%';
        mainImg.style.objectFit = 'cover';
        mainContainer.appendChild(mainImg);
      }
      mainImg.src = this.dataset.image;
    });
  });
}

/* ---------- Click-to-zoom on main image ---------- */
function initZoom() {
  const main = document.getElementById('galleryMain');
  if (!main) return;
  main.addEventListener('click', function () {
    this.classList.toggle('zoomed');
  });
}

/* ---------- Favorite toggle (FormData POST to this same page) ---------- */
function initFavoriteToggle() {
  const btn = document.getElementById('favoriteBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const icon = this.querySelector('i');
    const itemId = this.dataset.itemId;
    const willBeActive = !this.classList.contains('active');

    // Optimistic UI update — corrected below if the server disagrees.
    this.classList.toggle('active', willBeActive);
    icon.classList.toggle('bi-heart', !willBeActive);
    icon.classList.toggle('bi-heart-fill', willBeActive);

    const formData = new FormData();
    formData.append('action', 'toggle_favorite');
    formData.append('item_id', itemId);

    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) {
          revertFavoriteUI(btn, icon, willBeActive);
          swapifyToast(data.message || 'Something went wrong.', 'danger');
          return;
        }
        swapifyToast(data.active ? 'Added to your wishlist' : 'Removed from wishlist', data.active ? 'primary' : 'secondary');
      })
      .catch(() => {
        revertFavoriteUI(btn, icon, willBeActive);
        swapifyToast('Something went wrong. Please try again.', 'danger');
      });
  });
}

function revertFavoriteUI(btn, icon, wasActive) {
  btn.classList.toggle('active', !wasActive);
  icon.classList.toggle('bi-heart', wasActive);
  icon.classList.toggle('bi-heart-fill', !wasActive);
}

/* ---------- Share button ---------- */
function initShareButton() {
  const btn = document.getElementById('shareBtn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    const url = window.location.href;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(() => swapifyToast('Link copied to clipboard', 'success'));
    } else {
      swapifyToast('Link: ' + url, 'secondary');
    }
  });
}

/* ---------- Trade request submit (FormData POST to this same page) ---------- */
function initTradeRequest() {
  const btn = document.getElementById('sendTradeRequestBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const offerSelect = document.getElementById('tradeOfferSelect');
    const messageField = document.getElementById('tradeMessage');
    const targetListingId = document.getElementById('tradeTargetListingId').value;

    if (!offerSelect.value) {
      swapifyToast('Please choose an item to offer.', 'danger');
      return;
    }

    const originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sending...';

    const formData = new FormData();
    formData.append('action', 'create_trade_request');
    formData.append('target_listing_id', targetListingId);
    formData.append('offered_listing_id', offerSelect.value);
    formData.append('message', messageField.value.trim());

    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then((res) => res.json())
      .then((data) => {
        btn.disabled = false;
        btn.textContent = originalLabel;

        if (!data.success) {
          swapifyToast(data.message || 'Something went wrong.', 'danger');
          return;
        }

        swapifyToast(data.message || 'Trade request sent!', 'success');
        offerSelect.value = '';
        messageField.value = '';

        const modalEl = document.getElementById('tradeModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) modalInstance.hide();
      })
      .catch(() => {
        btn.disabled = false;
        btn.textContent = originalLabel;
        swapifyToast('Something went wrong. Please try again.', 'danger');
      });
  });
}