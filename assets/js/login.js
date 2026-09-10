/**
 * Swapify — login.js
 * Password toggle, client-side validation, and an AJAX-ready submit
 * handler that posts to the placeholder /api/login.php endpoint.
 */
console.log('login.js loaded');

document.addEventListener('DOMContentLoaded', function () {
  initPasswordToggle();
  initLoginForm();
});

/* ---------- Show/hide password ---------- */
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

/* ---------- Login form: validate + AJAX submit ---------- */
function initLoginForm() {
  const form = document.getElementById('loginForm');
  if (!form) return;

  const emailInput = document.getElementById('loginEmail');
  const passwordInput = document.getElementById('loginPassword');
  const submitBtn = document.getElementById('loginSubmitBtn');
  const spinner = submitBtn.querySelector('.spinner-border');
  const btnLabel = submitBtn.querySelector('.btn-label');

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    form.classList.add('was-validated');

    let valid = true;

    if (!emailInput.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) {
      emailInput.classList.add('is-invalid');
      valid = false;
    } else {
      emailInput.classList.remove('is-invalid');
    }

    if (!passwordInput.value || passwordInput.value.length < 6) {
      passwordInput.classList.add('is-invalid');
      valid = false;
    } else {
      passwordInput.classList.remove('is-invalid');
    }

    if (!valid) return;

    submitLogin(emailInput.value.trim(), passwordInput.value, submitBtn, spinner, btnLabel);
  });
}

function submitLogin(email, password, submitBtn, spinner, btnLabel) {
  submitBtn.disabled = true;
  spinner.classList.remove('d-none');
  btnLabel.textContent = 'Signing in...';

  const form = document.getElementById('loginForm');
  const formData = new FormData(form);

  const xhr = new XMLHttpRequest();

  xhr.open('POST', 'login.php', true);

  xhr.onload = function () {
    submitBtn.disabled = false;
    spinner.classList.add('d-none');
    btnLabel.textContent = 'Log in';

    if (xhr.status === 200) {

      console.log(xhr.responseText);

      const response = JSON.parse(xhr.responseText);

      if (response.success) {

        swapifyToast(
          'Logged in successfully. Redirecting...',
          'success'
        );

        setTimeout(() => {
          window.location.href = 'dashboard.php';
        }, 1000);

      } else {

        swapifyToast(
          response.message,
          'danger'
        );

      }

    } else {

      swapifyToast(
        'Login failed. Please try again.',
        'danger'
      );

    }
  };

  xhr.onerror = function () {
    submitBtn.disabled = false;
    spinner.classList.add('d-none');
    btnLabel.textContent = 'Log in';

    swapifyToast(
      'Something went wrong. Please try again.',
      'danger'
    );
  };

  xhr.send(formData);
}