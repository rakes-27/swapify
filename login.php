<?php
session_start();

require_once 'connection.php';

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$isValid = false;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Find user by email
    $stmt = mysqli_prepare(
        $connection,
        "SELECT id, email, password_hash
         FROM users
         WHERE email = ?"
    );

    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    // Check if user exists
    if (mysqli_num_rows($result) === 1) {

        $user = mysqli_fetch_assoc($result);

        // Verify password
        if (password_verify($password, $user['password_hash'])) {

            $isValid = true;

        } else {

            $message = "Invalid email or password.";
        }

    } else {

        $message = "Invalid email or password.";
    }

    mysqli_stmt_close($stmt);

    if ($isValid) {

        // Store only essential session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];

        echo json_encode([
            'success' => true,
            'message' => 'Login successful.'
        ]);

    } else {

        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
    }

    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in — Swapify</title>
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
                <p class="auth-brand-quote mb-4">"Traded my old guitar for a drone in three days. No cash, no hassle."</p>
                <div class="auth-mini-swap">
                    <div class="row-item"><i class="bi bi-music-note-beamed"></i> Acoustic Guitar <i class="bi bi-arrow-right ms-auto"></i></div>
                    <div class="row-item"><i class="bi bi-cpu"></i> DJI Mini Drone</div>
                </div>
            </div>

            <div class="auth-brand-foot">Trade Smarter. Own Better. &copy; <?= date('Y') ?> Swapify</div>
        </div>

        <!-- ============================== FORM PANEL ============================== -->
        <div class="auth-form-panel">
            <div class="auth-card">
                <div class="d-lg-none mb-4">
                    <a href="<?= asset('index.php') ?>" class="navbar-brand-swapify">
                        <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                        Swapify
                    </a>
                </div>

                <h3 class="mb-1">Welcome back</h3>
                <p class="text-muted-swap mb-4">Log in to keep trading with your community.</p>

                <button type="button" class="btn-google mb-3" onclick="window.location.href='<?= url('admin/login.php') ?>'">
                    <i class="bi bi-google"></i> Login as Admin
                </button>
                <div class="auth-divider">or log in with email</div>

                <form id="loginForm" novalidate>
                    <div class="mb-3">
                        <label for="loginEmail" class="form-label">Email address</label>
                        <input type="email" class="form-control" id="loginEmail" name="email" placeholder="you@example.com" required>
                        <div class="invalid-feedback">Enter a valid email address.</div>
                    </div>

                    <div class="mb-2">
                        <label for="loginPassword" class="form-label">Password</label>
                        <div class="auth-input-group">
                            <input type="password" class="form-control" id="loginPassword" name="password" placeholder="Enter your password" required minlength="6">
                            <button type="button" class="auth-input-toggle" data-target="loginPassword" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback">Password must be at least 6 characters.</div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="rememberMe" checked>
                            <label class="form-check-label small text-muted-swap" for="rememberMe">Remember me</label>
                        </div>
                        <a href="/forgot-password.php" class="small text-decoration-none">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" id="loginSubmitBtn">
                        <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                        <span class="btn-label">Log in</span>
                    </button>
                </form>

                <p class="text-center text-muted-swap small mt-4 mb-0">
                    Don't have an account? <a href="<?= asset('register.php') ?>" class="fw-semibold text-decoration-none">Sign up free</a>
                </p>
            </div>
        </div>
    </div>

    <?php loadScripts('login'); ?>
</body>
</html>