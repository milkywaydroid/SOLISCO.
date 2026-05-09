<?php
/* ============================================================
   SMOIMS – Product Ordering Page (UI aligned with home.php)
   FILE: pages/customer/ordering_item.php
   ============================================================ */

@ini_set('upload_max_filesize', '20M');
@ini_set('post_max_size',       '25M');
@ini_set('memory_limit',        '256M');

require_once '../../includes/config.php';
requireCustomerLogin();

function parseDesignImageValue(?string $value): ?string
{
    $value = trim($value ?? '');
    if ($value === '') return null;
    if (str_starts_with($value, 'data:image/') && preg_match('#^data:image/[^;]+;base64,(.+)$#', $value, $matches)) {
        return base64_decode($matches[1]);
    }
    return null;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: home.php"); exit; }

/* AJAX: variant stock */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'variants') {
    header('Content-Type: application/json');
    $itemId = (int)($_GET['id'] ?? 0);
    if (!$itemId) { echo json_encode([]); exit; }
    $cStmt = $conn->prepare("SELECT id, color_name FROM inventory_color_stock WHERE item_id = ? ORDER BY color_name");
    $cStmt->bind_param("i", $itemId); $cStmt->execute();
    $colors = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC); $cStmt->close();
    $result = [];
    foreach ($colors as $c) {
        $sStmt = $conn->prepare("SELECT size_name, quantity FROM inventory_size_variants WHERE color_id = ? ORDER BY FIELD(size_name,'XS','S','M','L','XL','XXL','Free Size')");
        $sStmt->bind_param("i", $c['id']); $sStmt->execute();
        $sizes = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC); $sStmt->close();
        $sizesMap = [];
        foreach ($sizes as $s) $sizesMap[$s['size_name']] = (int)$s['quantity'];
        $result[$c['color_name']] = ['color_id' => $c['id'], 'sizes' => $sizesMap];
    }
    $qStmt = $conn->prepare("SELECT quantity FROM inventory WHERE id = ?");
    $qStmt->bind_param("i", $itemId); $qStmt->execute();
    $qty = $qStmt->get_result()->fetch_assoc()['quantity'] ?? 0; $qStmt->close();
    echo json_encode(['colors' => $result, 'total_qty' => (int)$qty]); exit;
}

/* AJAX: place order now */
if (isset($_POST['ajax_order_now']) && $_POST['ajax_order_now'] === '1') {
    header('Content-Type: application/json');
    $customerId  = (int)$_SESSION['customer_id'];
    $itemId      = (int)($_POST['item_id']    ?? 0);
    $unitPrice   = (float)($_POST['unit_price'] ?? 0);
    $quantity    = max(1, (int)($_POST['quantity']  ?? 1));
    $color       = trim($_POST['color']    ?? '');
    $size        = trim($_POST['size']     ?? '');
    $location    = trim($_POST['location'] ?? '');
    $totalAmount = $unitPrice * $quantity;
    $designFront = ($_POST['design_front_na'] ?? '0') === '1' ? null : parseDesignImageValue($_POST['design_front'] ?? null);
    $designBack  = ($_POST['design_back_na']  ?? '0') === '1' ? null : parseDesignImageValue($_POST['design_back'] ?? null);
    $designLeft  = ($_POST['design_left_na']  ?? '0') === '1' ? null : parseDesignImageValue($_POST['design_left'] ?? null);
    $designRight = ($_POST['design_right_na'] ?? '0') === '1' ? null : parseDesignImageValue($_POST['design_right'] ?? null);
    if (!$itemId || !$color || $unitPrice <= 0) { echo json_encode(['success' => false, 'message' => 'Missing required order data.']); exit; }
    $conn->begin_transaction();
    try {
        $oStmt = $conn->prepare("INSERT INTO orders (customer_id, total_amount, status, location) VALUES (?, ?, 'Pending', ?)");
        $oStmt->bind_param("ids", $customerId, $totalAmount, $location); $oStmt->execute();
        $orderId = $conn->insert_id; $oStmt->close();
        $iStmt = $conn->prepare("INSERT INTO order_items (order_id, item_id, quantity, unit_price, color, size, design_front, design_back, design_left, design_right) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $iStmt->bind_param("iiidssssss", $orderId, $itemId, $quantity, $unitPrice, $color, $size, $designFront, $designBack, $designLeft, $designRight);
        $iStmt->execute(); $iStmt->close();
        $conn->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Order could not be saved. Please try again.']);
    }
    exit;
}

/* Fetch product */
$stmt = $conn->prepare("SELECT * FROM inventory WHERE id = ? AND quantity > 0");
$stmt->bind_param("i", $id); $stmt->execute();
$product = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$product) { header("Location: home.php"); exit; }

/* Variants */
$cStmt = $conn->prepare("SELECT id, color_name FROM inventory_color_stock WHERE item_id = ? ORDER BY color_name");
$cStmt->bind_param("i", $id); $cStmt->execute();
$colorRows = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC); $cStmt->close();
$variantData = [];
foreach ($colorRows as $c) {
    $sStmt = $conn->prepare("SELECT size_name, quantity FROM inventory_size_variants WHERE color_id = ? ORDER BY FIELD(size_name,'XS','S','M','L','XL','XXL','Free Size')");
    $sStmt->bind_param("i", $c['id']); $sStmt->execute();
    $sizeRows = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC); $sStmt->close();
    $sizesMap = [];
    foreach ($sizeRows as $s) $sizesMap[$s['size_name']] = (int)$s['quantity'];
    $variantData[$c['color_name']] = ['color_id' => $c['id'], 'sizes' => $sizesMap];
}
if (empty($variantData) && !empty($product['colors'])) {
    foreach (array_filter(array_map('trim', explode(',', $product['colors']))) as $cname)
        $variantData[$cname] = ['color_id' => null, 'sizes' => []];
}
$ALL_SIZES = ['XS','S','M','L','XL','XXL','Free Size'];
$allSizeNames = [];
foreach ($variantData as $vd) foreach (array_keys($vd['sizes']) as $sz) if (!in_array($sz, $allSizeNames)) $allSizeNames[] = $sz;
usort($allSizeNames, fn($a,$b) => (array_search($a,$ALL_SIZES)===false?99:array_search($a,$ALL_SIZES)) - (array_search($b,$ALL_SIZES)===false?99:array_search($b,$ALL_SIZES)));

$hasNoSizes = empty($allSizeNames);

/* Reviews */
$reviewsStmt = $conn->prepare("SELECT r.*, c.full_name AS customer_name FROM reviews r JOIN customers c ON r.customer_id = c.id WHERE r.product_id = ? ORDER BY r.created_at DESC LIMIT 10");
$reviewsStmt->bind_param("i", $id); $reviewsStmt->execute();
$reviews = $reviewsStmt->get_result()->fetch_all(MYSQLI_ASSOC); $reviewsStmt->close();
$avgRating = 0;
if (!empty($reviews)) { $avgRating = round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1); }

function colorToHex(string $name): string {
    $map=['white'=>'#f8fafc','black'=>'#1a1a2e','red'=>'#ef4444','blue'=>'#3b82f6','green'=>'#22c55e','yellow'=>'#eab308','purple'=>'#a855f7','pink'=>'#ec4899','orange'=>'#f97316','gray'=>'#9ca3af','grey'=>'#9ca3af','brown'=>'#92400e','beige'=>'#d9c8a9','coral'=>'#f87171','navy'=>'#1e3a5f','teal'=>'#14b8a6','cream'=>'#fef3c7','lavender'=>'#c4b5fd','maroon'=>'#9f1239','sky'=>'#38bdf8','light blue'=>'#bae6fd','violet'=>'#7c3aed'];
    $lower=strtolower(trim($name));
    foreach($map as $k=>$h) if(str_contains($lower,$k)) return $h;
    return '#94a3b8';
}
function dbImageToDataUrl(?string $blob): ?string {
    if (empty($blob)) return null;
    if (str_starts_with($blob, 'data:image/')) return $blob;
    return 'data:image/jpeg;base64,' . base64_encode($blob);
}

$sideImages = ['front'=>$product['image_front']??null,'back'=>$product['image_back']??null,'left'=>$product['image_left']??null,'right'=>$product['image_right']??null,'profile'=>$product['profile_image']??null];
foreach ($sideImages as $side => $img) { $sideImages[$side] = dbImageToDataUrl($img); }
$defaultImg = null; $defaultSide = 'profile';
foreach($sideImages as $sk=>$img) { if($img){$defaultImg=$img; $defaultSide=$sk; break;} }
$qty=$product['quantity'];
if($qty<=0){$badgeClass='badge-out';$badgeText='Out of Stock';}
elseif($qty<10){$badgeClass='badge-low';$badgeText="Only $qty left";}
else{$badgeClass='badge-ok';$badgeText="In Stock";}

$customerId = $_SESSION['customer_id'];
$cartCountRes = $conn->query("SELECT COUNT(*) as cnt FROM cart WHERE customer_id = $customerId");
$cartCount = $cartCountRes ? (int)$cartCountRes->fetch_assoc()['cnt'] : 0;
$existingBoundaries = json_decode($product['design_boundaries'] ?? '{}', true);

