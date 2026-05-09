<?php
/* ============================================================
  SMOIMS - Customer Homepage (Product Listing)
  FILE: pages/customer/home.php
  ============================================================ */

require_once '../../includes/config.php';
requireCustomerLogin();

/* ── Filter by category ── */
$cat   = clean($conn, $_GET['category'] ?? '');
$where = $cat ? "WHERE i.category = ? AND i.quantity > 0" : "WHERE i.quantity > 0";

/* ── AJAX: real-time stock polling endpoint ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stock') {
    header('Content-Type: application/json');

    $ids = array_map('intval', explode(',', $_GET['ids'] ?? ''));
    $ids = array_filter($ids);
    if (empty($ids)) { echo json_encode([]); exit; }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT id, quantity FROM inventory WHERE id IN ($placeholders)"
    );
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows as $r) {
        $out[$r['id']] = ['quantity' => (int)$r['quantity']];
    }
    echo json_encode($out);
    exit;
}

/* ── Fetch products with review aggregates ── */
if ($cat) {
    $stmt = $conn->prepare(
        "SELECT i.*,
                ROUND(AVG(r.rating), 1) AS avg_rating,
                COUNT(r.id)            AS review_count
           FROM inventory i
           LEFT JOIN reviews r ON r.product_id = i.id
          WHERE i.category = ? AND i.quantity > 0
          GROUP BY i.id
          ORDER BY i.item_name ASC"
    );
    $stmt->bind_param("s", $cat);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query(
        "SELECT i.*,
                ROUND(AVG(r.rating), 1) AS avg_rating,
                COUNT(r.id)            AS review_count
           FROM inventory i
           LEFT JOIN reviews r ON r.product_id = i.id
          WHERE i.quantity > 0
          GROUP BY i.id
          ORDER BY i.item_name ASC"
    );
}

/* ── Categories for tabs ── */
$categories = $conn->query("SELECT DISTINCT category FROM inventory ORDER BY category");

/* ── Build products array ── */
$products = [];
$now = new DateTime();
while ($p = $result->fetch_assoc()) {
    // Mark product as "new" if added within the last 5 days
    $createdAt = new DateTime($p['created_at']);
    $diffDays  = (int)$now->diff($createdAt)->days;
    $p['is_new'] = ($diffDays < 5);
    $products[] = $p;
}


