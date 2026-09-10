<?php
/**
 * Swapify — Edit Profile
 * Profile info (name, username, email, phone, location, bio), avatar, and
 * banner ONLY. Privacy toggles and notification settings live in
 * settings.php — this page intentionally doesn't touch either.
 *
 * The banner has its own independent action (update_banner) so it can be
 * changed on its own without touching or re-validating name/username/email.
 *
 * Single POST-redirect-GET flow like settings.php: normal form submit +
 * flash message, since this involves file uploads.
 *
 * Requires the `banner_path` column on `users` — see
 * add_banner_path_migration.sql.
 */
session_start();

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/components/cards.php';
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];

// Banner is displayed with CSS background-size:cover on profile.php, which
// crops any aspect ratio to fit — so uploads just need a sane max resolution
// and file size, not an exact/near-exact ratio match.
const BANNER_MAX_WIDTH  = 2560;
const BANNER_MAX_HEIGHT = 1440;

// ===================== HANDLE BANNER UPLOAD (POST ONLY, INDEPENDENT) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_banner') {

    $errors = [];
    $newBannerPath = null;

    if (!isset($_FILES['banner']) || $_FILES['banner']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please choose a banner image first.';
    } elseif ($_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'There was a problem uploading that file.';
    } else {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $maxBytes   = 5 * 1024 * 1024; // 5MB

        $tmpPath  = $_FILES['banner']['tmp_name'];
        $origName = $_FILES['banner']['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $size     = $_FILES['banner']['size'];

        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'Banner must be a JPG, PNG, or WEBP image.';
        } elseif ($size > $maxBytes) {
            $errors[] = 'Banner image must be under 5MB.';
        } else {
            $dimensions = @getimagesize($tmpPath);
            if (!$dimensions) {
                $errors[] = 'Could not read that banner image. Please try a different file.';
            } else {
                [$width, $height] = $dimensions;

                if ($width > BANNER_MAX_WIDTH || $height > BANNER_MAX_HEIGHT) {
                    $errors[] = 'Banner image is too large — max ' . BANNER_MAX_WIDTH . '×' . BANNER_MAX_HEIGHT . 'px.';
                }
                // No aspect-ratio requirement: profile.php renders the banner with
                // background-size:cover, which already crops any ratio to fit the
                // banner area, so there's nothing for the upload itself to enforce.
            }
        }

        if (empty($errors)) {
            $uploadDir = __DIR__ . '/uploads/banners/';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = 'Server could not create the uploads/banners/ folder. Check that "uploads/" is writable by the web server (e.g. chmod 755 or 775, owned by the web server user).';
            } elseif (!is_writable($uploadDir)) {
                $errors[] = 'The uploads/banners/ folder exists but is not writable by the web server. Check its permissions/ownership.';
            }
        }

        if (empty($errors)) {
            $filename = 'banner_' . $currentUserId . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($tmpPath, $destPath)) {
                $newBannerPath = 'uploads/banners/' . $filename;
            } else {
                $errors[] = 'Could not save the uploaded banner. Please try again.';
            }
        }
    }

    if (empty($errors) && $newBannerPath !== null) {
        $updStmt = mysqli_prepare($connection, "UPDATE users SET banner_path = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($updStmt, "si", $newBannerPath, $currentUserId);

        if (mysqli_stmt_execute($updStmt)) {
            $_SESSION['edit_profile_flash'] = ['type' => 'success', 'message' => 'Banner updated.'];
        } else {
            $_SESSION['edit_profile_flash'] = ['type' => 'danger', 'message' => 'Could not save banner: ' . mysqli_stmt_error($updStmt)];
        }
        mysqli_stmt_close($updStmt);
    } else {
        $_SESSION['edit_profile_flash'] = ['type' => 'danger', 'message' => implode(' ', $errors)];
    }

    header('Location: edit_profile.php');
    exit;
}

