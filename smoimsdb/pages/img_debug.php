<?php
/* ============================================================
   FILE: pages/staff/img_debug.php
   PURPOSE: Temporary debug script — run this once to diagnose
            why image uploads fail. DELETE after fixing.
   USAGE: Visit  pages/staff/img_debug.php  in your browser.
   ============================================================ */
require_once '../includes/config.php';
requireStaffLogin();

echo "<h2>Image Upload Debug</h2><pre>";

/* 1. Check PHP limits */
echo "upload_max_filesize : " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size       : " . ini_get('post_max_size') . "\n";
echo "memory_limit        : " . ini_get('memory_limit') . "\n\n";

/* 2. Check column types in inventory table */
$res = $conn->query("SHOW COLUMNS FROM inventory");
echo "=== inventory table columns ===\n";
while ($col = $res->fetch_assoc()) {
    echo str_pad($col['Field'], 20) . $col['Type'] . "\n";
}

/* 3. Try a test write of a small base64 string to profile_image */
$testData = 'data:image/png;base64,' . base64_encode(str_repeat('A', 100));
$stmt = $conn->prepare("UPDATE inventory SET profile_image = ? WHERE id = (SELECT MIN(id) FROM (SELECT id FROM inventory LIMIT 1) t)");
if ($stmt) {
    $stmt->bind_param("s", $testData);
    $ok = $stmt->execute();
    echo "\nTest write to profile_image: " . ($ok ? "✅ OK" : "❌ FAILED — " . $conn->error) . "\n";
    $stmt->close();
} else {
    echo "\nprepare() failed: " . $conn->error . "\n";
    echo "→ Column 'profile_image' may not exist!\n";
}

/* 4. Check max_allowed_packet */
$r = $conn->query("SHOW VARIABLES LIKE 'max_allowed_packet'");
if ($row = $r->fetch_assoc()) {
    $mb = round($row['Value'] / 1024 / 1024, 1);
    echo "\nMySQL max_allowed_packet: {$row['Value']} bytes ({$mb} MB)\n";
    if ($mb < 5) echo "⚠️  This is LOW — base64 images need at least 5–10 MB here.\n";
}

echo "\n=== Done ===\n</pre>";