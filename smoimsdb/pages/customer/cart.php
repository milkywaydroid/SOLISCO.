<?php
/* ============================================================
   SMOIMS — Customer Cart  (UI aligned to home.php)
   FILE: pages/customer/cart.php
   ============================================================ */

require_once '../../includes/config.php';
requireCustomerLogin();

/* ── Add to Cart (from ordering_item.php) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $customerId = (int)$_SESSION['customer_id'];
    $itemId     = (int)($_POST['item_id']    ?? 0);
    $unitPrice  = (float)($_POST['unit_price'] ?? 0);
    $quantity   = max(1, (int)($_POST['quantity'] ?? 1));
    $color      = trim($_POST['color']    ?? '');
    $size       = trim($_POST['size']     ?? '');
    $location   = trim($_POST['location'] ?? '');

    // Decode base64 design images back to binary for storage
    function parseDesignForCart(?string $value): ?string {
      $value = trim($value ?? '');
      if ($value === '') return null;
      // Already a data URL — extract binary
      if (str_starts_with($value, 'data:image/') && preg_match('#^data:image/[^;]+;base64,(.+)$#s', $value, $m)) {
          return base64_decode($m[1]);
      }
      return null;
    }

    $designFront = ($_POST['design_front_na'] ?? '0') === '1' ? null : parseDesignForCart($_POST['design_front'] ?? null);
    $designBack  = ($_POST['design_back_na']  ?? '0') === '1' ? null : parseDesignForCart($_POST['design_back']  ?? null);
    $designLeft  = ($_POST['design_left_na']  ?? '0') === '1' ? null : parseDesignForCart($_POST['design_left']  ?? null);
    $designRight = ($_POST['design_right_na'] ?? '0') === '1' ? null : parseDesignForCart($_POST['design_right'] ?? null);

    if ($itemId && $color && $unitPrice > 0) {
        $stmt = $conn->prepare(
            "INSERT INTO cart (customer_id, item_id, quantity, unit_price, color, size, design_front, design_back, design_left, design_right, location)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iiiidssssss",
          $customerId, $itemId, $quantity, $unitPrice,
          $color, $size,
          $designFront, $designBack, $designLeft, $designRight,
          $location
        );
        // Fix bind_param string — no space above, that was for readability:
        $stmt->execute();
        $stmt->close();
        header("Location: cart.php?added=1");
        exit;
    } else {
        header("Location: cart.php?error=missing_fields");
        exit;
    }
}

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

function dbImageToDataUrl(?string $blob): ?string
{
    if (empty($blob)) return null;
    if (str_starts_with($blob, 'data:image/')) return $blob;

    // Detect PNG vs JPEG by magic bytes
    $isPng = (substr($blob, 0, 4) === "\x89PNG");
    $mime  = $isPng ? 'image/png' : 'image/jpeg';

    return 'data:' . $mime . ';base64,' . base64_encode($blob);
}

$message    = '';
$customerId = $_SESSION['customer_id'];

/* ── Remove single item ── */
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    $conn->query("DELETE FROM cart WHERE id = $removeId AND customer_id = $customerId");
    header("Location: cart.php?removed=1"); exit;
}

/* ── Remove multiple selected items ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    if (!empty($ids)) {
        $idList = implode(',', $ids);
        $conn->query("DELETE FROM cart WHERE id IN ($idList) AND customer_id = $customerId");
    }
    header("Location: cart.php"); exit;
}

/* ── Place Order from Cart ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $ids = array_map('intval', $_POST['selected_ids'] ?? []);
    if (!empty($ids)) {
        $idList = implode(',', $ids);
        $cartRes = $conn->query(
            "SELECT c.*, i.item_name FROM cart c
             JOIN inventory i ON c.item_id = i.id
             WHERE c.id IN ($idList) AND c.customer_id = $customerId"
        );
        if ($cartRes && $cartRes->num_rows > 0) {
            $dbItems = []; $total = 0;
            while ($row = $cartRes->fetch_assoc()) {
                $dbItems[] = $row;
                $total += ($row['quantity'] * $row['unit_price']);
            }
            $conn->query(
                "INSERT INTO orders (customer_id, total_amount, status, created_at)
                 VALUES ($customerId, $total, 'Pending', NOW())"
            );
            $orderId = $conn->insert_id;
            foreach ($dbItems as $item) {
                $iid=$item['item_id']; $iqty=$item['quantity']; $iprice=$item['unit_price'];
                $icolor=$item['color']; $isize=$item['size'];
                $dfront=$item['design_front']; $dback=$item['design_back'];
                $dleft=$item['design_left']; $dright=$item['design_right'];
                $stmt2 = $conn->prepare(
                    "INSERT INTO order_items
                        (order_id, item_id, quantity, unit_price, color, size, design_front, design_back, design_left, design_right)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt2->bind_param("iiidssssss", $orderId, $iid, $iqty, $iprice, $icolor, $isize, $dfront, $dback, $dleft, $dright);
                $stmt2->execute(); $stmt2->close();
            }
            $conn->query("DELETE FROM cart WHERE id IN ($idList) AND customer_id = $customerId");
            header("Location: orders.php?success=$orderId"); exit;
        }
    }
}

/* ── Fetch cart items ── */
$cartItems = []; $grandTotal = 0;
$displayRes = $conn->query(
    "SELECT c.*, i.item_name, i.profile_image, i.category, i.quantity as stock_qty
     FROM cart c
     JOIN inventory i ON c.item_id = i.id
     WHERE c.customer_id = $customerId
     ORDER BY c.created_at DESC"
);
if ($displayRes) {
    while ($row = $displayRes->fetch_assoc()) {
        $row['subtotal'] = $row['quantity'] * $row['unit_price'];
        $grandTotal += $row['subtotal'];
        $cartItems[] = $row;
    }
}