// ===================== HANDLE PROFILE INFO + AVATAR (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {

    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $bio      = trim($_POST['bio'] ?? '');
    $location = trim($_POST['location'] ?? '');

    $errors = [];

    if ($name === '') $errors[] = 'Name is required.';
    if ($username === '' || !preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username)) {
        $errors[] = 'Username must be 3-30 characters (letters, numbers, underscore, dot only).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $dupStmt = mysqli_prepare(
            $connection,
            "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?"
        );
        mysqli_stmt_bind_param($dupStmt, "ssi", $username, $email, $currentUserId);
        mysqli_stmt_execute($dupStmt);
        $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
        mysqli_stmt_close($dupStmt);

        if ($dup) {
            $errors[] = 'That username or email is already taken.';
        }
    }

    // ---------- Avatar upload (optional) ----------
    $newAvatarPath = null;
    if (empty($errors) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $maxBytes   = 2 * 1024 * 1024; // 2MB

        $tmpPath  = $_FILES['avatar']['tmp_name'];
        $origName = $_FILES['avatar']['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $size     = $_FILES['avatar']['size'];

        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'Avatar must be a JPG, PNG, or WEBP image.';
        } elseif ($size > $maxBytes) {
            $errors[] = 'Avatar image must be under 2MB.';
        } else {
            $uploadDir = __DIR__ . '/uploads/avatars/';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = 'Server could not create the uploads/avatars/ folder. Check that "uploads/" is writable by the web server.';
            } elseif (!is_writable($uploadDir)) {
                $errors[] = 'The uploads/avatars/ folder exists but is not writable by the web server.';
            }

            if (empty($errors)) {
                $filename = 'avatar_' . $currentUserId . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . $filename;

                if (move_uploaded_file($tmpPath, $destPath)) {
                    $newAvatarPath = 'uploads/avatars/' . $filename;
                } else {
                    $errors[] = 'Could not save the uploaded avatar. Please try again.';
                }
            }
        }
    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'There was a problem uploading that file.';
    }

    if (empty($errors)) {
        $setParts = "name = ?, username = ?, email = ?, phone = ?, bio = ?, location = ?, updated_at = NOW()";
        $types    = "ssssss";
        $params   = [$name, $username, $email, $phone, $bio, $location];

        if ($newAvatarPath !== null) {
            $setParts .= ", avatar_path = ?";
            $types    .= "s";
            $params[]  = $newAvatarPath;
        }

        $types   .= "i";
        $params[] = $currentUserId;

        $updStmt = mysqli_prepare($connection, "UPDATE users SET $setParts WHERE id = ?");
        mysqli_stmt_bind_param($updStmt, $types, ...$params);

        if (mysqli_stmt_execute($updStmt)) {
            $_SESSION['edit_profile_flash'] = ['type' => 'success', 'message' => 'Profile updated.'];
        } else {
            $_SESSION['edit_profile_flash'] = ['type' => 'danger', 'message' => 'Could not save profile: ' . mysqli_stmt_error($updStmt)];
        }
        mysqli_stmt_close($updStmt);
    } else {
        $_SESSION['edit_profile_flash'] = ['type' => 'danger', 'message' => implode(' ', $errors)];
    }

    header('Location: edit_profile.php');
    exit;
}

