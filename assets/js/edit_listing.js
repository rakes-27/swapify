/**
 * Swapify — edit-listing.js
 * Same interaction model as create-listing.js (dropzone, tags, live preview,
 * validation) but pre-fills the preview from existing values on load,
 * submits to /api/update-item.php, and adds a delete-with-confirmation flow.
 */

document.addEventListener('DOMContentLoaded', function () {
  initDropzone();
  initTagInput();
  initLivePreview();
  updateLivePreview(); // pre-fill preview from existing values
  initListingForm();
  initDeleteListing();
});

/* ---------- Drag & drop + click-to-upload (existing images shown pre-loaded) ---------- */
let uploadedImages = [];

function initDropzone() {
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('fileInput');
  const previewGrid = document.getElementById('imagePreviewGrid');
  if (!dropzone || !fileInput) return;

  // Seed with the item's existing photos (placeholder icons standing in for real URLs).
  document.querySelectorAll('.existing-image-thumb').forEach((el) => {
    uploadedImages.push({ id: el.dataset.id, existing: true });
  });
  wireRemoveButtons();

  dropzone.addEventListener('click', () => fileInput.click());
  ['dragenter', 'dragover'].forEach((evt) => {
    dropzone.addEventListener(evt, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
  });
  ['dragleave', 'drop'].forEach((evt) => {
    dropzone.addEventListener(evt, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
  });
  dropzone.addEventListener('drop', (e) => handleFiles(e.dataTransfer.files));
  fileInput.addEventListener('change', (e) => handleFiles(e.target.files));

  function handleFiles(fileList) {
    Array.from(fileList).forEach((file) => {
      if (!file.type.startsWith('image/')) return;
      const reader = new FileReader();
      reader.onload = (e) => {
        const id = 'img-' + Date.now() + Math.random().toString(16).slice(2);
        uploadedImages.push({ id, src: e.target.result });
        const thumb = document.createElement('div');
        thumb.className = 'image-preview-thumb';
        thumb.style.backgroundImage = `url(${e.target.result})`;
        thumb.dataset.id = id;
        thumb.innerHTML = `<button type="button" class="image-preview-remove" data-id="${id}"><i class="bi bi-x"></i></button>`;
        previewGrid.appendChild(thumb);
        wireRemoveButtons();
      };
      reader.readAsDataURL(file);
    });
  }

  function wireRemoveButtons() {
    previewGrid.querySelectorAll('.image-preview-remove').forEach((btn) => {
      btn.onclick = function () {
        uploadedImages = uploadedImages.filter((img) => img.id !== this.dataset.id);
        this.closest('.image-preview-thumb').remove();
      };
    });
  }
}

/* ---------- Tag input ---------- */
function initTagInput() {
  const wrap = document.getElementById('tagInputWrap');
  const input = document.getElementById('tagTextInput');
  if (!wrap || !input) return;
  const tags = Array.from(wrap.querySelectorAll('.tag-pill')).map((p) => p.dataset.tag);

  wireExistingTagRemovals();
  input.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ',') && this.value.trim()) {
      e.preventDefault();
      addTag(this.value.trim());
      this.value = '';
    }
  });

  function wireExistingTagRemovals() {
    wrap.querySelectorAll('.tag-pill button').forEach((btn) => {
      btn.onclick = () => btn.closest('.tag-pill').remove();
    });
  }

  function addTag(text) {
    if (tags.includes(text)) return;
    tags.push(text);
    const pill = document.createElement('span');
    pill.className = 'tag-pill';
    pill.dataset.tag = text;
    pill.innerHTML = `${text} <button type="button" aria-label="Remove tag"><i class="bi bi-x"></i></button>`;
    pill.querySelector('button').addEventListener('click', () => {
      tags.splice(tags.indexOf(text), 1);
      pill.remove();
    });
    wrap.insertBefore(pill, input);
  }
}

/* ---------- Live preview card ---------- */
function initLivePreview() {
  const ids = ['listingTitle', 'listingLookingFor', 'listingCondition', 'listingLocation'];
  ids.forEach((id) => {
    const el = document.getElementById(id);
    if (el) { el.addEventListener('input', updateLivePreview); el.addEventListener('change', updateLivePreview); }
  });
}

function updateLivePreview() {
  const title = document.getElementById('listingTitle');
  const forField = document.getElementById('listingLookingFor');
  const condition = document.getElementById('listingCondition');
  const location = document.getElementById('listingLocation');

  document.getElementById('previewTitle').textContent = title.value.trim() || 'Your item title';
  document.getElementById('previewFor').textContent = forField.value.trim() || 'Anything interesting';
  document.getElementById('previewCondition').textContent = condition.value || 'Condition';
  document.getElementById('previewLocation').textContent = location.value.trim() || 'Location';
}

/* ---------- Form validation + AJAX submit ---------- */
function initListingForm() {
  const form = document.getElementById('listingForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    let valid = true;

    ['listingTitle', 'listingDescription', 'listingCategory', 'listingCondition', 'listingLookingFor', 'listingLocation'].forEach((id) => {
      const field = document.getElementById(id);
      const isInvalid = !field.value || !field.value.trim();
      field.classList.toggle('is-invalid', isInvalid);
      if (isInvalid) valid = false;
    });

    if (!uploadedImages.length) {
      document.getElementById('dropzoneError').classList.remove('d-none');
      valid = false;
    } else {
      document.getElementById('dropzoneError').classList.add('d-none');
    }

    if (!valid) return;

    const btn = document.getElementById('listingSubmitBtn');
    btn.disabled = true;
    btn.querySelector('.spinner-border').classList.remove('d-none');
    btn.querySelector('.btn-label').textContent = 'Saving changes...';

    // Placeholder endpoint — backend not implemented yet.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/update-item.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
      btn.disabled = false;
      btn.querySelector('.spinner-border').classList.add('d-none');
      btn.querySelector('.btn-label').textContent = 'Save changes';
      swapifyToast('Listing updated successfully!', 'success');
    };
    xhr.onerror = function () {
      btn.disabled = false;
      btn.querySelector('.spinner-border').classList.add('d-none');
      btn.querySelector('.btn-label').textContent = 'Save changes';
      swapifyToast('Something went wrong. Please try again.', 'danger');
    };
    xhr.send(JSON.stringify({
      itemId: form.dataset.itemId,
      title: document.getElementById('listingTitle').value.trim(),
      description: document.getElementById('listingDescription').value.trim(),
      category: document.getElementById('listingCategory').value,
      condition: document.getElementById('listingCondition').value,
      lookingFor: document.getElementById('listingLookingFor').value.trim(),
      location: document.getElementById('listingLocation').value.trim(),
      imageCount: uploadedImages.length,
    }));
  });
}

/* ---------- Delete listing (confirm modal already in the page) ---------- */
function initDeleteListing() {
  const confirmBtn = document.getElementById('confirmDeleteBtn');
  if (!confirmBtn) return;

  confirmBtn.addEventListener('click', function () {
    const form = document.getElementById('listingForm');

    // Placeholder endpoint — backend not implemented yet.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/delete-item.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
      swapifyToast('Listing deleted.', 'secondary');
      // window.location.href = '/dashboard.php';
    };
    xhr.send(JSON.stringify({ itemId: form.dataset.itemId }));

    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
  });
}