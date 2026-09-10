<?php
/**
 * Swapify Admin — Manage Users
 * Real enum: active, suspended, deactivated.
 * Suspend toggles active <-> suspended (admin action).
 * Delete cascades fully: listings (+ their images/wishlists/comments/
 * trending rows), trade_requests (as either party), reviews (as either
 * party), comments, comment_likes, comment_reports, wishlists,
 * notifications, then the user row itself.
 */
session_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../connection.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// ===================== POST: suspend / reactivate / delete =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user.']);
        exit;
    }

    if ($userId === (int) ($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => "You can't suspend or delete your own account."]);
        exit;
    }

    if ($action === 'suspend' || $action === 'reactivate') {
        $newStatus = $action === 'suspend' ? 'suspended' : 'active';
        $stmt = mysqli_prepare($connection, "UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $newStatus, $userId);
        $ok = mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        $error = mysqli_error($connection);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not update user.', 'debug' => $error]);
            exit;
        }
        if ($affected === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found, or already in that state.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => $action === 'suspend' ? 'User suspended.' : 'User reactivated.']);
        exit;
    }

    if ($action === 'delete') {
        // Delete this user's listings' dependents first, then the listings themselves.
        $listingIdsResult = mysqli_query($connection, "SELECT id FROM listings WHERE user_id = $userId");
        $listingIds = [];
        while ($row = mysqli_fetch_assoc($listingIdsResult)) {
            $listingIds[] = (int) $row['id'];
        }
        if (!empty($listingIds)) {
            $idList = implode(',', $listingIds);
            mysqli_query($connection, "DELETE FROM wishlists WHERE listing_id IN ($idList)");
            mysqli_query($connection, "DELETE FROM listing_images WHERE listing_id IN ($idList)");
            mysqli_query($connection, "DELETE FROM comments WHERE listing_id IN ($idList)");
            mysqli_query($connection, "DELETE FROM trending_snapshot WHERE listing_id IN ($idList)");
        }
        mysqli_query($connection, "DELETE FROM listings WHERE user_id = $userId");

        // This user's own activity across other tables.
        mysqli_query($connection, "DELETE FROM trade_requests WHERE requester_id = $userId OR owner_id = $userId");
        mysqli_query($connection, "DELETE FROM reviews WHERE reviewer_id = $userId OR reviewee_id = $userId");
        mysqli_query($connection, "DELETE FROM comment_likes WHERE user_id = $userId");
        mysqli_query($connection, "DELETE FROM comment_reports WHERE user_id = $userId");
        mysqli_query($connection, "DELETE FROM comments WHERE user_id = $userId");
        mysqli_query($connection, "DELETE FROM wishlists WHERE user_id = $userId");
        mysqli_query($connection, "DELETE FROM notifications WHERE user_id = $userId");

        $stmt = mysqli_prepare($connection, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        $ok = mysqli_stmt_execute($stmt);
        $error = mysqli_error($connection);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Could not delete user.', 'debug' => $error]);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'User and all related data deleted.']);
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
    $whereSearch = "WHERE (name LIKE ? OR email LIKE ?)";
    $likeTerm = '%' . $search . '%';
    $params = [$likeTerm, $likeTerm];
    $types = 'ss';
}

$countSql = "SELECT COUNT(*) AS total FROM users $whereSearch";
$countStmt = mysqli_prepare($connection, $countSql);
if ($types !== '') {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalUsers = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
mysqli_stmt_close($countStmt);
$totalPages = max(1, (int) ceil($totalUsers / $perPage));

$sql = "SELECT id, name, email, status, created_at,
               (SELECT COUNT(*) FROM trade_requests tr WHERE tr.status = 'accepted' AND (tr.requester_id = users.id OR tr.owner_id = users.id)) AS trades_count
        FROM users
        $whereSearch
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($connection, $sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . 'ii';
mysqli_stmt_bind_param($stmt, $allTypes, ...$allParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $displayName = $row['name'] ?: ucfirst(explode('@', $row['email'])[0]);
    $initials = strtoupper(substr($displayName, 0, 1) . (strpos($displayName, ' ') !== false ? substr(strstr($displayName, ' '), 1, 1) : substr($displayName, 1, 1)));
    $users[] = [
        'id'       => $row['id'],
        'initials' => $initials,
        'name'     => $displayName,
        'email'    => $row['email'],
        'joined'   => date('M Y', strtotime($row['created_at'])),
        'trades'   => (int) $row['trades_count'],
        'status'   => $row['status'],
    ];
}
mysqli_stmt_close($stmt);

$statusLabel = ['active' => 'Active', 'suspended' => 'Suspended', 'deactivated' => 'Deactivated'];
$statusBadgeClass = ['active' => 'accepted', 'suspended' => 'rejected', 'deactivated' => 'cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — Swapify Admin</title>
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
            <span class="fw-semibold">Manage Users</span>
        </div>

        <div class="dashboard-content">
            <div class="mb-4">
                <h2 class="mb-1">Users</h2>
                <p class="text-muted-swap mb-0"><?= number_format($totalUsers) ?> registered traders</p>
            </div>

            <div class="admin-table-card">
                <div class="admin-table-toolbar">
                    <form class="admin-table-search" method="GET" style="flex:1; max-width:360px;">
                        <i class="bi bi-search text-muted-swap"></i>
                        <input type="search" name="q" id="userSearchInput" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                    <span class="text-muted-swap small">Showing <?= count($users) ?> of <?= number_format($totalUsers) ?> users<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Joined</th>
                                <th>Trades</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if (empty($users)): ?>
                                <tr><td colspan="6" class="text-center text-muted-swap py-4">No users found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr data-user-id="<?= $user['id'] ?>" data-user-name="<?= htmlspecialchars($user['name']) ?>">
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="profile-card-avatar small"><?= htmlspecialchars($user['initials']) ?></div>
                                                <span class="fw-semibold"><?= htmlspecialchars($user['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-muted-swap"><?= htmlspecialchars($user['email']) ?></td>
                                        <td class="text-muted-swap"><?= $user['joined'] ?></td>
                                        <td><?= $user['trades'] ?></td>
                                        <td><span class="status-badge <?= $statusBadgeClass[$user['status']] ?? 'pending' ?>"><?= $statusLabel[$user['status']] ?? ucfirst($user['status']) ?></span></td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1">
                                                <a href="<?= asset('profile.php') ?>?id=<?= $user['id'] ?>" target="_blank" class="admin-row-icon-btn" aria-label="View"><i class="bi bi-eye"></i></a>
                                                <?php if ($user['status'] === 'suspended'): ?>
                                                    <button class="admin-row-icon-btn success reactivate-user-btn" aria-label="Reactivate"><i class="bi bi-play-circle"></i></button>
                                                <?php else: ?>
                                                    <button class="admin-row-icon-btn suspend-user-btn" aria-label="Suspend"><i class="bi bi-pause-circle"></i></button>
                                                <?php endif; ?>
                                                <button class="admin-row-icon-btn danger delete-user-btn" aria-label="Delete"><i class="bi bi-trash"></i></button>
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
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
                    <i class="bi bi-trash text-danger fs-4"></i>
                </div>
                <h5 class="mb-2">Delete <span id="deleteUserName">this user</span>?</h5>
                <p class="text-muted-swap mb-4">This permanently removes their account, listings, and trade history.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-light-swap px-4" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger px-4" id="confirmDeleteUserBtn">Delete</button>
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

<?php loadScripts('admin_users'); ?>
</body>
</html>