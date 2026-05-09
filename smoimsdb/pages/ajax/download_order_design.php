<?php
/* ============================================================
   SMOIMS - AJAX: Download Uploaded Design Image
   FILE: pages/ajax/download_order_design.php
   ============================================================ */
require_once '../../includes/config.php';
requireStaffLogin();

$itemId = (int)($_GET['item_id'] ?? 0);
$field  = $_GET['field'] ?? '';
$allowed = ['design_front', 'design_back', 'design_left', 'design_right'];

if (!$itemId || !in_array($field, $allowed, true)) {
    http_response_code(400);
    exit('Invalid request');
}

$stmt = $conn->prepare(
    "SELECT oi.$field AS image_data, oi.order_id, o.customer_id
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
      WHERE oi.id = ?
      LIMIT 1"
);
$stmt->bind_param('i', $itemId);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if (!$result || $result->num_rows === 0) {
    http_response_code(404);
    exit('Image not found');
}

$row = $result->fetch_assoc();
$image = $row['image_data'];
if (empty($image)) {
    http_response_code(404);
    exit('No image data');
}

$imageData = $image;
if (str_starts_with($imageData, 'data:image/')) {
    list($meta, $data) = explode(',', $imageData, 2) + ['', ''];
    $imageData = base64_decode($data, true) ?: null;
    $info = [];
    if (preg_match('#^data:([^;]+);#', $meta, $m)) {
        $mime = $m[1];
    }
} else {
    $info = @getimagesizefromstring($imageData);
    $mime = $info['mime'] ?? 'image/jpeg';
}

if (empty($imageData)) {
    http_response_code(404);
    exit('No image data');
}

$mime = $mime ?? 'image/jpeg';
$exts = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];
$ext = $exts[$mime] ?? 'jpg';

$filename = sprintf('order_%d_item_%d_%s.%s', (int)$row['order_id'], $itemId, substr($field, 7), $ext);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . strlen($imageData));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $imageData;
exit;