function colorToHex(string $name): string {
    $map=['white'=>'#f8fafc','black'=>'#1a1a2e','red'=>'#ef4444','blue'=>'#3b82f6','green'=>'#22c55e',
          'yellow'=>'#eab308','purple'=>'#a855f7','pink'=>'#ec4899','orange'=>'#f97316','gray'=>'#9ca3af',
          'grey'=>'#9ca3af','brown'=>'#92400e','beige'=>'#d9c8a9','coral'=>'#f87171','navy'=>'#1e3a5f',
          'teal'=>'#14b8a6','cream'=>'#fef3c7','lavender'=>'#c4b5fd','maroon'=>'#9f1239','sky'=>'#38bdf8',
          'light blue'=>'#bae6fd','violet'=>'#7c3aed'];
    $lower = strtolower(trim($name));
    foreach($map as $k=>$h) if(str_contains($lower,$k)) return $h;
    return '#94a3b8';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Cart | SolisCo.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../css/style.css">
<style>
/* ══════════════════════════════════════════════
   TOKENS — exact match to home.php
══════════════════════════════════════════════ */
:root {
  --c-bg: #f5f3ff;
  --c-surface: #ffffff;
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
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--font-body);
  background: var(--c-bg);
  color: var(--c-text);
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
}

/* Animated background blobs (same as home) */
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

/* Page wrap */
.cust-page { max-width: 1180px; margin: 0 auto; padding: 24px 24px 120px; position: relative; z-index: 1; }

