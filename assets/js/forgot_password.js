/**
 * Swapify — forgot-password.js
 * Drives a 4-step flow entirely client-side (email -> OTP -> reset -> success).
 * Each step posts to a placeholder endpoint; nothing here talks to a real backend yet.
 */

document.addEventListener('DOMContentLoaded', function () {
  initEmailStep();
  initOtpInputs();
  initOtpStep();
  initResetStep();
  initResendTimer();
  initPasswordToggle();
});

function goToStep(stepNum) {
  document.querySelectorAll('.auth-step').forEach((el) => el.classList.add('d-none'));
  document.getElementById('step' + stepNum).classList.remove('d-none');
}

/* ---------- Step 1: email ---------- */
function initEmailStep() {
  const form = document.getElementById('emailForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const emailInput = document.getElementById('fpEmail');
    const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim());
    emailInput.classList.toggle('is-invalid', !valid);
    if (!valid) return;

    const btn = document.getElementById('emailSubmitBtn');
    setLoading(btn, true, 'Sending code...');

    // Placeholder endpoint — backend not implemented yet.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/forgot-password.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
      setLoading(btn, false, 'Send reset code');
      document.getElementById('otpEmailDisplay').textContent = emailInput.value.trim();
      goToStep(2);
      startResendTimer();
    };
    xhr.onerror = function () { setLoading(btn, false, 'Send reset code'); };
    xhr.send(JSON.stringify({ email: emailInput.value.trim() }));
  });
}

/* ---------- OTP inputs: auto-advance / auto-back ---------- */
function initOtpInputs() {
  const inputs = document.querySelectorAll('.otp-input');
  inputs.forEach((input, idx) => {
    input.addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 1);
      if (this.value && inputs[idx + 1]) inputs[idx + 1].focus();
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !this.value && inputs[idx - 1]) inputs[idx - 1].focus();
    });
  });
}

/* ---------- Step 2: OTP verify ---------- */
function initOtpStep() {
  const form = document.getElementById('otpForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const inputs = document.querySelectorAll('.otp-input');
    const code = Array.from(inputs).map((i) => i.value).join('');

    if (code.length < 4) {
      inputs.forEach((i) => i.classList.add('is-invalid'));
      return;
    }
    inputs.forEach((i) => i.classList.remove('is-invalid'));

    const btn = document.getElementById('otpSubmitBtn');
    setLoading(btn, true, 'Verifying...');

    // Placeholder endpoint — backend not implemented yet.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/verify-otp.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
      setLoading(btn, false, 'Verify code');
      goToStep(3);
    };
    xhr.onerror = function () { setLoading(btn, false, 'Verify code'); };
    xhr.send(JSON.stringify({ code }));
  });
}

/* ---------- Step 3: reset password ---------- */
function initResetStep() {
  const form = document.getElementById('resetForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const pwd = document.getElementById('newPassword');
    const confirm = document.getElementById('confirmNewPassword');

    const pwdValid = pwd.value.length >= 6;
    const matchValid = confirm.value === pwd.value && confirm.value !== '';
    pwd.classList.toggle('is-invalid', !pwdValid);
    confirm.classList.toggle('is-invalid', !matchValid);
    if (!pwdValid || !matchValid) return;

    const btn = document.getElementById('resetSubmitBtn');
    setLoading(btn, true, 'Resetting...');

    // Placeholder endpoint — backend not implemented yet.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/reset-password.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
      setLoading(btn, false, 'Reset password');
      goToStep(4);
    };
    xhr.onerror = function () { setLoading(btn, false, 'Reset password'); };
    xhr.send(JSON.stringify({ password: pwd.value }));
  });
}

/* ---------- Resend code cooldown ---------- */
function initResendTimer() {
  const resendBtn = document.getElementById('resendCodeBtn');
  if (!resendBtn) return;
  resendBtn.addEventListener('click', function (e) {
    e.preventDefault();
    if (resendBtn.classList.contains('disabled')) return;
    startResendTimer();
  });
}

function startResendTimer() {
  const resendBtn = document.getElementById('resendCodeBtn');
  let seconds = 30;
  resendBtn.classList.add('disabled');
  const original = 'Resend code';
  const interval = setInterval(() => {
    resendBtn.textContent = `Resend code (${seconds}s)`;
    seconds--;
    if (seconds < 0) {
      clearInterval(interval);
      resendBtn.textContent = original;
      resendBtn.classList.remove('disabled');
    }
  }, 1000);
}

/* ---------- Shared: password visibility ---------- */
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

/* ---------- Shared: button loading state ---------- */
function setLoading(btn, isLoading, label) {
  btn.disabled = isLoading;
  btn.querySelector('.spinner-border').classList.toggle('d-none', !isLoading);
  btn.querySelector('.btn-label').textContent = label;
}