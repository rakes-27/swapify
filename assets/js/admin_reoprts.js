/**
 * Swapify Admin — admin_reports.js
 * Resolve/Dismiss both POST to this same page (reports.php), like the rest
 * of the app's action=... pattern. Report type (listing/user/comment) comes
 * from each row's data-report-type, since listing/user reports live in
 * `reports` (with real status tracking) while comment reports live in
 * `comment_reports` (no status column — resolve and dismiss both delete it).
 */

document.addEventListener('DOMContentLoaded', function () {
  initResolveButtons();
  initDeleteReportButtons();
});

function endpoint() {
  return window.location.pathname;
}

/* ---------- Resolve ---------- */
function initResolveButtons() {
  document.querySelectorAll('.resolve-report-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      const row = this.closest('tr');
      const reportId = row.dataset.reportId;
      const reportType = row.dataset.reportType; // 'listing' | 'user' | 'comment'
      const action = reportType === 'comment' ? 'resolve_comment_report' : 'resolve_report';

      const formData = new FormData();
      formData.append('action', action);
      formData.append('report_id', reportId);

      fetch(endpoint(), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((data) => {
          if (!data.success) {
            swapifyToast(data.message || 'Could not resolve report.', 'danger');
            return;
          }

          if (reportType === 'comment') {
            // comment_reports has no status to persist — resolving just removes it
            row.remove();
          } else {
            const badge = row.querySelector('.status-badge');
            badge.className = 'status-badge accepted';
            badge.textContent = 'Resolved';
            btn.remove();
          }

          swapifyToast('Report marked as resolved', 'success');
        })
        .catch(() => swapifyToast('Something went wrong. Please try again.', 'danger'));
    });
  });
}

/* ---------- Delete / Dismiss ---------- */
let pendingDeleteReportRow = null;

function initDeleteReportButtons() {
  document.querySelectorAll('.delete-report-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      pendingDeleteReportRow = this.closest('tr');
      new bootstrap.Modal(document.getElementById('deleteReportModal')).show();
    });
  });

  const confirmBtn = document.getElementById('confirmDeleteReportBtn');
  if (!confirmBtn) return;

  confirmBtn.addEventListener('click', function () {
    if (!pendingDeleteReportRow) return;

    const row = pendingDeleteReportRow;
    const reportId = row.dataset.reportId;
    const reportType = row.dataset.reportType;
    const action = reportType === 'comment' ? 'delete_comment_report' : 'delete_report';

    const formData = new FormData();
    formData.append('action', action);
    formData.append('report_id', reportId);

    fetch(endpoint(), {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then((res) => res.json())
      .then((data) => {
        bootstrap.Modal.getInstance(document.getElementById('deleteReportModal')).hide();

        if (!data.success) {
          swapifyToast(data.message || 'Could not dismiss report.', 'danger');
          pendingDeleteReportRow = null;
          return;
        }

        row.remove();
        swapifyToast('Report dismissed and removed', 'secondary');
        pendingDeleteReportRow = null;
      })
      .catch(() => {
        bootstrap.Modal.getInstance(document.getElementById('deleteReportModal')).hide();
        swapifyToast('Something went wrong. Please try again.', 'danger');
        pendingDeleteReportRow = null;
      });
  });
}