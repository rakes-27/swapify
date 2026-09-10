<?php
/**
 * Swapify Admin — Manage Categories
 * Full CRUD against the real categories table (name, slug, icon columns).
 * Delete is blocked if any listings still reference the category —
 * admin must reassign/remove those listings first.
 */
session_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../connection.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

function slugify($text) {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

// ===================== POST: add / update / delete =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'bi-tag';

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Category name is required.']);
            exit;
        }

        $slug = slugify($name);

        $dupStmt = mysqli_prepare($connection, "SELECT id FROM categories WHERE slug = ?");
        mysqli_stmt_bind_param($dupStmt, "s", $slug);
        mysqli_stmt_execute($dupStmt);
        $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
        mysqli_stmt_close($dupStmt);

        if ($dup) {
            echo json_encode(['success' => false, 'message' => 'A category with that name already exists.']);
            exit;
        }

        $stmt = mysqli_prepare($connection, "INSERT INTO categories (name, slug, icon) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sss", $name, $slug, $icon);
        $ok = mysqli_stmt_execute($stmt);
        $newId = mysqli_insert_id($connection);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not add category.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Category added.', 'id' => $newId, 'slug' => $slug]);
        exit;
    }

    if ($action === 'update') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'bi-tag';

        if ($categoryId <= 0 || $name === '') {
            echo json_encode(['success' => false, 'message' => 'Category name is required.']);
            exit;
        }

        $slug = slugify($name);

        $dupStmt = mysqli_prepare($connection, "SELECT id FROM categories WHERE slug = ? AND id != ?");
        mysqli_stmt_bind_param($dupStmt, "si", $slug, $categoryId);
        mysqli_stmt_execute($dupStmt);
        $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
        mysqli_stmt_close($dupStmt);

        if ($dup) {
            echo json_encode(['success' => false, 'message' => 'A category with that name already exists.']);
            exit;
        }

        $stmt = mysqli_prepare($connection, "UPDATE categories SET name = ?, slug = ?, icon = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssi", $name, $slug, $icon, $categoryId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not update category.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Category updated.', 'slug' => $slug]);
        exit;
    }

    if ($action === 'delete') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($categoryId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid category.']);
            exit;
        }

        $countStmt = mysqli_prepare($connection, "SELECT COUNT(*) AS cnt FROM listings WHERE category_id = ?");
        mysqli_stmt_bind_param($countStmt, "i", $categoryId);
        mysqli_stmt_execute($countStmt);
        $listingCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['cnt'];
        mysqli_stmt_close($countStmt);

        if ($listingCount > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Can't delete — {$listingCount} listing" . ($listingCount === 1 ? '' : 's') . " still use this category. Reassign or remove them first.",
            ]);
            exit;
        }

        $stmt = mysqli_prepare($connection, "DELETE FROM categories WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $categoryId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not delete category.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Category deleted.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ===================== GET: list categories with real listing counts =====================
$catStmt = mysqli_query(
    $connection,
    "SELECT c.id, c.name, c.slug, c.icon,
            (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id) AS listing_count
     FROM categories c
     ORDER BY c.name ASC"
);
$categories = [];
while ($row = mysqli_fetch_assoc($catStmt)) {
    $categories[] = [
        'id'    => $row['id'],
        'icon'  => $row['icon'] ?: 'bi-tag',
        'name'  => $row['name'],
        'slug'  => $row['slug'],
        'count' => (int) $row['listing_count'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories — Swapify Admin</title>
    <?php loadBootstrap(); ?>
</head>
<body>

<div class="dashboard-shell">

    <?php component('admin_sidebar'); ?>

    <div class="dashboard-main">
        <div class="dashboard-topbar d-flex align-items-center gap-2">
            <button class="btn btn-light-swap d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileAdminSidebar">
                <i class="bi bi-list"></i>
            </button>
            <span class="fw-semibold">Manage Categories</span>
        </div>

        <div class="dashboard-content">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h2 class="mb-1">Categories</h2>
                    <p class="text-muted-swap mb-0" id="categoryCountText"><?= count($categories) ?> categories</p>
                </div>
                <button class="btn btn-primary" id="openAddCategoryBtn" data-bs-toggle="modal" data-bs-target="#categoryModal">
                    <i class="bi bi-plus-lg me-1"></i> Add category
                </button>
            </div>

            <div class="admin-table-card">
                <div class="table-responsive">
                    <table class="table admin-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Slug</th>
                                <th>Listings</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="categoriesTableBody">
                            <?php if (empty($categories)): ?>
                                <tr><td colspan="4" class="text-center text-muted-swap py-4">No categories yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($categories as $cat): ?>
                                    <tr data-category-id="<?= $cat['id'] ?>">
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="admin-mini-thumb"><i class="bi <?= htmlspecialchars($cat['icon']) ?>"></i></div>
                                                <span class="fw-semibold category-name-cell"><?= htmlspecialchars($cat['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-muted-swap category-slug-cell"><?= htmlspecialchars($cat['slug']) ?></td>
                                        <td class="category-count-cell"><?= number_format($cat['count']) ?></td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1">
                                                <button class="admin-row-icon-btn edit-category-btn" aria-label="Edit"><i class="bi bi-pencil"></i></button>
                                                <button class="admin-row-icon-btn danger delete-category-btn" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add / Edit modal (shared) -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px; border:none;">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="categoryModalTitle">Add category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="categoryForm">
                    <input type="hidden" id="categoryEditId">
                    <div class="mb-3">
                        <label class="form-label form-label-swap">Category name</label>
                        <input type="text" class="form-control" id="categoryNameInput" style="border-radius:12px;" placeholder="e.g. Gadgets">
                        <div class="invalid-feedback">Enter a category name.</div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label form-label-swap">Bootstrap icon class</label>
                        <input type="text" class="form-control" id="categoryIconInput" style="border-radius:12px;" placeholder="e.g. bi-phone">
                        <div class="text-muted-swap small mt-1">See icons at icons.getbootstrap.com</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light-swap" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveCategoryBtn" data-mode="add">Save category</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                    <i class="bi bi-trash text-danger fs-4"></i>
                </div>
                <h5 class="mb-2">Delete <span id="deleteCategoryName">this category</span>?</h5>
                <p class="text-muted-swap mb-4">Listings in this category will need to be reassigned.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-light-swap px-4" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger px-4" id="confirmDeleteCategoryBtn">Delete</button>
                </div>
        </div>
    </div>
</div>

<!-- Mobile sidebar -->
<div class="offcanvas offcanvas-start" id="mobileAdminSidebar">
    <div class="offcanvas-header">
        <span class="navbar-brand-swapify"><span class="brand-mark"><i class="bi bi-arrow-left-right"></i></span> Swapify Admin</span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0"><?php component('admin_sidebar'); ?></div>
</div>

<?php loadScripts('admin_categories'); ?>
</body>
</html>