/* ── Topbar (glass) — same as home ── */
.topbar {
  display: flex; justify-content: space-between; align-items: center;
  padding: 14px 22px; margin-bottom: 28px;
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

/* ── Page heading ── */
.page-head {
  margin-bottom: 22px;
  animation: fadeUp .7s cubic-bezier(.22,1,.36,1) both;
}
.page-head h1 {
  font-family: var(--font-display);
  font-size: 2.4rem; font-weight: 800; line-height: 1.1;
  color: var(--c-text);
  display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
}
.page-head h1 .grad {
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
  font-style: italic;
}
.count-pill {
  font-family: var(--font-head); font-size: .8rem; font-weight: 700;
  padding: 6px 14px; border-radius: 99px;
  background: rgba(255,255,255,.7);
  border: 1.5px solid var(--c-border);
  color: var(--c-accent-deep);
  backdrop-filter: blur(8px);
}
.page-head p { color: var(--c-muted); margin-top: 8px; font-size: .98rem; max-width: 640px; }

/* ── Layout ── */
.page-wrap {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 26px;
  align-items: start;
  animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .15s both;
}

/* ── Select-all bar ── */
.select-bar {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px; margin-bottom: 16px;
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(12px);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: 16px;
  box-shadow: var(--shadow-card);
}
.select-bar label {
  display: flex; align-items: center; gap: 10px;
  font-family: var(--font-head); font-weight: 700; font-size: .92rem;
  cursor: pointer; user-select: none; color: var(--c-text);
}
.select-spacer { flex: 1; }
.sel-count {
  font-size: .82rem; font-weight: 700; color: var(--c-accent);
  padding: 4px 12px; border-radius: 99px; background: var(--c-accent-light);
}
.btn-delete-sel {
  padding: 9px 16px; border-radius: 10px; border: 1.5px solid #fecaca;
  background: var(--c-err-bg); color: var(--c-err);
  font-family: var(--font-head); font-weight: 700; font-size: .82rem;
  cursor: pointer; transition: all .25s; display: none;
}
.btn-delete-sel.visible { display: inline-flex; }
.btn-delete-sel:hover { background: var(--c-err); color: #fff; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220,38,38,.3); }

/* Custom checkbox */
.check-custom {
  width: 22px; height: 22px; border-radius: 7px;
  border: 2px solid var(--c-border-strong); background: #fff;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all .2s; cursor: pointer; flex-shrink: 0;
}
.check-custom.checked {
  background: var(--grad-primary); border-color: transparent;
  box-shadow: 0 4px 12px rgba(124,58,237,.4);
}
.check-custom.checked::after {
  content: '✓'; color: #fff; font-weight: 800; font-size: .95rem;
}

/* ── Cart list ── */
.cart-list { display: flex; flex-direction: column; gap: 16px; }

.cart-card {
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(12px);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: var(--r-card);
  padding: 20px;
  box-shadow: var(--shadow-card);
  transition: transform .35s cubic-bezier(.22,1,.36,1), box-shadow .35s, border-color .25s;
  position: relative;
}
.cart-card::before {
  content: ''; position: absolute; inset: 0; border-radius: var(--r-card);
  padding: 1.5px; background: var(--grad-primary);
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor; mask-composite: exclude;
  opacity: 0; transition: opacity .3s; pointer-events: none;
}
.cart-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
.cart-card.selected::before { opacity: 1; }
.cart-card.selected { box-shadow: var(--shadow-hover); }

.card-top {
  display: grid;
  grid-template-columns: auto 110px 1fr auto;
  gap: 18px; align-items: center;
}
.card-check { display: flex; align-items: center; justify-content: center; cursor: pointer; }

.product-thumb {
  width: 110px; height: 110px;
  border-radius: 14px; overflow: hidden;
  background: linear-gradient(135deg, #ede9fe, #fce7f3);
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid rgba(255,255,255,.8);
}
.product-thumb img { width: 100%; height: 100%; object-fit: cover; }
.thumb-ph { font-size: 2.6rem; opacity: .55; }

.product-info { min-width: 0; }
.product-category {
  font-size: .7rem; font-weight: 800; letter-spacing: 1px;
  color: var(--c-accent); text-transform: uppercase; margin-bottom: 4px;
}
.product-name {
  font-family: var(--font-head); font-weight: 700; font-size: 1.1rem;
  line-height: 1.3; color: var(--c-text); margin-bottom: 6px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.product-price {
  font-family: var(--font-display); font-size: 1.25rem; font-weight: 800;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
  margin-bottom: 8px;
}
.product-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.meta-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 11px; border-radius: 99px;
  background: var(--c-accent-light); border: 1px solid var(--c-border-strong);
  color: var(--c-accent-deep);
  font-size: .74rem; font-weight: 700;
}
.pill-swatch {
  width: 12px; height: 12px; border-radius: 50%;
  border: 1.5px solid #fff; box-shadow: 0 0 0 1px var(--c-border-strong);
}
.stock-badge {
  padding: 4px 11px; border-radius: 99px;
  font-size: .7rem; font-weight: 800; letter-spacing: .3px; text-transform: uppercase;
}
.badge-ok  { background: var(--c-ok-bg);   color: var(--c-ok); }
.badge-low { background: var(--c-warn-bg); color: var(--c-warn); }
.badge-out { background: var(--c-err-bg);  color: var(--c-err); }

.card-right {
  display: flex; flex-direction: column; align-items: flex-end; gap: 8px;
}
.subtotal-badge {
  font-family: var(--font-display); font-size: 1.4rem; font-weight: 800;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.qty-display {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .78rem; font-weight: 600; color: var(--c-muted);
}
.qty-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 30px; padding: 0 8px;
  border-radius: 99px;
  background: var(--c-accent-light);
  border: 1.5px solid var(--c-border-strong);
  color: var(--c-accent-deep);
  font-family: var(--font-head); font-weight: 800; font-size: .85rem;
}
.btn-remove-single {
  width: 32px; height: 32px; border-radius: 50%;
  border: 1.5px solid var(--c-border);
  background: #fff; color: var(--c-muted);
  cursor: pointer; transition: all .2s;
  font-size: .9rem; font-weight: 700;
}
.btn-remove-single:hover {
  background: var(--c-err); color: #fff; border-color: var(--c-err);
  transform: rotate(90deg);
}

/* Meta bar */
.card-meta-bar {
  display: flex; flex-wrap: wrap; gap: 16px;
  margin-top: 14px; padding-top: 14px;
  border-top: 1.5px dashed var(--c-border);
  font-size: .78rem; color: var(--c-muted); font-weight: 500;
}
.card-meta-bar .ico { margin-right: 4px; }

/* ── Design Section ── */
.design-section {
  margin-top: 14px; padding-top: 14px;
  border-top: 1.5px dashed var(--c-border);
}
.design-section-title {
  display: flex; align-items: center; gap: 8px;
  font-family: var(--font-head); font-weight: 700; font-size: .88rem;
  color: var(--c-text); margin-bottom: 10px;
}
.design-section-title .dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--grad-primary);
  box-shadow: 0 0 10px rgba(124,58,237,.6);
}
.design-buttons {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
}
.btn-view-design {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  padding: 12px 8px;
  background: rgba(255,255,255,.7);
  border: 1.5px solid var(--c-border);
  border-radius: 12px; cursor: pointer;
  transition: all .25s; color: var(--c-text);
  font-family: var(--font-head);
}
.btn-view-design.has-design:hover {
  border-color: var(--c-accent-mid);
  background: var(--c-accent-light);
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(124,58,237,.18);
}
.btn-view-design.no-design {
  opacity: .5; cursor: not-allowed; background: #f9fafb;
}
.bvd-icon { font-size: 1.1rem; color: var(--c-accent); }
.bvd-side { font-size: .82rem; font-weight: 700; }
.bvd-status {
  font-size: .68rem; font-weight: 600; color: var(--c-muted);
  letter-spacing: .3px; text-transform: uppercase;
}
.btn-view-design.has-design .bvd-status { color: var(--c-accent); }

/* Inline preview */
.inline-design-wrap {
  max-height: 0; overflow: hidden; opacity: 0;
  transition: max-height .45s cubic-bezier(.22,1,.36,1), opacity .35s, margin .3s;
}

/* BINAGO NA UI PARA MAGING CONSISTENT YUNG IMAGE*/

.inline-design-wrap.open {
  max-height: 560px; opacity: 1; margin-top: 14px;
}


.inline-design-card {
  background: var(--grad-hero);
  border: 1.5px solid var(--c-border-strong);
  border-radius: 16px; padding: 16px; text-align: center;
  min-height: 460px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}


.inline-design-card img {
  width: 100%;
  max-width: 560px;
  height: 420px;
  object-fit: contain;
  border-radius: 12px;
  background: #f5f3ff;
  box-shadow: 0 8px 24px rgba(109,40,217,.15);
  display: block;
  margin: 0 auto;
}


.inline-design-label {
  font-family: var(--font-head); font-weight: 700; font-size: .88rem;
  color: var(--c-accent-deep); margin-top: 10px; letter-spacing: .3px;
  text-transform: uppercase;
}
.inline-design-actions { margin-top: 10px; display: flex; gap: 8px; justify-content: center; }
.inline-action {
  padding: 8px 14px; border-radius: 10px;
  background: rgba(255,255,255,.85); border: 1.5px solid var(--c-border-strong);
  color: var(--c-accent-deep); font-family: var(--font-head); font-weight: 700; font-size: .8rem;
  cursor: pointer; transition: all .2s;
}
.inline-action:hover { background: #fff; color: var(--c-accent); transform: translateY(-1px); }

/* ── Order Summary (desktop) ── */
.summary-col { position: sticky; top: 100px; }
.summary-panel {
  background: rgba(255,255,255,.85);
  backdrop-filter: blur(14px);
  border: 1.5px solid rgba(255,255,255,.8);
  border-radius: var(--r-card);
  box-shadow: var(--shadow-card);
  overflow: hidden;
}
.summary-empty-state {
  padding: 50px 24px; text-align: center; color: var(--c-muted);
}
.summary-empty-icon { font-size: 3rem; margin-bottom: 12px; opacity: .55; }
.summary-empty-text { font-size: .92rem; line-height: 1.5; }
.summary-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1.5px solid var(--c-border);
}
.summary-head-title {
  font-family: var(--font-display); font-size: 1.3rem; font-weight: 800;
  color: var(--c-text);
}
.summary-count-pill {
  font-family: var(--font-head); font-size: .76rem; font-weight: 700;
  padding: 4px 12px; border-radius: 99px;
  background: var(--c-accent-light); color: var(--c-accent-deep);
}
.summary-body { padding: 18px 22px 22px; }
.summary-items-list {
  display: flex; flex-direction: column; gap: 10px;
  max-height: 240px; overflow-y: auto; padding-right: 4px;
}
.si-row {
  display: grid; grid-template-columns: 44px 1fr auto; gap: 10px; align-items: center;
  padding: 8px; border-radius: 12px; background: rgba(237,233,254,.4);
}
.si-img {
  width: 44px; height: 44px; border-radius: 10px; overflow: hidden;
  background: var(--grad-hero); display: flex; align-items: center; justify-content: center;
}
.si-img img { width: 100%; height: 100%; object-fit: cover; }
.si-info { min-width: 0; }
.si-name {
  font-family: var(--font-head); font-weight: 700; font-size: .82rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--c-text);
}
.si-variant { font-size: .7rem; color: var(--c-muted); margin-top: 2px; }
.si-price {
  font-family: var(--font-head); font-weight: 800; font-size: .82rem;
  color: var(--c-accent-deep);
}
.summary-divider { border: none; border-top: 1.5px dashed var(--c-border); margin: 14px 0; }
.summary-line {
  display: flex; justify-content: space-between; align-items: center;
  font-size: .9rem; color: var(--c-text); margin-bottom: 6px;
}
.summary-line.total { font-size: 1rem; font-weight: 700; }
.total-val {
  font-family: var(--font-display); font-size: 1.5rem; font-weight: 800;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.delivery-note {
  margin: 12px 0 16px; padding: 10px 12px;
  background: var(--c-accent-light); border: 1px dashed var(--c-border-strong);
  border-radius: 10px; font-size: .76rem; color: var(--c-accent-deep); text-align: center;
}
.btn-checkout {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; padding: 14px;
  background: var(--grad-primary); color: #fff;
  border: none; border-radius: var(--r-btn);
  font-family: var(--font-head); font-weight: 800; font-size: .98rem;
  cursor: pointer; transition: all .3s;
  box-shadow: 0 6px 18px rgba(124,58,237,.35);
  position: relative; overflow: hidden;
}
.btn-checkout::before {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(120deg, transparent, rgba(255,255,255,.4), transparent);
  transform: translateX(-100%); transition: transform .6s;
}
.btn-checkout:hover:not(:disabled) {
  transform: translateY(-2px); box-shadow: 0 10px 26px rgba(124,58,237,.5);
}
.btn-checkout:hover:not(:disabled)::before { transform: translateX(100%); }
.btn-checkout:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }

