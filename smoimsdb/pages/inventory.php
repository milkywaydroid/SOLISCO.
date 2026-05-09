<?php
/* ============================================================
   FILE: pages/staff/inventory.php
   Full color-dependent size stock management
   ============================================================ */

@ini_set('upload_max_filesize', '20M');
@ini_set('post_max_size',       '25M');
@ini_set('memory_limit',        '256M');

require_once '../includes/config.php';
requireStaffLogin();

/* ══════════════════════════════════════════════════════════════
   CONSTANTS
══════════════════════════════════════════════════════════════ */

$SIZE_OPTIONS = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'Free Size'];

$IMAGE_COL_MAP = [
    'front'   => 'image_front',
    'back'    => 'image_back',
    'left'    => 'image_left',
    'right'   => 'image_right',
    'profile' => 'profile_image',
];

/* ══════════════════════════════════════════════════════════════
   HELPER FUNCTIONS
══════════════════════════════════════════════════════════════ */

function getComputedColor(string $name): string {
    $map = [
        'white' => '#f8fafc', 'black'    => '#1e293b', 'red'    => '#ef4444',
        'blue'  => '#3b82f6', 'green'    => '#22c55e', 'yellow' => '#eab308',
        'purple'=> '#a855f7', 'pink'     => '#ec4899', 'orange' => '#f97316',
        'gray'  => '#9ca3af', 'grey'     => '#9ca3af', 'brown'  => '#92400e',
        'beige' => '#d4b896', 'coral'    => '#f87171', 'navy'   => '#1e3a5f',
        'teal'  => '#14b8a6', 'cream'    => '#fef9c3', 'lavender'=> '#ddd6fe',
        'maroon'=> '#881337', 'sky'      => '#7dd3fc',
    ];
    $lower = strtolower(trim($name));
    foreach ($map as $keyword => $hex) {
        if (str_contains($lower, $keyword)) return $hex;
    }
    return '#a78bfa';
}

function ensureStockQtyColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $col = $conn->query("SHOW COLUMNS FROM inventory_color_stock LIKE 'stock_qty'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE inventory_color_stock ADD COLUMN stock_qty INT NOT NULL DEFAULT 0");
    }
    $checked = true;
}

function hasStockQtyColumn(mysqli $conn): bool {
    static $result = null;
    if ($result === null) {
        $col    = $conn->query("SHOW COLUMNS FROM inventory_color_stock LIKE 'stock_qty'");
        $result = ($col && $col->num_rows > 0);
    }
    return $result;
}

