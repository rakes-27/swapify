/**
 * Swapify — reviews.js
 * Interactive star picker in the "write a review" modal, char counter,
 * and a real submit via FormData to this same page (reviews.php,
 * action=submit_review) — no separate API endpoint. On success, the
 * new review is prepended to the grid and the summary stats refresh
 * by reloading the page (simplest way to keep the average/distribution
 * correct without duplicating that math in JS).
 */

document.addEventListener('DOMContentLoaded', function () {
  initStarInput();
  initReviewCharCounter();
  initReviewSubmit();
});

/* ---------- Star rating input ---------- */
let selectedRating = 0;

function initStarInput() {
  const wrap = document.getElementById('starRatingInput');
  const stars = document.querySelectorAll('.star-rating-input i');
  if (!stars.length || !wrap) return;

  stars.forEach((star) => {
    star.addEventListener('mouseenter', () => paintStars(parseInt(star.dataset.value, 10)));
    star.addEventListener('click', () => {
      selectedRating = parseInt(star.dataset.value, 10);
      paintStars(selectedRating);
    });
  });

  wrap.addEventListener('mouseleave', () => paintStars(selectedRating));

  function paintStars(count) {
    stars.forEach((s) => {
      const val = parseInt(s.dataset.value, 10);
      s.classList.toggle('bi-star-fill', val <= count);
      s.classList.toggle('bi-star', val > count);
    });
  }
}

/* ---------- Character counter ---------- */
function initReviewCharCounter() {
  const input = document.getElementById('reviewText');
  const counter = document.getElementById('reviewCharCounter');
  if (!input || !counter) return;
  input.addEventListener('input', function () {
    counter.textContent = `${this.value.length} / 400`;
  });
}

/* ---------- Submit (FormData + fetch POST to this same page) ---------- */
function initReviewSubmit() {
  const btn = document.getElementById('submitReviewBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const tradeSelect = document.getElementById('reviewTradeSelect');
    const text = document.getElementById('reviewText').value.trim();

    if (!tradeSelect || !tradeSelect.value) {
      swapifyToast('Please choose which trade this review is for.', 'danger');
      return;
    }
    if (selectedRating === 0) {
      swapifyToast('Please select a star rating.', 'danger');
      return;
    }

    const originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Submitting...';

    const formData = new FormData();
    formData.append('action', 'submit_review');
    formData.append('trade_request_id', tradeSelect.value);
    formData.append('rating', selectedRating);
    formData.append('comment', text);

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
          swapifyToast(data.message || 'Could not submit review.', 'danger');
          return;
        }

        bootstrap.Modal.getInstance(document.getElementById('writeReviewModal')).hide();
        swapifyToast(data.message || 'Review submitted — thank you!', 'success');

        // Reload so the summary stats (average, distribution, review list,
        // and "already reviewed" eligibility) all reflect the new review.
        setTimeout(() => window.location.reload(), 600);
      })
      .catch(() => {
        btn.disabled = false;
        btn.textContent = originalLabel;
        swapifyToast('Something went wrong. Please try again.', 'danger');
      });
  });
}