/* ── Empty state ── */
.empty-state {
  text-align: center; padding: 80px 24px;
  background: rgba(255,255,255,.6);
  border: 1.5px dashed var(--c-border-strong);
  border-radius: 24px;
}
.empty-icon { font-size: 4rem; margin-bottom: 14px; opacity: .55; }
.empty-state h2 {
  font-family: var(--font-display); font-size: 1.7rem;
  margin-bottom: 8px; color: var(--c-text);
}
.empty-state p { color: var(--c-muted); margin-bottom: 22px; line-height: 1.55; }
.btn-browse {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 28px; border-radius: 99px;
  background: var(--grad-primary); color: #fff;
  text-decoration: none; font-family: var(--font-head); font-weight: 700;
  box-shadow: 0 6px 18px rgba(124,58,237,.35); transition: all .25s;
}
.btn-browse:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(124,58,237,.5); }

/* ── Mobile bottom summary ── */
.mobile-summary {
  display: none;
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 90;
  background: rgba(255,255,255,.92);
  backdrop-filter: blur(20px) saturate(140%);
  -webkit-backdrop-filter: blur(20px) saturate(140%);
  border-top: 1.5px solid rgba(255,255,255,.9);
  box-shadow: 0 -8px 28px rgba(109,40,217,.12);
  transform: translateY(110%); transition: transform .35s cubic-bezier(.22,1,.36,1);
}
.mobile-summary.open { transform: translateY(0); }
.mobile-summary-inner { padding: 14px 18px 18px; }
.mobile-handle { display: flex; justify-content: center; margin-bottom: 8px; }
.mobile-handle-bar { width: 44px; height: 4px; border-radius: 99px; background: var(--c-border-strong); }
.mobile-summary-collapsed {
  display: flex; align-items: center; gap: 12px;
}
.mobile-sel-info { flex: 1; }
.mobile-sel-count { font-size: .78rem; color: var(--c-muted); font-weight: 600; }
.mobile-sel-total {
  font-family: var(--font-display); font-size: 1.4rem; font-weight: 800;
  background: var(--grad-primary);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.mobile-checkout-btn {
  padding: 13px 22px; border: none; border-radius: 99px;
  background: var(--grad-primary); color: #fff;
  font-family: var(--font-head); font-weight: 800; font-size: .9rem;
  cursor: pointer; box-shadow: 0 6px 18px rgba(124,58,237,.35);
  transition: all .2s;
}
.mobile-checkout-btn:disabled { opacity: .5; cursor: not-allowed; }
.mobile-checkout-btn:hover:not(:disabled) { transform: translateY(-2px); }

/* ── Lightbox ── */
.lightbox {
  position: fixed; inset: 0; z-index: 1100;
  background: rgba(30,27,75,.55);
  backdrop-filter: blur(8px);
  display: flex; align-items: center; justify-content: center; padding: 24px;
  opacity: 0; pointer-events: none; transition: opacity .25s;
}
.lightbox.open { opacity: 1; pointer-events: auto; }
.lightbox-inner {
  background: rgba(255,255,255,.95);
  backdrop-filter: blur(20px);
  border-radius: 22px; padding: 22px;
  max-width: 720px; width: 100%;
  border: 1.5px solid rgba(255,255,255,.8);
  box-shadow: 0 24px 60px rgba(30,27,75,.25);
  text-align: center;
}
.lightbox-title {
  font-family: var(--font-head); font-weight: 700; font-size: 1rem;
  color: var(--c-accent-deep); margin-bottom: 12px;
}
.lightbox-img {
  max-width: 100%; max-height: 70vh; border-radius: 14px;
  background: var(--grad-hero);
}
.lightbox-close {
  margin-top: 14px; padding: 10px 22px; border-radius: 10px;
  background: var(--grad-primary); color: #fff; border: none;
  font-family: var(--font-head); font-weight: 700; cursor: pointer;
  box-shadow: 0 6px 18px rgba(124,58,237,.35);
}
.lightbox-close:hover { transform: translateY(-1px); }

/* ── Modals (logout / delete) — same shape as home ── */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(30,27,75,.5);
  backdrop-filter: blur(8px);
  z-index: 1000; display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity .25s;
}
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal-box {
  background: rgba(255,255,255,.95);
  backdrop-filter: blur(20px);
  padding: 36px; border-radius: 24px; width: 100%; max-width: 400px; text-align: center;
  box-shadow: 0 24px 60px rgba(30,27,75,.25);
  transform: translateY(30px) scale(.95);
  transition: transform .35s cubic-bezier(.22,1,.36,1);
  border: 1.5px solid rgba(255,255,255,.8);
}
.modal-overlay.open .modal-box { transform: translateY(0) scale(1); }
.modal-icon { font-size: 3.5rem; margin-bottom: 14px; display: inline-block; }
.modal-box h3 { font-family: var(--font-display); font-size: 1.6rem; margin-bottom: 10px; color: var(--c-text); }
.modal-box p { color: var(--c-muted); margin-bottom: 26px; line-height: 1.5; }
.modal-actions { display: flex; gap: 12px; }
.modal-cancel {
  flex: 1; padding: 13px; border-radius: 12px; border: 1.5px solid var(--c-border);
  background: #fff; font-family: var(--font-head); font-weight: 700; cursor: pointer; transition: all .2s;
  color: var(--c-text);
}
.modal-cancel:hover { background: var(--c-bg); border-color: var(--c-border-strong); }
.modal-confirm-del {
  flex: 1; padding: 13px; border-radius: 12px;
  background: var(--c-err); color: #fff;
  border: none; font-family: var(--font-head); font-weight: 700; cursor: pointer; transition: all .2s;
}
.modal-confirm-del:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(220,38,38,.35); }
.modal-confirm-logout {
  flex: 1; padding: 13px; border-radius: 12px;
  background: var(--grad-primary); color: #fff; text-decoration: none;
  font-family: var(--font-head); font-weight: 700; transition: all .2s;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 18px rgba(124,58,237,.35);
}
.modal-confirm-logout:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(124,58,237,.5); }