function dbImageToDataUrl(?string $blob): ?string
{
    if (empty($blob)) return null;
    if (str_starts_with($blob, 'data:image/')) return $blob;
    return 'data:image/jpeg;base64,' . base64_encode($blob);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Home | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../css/style.css">
  <style>
    /* ── Tokens ── */
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

    /* ── Animated background blobs ── */
    body::before, body::after {
      content: 'S';
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

    /* ── Page ── */
    .cust-page { max-width: 1180px; margin: 0 auto; padding: 24px 24px 80px; position: relative; z-index: 1; }

    /* ── Topbar (glass) ── */
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

    /* ── Hero ── */
    .hero {
      position: relative;
      overflow: hidden;
      border-radius: 28px;
      padding: 48px 48px;
      /* Remove background properties from here */
    }

    .hero::before {
      content: "";
      position: absolute;
      inset: 0; /* fills the whole hero area */
      z-index: -1; /* stays behind the text */
      
      /* Set your transparency here */
      opacity: 1; 
      
      background: 
        linear-gradient(rgba(167, 139, 250, 0.8), rgba(240, 171, 252, 0.8)),
        url('../../images/pretty.png') no-repeat center;
      background-size: cover;
    } 

    .hero::after {
      content: ''; 
      position: absolute;
      right: 50px; 
      top: 50%;
      transform: translateY(-50%);
      
      /* Set your logo size */
      width: 180px; 
      height: 180px;
      
      /* Add your image path here */
      background: url('') no-repeat center;
      background-size: contain;
      
      opacity: .9; /* Adjust transparency to your liking */
      pointer-events: none;
      
      /* Keeps that cool rotation effect from your dashboard */
      animation: spin 22s linear infinite; 
    }
    @keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }
    .hero-eyebrow {
      display: inline-block;
      padding: 5px 14px;
      background: rgba(255,255,255,.6);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255,255,255,.8);
      border-radius: 99px;
      font-size: .72rem; font-weight: 700;
      letter-spacing: 1.2px; text-transform: uppercase;
      color: var(--c-accent-deep);
      margin-bottom: 14px;
      position: relative; z-index: 1;
    }
    .hero h2 {
      font-family: var(--font-display);
      font-size: 2.4rem;
      font-weight: 800;
      color: #f5eaff;
      line-height: 1.1;
      position: relative; z-index: 1;
    }
    .hero h2 .accent {
      background: var(--grad-primary);
      -webkit-background-clip: text;
      background-clip: text;
      color: #f5eaff;
      font-style: italic;
    }
    .hero p {
      color: #f5eaff; margin-top: 10px;
      font-size: 1rem; max-width: 540px;
      position: relative; z-index: 1;
    }

    /* ── Search ── */
    .search-wrap {
      position: relative; margin-bottom: 18px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .2s both;
    }
    .search-wrap .ico { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-size: 1rem; pointer-events: none; }
    .search-inp {
      width: 100%; padding: 16px 48px 16px 48px;
      border: 1.5px solid var(--c-border); border-radius: 16px;
      font-family: var(--font-body); font-size: .95rem;
      background: rgba(255,255,255,.85);
      backdrop-filter: blur(10px);
      transition: all .25s; outline: none; color: var(--c-text);
      box-shadow: 0 2px 12px rgba(109,40,217,.04);
    }
    .search-inp:focus {
      border-color: var(--c-accent-mid);
      box-shadow: 0 0 0 4px rgba(167,139,250,.18), 0 4px 18px rgba(109,40,217,.10);
      transform: translateY(-1px);
    }
    .search-inp::placeholder { color: #b3a9d3; }
    .search-clr { position: absolute; right: 18px; top: 50%; transform: translateY(-50%); background: var(--c-accent-light); border: none; width: 26px; height: 26px; border-radius: 50%; font-size: .8rem; cursor: pointer; color: var(--c-accent); display: none; align-items: center; justify-content: center; transition: all .2s; }
    .search-clr:hover { background: var(--c-accent); color: #fff; transform: translateY(-50%) rotate(90deg); }

    /* ── Category tabs ── */
    .cat-row {
      display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 22px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .3s both;
    }
    .cat-tab {
      padding: 8px 18px; border-radius: 99px; font-weight: 700; font-size: .82rem;
      border: 1.5px solid var(--c-border);
      color: var(--c-muted);
      background: rgba(255,255,255,.7);
      backdrop-filter: blur(8px);
      text-decoration: none; transition: all .25s; white-space: nowrap;
    }
    .cat-tab:hover {
      border-color: var(--c-accent-mid); color: var(--c-accent);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(124,58,237,.15);
    }
    .cat-tab.active {
      border-color: transparent;
      background: var(--grad-primary);
      color: #fff;
      box-shadow: 0 6px 18px rgba(124,58,237,.35);
    }

    /* ── sync-indicator {
      display: inline-flex; align-items: center; gap: 8px;
      font-size: .78rem; color: var(--c-muted); margin-bottom: 18px;
      font-weight: 600;
      padding: 6px 14px;
      background: rgba(255,255,255,.6);
      border: 1px solid var(--c-border);
      border-radius: 99px;
      backdrop-filter: blur(8px); ── */


    .sync-indicator {
      display: none;
    }

    .sync-dot { width: 8px; height: 8px; background: var(--c-ok); border-radius: 50%; animation: pulse 2s infinite; box-shadow: 0 0 8px var(--c-ok); }
    @keyframes pulse { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: .4; transform: scale(.8); } }

    /* ── Grid ── */
    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 22px;
    }

    /* ── Card ── */
    .product-card {
      background: rgba(255,255,255,.85);
      backdrop-filter: blur(12px);
      border-radius: var(--r-card);
      border: 1.5px solid rgba(255,255,255,.8);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      text-decoration: none;
      color: inherit;
      transition: transform .35s cubic-bezier(.22,1,.36,1), box-shadow .35s, border-color .25s;
      box-shadow: var(--shadow-card);
      cursor: pointer;
      position: relative;
      animation: cardIn .6s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes cardIn {
      from { opacity: 0; transform: translateY(24px) scale(.96); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .product-grid > .product-card:nth-child(1) { animation-delay: .05s; }
    .product-grid > .product-card:nth-child(2) { animation-delay: .10s; }
    .product-grid > .product-card:nth-child(3) { animation-delay: .15s; }
    .product-grid > .product-card:nth-child(4) { animation-delay: .20s; }
    .product-grid > .product-card:nth-child(5) { animation-delay: .25s; }
    .product-grid > .product-card:nth-child(6) { animation-delay: .30s; }
    .product-grid > .product-card:nth-child(7) { animation-delay: .35s; }
    .product-grid > .product-card:nth-child(8) { animation-delay: .40s; }

    .product-card::before {
      content: '';
      position: absolute; inset: 0;
      border-radius: var(--r-card);
      padding: 1.5px;
      background: var(--grad-primary);
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
              mask-composite: exclude;
      opacity: 0;
      transition: opacity .3s;
      pointer-events: none;
    }
    .product-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-hover);
    }
    .product-card:hover::before { opacity: 1; }

    /* Card image */
    .card-img {
      width: 100%; height: 220px;
      background: linear-gradient(135deg, #ede9fe, #fce7f3);
      display: flex; align-items: center; justify-content: center;
      position: relative;
      overflow: hidden;
    }
    .card-img img {
      width: 100%; height: 100%; object-fit: cover;
      transition: transform .6s cubic-bezier(.22,1,.36,1);
    }
    .product-card:hover .card-img img { transform: scale(1.08); }
    .card-img::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(180deg, transparent 60%, rgba(30,27,75,.15));
      opacity: 0; transition: opacity .3s;
    }
    .product-card:hover .card-img::after { opacity: 1; }

    .card-ribbon {
      position: absolute; top: 14px; right: 14px; z-index: 2;
      padding: 5px 12px; border-radius: 99px; font-size: .68rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: .5px;
      backdrop-filter: blur(8px);
      box-shadow: 0 4px 12px rgba(0,0,0,.15);
    }
    .ribbon-ok  { background: rgba(22,163,74,.95); color: #fff; }
    .ribbon-low { background: rgba(217,119,6,.95); color: #fff; }
    .ribbon-out { background: rgba(220,38,38,.95); color: #fff; }

    /* ── NEW item badge ── */
    .new-badge {
      position: absolute; top: 14px; left: 14px; z-index: 3;
      padding: 4px 11px;
      border-radius: 99px;
      font-size: .68rem; font-weight: 800;
      letter-spacing: 1.2px; text-transform: uppercase;
      background: var(--grad-primary);
      color: #fff;
      box-shadow: 0 3px 12px rgba(124,58,237,.45);
      animation: newBadgePop .5s cubic-bezier(.22,1,.36,1) both, newBadgeGlow 2.4s ease-in-out infinite;
      pointer-events: none;
    }
    @keyframes newBadgePop {
      from { opacity: 0; transform: scale(.5); }
      to   { opacity: 1; transform: scale(1); }
    }
    @keyframes newBadgeGlow {
      0%,100% { box-shadow: 0 3px 12px rgba(124,58,237,.45); }
      50%     { box-shadow: 0 3px 22px rgba(124,58,237,.75), 0 0 0 4px rgba(167,139,250,.2); }
    }

    /* Card Body */
    .card-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }
    .card-cat { font-size: .7rem; font-weight: 800; color: var(--c-accent); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .card-title { font-family: var(--font-head); font-size: 1.1rem; font-weight: 700; margin-bottom: 10px; line-height: 1.3; }
    .card-price {
      font-family: var(--font-display);
      font-size: 1.5rem; font-weight: 800;
      background: var(--grad-primary);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      margin-bottom: 16px;
    }

    /* Star Rating */
    .card-meta { margin-top: auto; border-top: 1.5px dashed var(--c-border); padding-top: 14px; }
    .card-rating {
      display: flex; align-items: center; gap: 7px;
    }
    .card-stars {
      display: flex; gap: 2px;
    }
    .card-stars .star {
      font-size: .9rem;
      color: #d1d5db;
      transition: color .2s;
    }
    .card-stars .star.filled { color: #f59e0b; }
    .card-stars .star.half {
      position: relative; color: #d1d5db;
    }
    .card-stars .star.half::before {
      content: '★';
      position: absolute; left: 0; top: 0;
      width: 50%; overflow: hidden;
      color: #f59e0b;
    }
    .card-rating-score {
      font-size: .82rem; font-weight: 700; color: var(--c-text);
    }
    .card-rating-count {
      font-size: .75rem; color: var(--c-muted); font-weight: 500;
    }
    .card-rating-none {
      font-size: .78rem; color: var(--c-muted); font-style: italic;
    }

    .card-order-btn {
      display: flex; align-items: center; justify-content: center; gap: 6px;
      width: 100%; padding: 12px; margin-top: 18px;
      background: #7e2bc2;
      color: #fff; text-align: center; border-radius: var(--r-btn);
      font-weight: 700; font-size: .92rem; text-decoration: none;
      transition: all .3s;
      position: relative; overflow: hidden;
      box-shadow: 0 4px 14px rgba(124,58,237,.3);
    }
    .card-order-btn::before {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(120deg, transparent, rgba(255,255,255,.4), transparent);
      transform: translateX(-100%);
      transition: transform .6s;
    }
    .card-order-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(124,58,237,.45); }
    .card-order-btn:hover::before { transform: translateX(100%); }
    .card-order-btn.disabled {
      background: #e5e7eb; color: #9ca3af; cursor: not-allowed;
      box-shadow: none;
    }
    .card-order-btn.disabled::before { display: none; }

    /* ── Empty state ── */
    .empty-state {
      grid-column: 1/-1; text-align: center; padding: 80px 24px; color: var(--c-muted);
      background: rgba(255,255,255,.6);
      border: 1.5px dashed var(--c-border-strong);
      border-radius: 24px;
    }
    .empty-state .em-ico { font-size: 3.5rem; margin-bottom: 12px; opacity: .5; }
    .no-results { display: none; }

    /* ── Floating cart ── */
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

    /* ── Modal ── */
    .modal-overlay { position: fixed; inset: 0; background: rgba(30,27,75,0.5); backdrop-filter: blur(8px); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .25s; }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal-box {
      background: rgba(255,255,255,.95);
      backdrop-filter: blur(20px);
      padding: 36px; border-radius: 24px; width: 100%; max-width: 400px; text-align: center;
      box-shadow: 0 24px 60px rgba(30,27,75,.25);
      transform: translateY(30px) scale(.95); transition: transform .35s cubic-bezier(.22,1,.36,1);
      border: 1.5px solid rgba(255,255,255,.8);
    }
    .modal-overlay.open .modal-box { transform: translateY(0) scale(1); }
    .lo-icon { font-size: 3.5rem; margin-bottom: 14px; animation: wave 1.6s ease-in-out infinite; display: inline-block; transform-origin: 70% 70%; }
    @keyframes wave { 0%,100% { transform: rotate(0); } 25% { transform: rotate(15deg); } 75% { transform: rotate(-10deg); } }
    .modal-box h3 { font-family: var(--font-display); font-size: 1.6rem; margin-bottom: 10px; }
    .modal-box p { color: var(--c-muted); margin-bottom: 26px; line-height: 1.5; }
    .btn-row { display: flex; gap: 12px; }
    .btn-cancel { flex: 1; padding: 13px; border-radius: 12px; border: 1.5px solid var(--c-border); background: #fff; font-weight: 700; cursor: pointer; transition: all .2s; }
    .btn-cancel:hover { background: var(--c-bg); border-color: var(--c-border-strong); }
    .btn-logout-ok { flex: 1; padding: 13px; border-radius: 12px; background: var(--c-err); color: #fff; text-decoration: none; font-weight: 700; transition: all .2s; }
    .btn-logout-ok:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(220,38,38,.35); }

    /* ── Shared keyframes ── */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Responsive ── */
    @media (max-width: 720px) {
      .topbar { flex-direction: column; gap: 12px; padding: 14px; position: static; }
      .topbar-nav { flex-wrap: wrap; justify-content: center; }
      .hero { padding: 32px 24px; }
      .hero h2 { font-size: 1.8rem; }
      .hero::after { font-size: 4rem; right: 20px; }
      .product-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
    }
    
 
  </style>
</head>
<body>

<div class="cust-page">

<!-- TOP NAV -->
<div class="topbar">
  <div class="topbar-logo">SOLISCO.</div>
  <div class="topbar-nav">
    <a href="home.php" class="active">Home</a>
    <a href="cart.php">🛒 Cart</a>
    <a href="orders.php">My Orders</a>
    <a href="profile.php">Profile</a>
    <a href="#" class="danger" onclick="openLogoutModal(); return false;">Logout</a>
  </div>
</div>


  <!-- Hero -->
  <section class="hero">
    <span class="hero-eyebrow">★ New Drop · Premium Collection</span>
    <h2>Welcome , <span class="accent"><?= explode(' ', $_SESSION['customer_name'])[0] ?></span></h2>
    <p>Discover our latest collection of premium custom merchandise — crafted with care, design it to stand out.</p>
  </section>

  <!-- Search & Filters -->
  <div class="search-wrap">
    <span class="ico">🔍</span>
    <input type="text" class="search-inp" id="liveSearch" placeholder="Search products or categories..." onkeyup="filterProducts(this.value)">
    <button class="search-clr" id="searchClr" onclick="clearSearch()">✕</button>
  </div>


  <div class="cat-row">
    <a href="home.php" class="cat-tab <?= !$cat ? 'active' : '' ?>">✦ All Items</a>
    <?php while ($c = $categories->fetch_assoc()): ?>
      <a href="home.php?category=<?= urlencode($c['category']) ?>"
         class="cat-tab <?= $cat === $c['category'] ? 'active' : '' ?>">
        <?= htmlspecialchars($c['category']) ?>
      </a>
    <?php endwhile; ?>
  </div>

  <div class="sync-indicator">
    <div class="sync-dot"></div>
    <span id="syncLabel">Live stock tracking active</span>
  </div>

  <!-- Product Grid -->
  <div class="product-grid" id="productGrid">
    <?php if (!empty($products)): ?>
      <?php foreach ($products as $p): ?>
        <div class="product-card"
             data-id="<?= $p['id'] ?>"
             data-name="<?= strtolower($p['item_name']) ?>"
             data-category="<?= strtolower($p['category']) ?>"
             data-qty="<?= $p['quantity'] ?>"
             onclick="if(event.target.tagName !== 'A') window.location='ordering_item.php?id=<?= $p['id'] ?>'">

          <div class="card-img">
            <?php
              $img = dbImageToDataUrl($p['profile_image'] ?: $p['image_front']);
              if ($img):
            ?>
              <img src="<?= $img ?>" alt="<?= htmlspecialchars($p['item_name']) ?>">
            <?php else: ?>
              <span style="font-size:3rem">No Image</span>
            <?php endif; ?>

            <?php if (!empty($p['is_new'])): ?>
              <div class="new-badge">✦ New</div>
            <?php endif; ?>

            <?php if ($p['quantity'] <= 0): ?>
              <div class="card-ribbon ribbon-out" id="ribbon_<?= $p['id'] ?>">Out of Stock</div>
            <?php elseif ($p['quantity'] < 10): ?>
              <div class="card-ribbon ribbon-low" id="ribbon_<?= $p['id'] ?>">Low Stock</div>
            <?php else: ?>
              <div class="card-ribbon ribbon-ok" id="ribbon_<?= $p['id'] ?>">In Stock</div>
            <?php endif; ?>
          </div>

          <div class="card-body">
            <span class="card-cat"><?= htmlspecialchars($p['category']) ?></span>
            <h3 class="card-title"><?= htmlspecialchars($p['item_name']) ?></h3>
            <div class="card-price">₱<?= number_format($p['price'], 2) ?></div>

            <div class="card-meta">
              <?php
                $avg   = (float)($p['avg_rating'] ?? 0);
                $count = (int)($p['review_count'] ?? 0);
              ?>
              <?php if ($count > 0): ?>
                <div class="card-rating">
                  <div class="card-stars">
                    <?php for ($s = 1; $s <= 5; $s++):
                      if ($avg >= $s)          $cls = 'filled';
                      elseif ($avg >= $s - 0.5) $cls = 'half';
                      else                      $cls = '';
                    ?>
                      <span class="star <?= $cls ?>">★</span>
                    <?php endfor; ?>
                  </div>
                  <span class="card-rating-score"><?= number_format($avg, 1) ?></span>
                  <span class="card-rating-count">(<?= $count ?> review<?= $count != 1 ? 's' : '' ?>)</span>
                </div>
              <?php else: ?>
                <div class="card-rating">
                  <div class="card-stars">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                      <span class="star">★</span>
                    <?php endfor; ?>
                  </div>
                  <span class="card-rating-none">No reviews yet</span>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($p['quantity'] > 0): ?>
              <a href="ordering_item.php?id=<?= $p['id'] ?>"
                 class="card-order-btn" id="orderbtn_<?= $p['id'] ?>">
                Order Now <span>→</span>
              </a>
            <?php else: ?>
              <span class="card-order-btn disabled" id="orderbtn_<?= $p['id'] ?>">
                Out of Stock
              </span>
            <?php endif; ?>
          </div>

        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty-state">
        <div class="em-ico"></div>
        <p>No products available at the moment.</p>
      </div>
    <?php endif; ?>

    <div class="no-results empty-state" id="noResults">
      <div class="em-ico">🔍</div>
      <p>No matching products found.</p>
    </div>
  </div><!-- /product-grid -->

</div><!-- /cust-page -->

<!-- Floating cart -->
<a href="cart.php" class="float-cart">🛒 View Cart</a>

<!-- LOGOUT MODAL -->
<div class="modal-overlay" id="logoutModal">
  <div class="modal-box">
    <div class="lo-icon"></div>
    <h3>Log Out?</h3>
    <p>Are you sure you want to log out of your account?</p>
    <div class="btn-row">
      <button class="btn-cancel" onclick="closeLogoutModal()">Cancel</button>
      <a href="../../logout.php" class="btn-logout-ok">Yes, Log Out</a>
    </div>
  </div>
</div>

<!-- ── No color map needed ── -->
<script>

/* ════════════════════════════════════════════════════════
   REAL-TIME INVENTORY SYNC
════════════════════════════════════════════════════════ */
async function syncInventory() {
    const cards = document.querySelectorAll('.product-card[data-id]');
    if (!cards.length) return;

    const ids = [...cards].map(c => c.dataset.id).join(',');

    try {
        const resp = await fetch(`home.php?ajax=stock&ids=${ids}`);
        if (!resp.ok) return;
        const data = await resp.json();

        cards.forEach(card => {
            const id  = card.dataset.id;
            const inv = data[id];
            if (!inv) return;

            const qty = inv.quantity;
            card.dataset.qty = qty;

            const ribbon = document.getElementById('ribbon_' + id);
            if (ribbon) {
                ribbon.className = 'card-ribbon';
                if (qty <= 0)       { ribbon.className += ' ribbon-out'; ribbon.textContent = 'Out of Stock'; }
                else if (qty < 10)  { ribbon.className += ' ribbon-low'; ribbon.textContent = 'Low Stock'; }
                else                { ribbon.className += ' ribbon-ok';  ribbon.textContent = 'In Stock'; }
            }

            const btn = document.getElementById('orderbtn_' + id);
            if (btn) {
                if (qty > 0) {
                    if (btn.tagName === 'SPAN') {
                        const a = document.createElement('a');
                        a.href      = `ordering_item.php?id=${id}`;
                        a.className = 'card-order-btn';
                        a.id        = 'orderbtn_' + id;
                        a.innerHTML = 'Order Now <span>→</span>';
                        btn.replaceWith(a);
                    } else {
                        btn.className = 'card-order-btn';
                        btn.href = `ordering_item.php?id=${id}`;
                        btn.innerHTML = 'Order Now <span>→</span>';
                    }
                } else {
                    if (btn.tagName === 'A') {
                        const span = document.createElement('span');
                        span.className   = 'card-order-btn disabled';
                        span.id          = 'orderbtn_' + id;
                        span.textContent = 'Out of Stock';
                        btn.replaceWith(span);
                    } else {
                        btn.className = 'card-order-btn disabled';
                        btn.textContent = 'Out of Stock';
                    }
                }
            }
        });

        const now  = new Date();
        const time = now.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:'2-digit' });
        document.getElementById('syncLabel').textContent = `Synced at ${time}`;

    } catch (err) {
        console.warn('Sync error:', err);
        document.getElementById('syncLabel').textContent = 'Sync paused — reconnecting…';
    }
}

setInterval(syncInventory, 10000);
setTimeout(syncInventory, 1000);


/* ════════════════════════════════════════════════════════
   LIVE SEARCH FILTER
════════════════════════════════════════════════════════ */
function filterProducts(query) {
    query = query.toLowerCase().trim();
    const cards    = document.querySelectorAll('.product-card[data-id]');
    const clrBtn   = document.getElementById('searchClr');
    const noResult = document.getElementById('noResults');
    let visible = 0;

    clrBtn.style.display = query.length ? 'flex' : 'none';

    cards.forEach(card => {
        const name = card.dataset.name     || '';
        const cat  = card.dataset.category || '';
        const show = name.includes(query) || cat.includes(query);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    noResult.style.display = visible === 0 && query.length ? 'block' : 'none';
}

function clearSearch() {
    const inp = document.getElementById('liveSearch');
    inp.value = '';
    filterProducts('');
    inp.focus();
}


/* ════════════════════════════════════════════════════════
   LOGOUT MODAL
════════════════════════════════════════════════════════ */
function openLogoutModal()  { document.getElementById('logoutModal').classList.add('open'); }
function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('open'); }
document.getElementById('logoutModal').addEventListener('click', e => {
    if (e.target.id === 'logoutModal') closeLogoutModal();
});
</script>
</body>
</html>