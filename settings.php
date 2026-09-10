<?php
/**
 * Swapify — Settings
 * Same file handles GET (render, pre-filled from DB) and POST actions:
 *   - update_account       -> email, phone
 *   - change_password      -> verifies current, hashes new
 *   - update_notifications -> notify_trades / notify_reviews / notify_messages / notify_marketing
 *   - update_privacy       -> show_exact_location / show_wishlist / allow_all_requests
 * Profile editing lives elsewhere in the app, so that section was removed here.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$currentUserId = $_SESSION['user_id'];

// ===================== POST: update_account =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account') {
    header('Content-Type: application/json');

    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    // Email must stay unique across users (excluding this user's own row).
    $dupStmt = mysqli_prepare($connection, "SELECT id FROM users WHERE email = ? AND id != ?");
    mysqli_stmt_bind_param($dupStmt, "si", $email, $currentUserId);
    mysqli_stmt_execute($dupStmt);
    $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
    mysqli_stmt_close($dupStmt);

    if ($dup) {
        echo json_encode(['success' => false, 'message' => 'That email is already in use.']);
        exit;
    }

    $stmt = mysqli_prepare($connection, "UPDATE users SET email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $email, $phone, $currentUserId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Account settings updated' : 'Something went wrong. Please try again.']);
    exit;
}

// ===================== POST: change_password =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    header('Content-Type: application/json');

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';

    if (mb_strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit;
    }

    $hashStmt = mysqli_prepare($connection, "SELECT password_hash FROM users WHERE id = ?");
    mysqli_stmt_bind_param($hashStmt, "i", $currentUserId);
    mysqli_stmt_execute($hashStmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($hashStmt));
    mysqli_stmt_close($hashStmt);

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updStmt = mysqli_prepare($connection, "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($updStmt, "si", $newHash, $currentUserId);
    $ok = mysqli_stmt_execute($updStmt);
    mysqli_stmt_close($updStmt);

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Password changed successfully' : 'Something went wrong. Please try again.']);
    exit;
}

// ===================== POST: update_notifications =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_notifications') {
    header('Content-Type: application/json');

    // Checkboxes are only present in $_POST when checked, so isset() = on.
    $notifyTrades     = isset($_POST['notify_trades']) ? 1 : 0;
    $notifyReviews    = isset($_POST['notify_reviews']) ? 1 : 0;
    $notifyMessages   = isset($_POST['notify_messages']) ? 1 : 0;
    $notifyMarketing  = isset($_POST['notify_marketing']) ? 1 : 0;

    $stmt = mysqli_prepare(
        $connection,
        "UPDATE users SET notify_trades = ?, notify_reviews = ?, notify_messages = ?, notify_marketing = ?, updated_at = NOW() WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iiiii", $notifyTrades, $notifyReviews, $notifyMessages, $notifyMarketing, $currentUserId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Notification preferences saved' : 'Something went wrong. Please try again.']);
    exit;
}

// ===================== POST: update_privacy =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_privacy') {
    header('Content-Type: application/json');

    $showExactLocation = isset($_POST['show_exact_location']) ? 1 : 0;
    $showWishlist      = isset($_POST['show_wishlist']) ? 1 : 0;
    $allowAllRequests  = isset($_POST['allow_all_requests']) ? 1 : 0;

    $stmt = mysqli_prepare(
        $connection,
        "UPDATE users SET show_exact_location = ?, show_wishlist = ?, allow_all_requests = ?, updated_at = NOW() WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iiii", $showExactLocation, $showWishlist, $allowAllRequests, $currentUserId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Privacy settings saved' : 'Something went wrong. Please try again.']);
    exit;
}

// ===================== RENDER PAGE (GET): pull current values ==============
$stmt = mysqli_prepare(
    $connection,
    "SELECT email, phone, notify_trades, notify_reviews, notify_messages, notify_marketing,
            show_exact_location, show_wishlist, allow_all_requests
     FROM users WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $currentUserId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="mb-4">
                <h2 class="mb-1">Settings</h2>
                <p class="text-muted-swap mb-0">Manage your account and preferences.</p>
            </div>

            <div class="row g-4">

                <!-- ============================== NAV ============================== -->
                <div class="col-lg-3">
                    <div class="settings-nav nav flex-column" id="settingsNav">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#secAccount" type="button"><i class="bi bi-envelope"></i> Account</button>
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#secPassword" type="button"><i class="bi bi-shield-lock"></i> Password</button>
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#secNotifications" type="button"><i class="bi bi-bell"></i> Notifications</button>
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#secPrivacy" type="button"><i class="bi bi-eye"></i> Privacy</button>
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#secTheme" type="button"><i class="bi bi-palette"></i> Theme</button>
                    </div>
                </div>

                <!-- ============================== CONTENT ============================== -->
                <div class="col-lg-9">
                    <div class="tab-content">

                        <!-- Account -->
                        <div class="tab-pane fade show active" id="secAccount">
                            <div class="dash-panel">
                                <div class="settings-section-title">Account settings</div>
                                <p class="text-muted-swap small mb-4">Your login details and contact information.</p>

                                <form id="accountForm">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label form-label-swap">Email address</label>
                                            <input type="email" class="form-control" name="email" id="accountEmail" value="<?= htmlspecialchars($user['email']) ?>" style="border-radius:12px;" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label form-label-swap">Phone number</label>
                                            <input type="tel" class="form-control" name="phone" id="accountPhone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" style="border-radius:12px;">
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-primary mt-4" id="saveAccountBtn">Save changes</button>
                                </form>

                                <hr class="my-4">
                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Deactivate account</div>
                                        <div class="text-muted-swap small">Temporarily hide your profile and listings.</div>
                                    </div>
                                    <button class="btn btn-outline-danger btn-sm">Deactivate</button>
                                </div>
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="tab-pane fade" id="secPassword">
                            <div class="dash-panel">
                                <div class="settings-section-title">Change password</div>
                                <p class="text-muted-swap small mb-4">Use a strong password you don't use elsewhere.</p>

                                <div class="row g-3" style="max-width:420px;">
                                    <div class="col-12">
                                        <label class="form-label form-label-swap">Current password</label>
                                        <input type="password" class="form-control" id="currentPassword" style="border-radius:12px;">
                                        <div class="invalid-feedback">Enter your current password.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label form-label-swap">New password</label>
                                        <input type="password" class="form-control" id="newPasswordSettings" style="border-radius:12px;">
                                        <div class="invalid-feedback">Must be at least 6 characters.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label form-label-swap">Confirm new password</label>
                                        <input type="password" class="form-control" id="confirmPasswordSettings" style="border-radius:12px;">
                                        <div class="invalid-feedback">Passwords don't match.</div>
                                    </div>
                                </div>
                                <button class="btn btn-primary mt-4" id="changePasswordBtn">Update password</button>
                            </div>
                        </div>

                        <!-- Notifications -->
                        <div class="tab-pane fade" id="secNotifications">
                            <div class="dash-panel">
                                <div class="settings-section-title">Notification preferences</div>
                                <p class="text-muted-swap small mb-4">Choose what you get notified about.</p>

                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Trade requests</div>
                                        <div class="text-muted-swap small">When someone offers a trade or responds to yours</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifyTrades" <?= $user['notify_trades'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Reviews</div>
                                        <div class="text-muted-swap small">When you receive a new review</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifyReviews" <?= $user['notify_reviews'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Messages</div>
                                        <div class="text-muted-swap small">When a trader sends you a message</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifyMessages" <?= $user['notify_messages'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Marketing emails</div>
                                        <div class="text-muted-swap small">Product updates and community news</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifyMarketing" <?= $user['notify_marketing'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <button class="btn btn-primary mt-4" id="saveNotificationsBtn">Save preferences</button>
                            </div>
                        </div>

                        <!-- Privacy -->
                        <div class="tab-pane fade" id="secPrivacy">
                            <div class="dash-panel">
                                <div class="settings-section-title">Privacy</div>
                                <p class="text-muted-swap small mb-4">Control who can see your activity.</p>

                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Show exact location</div>
                                        <div class="text-muted-swap small">Otherwise only your city is shown</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="showExactLocation" <?= $user['show_exact_location'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Show wishlist on profile</div>
                                        <div class="text-muted-swap small">Let others see what you're looking for</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="showWishlist" <?= $user['show_wishlist'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="fw-semibold small">Allow trade requests from anyone</div>
                                        <div class="text-muted-swap small">Turn off to only allow trusted traders</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allowAllRequests" <?= $user['allow_all_requests'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <button class="btn btn-primary mt-4" id="savePrivacyBtn">Save preferences</button>
                            </div>
                        </div>

                        <!-- Theme (placeholder) -->
                        <div class="tab-pane fade" id="secTheme">
                            <div class="dash-panel">
                                <div class="settings-section-title">Theme</div>
                                <p class="text-muted-swap small mb-4">Dark mode is on the way — this is a preview of what's coming.</p>

                                <div class="row g-3">
                                    <div class="col-4">
                                        <div class="theme-option selected">
                                            <div class="theme-option-preview light"></div>
                                            <span class="small fw-semibold">Light</span>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="theme-option disabled">
                                            <span class="theme-option-badge">Soon</span>
                                            <div class="theme-option-preview dark"></div>
                                            <span class="small fw-semibold">Dark</span>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="theme-option disabled">
                                            <span class="theme-option-badge">Soon</span>
                                            <div class="theme-option-preview system"></div>
                                            <span class="small fw-semibold">System</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('settings'); ?>
</body>
</html>