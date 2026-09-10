<?php
/**
 * Swapify Admin — Login
 * Reuses the users table — admin accounts are just users with role = 'admin'.
 * Separate session key ($_SESSION['admin_id']) so an admin session doesn't
 * get confused with a regular logged-in user session on the public site.
 */
session_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../connection.php';

// ===================== POST: admin login =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter your email and password.']);
        exit;
    }

    $stmt = mysqli_prepare($connection, "SELECT id, password_hash, role, status FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'This account does not have admin access.']);
        exit;
    }

    if ($user['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'This account is not active.']);
        exit;
    }

    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_email'] = $email;

    echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <div class="auth-shell">

        <!-- ============================== BRAND PANEL ============================== -->
        <div class="auth-brand-panel">
            <a href="/index.php" class="auth-brand-logo">
                <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                Swapify <span class="fw-normal" style="opacity:0.7;">Admin</span>
            </a>

            <div>
                <p class="auth-brand-quote mb-4">Keep the marketplace healthy — review listings, manage users, and resolve reports.</p>
                <div class="auth-mini-swap">
                    <div class="row-item"><i class="bi bi-shield-lock"></i> Restricted to authorized staff</div>
                    <div class="row-item"><i class="bi bi-clock-history"></i> All actions are logged</div>
                </div>
            </div>

            <div class="auth-brand-foot">Swapify Admin Panel &copy; <?= date('Y') ?></div>
        </div>

        <!-- ============================== FORM PANEL ============================== -->
        <div class="auth-form-panel">
            <div class="auth-card">
                <div class="d-lg-none mb-4">
                    <a href="/index.php" class="navbar-brand-swapify">
                        <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                        Swapify Admin
                    </a>
                </div>

                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 mb-3" style="width:52px;height:52px;">
                    <i class="bi bi-shield-lock text-primary fs-4"></i>
                </div>
                <h3 class="mb-1">Admin sign in</h3>
                <p class="text-muted-swap mb-4">Restricted access — staff credentials only.</p>

                <form id="adminLoginForm" novalidate>
                    <div class="mb-3">
                        <label for="adminEmail" class="form-label">Admin email</label>
                        <input type="email" class="form-control" id="adminEmail" placeholder="admin@swapify.com" required>
                        <div class="invalid-feedback">Enter a valid email address.</div>
                    </div>

                    <div class="mb-4">
                        <label for="adminPassword" class="form-label">Password</label>
                        <div class="auth-input-group">
                            <input type="password" class="form-control" id="adminPassword" placeholder="Enter your password" required minlength="6">
                            <button type="button" class="auth-input-toggle" data-target="adminPassword" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback">Password must be at least 6 characters.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" id="adminLoginSubmitBtn">
                        <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                        <span class="btn-label">Sign in</span>
                    </button>
                </form>

                <div id="adminLoginError" class="alert alert-danger mt-3 py-2 small d-none"></div>

                <p class="text-center text-muted-swap small mt-4 mb-0">
                    <a href="<?= asset('index.php') ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to Swapify</a>
                </p>
            </div>
        </div>
    </div>

    <?php loadScripts('admin_login'); ?>
</body>
</html>