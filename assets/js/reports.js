/**
 * Swapify — reports.js
 * Drives the "Report something" modal: reason selection, description with
 * char counter, and an AJAX-ready submit to a placeholder /api/report.php.
 */

document.addEventListener('DOMContentLoaded', function () {
  initReportTypeSwitch();
  initReportCharCounter();
  initReportSubmit();
});

/* ---------- Target placeholder changes with report type ---------- */
function initReportTypeSwitch() {
  const typeSelect = document.getElementById('reportType');
  const targetInput = document.getElementById('reportTarget');
  if (!typeSelect || !targetInput) return;

  const placeholders = {
    listing: 'e.g. Canon AE-1 Film Camera',
    user: 'e.g. username or trader name',
    comment: 'Paste the comment text or link',
  };
  typeSelect.addEventListener('change', function () {
    targetInput.placeholder = placeholders[this.value] || 'What are you reporting?';
  });
}

/* ---------- Character counter ---------- */
function initReportCharCounter() {
  const input = document.getElementById('reportDescription');
  const counter = document.getElementById('reportDescCounter');
  if (!input || !counter) return;
  input.addEventListener('input', function () {
    counter.textContent = `${this.value.length} / 500`;
  });
}

/* ---------- Submit ---------- */
function initReportSubmit() {
  const btn = document.getElementById('submitNewReportBtn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    const type = document.getElementById('reportType').value;
    const target = document.getElementById('reportTarget').value.trim();
    const reason = document.getElementById('reportReason').value;
    const description = document.getElementById('reportDescription').value.trim();

    if (!target) {
      document.getElementById('reportTarget').classList.add('is-invalid');
      return;
    }
    document.getElementById('reportTarget').classList.remove('is-invalid');

    // Placeholder endpoint — backend not implemented yet.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/report.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.send(JSON.stringify({ type, target, reason, description }));

    bootstrap.Modal.getInstance(document.getElementById('newReportModal')).hide();
    swapifyToast('Report submitted. Our team will review it shortly.', 'success');
  });
}