function getColorStock(mysqli $conn, int $itemId): array {
    $sqQty = hasStockQtyColumn($conn) ? 'cs.stock_qty' : '0';
    $stmt = $conn->prepare(
        "SELECT cs.id, cs.color_name, $sqQty AS stock_qty,
                (SELECT COUNT(*) FROM inventory_size_variants sv WHERE sv.color_id = cs.id) AS size_count
           FROM inventory_color_stock cs
          WHERE cs.item_id = ?
          ORDER BY cs.color_name"
    );
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getSizeStockByColor(mysqli $conn, int $colorId): array {
    $check = $conn->query("SHOW TABLES LIKE 'inventory_size_variants'");
    if (!$check || $check->num_rows === 0) return [];
    $stmt = $conn->prepare(
        "SELECT sv.id, sv.size_name, sv.quantity AS stock_qty, sv.color_id
           FROM inventory_size_variants sv
          WHERE sv.color_id = ?
          ORDER BY FIELD(sv.size_name,'XS','S','M','L','XL','XXL','Free Size')"
    );
    $stmt->bind_param("i", $colorId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getAllSizeStockForItem(mysqli $conn, int $itemId): array {
    $check = $conn->query("SHOW TABLES LIKE 'inventory_size_variants'");
    if (!$check || $check->num_rows === 0) return [];
    $stmt = $conn->prepare(
        "SELECT sv.id, sv.size_name, sv.quantity AS stock_qty,
                sv.color_id, cs.color_name
           FROM inventory_size_variants sv
           JOIN inventory_color_stock cs ON cs.id = sv.color_id
          WHERE cs.item_id = ?
          ORDER BY cs.color_name, FIELD(sv.size_name,'XS','S','M','L','XL','XXL','Free Size')"
    );
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function syncColorAndItemQty(mysqli $conn, int $colorId): void {
    $conn->query(
        "UPDATE inventory_color_stock
            SET stock_qty = (
                SELECT COALESCE(SUM(quantity), 0)
                  FROM inventory_size_variants
                 WHERE color_id = $colorId
            )
          WHERE id = $colorId"
    );
    $conn->query(
        "UPDATE inventory i
            SET i.quantity = (
                SELECT COALESCE(SUM(cs.stock_qty), 0)
                  FROM inventory_color_stock cs
                 WHERE cs.item_id = i.id
            )
          WHERE i.id = (SELECT item_id FROM inventory_color_stock WHERE id = $colorId LIMIT 1)"
    );
}

function syncItemQtyFromColors(mysqli $conn, int $itemId): void {
    $stmt = $conn->prepare(
        "UPDATE inventory
            SET quantity = (
                SELECT COALESCE(SUM(stock_qty), 0)
                  FROM inventory_color_stock
                 WHERE item_id = ?
            )
          WHERE id = ?"
    );
    $stmt->bind_param("ii", $itemId, $itemId);
    $stmt->execute();
    $stmt->close();
}

function saveImageToDB(mysqli $conn, int $itemId, string $column, string $rawBytes): bool {
    $stmt = $conn->prepare("UPDATE inventory SET `$column` = ? WHERE id = ?");
    $null = null;
    $stmt->bind_param("bi", $null, $itemId);
    $stmt->send_long_data(0, $rawBytes);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function clearImageInDB(mysqli $conn, int $itemId, string $column): bool {
    $stmt = $conn->prepare("UPDATE inventory SET `$column` = NULL WHERE id = ?");
    $stmt->bind_param("i", $itemId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}


/* ══════════════════════════════════════════════════════════════
   AJAX: image upload/remove
══════════════════════════════════════════════════════════════ */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'image') {
    header('Content-Type: application/json');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $side   = trim($_POST['side']    ?? '');
    $action = trim($_POST['action']  ?? 'upload');
    if (!$itemId || !$side || !isset($IMAGE_COL_MAP[$side])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']); exit;
    }
    $column = $IMAGE_COL_MAP[$side];
    if ($action === 'remove') {
        $ok = clearImageInDB($conn, $itemId, $column);
        echo json_encode(['success' => $ok, 'error' => $ok ? null : $conn->error]); exit;
    }
    $uploadErrorMessages = [
        UPLOAD_ERR_INI_SIZE   => 'File too large (server limit: ' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit).',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file received.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temp folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server error: cannot write to disk.',
    ];
    $phpError = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($phpError !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => $uploadErrorMessages[$phpError] ?? "Upload error code $phpError."]); exit;
    }
    $rawBytes    = file_get_contents($_FILES['image']['tmp_name']);
    $detectedMime = mime_content_type($_FILES['image']['tmp_name']);
    if (!str_starts_with($detectedMime ?: '', 'image/')) {
        echo json_encode(['success' => false, 'error' => 'Not a valid image.']); exit;
    }
    $mime    = $detectedMime ?: 'image/jpeg';
    $ok = saveImageToDB($conn, $itemId, $column, $rawBytes);
    if ($ok) {
        // Build a data URL for the browser preview from the raw bytes we just saved
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($rawBytes);
        echo json_encode(['success' => true, 'dataUrl' => $dataUrl, 'fileSizeKB' => round(strlen($rawBytes) / 1024, 1)]);
    } else {
        $hint = (stripos($conn->error, 'max_allowed_packet') !== false) ? ' Increase MySQL max_allowed_packet.' : '';
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error . $hint]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   AJAX: design boundary save / load
══════════════════════════════════════════════════════════════ */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'design_boundary') {
    header('Content-Type: application/json');
    $action = trim($_POST['action'] ?? $_GET['action'] ?? '');
    $itemId = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
    if (!$itemId) { echo json_encode(['success'=>false,'error'=>'Missing item ID']); exit; }

    // Ensure design_boundaries column exists
    $conn->query("ALTER TABLE inventory ADD COLUMN IF NOT EXISTS design_boundaries TEXT NULL");

    if ($action === 'save') {
        $sides = ['front','back','left','right'];
        $boundaries = [];
        foreach ($sides as $s) {
            $key = 'boundary_' . $s;
            if (!empty($_POST[$key])) {
                $decoded = json_decode($_POST[$key], true);
                if ($decoded && isset($decoded['x'],$decoded['y'],$decoded['w'],$decoded['h'])) {
                    $boundaries[$s] = [
                        'x' => round((float)$decoded['x'], 4),
                        'y' => round((float)$decoded['y'], 4),
                        'w' => round((float)$decoded['w'], 4),
                        'h' => round((float)$decoded['h'], 4),
                    ];
                }
            }
        }
        $json = json_encode($boundaries);
        $stmt = $conn->prepare("UPDATE inventory SET design_boundaries = ? WHERE id = ?");
        $stmt->bind_param("si", $json, $itemId);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok,'boundaries'=>$boundaries]);
    } elseif ($action === 'load') {
        $stmt = $conn->prepare("SELECT design_boundaries FROM inventory WHERE id = ?");
        $stmt->bind_param("i", $itemId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $b = json_decode($row['design_boundaries'] ?? '{}', true) ?: [];
        echo json_encode(['success'=>true,'boundaries'=>$b]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Unknown action']);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   AJAX: size stock per color
══════════════════════════════════════════════════════════════ */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'size_stock') {
    header('Content-Type: application/json');
    $colorId = (int)($_GET['color_id'] ?? 0);
    if (!$colorId) { echo json_encode([]); exit; }

    // Check if this color's item has has_sizes=1
    $chkStmt = $conn->prepare(
        "SELECT i.has_sizes FROM inventory i
           JOIN inventory_color_stock cs ON cs.item_id = i.id
          WHERE cs.id = ? LIMIT 1"
    );
    $chkStmt->bind_param("i", $colorId);
    $chkStmt->execute();
    $chkRow = $chkStmt->get_result()->fetch_assoc();
    $chkStmt->close();

    $rows = getSizeStockByColor($conn, $colorId);

    // Auto-create default size variants when has_sizes is ON but none exist yet
    if (($chkRow['has_sizes'] ?? 0) == 1 && empty($rows)) {
        $defaultSizes = ['XS','S','M','L','XL','XXL','Free Size'];
        $ins = $conn->prepare(
            "INSERT IGNORE INTO inventory_size_variants (color_id, size_name, quantity) VALUES (?, ?, 0)"
        );
        foreach ($defaultSizes as $sz) {
            $ins->bind_param("is", $colorId, $sz);
            $ins->execute();
        }
        $ins->close();
        $rows = getSizeStockByColor($conn, $colorId);
    }

    echo json_encode($rows);
    exit;
}


/* ══════════════════════════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════════════════════════ */

$message = '';
$error   = '';

/* --- Add new item --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $name        = clean($conn, $_POST['item_name']   ?? '');
    $category    = clean($conn, $_POST['category']    ?? '');
    $total_qty   = (int)($_POST['quantity']            ?? 0);
    $price       = (float)($_POST['price']             ?? 0);
    $description = clean($conn, $_POST['description'] ?? '');
    $has_sizes   = isset($_POST['has_sizes']) ? 1 : 0;
    $sel_colors  = $_POST['selected_colors'] ?? [];
    $colors_raw  = implode(', ', array_map('trim', $sel_colors));

    if ($name && $category && $price >= 0) {
        $stmt = $conn->prepare(
            "INSERT INTO inventory (item_name, category, quantity, price, description, colors, has_sizes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssidssi", $name, $category, $total_qty, $price, $description, $colors_raw, $has_sizes);

        if ($stmt->execute()) {
            $newItemId = $stmt->insert_id;
            $stmt->close();

            ensureStockQtyColumn($conn);

            $selectedSizes = array_filter($_POST['sizes'] ?? []);
            // color_size_qtys keyed by colorName->sizeName
            $colorSizeQtys = $_POST['color_size_qtys'] ?? [];

            if (!empty($sel_colors)) {
                $cStmt = $conn->prepare(
                    "INSERT INTO inventory_color_stock (item_id, color_name, stock_qty) VALUES (?, ?, ?)"
                );
                foreach ($sel_colors as $colorName) {
                    $colorName = clean($conn, trim($colorName));
                    if (!$colorName) continue;

                    $colorQty = 0;
                    $cStmt->bind_param("isi", $newItemId, $colorName, $colorQty);
                    $cStmt->execute();
                    $newColorId = $cStmt->insert_id;

                    if ($has_sizes && !empty($selectedSizes)) {
                        $sStmt = $conn->prepare(
                            "INSERT IGNORE INTO inventory_size_variants (color_id, size_name, quantity) VALUES (?, ?, ?)"
                        );
                        foreach ($selectedSizes as $sizeName) {
                            $qty = (int)(($colorSizeQtys[$colorName][$sizeName] ?? 0));
                            $sStmt->bind_param("isi", $newColorId, $sizeName, $qty);
                            $sStmt->execute();
                        }
                        $sStmt->close();
                        syncColorAndItemQty($conn, $newColorId);
                    } else {
                        // No sizes: use flat qty per color
                        $flatQty = (int)($_POST['color_flat_qtys'][$colorName] ?? 0);
                        $conn->query("UPDATE inventory_color_stock SET stock_qty = $flatQty WHERE id = $newColorId");
                    }
                }
                $cStmt->close();
                syncItemQtyFromColors($conn, $newItemId);
            }

            // PRG: redirect after POST to prevent duplicate submission on reload
            header("Location: inventory.php?msg=" . urlencode("Item \"$name\" added successfully."));
            exit;
        } else {
            $stmt->close();
            $error = "Database error: " . $conn->error;
        }
        
    } else {
        $error = "Please fill in all required fields (Name, Category, Price).";
    }
}

/* --- Update total quantity directly (no-color items) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $error = 'Total stock is computed from color and size stock. Update color or size quantities only.';
}

/* --- Update color-level stock (no-size items) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_color_stock'])) {
    ensureStockQtyColumn($conn);
    $itemId    = (int)($_POST['item_id']  ?? 0);
    $colorIds  = $_POST['color_ids']  ?? [];
    $colorQtys = $_POST['color_qtys'] ?? [];
    $stmt = $conn->prepare(
        "UPDATE inventory_color_stock SET stock_qty = ? WHERE id = ? AND item_id = ?"
    );
    foreach ($colorIds as $idx => $cid) {
        $cid = (int)$cid;
        $qty = max(0, (int)($colorQtys[$idx] ?? 0));
        $stmt->bind_param("iii", $qty, $cid, $itemId);
        $stmt->execute();
    }
    $stmt->close();
    syncItemQtyFromColors($conn, $itemId);
    $message = "Color stock updated.";
}

/* --- Update size-level stock (per color) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_size_stock'])) {
    $colorId  = (int)($_POST['color_id'] ?? 0);
    $sizeIds  = $_POST['size_ids']  ?? [];
    $sizeQtys = $_POST['size_qtys'] ?? [];
    $stmt = $conn->prepare(
        "UPDATE inventory_size_variants SET quantity = ? WHERE id = ? AND color_id = ?"
    );
    foreach ($sizeIds as $idx => $sid) {
        $sid = (int)$sid;
        $qty = max(0, (int)($sizeQtys[$idx] ?? 0));
        $stmt->bind_param("iii", $qty, $sid, $colorId);
        $stmt->execute();
    }
    $stmt->close();
    if ($colorId) syncColorAndItemQty($conn, $colorId);
    $message = "Size stock updated.";
}

/* --- Bulk copy sizes from one color to all others --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_copy_sizes'])) {
    $itemId      = (int)($_POST['item_id']       ?? 0);
    $srcColorId  = (int)($_POST['src_color_id']  ?? 0);
    if ($itemId && $srcColorId) {
        $srcSizes = getSizeStockByColor($conn, $srcColorId);
        $otherRes = $conn->prepare(
            "SELECT id FROM inventory_color_stock WHERE item_id = ? AND id != ?"
        );
        $otherRes->bind_param("ii", $itemId, $srcColorId);
        $otherRes->execute();
        $otherColors = $otherRes->get_result()->fetch_all(MYSQLI_ASSOC);
        $otherRes->close();
        foreach ($otherColors as $oc) {
            $tColorId = (int)$oc['id'];
            foreach ($srcSizes as $sv) {
                $check = $conn->prepare(
                    "SELECT id FROM inventory_size_variants WHERE color_id = ? AND size_name = ?"
                );
                $check->bind_param("is", $tColorId, $sv['size_name']);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();
                if ($exists) {
                    $upd = $conn->prepare("UPDATE inventory_size_variants SET quantity = ? WHERE id = ?");
                    $upd->bind_param("ii", $sv['stock_qty'], $exists['id']);
                    $upd->execute(); $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO inventory_size_variants (color_id, size_name, quantity) VALUES (?, ?, ?)");
                    $ins->bind_param("isi", $tColorId, $sv['size_name'], $sv['stock_qty']);
                    $ins->execute(); $ins->close();
                }
            }
            syncColorAndItemQty($conn, $tColorId);
        }
        $message = "Sizes copied to all colors and totals synced.";
    }
}

/* --- Update item details --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_details'])) {
    $itemId      = (int)($_POST['item_id']        ?? 0);
    $itemName    = clean($conn, $_POST['item_name']   ?? '');
    $category    = clean($conn, $_POST['category']    ?? '');
    $description = clean($conn, $_POST['description'] ?? '');
    $colors      = clean($conn, $_POST['colors']      ?? '');
    $has_sizes   = isset($_POST['has_sizes']) ? 1 : 0;
    $stmt = $conn->prepare(
        "UPDATE inventory SET item_name = ?, category = ?, description = ?, colors = ?, has_sizes = ? WHERE id = ?"
    );
    $stmt->bind_param("sssiii", $itemName, $category, $description, $colors, $has_sizes, $itemId);
    $stmt->execute() ? $message = 'Details updated.' : $error = 'Update failed: ' . $conn->error;
    $stmt->close();
    if (!empty($colors)) {
        ensureStockQtyColumn($conn);
        foreach (array_filter(array_map('trim', explode(',', $colors))) as $colorName) {
            $chk = $conn->prepare("SELECT id FROM inventory_color_stock WHERE item_id = ? AND color_name = ?");
            $chk->bind_param("is", $itemId, $colorName);
            $chk->execute();
            $existingColor = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$existingColor) {
                $ins = $conn->prepare("INSERT INTO inventory_color_stock (item_id, color_name, stock_qty) VALUES (?, ?, 0)");
                $ins->bind_param("is", $itemId, $colorName);
                $ins->execute();
                $existingColor = ['id' => $ins->insert_id];
                $ins->close();
            }

            // Auto-create size variants if has_sizes is now ON
            if ($has_sizes && !empty($existingColor['id'])) {
                $cid = (int)$existingColor['id'];
                $defaultSizes = ['XS','S','M','L','XL','XXL','Free Size'];
                $sIns = $conn->prepare(
                    "INSERT IGNORE INTO inventory_size_variants (color_id, size_name, quantity) VALUES (?, ?, 0)"
                );
                foreach ($defaultSizes as $sz) {
                    $sIns->bind_param("is", $cid, $sz);
                    $sIns->execute();
                }
                $sIns->close();
            }
        }
    }
}


/* ══════════════════════════════════════════════════════════════
   GET HANDLERS
══════════════════════════════════════════════════════════════ */

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $stmt  = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $delId);
    $stmt->execute()
        ? header("Location: inventory.php?msg=" . urlencode('Item deleted.'))
        : header("Location: inventory.php?msg=" . urlencode('Delete failed: ' . $conn->error));
    $stmt->close();
    exit;
}

if (isset($_GET['msg']) && !$message) {
    $message = htmlspecialchars($_GET['msg']);
}

$search = clean($conn, $_GET['search'] ?? '');
if ($search) {
    $stmt = $conn->prepare(
        "SELECT * FROM inventory WHERE item_name LIKE ? OR category LIKE ? ORDER BY item_name ASC"
    );
    $like = "%$search%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $items = $stmt->get_result();
    $stmt->close();
} else {
    $items = $conn->query("SELECT * FROM inventory ORDER BY item_name ASC");
}

$lowItems = $conn->query("SELECT * FROM inventory WHERE quantity < 10 ORDER BY quantity ASC");

/* Build product list as array so we can iterate + compute stats */
$itemsArr = [];
if ($items) { while ($r = $items->fetch_assoc()) { $itemsArr[] = $r; } }

$stat_total  = count($itemsArr);
$stat_active = 0; $stat_low = 0; $stat_out = 0;
$catSet = [];
foreach ($itemsArr as $r) {
    $q = (int)$r['quantity'];
    if ($q === 0) $stat_out++;
    elseif ($q < 10) { $stat_low++; $stat_active++; }
    else $stat_active++;
    if (!empty($r['category'])) $catSet[$r['category']] = true;
}
$catList = array_keys($catSet); sort($catList);


?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <style>

    :root {
      --pp-50:#f5f3ff;--pp-100:#ede9fe;--pp-200:#ddd6fe;--pp-300:#c4b5fd;
      --pp-400:#a78bfa;--pp-500:#8b5cf6;--pp-600:#7c3aed;--pp-700:#6d28d9;
      --pp-800:#5b21b6;--pp-900:#4c1d95;
      --surface:#ffffff;--surface-alt:#faf9ff;--surface-hover:#f5f3ff;
      --border:#ede9fe;--border-strong:#c4b5fd;
      --text-primary:#1e1b4b;--text-secondary:#4c4580;--text-muted:#7c74a3;
      --radius-sm:8px;--radius-md:12px;--radius-lg:16px;--radius-xl:20px;
      --shadow-sm:0 1px 3px rgba(109,40,217,.08),0 1px 2px rgba(109,40,217,.04);
      --shadow-md:0 4px 16px rgba(109,40,217,.10),0 2px 6px rgba(109,40,217,.06);
      --shadow-lg:0 8px 32px rgba(109,40,217,.14);
      --font-head:'Sora',sans-serif;--font-body:'DM Sans',sans-serif;
      --ok-bg:#ecfdf5;--ok-text:#065f46;--ok-border:#a7f3d0;
      --warn-bg:#fffbeb;--warn-text:#92400e;--warn-border:#fde68a;
      --err-bg:#fef2f2;--err-text:#991b1b;--err-border:#fecaca;
    }

    *{margin:0;padding:0;box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{
      font-family:'Plus Jakarta Sans',system-ui,sans-serif;
      background:
        radial-gradient(1200px 600px at -10% -10%, rgba(167,139,250,.10), transparent 60%),
        radial-gradient(900px 500px at 110% 0%, rgba(240,171,252,.10), transparent 60%),
        var(--bg);
      color:var(--ink);line-height:1.55;min-height:100vh;
    }
    a{color:inherit;text-decoration:none}
    ::selection{background:var(--pastel-accent);color:#fff}

    /* Layout — works alongside sidebar.php */
    .layout{display:flex;min-height:100vh;align-items:flex-start}
    .main{flex:1;padding:32px 40px;min-width:0;animation:fade-up .5s var(--ease) both}
    @media (max-width:768px){.main{padding:20px}}

    /* Universal animations */
    @keyframes fade-up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
    @keyframes fade-in{from{opacity:0}to{opacity:1}}
    @keyframes pop-in{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}

    /* Mobile sidebar toggle */
    .menu-toggle{
      display:none;background:var(--surface);border:1px solid var(--border);
      padding:10px 14px;border-radius:12px;font-weight:700;cursor:pointer;
      box-shadow:var(--shadow-sm);
    }
    @media (max-width:768px){.menu-toggle{display:inline-flex}}


    .inv-page{padding:0;width:100%;}
    .inv-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;gap:16px;flex-wrap:wrap;}
    .inv-header h1{font-family:var(--font-head);font-size:1.75rem;font-weight:800;color:var(--text-primary);line-height:1.2;}
    .inv-header p{font-size:.9rem;color:var(--text-muted);margin-top:4px;}

    .btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:var(--radius-md);font-family:var(--font-body);font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none;white-space:nowrap;}
    .btn-primary{background: #564586;color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.25);}
    .btn-primary:hover{background:var(--pp-700);transform:translateY(-1px);}
    .btn-secondary{background:var(--pp-100);color:var(--pp-700);border:1.5px solid var(--pp-200);}
    .btn-secondary:hover{background:var(--pp-200);}
    .btn-ghost{background:transparent;color:var(--text-secondary);border:1.5px solid var(--border);}
    .btn-ghost:hover{background:var(--surface-hover);}
    .btn-sm{padding:5px 10px;font-size:.78rem;border-radius:var(--radius-sm);}
    .btn-danger{background:var(--err-bg);color:var(--err-text);border:1.5px solid var(--err-border);}
    .btn-danger:hover{background:#fee2e2;}
    .w-full{width:100%;}
    .mt-4{margin-top:16px;}

    .alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--radius-md);font-size:.875rem;font-weight:500;margin-bottom:20px;border:1.5px solid transparent;}
    .alert-success{background:var(--ok-bg);color:var(--ok-text);border-color:var(--ok-border);}
    .alert-danger{background:var(--err-bg);color:var(--err-text);border-color:var(--err-border);}

    .low-stock-card{background:var(--warn-bg);border:1.5px solid var(--warn-border);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:24px;}
    .low-stock-card .low-title{font-family:var(--font-head);font-size:.85rem;font-weight:700;color:var(--warn-text);margin-bottom:10px;}
    .low-stock-tags{display:flex;flex-wrap:wrap;gap:6px;}
    .low-tag{padding:3px 10px;border-radius:999px;background:#fef3c7;color:var(--warn-text);font-size:.75rem;font-weight:600;border:1px solid #fde68a;}

    .search-row{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
    .search-row input{flex:1;min-width:200px;padding:10px 14px;border-radius:var(--radius-md);border:1.5px solid var(--border);background:var(--surface);font-family:var(--font-body);font-size:.9rem;color:var(--text-primary);transition:border-color .15s,box-shadow .15s;}
    .search-row input:focus{outline:none;border-color:var(--pp-400);box-shadow:0 0 0 3px rgba(167,139,250,.2);}

    .table-card{background:var(--surface);border-radius:var(--radius-xl);border:1.5px solid var(--border);box-shadow:var(--shadow-sm);overflow:hidden;}
    .table-wrapper{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;min-width:780px;}
    thead th{background:var(--pp-50);color:var(--text-secondary);font-family:var(--font-head);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:13px 16px;text-align:left;border-bottom:1.5px solid var(--border);white-space:nowrap;}
    tbody td{padding:13px 16px;vertical-align:middle;border-bottom:1px solid var(--pp-50);font-size:.875rem;color:var(--text-primary);}
    tbody tr:last-child td{border-bottom:none;}
    tbody tr{transition:background .12s;}
    tbody tr:hover{background:var(--surface-hover);}

    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:.72rem;font-weight:700;white-space:nowrap;}
    .badge-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
    .badge-low{background:var(--warn-bg);color:var(--warn-text);border:1px solid #fde68a;}
    .badge-out{background:var(--err-bg);color:var(--err-text);border:1px solid var(--err-border);}
    .badge-cat{background:var(--pp-100);color:var(--pp-700);border:1px solid var(--pp-200);}

    .tbl-thumb{width:44px;height:44px;border-radius:var(--radius-sm);object-fit:cover;border:1.5px solid var(--border);}
    .tbl-no-img{width:44px;height:44px;border-radius:var(--radius-sm);border:1.5px dashed var(--border-strong);background:var(--pp-50);display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:600;color:var(--pp-400);letter-spacing:.03em;}

    .qty-form{display:flex;align-items:center;gap:6px;}
    .qty-input{width:68px;padding:5px 8px;border-radius:var(--radius-sm);border:1.5px solid var(--border);font-family:var(--font-body);font-size:.875rem;color:var(--text-primary);}
    .qty-input:focus{outline:none;border-color:var(--pp-400);}

    .actions-cell{display:flex;flex-direction:column;align-items:stretch;gap:4px;padding:8px 0;}
    .btn-act{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:var(--radius-sm);font-size:.72rem;font-weight:700;cursor:pointer;border:1.5px solid transparent;transition:all .13s;white-space:nowrap;background:none;letter-spacing:.02em;width:100%;justify-content:center;}
    .btn-act-purple{background:var(--pp-100);color:var(--pp-700);border-color:var(--pp-200);}
    .btn-act-purple:hover{background:var(--pp-200);}
    .btn-act-slate{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    .btn-act-slate:hover{background:#e2e8f0;}
    .btn-act-amber{background:#fef3c7;color:#92400e;border-color:#fde68a;}
    .btn-act-amber:hover{background:#fde68a;}
    .btn-act-red{background:var(--err-bg);color:var(--err-text);border-color:var(--err-border);}
    .btn-act-red:hover{background:#fee2e2;}

    .color-dot{width:10px;height:10px;border-radius:50%;display:inline-block;border:1.5px solid rgba(0,0,0,.12);}

    .empty-state{text-align:center;padding:52px 24px;}
    .empty-state p{color:var(--text-muted);font-size:.9rem;}

    .db-notice{background:#fef3c7;border:1.5px solid #fde68a;border-radius:var(--radius-md);padding:12px 16px;margin-bottom:20px;font-size:.83rem;color:#92400e;line-height:1.55;}
    .db-notice strong{display:block;margin-bottom:4px;font-size:.85rem;}
    .db-notice code{background:rgba(0,0,0,.08);padding:1px 5px;border-radius:4px;font-size:.78rem;font-family:monospace;}

    /* ── Modal base ── */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(30,27,75,.45);backdrop-filter:blur(4px);z-index:1000;align-items:center;justify-content:center;padding:16px;}
    .modal-overlay.open{display:flex;}
    .modal-box{background:var(--surface);border-radius:var(--radius-xl);width:100%;max-width:640px;max-height:92vh;overflow-y:auto;padding:32px 36px;position:relative;box-shadow:var(--shadow-lg);border:1.5px solid var(--border);animation:modalIn .2s cubic-bezier(.34,1.56,.64,1);}
    @keyframes modalIn{from{transform:translateY(12px) scale(.97);opacity:0;}to{transform:none;opacity:1;}}
    .modal-close{position:absolute;top:16px;right:16px;width:32px;height:32px;border-radius:50%;background:var(--pp-100);border:none;cursor:pointer;font-size:1rem;color:var(--pp-700);display:flex;align-items:center;justify-content:center;transition:background .13s;}
    .modal-close:hover{background:var(--pp-200);}
    .modal-title{font-family:var(--font-head);font-size:1.15rem;font-weight:800;color:var(--text-primary);margin-bottom:6px;}
    .modal-subtitle{font-size:.83rem;color:var(--text-muted);margin-bottom:24px;padding-bottom:20px;border-bottom:1.5px solid var(--pp-50);}

    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    @media(max-width:500px){.form-grid{grid-template-columns:1fr;}}
    .form-group{display:flex;flex-direction:column;gap:6px;}
    .form-group.full{grid-column:1/-1;}
    .form-label{font-size:.8rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:6px;}
    .badge-opt{background:var(--pp-100);color:var(--pp-600);font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:999px;}
    .form-control{padding:10px 13px;border-radius:var(--radius-md);border:1.5px solid var(--border);background:var(--surface);font-family:var(--font-body);font-size:.9rem;color:var(--text-primary);transition:border-color .15s,box-shadow .15s;width:100%;}
    .form-control:focus{outline:none;border-color:var(--pp-400);box-shadow:0 0 0 3px rgba(167,139,250,.2);}
    textarea.form-control{min-height:80px;resize:vertical;}
    .form-hint{font-size:.75rem;color:var(--text-muted);}
    .section-div{display:flex;align-items:center;gap:10px;margin:20px 0 16px;color:var(--text-muted);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;}
    .section-div::before,.section-div::after{content:'';flex:1;height:1px;background:var(--border);}

    .toggle-row{display:flex;align-items:center;justify-content:space-between;background:var(--surface-alt);border:1.5px solid var(--border);border-radius:var(--radius-md);padding:12px 14px;}
    .toggle-label{font-size:.875rem;font-weight:600;color:var(--text-primary);}
    .toggle-desc{font-size:.75rem;color:var(--text-muted);}
    .toggle-switch{position:relative;width:42px;height:24px;flex-shrink:0;}
    .toggle-switch input{opacity:0;width:0;height:0;}
    .toggle-slider{position:absolute;inset:0;cursor:pointer;background:var(--border-strong);border-radius:999px;transition:.2s;}
    .toggle-slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:var(--shadow-sm);}
    .toggle-switch input:checked+.toggle-slider{background:var(--pp-500);}
    .toggle-switch input:checked+.toggle-slider:before{transform:translateX(18px);}

    .sizes-check-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
    .size-check-item{position:relative;}
    .size-check-item input{display:none;}
    .size-check-item label{display:inline-flex;align-items:center;justify-content:center;width:56px;height:36px;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:var(--surface);font-size:.8rem;font-weight:700;cursor:pointer;color:var(--text-secondary);transition:all .13s;}
    .size-check-item input:checked+label{background:var(--pp-100);border-color:var(--pp-400);color:var(--pp-700);}

    /* ══════════════════════════════════════════
       STOCK MODAL — color selector + size rows
    ══════════════════════════════════════════ */

    /* Color list */
    .stock-color-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
    .stock-color-btn {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 14px; border-radius: var(--radius-md);
      border: 1.5px solid var(--border); background: var(--surface);
      cursor: pointer; text-align: left; width: 100%;
      font-family: var(--font-body); font-size: .9rem; font-weight: 600;
      color: var(--text-primary); transition: all .13s;
    }
    .stock-color-btn:hover { border-color: var(--pp-300); background: var(--pp-50); }
    .stock-color-btn.active {
      border-color: var(--pp-500);
      background: var(--pp-50);
      box-shadow: 0 0 0 3px rgba(139,92,246,.12);
    }
    .stock-color-swatch {
      width: 18px; height: 18px; border-radius: 50%;
      border: 1.5px solid rgba(0,0,0,.12); flex-shrink: 0;
    }
    .stock-color-name { flex: 1; text-transform: capitalize; }
    .stock-color-total {
      font-size: .75rem; color: var(--text-muted);
      background: var(--pp-100); border-radius: 999px;
      padding: 2px 8px; font-weight: 700;
    }

    /* Size editor panel */
    .stock-size-panel {
      border-top: 1.5px solid var(--border);
      padding-top: 20px;
      display: none;
    }
    .stock-size-panel.visible { display: block; }
    .stock-size-heading {
      font-family: var(--font-head); font-size: 1rem; font-weight: 700;
      color: var(--text-primary); margin-bottom: 16px;
      display: flex; align-items: center; gap: 10px;
    }
    .stock-size-heading .head-swatch {
      width: 14px; height: 14px; border-radius: 50%;
      border: 1.5px solid rgba(0,0,0,.12);
    }

    /* Size table header */
    .size-tbl-head {
      display: grid; grid-template-columns: 80px 1fr 100px;
      padding: 0 0 8px 0;
      border-bottom: 1.5px solid var(--border);
      margin-bottom: 4px;
    }
    .size-tbl-head span {
      font-size: .72rem; font-weight: 700;
      color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em;
    }

    /* Size rows */
    .size-tbl-row {
      display: grid; grid-template-columns: 80px 1fr 100px;
      align-items: center; gap: 0;
      padding: 10px 0;
      border-bottom: 1px solid var(--pp-50);
    }
    .size-tbl-row:last-child { border-bottom: none; }
    .size-tbl-name {
      font-size: .9rem; font-weight: 700; color: var(--pp-700);
      font-family: var(--font-head);
    }
    .size-tbl-bar-wrap {
      height: 6px; background: var(--pp-100); border-radius: 999px;
      overflow: hidden; margin-right: 16px;
    }
    .size-tbl-bar {
      height: 100%; border-radius: 999px; background: var(--pp-400);
      transition: width .3s ease;
    }
    .size-tbl-bar.bar-out { background: #fca5a5; }
    .size-tbl-bar.bar-low { background: #fde68a; }
    .size-tbl-bar.bar-ok  { background: #6ee7b7; }
    .size-tbl-qty {
      width: 80px; padding: 7px 10px;
      border-radius: var(--radius-md);
      border: 1.5px solid var(--border);
      font-family: var(--font-body); font-size: .9rem;
      text-align: center; font-weight: 700;
      color: var(--text-primary);
      transition: border-color .15s, background .15s;
    }
    .size-tbl-qty:focus {
      outline: none; border-color: var(--pp-400);
      box-shadow: 0 0 0 3px rgba(167,139,250,.2);
    }
    .size-tbl-qty.qty-zero { border-color: #fca5a5; background: #fff5f5; color: #dc2626; }
    .size-tbl-qty.qty-low  { border-color: #fde68a; background: #fffbeb; color: #92400e; }

    /* Grand total strip */
    .stock-total-strip {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px;
      background: var(--pp-50); border-radius: var(--radius-md);
      border: 1.5px solid var(--pp-200); margin-top: 14px;
      font-size: .82rem; font-weight: 700; color: var(--text-secondary);
    }
    .stock-total-num {
      font-size: 1rem; font-weight: 800; color: var(--pp-700);
      font-family: var(--font-head);
    }

    /* Bulk copy bar */
    .bulk-bar{display:flex;align-items:center;gap:10px;background:var(--pp-50);border:1.5px solid var(--pp-200);border-radius:var(--radius-md);padding:10px 14px;margin-bottom:16px;flex-wrap:wrap;}
    .bulk-bar label{font-size:.78rem;font-weight:700;color:var(--text-secondary);white-space:nowrap;}
    .bulk-bar select{flex:1;min-width:120px;padding:6px 10px;border-radius:var(--radius-sm);border:1.5px solid var(--border);font-family:var(--font-body);font-size:.82rem;background:var(--surface);color:var(--text-primary);}

    /* No-size color rows */
    .no-size-color-row {
      display: grid; grid-template-columns: 1fr 100px;
      align-items: center; gap: 0;
      padding: 10px 0; border-bottom: 1px solid var(--pp-50);
    }
    .no-size-color-row:last-child { border-bottom: none; }
    .no-size-color-label {
      display: flex; align-items: center; gap: 10px;
      font-size: .9rem; font-weight: 600; color: var(--text-primary);
      text-transform: capitalize;
    }
    .no-size-qty-input {
      width: 80px; padding: 7px 10px;
      border-radius: var(--radius-md);
      border: 1.5px solid var(--border);
      font-family: var(--font-body); font-size: .9rem;
      text-align: center; font-weight: 700; color: var(--text-primary);
      transition: border-color .15s;
    }
    .no-size-qty-input:focus { outline: none; border-color: var(--pp-400); }
    .no-size-qty-input.qty-zero { border-color: #fca5a5; background: #fff5f5; color: #dc2626; }
    .no-size-qty-input.qty-low  { border-color: #fde68a; background: #fffbeb; color: #92400e; }

    /* ── Add Item: color-size qty grid ── */
    .add-color-tabs {
      display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px;
    }
    .add-color-tab {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 14px; border-radius: 999px;
      border: 1.5px solid var(--border); background: var(--surface);
      font-size: .78rem; font-weight: 700; cursor: pointer;
      color: var(--text-secondary); transition: all .13s;
    }
    .add-color-tab.active {
      border-color: var(--pp-500); background: var(--pp-100); color: var(--pp-700);
    }
    .add-color-tab .tab-dot {
      width: 10px; height: 10px; border-radius: 50%;
      border: 1px solid rgba(0,0,0,.12);
    }
    .add-color-panel { display: none; }
    .add-color-panel.active { display: block; }

    .add-size-rows { display: flex; flex-direction: column; gap: 8px; }
    .add-size-row {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 14px; border-radius: var(--radius-md);
      border: 1.5px solid var(--border); background: var(--surface-alt);
    }
    .add-size-row-name {
      min-width: 56px; font-size: .85rem; font-weight: 800;
      color: var(--pp-700); font-family: var(--font-head);
    }
    .add-size-row-input {
      flex: 1; max-width: 100px; padding: 6px 10px;
      border-radius: var(--radius-sm); border: 1.5px solid var(--border);
      font-family: var(--font-body); font-size: .875rem;
      text-align: center; color: var(--text-primary); font-weight: 700;
    }
    .add-size-row-input:focus { outline: none; border-color: var(--pp-400); box-shadow: 0 0 0 3px rgba(167,139,250,.2); }
    .add-flat-qty-wrap { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
    .add-flat-color-row {
      display: flex; align-items: center; gap: 10px; padding: 8px 12px;
      border-radius: var(--radius-md); border: 1.5px solid var(--border);
      background: var(--surface-alt);
    }
    .add-flat-dot { width: 12px; height: 12px; border-radius: 50%; border: 1px solid rgba(0,0,0,.12); flex-shrink: 0; }
    .add-flat-name { flex: 1; font-size: .83rem; font-weight: 700; color: var(--text-primary); }
    .add-flat-input {
      width: 80px; padding: 5px 8px; border-radius: var(--radius-sm);
      border: 1.5px solid var(--border); font-family: var(--font-body);
      font-size: .875rem; text-align: center; color: var(--text-primary); font-weight: 700;
    }
    .add-flat-input:focus { outline: none; border-color: var(--pp-400); }

    /* Image manager */
    .img-profile-row{display:flex;align-items:center;gap:16px;padding:16px 20px;border:2px solid var(--pp-300);border-radius:var(--radius-md);background:var(--pp-50);margin-bottom:22px;}
    .img-profile-row.has-image{border-color:#6ee7b7;background:#f0fdf4;}
    .img-circle-box{width:90px;height:90px;border-radius:50%;border:2px solid var(--border);background:var(--pp-100);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;position:relative;}
    .img-circle-box img{width:100%;height:100%;object-fit:cover;display:none;border-radius:50%;}
    .img-sides-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    @media(max-width:480px){.img-sides-grid{grid-template-columns:1fr;}}
    .img-side-card{border:2px dashed var(--border);border-radius:var(--radius-md);padding:14px;background:var(--pp-50);display:flex;flex-direction:column;align-items:center;gap:10px;transition:border-color .15s,background .15s;}
    .img-side-card.has-image{border-style:solid;border-color:#6ee7b7;background:#f0fdf4;}
    .img-side-card.uploading{border-color:var(--pp-400);background:var(--pp-100);animation:pulse .8s ease-in-out infinite;}
    @keyframes pulse{0%,100%{opacity:1;}50%{opacity:.6;}}
    .img-side-label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--pp-700);}
    .img-preview-box{width:100%;height:120px;border-radius:var(--radius-sm);background:var(--pp-100);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;border:1.5px solid var(--border);}
    .img-preview-box img{width:100%;height:100%;object-fit:contain;display:none;}
    .img-placeholder{font-size:.75rem;font-weight:600;color:var(--pp-400);letter-spacing:.04em;}
    .img-spinner{display:none;position:absolute;inset:0;background:rgba(255,255,255,.8);align-items:center;justify-content:center;font-size:.8rem;font-weight:600;color:var(--pp-600);}
    .img-spinner.show{display:flex;}
    .img-btn-row{display:flex;gap:6px;width:100%;}
    .img-upload-btn{flex:1;padding:6px 0;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:var(--surface);font-size:.75rem;font-weight:700;cursor:pointer;color:var(--pp-700);transition:all .13s;}
    .img-upload-btn:hover{border-color:var(--pp-400);background:var(--pp-50);}
    .img-remove-btn{padding:6px 10px;border-radius:var(--radius-sm);border:1.5px solid #fca5a5;background:#fff5f5;font-size:.75rem;font-weight:700;cursor:pointer;color:#dc2626;display:none;transition:background .13s;}
    .img-remove-btn:hover{background:#fee2e2;}
    .img-status{font-size:.72rem;font-weight:600;text-align:center;min-height:16px;color:var(--text-muted);}
    .img-status.ok{color:#059669;}
    .img-status.err{color:#dc2626;}
    .img-section-head{font-family:var(--font-head);font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--pp-600);margin-bottom:10px;display:flex;align-items:center;gap:8px;}
    .img-section-head span.rule{flex:1;height:1px;background:var(--border);}
    .img-section-head span.note{font-size:.7rem;font-weight:600;color:var(--text-muted);text-transform:none;letter-spacing:0;}
    input.side-file-input{display:none;}

    .sync-note{font-size:.75rem;color:var(--text-muted);margin-top:8px;}

    /* ───────────── PRODUCT GRID UI ───────────── */
    .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
    .stat-card{position:relative;background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;box-shadow:var(--shadow-sm);transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .25s;animation:fadeUp .5s both;overflow:hidden;}
    .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
    .stat-card::after{content:"";position:absolute;inset:0;background:linear-gradient(135deg,transparent 60%,rgba(124,58,237,.06));pointer-events:none;}
    .stat-label{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);}
    .stat-value{font-family:var(--font-head);font-size:2rem;font-weight:800;color:var(--text-primary);margin-top:4px;line-height:1;}
    .stat-sub{font-size:.75rem;font-weight:600;margin-top:6px;}
    .stat-sub.up{color:#16a34a;} .stat-sub.warn{color:#b45309;} .stat-sub.danger{color:#b91c1c;}
    .stat-card.t-active{border-top:3px solid #22c55e;}
    .stat-card.t-low{border-top:3px solid #f59e0b;}
    .stat-card.t-out{border-top:3px solid #ef4444;}
    .stat-card.t-total{border-top:3px solid var(--pp-500);}

    .filter-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
    .chip{background:var(--surface);border:1.5px solid var(--border);color:var(--text-secondary);padding:7px 14px;border-radius:999px;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
    .chip:hover{border-color:var(--pp-300);color:var(--pp-700);transform:translateY(-1px);}
    .chip.active{background:var(--pp-600);color:#fff;border-color:var(--pp-600);box-shadow:0 4px 14px rgba(124,58,237,.35);}

    .product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px;}
    .p-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-xl);overflow:hidden;display:flex;flex-direction:column;box-shadow:var(--shadow-sm);transition:transform .35s cubic-bezier(.34,1.56,.64,1),box-shadow .35s,border-color .25s;animation:fadeUp .5s both;position:relative;}
    .p-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:var(--pp-300);}
    .p-card:hover .p-thumb img{transform:scale(1.08);}

    .p-thumb{position:relative;aspect-ratio:1/1;overflow:hidden;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--pp-100),var(--pp-50));}
    .p-thumb img{width:100%;height:100%;object-fit:cover;transition:transform .6s cubic-bezier(.34,1.56,.64,1);}
    .p-thumb .no-img{font-family:var(--font-head);font-weight:700;color:var(--pp-400);font-size:.85rem;letter-spacing:.05em;}
    .p-thumb .p-status{position:absolute;top:10px;left:10px;}
    .p-thumb .p-stock-pill{position:absolute;top:10px;right:10px;background:rgba(255,255,255,.92);backdrop-filter:blur(6px);padding:4px 10px;border-radius:999px;font-size:.7rem;font-weight:700;color:var(--text-primary);box-shadow:0 2px 8px rgba(0,0,0,.08);}
    .p-thumb::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 60%,rgba(0,0,0,.1));pointer-events:none;}

    .p-body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:6px;}
    .p-cat{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--pp-600);}
    .p-name{font-family:var(--font-head);font-weight:700;font-size:.98rem;color:var(--text-primary);line-height:1.3;overflow:hidden;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;}
    .p-price-row{display:flex;justify-content:space-between;align-items:center;margin-top:6px;}
    .p-price{font-family:var(--font-head);font-size:1.05rem;font-weight:800;color:var(--pp-700);}
    .p-stock-text{font-size:.72rem;font-weight:600;color:var(--text-muted);}
    .p-colors{display:flex;gap:4px;margin-top:8px;flex-wrap:wrap;}
    .p-colors .color-dot{width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px var(--border);}
    .p-sizes{display:flex;flex-wrap:wrap;gap:3px;margin-top:6px;}
    .p-sizes .sz{font-size:.62rem;font-weight:700;background:var(--pp-50);color:var(--pp-700);border:1px solid var(--pp-200);border-radius:4px;padding:1px 5px;}

    /* Always-visible labeled action bar inside the card body */
    .p-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);}
    .p-actions .btn-act{width:100%;padding:7px 8px;font-size:.72rem;display:inline-flex;align-items:center;justify-content:center;gap:5px;}
    .p-actions .btn-act.full{grid-column:1 / -1;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:none;}}
    .product-grid .p-card:nth-child(1){animation-delay:.02s}
    .product-grid .p-card:nth-child(2){animation-delay:.06s}
    .product-grid .p-card:nth-child(3){animation-delay:.1s}
    .product-grid .p-card:nth-child(4){animation-delay:.14s}
    .product-grid .p-card:nth-child(5){animation-delay:.18s}
    .product-grid .p-card:nth-child(6){animation-delay:.22s}
    .product-grid .p-card:nth-child(7){animation-delay:.26s}
    .product-grid .p-card:nth-child(8){animation-delay:.3s}

    .empty-grid{grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text-muted);background:var(--surface);border:1.5px dashed var(--border-strong);border-radius:var(--radius-xl);}

    @media(max-width:600px){
      .stat-value{font-size:1.6rem}
      .product-grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;}
      .p-actions{grid-template-columns:repeat(2,1fr);}
    }


  </style>
</head>
<body>
<div class="layout">
  <?php @include '../includes/sidebar.php'; ?>
  <div class="main">
  <div class="inv-page">

    <div class="inv-header">
      <div>
        <h1>Inventory</h1>
        <p>Manage products, stock levels, colors, sizes, and images.</p>
      </div>
      <button class="btn btn-primary"
              onclick="document.getElementById('addModal').classList.add('open')">
        + Add New Item
      </button>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!hasStockQtyColumn($conn)): ?>
    <div class="db-notice">
      <strong>Database Notice</strong>
      The <code>inventory_color_stock</code> table is missing the <code>stock_qty</code> column.
      Run <code>smoims_migration.sql</code> to fix this.
    </div>
    <?php endif; ?>

    <?php if ($lowItems && $lowItems->num_rows > 0): ?>
    <div class="low-stock-card">
      <div class="low-title">Low Stock — <?= $lowItems->num_rows ?> item(s) need attention</div>
      <div class="low-stock-tags">
        <?php while ($l = $lowItems->fetch_assoc()): ?>
          <span class="low-tag"><?= htmlspecialchars($l['item_name']) ?> — <?= $l['quantity'] ?> left</span>
        <?php endwhile; ?>
      </div>
    </div>
    <?php endif; ?>

    <form method="GET" action="inventory.php" class="search-row">
      <input type="text" name="search"
             placeholder="Search by name or category..."
             value="<?= htmlspecialchars($search) ?>">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($search): ?>
        <a href="inventory.php" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>

    <!-- ─────── STAT CARDS ─────── -->
    <div class="stat-grid">
      <div class="stat-card t-total">
        <div class="stat-label">Total Products</div>
        <div class="stat-value"><?= number_format($stat_total) ?></div>
        <div class="stat-sub up">All items in catalog</div>
      </div>
      <div class="stat-card t-active">
        <div class="stat-label">Active</div>
        <div class="stat-value"><?= number_format($stat_active) ?></div>
        <div class="stat-sub up"><?= $stat_total ? round($stat_active/$stat_total*100) : 0 ?>% of total</div>
      </div>
      <div class="stat-card t-low">
        <div class="stat-label">Low Stock</div>
        <div class="stat-value"><?= number_format($stat_low) ?></div>
        <div class="stat-sub warn">Need restock</div>
      </div>
      <div class="stat-card t-out">
        <div class="stat-label">Out of Stock</div>
        <div class="stat-value"><?= number_format($stat_out) ?></div>
        <div class="stat-sub danger">Action required</div>
      </div>
    </div>

    <!-- ─────── CATEGORY FILTER CHIPS ─────── -->
    <div class="filter-chips" id="catChips">
      <button type="button" class="chip active" data-cat="__all">All</button>
      <?php foreach ($catList as $cat): ?>
        <button type="button" class="chip" data-cat="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></button>
      <?php endforeach; ?>
    </div>

    <!-- ─────── PRODUCT GRID ─────── -->
    <div class="product-grid" id="productGrid">
      <?php if (!empty($itemsArr)): ?>
        <?php foreach ($itemsArr as $row):
          $colorStock = getColorStock($conn, $row['id']);
          $allSizes   = getAllSizeStockForItem($conn, $row['id']);
          $imgsJs = json_encode([
            'front'   => !empty($row['image_front'])  ? 'data:image/jpeg;base64,' . base64_encode($row['image_front'])  : null,
            'back'    => !empty($row['image_back'])   ? 'data:image/jpeg;base64,' . base64_encode($row['image_back'])   : null,
            'left'    => !empty($row['image_left'])   ? 'data:image/jpeg;base64,' . base64_encode($row['image_left'])   : null,
            'right'   => !empty($row['image_right'])  ? 'data:image/jpeg;base64,' . base64_encode($row['image_right'])  : null,
            'profile' => !empty($row['profile_image'])? 'data:image/jpeg;base64,' . base64_encode($row['profile_image']): null,
          ]);
          $sizesByColor = [];
          foreach ($allSizes as $sv) { $sizesByColor[$sv['color_id']][] = $sv; }
          $thumbRaw = $row['profile_image'] ?: $row['image_front'] ?: null;
          $thumb = $thumbRaw ? 'data:image/jpeg;base64,' . base64_encode($thumbRaw) : null;
          $sizeTotals = [];
          foreach ($allSizes as $sv) { $sizeTotals[$sv['size_name']] = ($sizeTotals[$sv['size_name']] ?? 0) + $sv['stock_qty']; }
        ?>
        <div class="p-card" data-cat="<?= htmlspecialchars($row['category'] ?? '') ?>">
          <div class="p-thumb">
            <?php if ($thumb): ?>
              <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($row['item_name']) ?>">
            <?php else: ?>
              <span class="no-img">No Image</span>
            <?php endif; ?>

            <div class="p-status">
              <?php if ($row['quantity'] == 0): ?>
                <span class="badge badge-out">Out of Stock</span>
              <?php elseif ($row['quantity'] < 10): ?>
                <span class="badge badge-low">Low</span>
              <?php else: ?>
                <span class="badge badge-ok">In Stock</span>
              <?php endif; ?>
            </div>
            <div class="p-stock-pill">Stock: <?= (int)$row['quantity'] ?></div>

          </div>

          <div class="p-body">
            <span class="p-cat"><?= htmlspecialchars($row['category'] ?? '—') ?></span>
            <div class="p-name"><?= htmlspecialchars($row['item_name']) ?></div>
            <div class="p-price-row">
              <span class="p-price">&#8369;<?= number_format($row['price'], 2) ?></span>
              <span class="p-stock-text">Stock: <?= (int)$row['quantity'] ?></span>
            </div>

            <?php if (!empty($colorStock)): ?>
              <div class="p-colors">
                <?php foreach ($colorStock as $cs): ?>
                  <span class="color-dot" title="<?= htmlspecialchars($cs['color_name']) ?>: <?= $cs['stock_qty'] ?>"
                        style="background:<?= getComputedColor($cs['color_name']) ?>"></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($sizeTotals)): ?>
              <div class="p-sizes">
                <?php foreach ($sizeTotals as $szName => $szQty): ?>
                  <span class="sz"><?= htmlspecialchars($szName) ?>·<?= $szQty ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Labeled action bar (always visible, user-friendly) -->
            <div class="p-actions">
              <button type="button" class="btn-act btn-act-purple" title="Manage product images"
                      onclick='openImgManager(<?= $row["id"] ?>, <?= json_encode($row["item_name"]) ?>, <?= $imgsJs ?>)'>📷 Images</button>
              <button type="button" class="btn-act btn-act-purple" title="Set design area / print boundary"
                      style="background:var(--pp-50);color:var(--pp-800);border-color:var(--pp-300)"
                      onclick='openBoundaryModal(<?= $row["id"] ?>, <?= json_encode($row["item_name"]) ?>, <?= $imgsJs ?>)'>📐 Design</button>
              <button type="button" class="btn-act btn-act-slate" title="Edit product details"
                      onclick='openDetailsModal(
                        <?= $row["id"] ?>,
                        <?= htmlspecialchars(json_encode($row["item_name"]), ENT_QUOTES) ?>,
                        <?= htmlspecialchars(json_encode($row["category"] ?? ""), ENT_QUOTES) ?>,
                        <?= htmlspecialchars(json_encode($row["description"] ?? ""), ENT_QUOTES) ?>,
                        <?= htmlspecialchars(json_encode(implode(', ', array_column($colorStock, 'color_name'))), ENT_QUOTES) ?>,
                        <?= (int)$row["has_sizes"] ?>
                      )'>✏️ Edit</button>
              <?php if (!empty($colorStock)): ?>
              <button type="button" class="btn-act btn-act-amber" title="Update stock quantities"
                      onclick='openStockModal(
                        <?= $row["id"] ?>,
                        <?= json_encode($row["item_name"]) ?>,
                        <?= json_encode($colorStock) ?>,
                        <?= json_encode($sizesByColor) ?>,
                        <?= (int)$row["has_sizes"] ?>
                      )'>📦 Stock</button>
              <?php endif; ?>
              <a href="inventory.php?delete=<?= $row['id'] ?>"
                 class="btn-act btn-act-red full" title="Delete this product permanently"
                 onclick="return confirm('Delete \'<?= htmlspecialchars(addslashes($row['item_name'])) ?>\'? This cannot be undone.')">🗑 Delete</a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-grid">
          <p><?= $search ? 'No items match your search.' : 'No inventory items yet. Add your first item!' ?></p>
        </div>
      <?php endif; ?>
    </div>

    <script>
      // Category filter
      (function(){
        const chips = document.querySelectorAll('#catChips .chip');
        const cards = document.querySelectorAll('#productGrid .p-card');
        chips.forEach(c => c.addEventListener('click', () => {
          chips.forEach(x => x.classList.remove('active'));
          c.classList.add('active');
          const cat = c.dataset.cat;
          cards.forEach(card => {
            const show = (cat === '__all') || card.dataset.cat === cat;
            card.style.display = show ? '' : 'none';
          });
        }));
      })();
    </script>

  </div>
  </div>
</div>


<!-- ════════════════════════════════════════════════════
     MODAL: ADD NEW ITEM
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <button class="modal-close"
            onclick="document.getElementById('addModal').classList.remove('open')">&#215;</button>
    <div class="modal-title">Add New Item</div>
    <div class="modal-subtitle">Images can be added after saving.</div>

    <form method="POST" action="inventory.php">
      <div class="form-grid">

        <div class="form-group full">
          <label class="form-label">Item Name *</label>
          <input type="text" name="item_name" class="form-control"
                 placeholder="e.g. Classic T-Shirt" required>
        </div>

        <div class="form-group">
          <label class="form-label">Category *</label>
          <input type="text" name="category" class="form-control"
                 placeholder="e.g. Shirt, Hoodie" required>
        </div>

        <div class="form-group">
          <label class="form-label">Price (&#8369;) *</label>
          <input type="number" name="price" class="form-control"
                 placeholder="0.00" step="0.01" min="0" required>
        </div>

        <div class="form-group full">
          <label class="form-label">Description <span class="badge-opt">Optional</span></label>
          <textarea name="description" class="form-control"
                    placeholder="Describe the product..."></textarea>
        </div>

        <div class="form-group full">
          <label class="form-label">Available Colors <span class="badge-opt">Optional</span></label>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:8px;background:var(--surface-alt);padding:14px;border-radius:var(--radius-md);border:1.5px solid var(--border);">
            <?php
            $available_colors = [
              'white'=>'#f8fafc','black'=>'#1e293b','red'=>'#ef4444','blue'=>'#3b82f6',
              'green'=>'#22c55e','yellow'=>'#eab308','purple'=>'#a855f7','pink'=>'#ec4899',
              'orange'=>'#f97316','gray'=>'#9ca3af','brown'=>'#92400e','beige'=>'#d4b896',
              'coral'=>'#f87171','navy'=>'#1e3a5f','teal'=>'#14b8a6','cream'=>'#fef9c3',
              'lavender'=>'#ddd6fe','maroon'=>'#881337','sky'=>'#7dd3fc',
            ];
            foreach ($available_colors as $name => $hex): ?>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:5px;border-radius:var(--radius-sm);transition:background .15s;">
                <input type="checkbox" name="selected_colors[]" value="<?= $name ?>"
                       style="width:15px;height:15px;cursor:pointer;accent-color:var(--pp-600)"
                       onchange="refreshAddQtyPanels()">
                <span style="width:13px;height:13px;border-radius:50%;background:<?= $hex ?>;border:1px solid rgba(0,0,0,.1);flex-shrink:0"></span>
                <span style="font-size:.83rem;text-transform:capitalize;color:var(--text-secondary)"><?= $name ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- ── Sizing section ── -->
      <div class="section-div">Sizing</div>
      <div class="toggle-row" style="margin-bottom:14px">
        <div>
          <div class="toggle-label">This item has size variants</div>
          <div class="toggle-desc">Track XS / S / M / L / XL stock per size, per color</div>
        </div>
        <label class="toggle-switch">
          <input type="checkbox" name="has_sizes" id="addHasSizes"
                 onchange="toggleAddSizesPanel(this.checked)">
          <span class="toggle-slider"></span>
        </label>
      </div>

      <!-- Size checkboxes (only shown when has_sizes) -->
      <div id="addSizesPanel" style="display:none;margin-bottom:16px">
        <label class="form-label" style="margin-bottom:8px">Select Available Sizes</label>
        <div class="sizes-check-grid">
          <?php foreach ($SIZE_OPTIONS as $sz): ?>
            <div class="size-check-item">
              <input type="checkbox" name="sizes[]" value="<?= $sz ?>"
                     id="sz_<?= $sz ?>" onchange="refreshAddQtyPanels()">
              <label for="sz_<?= $sz ?>"><?= $sz ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Dynamic qty entry: per-color per-size (or flat per-color) -->
      <div id="addQtySection" style="display:none;margin-bottom:16px">
        <div class="section-div">Stock Quantities</div>
        <div id="addQtyContent"></div>
      </div>

      <!-- Hidden fallback quantity field -->
      <input type="hidden" name="quantity" value="0">

      <button type="submit" name="add_item" class="btn btn-primary w-full mt-4">
        Add Item
      </button>
    </form>
  </div>
</div>


<!-- ════════════════════════════════════════════════════
     MODAL: IMAGE MANAGER
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="imgModal">
  <div class="modal-box" style="max-width:700px">
    <button class="modal-close" onclick="closeImgModal()">&#215;</button>
    <div class="modal-title" id="imgModalTitle">Product Images</div>
    <div class="modal-subtitle">Upload images for each angle. Changes save instantly.</div>

    <input type="hidden" id="imgModalItemId" value="">

    <div class="img-section-head">
      <span>Profile / Thumbnail</span>
      <span class="rule"></span>
      <span class="note">Used as the main product card thumbnail</span>
    </div>

    <div class="img-profile-row" id="imgCard_profile">
      <div class="img-circle-box" id="imgPreviewBox_profile">
        <img id="imgPreviewImg_profile" src="" alt="Profile">
        <span id="imgPlaceholder_profile" style="font-size:.75rem;font-weight:600;color:var(--pp-400)">No image</span>
        <div class="img-spinner" id="imgSpinner_profile">Uploading...</div>
      </div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:.9rem;margin-bottom:4px">Main Profile Photo</div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:10px">Shown in the inventory table and on customer-facing pages.</div>
        <div style="display:flex;gap:8px;align-items:center">
          <button type="button" class="btn btn-primary btn-sm"
                  onclick="document.getElementById('imgFile_profile').click()">Upload Profile</button>
          <button type="button" class="img-remove-btn" id="imgRemoveBtn_profile"
                  onclick="removeImage('profile')">Remove</button>
        </div>
        <div class="img-status" id="imgStatus_profile" style="margin-top:6px;text-align:left"></div>
      </div>
      <input type="file" id="imgFile_profile" class="side-file-input"
             accept="image/*" onchange="uploadImage('profile', this)">
    </div>

    <div class="img-section-head" style="margin-top:20px">
      <span>Product Side Views</span>
      <span class="rule"></span>
      <span class="note">Upload each angle</span>
    </div>

    <div class="img-sides-grid">
      <?php
      $sideLabels = [
        'front' => ['label' => 'Front View',  'desc' => 'Front-facing shot'],
        'back'  => ['label' => 'Back View',   'desc' => 'Rear-facing shot'],
        'left'  => ['label' => 'Left Side',   'desc' => 'Left angle'],
        'right' => ['label' => 'Right Side',  'desc' => 'Right angle'],
      ];
      foreach ($sideLabels as $sideKey => $sideInfo): ?>
        <div class="img-side-card" id="imgCard_<?= $sideKey ?>">
          <div style="display:flex;align-items:center;gap:6px;width:100%">
            <div>
              <div class="img-side-label"><?= $sideInfo['label'] ?></div>
              <div style="font-size:.68rem;color:var(--text-muted)"><?= $sideInfo['desc'] ?></div>
            </div>
          </div>
          <div class="img-preview-box">
            <img id="imgPreviewImg_<?= $sideKey ?>" src="" alt="<?= $sideInfo['label'] ?>">
            <span id="imgPlaceholder_<?= $sideKey ?>" class="img-placeholder">No image</span>
            <div class="img-spinner" id="imgSpinner_<?= $sideKey ?>">Uploading...</div>
          </div>
          <div class="img-btn-row">
            <button type="button" class="img-upload-btn"
                    onclick="document.getElementById('imgFile_<?= $sideKey ?>').click()">Upload</button>
            <button type="button" class="img-remove-btn" id="imgRemoveBtn_<?= $sideKey ?>"
                    onclick="removeImage('<?= $sideKey ?>')">Remove</button>
          </div>
          <div class="img-status" id="imgStatus_<?= $sideKey ?>"></div>
          <input type="file" id="imgFile_<?= $sideKey ?>" class="side-file-input"
                 accept="image/*" onchange="uploadImage('<?= $sideKey ?>', this)">
        </div>
      <?php endforeach; ?>
    </div>

    <button type="button" class="btn btn-primary w-full"
            onclick="closeImgModal()" style="margin-top:24px">Done</button>
  </div>
</div>


<!-- ════════════════════════════════════════════════════
     MODAL: EDIT DETAILS
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="detailsModal">
  <div class="modal-box" style="max-width:520px">
    <button class="modal-close" onclick="document.getElementById('detailsModal').classList.remove('open')">&#215;</button>
    <div class="modal-title">Edit Item Details</div>
    <div class="modal-subtitle">Update description, colors, and size settings.</div>
    <form method="POST" action="inventory.php">
      <input type="hidden" name="item_id" id="detailsItemId">
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Item Name</label>
        <input type="text" name="item_name" id="detailsItemName" class="form-control"
               placeholder="Enter item name">
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Category</label>
        <input type="text" name="category" id="detailsCategory" class="form-control"
               placeholder="Enter category">
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Description</label>
        <textarea name="description" id="detailsDescription" class="form-control"
                  placeholder="Enter item description..."></textarea>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Available Colors</label>
        <input type="text" name="colors" id="detailsColors" class="form-control"
               placeholder="White, Black, Red">
        <div class="form-hint">New colors start at 0 stock — adjust via the Stock button.</div>
      </div>
      <div class="toggle-row" style="margin-bottom:20px">
        <div>
          <div class="toggle-label">Has size variants</div>
          <div class="toggle-desc">Track stock per size, per color</div>
        </div>
        <label class="toggle-switch">
          <input type="checkbox" name="has_sizes" id="detailsHasSizes">
          <span class="toggle-slider"></span>
        </label>
      </div>
      <button type="submit" name="update_details" class="btn btn-primary w-full">Save Changes</button>
    </form>
  </div>
</div>


<!-- ════════════════════════════════════════════════════
     MODAL: STOCK
     • Color list on left → click to select
     • If has_sizes: show size table for selected color
     • If no sizes: show all colors with qty inputs at once
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="stockModal">
  <div class="modal-box" style="max-width:500px">
    <button class="modal-close" onclick="document.getElementById('stockModal').classList.remove('open')">&#215;</button>
    <div class="modal-title" id="stockModalTitle">Stock Management</div>
    <div class="modal-subtitle" id="stockModalSubtitle">Select a color to edit its stock.</div>

    <!-- ─── SIZED ITEMS: color list + size editor ─── -->
    <div id="stockSizedSection" style="display:none">

      <!-- Color selector list -->
      <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
        Select Color
      </div>
      <div class="stock-color-list" id="stockColorList"></div>

      <!-- Size editor panel (shown after selecting a color) -->
      <form method="POST" action="inventory.php" id="stockSizeForm">
        <input type="hidden" name="color_id"         id="stockSizeColorId">
        <input type="hidden" name="item_id"           id="stockSizeItemId">
        <input type="hidden" name="update_size_stock" value="1">

        <div class="stock-size-panel" id="stockSizePanel">
          <div class="stock-size-heading">
            <span class="head-swatch" id="stockSizeHeadSwatch"></span>
            <span id="stockSizeHeadText"></span>
          </div>

          <!-- Table header row -->
          <div class="size-tbl-head">
            <span>Size</span>
            <span>Stock bar</span>
            <span style="text-align:right">Qty</span>
          </div>

          <!-- Rows injected here -->
          <div id="stockSizeRows"></div>

          <!-- Total strip -->
          <div class="stock-total-strip" id="stockTotalStrip" style="display:none">
            <span>Total for this color</span>
            <span class="stock-total-num" id="stockTotalNum">0</span>
          </div>

          <!-- Bulk copy -->
          <div class="bulk-bar" id="stockBulkBar" style="display:none;margin-top:14px">
            <label>Copy sizes from:</label>
            <select id="stockBulkSrc"></select>
            <button type="button" class="btn btn-secondary btn-sm"
                    onclick="submitStockBulkCopy()">Apply to all colors</button>
          </div>

          <button type="submit" class="btn btn-primary w-full" style="margin-top:16px">
            Save Stock
          </button>
          <p class="sync-note">Color total and item total update automatically.</p>
        </div>
      </form>
    </div>

    <!-- ─── NON-SIZED ITEMS: all colors at once ─── -->
    <form method="POST" action="inventory.php" id="stockColorForm" style="display:none">
      <input type="hidden" name="item_id"            id="stockColorItemId">
      <input type="hidden" name="update_color_stock" value="1">

      <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
        Edit Stock per Color
      </div>

      <!-- Table header -->
      <div style="display:grid;grid-template-columns:1fr 100px;padding:0 0 8px;border-bottom:1.5px solid var(--border);margin-bottom:4px">
        <span style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Color</span>
        <span style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;text-align:right">Qty</span>
      </div>

      <div id="stockColorRows"></div>

      <button type="submit" class="btn btn-primary w-full" style="margin-top:16px">
        Save Stock
      </button>
      <p class="sync-note">Item total updates automatically after saving.</p>
    </form>

  </div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL: DESIGN BOUNDARY (Admin)
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="boundaryModal" style="z-index:1100">
  <div class="modal-box" style="max-width:700px;padding:28px 28px 24px">
    <button class="modal-close" onclick="closeBoundaryModal()">&#215;</button>
    <div class="modal-title" id="boundaryModalTitle">Design Area</div>
    <div class="modal-subtitle" style="margin-bottom:14px">
      Click and drag on the product image to draw the printable design boundary.
      Customers will only be able to place their artwork inside this box.
    </div>

    <input type="hidden" id="boundaryItemId" value="">

    <!-- View tabs -->
    <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
      <?php foreach(['front'=>'⬆ Front','back'=>'⬇ Back','left'=>'◀ Left','right'=>'▶ Right'] as $sk=>$sl): ?>
        <button type="button" id="btab_<?= $sk ?>"
                onclick="switchBoundaryTab('<?= $sk ?>')"
                style="padding:7px 16px;border-radius:999px;border:1.5px solid var(--border);background:var(--surface);
                       font-size:.78rem;font-weight:700;cursor:pointer;color:var(--text-secondary);
                       font-family:var(--font-body);transition:all .15s"
                class="b-tab">
          <?= $sl ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Canvas panels per side -->
    <?php foreach(['front','back','left','right'] as $sk): ?>
    <div id="bview_<?= $sk ?>" style="display:none">
      <div style="position:relative;width:100%;border-radius:12px;overflow:hidden;
                  border:2px dashed var(--border-strong);background:var(--pp-50);cursor:crosshair;
                  user-select:none;-webkit-user-select:none">
        <canvas id="bcanvas_<?= $sk ?>"
          style="display:block;width:100%;height:auto;touch-action:none"
          onmousedown="startDraw('<?= $sk ?>',event)"
          onmousemove="moveDraw('<?= $sk ?>',event)"
          onmouseup="endDraw('<?= $sk ?>',event)"
          onmouseleave="endDraw('<?= $sk ?>',event)"
          ontouchstart="startDrawTouch('<?= $sk ?>',event)"
          ontouchmove="moveDrawTouch('<?= $sk ?>',event)"
          ontouchend="endDrawTouch('<?= $sk ?>',event)">
        </canvas>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:8px">
        <div>
          <div id="bstat_<?= $sk ?>"
               style="font-size:.8rem;font-weight:600;color:var(--text-secondary)">
            No boundary set — drag to draw one.
          </div>
          <div id="bcoord_<?= $sk ?>"
               style="font-size:.72rem;color:var(--text-muted);margin-top:2px;font-family:monospace"></div>
        </div>
        <button type="button"
                onclick="clearBoundary('<?= $sk ?>')"
                style="padding:5px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--err-border);
                       background:var(--err-bg);color:var(--err-text);font-size:.75rem;font-weight:700;
                       cursor:pointer;font-family:var(--font-body)">
          ✕ Clear
        </button>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px;gap:12px;flex-wrap:wrap">
      <div id="boundarySaveStatus" style="font-size:.83rem;font-weight:600"></div>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeBoundaryModal()">Cancel</button>
        <button type="button" class="btn btn-primary" id="saveBoundaryBtn" onclick="saveBoundaries()">
          💾 Save All Boundaries
        </button>
      </div>
    </div>
  </div>
</div>

<style>
.b-tab.active {
  background: var(--pp-100) !important;
  border-color: var(--pp-500) !important;
  color: var(--pp-700) !important;
}
</style>

<!-- Hidden form for bulk copy -->
<form method="POST" action="inventory.php" id="bulkCopyForm">
  <input type="hidden" name="item_id"         id="bulkCopyItemId">
  <input type="hidden" name="src_color_id"    id="bulkCopySrcColorId">
  <input type="hidden" name="bulk_copy_sizes" value="1">
</form>


<script>
/* ═══════════════════════════════════════════════════════
   MODAL BACKDROP CLOSE
═══════════════════════════════════════════════════════ */
['addModal','imgModal','detailsModal','stockModal','boundaryModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

/* ═══════════════════════════════════════════════════════
   COLOR UTILITY
═══════════════════════════════════════════════════════ */
const COLOR_HEX = {
    white:'#f8fafc',black:'#1e293b',red:'#ef4444',blue:'#3b82f6',green:'#22c55e',
    yellow:'#eab308',purple:'#a855f7',pink:'#ec4899',orange:'#f97316',gray:'#9ca3af',
    grey:'#9ca3af',brown:'#92400e',beige:'#d4b896',coral:'#f87171',navy:'#1e3a5f',
    teal:'#14b8a6',cream:'#fef9c3',lavender:'#ddd6fe',maroon:'#881337',sky:'#7dd3fc'
};
function colorHex(name) {
    const l = (name||'').toLowerCase().trim();
    for (const [k,v] of Object.entries(COLOR_HEX)) { if (l.includes(k)) return v; }
    return '#a78bfa';
}

/* ═══════════════════════════════════════════════════════
   STOCK QTY INPUT VISUAL
═══════════════════════════════════════════════════════ */
function applyQtyClass(input) {
    const v = parseInt(input.value, 10) || 0;
    input.classList.toggle('qty-zero', v === 0);
    input.classList.toggle('qty-low',  v > 0 && v < 5);
}
function barClass(qty) {
    if (qty === 0) return 'bar-out';
    if (qty < 5)  return 'bar-low';
    return 'bar-ok';
}

/* ═══════════════════════════════════════════════════════
   ══ UNIFIED STOCK MODAL ══
   openStockModal(itemId, itemName, colorStock, sizesByColor, hasSizes)
═══════════════════════════════════════════════════════ */
let _sm = { itemId:0, colors:[], sizesByColor:{}, hasSizes:false };

function openStockModal(itemId, itemName, colorStock, sizesByColor, hasSizes) {
    _sm = { itemId, colors: colorStock, sizesByColor, hasSizes: !!hasSizes };

    document.getElementById('stockModalTitle').textContent = '📦 ' + itemName;
    document.getElementById('stockModal').classList.add('open');

    const sizedSection = document.getElementById('stockSizedSection');
    const colorForm    = document.getElementById('stockColorForm');

    if (hasSizes) {
        sizedSection.style.display = '';
        colorForm.style.display    = 'none';
        document.getElementById('stockModalSubtitle').textContent = 'Select a color to edit its sizes.';
        _buildSizedModal(itemId, colorStock, sizesByColor);
    } else {
        sizedSection.style.display = 'none';
        colorForm.style.display    = '';
        document.getElementById('stockModalSubtitle').textContent = 'Edit stock quantity per color.';
        _buildColorModal(itemId, colorStock);
    }
}

/* ─────────────────────────────────────────────────────
   SIZED ITEMS — color list + size panel
───────────────────────────────────────────────────── */
function _buildSizedModal(itemId, colorStock, sizesByColor) {
    document.getElementById('stockSizeItemId').value = itemId;

    // Reset
    document.getElementById('stockSizePanel').classList.remove('visible');
    document.getElementById('stockTotalStrip').style.display = 'none';

    // Bulk copy
    const bulkSrc = document.getElementById('stockBulkSrc');
    bulkSrc.innerHTML = '';
    colorStock.forEach(cs => {
        const o = document.createElement('option');
        o.value = cs.id; o.textContent = cs.color_name;
        bulkSrc.appendChild(o);
    });
    document.getElementById('bulkCopyItemId').value = itemId;

    // Color list
    const listEl = document.getElementById('stockColorList');
    listEl.innerHTML = '';

    colorStock.forEach((cs, idx) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'stock-color-btn';
        btn.dataset.colorId = cs.id;
        btn.innerHTML = `
            <span class="stock-color-swatch" style="background:${colorHex(cs.color_name)}"></span>
            <span class="stock-color-name">${cs.color_name}</span>
            <span class="stock-color-total cs-total-label">${cs.stock_qty} pcs</span>
            <span style="font-size:.85rem;color:var(--text-muted)">›</span>`;
        btn.addEventListener('click', () => _selectSizeColor(cs.id, btn, cs.color_name));
        listEl.appendChild(btn);

        if (idx === 0) setTimeout(() => btn.click(), 50);
    });
}

function _selectSizeColor(colorId, btn, colorName) {
    // Highlight
    document.querySelectorAll('#stockColorList .stock-color-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.getElementById('stockSizeColorId').value = colorId;

    // Update heading
    document.getElementById('stockSizeHeadSwatch').style.background = colorHex(colorName);
    document.getElementById('stockSizeHeadText').textContent = 'Edit Sizes for ' + colorName;

    // Show panel
    const panel = document.getElementById('stockSizePanel');
    panel.classList.add('visible');

    const rowsEl = document.getElementById('stockSizeRows');
    rowsEl.innerHTML = '<div style="padding:18px 0;text-align:center;color:var(--text-muted);font-size:.85rem">Loading…</div>';
    document.getElementById('stockTotalStrip').style.display = 'none';

    // Always fetch fresh from server so auto-created sizes are picked up immediately
    fetch(`inventory.php?ajax=size_stock&color_id=${colorId}`)
        .then(r => r.json())
        .then(data => {
            _sm.sizesByColor[colorId] = data;
            _renderSizeRows(rowsEl, data, colorId);
        })
        .catch(() => {
            rowsEl.innerHTML = '<div style="padding:18px 0;text-align:center;color:#dc2626;font-size:.85rem">Failed to load sizes.</div>';
        });
}

function _renderSizeRows(rowsEl, sizes, colorId) {
    if (!sizes || sizes.length === 0) {
        rowsEl.innerHTML = `
            <div style="padding:24px 0;text-align:center;color:var(--text-muted);font-size:.85rem;line-height:1.7">
              No size variants found.<br>
              Enable sizes via <strong>Edit Details</strong>, then re-open Stock.
            </div>`;
        document.getElementById('stockTotalStrip').style.display = 'none';
        document.getElementById('stockBulkBar').style.display = 'none';
        return;
    }

    const maxQty = Math.max(...sizes.map(s => parseInt(s.stock_qty, 10) || 0), 1);

    rowsEl.innerHTML = '';
    sizes.forEach(sv => {
        const qty   = parseInt(sv.stock_qty, 10) || 0;
        const pct   = Math.min(100, Math.round((qty / maxQty) * 100));
        const bCls  = barClass(qty);
        const qCls  = qty === 0 ? 'qty-zero' : qty < 5 ? 'qty-low' : '';

        const row = document.createElement('div');
        row.className = 'size-tbl-row';
        row.innerHTML = `
            <input type="hidden" name="size_ids[]" value="${sv.id}">
            <span class="size-tbl-name">${sv.size_name}</span>
            <div class="size-tbl-bar-wrap">
              <div class="size-tbl-bar ${bCls}" style="width:${pct}%"></div>
            </div>
            <input type="number" name="size_qtys[]" value="${qty}" min="0"
                   class="size-tbl-qty ${qCls}"
                   oninput="_onSizeQtyChange(this, ${colorId})"
                   onfocus="this.style.borderColor='var(--pp-400)';this.style.boxShadow='0 0 0 3px rgba(167,139,250,.2)'"
                   onblur="this.style.boxShadow='none'">`;
        rowsEl.appendChild(row);
    });

    _recalcTotal(colorId);
    document.getElementById('stockBulkBar').style.display =
        _sm.colors.length > 1 ? 'flex' : 'none';
}

function _onSizeQtyChange(input, colorId) {
    const qty = parseInt(input.value, 10) || 0;
    input.classList.toggle('qty-zero', qty === 0);
    input.classList.toggle('qty-low',  qty > 0 && qty < 5);
    // update bar
    const row    = input.closest('.size-tbl-row');
    const barEl  = row && row.querySelector('.size-tbl-bar');
    if (barEl) {
        const allQtys = [...document.querySelectorAll('#stockSizeRows input[type=number]')]
            .map(i => parseInt(i.value,10)||0);
        const maxQ = Math.max(...allQtys, 1);
        const pct  = Math.min(100, Math.round((qty / maxQ) * 100));
        barEl.style.width = pct + '%';
        barEl.className = 'size-tbl-bar ' + barClass(qty);
    }
    _recalcTotal(colorId);
}

function _recalcTotal(colorId) {
    let total = 0;
    document.querySelectorAll('#stockSizeRows input[type=number]').forEach(i => {
        total += parseInt(i.value, 10) || 0;
    });
    document.getElementById('stockTotalNum').textContent = total;
    document.getElementById('stockTotalStrip').style.display = 'flex';

    // update color list label
    const activeBtn = document.querySelector(`#stockColorList .stock-color-btn[data-color-id="${colorId}"]`);
    if (activeBtn) {
        const lbl = activeBtn.querySelector('.cs-total-label');
        if (lbl) lbl.textContent = total + ' pcs';
    }
}

/* ─────────────────────────────────────────────────────
   NON-SIZED ITEMS — all colors table
───────────────────────────────────────────────────── */
function _buildColorModal(itemId, colorStock) {
    document.getElementById('stockColorItemId').value = itemId;

    const rowsEl = document.getElementById('stockColorRows');
    rowsEl.innerHTML = '';

    colorStock.forEach(cs => {
        const qty   = parseInt(cs.stock_qty, 10) || 0;
        const qCls  = qty === 0 ? 'qty-zero' : qty < 5 ? 'qty-low' : '';

        const row = document.createElement('div');
        row.className = 'no-size-color-row';
        row.innerHTML = `
            <input type="hidden" name="color_ids[]" value="${cs.id}">
            <div class="no-size-color-label">
              <span style="width:14px;height:14px;border-radius:50%;background:${colorHex(cs.color_name)};border:1.5px solid rgba(0,0,0,.12);flex-shrink:0"></span>
              ${cs.color_name}
            </div>
            <input type="number" name="color_qtys[]" value="${qty}" min="0"
                   class="no-size-qty-input ${qCls}"
                   oninput="applyQtyClass(this)"
                   onfocus="this.style.borderColor='var(--pp-400)';this.style.boxShadow='0 0 0 3px rgba(167,139,250,.2)'"
                   onblur="this.style.boxShadow='none'">`;
        rowsEl.appendChild(row);
    });
}

/* Bulk copy */
function submitStockBulkCopy() {
    const srcId = document.getElementById('stockBulkSrc').value;
    if (!srcId) return;
    if (!confirm('Overwrite size quantities for all other colors with this color\'s sizes?')) return;
    document.getElementById('bulkCopySrcColorId').value = srcId;
    document.getElementById('bulkCopyForm').submit();
}


/* ═══════════════════════════════════════════════════════
   ADD ITEM: dynamic qty panels
═══════════════════════════════════════════════════════ */
function toggleAddSizesPanel(on) {
    document.getElementById('addSizesPanel').style.display = on ? 'block' : 'none';
    refreshAddQtyPanels();
}

function refreshAddQtyPanels() {
    const hasSizes  = document.getElementById('addHasSizes').checked;
    const selColors = [...document.querySelectorAll('input[name="selected_colors[]"]:checked')].map(i => i.value);
    const selSizes  = hasSizes
        ? [...document.querySelectorAll('input[name="sizes[]"]:checked')].map(i => i.value)
        : [];

    const section  = document.getElementById('addQtySection');
    const content  = document.getElementById('addQtyContent');

    if (selColors.length === 0) { section.style.display = 'none'; return; }
    section.style.display = 'block';
    content.innerHTML = '';

    if (hasSizes && selSizes.length > 0) {
        // Color tabs with size rows per color
        const tabsEl = document.createElement('div');
        tabsEl.className = 'add-color-tabs';
        const panels = document.createElement('div');

        selColors.forEach((colorName, ci) => {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'add-color-tab' + (ci === 0 ? ' active' : '');
            tab.dataset.panel = 'addcp_' + colorName;
            tab.innerHTML = `<span class="tab-dot" style="background:${colorHex(colorName)}"></span>${colorName}`;
            tab.addEventListener('click', () => {
                document.querySelectorAll('.add-color-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.add-color-panel').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('addcp_' + colorName).classList.add('active');
            });
            tabsEl.appendChild(tab);

            const panel = document.createElement('div');
            panel.className = 'add-color-panel' + (ci === 0 ? ' active' : '');
            panel.id = 'addcp_' + colorName;
            panel.style.marginTop = '12px';

            const rowsEl = document.createElement('div');
            rowsEl.className = 'add-size-rows';
            selSizes.forEach(sizeName => {
                const row = document.createElement('div');
                row.className = 'add-size-row';
                row.innerHTML = `
                    <span class="add-size-row-name">${sizeName}</span>
                    <input type="number"
                           name="color_size_qtys[${colorName}][${sizeName}]"
                           value="0" min="0"
                           class="add-size-row-input"
                           placeholder="Qty">`;
                rowsEl.appendChild(row);
            });
            panel.appendChild(rowsEl);
            panels.appendChild(panel);
        });

        content.appendChild(tabsEl);
        content.appendChild(panels);

    } else if (!hasSizes && selColors.length > 0) {
        // Flat: one qty per color
        const wrap = document.createElement('div');
        wrap.className = 'add-flat-qty-wrap';
        selColors.forEach(colorName => {
            const row = document.createElement('div');
            row.className = 'add-flat-color-row';
            row.innerHTML = `
                <span class="add-flat-dot" style="background:${colorHex(colorName)}"></span>
                <span class="add-flat-name" style="text-transform:capitalize">${colorName}</span>
                <input type="number"
                       name="color_flat_qtys[${colorName}]"
                       value="0" min="0"
                       class="add-flat-input"
                       placeholder="Qty">`;
            wrap.appendChild(row);
        });
        content.appendChild(wrap);

    } else if (hasSizes && selSizes.length === 0 && selColors.length > 0) {
        content.innerHTML = '<p style="font-size:.8rem;color:var(--text-muted);padding:8px 0">Select at least one size above to enter quantities.</p>';
    }
}


/* ═══════════════════════════════════════════════════════
   IMAGE MANAGER
═══════════════════════════════════════════════════════ */
const ALL_SIDES = ['profile','front','back','left','right'];

function openImgManager(itemId, itemName, imgs) {
    document.getElementById('imgModalItemId').value = itemId;
    document.getElementById('imgModalTitle').textContent = 'Images — ' + itemName;
    ALL_SIDES.forEach(side => {
        setCardImage(side, imgs[side] || null);
        const s = document.getElementById('imgStatus_' + side);
        const f = document.getElementById('imgFile_'   + side);
        if (s) { s.textContent = ''; s.className = 'img-status'; }
        if (f) f.value = '';
    });
    document.getElementById('imgModal').classList.add('open');
}

function setCardImage(side, url) {
    const img  = document.getElementById('imgPreviewImg_'  + side);
    const ph   = document.getElementById('imgPlaceholder_' + side);
    const rb   = document.getElementById('imgRemoveBtn_'   + side);
    const card = document.getElementById('imgCard_'        + side);
    if (!img) return;
    if (url) {
        img.src = url; img.style.display = 'block';
        if (ph)  ph.style.display  = 'none';
        if (rb)  rb.style.display  = 'inline-flex';
        if (card) card.classList.add('has-image');
    } else {
        img.src = ''; img.style.display = 'none';
        if (ph)  ph.style.display  = 'block';
        if (rb)  rb.style.display  = 'none';
        if (card) card.classList.remove('has-image');
    }
}

async function resizeImage(file, maxPx = 1200, q = 0.82) {
    return new Promise((res, rej) => {
        const reader = new FileReader();
        reader.onerror = () => rej(new Error('Read failed'));
        reader.onload = e => {
            const img = new Image();
            img.onerror = () => rej(new Error('Decode failed'));
            img.onload = () => {
                let {width, height} = img;
                if (width > maxPx || height > maxPx) {
                    if (width >= height) { height = Math.round(height / width * maxPx); width = maxPx; }
                    else { width = Math.round(width / height * maxPx); height = maxPx; }
                }
                const c = document.createElement('canvas');
                c.width = width; c.height = height;
                c.getContext('2d').drawImage(img, 0, 0, width, height);
                c.toBlob(b => b ? res(b) : rej(new Error('toBlob failed')), 'image/jpeg', q);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

async function uploadImage(side, fileInput) {
    const file = fileInput.files[0];
    if (!file) return;
    const itemId = parseInt(document.getElementById('imgModalItemId').value, 10);
    if (!itemId) { alert('Error: item ID missing.'); return; }

    const statusEl  = document.getElementById('imgStatus_'  + side);
    const spinnerEl = document.getElementById('imgSpinner_' + side);
    const cardEl    = document.getElementById('imgCard_'    + side);

    statusEl.textContent = 'Compressing...'; statusEl.className = 'img-status';
    if (spinnerEl) spinnerEl.classList.add('show');
    if (cardEl)    cardEl.classList.add('uploading');

    let toSend;
    try { const blob = await resizeImage(file); toSend = new File([blob], 'product_image.jpg', {type:'image/jpeg'}); }
    catch { toSend = file; }

    statusEl.textContent = 'Uploading...';
    const fd = new FormData();
    fd.append('item_id', itemId); fd.append('side', side);
    fd.append('action', 'upload'); fd.append('image', toSend);

    try {
        const res  = await fetch('inventory.php?ajax=image', {method:'POST', body:fd});
        const data = JSON.parse(await res.text());
        if (data.success) {
            setCardImage(side, data.dataUrl);
            statusEl.textContent = 'Saved' + (data.fileSizeKB ? ` (${data.fileSizeKB} KB)` : '');
            statusEl.className   = 'img-status ok';
        } else {
            statusEl.textContent = 'Failed: ' + (data.error || 'Unknown');
            statusEl.className   = 'img-status err';
        }
    } catch {
        statusEl.textContent = 'Network error.'; statusEl.className = 'img-status err';
    } finally {
        if (spinnerEl) spinnerEl.classList.remove('show');
        if (cardEl)    cardEl.classList.remove('uploading');
        fileInput.value = '';
    }
}

async function removeImage(side) {
    if (!confirm(`Remove the ${side} image?`)) return;
    const itemId = parseInt(document.getElementById('imgModalItemId').value, 10);
    const statusEl = document.getElementById('imgStatus_' + side);
    statusEl.textContent = 'Removing...'; statusEl.className = 'img-status';
    const fd = new FormData();
    fd.append('item_id', itemId); fd.append('side', side); fd.append('action', 'remove');
    try {
        const data = JSON.parse(await (await fetch('inventory.php?ajax=image', {method:'POST', body:fd})).text());
        if (data.success) { setCardImage(side, null); statusEl.textContent = 'Removed.'; }
        else { statusEl.textContent = 'Failed: ' + (data.error || ''); statusEl.className = 'img-status err'; }
    } catch { statusEl.textContent = 'Network error.'; statusEl.className = 'img-status err'; }
}

function closeImgModal() {
    document.getElementById('imgModal').classList.remove('open');
    // Use GET redirect instead of reload() to prevent POST re-submission
    window.location.href = window.location.pathname + (window.location.search || '');
}

/* ═══════════════════════════════════════════════════════
   EDIT DETAILS MODAL
═══════════════════════════════════════════════════════ */
function openDetailsModal(itemId, itemName, category, description, colors, hasSizes) {
    document.getElementById('detailsItemId').value      = itemId;
    document.getElementById('detailsItemName').value    = itemName;
    document.getElementById('detailsCategory').value    = category;
    document.getElementById('detailsDescription').value = description;
    document.getElementById('detailsColors').value      = colors;
    document.getElementById('detailsHasSizes').checked  = (hasSizes === 1);
    document.getElementById('detailsModal').classList.add('open');
}

/* ═══════════════════════════════════════════════════════
   DESIGN BOUNDARY TOOL — Admin
═══════════════════════════════════════════════════════ */

let _bImages    = {};
let _bBoxes     = {};
let _bDrawing   = {};
let _bCurrentSide = 'front';
let _bImgObjects  = {};

function openBoundaryModal(itemId, itemName, imgs) {
  document.getElementById('boundaryItemId').value = itemId;
  document.getElementById('boundaryModalTitle').textContent = 'Design Area — ' + itemName;

  _bImages = {
    front: imgs.front || null,
    back:  imgs.back  || null,
    left:  imgs.left  || null,
    right: imgs.right || null,
  };
  _bBoxes = {}; _bDrawing = {}; _bImgObjects = {};

  fetch('inventory.php?ajax=design_boundary&action=load&item_id=' + itemId)
    .then(r => r.json())
    .then(data => {
      if (data.boundaries) _bBoxes = data.boundaries;
      ['front','back','left','right'].forEach(s => initBoundaryCanvas(s));
      switchBoundaryTab('front');
    })
    .catch(() => {
      ['front','back','left','right'].forEach(s => initBoundaryCanvas(s));
      switchBoundaryTab('front');
    });

  document.getElementById('boundaryModal').classList.add('open');
}

function closeBoundaryModal() {
  document.getElementById('boundaryModal').classList.remove('open');
}

function switchBoundaryTab(side) {
  _bCurrentSide = side;
  ['front','back','left','right'].forEach(s => {
    const view = document.getElementById('bview_' + s);
    const tab  = document.getElementById('btab_' + s);
    if (view) view.style.display = s === side ? 'block' : 'none';
    if (tab)  tab.classList.toggle('active', s === side);
  });
  setTimeout(() => renderBoundaryCanvas(side), 60);
}

function initBoundaryCanvas(side) {
  const canvas = document.getElementById('bcanvas_' + side);
  const imgSrc = _bImages[side];
  if (!imgSrc) {
    canvas.width = 560; canvas.height = 420;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#f5f3ff'; ctx.fillRect(0,0,560,420);
    ctx.fillStyle = '#c4b5fd'; ctx.font = '15px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('No product image for this side — upload one first.', 280, 210);
    document.getElementById('btab_' + side).style.opacity = '0.4';
    document.getElementById('bstat_' + side).textContent = 'Upload a product image first.';
    return;
  }
  document.getElementById('btab_' + side).style.opacity = '1';
  const img = new Image();
  img.onload = () => {
    _bImgObjects[side] = img;
    canvas.width  = img.naturalWidth;
    canvas.height = img.naturalHeight;
    renderBoundaryCanvas(side);
  };
  img.src = imgSrc;
}

function renderBoundaryCanvas(side) {
  const canvas = document.getElementById('bcanvas_' + side);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const img = _bImgObjects[side];
  ctx.clearRect(0,0,canvas.width,canvas.height);
  if (img) ctx.drawImage(img,0,0);
  else { ctx.fillStyle='#f5f3ff'; ctx.fillRect(0,0,canvas.width,canvas.height); }

  const box = _bBoxes[side];
  if (box) {
    const bx=box.x*canvas.width, by=box.y*canvas.height;
    const bw=box.w*canvas.width, bh=box.h*canvas.height;
    ctx.fillStyle='rgba(0,0,0,0.42)';
    ctx.fillRect(0,0,canvas.width,canvas.height);
    ctx.clearRect(bx,by,bw,bh);
    if (img) ctx.drawImage(img,bx,by,bw,bh,bx,by,bw,bh);
    ctx.strokeStyle='#7c3aed'; ctx.lineWidth=Math.max(2,canvas.width*0.004);
    ctx.setLineDash([8,4]); ctx.strokeRect(bx,by,bw,bh); ctx.setLineDash([]);
    const hs=Math.max(8,canvas.width*0.018);
    ctx.fillStyle='#7c3aed';
    [[bx,by],[bx+bw,by],[bx,by+bh],[bx+bw,by+bh]].forEach(([cx,cy])=>ctx.fillRect(cx-hs/2,cy-hs/2,hs,hs));
    const pctX=Math.round(box.x*100),pctY=Math.round(box.y*100),pctW=Math.round(box.w*100),pctH=Math.round(box.h*100);
    document.getElementById('bcoord_'+side).textContent=`x:${pctX}% y:${pctY}% w:${pctW}% h:${pctH}%`;
    document.getElementById('bstat_'+side).textContent=`Design area set ✓  (${pctW}% × ${pctH}% of image)`;
  } else {
    document.getElementById('bcoord_'+side).textContent='';
    document.getElementById('bstat_'+side).textContent='No boundary set — drag to draw one.';
  }
  const d=_bDrawing[side];
  if (d&&d.active) {
    ctx.strokeStyle='#a78bfa'; ctx.lineWidth=2; ctx.setLineDash([6,3]);
    ctx.strokeRect(d.sx,d.sy,d.ex-d.sx,d.ey-d.sy); ctx.setLineDash([]);
    ctx.fillStyle='rgba(124,58,237,0.10)'; ctx.fillRect(d.sx,d.sy,d.ex-d.sx,d.ey-d.sy);
  }
}

function canvasPoint(side,evt) {
  const c=document.getElementById('bcanvas_'+side), r=c.getBoundingClientRect();
  return {x:(evt.clientX-r.left)*(c.width/r.width), y:(evt.clientY-r.top)*(c.height/r.height)};
}
function startDraw(side,evt) { if(!_bImgObjects[side])return; evt.preventDefault(); const p=canvasPoint(side,evt); _bDrawing[side]={active:true,sx:p.x,sy:p.y,ex:p.x,ey:p.y}; }
function moveDraw(side,evt)  { if(!_bDrawing[side]?.active)return; const p=canvasPoint(side,evt); _bDrawing[side].ex=p.x; _bDrawing[side].ey=p.y; renderBoundaryCanvas(side); }
function endDraw(side,evt)   { if(!_bDrawing[side]?.active)return; const p=canvasPoint(side,evt); _bDrawing[side].ex=p.x; _bDrawing[side].ey=p.y; _bDrawing[side].active=false; commitBoundaryDraw(side); }

function touchP(side,evt) { const c=document.getElementById('bcanvas_'+side),r=c.getBoundingClientRect(),t=evt.touches[0]||evt.changedTouches[0]; return {x:(t.clientX-r.left)*(c.width/r.width),y:(t.clientY-r.top)*(c.height/r.height)}; }
function startDrawTouch(side,evt){evt.preventDefault();const p=touchP(side,evt);_bDrawing[side]={active:true,sx:p.x,sy:p.y,ex:p.x,ey:p.y};}
function moveDrawTouch(side,evt){evt.preventDefault();if(!_bDrawing[side]?.active)return;const p=touchP(side,evt);_bDrawing[side].ex=p.x;_bDrawing[side].ey=p.y;renderBoundaryCanvas(side);}
function endDrawTouch(side,evt){evt.preventDefault();if(!_bDrawing[side]?.active)return;const p=touchP(side,evt);_bDrawing[side].ex=p.x;_bDrawing[side].ey=p.y;_bDrawing[side].active=false;commitBoundaryDraw(side);}

function commitBoundaryDraw(side) {
  const c=document.getElementById('bcanvas_'+side), d=_bDrawing[side];
  if (!d) return;
  let x1=Math.min(d.sx,d.ex),x2=Math.max(d.sx,d.ex),y1=Math.min(d.sy,d.ey),y2=Math.max(d.sy,d.ey);
  x1=Math.max(0,x1);y1=Math.max(0,y1);x2=Math.min(c.width,x2);y2=Math.min(c.height,y2);
  const w=x2-x1,h=y2-y1;
  if (w<10||h<10){_bDrawing[side]={};renderBoundaryCanvas(side);return;}
  _bBoxes[side]={x:x1/c.width,y:y1/c.height,w:w/c.width,h:h/c.height};
  _bDrawing[side]={};
  renderBoundaryCanvas(side);
}

function clearBoundary(side) { delete _bBoxes[side]; _bDrawing[side]={}; renderBoundaryCanvas(side); }

async function saveBoundaries() {
  const itemId=document.getElementById('boundaryItemId').value;
  const btn=document.getElementById('saveBoundaryBtn'), status=document.getElementById('boundarySaveStatus');
  btn.disabled=true; btn.textContent='Saving…'; status.textContent='';
  const fd=new FormData();
  fd.append('action','save'); fd.append('item_id',itemId);
  ['front','back','left','right'].forEach(s=>{ if(_bBoxes[s]) fd.append('boundary_'+s,JSON.stringify(_bBoxes[s])); });
  try {
    const res=await fetch('inventory.php?ajax=design_boundary',{method:'POST',body:fd});
    const data=await res.json();
    if (data.success){status.textContent='✅ Boundaries saved!';status.style.color='#065f46';}
    else {status.textContent='❌ Save failed.';status.style.color='#991b1b';}
  } catch { status.textContent='❌ Network error.'; status.style.color='#991b1b'; }
  finally { btn.disabled=false; btn.textContent='💾 Save All Boundaries'; }
}
</script>
</body>
</html>