/* ── Toasts ── */
.toast-stack {
  position: fixed; top: 92px; right: 24px; z-index: 1200;
  display: flex; flex-direction: column; gap: 10px;
}
.toast {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 18px; border-radius: 14px;
  background: rgba(255,255,255,.95);
  backdrop-filter: blur(14px);
  border: 1.5px solid rgba(255,255,255,.8);
  box-shadow: 0 10px 30px rgba(109,40,217,.15);
  font-family: var(--font-head); font-weight: 600; font-size: .88rem;
  color: var(--c-text);
  animation: toastIn .35s cubic-bezier(.22,1,.36,1) both;
}
.toast.out { animation: toastOut .35s ease both; }
@keyframes toastIn  { from { opacity:0; transform:translateX(40px); } to { opacity:1; transform:none; } }
@keyframes toastOut { to { opacity:0; transform:translateX(40px); } }
.toast-icon-wrap {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--grad-primary); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem; font-weight: 800;
}
.toast-icon-wrap.err { background: var(--c-err); }

/* Shared keyframes */
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
.cart-card { animation: cardIn .5s cubic-bezier(.22,1,.36,1) both; }
<?php foreach (array_keys($cartItems) as $i): ?>
.cart-list > .cart-card:nth-child(<?= $i+1 ?>) { animation-delay: <?= $i*.06 ?>s; }
<?php endforeach; ?>
@keyframes cardIn {
  from { opacity: 0; transform: translateY(20px) scale(.97); }
  to   { opacity: 1; transform: none; }
}

