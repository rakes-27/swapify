/**
 * Swapify Admin — admin_categories.js
 * Full CRUD against categories.php. No optimistic row insertion —
 * every action waits for server confirmation, then reloads, so the
 * table can never drift from the real DB state.
 */

document.addEventListener('DOMContentLoaded', function () {
  initAddCategory();
  initEditCategory();
  initDeleteCategory();
});

/* ---------- Add / Edit (shared modal + save button) ---------- */
function initAddCategory() {
  const addBtn = document.getElementById('openAddCategoryBtn');
  const saveBtn = document.getElementById('saveCategoryBtn');
  if (!addBtn || !saveBtn) return;

  addBtn.addEventListener('click', function () {
    document.getElementById('categoryModalTitle').textContent = 'Add category';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryEditId').value = '';
    document.getElementById('categoryNameInput').classList.remove('is-invalid');
    saveBtn.dataset.mode = 'add';
  });

  saveBtn.addEventListener('click', function () {
    const nameInput = document.getElementById('categoryNameInput');
    const name = nameInput.value.trim();
    const icon = document.getElementById('categoryIconInput').value.trim() || 'bi-tag';

    if (!name) {
      nameInput.classList.add('is-invalid');
      return;
    }
    nameInput.classList.remove('is-invalid');

    const mode = this.dataset.mode;
    const formData = new FormData();
    formData.append('name', name);
    formData.append('icon', icon);

    if (mode === 'edit') {
      formData.append('action', 'update');
      formData.append('category_id', document.getElementById('categoryEditId').value);
    } else {
      formData.append('action', 'add');
    }

    fetch('categories.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide();
        if (data.success) {
          swapifyToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 500);
        } else {
          swapifyToast(data.message, 'danger');
        }
      })
      .catch(() => swapifyToast('Something went wrong.', 'danger'));
  });
}

/* ---------- Edit: pre-fill modal from the row ---------- */
function initEditCategory() {
  document.querySelectorAll('.edit-category-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      const row = this.closest('tr');
      document.getElementById('categoryModalTitle').textContent = 'Edit category';
      document.getElementById('categoryEditId').value = row.dataset.categoryId;
      document.getElementById('categoryNameInput').value = row.querySelector('.category-name-cell').textContent.trim();
      document.getElementById('categoryNameInput').classList.remove('is-invalid');
      document.getElementById('categoryIconInput').value = row.querySelector('.admin-mini-thumb i').className.replace('bi ', '').trim();
      document.getElementById('saveCategoryBtn').dataset.mode = 'edit';
      new bootstrap.Modal(document.getElementById('categoryModal')).show();
    });
  });
}

/* ---------- Delete (blocked server-side if listings still reference it) ---------- */
function initDeleteCategory() {
  let pendingCategoryId = null;
  const modalEl = document.getElementById('deleteCategoryModal');
  const modal = new bootstrap.Modal(modalEl);
  const confirmBtn = document.getElementById('confirmDeleteCategoryBtn');

  document.querySelectorAll('.delete-category-btn').forEach((btn) => {
    btn.addEventListener('click', function () {
      const row = this.closest('tr');
      pendingCategoryId = row.dataset.categoryId;
      document.getElementById('deleteCategoryName').textContent = row.querySelector('.category-name-cell').textContent.trim();
      modal.show();
    });
  });

  confirmBtn.addEventListener('click', function () {
    if (!pendingCategoryId) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('category_id', pendingCategoryId);

    fetch('categories.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        modal.hide();
        if (data.success) {
          swapifyToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 500);
        } else {
          swapifyToast(data.message, 'danger');
        }
      })
      .catch(() => {
        modal.hide();
        swapifyToast('Something went wrong.', 'danger');
      });
  });
}