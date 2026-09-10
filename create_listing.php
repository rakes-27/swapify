<?php

require_once __DIR__ . '/includes/bootstrap.php';
require_once 'connection.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ===================== HANDLE AJAX SUBMISSION (POST ONLY) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');
    session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'You must be logged in to create a listing.'
        ]);
        exit;
    }

    $userId        = $_SESSION['user_id'];
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $categoryName  = trim($_POST['category'] ?? '');
    $condition     = trim($_POST['condition'] ?? '');
    $lookingFor    = trim($_POST['looking_for'] ?? '');
    $location      = trim($_POST['location'] ?? '');
    $tagsRaw       = trim($_POST['tags'] ?? ''); // comma-separated string from the frontend

    $errors = [];
    if ($title === '')        $errors[] = 'Title is required.';
    if ($description === '')  $errors[] = 'Description is required.';
    if ($categoryName === '') $errors[] = 'Category is required.';
    if ($condition === '')    $errors[] = 'Condition is required.';
    if ($lookingFor === '')   $errors[] = 'Looking for field is required.';
    if ($location === '')     $errors[] = 'Location is required.';

    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => implode(' ', $errors)
        ]);
        exit;
    }

    // --- Resolve category name -> category_id ---
    // ASSUMPTION: a `categories` table exists with columns (id, name).
    // Adjust the column name below if yours differs.
    $categoryId = null;
    $catStmt = mysqli_prepare($connection, "SELECT id FROM categories WHERE name = ?");
    mysqli_stmt_bind_param($catStmt, "s", $categoryName);
    mysqli_stmt_execute($catStmt);
    $catResult = mysqli_stmt_get_result($catStmt);

    if ($catRow = mysqli_fetch_assoc($catResult)) {
        $categoryId = $catRow['id'];
    }
    mysqli_stmt_close($catStmt);

    if ($categoryId === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Selected category is not recognized.'
        ]);
        exit;
    }

    // --- Image uploads (validated & moved before DB writes so we don't
    //     insert a listing if the photos fail) ---
    $uploadedPaths = [];

    if (!empty($_FILES['images']['name'][0])) {

        $uploadDir = __DIR__ . '/uploads/listings/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $maxFiles = 8;
        $fileCount = count($_FILES['images']['name']);

        if ($fileCount > $maxFiles) {
            echo json_encode([
                'success' => false,
                'message' => 'You can upload a maximum of ' . $maxFiles . ' photos.'
            ]);
            exit;
        }

        for ($i = 0; $i < $fileCount; $i++) {

            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpPath  = $_FILES['images']['tmp_name'][$i];
            $mimeType = mime_content_type($tmpPath);

            if (!in_array($mimeType, $allowedTypes, true)) {
                continue;
            }

            $ext      = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
            $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destPath = $uploadDir . $safeName;

            if (move_uploaded_file($tmpPath, $destPath)) {
                $uploadedPaths[] = 'uploads/listings/' . $safeName;
            }
        }

        if (empty($uploadedPaths)) {
            echo json_encode([
                'success' => false,
                'message' => 'None of the uploaded photos were valid. Use PNG, JPG, or WEBP.'
            ]);
            exit;
        }
    }

    // --- Parse tags into an array ---
    $tagList = [];
    if ($tagsRaw !== '') {
        $tagList = array_filter(array_map('trim', explode(',', $tagsRaw)));
    }

    // --- Insert everything as one transaction ---
    mysqli_begin_transaction($connection);

    try {
        // 1. listings
        $stmt = mysqli_prepare(
            $connection,
            "INSERT INTO listings
                (user_id, category_id, title, description, looking_for, condition_type, location, status, views_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, NOW(), NOW())"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "iisssss",
            $userId,
            $categoryId,
            $title,
            $description,
            $lookingFor,
            $condition,
            $location
        );
        mysqli_stmt_execute($stmt);
        $listingId = mysqli_insert_id($connection);
        mysqli_stmt_close($stmt);

        // 2. listing_images (one row per photo)
        if (!empty($uploadedPaths)) {
            $imgStmt = mysqli_prepare(
                $connection,
                "INSERT INTO listing_images (listing_id, image_path, sort_order) VALUES (?, ?, ?)"
            );
            foreach ($uploadedPaths as $index => $path) {
                mysqli_stmt_bind_param($imgStmt, "isi", $listingId, $path, $index);
                mysqli_stmt_execute($imgStmt);
            }
            mysqli_stmt_close($imgStmt);
        }

        // 3. listing_tags (one row per tag)
        if (!empty($tagList)) {
            $tagStmt = mysqli_prepare(
                $connection,
                "INSERT INTO listing_tags (listing_id, tag) VALUES (?, ?)"
            );
            foreach ($tagList as $tag) {
                mysqli_stmt_bind_param($tagStmt, "is", $listingId, $tag);
                mysqli_stmt_execute($tagStmt);
            }
            mysqli_stmt_close($tagStmt);
        }

        mysqli_commit($connection);

        echo json_encode([
            'success' => true,
            'message' => 'Listing published successfully.',
            'listing_id' => $listingId
        ]);

    } catch (mysqli_sql_exception $e) {
        mysqli_rollback($connection);
        echo json_encode([
            'success' => false,
            'message' => 'Something went wrong while saving your listing. Please try again.'
        ]);
    }

    exit;
}