/* ── Responsive ── */
@media (max-width: 980px) {
  .page-wrap { grid-template-columns: 1fr; }
  .summary-col { display: none; }
  .mobile-summary { display: block; }
  .cust-page { padding-bottom: 200px; }
}
@media (max-width: 720px) {
  .topbar { flex-direction: column; gap: 12px; padding: 14px; position: static; }
  .topbar-nav { flex-wrap: wrap; justify-content: center; }
  .page-head h1 { font-size: 1.9rem; }
  .card-top { grid-template-columns: auto 80px 1fr; }
  .card-right {
    grid-column: 1 / -1;
    flex-direction: row; justify-content: space-between; align-items: center;
    padding-top: 12px; margin-top: 4px;
    border-top: 1.5px dashed var(--c-border);
  }
  .product-thumb { width: 80px; height: 80px; }
  .design-buttons { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
  .cust-page { padding: 18px 14px 220px; }
  .page-head h1 { font-size: 1.6rem; }
}
</style>
</head>
<body>

<div class="cust-page">

<!-- TOP NAV -->
<div class="topbar">
  <div class="topbar-logo">SOLISCO.</div>
  <div class="topbar-nav">
    <a href="home.php">Home</a>
    <a href="cart.php" class="active">🛒 Cart</a>
    <a href="orders.php">My Orders</a>
    <a href="profile.php">Profile</a>
    <a href="#" class="danger" onclick="openModal('logoutModal'); return false;">Logout</a>
  </div>
</div>

<form method="POST" action="cart.php" id="cartForm">
  <input type="hidden" name="place_order" value="1">

  <div class="page-head">
    <h1>
      Your <span class="grad">Cart</span>
      <span class="count-pill" id="totalCountBadge"><?= count($cartItems) ?> item<?= count($cartItems) !== 1 ? 's' : '' ?></span>
    </h1>
    <p>Select items to checkout. Click <strong>“View Design”</strong> on any side to preview that design.</p>
  </div>

  <div class="page-wrap">
    <!-- LEFT: Cart List -->
    <div class="cart-list-col">

      <?php if (!empty($cartItems)): ?>

      <!-- Select-all bar -->
      <div class="select-bar">
        <label for="selectAll">
          <div class="check-custom" id="selectAllCheck"></div>
          Select All
        </label>
        <input type="checkbox" id="selectAll" style="display:none" onchange="toggleAll(this.checked)">
        <div class="select-spacer"></div>
        <span class="sel-count" id="selCountLabel"></span>
        <button type="button" class="btn-delete-sel" id="btnDeleteSel" onclick="openDeleteModal('selected')">
          Remove Selected
        </button>
      </div>

      <!-- Cart items -->
      <div class="cart-list" id="cartList">
        <?php foreach ($cartItems as $ci):
          $imgUrl   = dbImageToDataUrl($ci['profile_image']);
          $stockQty = (int)$ci['stock_qty'];
          if ($stockQty <= 0)        { $bclass='badge-out'; $btxt='Out of Stock'; }
          elseif ($stockQty < 10)    { $bclass='badge-low'; $btxt="Only $stockQty left"; }
          else                       { $bclass='badge-ok';  $btxt='In Stock'; }
          $dFront = dbImageToDataUrl($ci['design_front'] ?? null);
          $dBack  = dbImageToDataUrl($ci['design_back']  ?? null);
          $dLeft  = dbImageToDataUrl($ci['design_left']  ?? null);
          $dRight = dbImageToDataUrl($ci['design_right'] ?? null);
          $createdAt = isset($ci['created_at']) ? date('M j, Y · g:i A', strtotime($ci['created_at'])) : '';
        ?>
        <div class="cart-card" id="card_<?= $ci['id'] ?>"
             data-id="<?= $ci['id'] ?>"
             data-price="<?= (float)$ci['unit_price'] ?>"
             data-qty="<?= (int)$ci['quantity'] ?>"
             data-subtotal="<?= (float)$ci['subtotal'] ?>"
             data-name="<?= htmlspecialchars($ci['item_name'], ENT_QUOTES) ?>"
             data-img="<?= htmlspecialchars($imgUrl ?? '', ENT_QUOTES) ?>">

          <!-- Top Row -->
          <div class="card-top">
            <div class="card-check" onclick="toggleCard(<?= $ci['id'] ?>)">
              <div class="check-custom" id="chk_<?= $ci['id'] ?>"></div>
            </div>

            <div class="product-thumb">
              <?php if ($imgUrl): ?>
                <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($ci['item_name']) ?>">
              <?php else: ?>
                <div class="thumb-ph"></div>
              <?php endif; ?>
            </div>

            <div class="product-info">
              <div class="product-category"><?= htmlspecialchars($ci['category'] ?? 'Merchandise') ?></div>
              <div class="product-name" title="<?= htmlspecialchars($ci['item_name']) ?>"><?= htmlspecialchars($ci['item_name']) ?></div>
              <div class="product-price">₱<?= number_format($ci['unit_price'], 2) ?></div>
              <div class="product-meta">
                <?php if (!empty($ci['color'])): ?>
                <span class="meta-pill">
                  <span class="pill-swatch" style="background:<?= colorToHex($ci['color']) ?>"></span>
                  <?= htmlspecialchars($ci['color']) ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($ci['size'])): ?>
                <span class="meta-pill"> <?= htmlspecialchars($ci['size']) ?></span>
                <?php endif; ?>
                <span class="stock-badge <?= $bclass ?>"><?= $btxt ?></span>
              </div>
            </div>

            <div class="card-right">
              <div class="subtotal-badge">₱<?= number_format($ci['subtotal'], 2) ?></div>
              <div class="qty-display">
                <span>Qty</span>
                <div class="qty-bubble"><?= $ci['quantity'] ?></div>
              </div>
              <button type="button" class="btn-remove-single" title="Remove"
                      onclick="openDeleteModal('single', <?= $ci['id'] ?>)">✕</button>
            </div>
          </div>

          <!-- Meta bar -->
          <?php if (!empty($ci['location']) || $createdAt): ?>
          <div class="card-meta-bar">
            <?php if (!empty($ci['location'])): ?>
              <span><span class="ico">📍</span> <?= htmlspecialchars($ci['location']) ?></span>
            <?php endif; ?>
            <?php if ($createdAt): ?>
              <span><span class="ico">🕐</span> Added <?= $createdAt ?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- DESIGN VIEW BUTTONS -->
          <div class="design-section">
            <div class="design-section-title">
              <span class="dot"></span> Custom Designs
            </div>
            <div class="design-buttons">
              <?php
              $sides = [
                ['key'=>'front','icon'=>'⬆','label'=>'Front','url'=>$dFront],
                ['key'=>'back', 'icon'=>'⬇','label'=>'Back', 'url'=>$dBack],
                ['key'=>'left', 'icon'=>'◀','label'=>'Left', 'url'=>$dLeft],
                ['key'=>'right','icon'=>'▶','label'=>'Right','url'=>$dRight],
              ];
              foreach ($sides as $s):
                $has = !empty($s['url']);
              ?>
              <button type="button"
                      class="btn-view-design <?= $has ? 'has-design' : 'no-design' ?>"
                      <?php if($has): ?>
                      onclick="toggleInlineDesign(<?= $ci['id'] ?>, '<?= $s['key'] ?>', this)"
                      data-src="<?= htmlspecialchars($s['url']) ?>"
                      data-title="<?= htmlspecialchars($ci['item_name']) ?> · <?= $s['label'] ?> Design"
                      data-side="<?= $s['label'] ?>"
                      title="View <?= $s['label'] ?> design"
                      <?php else: ?>
                      disabled
                      <?php endif; ?>>
                <span class="bvd-icon"><?= $s['icon'] ?></span>
                <span class="bvd-side"><?= $s['label'] ?></span>
                <span class="bvd-status"><?= $has ? 'View Design' : 'No design' ?></span>
              </button>
              <?php endforeach; ?>
            </div>

            <!-- Inline preview slot -->
            <div class="inline-design-wrap" id="inline_<?= $ci['id'] ?>">
              <div class="inline-design-card">
                <img src="" alt="" id="inlineImg_<?= $ci['id'] ?>">
                <div class="inline-design-label" id="inlineLbl_<?= $ci['id'] ?>"></div>
                <div class="inline-design-actions">
                  <button type="button" class="inline-action"
                          onclick="openLightboxFromInline(<?= $ci['id'] ?>)">Full Size</button>
                  <button type="button" class="inline-action"
                          onclick="closeInlineDesign(<?= $ci['id'] ?>)">✕ Hide</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Hidden checkbox for form submission -->
          <input type="checkbox" name="selected_ids[]" value="<?= $ci['id'] ?>"
                 id="formChk_<?= $ci['id'] ?>" style="display:none">
        </div>
        <?php endforeach; ?>
      </div>

      <?php else: ?>
      <!-- Empty state -->
      <div class="empty-state">
        <div class="empty-icon"></div>
        <h2>Your cart is empty</h2>
        <p>Looks like you haven't added any items yet.<br>Browse our merchandise to get started!</p>
        <a href="home.php" class="btn-browse">✦ Browse Products</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Order Summary (desktop) -->
    <?php if (!empty($cartItems)): ?>
    <div class="summary-col">
      <div class="summary-panel" id="summaryPanel">
        <div class="summary-empty-state" id="summaryEmptyState">
          <div class="summary-empty-icon">☑️</div>
          <div class="summary-empty-text">Select items from your cart<br>to see your order summary</div>
        </div>

        <div id="summaryContent" style="display:none">
          <div class="summary-head">
            <span class="summary-head-title">Order Summary</span>
            <span class="summary-count-pill" id="summaryCountPill">0 items</span>
          </div>
          <div class="summary-body">
            <div class="summary-items-list" id="summaryItemsList"></div>
            <hr class="summary-divider">
            <div class="summary-line">
              <span>Subtotal</span>
              <span id="summarySubtotal">₱0.00</span>
            </div>
            <div class="summary-line">
              <span>Shipping</span>
              <span style="color:var(--c-muted);font-style:italic">To be confirmed</span>
            </div>
            <hr class="summary-divider">
            <div class="summary-line total">
              <span>Total</span>
              <span class="total-val" id="summaryTotal">₱0.00</span>
            </div>
            <div class="delivery-note">Estimated delivery will be confirmed after order review</div>
            <button type="submit" class="btn-checkout" id="summaryCheckoutBtn" disabled>
              ✓ Place Order
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- .page-wrap -->
</form>

