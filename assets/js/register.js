/**
 * Swapify — register.js
 * Password toggle + strength meter, profile picture preview,
 * client-side validation, AJAX-ready submit to /api/register.php.
 */

document.addEventListener('DOMContentLoaded', function () {
  initPasswordToggle();
  initStrengthMeter();
  initAvatarPreview();
  initRegisterForm();
});

function initPasswordToggle() {
  document.querySelectorAll('.auth-input-toggle').forEach((btn) => {
    btn.addEventListener('click', function () {
      const input = document.getElementById(this.dataset.target);
      const icon = this.querySelector('i');
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      icon.classList.toggle('bi-eye', !isPassword);
      icon.classList.toggle('bi-eye-slash', isPassword);
    });
  });
}

/* ---------- Password strength meter ---------- */
function initStrengthMeter() {
  const input = document.getElementById('regPassword');
  const fill = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  if (!input) return;

  input.addEventListener('input', function () {
    const score = scorePassword(this.value);
    const levels = [
      { pct: 0, color: '#e9ecef', text: '' },
      { pct: 25, color: '#dc3545', text: 'Weak' },
      { pct: 50, color: '#ff8a3d', text: 'Fair' },
      { pct: 75, color: '#ffc107', text: 'Good' },
      { pct: 100, color: '#28a745', text: 'Strong' },
    ];
    const lvl = levels[score];
    fill.style.width = lvl.pct + '%';
    fill.style.backgroundColor = lvl.color;
    label.textContent = lvl.text;
  });
}

function scorePassword(pwd) {
  let score = 0;
  if (pwd.length >= 6) score++;
  if (pwd.length >= 10) score++;
  if (/[A-Z]/.test(pwd) && /[0-9]/.test(pwd)) score++;
  if (/[^A-Za-z0-9]/.test(pwd)) score++;
  return Math.min(score, 4);
}

/* ---------- Profile picture preview ---------- */
function initAvatarPreview() {
  const fileInput = document.getElementById('avatarInput');
  const preview = document.getElementById('avatarPreview');
  if (!fileInput) return;

  fileInput.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
      preview.style.backgroundImage = `url(${e.target.result})`;
      preview.innerHTML = '';
    };
    reader.readAsDataURL(file);
  });
}

/* ---------- Register form: validate + AJAX submit ---------- */
function initRegisterForm() {
  const form = document.getElementById('registerForm');
  if (!form) return;

  const fields = {
    name: document.getElementById('regName'),
    username: document.getElementById('regUsername'),
    email: document.getElementById('regEmail'),
    phone: document.getElementById('regPhone'),
    password: document.getElementById('regPassword'),
    confirm: document.getElementById('regConfirm'),
    terms: document.getElementById('regTerms'),
  };
  const submitBtn = document.getElementById('registerSubmitBtn');
  const spinner = submitBtn.querySelector('.spinner-border');
  const btnLabel = submitBtn.querySelector('.btn-label');

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    let valid = true;

    toggleInvalid(fields.name, fields.name.value.trim().length < 2);
    toggleInvalid(fields.username, !/^[a-zA-Z0-9_]{3,}$/.test(fields.username.value.trim()));
    toggleInvalid(fields.email, !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(fields.email.value.trim()));
    toggleInvalid(fields.phone, fields.phone.value.trim().length < 7);
    toggleInvalid(fields.password, fields.password.value.length < 6);
    toggleInvalid(fields.confirm, fields.confirm.value !== fields.password.value || !fields.confirm.value);

    [fields.name, fields.username, fields.email, fields.phone, fields.password, fields.confirm].forEach((f) => {
      if (f.classList.contains('is-invalid')) valid = false;
    });

    if (!fields.terms.checked) {
      fields.terms.classList.add('is-invalid');
      valid = false;
    } else {
      fields.terms.classList.remove('is-invalid');
    }

    if (!valid) return;
    submitRegister(fields, submitBtn, spinner, btnLabel);
  });
}

function toggleInvalid(field, isInvalid) {
  field.classList.toggle('is-invalid', isInvalid);
}

function submitRegister(fields, submitBtn, spinner, btnLabel) {
  submitBtn.disabled = true;
  spinner.classList.remove('d-none');
  btnLabel.textContent = 'Creating account...';

  const form = document.getElementById('registerForm');
  const formData = new FormData(form);

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'register.php', true);

  xhr.onload = function () {
    submitBtn.disabled = false;
    spinner.classList.add('d-none');
    btnLabel.textContent = 'Create account';

    if (xhr.status === 200) {
      console.log(xhr.responseText);
      const response = JSON.parse(xhr.responseText);

      if (response.success) {

        swapifyToast('Account created! Redirecting to login...', 'success');

        setTimeout(() => {
          window.location.href = 'login.php';
        }, 1500);

      } else {

        swapifyToast(response.message, 'danger');

      }

    } else {

      swapifyToast('Registration failed. Please try again.', 'danger');

    }
  };

  xhr.onerror = function () {
    submitBtn.disabled = false;
    spinner.classList.add('d-none');
    btnLabel.textContent = 'Create account';

    swapifyToast('Something went wrong. Please try again.', 'danger');
  };

  xhr.send(formData);
}