/**
 * Swapify — create-listing.js
 * Drag & drop image upload with previews, tag chips, a live preview card
 * that mirrors form input, client validation, and an AJAX submit via
 * FormData (multipart) to /api/create-item.php.
 */

document.addEventListener('DOMContentLoaded', function () {
  initDropzone();
  initTagInput();
  initLivePreview();
  initListingForm();
});

/* ---------- Drag & drop + click-to-upload ---------- */
let uploadedImages = []; // { id, src (base64 preview), file (actual File object) }

function initDropzone() {
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('fileInput');
  const previewGrid = document.getElementById('imagePreviewGrid');
  if (!dropzone || !fileInput) return;

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
        uploadedImages.push({ id, src: e.target.result, file });
        renderPreviews();
        updateMainPreviewImage();
      };
      reader.readAsDataURL(file);
    });
  }

  function renderPreviews() {
    previewGrid.innerHTML = '';
    uploadedImages.forEach((img) => {
      const thumb = document.createElement('div');
      thumb.className = 'image-preview-thumb';
      thumb.style.backgroundImage = `url(${img.src})`;
      thumb.innerHTML = `<button type="button" class="image-preview-remove" data-id="${img.id}"><i class="bi bi-x"></i></button>`;
      previewGrid.appendChild(thumb);
    });

    previewGrid.querySelectorAll('.image-preview-remove').forEach((btn) => {
      btn.addEventListener('click', function () {
        uploadedImages = uploadedImages.filter((img) => img.id !== this.dataset.id);
        renderPreviews();
        updateMainPreviewImage();
      });
    });
  }
}

/* ---------- Tag input ---------- */
let currentTags = [];

function initTagInput() {
  const wrap = document.getElementById('tagInputWrap');
  const input = document.getElementById('tagTextInput');
  if (!wrap || !input) return;

  input.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ',') && this.value.trim()) {
      e.preventDefault();
      addTag(this.value.trim());
      this.value = '';
    }
  });

  function addTag(text) {
    if (currentTags.includes(text)) return;
    currentTags.push(text);
    const pill = document.createElement('span');
    pill.className = 'tag-pill';
    pill.innerHTML = `${text} <button type="button" aria-label="Remove tag"><i class="bi bi-x"></i></button>`;
    pill.querySelector('button').addEventListener('click', () => {
      currentTags = currentTags.filter((t) => t !== text);
      pill.remove();
    });
    wrap.insertBefore(pill, input);
  }
}

/* ---------- Live preview card ---------- */
function initLivePreview() {
  const titleInput = document.getElementById('listingTitle');
  const forInput = document.getElementById('listingLookingFor');
  const conditionSelect = document.getElementById('listingCondition');
  const locationInput = document.getElementById('listingLocation');

  [titleInput, forInput, conditionSelect, locationInput].forEach((el) => {
    if (el) el.addEventListener('input', updateLivePreview);
    if (el) el.addEventListener('change', updateLivePreview);
  });
}

function updateLivePreview() {
  const title = document.getElementById('listingTitle');
  const forField = document.getElementById('listingLookingFor');
  const condition = document.getElementById('listingCondition');
  const location = document.getElementById('listingLocation');

  const previewTitle = document.getElementById('previewTitle');
  const previewFor = document.getElementById('previewFor');
  const previewCondition = document.getElementById('previewCondition');
  const previewLocation = document.getElementById('previewLocation');

  if (previewTitle) previewTitle.textContent = title.value.trim() || 'Your item title';
  if (previewFor) previewFor.textContent = forField.value.trim() || 'Anything interesting';
  if (previewCondition) previewCondition.textContent = condition.value || 'Condition';
  if (previewLocation) previewLocation.textContent = location.value.trim() || 'Location';
}

function updateMainPreviewImage() {
  const previewIcon = document.getElementById('previewImageIcon');
  if (!previewIcon) return;

  const previewBox = previewIcon.closest('.trade-card-img');
  if (!previewBox) return;

  if (uploadedImages.length) {
    previewBox.style.backgroundImage = `url(${uploadedImages[0].src})`;
    previewBox.style.backgroundSize = 'cover';
    previewBox.style.backgroundPosition = 'center';
    previewIcon.classList.add('d-none');
  } else {
    previewBox.style.backgroundImage = '';
    previewIcon.classList.remove('d-none');
  }
}

/* ---------- Form validation + AJAX submit (FormData) ---------- */
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
    btn.querySelector('.btn-label').textContent = 'Publishing...';

    // Build multipart form data so actual image files are sent, not just their count.
    const formData = new FormData();
    formData.append('title', document.getElementById('listingTitle').value.trim());
    formData.append('description', document.getElementById('listingDescription').value.trim());
    formData.append('category', document.getElementById('listingCategory').value);
    formData.append('condition', document.getElementById('listingCondition').value);
    formData.append('looking_for', document.getElementById('listingLookingFor').value.trim());
    formData.append('location', document.getElementById('listingLocation').value.trim());
    formData.append('tags', currentTags.join(','));

    uploadedImages.forEach((img) => {
      formData.append('images[]', img.file, img.file.name);
    });

    const xhr = new XMLHttpRequest();
   xhr.open('POST', 'create_listing.php', true);
    // Do NOT set Content-Type manually — the browser sets the correct
    // multipart/form-data boundary automatically for FormData bodies.

    xhr.onload = function () {
      btn.disabled = false;
      btn.querySelector('.spinner-border').classList.add('d-none');
      btn.querySelector('.btn-label').textContent = 'Publish listing';

      let response = {};
      try {
        response = JSON.parse(xhr.responseText);
      } catch (err) {
        swapifyToast('Unexpected server response.', 'danger');
        return;
      }

      if (xhr.status >= 200 && xhr.status < 300 && response.success) {
        swapifyToast(response.message || 'Listing published successfully!', 'success');
        form.reset();
        uploadedImages = [];
        currentTags = [];
        document.getElementById('imagePreviewGrid').innerHTML = '';
        updateMainPreviewImage();
      } else {
        swapifyToast(response.message || 'Something went wrong. Please try again.', 'danger');
      }
    };

    xhr.onerror = function () {
      btn.disabled = false;
      btn.querySelector('.spinner-border').classList.add('d-none');
      btn.querySelector('.btn-label').textContent = 'Publish listing';
      swapifyToast('Something went wrong. Please try again.', 'danger');
    };

    xhr.send(formData);
  });
}