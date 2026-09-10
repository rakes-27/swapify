<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/bootstrap.php';

    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $regex_fullname = '/^[A-Za-z\s]{2,100}$/' ;
    $regx_username = '/^[A-Za-z0-9_]{3,20}$/' ;
    $regex_email = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
    $regex_phone = '/^[0-9+\-\s]{7,20}$/' ;
    $reges_password = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/' ;
    
$isValid = false;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        preg_match($regex_fullname, $fullname) &&
        preg_match($regx_username, $username) &&
        preg_match($regex_email, $email) &&
        preg_match($regex_phone, $phone) &&
        preg_match($reges_password, $password) &&
        $confirm_password === $password
    ) {

        // Check duplicate username/email
        $check = mysqli_prepare(
            $connection,
            "SELECT id FROM users WHERE username = ? OR email = ?"
        );

        mysqli_stmt_bind_param(
            $check,
            "ss",
            $username,
            $email
        );

        mysqli_stmt_execute($check);

        $result = mysqli_stmt_get_result($check);

        if (mysqli_num_rows($result) == 0) {
            $isValid = true;
        } else {
            $message = "Username or email already exists.";
        }

        mysqli_stmt_close($check);

    } else {
        $message = "Invalid form data.";
    }


    if ($isValid) {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare(
            $connection,
            "INSERT INTO users 
            (name, username, email, phone, password_hash) 
            VALUES (?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "sssss",
            $fullname,
            $username,
            $email,
            $phone,
            $hashedPassword
        );


        if (mysqli_stmt_execute($stmt)) {

            echo json_encode([
                'success' => true,
                'message' => 'Account created successfully.'
            ]);

            exit;

        } else {

            echo json_encode([
                'success' => false,
                'message' => 'Database error.'
            ]);

            exit;

        }

        mysqli_stmt_close($stmt);


    } else {

        echo json_encode([
            'success' => false,
            'message' => $message
        ]);

        exit;

    }

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create your account — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <div class="auth-shell">

        <!-- ============================== BRAND PANEL ============================== -->
        <div class="auth-brand-panel">
            <a href="<?= asset('index.php') ?>" class="auth-brand-logo">
                <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                Swapify
            </a>

            <div>
                <p class="auth-brand-quote mb-4">Join 42,000+ traders swapping goods instead of spending cash.</p>
                <div class="auth-mini-swap">
                    <div class="row-item"><i class="bi bi-check-circle-fill"></i> No listing fees</div>
                    <div class="row-item"><i class="bi bi-check-circle-fill"></i> Verified, trusted traders</div>
                    <div class="row-item"><i class="bi bi-check-circle-fill"></i> Trade locally or ship</div>
                </div>
            </div>

            <div class="auth-brand-foot">Trade Smarter. Own Better. &copy; <?= date('Y') ?> Swapify</div>
        </div>

        <!-- ============================== FORM PANEL ============================== -->
        <div class="auth-form-panel">
            <div class="auth-card" style="max-width:460px;">
                <div class="d-lg-none mb-4">
                    <a href="/index.php" class="navbar-brand-swapify">
                        <span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span>
                        Swapify
                    </a>
                </div>

                <h3 class="mb-1">Create your account</h3>
                <p class="text-muted-swap mb-4">Start swapping in under two minutes.</p>

                <button type="button" class="btn-google mb-3">
                    <i class="bi bi-google"></i> Continue with Google
                </button>
                <div class="auth-divider">or sign up with email</div>

                <form id="registerForm" novalidate>

                    <!-- Avatar upload -->
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <label for="avatarInput" class="mb-0" style="cursor:pointer;">
                            <div id="avatarPreview" class="rounded-circle bg-body-secondary d-flex align-items-center justify-content-center"
                                 style="width:64px;height:64px;background-size:cover;background-position:center;background-color:var(--swap-gray-50);border:1px solid var(--swap-gray-100);">
                                <i class="bi bi-camera text-muted-swap"></i>
                            </div>
                        </label>
                        <div>
                            <label for="avatarInput" class="btn btn-light-swap btn-sm mb-1" style="cursor:pointer;">Upload photo</label>
                            <input type="file" id="avatarInput" accept="image/*" class="d-none" name="profile">
                            <div class="text-muted-swap small">Optional — JPG or PNG</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="regName" class="form-label">Full name</label>
                        <input type="text" class="form-control" id="regName" name="fullname" placeholder="Jane Doe" required>
                        <div class="invalid-feedback">Enter your full name.</div>
                    </div>

                    <div class="mb-3">
                        <label for="regUsername" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white" style="border-radius:12px 0 0 12px;">@</span>
                            <input type="text" class="form-control" id="regUsername" name="username" placeholder="janedoe" required style="border-radius:0 12px 12px 0;">
                        </div>
                        <div class="invalid-feedback d-block" id="usernameError" style="display:none;">3+ characters, letters/numbers/underscore only.</div>
                    </div>

                    <div class="mb-3">
                        <label for="regEmail" class="form-label">Email address</label>
                        <input type="email" class="form-control" id="regEmail" name="email" placeholder="you@example.com" required>
                        <div class="invalid-feedback">Enter a valid email address.</div>
                    </div>

                    <div class="mb-3">
                        <label for="regPhone" class="form-label">Phone number</label>
                        <input type="tel" class="form-control" id="regPhone" name="phone" placeholder="+977 98XXXXXXXX" required>
                        <div class="invalid-feedback">Enter a valid phone number.</div>
                    </div>

                    <div class="mb-1">
                        <label for="regPassword" class="form-label">Password</label>
                        <div class="auth-input-group">
                            <input type="password" class="form-control" id="regPassword" name="password" placeholder="Create a password" required minlength="6">
                            <button type="button" class="auth-input-toggle" data-target="regPassword" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="auth-strength-bar"><div class="auth-strength-fill" id="strengthFill"></div></div>
                    <div class="d-flex justify-content-end mb-3">
                        <span class="small text-muted-swap" id="strengthLabel"></span>
                    </div>

                    <div class="mb-4">
                        <label for="regConfirm" class="form-label">Confirm password</label>
                        <div class="auth-input-group">
                            <input type="password" class="form-control" id="regConfirm" name="confirm_password" placeholder="Re-enter your password" required>
                            <button type="button" class="auth-input-toggle" data-target="regConfirm" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="invalid-feedback d-block" style="display:none;">Passwords don't match.</div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="regTerms" required>
                        <label class="form-check-label small text-muted-swap" for="regTerms">
                            I agree to Swapify's <a href="uploads\terms\Swapify Terms & Conditions.pdf" target="_blank">Terms of Service</a> and <a href="uploads\terms\Swapify Privacy Policy.pdf" target="_blank">Privacy Policy</a>.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2" id="registerSubmitBtn" name= "register">
                        <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                        <span class="btn-label">Create account</span>
                    </button>
                </form>

                <p class="text-center text-muted-swap small mt-4 mb-0">
                    Already have an account? <a href="/login.php" class="fw-semibold text-decoration-none">Log in</a>
                </p>
            </div>
        </div>
    </div>

    <?php loadScripts('register'); ?>
</body>
</html>