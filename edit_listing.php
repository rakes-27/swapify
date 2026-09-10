<?php
/**
 * Swapify — Edit Listing
 * Frontend only. Same layout as create-listing.php, pre-filled with a
 * placeholder item's data. Submits to /api/update-item.php via AJAX
 * (edit-listing.js). Includes a delete-with-confirmation flow.
 */
require_once __DIR__ . '/includes/bootstrap.php';

// Placeholder existing item data (would come from the database).
$existingItem = [
    'id' => 1,
    'title' => 'Canon AE-1 Film Camera',
    'description' => "Classic 35mm SLR film camera in great working condition. Shutter, light meter, and film advance all function smoothly. Comes with the original 50mm f/1.8 lens and a padded carry case.",
    'category' => 'Cameras',
    'condition' => 'Good',
    'lookingFor' => 'Nintendo Switch or gaming laptop',
    'location' => 'Kathmandu',
    'tags' => ['vintage', 'film', '35mm'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit listing — Swapify</title>
    <?php loadBootstrap(); ?>
</head>
<body>

    <?php component('navbar'); ?>

    <section class="section-py pt-4">
        <div class="container">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h2 class="mb-1">Edit listing</h2>
                    <p class="text-muted-swap mb-0">Update your item's details or photos.</p>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">
                    <i class="bi bi-trash me-1"></i> Delete listing
                </button>
            </div>

            <div class="row g-4">

                <!-- ============================== FORM ============================== -->
                <div class="col-lg-8">
                    <form id="listingForm" novalidate data-item-id="<?= $existingItem['id'] ?>">

                        <!-- Images -->
                        <div class="dash-panel mb-4">
                            <div class="dash-panel-title">Photos</div>
                            <div class="dropzone" id="dropzone">
                                <div class="dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                                <div class="fw-semibold">Drag & drop images here</div>
                                <div class="text-muted-swap small">or click to browse — PNG or JPG, up to 8 photos</div>
                                <input type="file" id="fileInput" accept="image/*" multiple class="d-none">
                            </div>
                            <div class="text-danger small mt-2 d-none" id="dropzoneError">Add at least one photo.</div>
                            <div class="image-preview-grid" id="imagePreviewGrid">
                                <div class="image-preview-thumb existing-image-thumb" data-id="existing-1" style="display:flex;align-items:center;justify-content:center;">
                                    <i class="bi bi-camera fs-3 text-muted-swap"></i>
                                    <button type="button" class="image-preview-remove" data-id="existing-1"><i class="bi bi-x"></i></button>
                                </div>
                                <div class="image-preview-thumb existing-image-thumb" data-id="existing-2" style="display:flex;align-items:center;justify-content:center;">
                                    <i class="bi bi-camera2 fs-3 text-muted-swap"></i>
                                    <button type="button" class="image-preview-remove" data-id="existing-2"><i class="bi bi-x"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- Details -->
                        <div class="dash-panel mb-4">
                            <div class="dash-panel-title">Item details</div>

                            <div class="mb-3">
                                <label for="listingTitle" class="form-label form-label-swap">Title</label>
                                <input type="text" class="form-control" id="listingTitle" style="border-radius:12px;" required
                                       value="<?= htmlspecialchars($existingItem['title']) ?>">
                                <div class="invalid-feedback">Give your item a title.</div>
                            </div>

                            <div class="mb-3">
                                <label for="listingDescription" class="form-label form-label-swap">Description</label>
                                <textarea class="form-control" id="listingDescription" rows="4" style="border-radius:12px;" required><?= htmlspecialchars($existingItem['description']) ?></textarea>
                                <div class="invalid-feedback">Add a short description.</div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label for="listingCategory" class="form-label form-label-swap">Category</label>
                                    <select class="form-select" id="listingCategory" style="border-radius:12px;" required>
                                        <?php foreach (['Books','Games','Electronics','Clothes','Cameras','Sports Equipment','Musical Instruments','Collectibles','Furniture','Art Supplies','Accessories','Gadgets'] as $cat): ?>
                                            <option <?= $cat === $existingItem['category'] ? 'selected' : '' ?>><?= $cat ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Choose a category.</div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="listingCondition" class="form-label form-label-swap">Condition</label>
                                    <select class="form-select" id="listingCondition" style="border-radius:12px;" required>
                                        <?php foreach (['New','Like New','Good','Used'] as $cond): ?>
                                            <option <?= $cond === $existingItem['condition'] ? 'selected' : '' ?>><?= $cond ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Choose a condition.</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="listingLookingFor" class="form-label form-label-swap">Looking for</label>
                                <input type="text" class="form-control" id="listingLookingFor" style="border-radius:12px;" required
                                       value="<?= htmlspecialchars($existingItem['lookingFor']) ?>">
                                <div class="invalid-feedback">Tell traders what you'd like in return.</div>
                            </div>

                            <div class="mb-3">
                                <label for="listingLocation" class="form-label form-label-swap">Location</label>
                                <input type="text" class="form-control" id="listingLocation" style="border-radius:12px;" required
                                       value="<?= htmlspecialchars($existingItem['location']) ?>">
                                <div class="invalid-feedback">Add your general location.</div>
                            </div>

                            <div>
                                <label class="form-label form-label-swap">Tags</label>
                                <div class="tag-input-wrap" id="tagInputWrap">
                                    <?php foreach ($existingItem['tags'] as $tag): ?>
                                        <span class="tag-pill" data-tag="<?= htmlspecialchars($tag) ?>">
                                            <?= htmlspecialchars($tag) ?> <button type="button" aria-label="Remove tag"><i class="bi bi-x"></i></button>
                                        </span>
                                    <?php endforeach; ?>
                                    <input type="text" id="tagTextInput" placeholder="Type a tag and press Enter">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center gap-2 px-4" id="listingSubmitBtn">
                                <span class="spinner-border spinner-btn d-none" role="status" aria-hidden="true"></span>
                                <span class="btn-label">Save changes</span>
                            </button>
                            <a href="/dashboard.php" class="btn btn-light-swap px-4">Cancel</a>
                        </div>
                    </form>
                </div>

                <!-- ============================== LIVE PREVIEW ============================== -->
                <div class="col-lg-4">
                    <div class="filter-panel" style="top: 90px;">
                        <div class="fw-semibold mb-3">Preview</div>
                        <div class="trade-card">
                            <div class="trade-card-img"><i class="bi bi-image-fill" id="previewImageIcon"></i></div>
                            <div class="trade-card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge-condition" id="previewCondition"><?= $existingItem['condition'] ?></span>
                                    <span class="text-muted-swap small"><i class="bi bi-geo-alt"></i> <span id="previewLocation"><?= $existingItem['location'] ?></span></span>
                                </div>
                                <div class="trade-card-title" id="previewTitle"><?= $existingItem['title'] ?></div>
                                <div class="trade-card-for">Looking for: <span class="amber" id="previewFor"><?= $existingItem['lookingFor'] ?></span></div>
                            </div>
                        </div>
                        <p class="text-muted-swap small mt-3 mb-0">This is how your listing appears in the marketplace.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================== DELETE CONFIRM MODAL ============================== -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px; border:none;">
                <div class="modal-body text-center p-4">
                    <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                        <i class="bi bi-trash text-danger fs-4"></i>
                    </div>
                    <h5 class="mb-2">Delete this listing?</h5>
                    <p class="text-muted-swap mb-4">This can't be undone. Any pending trade offers on this item will be cancelled.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <button class="btn btn-light-swap px-4" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-danger px-4" id="confirmDeleteBtn">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php component('footer'); ?>

    <?php loadScripts('edit_listing'); ?>
</body>
</html>