<?php
/* ============================================================
   FILE: pages/staff/upload_product_images.php
   ============================================================ */

require_once '../includes/config.php';
requireStaffLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

/* ══════════════════════════════════════════
   Resolve item_id
   Accept either ?item_id=123 or ?item_name=T-Shirt
══════════════════════════════════════════ */
$itemId   = (int)($_POST['item_id'] ?? 0);
$itemName = trim($_POST['item_name'] ?? '');

if (!$itemId && $itemName) {
    /* Look up by name */
    $stmt = $conn->prepare("SELECT id FROM inventory WHERE item_name = ? LIMIT 1");
    $stmt->bind_param("s", $itemName);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $itemId = (int)$row['id'];
    }
    $stmt->close();
}

if (!$itemId) {
    echo json_encode(['success' => false, 'error' => 'Item not found. Provide a valid item_id or item_name.']);
    exit;
}

$side   = trim($_POST['side']   ?? '');
$action = trim($_POST['action'] ?? 'upload');

$col_map = [
    'front'   => 'image_front',
    'back'    => 'image_back',
    'left'    => 'image_left',
    'right'   => 'image_right',
    'profile' => 'profile_image',
];

if (!$side || !isset($col_map[$side])) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing side. Must be: front, back, left, right, profile.']);
    exit;
}

$col = $col_map[$side];

/* ══════════════════════════════════════════
   REMOVE action — set DB column to NULL
══════════════════════════════════════════ */
if ($action === 'remove') {
    $stmt = $conn->prepare("UPDATE inventory SET `$col` = NULL WHERE id = ?");
    $stmt->bind_param("i", $itemId);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'removed' => $ok]);
    exit;
}

/* ══════════════════════════════════════════
   UPLOAD action — convert to base64, save to DB
══════════════════════════════════════════ */
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $codes = [
        1 => 'File exceeds server upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
        2 => 'File exceeds form MAX_FILE_SIZE',
        3 => 'File only partially uploaded',
        4 => 'No file was sent',
        6 => 'Missing temp folder',
        7 => 'Failed to write to disk',
    ];
    $code = $_FILES['image']['error'] ?? 4;
    echo json_encode(['success' => false, 'error' => $codes[$code] ?? "PHP upload error code $code"]);
    exit;
}

/* Read raw bytes and create a preview URL for immediate display */
$raw  = file_get_contents($_FILES['image']['tmp_name']);
if ($raw === false) {
    echo json_encode(['success' => false, 'error' => 'Could not read uploaded file from temp storage.']);
    exit;
}

$mime = $_FILES['image']['type'] ?: 'image/jpeg';
if (!str_starts_with($mime, 'image/')) $mime = 'image/jpeg';

$dataUrl = 'data:' . $mime . ';base64,' . base64_encode($raw);

/* Save raw bytes to DB */
$stmt = $conn->prepare("UPDATE inventory SET `$col` = ? WHERE id = ?");
$stmt->bind_param("si", $raw, $itemId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok) {
    echo json_encode([
        'success' => true,
        'dataUrl' => $dataUrl,       /* returned so JS can update <img> immediately */
        'message' => 'Image saved to database successfully.',
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => 'Database save failed: ' . $conn->error,
    ]);
}
exit;