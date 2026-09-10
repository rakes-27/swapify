<?php
/**
 * Swapify Admin — Manage Listings
 * Real enum: pending, active, rejected, traded, cancelled.
 *   pending            -> Approve (active) / Reject (rejected)
 *   active             -> Cancel (cancelled)
 *   rejected/cancelled -> Reactivate (active)
 *   traded             -> view-only, no status action
 *   any status         -> permanent Delete
 */
session_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../connection.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// ===================== POST: approve / reject / cancel / reactivate / delete =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $listingId = (int) ($_POST['listing_id'] ?? 0);

    if ($listingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid listing.']);
        exit;
    }

    $statusMap = [
        'approve'    => 'active',
        'reject'     => 'rejected',
        'cancel'     => 'cancelled',
        'reactivate' => 'active',
    ];

    if (isset($statusMap[$action])) {
        $newStatus = $statusMap[$action];
        $stmt = mysqli_prepare($connection, "UPDATE listings SET status = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $newStatus, $listingId);
        $ok = mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        $error = mysqli_error($connection);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not update listing.', 'debug' => $error]);
            exit;
        }
        if ($affected === 0) {
            echo json_encode(['success' => false, 'message' => 'Listing not found, or already in that state.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Listing updated.']);
        exit;
    }

    if ($action === 'delete') {
        mysqli_query($connection, "DELETE FROM wishlists WHERE listing_id = $listingId");
        mysqli_query($connection, "DELETE FROM listing_images WHERE listing_id = $listingId");
        mysqli_query($connection, "DELETE FROM comments WHERE listing_id = $listingId");
        mysqli_query($connection, "DELETE FROM trending_snapshot WHERE listing_id = $listingId");

        $stmt = mysqli_prepare($connection, "DELETE FROM listings WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $listingId);
        $ok = mysqli_stmt_execute($stmt);
        $error = mysqli_error($connection);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not delete listing.', 'debug' => $error]);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Listing deleted.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ===================== GET: search + pagination =====================
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$whereSearch = '';
$params = [];
$types = '';
if ($search !== '') {
    $whereSearch = "WHERE (l.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $likeTerm = '%' . $search . '%';
    $params = [$likeTerm, $likeTerm, $likeTerm];
    $types = 'sss';
}

$countSql = "SELECT COUNT(*) AS total FROM listings l LEFT JOIN users u ON u.id = l.user_id $whereSearch";
$countStmt = mysqli_prepare($connection, $countSql);
if ($types !== '') {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalListings = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
mysqli_stmt_close($countStmt);
$totalPages = max(1, (int) ceil($totalListings / $perPage));

$sql = "SELECT l.id, l.title, l.status, c.name AS category_name, u.name, u.email
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN categories c ON c.id = l.category_id
        $whereSearch
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($connection, $sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . 'ii';
mysqli_stmt_bind_param($stmt, $allTypes, ...$allParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$listings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $ownerName = $row['name'] ?: ucfirst(explode('@', $row['email'])[0]);
    $listings[] = [
        'id'       => $row['id'],
        'title'    => $row['title'],
        'owner'    => $ownerName,
        'category' => $row['category_name'] ?? 'Uncategorized',
        'status'   => $row['status'],
    ];
}
mysqli_stmt_close($stmt);

$statusLabel = [
    'pending'   => 'Pending review',
    'active'    => 'Active',
    'rejected'  => 'Rejected',
    'traded'    => 'Traded',
    'cancelled' => 'Cancelled',
];
$statusBadgeClass = [
    'pending'   => 'pending',
    'active'    => 'accepted',
    'rejected'  => 'rejected',
    'traded'    => 'accepted',
    'cancelled' => 'cancelled',
];

$totalAllListings = (int) mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS cnt FROM listings"))['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Listings — Swapify Admin</title>
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
            <span class="fw-semibold">Manage Listings</span>
        </div>

        <div class="dashboard-content">
            <div class="mb-4">
                <h2 class="mb-1">Listings</h2>
                <p class="text-muted-swap mb-0"><?= count($listings) ?> listings shown &middot; <?= number_format($totalAllListings) ?> total</p>
            </div>

            <div class="admin-table-card">
                <div class="admin-table-toolbar">
                    <form class="admin-table-search" method="GET" style="flex:1; max-width:360px;">
                        <i class="bi bi-search text-muted-swap"></i>
                        <input type="search" name="q" id="listingSearchInput" placeholder="Search by title, owner name, or email..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                    <span class="text-muted-swap small">Showing <?= count($listings) ?> of <?= number_format($totalListings) ?> listings<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table">
                        <thead>
                            <tr>
                                <th>Listing</th>
                                <th>Owner</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="listingsTableBody">
                            <?php if (empty($listings)): ?>
                                <tr><td colspan="5" class="text-center text-muted-swap py-4">No listings found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($listings as $item): ?>
                                    <tr data-listing-id="<?= $item['id'] ?>" data-listing-title="<?= htmlspecialchars($item['title']) ?>">
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="admin-mini-thumb"><i class="bi bi-box-seam"></i></div>
                                                <span class="fw-semibold"><?= htmlspecialchars($item['title']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-muted-swap"><?= htmlspecialchars($item['owner']) ?></td>
                                        <td class="text-muted-swap"><?= htmlspecialchars($item['category']) ?></td>
                                        <td><span class="status-badge <?= $statusBadgeClass[$item['status']] ?? 'pending' ?>"><?= $statusLabel[$item['status']] ?? ucfirst($item['status']) ?></span></td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1">
                                                <?php if ($item['status'] === 'pending'): ?>
                                                    <button class="admin-row-icon-btn success approve-listing-btn" aria-label="Approve"><i class="bi bi-check-lg"></i></button>
                                                    <button class="admin-row-icon-btn danger reject-listing-btn" aria-label="Reject"><i class="bi bi-x-lg"></i></button>
                                                <?php elseif ($item['status'] === 'active'): ?>
                                                    <button class="admin-row-icon-btn danger cancel-listing-btn" aria-label="Cancel"><i class="bi bi-eye-slash"></i></button>
                                                <?php elseif ($item['status'] === 'rejected' || $item['status'] === 'cancelled'): ?>
                                                    <button class="admin-row-icon-btn success reactivate-listing-btn" aria-label="Reactivate"><i class="bi bi-arrow-counterclockwise"></i></button>
                                                <?php endif; ?>
                                                <a href="<?= asset('items_details.php') ?>?id=<?= $item['id'] ?>" target="_blank" class="admin-row-icon-btn" aria-label="View"><i class="bi bi-eye"></i></a>
                                                <button class="admin-row-icon-btn danger delete-listing-btn" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="admin-table-footer">
                    <span class="text-muted-swap small">Page <?= $page ?> of <?= number_format($totalPages) ?></span>
                    <ul class="pagination pagination-swap mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(['q' => $search, 'page' => max(1, $page - 1)]) ?>"><i class="bi bi-chevron-left"></i></a>
                        </li>
                        <?php
                        $startPage = max(1, $page - 1);
                        $endPage = min($totalPages, $startPage + 2);
                        for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(['q' => $search, 'page' => $p]) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(['q' => $search, 'page' => min($totalPages, $page + 1)]) ?>"><i class="bi bi-chevron-right"></i></a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div class="modal fade" id="deleteListingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:20px; border:none;">
            <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                    <i class="bi bi-trash text-danger fs-4"></i>
                </div>
                <h5 class="mb-2">Delete <span id="deleteListingTitle">this listing</span>?</h5>
                <p class="text-muted-swap mb-4">This removes the listing and any pending trade offers on it.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-light-swap px-4" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger px-4" id="confirmDeleteListingBtn">Delete</button>
                </div>
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

<?php loadScripts('admin_listings'); ?>
</body>
</html>