</div><!-- /cust-page -->

<!-- Toast stack -->
<div class="toast-stack" id="toastStack"></div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <div class="lightbox-inner" onclick="event.stopPropagation()">
    <div class="lightbox-title" id="lightboxTitle"></div>
    <img class="lightbox-img" id="lightboxImg" src="" alt="Design">
    <button class="lightbox-close" onclick="closeLightbox()">Close Preview</button>
  </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-icon"></div>
    <h3>Remove Item?</h3>
    <p>This will permanently remove the selected item(s) from your cart.</p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeDeleteModal()">Cancel</button>
      <button class="modal-confirm-del" id="deleteConfirmBtn">Remove</button>
    </div>
  </div>
</div>

<!-- Logout modal -->
<div class="modal-overlay" id="logoutModal">
  <div class="modal-box">
    <div class="modal-icon"></div>
    <h3>Log Out?</h3>
    <p>Are you sure you want to log out of your account?</p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeModal('logoutModal')">Cancel</button>
      <a href="../../logout.php" class="modal-confirm-logout">Yes, Log Out</a>
    </div>
  </div>
</div>

<!-- MOBILE BOTTOM SUMMARY -->
<?php if (!empty($cartItems)): ?>
<div class="mobile-summary" id="mobileSummary">
  <div class="mobile-summary-inner">
    <div class="mobile-handle"><div class="mobile-handle-bar"></div></div>
    <div class="mobile-summary-collapsed">
      <div class="mobile-sel-info">
        <div class="mobile-sel-count" id="mobileSelCount">0 items selected</div>
        <div class="mobile-sel-total" id="mobileTotal">₱0.00</div>
      </div>
      <button type="button" class="mobile-checkout-btn" id="mobilePlaceOrderBtn" disabled
              onclick="document.getElementById('cartForm').submit()">
        Place Order ✓
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* ════════ STATE ════════ */
const selectedIds = new Set();
let deleteTarget = null;
const inlineState = {};