// ===================== RENDER PAGE (GET) =====================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create a listing — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="mb-4">
                <h2 class="mb-1">List an item to swap</h2>
                <p class="text-muted-swap">Add photos and details — the more specific, the faster you'll get good offers.</p>
            </div>

            <div class="row g-4">

                <!-- ============================== FORM ============================== -->
                <div class="col-lg-8">
                    <form id="listingForm" enctype="multipart/form-data" novalidate>

                        <!-- Images -->
                        <div class="dash-panel mb-4">
                            <div class="dash-panel-title">Photos</div>
                            <div class="dropzone" id="dropzone">
                                <div class="dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                                <div class="fw-semibold">Drag & drop images here</div>
                                <div class="text-muted-swap small">or click to browse — PNG or JPG, up to 8 photos</div>
                                <input type="file" id="fileInput" name="images[]" accept="image/*" multiple class="d-none">
                            </div>
                            <div class="text-danger small mt-2 d-none" id="dropzoneError">Add at least one photo.</div>
                            <div class="image-preview-grid" id="imagePreviewGrid"></div>
                        </div>

                        <!-- Details -->
                        <div class="dash-panel mb-4">
                            <div class="dash-panel-title">Item details</div>

                            <div class="mb-3">
                                <label for="listingTitle" class="form-label form-label-swap">Title</label>
                                <input type="text" class="form-control" id="listingTitle" name="title" placeholder="e.g. Canon AE-1 Film Camera" style="border-radius:12px;" required>
                                <div class="invalid-feedback">Give your item a title.</div>
                            </div>

                            <div class="mb-3">
                                <label for="listingDescription" class="form-label form-label-swap">Description</label>
                                <textarea class="form-control" id="listingDescription" name="description" rows="4" style="border-radius:12px;" placeholder="Describe the item's condition, age, accessories included..." required></textarea>
                                <div class="invalid-feedback">Add a short description.</div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label for="listingCategory" class="form-label form-label-swap">Category</label>
                                    <select class="form-select" id="listingCategory" name="category" style="border-radius:12px;" required>
                                        <option value="">Select category</option>
                                        <option>Books</option>
                                        <option>Games</option>
                                        <option>Electronics</option>
                                        <option>Clothes</option>
                                        <option>Cameras</option>
                                        <option>Sports Equipment</option>
                                        <option>Musical Instruments</option>
                                        <option>Collectibles</option>
                                        <option>Furniture</option>
                                        <option>Art Supplies</option>
                                        <option>Accessories</option>
                                        <option>Gadgets</option>
                                    </select>
                                    <div class="invalid-feedback">Choose a category.</div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="listingCondition" class="form-label form-label-swap">Condition</label>
                                    <select class="form-select" id="listingCondition" name="condition" style="border-radius:12px;" required>
                                        <option value="">Select condition</option>
                                        <option>New</option>
                                        <option>Like New</option>
                                        <option>Good</option>
                                        <option>Used</option>
                                    </select>
                                    <div class="invalid-feedback">Choose a condition.</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="listingLookingFor" class="form-label form-label-swap">Looking for</label>
                                <input type="text" class="form-control" id="listingLookingFor" name="looking_for" placeholder="e.g. Nintendo Switch or gaming laptop" style="border-radius:12px;" required>
                                <div class="invalid-feedback">Tell traders what you'd like in return.</div>
                            </div>

                            <div class="mb-3">
                                <label for="listingLocation" class="form-label form-label-swap">Location</label>
                                <input type="text" class="form-control" id="listingLocation" name="location" placeholder="e.g. Kathmandu" style="border-radius:12px;" required>
                                <div class="invalid-feedback">Add your general location.</div>
                            </div>

                            <div>
                                <label class="form-label form-label-swap">Tags</label>
                                <div class="tag-input-wrap" id="tagInputWrap">
                                    <input type="text" id="tagTextInput" placeholder="Type a tag and press Enter">
                                </div>
                                <div class="text-muted-swap small mt-1">e.g. vintage, film, 35mm</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center gap-2 px-4" id="listingSubmitBtn">
                            <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-label">Publish listing</span>
                        </button>
                    </form>
                </div>

                <!-- ============================== LIVE PREVIEW ============================== -->
                <div class="col-lg-4">
                    <div class="filter-panel" style="top: 90px;">
                        <div class="fw-semibold mb-3">Preview</div>
                        <div class="trade-card">
                            <div class="trade-card-img">
                                <i class="bi bi-image" id="previewImageIcon"></i>
                            </div>
                            <div class="trade-card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge-condition" id="previewCondition">Condition</span>
                                    <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <span id="previewLocation">Location</span></span>
                                </div>
                                <div class="trade-card-title" id="previewTitle">Your item title</div>
                                <div class="trade-card-for">Looking for: <span class="amber" id="previewFor">Anything interesting</span></div>
                            </div>
                        </div>
                        <p class="text-muted-swap small mt-3 mb-0">This is how your listing will appear in the marketplace.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php component('footer'); ?>

    <?php loadScripts('create_listing'); ?>
</body>
</html>