$colorAvailability = [];
foreach ($variantData as $colorName => $vd) {
    if ($hasNoSizes) {
        if ($vd['color_id'] !== null) {
            $csStmt = $conn->prepare("SELECT stock_qty FROM inventory_color_stock WHERE id = ?");
            $csStmt->bind_param("i", $vd['color_id']); $csStmt->execute();
            $csRow = $csStmt->get_result()->fetch_assoc(); $csStmt->close();
            if ($csRow !== null && isset($csRow['stock_qty'])) {
                $colorAvailability[$colorName] = (int)$csRow['stock_qty'] > 0;
            } else {
                $colorAvailability[$colorName] = $qty > 0;
            }
        } else {
            $colorAvailability[$colorName] = $qty > 0;
        }
    } else {
        $colorAvailability[$colorName] = array_sum($vd['sizes']) > 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title><?= htmlspecialchars($product['item_name']) ?> | SolisCo.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../css/style.css">
<style>
/* ── Tokens (aligned with home.php) ── */
:root {
  --c-bg: #f5f3ff;
  --c-surface: #ffffff;
  --c-surface-2: #faf9ff;
  --c-border: #ede9fe;
  --c-border-strong: #c4b5fd;
  --c-accent: #7c3aed;
  --c-accent-light: #ede9fe;
  --c-accent-mid: #a78bfa;
  --c-accent-deep: #5b21b6;
  --c-text: #1e1b4b;
  --c-muted: #6b7280;
  --c-ok: #16a34a;
  --c-ok-bg: #dcfce7;
  --c-warn: #d97706;
  --c-warn-bg: #fef3c7;
  --c-err: #dc2626;
  --c-err-bg: #fef2f2;
  --r-card: 20px;
  --r-btn: 12px;
  --shadow-card: 0 2px 8px rgba(109,40,217,.06), 0 8px 24px rgba(109,40,217,.05);
  --shadow-hover: 0 12px 40px rgba(109,40,217,.20), 0 4px 12px rgba(109,40,217,.10);
  --grad-primary: #7c3aed;
  --grad-hero: linear-gradient(135deg, #ede9fe 0%, #fce7f3 50%, #e0e7ff 100%);
  --font-head: 'Sora', sans-serif;
  --font-display: 'Playfair Display', serif;
  --font-body: 'DM Sans', sans-serif;

  /* Aliases used by Design Studio + form components */
  --bg: var(--c-bg);
  --bg-2: var(--c-accent-light);
  --bg-card: var(--c-surface);
  --bg-card-2: var(--c-surface-2);
  --border: var(--c-border);
  --border-hi: var(--c-border-strong);
  --text: var(--c-text);
  --text-2: var(--c-muted);
  --text-3: var(--c-accent-mid);
  --accent: var(--c-accent);
  --accent-2: #8b5cf6;
  --accent-3: var(--c-accent-mid);
  --accent-light: var(--c-accent-light);
  --accent-glow: rgba(124,58,237,.18);
  --green: var(--c-ok);
  --green-bg: var(--c-ok-bg);
  --red: var(--c-err);
  --red-bg: var(--c-err-bg);
  --amber: var(--c-warn);
  --amber-bg: var(--c-warn-bg);
  --pink: #ec4899;
  --shadow-sm: 0 1px 3px rgba(124,58,237,.08), 0 1px 2px rgba(0,0,0,.04);
  --shadow: var(--shadow-card);
  --shadow-lg: 0 24px 60px rgba(30,27,75,.25);
  --r: 10px;
  --r-lg: 16px;
  --r-xl: 22px;
  --ff: var(--font-body);
  --ff-h: var(--font-display);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--font-body);
  background: var(--c-bg);
  color: var(--c-text);
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }
button { font-family: inherit; cursor: pointer; border: none; background: none; }
img { display: block; max-width: 100%; }

/* ── Animated blobs (from home) ── */
body::before, body::after {
  content: '';
  position: fixed;
  width: 500px; height: 500px;
  border-radius: 50%;
  filter: blur(120px);
  opacity: .35;
  z-index: -1;
  pointer-events: none;
  animation: blobFloat 18s ease-in-out infinite;
}
body::before {
  background: radial-gradient(circle, #c4b5fd, transparent 70%);
  top: -150px; left: -150px;
}
body::after {
  background: radial-gradient(circle, #fbcfe8, transparent 70%);
  bottom: -150px; right: -150px;
  animation-delay: -9s;
}
@keyframes blobFloat {
  0%,100% { transform: translate(0,0) scale(1); }
  33%     { transform: translate(60px,-40px) scale(1.1); }
  66%     { transform: translate(-40px,60px) scale(.95); }
}

/* ── Toast ── */
.toast-stack { position: fixed; top: 96px; right: 22px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
.toast {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 18px; border-radius: 14px;
  background: rgba(255,255,255,.92); backdrop-filter: blur(14px);
  border: 1.5px solid rgba(255,255,255,.8);
  font-size: .85rem; font-weight: 600; color: var(--c-text);
  min-width: 240px; animation: slideIn .3s ease;
  box-shadow: var(--shadow-card);
}
.toast.hiding { animation: slideOut .3s ease forwards; }
.toast-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .78rem; background: var(--c-ok-bg); color: var(--c-ok); flex-shrink: 0; font-weight: 800; }
.toast-error .toast-icon { background: var(--c-err-bg); color: var(--c-err); }
@keyframes slideIn { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:none; } }
@keyframes slideOut { to { opacity:0; transform:translateX(20px); } }

/* ── Page wrap ── */
.cust-page { max-width: 1400px; margin: 0 auto; padding: 24px 24px 80px; position: relative; z-index: 1; }

/* ── Topbar (glass — matches home) ── */
.topbar {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 22px; margin-bottom: 22px;
  background: rgba(255,255,255,.7);
  backdrop-filter: blur(20px) saturate(140%);
  -webkit-backdrop-filter: blur(20px) saturate(140%);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: 18px;
  box-shadow: 0 4px 24px rgba(109,40,217,.06);
  position: sticky; top: 16px; z-index: 100;
  animation: slideDown .7s cubic-bezier(.22,1,.36,1) both;
}
@keyframes slideDown {
  from { opacity: 0; transform: translateY(-20px); }
  to   { opacity: 1; transform: translateY(0); }
}
.topbar-logo {
  font-family: var(--font-display);
  font-weight: 800; font-size: 1.4rem;
  background: var(--grad-primary);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  letter-spacing: .5px;
}
.topbar-nav { display: flex; gap: 6px; align-items: center; }
.topbar-nav a {
  padding: 8px 14px; border-radius: 10px;
  font-weight: 600; font-size: .9rem; color: var(--c-text);
  text-decoration: none; transition: all .25s;
  position: relative;
}
.topbar-nav a:hover { background: var(--c-accent-light); color: var(--c-accent); transform: translateY(-1px); }
.topbar-nav a.active {
  background: var(--grad-primary); color: #fff;
  box-shadow: 0 4px 14px rgba(124,58,237,.35);
}
.topbar-nav a.danger { color: var(--c-err); }
.topbar-nav a.danger:hover { background: var(--c-err-bg); color: var(--c-err); }
.cart-badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 5px; margin-left:6px; background:#ec4899; color:#fff; font-size:.62rem; font-weight:800; border-radius:99px; }

/* ── Breadcrumb ── */
.breadcrumb {
  display: flex; align-items: center; gap: 8px;
  font-size: .82rem; color: var(--c-muted); font-weight: 500;
  margin: 4px 4px 18px;
  animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .15s both;
}
.breadcrumb a { color: var(--c-accent); font-weight: 600; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb .sep { color: var(--c-accent-mid); opacity: .6; }
.breadcrumb .current { color: var(--c-text); font-weight: 700; }

/* ── Page grid ── */
.page {
  display: grid;
  grid-template-columns: 320px 1fr 360px;
  gap: 22px;
  align-items: start;
}
@media (max-width: 1100px) { .page { grid-template-columns: 280px 1fr; } .panel-right { grid-column: 1/-1; } }
@media (max-width: 760px)  { .page { grid-template-columns: 1fr; } }

/* ── Panel base (glass cards like home) ── */
.panel {
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(12px);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: var(--r-card);
  overflow: hidden;
  box-shadow: var(--shadow-card);
  animation: cardIn .6s cubic-bezier(.22,1,.36,1) both;
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(20px) scale(.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.panel-head {
  padding: 16px 22px;
  border-bottom: 1.5px solid var(--c-border);
  display: flex; align-items: center; gap: 10px;
  background: linear-gradient(135deg, rgba(139,92,246,.06) 0%, transparent 100%);
}
.panel-head-title {
  font-family: var(--font-head);
  font-size: .78rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: 1.4px;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.panel-body { padding: 20px; }

/* ── Left Panel: image + product info ── */
.product-img-main {
  width: 100%; aspect-ratio: 1;
  background: linear-gradient(135deg, #ede9fe, #fce7f3);
  display: flex; align-items: center; justify-content: center;
  position: relative; overflow: hidden;
}
.product-img-main img { width: 100%; height: 100%; object-fit: contain; padding: 18px; transition: transform .5s cubic-bezier(.22,1,.36,1); }
.product-img-main:hover img { transform: scale(1.05); }
.no-img-ph { font-size: 4rem; opacity: .35; }
.side-tag {
  position: absolute; bottom: 12px; left: 12px;
  background: rgba(255,255,255,.92); backdrop-filter: blur(8px);
  border: 1px solid rgba(255,255,255,.8);
  padding: 5px 14px; border-radius: 99px;
  font-size: .68rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: 1px;
  color: var(--c-accent-deep);
}
.stock-pill {
  position: absolute; top: 12px; right: 12px;
  padding: 5px 12px; border-radius: 99px;
  font-size: .68rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .5px;
  backdrop-filter: blur(8px);
  box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.badge-ok  { background: rgba(22,163,74,.95); color: #fff; }
.badge-low { background: rgba(217,119,6,.95); color: #fff; }
.badge-out { background: rgba(220,38,38,.95); color: #fff; }

.thumb-rail { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; padding: 12px; background: var(--c-accent-light); border-top: 1.5px solid var(--c-border); }
.thumb { aspect-ratio: 1; border-radius: 12px; background: #fff; border: 1.5px solid var(--c-border); overflow: hidden; cursor: pointer; transition: all .25s; display: flex; align-items: center; justify-content: center; }
.thumb img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
.thumb:hover { border-color: var(--c-accent-mid); box-shadow: 0 0 0 3px rgba(167,139,250,.18); transform: translateY(-2px); }
.thumb.active { border-color: var(--c-accent); box-shadow: 0 0 0 3px rgba(124,58,237,.22); }
.thumb.dim { opacity: .35; cursor: not-allowed; }
.thumb-ph { display: flex; flex-direction: column; align-items: center; gap: 2px; color: var(--c-accent-mid); font-size: .55rem; font-weight: 800; text-transform: uppercase; }
.thumb-ph-icon { font-size: 1.1rem; }

.prod-category { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: var(--c-accent); margin-bottom: 6px; }
.prod-name { font-family: var(--font-head); font-size: 1.45rem; font-weight: 700; line-height: 1.25; margin-bottom: 10px; color: var(--c-text); }
.prod-price {
  font-family: var(--font-display);
  font-size: 1.9rem; font-weight: 800;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
  margin-bottom: 14px;
}
.prod-desc {
  font-size: .85rem; line-height: 1.65; color: var(--c-muted);
  padding: 14px; background: var(--c-accent-light);
  border-radius: var(--r-btn); border: 1.5px solid var(--c-border);
}
.prod-rating-row {
  padding: 12px 20px; border-top: 1.5px dashed var(--c-border);
  background: rgba(237,233,254,.4);
  display: flex; align-items: center; gap: 8px; font-size: .82rem; color: var(--c-muted);
}

/* ══════════════════════════════════════════
   DESIGN STUDIO
══════════════════════════════════════════ */
.ds-tabs { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1.5px solid var(--c-border); }
.ds-tab { padding: 14px 8px; display: flex; flex-direction: column; align-items: center; gap: 4px; font-size: .75rem; font-weight: 700; color: var(--c-muted); transition: all .25s; position: relative; border-bottom: 2px solid transparent; background: transparent; font-family: var(--font-body); }
.ds-tab:hover { color: var(--c-accent); background: var(--c-accent-light); }
.ds-tab.active { color: var(--c-accent); border-bottom-color: var(--c-accent); background: var(--c-accent-light); }
.ds-tab-icon { font-size: 1.15rem; }
.ds-tab-label { font-size: .7rem; font-weight: 800; letter-spacing: .5px; }
.ds-dot { width: 7px; height: 7px; border-radius: 50%; background: #e5e7eb; border: 1.5px solid #d1d5db; position: absolute; top: 8px; right: 8px; transition: all .2s; }
.ds-dot.filled { background: var(--c-ok); border-color: var(--c-ok); box-shadow: 0 0 6px rgba(22,163,74,.45); }
.ds-dot.na-dot { background: var(--c-muted); border-color: var(--c-muted); }

.ds-canvas-area {
  background:
    repeating-conic-gradient(rgba(139,92,246,.05) 0% 25%, transparent 0% 50%) 0 0 / 20px 20px,
    #faf9ff;
  display: flex; align-items: stretch; justify-content: stretch;
  min-height: 420px; padding: 0; position: relative; width: 100%;
}
#ds_canvas_container { position: relative; display: block; touch-action: none; user-select: none; width: 100%; line-height: 0; }
#ds_product_canvas { display: block; border-radius: 0; width: 100%; height: auto; max-height: 600px; }
#ds_mask_window { position: absolute; background: transparent; overflow: hidden; pointer-events: auto; box-shadow: 0 0 0 1.5px var(--c-accent), 0 0 0 2000px rgba(124,58,237,.07); z-index: 10; border-radius: 4px; }
.ds-mask-line { position: absolute; inset: 0; border: 2px dashed rgba(124,58,237,.5); border-radius: 3px; pointer-events: none; }
#ds_image_wrapper { position: absolute; top: 0; left: 0; transform-origin: top left; will-change: transform; pointer-events: auto; }
#ds_design_img { width: 100%; height: 100%; object-fit: contain; pointer-events: none; display: block; }
.ds-resize-handle { position: absolute; width: 16px; height: 16px; background: var(--c-accent); border: 2px solid #fff; border-radius: 50%; bottom: -8px; right: -8px; cursor: nwse-resize; z-index: 30; box-shadow: 0 2px 8px rgba(0,0,0,.25); pointer-events: auto; }
.ds-rotate-handle { position: absolute; width: 22px; height: 22px; background: #fff; border: 2px solid var(--c-accent); border-radius: 50%; top: -28px; left: 50%; transform: translateX(-50%); cursor: grab; z-index: 30; display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--c-accent); box-shadow: 0 2px 8px rgba(0,0,0,.15); pointer-events: auto; }
.ds-rotate-handle::after { content: '↻'; }

.ds-toolbar { display: flex; align-items: center; gap: 8px; padding: 14px 18px; border-top: 1.5px solid var(--c-border); flex-wrap: wrap; background: rgba(250,249,255,.6); }
.ds-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 99px; font-size: .8rem; font-weight: 700; transition: all .25s; font-family: var(--font-body); }
.ds-btn-primary { background: var(--grad-primary); color: #fff; box-shadow: 0 4px 14px rgba(124,58,237,.32); }
.ds-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(124,58,237,.42); }
.ds-btn-ghost { background: #fff; border: 1.5px solid var(--c-border); color: var(--c-muted); }
.ds-btn-ghost:hover { border-color: var(--c-accent-mid); color: var(--c-accent); }
.ds-btn-danger:hover { border-color: rgba(220,38,38,.35); color: var(--c-err); background: var(--c-err-bg); }
.ds-spacer { flex: 1; }
.ds-status { display: flex; align-items: center; gap: 6px; font-size: .76rem; color: var(--c-muted); font-weight: 600; }
.ds-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; }
.ds-status-dot.ok { background: var(--c-ok); box-shadow: 0 0 6px rgba(22,163,74,.45); }
.ds-status-dot.na { background: var(--c-muted); }

.ds-summary { display: grid; grid-template-columns: repeat(4,1fr); gap: 6px; padding: 12px 18px; border-top: 1.5px solid var(--c-border); background: rgba(250,249,255,.6); }
.ds-chip { display: flex; align-items: center; gap: 5px; padding: 7px 8px; border-radius: 10px; font-size: .7rem; font-weight: 700; color: var(--c-muted); background: var(--c-accent-light); justify-content: center; border: 1.5px solid var(--c-border); }
.ds-chip.filled { color: var(--c-ok); background: var(--c-ok-bg); border-color: rgba(22,163,74,.25); }
.ds-chip.na { color: var(--c-muted); background: #f3f4f6; border-color: #e5e7eb; }
.ds-chip-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; opacity: .7; }
.ds-tip {
  margin: 12px 18px 16px; padding: 12px 16px;
  background: var(--c-accent-light);
  border-radius: var(--r-btn); border-left: 3px solid var(--c-accent-mid);
  font-size: .78rem; color: var(--c-accent-deep); line-height: 1.55; font-weight: 500;
}

/* ── Right Panel: Form ── */
.form-section { padding: 18px 20px; border-bottom: 1.5px solid var(--c-border); }
.form-section:last-child { border-bottom: none; }
.sec-label { display: flex; align-items: center; gap: 10px; font-size: .74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: var(--c-text); margin-bottom: 14px; }
.sec-num { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: var(--c-accent-light); border: 1.5px solid var(--c-accent-mid); font-size: .7rem; font-weight: 800; color: var(--c-accent); flex-shrink: 0; transition: all .25s; }
.sec-num.done { background: var(--grad-primary); border-color: transparent; color: #fff; box-shadow: 0 3px 10px rgba(124,58,237,.35); }
.req { color: var(--c-err); font-style: normal; }

.color-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 8px; }
.color-card { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 6px; background: var(--c-accent-light); border: 1.5px solid var(--c-border); border-radius: 14px; cursor: pointer; transition: all .25s; position: relative; }
.color-card.avail:hover { border-color: var(--c-accent); transform: translateY(-2px); box-shadow: 0 6px 14px rgba(124,58,237,.18); }
.color-card.selected { border-color: var(--c-accent); background: #fff; box-shadow: 0 0 0 3px rgba(124,58,237,.22); }
.color-card.selected::after { content: '✓'; position: absolute; top: 4px; right: 6px; color: var(--c-accent); font-size: .78rem; font-weight: 800; }
.color-card.oos { opacity: .45; cursor: not-allowed; pointer-events: none; filter: grayscale(60%); }
.color-card.oos::before { content: 'OUT'; position: absolute; top: 4px; right: 4px; font-size: .5rem; font-weight: 800; color: var(--c-err); background: var(--c-err-bg); padding: 1px 5px; border-radius: 4px; letter-spacing: .5px; }
.swatch { width: 34px; height: 34px; border-radius: 50%; border: 2px solid rgba(0,0,0,.08); box-shadow: 0 1px 4px rgba(0,0,0,.1); }
.clabel { font-size: .68rem; font-weight: 700; color: var(--c-text); text-align: center; line-height: 1.2; }

.size-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(54px, 1fr)); gap: 6px; }
.size-btn { padding: 11px 6px; border-radius: 10px; background: var(--c-accent-light); border: 1.5px solid var(--c-border); font-size: .82rem; font-weight: 800; color: var(--c-text); transition: all .25s; font-family: var(--font-body); }
.size-btn:not(:disabled):hover { border-color: var(--c-accent); color: var(--c-accent); background: #fff; transform: translateY(-1px); }
.size-btn.selected { background: var(--grad-primary); border-color: transparent; color: #fff; box-shadow: 0 4px 12px rgba(124,58,237,.32); }
.size-btn:disabled { opacity: .35; cursor: not-allowed; }
.size-hint { font-size: .75rem; color: var(--c-muted); margin-top: 8px; font-weight: 500; }

.qty-row { display: flex; align-items: center; gap: 14px; }
.qty-ctrl { display: inline-flex; align-items: center; background: var(--c-accent-light); border: 1.5px solid var(--c-border); border-radius: var(--r-btn); overflow: hidden; }
.qty-btn { width: 40px; height: 40px; font-size: 1.15rem; font-weight: 800; color: var(--c-accent); transition: background .2s; }
.qty-btn:hover { background: var(--c-accent); color: #fff; }
.qty-num { width: 54px; height: 40px; text-align: center; border: none; border-left: 1px solid var(--c-border); border-right: 1px solid var(--c-border); font-size: .92rem; font-weight: 800; color: var(--c-text); background: #fff; -moz-appearance: textfield; font-family: var(--font-body); }
.qty-num::-webkit-outer-spin-button, .qty-num::-webkit-inner-spin-button { -webkit-appearance: none; }
.qty-note { font-size: .76rem; color: var(--c-muted); font-weight: 600; }

.loc-input {
  width: 100%; padding: 13px 16px;
  border: 1.5px solid var(--c-border); border-radius: var(--r-btn);
  font-size: .88rem; font-family: var(--font-body); font-weight: 500;
  background: rgba(255,255,255,.85); color: var(--c-text);
  transition: all .25s; outline: none;
}
.loc-input:focus { border-color: var(--c-accent-mid); background: #fff; box-shadow: 0 0 0 4px rgba(167,139,250,.18); }
.loc-input::placeholder { color: #b3a9d3; }

.val-msg { display: none; margin: 0 20px 14px; padding: 11px 14px; border-radius: var(--r-btn); background: var(--c-err-bg); color: var(--c-err); font-size: .8rem; font-weight: 600; border: 1.5px solid rgba(220,38,38,.2); }
.val-msg.show { display: block; }

.action-section { padding: 18px 20px; display: flex; flex-direction: column; gap: 10px; background: linear-gradient(180deg, transparent 0%, rgba(139,92,246,.05) 100%); }
.btn-order {
  width: 100%; padding: 14px; border-radius: var(--r-btn);
  background: var(--grad-primary); color: #fff;
  font-size: .94rem; font-weight: 800; font-family: var(--font-body);
  letter-spacing: .3px; transition: all .3s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: 0 6px 18px rgba(124,58,237,.35);
  position: relative; overflow: hidden;
}
.btn-order::before {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(120deg, transparent, rgba(255,255,255,.4), transparent);
  transform: translateX(-100%); transition: transform .6s;
}
.btn-order:not(:disabled):hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(124,58,237,.48); }
.btn-order:not(:disabled):hover::before { transform: translateX(100%); }
.btn-cart {
  width: 100%; padding: 12px; border-radius: var(--r-btn);
  background: #fff; border: 2px solid var(--c-border-strong); color: var(--c-accent);
  font-size: .88rem; font-weight: 800; font-family: var(--font-body);
  transition: all .25s; display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-cart:not(:disabled):hover { border-color: var(--c-accent); background: var(--c-accent-light); transform: translateY(-1px); }
.btn-order:disabled, .btn-cart:disabled { opacity: .4; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

.sync-row { display: flex; align-items: center; justify-content: center; gap: 7px; font-size: .73rem; color: var(--c-muted); font-weight: 600; }
.sync-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--c-ok); animation: pulse 2s infinite; box-shadow: 0 0 8px var(--c-ok); }
@keyframes pulse { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: .4; transform: scale(.8); } }

.design-progress { padding: 16px 20px; border-bottom: 1.5px solid var(--c-border); }
.design-progress-label { font-size: .74rem; color: var(--c-text); margin-bottom: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; }
.design-chips { display: grid; grid-template-columns: repeat(4,1fr); gap: 6px; }
.d-chip { padding: 7px 4px; border-radius: 10px; border: 1.5px solid var(--c-border); background: var(--c-accent-light); text-align: center; font-size: .68rem; font-weight: 800; color: var(--c-muted); transition: all .25s; }
.d-chip.ok { background: var(--c-ok-bg); border-color: rgba(22,163,74,.25); color: var(--c-ok); }
.d-chip.na-c { border-color: #e5e7eb; color: var(--c-muted); background: #f9fafb; }

/* ── Modal (matches home) ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(30,27,75,.5); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-overlay.show, .modal-overlay.open { display: flex; animation: fadeIn .25s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.modal {
  background: rgba(255,255,255,.95); backdrop-filter: blur(20px);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: 24px; padding: 32px; max-width: 420px; width: 100%;
  box-shadow: var(--shadow-lg); text-align: center; position: relative;
}
.modal-close { position: absolute; top: 14px; right: 14px; width: 32px; height: 32px; border-radius: 50%; background: var(--c-accent-light); color: var(--c-muted); font-size: 1.1rem; display: flex; align-items: center; justify-content: center; transition: all .2s; }
.modal-close:hover { background: var(--c-err-bg); color: var(--c-err); }
.modal-icon { font-size: 2.8rem; margin-bottom: 10px; }
.modal h2, .modal h3 { font-family: var(--font-display); font-size: 1.5rem; font-weight: 800; margin-bottom: 6px; color: var(--c-text); }
.modal-sub, .modal p { font-size: .88rem; color: var(--c-muted); margin-bottom: 20px; line-height: 1.5; }
.modal-meta { background: var(--c-accent-light); border: 1.5px solid var(--c-border); border-radius: 16px; padding: 16px; margin-bottom: 22px; text-align: left; }
.modal-meta-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; }
.modal-meta-row + .modal-meta-row { border-top: 1.5px dashed var(--c-border); }
.modal-meta-label { font-size: .73rem; color: var(--c-muted); font-weight: 800; text-transform: uppercase; letter-spacing: .6px; }
.modal-meta-val { font-size: .9rem; font-weight: 700; color: var(--c-text); }
.modal-meta-val.price {
  font-family: var(--font-display); font-size: 1.15rem; font-weight: 800;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.modal-actions, .btn-row { display: grid; grid-template-columns: 1fr 1.3fr; gap: 12px; }
.btn-row { grid-template-columns: 1fr 1fr; }
.btn-cancel { padding: 13px; border-radius: var(--r-btn); background: #fff; border: 1.5px solid var(--c-border); color: var(--c-text); font-size: .87rem; font-weight: 700; transition: all .2s; font-family: var(--font-body); }
.btn-cancel:hover { background: var(--c-bg); border-color: var(--c-border-strong); }
.btn-confirm { padding: 13px; border-radius: var(--r-btn); background: var(--grad-primary); color: #fff; font-size: .87rem; font-weight: 800; transition: all .2s; box-shadow: 0 4px 14px rgba(124,58,237,.32); font-family: var(--font-body); }
.btn-confirm:hover { box-shadow: 0 8px 22px rgba(124,58,237,.45); transform: translateY(-1px); }
.btn-logout-ok { padding: 13px; border-radius: var(--r-btn); background: var(--c-err); color: #fff; text-decoration: none; font-weight: 800; transition: all .2s; display:flex; align-items:center; justify-content:center; }
.btn-logout-ok:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(220,38,38,.35); }
.lo-icon { font-size: 3.2rem; margin-bottom: 12px; animation: wave 1.6s ease-in-out infinite; display: inline-block; transform-origin: 70% 70%; }
@keyframes wave { 0%,100% { transform: rotate(0); } 25% { transform: rotate(15deg); } 75% { transform: rotate(-10deg); } }

/* ── Reviews ── */
.reviews-wrap { margin-top: 36px; }
.reviews-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.reviews-title { font-family: var(--font-display); font-size: 1.6rem; font-weight: 800; color: var(--c-text); }
.reviews-summary { display: flex; align-items: center; gap: 8px; font-size: .85rem; color: var(--c-muted); }
.stars { display: inline-flex; gap: 1px; }
.star { color: #d1d5db; font-size: .95rem; }
.star.filled { color: #f59e0b; }
.reviews-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.review-card {
  background: rgba(255,255,255,.85); backdrop-filter: blur(10px);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: var(--r-card); padding: 20px;
  transition: all .3s cubic-bezier(.22,1,.36,1);
  box-shadow: var(--shadow-card);
}
.review-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
.review-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.review-author { font-family: var(--font-head); font-weight: 700; font-size: .92rem; color: var(--c-text); }
.review-comment { font-size: .85rem; color: var(--c-muted); line-height: 1.6; margin-bottom: 8px; }
.review-date { font-size: .72rem; color: var(--c-accent-mid); font-weight: 600; }
.review-reply { margin-top: 12px; padding: 12px 14px; background: var(--c-accent-light); border-radius: var(--r-btn); border-left: 3px solid var(--c-accent-mid); font-size: .82rem; color: var(--c-accent-deep); }
.review-reply-label { font-size: .68rem; font-weight: 800; color: var(--c-accent); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 4px; }
.no-reviews {
  text-align: center; padding: 60px 24px;
  background: rgba(255,255,255,.6);
  border: 1.5px dashed var(--c-border-strong);
  border-radius: var(--r-card); color: var(--c-muted);
}
.no-reviews-icon { font-size: 3rem; margin-bottom: 12px; opacity: .55; }

/* ── Floating cart (matches home) ── */
.float-cart {
  position: fixed; bottom: 28px; right: 28px; z-index: 50;
  display: inline-flex; align-items: center; gap: 8px;
  padding: 14px 22px; border-radius: 99px;
  background: var(--grad-primary); color: #fff;
  text-decoration: none; font-weight: 700;
  box-shadow: 0 8px 28px rgba(124,58,237,.45);
  transition: all .3s;
  animation: cartIn .6s cubic-bezier(.22,1,.36,1) .8s both;
}
@keyframes cartIn {
  from { opacity: 0; transform: translateY(40px) scale(.8); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.float-cart:hover { transform: translateY(-3px) scale(1.05); box-shadow: 0 14px 36px rgba(124,58,237,.55); }
.float-cart .fc-badge { background: rgba(255,255,255,.25); padding: 2px 8px; border-radius: 99px; font-size: .72rem; font-weight: 800; }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── Responsive ── */
@media (max-width: 720px) {
  .topbar { flex-direction: column; gap: 12px; padding: 14px; position: static; }
  .topbar-nav { flex-wrap: wrap; justify-content: center; }
  .ds-canvas-area { min-height: 280px; }
  .panel-body { padding: 16px; }
  .form-section { padding: 14px 16px; }
  .cust-page { padding: 16px 14px 80px; }
}
</style>
</head>
<body>

<div class="toast-stack" id="toastStack"></div>

<div class="cust-page">

  <!-- TOP NAV (matches home) -->
  <div class="topbar">
    <div class="topbar-logo">Solis Merch</div>
    <div class="topbar-nav">
      <a href="home.php">Home</a>
      <a href="cart.php">🛒 Cart<?php if($cartCount > 0): ?><span class="cart-badge"><?= $cartCount > 9 ? '9+' : $cartCount ?></span><?php endif; ?></a>
      <a href="orders.php">My Orders</a>
      <a href="profile.php">Profile</a>
      <a href="#" class="danger" onclick="openLogoutModal(); return false;">Logout</a>
    </div>
  </div>

  <!-- Breadcrumb -->
  <div class="breadcrumb">
    <a href="home.php">Home</a>
    <span class="sep">›</span>
    <span><?= htmlspecialchars($product['category'] ?? 'Products') ?></span>
    <span class="sep">›</span>
    <span class="current"><?= htmlspecialchars($product['item_name']) ?></span>
  </div>

  <div class="page">

    <!-- LEFT -->
    <div class="panel panel-left">
      <div class="product-img-main">
        <?php if($defaultImg): ?>
          <img id="mainImg" src="<?= htmlspecialchars($defaultImg) ?>" alt="<?= htmlspecialchars($product['item_name']) ?>">
        <?php else: ?>
          <div class="no-img-ph">👕</div>
        <?php endif; ?>
        <span class="side-tag" id="sideTag"><?= ucfirst($defaultSide) ?></span>
        <span class="stock-pill <?= $badgeClass ?>" id="stockBadge"><?= $badgeText ?></span>
      </div>
      <div class="thumb-rail">
        <?php
        $sides = ['profile'=>['🙂','Profile'],'front'=>['⬆','Front'],'back'=>['⬇','Back'],'left'=>['◀','Left'],'right'=>['▶','Right']];
        $firstActive = null;
        foreach($sides as $sk=>$_) if(!empty($sideImages[$sk]) && $firstActive===null) $firstActive=$sk;
        foreach($sides as $sk=>[$icon,$lbl]):
          $hasImg = !empty($sideImages[$sk]);
          $isActive = $sk === ($firstActive ?? 'profile');
        ?>
          <div class="thumb <?= $isActive?'active':'' ?> <?= !$hasImg?'dim':'' ?>"
               id="thumb_<?= $sk ?>"
               onclick="<?= $hasImg?"switchSide('$sk','".htmlspecialchars(addslashes($sideImages[$sk]))."','$lbl')":'' ?>">
            <?php if($hasImg): ?>
              <img src="<?= htmlspecialchars($sideImages[$sk]) ?>" alt="<?= $lbl ?>">
            <?php else: ?>
              <div class="thumb-ph"><span class="thumb-ph-icon"><?= $icon ?></span><?= $lbl ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="panel-body">
        <div class="prod-category"><?= htmlspecialchars($product['category'] ?? 'Merchandise') ?></div>
        <h1 class="prod-name"><?= htmlspecialchars($product['item_name']) ?></h1>
        <div class="prod-price">₱<?= number_format($product['price'],2) ?></div>
        <?php if(!empty($product['description'])): ?>
          <div class="prod-desc"><?= nl2br(htmlspecialchars($product['description'])) ?></div>
        <?php endif; ?>
      </div>
      <?php if(!empty($reviews)): ?>
      <div class="prod-rating-row">
        <div class="stars">
          <?php for($i=1;$i<=5;$i++): ?><span class="star <?= $i<=$avgRating?'filled':'' ?>">★</span><?php endfor; ?>
        </div>
        <span style="font-weight:800;color:var(--c-text)"><?= $avgRating ?></span>
        <span>(<?= count($reviews) ?> review<?= count($reviews)!==1?'s':'' ?>)</span>
      </div>
      <?php endif; ?>
    </div>

    <!-- CENTER: Design Studio -->
    <div class="panel panel-center">
      <div class="panel-head">
        <span style="font-size:1.15rem">🎨</span>
        <span class="panel-head-title">Design Studio</span>
        <span style="margin-left:auto;font-size:.74rem;color:var(--c-muted);font-weight:600;">Upload designs for all 4 sides</span>
      </div>

      <?php foreach(['front','back','left','right'] as $sk): ?>
        <input type="file" id="ds_file_<?= $sk ?>" accept="image/*" style="display:none">
      <?php endforeach; ?>

      <div class="ds-tabs">
        <?php foreach(['front'=>['⬆','Front'],'back'=>['⬇','Back'],'left'=>['◀','Left'],'right'=>['▶','Right']] as $sk=>[$icon,$lbl]): ?>
          <button type="button" class="ds-tab <?= $sk==='front'?'active':'' ?>" id="dstab_<?= $sk ?>" onclick="dsSwitchTab('<?= $sk ?>')">
            <span class="ds-tab-icon"><?= $icon ?></span>
            <span class="ds-tab-label"><?= $lbl ?></span>
            <span class="ds-dot" id="dsdot_<?= $sk ?>"></span>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="ds-canvas-area" id="dsCanvasArea">
        <div id="ds_canvas_container">
          <canvas id="ds_product_canvas"></canvas>
          <div id="ds_mask_window" style="display:none">
            <div class="ds-mask-line"></div>
            <div id="ds_image_wrapper">
              <img id="ds_design_img" src="" alt="Design" style="display:block;width:100%;height:100%;object-fit:contain;">
              <div class="ds-resize-handle" id="ds_resize_handle" title="Resize"></div>
              <div class="ds-rotate-handle" id="ds_rotate_handle" title="Rotate"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="ds-toolbar">
        <button type="button" class="ds-btn ds-btn-primary" onclick="dsTriggerUpload()">📎 Upload Design</button>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-danger" id="dsClearBtn" style="display:none" onclick="dsClearSide()">🗑 Clear</button>
        <button type="button" class="ds-btn ds-btn-ghost" onclick="dsMarkNA()">✕ No design</button>
        <div class="ds-spacer"></div>
        <div class="ds-status">
          <span class="ds-status-dot" id="dsStatusDot"></span>
          <span id="dsStatusText">Upload or mark N/A</span>
        </div>
      </div>

      <div class="ds-summary" id="dsSummary">
        <?php foreach(['front'=>'⬆ Front','back'=>'⬇ Back','left'=>'◀ Left','right'=>'▶ Right'] as $sk=>$sl): ?>
          <div class="ds-chip" id="dssum_<?= $sk ?>">
            <span class="ds-chip-dot"></span><?= $sl ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ds-tip">
        💡 <strong>Drag</strong> to move · <strong>Corner handle</strong> to resize · <strong>Top knob</strong> to rotate · <strong>"No design"</strong> if this side is blank
      </div>
    </div>

    <!-- RIGHT: Order Form -->
    <div class="panel panel-right">
      <div class="panel-head">
        <span style="font-size:1.05rem">🛍️</span>
        <span class="panel-head-title">Place Your Order</span>
      </div>

      <!-- NEW — remove onsubmit -->
      <form id="orderForm" method="POST" action="cart.php" enctype="multipart/form-data">
        <input type="hidden" name="item_id"    value="<?= $product['id'] ?>">
        <input type="hidden" name="unit_price" value="<?= $product['price'] ?>">
        <input type="hidden" name="add_to_cart" value="1">
        <input type="hidden" name="color" id="hiddenColor" value="">
        <input type="hidden" name="size"  id="hiddenSize"  value="">
        <input type="hidden" name="design_front"    id="b64_front">
        <input type="hidden" name="design_back"     id="b64_back">
        <input type="hidden" name="design_left"     id="b64_left">
        <input type="hidden" name="design_right"    id="b64_right">
        <input type="hidden" name="design_front_na" id="na_flag_front"  value="0">
        <input type="hidden" name="design_back_na"  id="na_flag_back"   value="0">
        <input type="hidden" name="design_left_na"  id="na_flag_left"   value="0">
        <input type="hidden" name="design_right_na" id="na_flag_right"  value="0">

        <div class="form-section">
          <div class="sec-label">
            <span class="sec-num" id="stepNum1">1</span>
            Color <em class="req">*</em>
          </div>
          <div class="color-grid" id="colorGrid">
            <?php if(!empty($variantData)): foreach($variantData as $colorName=>$vd):
              $avail = $colorAvailability[$colorName] ?? false;
            ?>
              <div class="color-card <?= $avail ? 'avail' : 'oos' ?>"
                   id="color_<?= htmlspecialchars($colorName) ?>"
                   data-color="<?= htmlspecialchars($colorName) ?>"
                   <?= $avail ? "onclick=\"pickColor('".htmlspecialchars(addslashes($colorName))."')\"" : '' ?>>
                <div class="swatch" style="background:<?= colorToHex($colorName) ?>"></div>
                <div class="clabel"><?= htmlspecialchars($colorName) ?></div>
              </div>
            <?php endforeach; else: ?>
              <p style="font-size:.82rem;color:var(--c-muted);grid-column:1/-1">No colors available.</p>
            <?php endif; ?>
          </div>
        </div>

        <?php if(!empty($allSizeNames)): ?>
        <div class="form-section">
          <div class="sec-label">
            <span class="sec-num" id="stepNum2">2</span>
            Size <em class="req">*</em>
          </div>
          <div class="size-grid" id="sizeGrid">
            <?php foreach($allSizeNames as $sz): ?>
              <button type="button" class="size-btn" id="size_<?= htmlspecialchars($sz) ?>"
                      data-size="<?= htmlspecialchars($sz) ?>" disabled
                      onclick="pickSize('<?= htmlspecialchars(addslashes($sz)) ?>')">
                <?= htmlspecialchars($sz) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="size-hint" id="sizeHint">Pick a color first</div>
        </div>
        <?php endif; ?>

        <div class="design-progress">
          <div class="design-progress-label">Design Status</div>
          <div class="design-chips" id="designChipsMini">
            <?php foreach(['front'=>'Front','back'=>'Back','left'=>'Left','right'=>'Right'] as $sk=>$sl): ?>
              <div class="d-chip" id="dchip_<?= $sk ?>"><?= $sl ?></div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-section">
          <div class="sec-label">
            <span class="sec-num"><?= !empty($allSizeNames)?'3':'2' ?></span>
            Quantity
          </div>
          <div class="qty-row">
            <div class="qty-ctrl">
              <button type="button" class="qty-btn" onclick="stepQty(-1)">−</button>
              <input type="number" name="quantity" id="qtyNum" class="qty-num" value="1" min="1" max="<?= $product['quantity'] ?>" readonly>
              <button type="button" class="qty-btn" onclick="stepQty(1)">+</button>
            </div>
            <span class="qty-note">Max: <?= $product['quantity'] ?></span>
          </div>
        </div>

        <div class="form-section">
          <div class="sec-label">
            <span class="sec-num"><?= !empty($allSizeNames)?'4':'3' ?></span>
            Delivery Location <em class="req">*</em>
          </div>
          <input type="text" name="location" id="locationInput" class="loc-input"
                 placeholder="e.g., Bldg A Room 101 or Pick-up"
                 oninput="refreshAddBtn(); clearValMsg()">
        </div>

        <div class="val-msg" id="valMsg"></div>

        <div class="action-section">
          <button type="button" class="btn-order" id="orderBtn" disabled>💳 Order Now</button>
          <button type="button" class="btn-cart" id="addBtn" disabled onclick="handleAddToCart()">🛒 Add to Cart</button>
          <div class="sync-row">
            <span class="sync-dot"></span>
            Live inventory sync
          </div>
        </div>
      </form>

      <div class="modal-overlay" id="orderConfirmModal">
        <div class="modal">
          <button type="button" class="modal-close" id="confirmModalClose">×</button>
          <div class="modal-icon">💳</div>
          <h2>Confirm Order</h2>
          <p class="modal-sub">Review your details before placing.</p>
          <div class="modal-meta">
            <div class="modal-meta-row">
              <span class="modal-meta-label">Item</span>
              <span class="modal-meta-val" id="confirmProductName">—</span>
            </div>
            <div class="modal-meta-row">
              <span class="modal-meta-label">Variant</span>
              <span class="modal-meta-val" id="confirmVariant">—</span>
            </div>
            <div class="modal-meta-row">
              <span class="modal-meta-label">Qty</span>
              <span class="modal-meta-val" id="confirmProductQty">—</span>
            </div>
            <div class="modal-meta-row">
              <span class="modal-meta-label">Total</span>
              <span class="modal-meta-val price" id="confirmProductTotal">—</span>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" id="confirmCancelBtn">← Back</button>
            <button type="button" class="btn-confirm" id="confirmOrderBtn">Confirm ✓</button>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /page -->

  <!-- REVIEWS -->
  <section class="reviews-wrap">
    <div class="reviews-head">
      <h2 class="reviews-title">Customer Reviews</h2>
      <?php if(!empty($reviews)): ?>
        <div class="reviews-summary">
          <div class="stars">
            <?php for($i=1;$i<=5;$i++): ?><span class="star <?= $i<=$avgRating?'filled':'' ?>">★</span><?php endfor; ?>
          </div>
          <span style="font-weight:800;color:var(--c-text)"><?= $avgRating ?>/5</span>
          <span>(<?= count($reviews) ?> review<?= count($reviews)!==1?'s':'' ?>)</span>
        </div>
      <?php endif; ?>
    </div>
    <?php if(!empty($reviews)): ?>
      <div class="reviews-grid">
        <?php foreach($reviews as $r): ?>
          <div class="review-card">
            <div class="review-top">
              <div class="review-author"><?= htmlspecialchars($r['customer_name'] ?? 'Customer') ?></div>
              <div class="stars">
                <?php for($i=1;$i<=5;$i++): ?><span class="star <?= $i<=$r['rating']?'filled':'' ?>">★</span><?php endfor; ?>
              </div>
            </div>
            <?php if(!empty($r['comment'])): ?>
              <div class="review-comment"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>
            <?php endif; ?>
            <div class="review-date"><?= date('M j, Y', strtotime($r['created_at'])) ?></div>
            <?php if(!empty($r['admin_reply'])): ?>
              <div class="review-reply">
                <div class="review-reply-label">SolisCo. Reply</div>
                <?= nl2br(htmlspecialchars($r['admin_reply'])) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="no-reviews">
        <div class="no-reviews-icon">💬</div>
        <p style="font-family:var(--font-head);font-weight:800;font-size:1.05rem;margin-bottom:6px;color:var(--c-text)">No reviews yet</p>
        <p style="font-size:.86rem">Be the first to review after purchasing!</p>
      </div>
    <?php endif; ?>
  </section>

</div><!-- /cust-page -->

<!-- Floating cart -->
<a href="cart.php" class="float-cart">🛒 View Cart<?php if($cartCount > 0): ?> <span class="fc-badge"><?= $cartCount > 9 ? '9+' : $cartCount ?></span><?php endif; ?></a>

<!-- LOGOUT MODAL (matches home) -->
<div class="modal-overlay" id="logoutModal">
  <div class="modal">
    <div class="lo-icon">👋</div>
    <h3>Log Out?</h3>
    <p>Are you sure you want to log out of your account?</p>
    <div class="btn-row">
      <button class="btn-cancel" onclick="closeLogoutModal()">Cancel</button>
      <a href="../../logout.php" class="btn-logout-ok">Yes, Log Out</a>
    </div>
  </div>
</div>

<script>
const PRODUCT_ID         = <?= $product['id'] ?>;
const ORDER_PRODUCT_NAME = <?= json_encode($product['item_name']) ?>;
const ORDER_UNIT_PRICE   = <?= (float)$product['price'] ?>;
const MAX_QTY            = <?= $product['quantity'] ?>;
const ALL_SIZES          = <?= json_encode($allSizeNames) ?>;
const SIDE_IMAGES        = <?= json_encode($sideImages) ?>;
const HAS_NO_SIZES       = <?= $hasNoSizes ? 'true' : 'false' ?>;
let   VARIANTS           = <?= json_encode($variantData) ?>;
let selColor = null, selSize = null;

function showToast(msg, type='success') {
  const stack = document.getElementById('toastStack');
  const t = document.createElement('div');
  t.className = 'toast' + (type==='error'?' toast-error':'');
  t.innerHTML = `<div class="toast-icon">${type==='success'?'✓':'!'}</div><span>${msg}</span>`;
  stack.appendChild(t);
  setTimeout(()=>{ t.classList.add('hiding'); setTimeout(()=>t.remove(), 350); }, 3500);
}

function switchSide(side, src, label) {
  const main = document.getElementById('mainImg');
  if (main) { main.style.opacity='0'; setTimeout(()=>{ main.src=src; main.style.opacity='1'; }, 150); }
  document.getElementById('sideTag').innerText = label;
  document.querySelectorAll('.thumb').forEach(t=>t.classList.remove('active'));
  document.getElementById('thumb_'+side)?.classList.add('active');
}

function pickColor(c) {
  selColor = c;
  document.getElementById('hiddenColor').value = c;
  document.querySelectorAll('.color-card').forEach(el=>el.classList.remove('selected'));
  const card = document.getElementById('color_'+c);
  if (card) card.classList.add('selected');
  document.getElementById('stepNum1')?.classList.add('done');
  refreshSizes(); refreshAddBtn(); clearValMsg();
}
function refreshSizes() {
  if (!ALL_SIZES.length) return;
  const sizes = (VARIANTS[selColor]?.sizes) || {};
  let anyAvail = false;
  ALL_SIZES.forEach(sz => {
    const btn = document.getElementById('size_'+sz);
    if (!btn) return;
    btn.classList.remove('selected');
    const q = sizes[sz];
    if (q === undefined) { btn.disabled = true; btn.style.opacity='.25'; }
    else if (q <= 0)     { btn.disabled = true; btn.style.textDecoration='line-through'; btn.style.opacity='.35'; }
    else                 { btn.disabled = false; btn.style.opacity='1'; btn.style.textDecoration=''; anyAvail = true; }
  });
  document.getElementById('sizeHint').innerText = anyAvail ? 'Select a size' : 'No sizes available for this color';
  if (selSize && !sizes[selSize]) { selSize = null; document.getElementById('hiddenSize').value=''; }
}
function pickSize(s) {
  selSize = s;
  document.getElementById('hiddenSize').value = s;
  document.querySelectorAll('.size-btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById('size_'+s).classList.add('selected');
  document.getElementById('stepNum2')?.classList.add('done');
  refreshAddBtn(); clearValMsg();
}

function isDesignReady() {
  for (const side of ['front','back','left','right']) {
    if (window._dsSideData?.[side]?.na) continue;
    if (!window._dsSideData?.[side]?.design) return false;
  }
  return true;
}
function isFormReady() {
  const loc = document.getElementById('locationInput').value.trim();
  return selColor && (!ALL_SIZES.length || selSize) && loc.length > 0 && isDesignReady();
}
function refreshAddBtn() {
  const ready = isFormReady();
  document.getElementById('addBtn').disabled = !ready;
  document.getElementById('orderBtn').disabled = !ready;
}
function clearValMsg() {
  const v = document.getElementById('valMsg');
  v.classList.remove('show'); v.innerText='';
}
function showValMsg(msg) {
  const v = document.getElementById('valMsg');
  v.innerText=msg; v.classList.add('show');
}
function stepQty(d) {
  const i = document.getElementById('qtyNum');
  let v = parseInt(i.value)+d;
  if(v>=1 && v<=MAX_QTY) i.value=v;
}

function showOrderConfirm() {
  if (!isFormReady()) { showValMsg('Please complete all required fields.'); showToast('Complete all steps first','error'); return; }
  document.getElementById('confirmProductName').innerText = ORDER_PRODUCT_NAME;
  document.getElementById('confirmVariant').innerText = `${selColor}${selSize?' / '+selSize:''}`;
  const q = parseInt(document.getElementById('qtyNum').value)||1;
  document.getElementById('confirmProductQty').innerText = q;
  document.getElementById('confirmProductTotal').innerText = '₱'+(ORDER_UNIT_PRICE*q).toFixed(2);
  document.getElementById('orderConfirmModal').classList.add('show');
}
function hideOrderConfirm() { document.getElementById('orderConfirmModal').classList.remove('show'); }
async function confirmOrderNow() {
  for (const side of ['front','back','left','right']) {
    if (window._dsSideData?.[side]?.na) { document.getElementById('b64_'+side).value=''; document.getElementById('na_flag_'+side).value='1'; }
    else { const c=await compositeDesignForSide(side); document.getElementById('b64_'+side).value=c||''; document.getElementById('na_flag_'+side).value='0'; }
  }
  const fd = new FormData(document.getElementById('orderForm'));
  fd.append('ajax_order_now','1');
  try {
    const res = await fetch(window.location.href,{method:'POST',body:fd});
    const json = await res.json();
    if(json.success){ showToast('Order placed! 🎉'); setTimeout(()=>{ window.location.href='orders.php'; },900); }
    else showToast(json.message||'Order failed','error');
  } catch(err){ showToast('Network error','error'); }
  hideOrderConfirm();
}
document.getElementById('orderBtn').addEventListener('click', showOrderConfirm);
document.getElementById('confirmModalClose').addEventListener('click', hideOrderConfirm);
document.getElementById('confirmCancelBtn').addEventListener('click', hideOrderConfirm);
document.getElementById('confirmOrderBtn').addEventListener('click', confirmOrderNow);
document.getElementById('orderConfirmModal').addEventListener('click', e=>{ if(e.target.id==='orderConfirmModal') hideOrderConfirm(); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ hideOrderConfirm(); closeLogoutModal(); } });

/* Logout modal */
function openLogoutModal()  { document.getElementById('logoutModal').classList.add('open'); }
function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('open'); }
document.getElementById('logoutModal').addEventListener('click', e => {
  if (e.target.id === 'logoutModal') closeLogoutModal();
});

async function liveSync() {
  try {
    const res = await fetch(`?id=${PRODUCT_ID}&ajax=variants`);
    const data = await res.json();
    if(data?.colors){
      VARIANTS=data.colors;
      if(selColor) refreshSizes();
      const badge=document.getElementById('stockBadge');
      const qty=data.total_qty||0;
      if(qty<=0){badge.className='stock-pill badge-out';badge.innerText='Out of Stock';}
      else if(qty<10){badge.className='stock-pill badge-low';badge.innerText=`Only ${qty} left`;}
      else{badge.className='stock-pill badge-ok';badge.innerText='In Stock';}
      if (!HAS_NO_SIZES) {
        Object.keys(VARIANTS).forEach(colorName => {
          const card = document.querySelector(`.color-card[data-color="${CSS.escape(colorName)}"]`);
          if (!card) return;
          const sizes = VARIANTS[colorName]?.sizes || {};
          const totalStock = Object.values(sizes).reduce((a,b)=>a+b,0);
          const avail = totalStock > 0;
          if (avail) {
            card.classList.remove('oos'); card.classList.add('avail');
            card.onclick = () => pickColor(colorName); card.style.pointerEvents = '';
          } else {
            card.classList.add('oos'); card.classList.remove('avail', 'selected');
            card.onclick = null; card.style.pointerEvents = 'none';
            if (selColor === colorName) { selColor = null; document.getElementById('hiddenColor').value=''; refreshAddBtn(); }
          }
        });
      }
    }
  } catch(e){}
}
setTimeout(liveSync,1500); setInterval(liveSync,8000);

/* ══════════════════════════════════════════════════════
   DESIGN STUDIO logic (unchanged)
══════════════════════════════════════════════════════ */
let dsCurrentSide = 'front';
let dsBoundaries  = <?= json_encode($existingBoundaries ?: (object)[]) ?>;
let dsDesignImage = null;
let dsTransform   = { translateX:0,translateY:0,scale:1,rotate:0,baseWidth:0,baseHeight:0 };
let dsIsNA        = { front:false,back:false,left:false,right:false };
window._dsSideData = {};

function dsLoadProductImage(side) {
  const canvas  = document.getElementById('ds_product_canvas');
  const area    = document.getElementById('dsCanvasArea');
  const imgUrl  = SIDE_IMAGES[side];
  if (!canvas) return;

  if (!imgUrl) {
    canvas.width  = 600;
    canvas.height = 400;
    canvas.style.width  = '100%';
    canvas.style.height = 'auto';
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#f5f3ff'; ctx.fillRect(0, 0, 600, 400);
    ctx.fillStyle = '#a78bfa'; ctx.font = 'bold 15px DM Sans, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('No product image — upload one first', 300, 200);
    document.getElementById('ds_mask_window').style.display = 'none';
    return;
  }

  const img = new Image(); img.crossOrigin = 'Anonymous';
  img.onload = function () {
    requestAnimationFrame(() => {
      const panelW = area.clientWidth > 0 ? area.clientWidth : 700;
      const scaleW = panelW / img.naturalWidth;
      const scaleH = 600 / img.naturalHeight;
      const ratio  = Math.min(scaleW, scaleH);
      const w = Math.round(img.naturalWidth  * ratio);
      const h = Math.round(img.naturalHeight * ratio);
      canvas.width  = w;
      canvas.height = h;
      canvas.style.width  = '100%';
      canvas.style.height = 'auto';
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      dsApplyMaskWindow(side);
    });
  };
  img.src = imgUrl;
}

function dsApplyMaskWindow(side) {
  const canvas  = document.getElementById('ds_product_canvas');
  const maskDiv = document.getElementById('ds_mask_window');
  if (!canvas || !maskDiv) return;
  const b = dsBoundaries[side] || { x: 0.08, y: 0.08, w: 0.84, h: 0.84 };
  maskDiv.style.left   = (b.x * canvas.width)  + 'px';
  maskDiv.style.top    = (b.y * canvas.height) + 'px';
  maskDiv.style.width  = (b.w * canvas.width)  + 'px';
  maskDiv.style.height = (b.h * canvas.height) + 'px';
  maskDiv.style.display = 'block';
}

function dsTriggerUpload() {
  const fi = document.getElementById('ds_file_' + dsCurrentSide);
  fi.value = '';
  fi.onchange = e => { if (e.target.files?.[0]) dsLoadDesign(e.target.files[0]); };
  fi.click();
}

function dsLoadDesign(file) {
  if (!file.type.startsWith('image/')) { showToast('Please upload an image', 'error'); return; }
  const reader = new FileReader();
  reader.onload = ev => {
    const img = new Image();
    img.onload = function () {
      dsDesignImage = img;
      const mw = document.getElementById('ds_mask_window').clientWidth;
      const mh = document.getElementById('ds_mask_window').clientHeight;
      const fitScale = Math.min(mw / img.width, mh / img.height) * 0.92;
      dsTransform = {
        translateX: (mw - img.width  * fitScale) / 2,
        translateY: (mh - img.height * fitScale) / 2,
        scale: fitScale, rotate: 0,
        baseWidth: img.width, baseHeight: img.height
      };
      dsUpdateTransform(); dsUpdateUIForDesign();
    };
    img.src = ev.target.result;
  };
  reader.readAsDataURL(file);
}

function dsUpdateTransform() {
  const wrapper = document.getElementById('ds_image_wrapper');
  if (!wrapper) return;
  const s = dsTransform.scale;
  wrapper.style.width  = (dsTransform.baseWidth  * s) + 'px';
  wrapper.style.height = (dsTransform.baseHeight * s) + 'px';
  wrapper.style.transform = `translate(${dsTransform.translateX}px,${dsTransform.translateY}px) rotate(${dsTransform.rotate}deg)`;
  wrapper.style.display = 'block';
}

function dsUpdateUIForDesign() {
  document.getElementById('dsClearBtn').style.display = 'inline-flex';
  document.getElementById('dsStatusText').innerText = 'Design ready — drag, resize or rotate';
  document.getElementById('dsStatusDot').className = 'ds-status-dot ok';
  document.getElementById('ds_design_img').src = dsDesignImage.src;
  document.getElementById('ds_design_img').style.display = 'block';
  saveCurrentSideDesign(); dsUpdateSummaryDots(); refreshAddBtn();
}

function dsClearSide() {
  dsDesignImage = null;
  dsTransform = { translateX:0, translateY:0, scale:1, rotate:0, baseWidth:0, baseHeight:0 };
  document.getElementById('ds_design_img').src = '';
  document.getElementById('ds_design_img').style.display = 'none';
  document.getElementById('dsClearBtn').style.display = 'none';
  document.getElementById('dsStatusText').innerText = 'Upload or mark N/A';
  document.getElementById('dsStatusDot').className = 'ds-status-dot';
  dsIsNA[dsCurrentSide] = false;
  document.getElementById('na_flag_' + dsCurrentSide).value = '0';
  delete window._dsSideData[dsCurrentSide];
  dsUpdateSummaryDots(); refreshAddBtn();
}

function dsMarkNA() {
  dsClearSide(); dsIsNA[dsCurrentSide] = true;
  document.getElementById('na_flag_' + dsCurrentSide).value = '1';
  document.getElementById('dsStatusText').innerText = 'No design (N/A)';
  document.getElementById('dsStatusDot').className = 'ds-status-dot na';
  window._dsSideData[dsCurrentSide] = { design: null, na: true, transform: null };
  dsUpdateSummaryDots(); refreshAddBtn();
}

function dsUpdateSummaryDots() {
  ['front','back','left','right'].forEach(side => {
    const dot   = document.getElementById('dsdot_'  + side);
    const sum   = document.getElementById('dssum_'  + side);
    const dchip = document.getElementById('dchip_' + side);
    dot.classList.remove('filled','na-dot');
    sum.classList.remove('filled','na');
    dchip?.classList.remove('ok','na-c');
    const data = window._dsSideData[side];
    if (data?.na)     { dot.classList.add('na-dot'); sum.classList.add('na');     dchip?.classList.add('na-c'); }
    else if (data?.design) { dot.classList.add('filled');  sum.classList.add('filled'); dchip?.classList.add('ok');   }
  });
}

function saveCurrentSideDesign() {
  window._dsSideData[dsCurrentSide] = {
    design: dsDesignImage, na: dsIsNA[dsCurrentSide],
    transform: dsTransform ? { ...dsTransform } : null
  };
}

function dsSwitchTab(side) {
  saveCurrentSideDesign(); dsCurrentSide = side;
  document.querySelectorAll('.ds-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('dstab_' + side).classList.add('active');
  dsLoadProductImage(side);
  setTimeout(() => {
    const data = window._dsSideData[side];
    if (data?.na) {
      dsIsNA[side] = true;
      document.getElementById('na_flag_' + side).value = '1';
      document.getElementById('ds_design_img').style.display = 'none';
      document.getElementById('dsClearBtn').style.display = 'none';
      document.getElementById('dsStatusText').innerText = 'No design (N/A)';
      document.getElementById('dsStatusDot').className = 'ds-status-dot na';
    } else if (data?.design) {
      dsDesignImage = data.design;
      dsTransform = data.transform || dsTransform;
      dsUpdateTransform(); dsUpdateUIForDesign();
    } else {
      dsClearSide();
    }
    dsUpdateSummaryDots();
  }, 300);
}
window.dsSwitchTab = dsSwitchTab;

function dsClamp() {
  const m = document.getElementById('ds_mask_window'); if (!m) return;
  const mw = m.clientWidth, mh = m.clientHeight;
  const iw = dsTransform.baseWidth * dsTransform.scale;
  const ih = dsTransform.baseHeight * dsTransform.scale;
  dsTransform.translateX = Math.min(mw * .8, Math.max(-iw + mw * .2, dsTransform.translateX));
  dsTransform.translateY = Math.min(mh * .8, Math.max(-ih + mh * .2, dsTransform.translateY));
  dsUpdateTransform();
}

function attachDragEvents() {
  const wrapper = document.getElementById('ds_image_wrapper');
  const maskDiv = document.getElementById('ds_mask_window');
  const resizeH = document.getElementById('ds_resize_handle');
  const rotateH = document.getElementById('ds_rotate_handle');
  if (!wrapper || !maskDiv) return;
  let isDragging=false, isResizing=false, isRotating=false;
  let startX, startY, startTX, startTY, startScale, startMX, startMY, startRotate, startAngle;

  function onDown(e, type) {
    e.preventDefault(); e.stopPropagation();
    if (type==='drag')   { isDragging=true;  startX=e.clientX; startY=e.clientY; startTX=dsTransform.translateX; startTY=dsTransform.translateY; }
    else if (type==='resize') { isResizing=true; startMX=e.clientX; startMY=e.clientY; startScale=dsTransform.scale; }
    else if (type==='rotate') {
      isRotating=true;
      const r=maskDiv.getBoundingClientRect();
      const cx=r.left+r.width/2, cy=r.top+r.height/2;
      startAngle=Math.atan2(e.clientY-cy, e.clientX-cx)*180/Math.PI;
      startRotate=dsTransform.rotate;
    }
  }
  wrapper.addEventListener('mousedown', e => onDown(e,'drag'));
  wrapper.addEventListener('touchstart', e => { const t=e.touches[0]; onDown({clientX:t.clientX,clientY:t.clientY,preventDefault:()=>e.preventDefault(),stopPropagation:()=>e.stopPropagation()},'drag'); }, {passive:false});
  resizeH.addEventListener('mousedown', e => onDown(e,'resize'));
  resizeH.addEventListener('touchstart', e => { const t=e.touches[0]; onDown({clientX:t.clientX,clientY:t.clientY,preventDefault:()=>e.preventDefault(),stopPropagation:()=>e.stopPropagation()},'resize'); }, {passive:false});
  rotateH.addEventListener('mousedown', e => onDown(e,'rotate'));
  rotateH.addEventListener('touchstart', e => { const t=e.touches[0]; onDown({clientX:t.clientX,clientY:t.clientY,preventDefault:()=>e.preventDefault(),stopPropagation:()=>e.stopPropagation()},'rotate'); }, {passive:false});

  function handleMove(cx, cy) {
    if (isDragging)  { dsTransform.translateX=startTX+(cx-startX); dsTransform.translateY=startTY+(cy-startY); dsClamp(); }
    if (isResizing)  { const d=(cx-startMX)+(cy-startMY); dsTransform.scale=Math.min(4,Math.max(.15,startScale+d*.005)); dsClamp(); }
    if (isRotating)  { const r=maskDiv.getBoundingClientRect(); const cx2=r.left+r.width/2,cy2=r.top+r.height/2; dsTransform.rotate=startRotate+(Math.atan2(cy-cy2,cx-cx2)*180/Math.PI-startAngle); dsUpdateTransform(); }
  }
  window.addEventListener('mousemove', e => handleMove(e.clientX, e.clientY));
  window.addEventListener('touchmove', e => { if(isDragging||isResizing||isRotating){ const t=e.touches[0]; handleMove(t.clientX,t.clientY); e.preventDefault(); } }, {passive:false});
  function endMove() { if(isDragging||isResizing||isRotating) saveCurrentSideDesign(); isDragging=isResizing=isRotating=false; }
  window.addEventListener('mouseup', endMove);
  window.addEventListener('touchend', endMove);
}


async function compositeDesignForSide(side) {
    const data = window._dsSideData[side];
    if (!data?.design) return null;

    const productImgSrc = SIDE_IMAGES[side];

    // Create canvas at a fixed consistent size
    const tmp = document.createElement('canvas');
    tmp.width  = 600;
    tmp.height = 600;
    const ctx = tmp.getContext('2d');

    // Step 1: Draw product background
    if (productImgSrc) {
        await new Promise(res => {
            const im = new Image();
            // No crossOrigin needed — it's a base64 data URL
            im.onload  = () => {
                // Draw product image scaled to fit the canvas
                const scale = Math.min(tmp.width / im.naturalWidth, tmp.height / im.naturalHeight);
                const w = im.naturalWidth  * scale;
                const h = im.naturalHeight * scale;
                const x = (tmp.width  - w) / 2;
                const y = (tmp.height - h) / 2;
                ctx.drawImage(im, x, y, w, h);
                res();
            };
            im.onerror = () => {
                // Fallback: fill with light background
                ctx.fillStyle = '#f5f3ff';
                ctx.fillRect(0, 0, tmp.width, tmp.height);
                res();
            };
            im.src = productImgSrc;
        });
    } else {
        ctx.fillStyle = '#f5f3ff';
        ctx.fillRect(0, 0, tmp.width, tmp.height);
    }

    // Step 2: Draw design on top using same boundary ratios
    const b = dsBoundaries[side] || { x: 0.08, y: 0.08, w: 0.84, h: 0.84 };
    const bx = b.x * tmp.width;
    const by = b.y * tmp.height;
    const bw = b.w * tmp.width;
    const bh = b.h * tmp.height;

    const tr = data.transform;
    const iw = tr.baseWidth  * tr.scale;
    const ih = tr.baseHeight * tr.scale;

    ctx.save();
    ctx.beginPath();
    ctx.rect(bx, by, bw, bh);
    ctx.clip();
    ctx.translate(bx + tr.translateX + iw / 2, by + tr.translateY + ih / 2);
    ctx.rotate(tr.rotate * Math.PI / 180);
    ctx.drawImage(data.design, -iw / 2, -ih / 2, iw, ih);
    ctx.restore();

    return tmp.toDataURL('image/png');
}

/* ============================================================

TEMPORARY composite function to generate final design image for order submission.
async function compositeDesignForSide(side) {
  const productCanvas = document.getElementById('ds_product_canvas');
  if (!productCanvas) return null;
  const data = window._dsSideData[side];
  if (!data?.design) return null;
  const tmp = document.createElement('canvas');
  tmp.width  = productCanvas.width;
  tmp.height = productCanvas.height;
  const ctx = tmp.getContext('2d');
  await new Promise(res => {
    const im = new Image();
    im.onload  = () => { ctx.drawImage(im, 0, 0, tmp.width, tmp.height); res(); };
    im.onerror = res;
    im.src = SIDE_IMAGES[side] || productCanvas.toDataURL();
  });
  const b = dsBoundaries[side] || { x: 0.08, y: 0.08, w: 0.84, h: 0.84 };
  const bx = b.x * tmp.width,  by = b.y * tmp.height;
  const bw = b.w * tmp.width,  bh = b.h * tmp.height;
  ctx.save();
  ctx.beginPath(); ctx.rect(bx, by, bw, bh); ctx.clip();
  const tr = data.transform;
  const iw = tr.baseWidth * tr.scale, ih = tr.baseHeight * tr.scale;
  ctx.translate(bx + tr.translateX + iw / 2, by + tr.translateY + ih / 2);
  ctx.rotate(tr.rotate * Math.PI / 180);
  ctx.drawImage(data.design, -iw / 2, -ih / 2, iw, ih);
  ctx.restore();
  return tmp.toDataURL('image/jpeg', .9);
}

   ============================================================ */

async function validateOrder(e) {
  if (!isFormReady()) {
    e.preventDefault();
    const missing = [];
    if (!selColor) missing.push('color');
    if (ALL_SIZES.length && !selSize) missing.push('size');
    if (!document.getElementById('locationInput').value.trim()) missing.push('delivery location');
    if (!isDesignReady()) missing.push('design for all sides');
    showValMsg('Missing: ' + missing.join(', '));
    showToast('Complete all steps first', 'error');
    return false;
  }
  e.preventDefault();
  for (const side of ['front','back','left','right']) {
    if (window._dsSideData?.[side]?.na) {
      document.getElementById('b64_' + side).value = '';
      document.getElementById('na_flag_' + side).value = '1';
    } else {
      const c = await compositeDesignForSide(side);
      document.getElementById('b64_' + side).value = c || '';
      document.getElementById('na_flag_' + side).value = '0';
    }
  }
  document.getElementById('orderForm').submit();
  return true;
}
window.validateOrder = validateOrder;

requestAnimationFrame(() => { dsLoadProductImage('front'); });
attachDragEvents();
window.addEventListener('resize', () => { dsLoadProductImage(dsCurrentSide); });
refreshAddBtn();


async function handleAddToCart() {
    if (!isFormReady()) {
        const missing = [];
        if (!selColor) missing.push('color');
        if (ALL_SIZES.length && !selSize) missing.push('size');
        if (!document.getElementById('locationInput').value.trim()) missing.push('delivery location');
        if (!isDesignReady()) missing.push('design for all sides');
        showValMsg('Missing: ' + missing.join(', '));
        showToast('Complete all steps first', 'error');
        return;
    }

    // Encode design images into hidden fields
    for (const side of ['front', 'back', 'left', 'right']) {
        if (window._dsSideData?.[side]?.na) {
            document.getElementById('b64_' + side).value = '';
            document.getElementById('na_flag_' + side).value = '1';
        } else {
            const c = await compositeDesignForSide(side);
            document.getElementById('b64_' + side).value = c || '';
            document.getElementById('na_flag_' + side).value = '0';
        }
    }

    document.getElementById('orderForm').submit();
}

</script>
</body>
</html>