/* ════════ TOAST ════════ */
function showToast(msg, type='success'){
  const stack = document.getElementById('toastStack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.innerHTML = `
    <div class="toast-icon-wrap ${type==='error'?'err':''}">${type==='success'?'✓':'!'}</div>
    <span>${msg}</span>`;
  stack.appendChild(t);
  setTimeout(()=>{ t.classList.add('out'); setTimeout(()=>t.remove(),350); },3200);
}

/* ════════ MODALS ════════ */
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function openDeleteModal(type, id=null){
  deleteTarget = type==='selected' ? 'selected' : id;
  openModal('deleteModal');
}
function closeDeleteModal(){ closeModal('deleteModal'); }
document.getElementById('deleteConfirmBtn')?.addEventListener('click', ()=>{
  closeDeleteModal();
  if (deleteTarget==='selected') deleteSelected();
  else deleteItem(deleteTarget);
});
['deleteModal','logoutModal'].forEach(id=>{
  document.getElementById(id).addEventListener('click', e=>{
    if (e.target.id===id) closeModal(id);
  });
});

/* ════════ DELETE ════════ */
function deleteItem(id){
  const card = document.getElementById('card_'+id);
  if (card){ card.style.transform='translateX(100%)'; card.style.opacity='0'; }
  setTimeout(()=>{ window.location.href='cart.php?remove='+id; }, 350);
}
function deleteSelected(){
  if (!selectedIds.size){ showToast('No items selected','error'); return; }
  const form = document.createElement('form');
  form.method='POST'; form.action='cart.php';
  const di = document.createElement('input');
  di.type='hidden'; di.name='delete_selected'; di.value='1';
  form.appendChild(di);
  selectedIds.forEach(id=>{
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='selected_ids[]'; inp.value=id;
    form.appendChild(inp);
  });
  document.body.appendChild(form);
  form.submit();
}

/* ════════ SELECTION ════════ */
function toggleCard(id){
  const card    = document.getElementById('card_'+id);
  const check   = document.getElementById('chk_'+id);
  const formChk = document.getElementById('formChk_'+id);
  if (selectedIds.has(id)){
    selectedIds.delete(id);
    card.classList.remove('selected');
    check.classList.remove('checked');
    formChk.checked = false;
  } else {
    selectedIds.add(id);
    card.classList.add('selected');
    check.classList.add('checked');
    formChk.checked = true;
  }
  updateSummary();
  syncSelectAllCheck();
}
function toggleAll(checked){
  document.querySelectorAll('.cart-card').forEach(card=>{
    const id = parseInt(card.dataset.id);
    const check = document.getElementById('chk_'+id);
    const formChk = document.getElementById('formChk_'+id);
    if (checked){
      selectedIds.add(id);
      card.classList.add('selected');
      check.classList.add('checked');
      if (formChk) formChk.checked = true;
    } else {
      selectedIds.delete(id);
      card.classList.remove('selected');
      check.classList.remove('checked');
      if (formChk) formChk.checked = false;
    }
  });
  document.getElementById('selectAllCheck').classList.toggle('checked', checked);
  updateSummary();
}
function syncSelectAllCheck(){
  const allCards = document.querySelectorAll('.cart-card');
  const allCheck = document.getElementById('selectAllCheck');
  const allIn = document.getElementById('selectAll');
  const allSelected = allCards.length>0 && selectedIds.size===allCards.length;
  allCheck.classList.toggle('checked', allSelected);
  if (allIn) allIn.checked = allSelected;
}
document.getElementById('selectAllCheck')?.addEventListener('click', ()=>{
  const allIn = document.getElementById('selectAll');
  const newState = !allIn.checked;
  allIn.checked = newState;
  document.getElementById('selectAllCheck').classList.toggle('checked', newState);
  toggleAll(newState);
});

/* ════════ SUMMARY ════════ */
function updateSummary(){
  const count = selectedIds.size;
  const delSel = document.getElementById('btnDeleteSel');
  const selLabel = document.getElementById('selCountLabel');
  if (delSel) delSel.classList.toggle('visible', count>0);
  if (selLabel) selLabel.textContent = count>0 ? `${count} selected` : '';

  const emptyState  = document.getElementById('summaryEmptyState');
  const content     = document.getElementById('summaryContent');
  const checkoutBtn = document.getElementById('summaryCheckoutBtn');
  const mobileSummary  = document.getElementById('mobileSummary');
  const mobileCount    = document.getElementById('mobileSelCount');
  const mobileTotal    = document.getElementById('mobileTotal');
  const mobilePlaceBtn = document.getElementById('mobilePlaceOrderBtn');

  let subtotal = 0;
  const data = [];
  selectedIds.forEach(id=>{
    const card = document.getElementById('card_'+id);
    if (!card) return;
    subtotal += parseFloat(card.dataset.subtotal);
    data.push({
      id,
      name: card.dataset.name,
      img:  card.dataset.img,
      price: parseFloat(card.dataset.price),
      qty:   parseInt(card.dataset.qty),
      sub:   parseFloat(card.dataset.subtotal),
    });
  });

  if (count===0){
    if (emptyState){ emptyState.style.display=''; content.style.display='none'; }
    if (checkoutBtn) checkoutBtn.disabled = true;
    if (mobileSummary) mobileSummary.classList.remove('open');
    if (mobilePlaceBtn) mobilePlaceBtn.disabled = true;
    return;
  }

  if (emptyState){ emptyState.style.display='none'; content.style.display=''; }
  const fmt = v => '₱'+v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  document.getElementById('summaryCountPill').textContent = count+' item'+(count!==1?'s':'');
  document.getElementById('summarySubtotal').textContent = fmt(subtotal);
  document.getElementById('summaryTotal').textContent = fmt(subtotal);

  const list = document.getElementById('summaryItemsList');
  if (list){
    list.innerHTML = data.map(d=>`
      <div class="si-row">
        <div class="si-img">${d.img?`<img src="${d.img}" alt="">`:'<span style="font-size:1.2rem;opacity:.4">👕</span>'}</div>
        <div class="si-info">
          <div class="si-name">${d.name}</div>
          <div class="si-variant">Qty ${d.qty} · ${fmt(d.price)} each</div>
        </div>
        <div class="si-price">${fmt(d.sub)}</div>
      </div>`).join('');
  }
  if (checkoutBtn) checkoutBtn.disabled = false;
  if (mobileSummary) mobileSummary.classList.add('open');
  if (mobileCount) mobileCount.textContent = count+' item'+(count!==1?'s':'')+' selected';
  if (mobileTotal) mobileTotal.textContent = fmt(subtotal);
  if (mobilePlaceBtn) mobilePlaceBtn.disabled = false;
}

/* ════════ DESIGN VIEW ════════ */
function toggleInlineDesign(cartId, sideKey, btn){
  const wrap = document.getElementById('inline_'+cartId);
  const img  = document.getElementById('inlineImg_'+cartId);
  const lbl  = document.getElementById('inlineLbl_'+cartId);
  const src  = btn.dataset.src;
  const title= btn.dataset.title;
  const side = btn.dataset.side;

  if (wrap.classList.contains('open') && wrap.dataset.side===sideKey){
    closeInlineDesign(cartId);
    return;
  }
  img.src = src;
  img.alt = title;
  lbl.textContent = side+' Design';
  wrap.dataset.side = sideKey;
  wrap.classList.add('open');
  inlineState[cartId] = { src, title, side };

  document.querySelectorAll('#card_'+cartId+' .btn-view-design').forEach(b=>{
    b.style.outline = (b===btn) ? '2px solid rgba(124,58,237,.55)' : '';
    b.style.outlineOffset = (b===btn) ? '2px' : '';
  });
}
function closeInlineDesign(cartId){
  const wrap = document.getElementById('inline_'+cartId);
  wrap.classList.remove('open');
  wrap.dataset.side = '';
  document.querySelectorAll('#card_'+cartId+' .btn-view-design').forEach(b=>{
    b.style.outline = ''; b.style.outlineOffset='';
  });
}
function openLightboxFromInline(cartId){
  const s = inlineState[cartId];
  if (!s) return;
  openLightbox(s.src, s.title);
}

/* ════════ LIGHTBOX ════════ */
function openLightbox(src, title){
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightboxTitle').textContent = title;
  document.getElementById('lightbox').classList.add('open');
}
function closeLightbox(){ document.getElementById('lightbox').classList.remove('open'); }
document.addEventListener('keydown', e=>{
  if (e.key==='Escape'){ closeLightbox(); ['deleteModal','logoutModal'].forEach(closeModal); }
});

/* ════════ NOTIFICATIONS ════════ */
<?php if (isset($_GET['added'])): ?>showToast('Item added to cart! 🎉');<?php endif; ?>
<?php if (isset($_GET['removed'])): ?>showToast('Item removed from cart.','error');<?php endif; ?>

updateSummary();
</script>
</body>
</html>
