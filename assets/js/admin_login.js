/**
 * Swapify Admin — admin_login.js
 * Password toggle, client-side validation, and real submit against
 * admin/login.php (fetch + FormData, no /api).
 */

document.addEventListener('DOMContentLoaded', function () {
  initPasswordToggle();
  initAdminLoginForm();
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

function initAdminLoginForm() {
  const form = document.getElementById('adminLoginForm');
  if (!form) return;

  const emailInput = document.getElementById('adminEmail');
  const passwordInput = document.getElementById('adminPassword');
  const submitBtn = document.getElementById('adminLoginSubmitBtn');
  const spinner = submitBtn.querySelector('.spinner-border');
  const btnLabel = submitBtn.querySelector('.btn-label');
  const errorBox = document.getElementById('adminLoginError');

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim());
    const passwordValid = passwordInput.value.length >= 6;
    emailInput.classList.toggle('is-invalid', !emailValid);
    passwordInput.classList.toggle('is-invalid', !passwordValid);
    if (!emailValid || !passwordValid) return;

    if (errorBox) errorBox.classList.add('d-none');
    submitBtn.disabled = true;
    spinner.classList.remove('d-none');
    btnLabel.textContent = 'Signing in...';

    const formData = new FormData();
    formData.append('email', emailInput.value.trim());
    formData.append('password', passwordInput.value);

    fetch('login.php', { method: 'POST', body: formData })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          swapifyToast('Signed in. Redirecting to dashboard...', 'success');
          window.location.href = data.redirect;
          return;
        }

        submitBtn.disabled = false;
        spinner.classList.add('d-none');
        btnLabel.textContent = 'Sign in';

        if (errorBox) {
          errorBox.textContent = data.message;
          errorBox.classList.remove('d-none');
        } else {
          swapifyToast(data.message, 'danger');
        }
      })
      .catch(() => {
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
        btnLabel.textContent = 'Sign in';
        swapifyToast('Something went wrong. Please try again.', 'danger');
      });
  });
}