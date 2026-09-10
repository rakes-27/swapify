<?php
/**
 * Swapify — Forgot Password Page
 * Frontend only. A 4-step flow (email -> OTP -> reset -> success), all steps
 * live in this one file and are toggled client-side by forgot-password.js.
 * Each step posts to a placeholder /api/*.php endpoint.
 */
require_once __DIR__ . '/includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your password — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <div class="auth-shell">

        <!-- ============================== BRAND PANEL ============================== -->
        <div class="auth-brand-panel">
            <a href="/index.php" class="auth-brand-logo">
                <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                Swapify
            </a>
            <div>
                <p class="auth-brand-quote mb-4">Locked out happens. Let's get you back to trading in under a minute.</p>
                <div class="auth-mini-swap">
                    <div class="row-item"><i class="bi bi-shield-lock"></i> Secure OTP verification</div>
                    <div class="row-item"><i class="bi bi-clock-history"></i> Takes about 60 seconds</div>
                </div>
            </div>
            <div class="auth-brand-foot">Trade Smarter. Own Better. &copy; <?= date('Y') ?> Swapify</div>
        </div>

        <!-- ============================== FORM PANEL ============================== -->
        <div class="auth-form-panel">
            <div class="auth-card">

                <div class="d-lg-none mb-4">
                    <a href="/index.php" class="navbar-brand-swapify">
                        <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                        Swapify
                    </a>
                </div>

                <!-- ===== STEP 1: Email ===== -->
                <div class="auth-step" id="step1">
                    <h3 class="mb-1">Forgot your password?</h3>
                    <p class="text-muted-swap mb-4">Enter the email on your account and we'll send you a reset code.</p>

                    <form id="emailForm" novalidate>
                        <div class="mb-4">
                            <label for="fpEmail" class="form-label">Email address</label>
                            <input type="email" class="form-control" id="fpEmail" placeholder="you@example.com" required>
                            <div class="invalid-feedback">Enter a valid email address.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" id="emailSubmitBtn">
                            <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-label">Send reset code</span>
                        </button>
                    </form>

                    <p class="text-center text-muted-swap small mt-4 mb-0">
                        Remembered it? <a href="/login.php" class="fw-semibold text-decoration-none">Back to log in</a>
                    </p>
                </div>

                <!-- ===== STEP 2: OTP ===== -->
                <div class="auth-step d-none" id="step2">
                    <h3 class="mb-1">Check your inbox</h3>
                    <p class="text-muted-swap mb-4">Enter the 4-digit code sent to <strong id="otpEmailDisplay">you@example.com</strong>.</p>

                    <form id="otpForm" novalidate>
                        <div class="d-flex gap-2 justify-content-center mb-4">
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" id="otpSubmitBtn">
                            <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-label">Verify code</span>
                        </button>
                    </form>

                    <p class="text-center text-muted-swap small mt-4 mb-0">
                        Didn't get it? <a href="#" id="resendCodeBtn" class="fw-semibold text-decoration-none">Resend code</a>
                    </p>
                </div>

                <!-- ===== STEP 3: Reset password ===== -->
                <div class="auth-step d-none" id="step3">
                    <h3 class="mb-1">Set a new password</h3>
                    <p class="text-muted-swap mb-4">Make it something you haven't used before.</p>

                    <form id="resetForm" novalidate>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New password</label>
                            <div class="auth-input-group">
                                <input type="password" class="form-control" id="newPassword" placeholder="Enter new password" required minlength="6">
                                <button type="button" class="auth-input-toggle" data-target="newPassword" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="invalid-feedback">Password must be at least 6 characters.</div>
                        </div>
                        <div class="mb-4">
                            <label for="confirmNewPassword" class="form-label">Confirm new password</label>
                            <div class="auth-input-group">
                                <input type="password" class="form-control" id="confirmNewPassword" placeholder="Re-enter new password" required>
                                <button type="button" class="auth-input-toggle" data-target="confirmNewPassword" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="invalid-feedback">Passwords don't match.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" id="resetSubmitBtn">
                            <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-label">Reset password</span>
                        </button>
                    </form>
                </div>

                <!-- ===== STEP 4: Success ===== -->
                <div class="auth-step d-none text-center" id="step4">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-4"
                         style="width:72px;height:72px;">
                        <i class="bi bi-check-lg text-primary" style="font-size:2rem;"></i>
                    </div>
                    <h3 class="mb-2">Password reset</h3>
                    <p class="text-muted-swap mb-4">Your password has been updated. You can now log in with your new password.</p>
                    <a href="/login.php" class="btn btn-primary w-100">Back to log in</a>
                </div>

            </div>
        </div>
    </div>

    <?php loadScripts('forgot_password'); ?>
</body>
</html>