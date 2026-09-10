<?php
/**
 * Swapify — /api/delete-item.php
 * Deletes a listing that belongs to the logged-in user. Called via AJAX
 * from my-listings.js. Verifies ownership before deleting, and removes
 * dependent rows (images, tags, offers) in the same transaction.
 */
require_once __DIR__ . '/../connection.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must be logged in.']);
    exit;
}
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$itemId = isset($input['itemId']) ? (int) $input['itemId'] : 0;

if ($itemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid item id.']);
    exit;
}

// Confirm the listing exists and belongs to this user before touching anything.
$ownerStmt = mysqli_prepare($connection, "SELECT user_id FROM listings WHERE id = ?");
mysqli_stmt_bind_param($ownerStmt, "i", $itemId);
mysqli_stmt_execute($ownerStmt);
$ownerResult = mysqli_stmt_get_result($ownerStmt);
$owner = mysqli_fetch_assoc($ownerResult);
mysqli_stmt_close($ownerStmt);

if (!$owner) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Listing not found.']);
    exit;
}

if ((int) $owner['user_id'] !== (int) $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this listing.']);
    exit;
}

mysqli_begin_transaction($connection);

try {
    // ASSUMPTION: listing_images, listing_tags, and offers all have a
    // listing_id column. If your schema uses ON DELETE CASCADE foreign
    // keys instead, these three deletes are redundant but harmless.
    foreach (['listing_images', 'listing_tags', 'offers'] as $table) {
        $stmt = mysqli_prepare($connection, "DELETE FROM {$table} WHERE listing_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $itemId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $stmt = mysqli_prepare($connection, "DELETE FROM listings WHERE id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $userId);
    mysqli_stmt_execute($stmt);
    $deleted = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($deleted === 0) {
        throw new mysqli_sql_exception('Listing was not deleted.');
    }

    mysqli_commit($connection);
    echo json_encode(['success' => true, 'message' => 'Listing deleted.']);

} catch (mysqli_sql_exception $e) {
    mysqli_rollback($connection);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong while deleting the listing. Please try again.']);
}