// ===================== HANDLE REMOVE AVATAR (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_avatar') {

    $pathStmt = mysqli_prepare($connection, "SELECT avatar_path FROM users WHERE id = ?");
    mysqli_stmt_bind_param($pathStmt, "i", $currentUserId);
    mysqli_stmt_execute($pathStmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($pathStmt));
    mysqli_stmt_close($pathStmt);

    if (!empty($row['avatar_path'])) {
        $fullPath = __DIR__ . '/' . $row['avatar_path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    $updStmt = mysqli_prepare($connection, "UPDATE users SET avatar_path = NULL, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($updStmt, "i", $currentUserId);

    if (mysqli_stmt_execute($updStmt)) {
        $_SESSION['edit_profile_flash'] = ['type' => 'success', 'message' => 'Avatar removed.'];
    } else {
        $_SESSION['edit_profile_flash'] = ['type' => 'danger', 'message' => 'Could not remove avatar: ' . mysqli_stmt_error($updStmt)];
    }
    mysqli_stmt_close($updStmt);

    header('Location: edit_profile.php');
    exit;
}

// ===================== HANDLE REMOVE BANNER (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_banner') {

    $pathStmt = mysqli_prepare($connection, "SELECT banner_path FROM users WHERE id = ?");
    mysqli_stmt_bind_param($pathStmt, "i", $currentUserId);
    mysqli_stmt_execute($pathStmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($pathStmt));
    mysqli_stmt_close($pathStmt);

    if (!empty($row['banner_path'])) {
        $fullPath = __DIR__ . '/' . $row['banner_path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    $updStmt = mysqli_prepare($connection, "UPDATE users SET banner_path = NULL, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($updStmt, "i", $currentUserId);

    if (mysqli_stmt_execute($updStmt)) {
        $_SESSION['edit_profile_flash'] = ['type' => 'success', 'message' => 'Banner removed.'];
    } else {
        $_SESSION['edit_profile_flash'] = ['type' => 'danger', 'message' => 'Could not remove banner: ' . mysqli_stmt_error($updStmt)];
    }
    mysqli_stmt_close($updStmt);

    header('Location: edit_profile.php');
    exit;
}

// ===================== RENDER PAGE (GET) =====================
$userStmt = mysqli_prepare(
    $connection,
    "SELECT id, name, username, email, phone, avatar_path, banner_path, bio, location
     FROM users WHERE id = ?"
);
mysqli_stmt_bind_param($userStmt, "i", $currentUserId);
mysqli_stmt_execute($userStmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));
mysqli_stmt_close($userStmt);

if (!$user) {
    header('Location: login.php');
    exit;
}

$displayName = $user['name'] ?: ($user['username'] ?: 'Trader');
$nameParts = preg_split('/\s+/', trim($displayName));
$initials = strtoupper(substr($nameParts[0] ?? 'U', 0, 1) . substr($nameParts[1] ?? '', 0, 1));
if (mb_strlen($initials) < 2) {
    $initials = strtoupper(substr($displayName, 0, 2));
}

$flash = $_SESSION['edit_profile_flash'] ?? null;
unset($_SESSION['edit_profile_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container" style="max-width:720px;">

            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb small mb-0">
                    <li class="breadcrumb-item"><a href="<?= asset('profile.php') ?>?id=<?= $currentUserId ?>" class="text-decoration-none">My Profile</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Profile</li>
                </ol>
            </nav>

            <h3 class="mb-4">Edit Profile</h3>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" role="alert">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="dash-panel mb-4">

                <!-- ============================== BANNER ============================== -->
                <div class="dash-panel-title mb-2">Banner</div>
                <div class="profile-cover mb-2" style="height:180px;<?php if (!empty($user['banner_path'])): ?> background-image:url('<?= asset(htmlspecialchars($user['banner_path'])) ?>'); background-size:cover; background-position:center;<?php endif; ?>"></div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <form method="POST" action="edit_profile.php" enctype="multipart/form-data" id="bannerForm" class="d-flex flex-wrap align-items-center gap-2">
                        <input type="hidden" name="action" value="update_banner">
                        <input type="file" class="form-control form-control-sm" name="banner" accept=".jpg,.jpeg,.png,.webp" required style="border-radius:12px; max-width:280px;">
                        <button type="submit" class="btn btn-light-swap btn-sm">Upload banner</button>
                    </form>
                    <?php if (!empty($user['banner_path'])): ?>
                        <form method="POST" action="edit_profile.php" onsubmit="return confirm('Remove your banner image?');">
                            <input type="hidden" name="action" value="remove_banner">
                            <button type="submit" class="btn btn-light-swap btn-sm">Remove banner</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="text-muted-swap small mb-4">
                    Wide images work best for the banner area (it's cropped to fit automatically). Max <?= BANNER_MAX_WIDTH ?>×<?= BANNER_MAX_HEIGHT ?>px. JPG, PNG, or WEBP. Max 5MB.
                </div>

                <!-- ============================== MAIN PROFILE FORM ============================== -->
                <div class="dash-panel-title mb-3">Profile info</div>
                <form method="POST" action="edit_profile.php" enctype="multipart/form-data" id="profileForm">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="d-flex align-items-center gap-3 mb-2">
                        <?php if (!empty($user['avatar_path'])): ?>
                            <div class="profile-card-avatar p-0" style="width:64px;height:64px;overflow:hidden;">
                                <img src="<?= asset(htmlspecialchars($user['avatar_path'])) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                        <?php else: ?>
                            <div class="profile-card-avatar" style="width:64px;height:64px;font-size:1.1rem;"><?= htmlspecialchars($initials) ?></div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <label class="form-label mb-1">Profile photo</label>
                            <input type="file" class="form-control" name="avatar" accept=".jpg,.jpeg,.png,.webp" style="border-radius:12px;">
                            <div class="text-muted-swap small mt-1">JPG, PNG, or WEBP. Max 2MB. Leave empty to keep your current photo.</div>
                        </div>
                    </div>

                    <?php if (!empty($user['avatar_path'])): ?>
                        <div class="mb-4">
                            <button type="submit" name="action" value="remove_avatar" class="btn btn-light-swap btn-sm" formnovalidate onclick="return confirm('Remove your profile photo?');">Remove photo</button>
                        </div>
                    <?php else: ?>
                        <div class="mb-4"></div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Full name</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" style="border-radius:12px;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" style="border-radius:12px;" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" style="border-radius:12px;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" style="border-radius:12px;">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-control" name="location" value="<?= htmlspecialchars($user['location'] ?? '') ?>" style="border-radius:12px;">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Bio</label>
                        <textarea class="form-control" name="bio" rows="3" style="border-radius:12px;" placeholder="Tell other traders a bit about yourself..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a href="<?= asset('profile.php') ?>?id=<?= $currentUserId ?>" class="btn btn-light-swap">Cancel</a>
                </form>
            </div>

        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts(); ?>
</body>
</html>