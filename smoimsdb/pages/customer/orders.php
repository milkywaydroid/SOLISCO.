<?php
/* ============================================================
   SMOIMS - Customer Order History (Redesigned)
   FILE: pages/customer/orders.php
   ============================================================ */
require_once '../../includes/config.php';
requireCustomerLogin();

function dbImageToDataUrl(?string $blob): ?string
{
    if (empty($blob)) return null;
    if (str_starts_with($blob, 'data:image/')) return $blob;
    return 'data:image/jpeg;base64,' . base64_encode($blob);
}

$customerId = $_SESSION['customer_id'];
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, customer_id, status FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result && $result->num_rows > 0) {
        $orderRow = $result->fetch_assoc();
        if ((int)$orderRow['customer_id'] !== $customerId) {
            $errorMessage = 'Unable to cancel this order.';
        } elseif ($orderRow['status'] !== 'Pending') {
            $errorMessage = 'Only pending orders can be canceled.';
        } else {
            $stmt2 = $conn->prepare("UPDATE orders SET status='Cancelled' WHERE id = ? AND customer_id = ? AND status = 'Pending'");
            $stmt2->bind_param('ii', $orderId, $customerId);
            $stmt2->execute();
            if ($stmt2->affected_rows > 0) {
                $successMessage = 'Order #' . $orderId . ' has been canceled successfully.';
            } else {
                $errorMessage = 'Unable to cancel this order.';
            }
            $stmt2->close();
        }
    } else {
        $errorMessage = 'Order not found.';
    }
}

$successOrderId = (int)($_GET['success'] ?? 0);

/* ── Filter by status tab ── */
$statusFilter = $_GET['status'] ?? 'all';
$allowedStatuses = ['all','Pending','Processing','Completed','Cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';

/* ── Fetch all orders (we'll count by status for tabs) ── */
$ordersQuery = $conn->query("
    SELECT o.*, COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.customer_id = $customerId
    GROUP BY o.id
    ORDER BY o.created_at DESC
");

$ordersAll = [];
$counts = ['all'=>0,'Pending'=>0,'Processing'=>0,'Completed'=>0,'Cancelled'=>0];
$totalSpent = 0.0;
if ($ordersQuery) {
    while ($r = $ordersQuery->fetch_assoc()) {
        $ordersAll[] = $r;
        $counts['all']++;
        if (isset($counts[$r['status']])) $counts[$r['status']]++;
        if ($r['status'] === 'Completed') $totalSpent += (float)$r['total_amount'];
    }
}
$ordersToShow = $statusFilter === 'all'
    ? $ordersAll
    : array_values(array_filter($ordersAll, fn($o) => $o['status'] === $statusFilter));

/* ── Preload items for visible orders to avoid N+1 weirdness in markup ── */
$itemsByOrder = [];
if (!empty($ordersToShow)) {
    $idList = implode(',', array_map(fn($o) => (int)$o['id'], $ordersToShow));
    if ($idList !== '') {
        $res = $conn->query("
            SELECT oi.*, i.item_name, i.profile_image
            FROM order_items oi
            JOIN inventory i ON oi.item_id = i.id
            WHERE oi.order_id IN ($idList)
        ");
        if ($res) {
            while ($it = $res->fetch_assoc()) {
                $itemsByOrder[$it['order_id']][] = $it;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Orders | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../css/style.css">
  <style>
    /* ── Tokens (aligned with home.php) ── */
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
      --c-ok: #16a34a;   --c-ok-bg: #dcfce7;
      --c-warn: #d97706; --c-warn-bg: #fef3c7;
      --c-info: #2563eb; --c-info-bg: #dbeafe;
      --c-err: #dc2626;  --c-err-bg: #fef2f2;
      --r-card: 20px;
      --r-btn: 12px;
      --shadow-card: 0 2px 8px rgba(109,40,217,.06), 0 8px 24px rgba(109,40,217,.05);
      --shadow-hover: 0 12px 40px rgba(109,40,217,.18), 0 4px 12px rgba(109,40,217,.08);
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
    body::before, body::after {
      content: '';
      position: fixed; width: 500px; height: 500px;
      border-radius: 50%; filter: blur(120px);
      opacity: .35; z-index: -1; pointer-events: none;
      animation: blobFloat 18s ease-in-out infinite;
    }
    body::before { background: radial-gradient(circle, #c4b5fd, transparent 70%); top: -150px; left: -150px; }
    body::after  { background: radial-gradient(circle, #fbcfe8, transparent 70%); bottom: -150px; right: -150px; animation-delay: -9s; }
    @keyframes blobFloat {
      0%,100% { transform: translate(0,0) scale(1); }
      33%     { transform: translate(60px,-40px) scale(1.1); }
      66%     { transform: translate(-40px,60px) scale(.95); }
    }

    .cust-page { max-width: 1180px; margin: 0 auto; padding: 24px 24px 80px; position: relative; z-index: 1; }

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


    /* ── Hero / Page header ── */
    .hero {
      position: relative; overflow: hidden; border-radius: 28px;
      padding: 40px 44px; margin-bottom: 24px;
      background: var(--grad-hero);
      border: 1.5px solid rgba(255,255,255,.8);
      box-shadow: var(--shadow-card);
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) both;
    }
    .hero-eyebrow {
      display: inline-block; padding: 5px 14px;
      background: rgba(255,255,255,.75); border: 1px solid rgba(255,255,255,.9);
      border-radius: 99px; font-size: .72rem; font-weight: 700;
      letter-spacing: 1.2px; text-transform: uppercase;
      color: var(--c-accent-deep); margin-bottom: 14px;
    }
    .hero h2 {
      font-family: var(--font-display); font-size: 2.2rem; font-weight: 800;
      line-height: 1.1; color: var(--c-text);
    }
    .hero h2 .accent {
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text;
      color: transparent; font-style: italic;
    }
    .hero p { color: var(--c-muted); margin-top: 10px; font-size: 1rem; max-width: 540px; }

    /* ── Stats row ── */
    .stats-row {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 14px; margin-top: 22px;
    }
    .stat {
      background: rgba(255,255,255,.85); backdrop-filter: blur(8px);
      border: 1.5px solid rgba(255,255,255,.9);
      padding: 14px 18px; border-radius: 16px;
    }
    .stat-label { text-align: center; font-size: .9rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: var(--c-muted); }
    .stat-value {
      text-align: center;
      font-family: var(--font-display); font-size: 1.6rem; font-weight: 800;
      margin-top: 4px;
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text; color: transparent;
    }

    /* ── Status filter tabs ── */
    .filter-row {
      display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 22px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .15s both;
    }
    .filter-tab {
      padding: 8px 16px; border-radius: 99px; font-weight: 700; font-size: .82rem;
      border: 1.5px solid var(--c-border); color: var(--c-muted);
      background: rgba(255,255,255,.7); backdrop-filter: blur(8px);
      text-decoration: none; transition: all .25s; white-space: nowrap;
      display: inline-flex; align-items: center; gap: 8px;
    }
    .filter-tab:hover { border-color: var(--c-accent-mid); color: var(--c-accent); transform: translateY(-2px); }
    .filter-tab.active {
      border-color: transparent; background: var(--grad-primary); color: #fff;
      box-shadow: 0 6px 18px rgba(124,58,237,.35);
    }
    .filter-count {
      font-size: .72rem; padding: 1px 8px; border-radius: 99px;
      background: rgba(0,0,0,.06); font-weight: 700;
    }
    .filter-tab.active .filter-count { background: rgba(255,255,255,.25); color: #fff; }

    /* ── Alerts ── */
    .alert {
      padding: 14px 18px; border-radius: 14px; margin-bottom: 16px;
      font-weight: 600; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both;
    }
    .alert-success { background: var(--c-ok-bg); color: #14532d; border: 1px solid #86efac; }
    .alert-danger  { background: var(--c-err-bg); color: #7f1d1d; border: 1px solid #fecaca; }
    .alert .pill-link {
      margin-left: auto; padding: 6px 12px; border-radius: 99px;
      background: rgba(255,255,255,.7); color: var(--c-accent-deep);
      text-decoration: none; font-weight: 700; font-size: .82rem;
      border: 1px solid rgba(0,0,0,.05);
    }
    .alert .pill-link:hover { background: #fff; }

    /* ── Order card ── */
    .orders-list { display: flex; flex-direction: column; gap: 18px; }
    .order-card {
      background: rgba(255,255,255,.9); backdrop-filter: blur(12px);
      border: 1.5px solid rgba(255,255,255,.8);
      border-radius: var(--r-card);
      box-shadow: var(--shadow-card);
      overflow: hidden;
      transition: transform .3s cubic-bezier(.22,1,.36,1), box-shadow .3s;
      animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both;
    }
    .order-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }

    .order-head {
      display: flex; justify-content: space-between; align-items: flex-start;
      gap: 16px; padding: 20px 24px;
      border-bottom: 1.5px dashed var(--c-border);
      flex-wrap: wrap;
    }
    .order-head-left .order-id {
      font-family: var(--font-head); font-weight: 800; font-size: 1.1rem;
      color: var(--c-text); display: flex; align-items: center; gap: 8px;
    }
    .order-head-left .order-id .hash {
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .order-meta { color: var(--c-muted); font-size: .85rem; margin-top: 4px; display: flex; gap: 12px; flex-wrap: wrap; }
    .order-meta span { display: inline-flex; align-items: center; gap: 5px; }

    .order-head-right { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
    .order-total {
      font-family: var(--font-display); font-size: 1.5rem; font-weight: 800;
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .status-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 12px; border-radius: 99px; font-size: .72rem; font-weight: 800;
      letter-spacing: .5px; text-transform: uppercase;
    }
    .status-pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    .status-pending    { background: var(--c-warn-bg); color: var(--c-warn); }
    .status-processing { background: var(--c-info-bg); color: var(--c-info); }
    .status-completed  { background: var(--c-ok-bg);   color: var(--c-ok); }
    .status-cancelled  { background: var(--c-err-bg);  color: var(--c-err); }

    /* ── Items list inside card ── */
    .order-items { padding: 16px 24px; display: flex; flex-direction: column; gap: 12px; }
    .order-item {
      display: grid;
      grid-template-columns: 64px 1fr auto auto;
      gap: 14px; align-items: center;
      padding: 10px; border-radius: 14px;
      transition: background .2s;
    }
    .order-item:hover { background: var(--c-accent-light); }
    .order-item .thumb {
      width: 64px; height: 64px; border-radius: 12px; overflow: hidden;
      background: linear-gradient(135deg, #ede9fe, #fce7f3);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; flex-shrink: 0;
      border: 1px solid var(--c-border);
    }
    .order-item .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .order-item .info .name { font-weight: 700; font-size: .98rem; color: var(--c-text); }
    .order-item .info .variant { font-size: .8rem; color: var(--c-muted); margin-top: 2px; display: flex; gap: 10px; flex-wrap: wrap; }
    .order-item .qty { font-weight: 700; color: var(--c-accent-deep); font-size: .9rem; white-space: nowrap; }
    .order-item .price { font-weight: 800; color: var(--c-text); font-size: .98rem; white-space: nowrap; }

    .review-btn {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 12px; border-radius: 99px;
      background: var(--c-ok-bg); color: var(--c-ok);
      text-decoration: none; font-weight: 700; font-size: .78rem;
      border: 1px solid #86efac; transition: all .2s;
      margin-left: 8px;
    }
    .review-btn:hover { background: var(--c-ok); color: #fff; transform: translateY(-1px); }

    /* ── Card footer actions ── */
    .order-foot {
      padding: 14px 24px; background: rgba(245,243,255,.5);
      border-top: 1.5px dashed var(--c-border);
      display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: var(--r-btn);
      font-weight: 700; font-size: .85rem; text-decoration: none;
      border: none; cursor: pointer; transition: all .25s;
      font-family: var(--font-body);
    }
    .btn-primary {
      background: var(--grad-primary); color: #fff;
      box-shadow: 0 4px 14px rgba(124,58,237,.3);
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(124,58,237,.45); }
    .btn-ghost {
      background: rgba(255,255,255,.85); color: var(--c-text);
      border: 1.5px solid var(--c-border);
    }
    .btn-ghost:hover { border-color: var(--c-accent-mid); color: var(--c-accent); }
    .btn-danger {
      background: #fff; color: var(--c-err);
      border: 1.5px solid #fecaca;
    }
    .btn-danger:hover { background: var(--c-err); color: #fff; border-color: var(--c-err); }

    /* ── Empty state ── */
    .empty-state {
      text-align: center; padding: 70px 24px; color: var(--c-muted);
      background: rgba(255,255,255,.6);
      border: 1.5px dashed var(--c-border-strong);
      border-radius: 24px;
    }
    .empty-state .em-ico { font-size: 3.5rem; margin-bottom: 14px; opacity: .6; }
    .empty-state h3 { font-family: var(--font-display); font-size: 1.4rem; color: var(--c-text); margin-bottom: 8px; }
    .empty-state p { margin-bottom: 22px; }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

    /* ── Modals ── */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(30,27,75,0.5); backdrop-filter: blur(8px);
      z-index: 1000; display: flex; align-items: center; justify-content: center;
      opacity: 0; pointer-events: none; transition: opacity .25s; padding: 20px;
    }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal-box {
      background: rgba(255,255,255,.97); backdrop-filter: blur(20px);
      padding: 32px; border-radius: 24px; width: 100%; max-width: 420px; text-align: center;
      box-shadow: 0 24px 60px rgba(30,27,75,.25);
      transform: translateY(30px) scale(.95); transition: transform .35s cubic-bezier(.22,1,.36,1);
      border: 1.5px solid rgba(255,255,255,.8);
    }
    .modal-overlay.open .modal-box { transform: translateY(0) scale(1); }
    .modal-icon { font-size: 3rem; margin-bottom: 12px; }
    .modal-box h3 { font-family: var(--font-display); font-size: 1.5rem; margin-bottom: 10px; }
    .modal-box p { color: var(--c-muted); margin-bottom: 24px; line-height: 1.5; }
    .btn-row { display: flex; gap: 12px; }
    .btn-row .btn { flex: 1; justify-content: center; padding: 13px; }

    /* ── Responsive ── */
    @media (max-width: 720px) {
      .topbar { flex-direction: column; gap: 12px; padding: 14px; position: static; }
      .topbar-nav { flex-wrap: wrap; justify-content: center; }
      .hero { padding: 28px 22px; }
      .hero h2 { font-size: 1.7rem; }
      .order-head { padding: 16px; }
      .order-items { padding: 12px 16px; }
      .order-foot { padding: 12px 16px; justify-content: stretch; }
      .order-foot .btn { flex: 1; justify-content: center; }
      .order-item { grid-template-columns: 56px 1fr; }
      .order-item .thumb { width: 56px; height: 56px; }
      .order-item .qty, .order-item .price { grid-column: 2; text-align: left; }
      .order-head-right { align-items: flex-start; text-align: left; }
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
        <a href="cart.php">🛒 Cart</a>
        <a href="orders.php" class="active">My Orders</a>
        <a href="profile.php">Profile</a>
        <a href="#" class="danger" onclick="openLogoutModal(); return false;">Logout</a>
      </div>
    </div>
    <!-- HERO -->
    <section class="hero">
      <span class="hero-eyebrow">Order History</span>
      <h2>Your <span class="accent">orders</span>, beautifully tracked.</h2>
      <p>Review your purchases, follow their progress, and grab receipts whenever you need them.</p>

      <div class="stats-row">
        <div class="stat">
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= (int)$counts['all'] ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">In Progress</div>
          <div class="stat-value"><?= (int)($counts['Pending'] + $counts['Processing']) ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">Completed</div>
          <div class="stat-value"><?= (int)$counts['Completed'] ?></div>
        </div>
        <div class="stat">
          <div class="stat-label">Total Spent</div>
          <div class="stat-value">₱<?= number_format($totalSpent, 2) ?></div>
        </div>
      </div>
    </section>

    <!-- ALERTS -->
    <?php if ($successOrderId): ?>
      <div class="alert alert-success">
        Order #<?= (int)$successOrderId ?> placed successfully! We'll process it soon.
        <a href="../receipt.php?order_id=<?= (int)$successOrderId ?>" target="_blank" class="pill-link">View Receipt</a>
      </div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
      <div class="alert alert-danger">⚠️ <?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <!-- STATUS TABS -->
    <div class="filter-row">
      <?php
        $tabs = [
          'all'        => ['label' => 'All'],
          'Pending'    => ['label' => 'Pending'],
          'Processing' => ['label' => 'Processing'],
          'Completed'  => ['label' => 'Completed'],
          'Cancelled'  => ['label' => 'Cancelled'],
        ];
        foreach ($tabs as $key => $meta):
          $active = $statusFilter === $key ? 'active' : '';
      ?>
        <a href="?status=<?= urlencode($key) ?>" class="filter-tab <?= $active ?>">
          <span><?= $meta['label'] ?></span>
          <span class="filter-count"><?= (int)$counts[$key] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ORDERS LIST -->
    <?php if (!empty($ordersToShow)): ?>
      <div class="orders-list">
        <?php foreach ($ordersToShow as $row):
          $items = $itemsByOrder[$row['id']] ?? [];
          $firstItemName = !empty($items) ? ($items[0]['item_name'] ?? 'this order') : 'this order';
          $statusClass = 'status-' . strtolower($row['status']);
        ?>
          <div class="order-card">
            <div class="order-head">
              <div class="order-head-left">
                <div class="order-id"><span class="hash">#</span>Order <?= (int)$row['id'] ?></div>
                <div class="order-meta">
                  <span>📅 <?= date('M d, Y', strtotime($row['created_at'])) ?></span>
                  <span>🕒 <?= date('g:i A', strtotime($row['created_at'])) ?></span>
                  <span>📦 <?= (int)$row['item_count'] ?> item<?= $row['item_count'] == 1 ? '' : 's' ?></span>
                </div>
              </div>
              <div class="order-head-right">
                <div class="order-total">₱<?= number_format($row['total_amount'], 2) ?></div>
                <span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></span>
              </div>
            </div>

            <?php if (!empty($items)): ?>
              <div class="order-items">
                <?php foreach ($items as $it):
                  $imgUrl = dbImageToDataUrl($it['profile_image']);
                ?>
                  <div class="order-item">
                    <div class="thumb">
                      <?php if ($imgUrl): ?>
                        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($it['item_name']) ?>">
                      <?php else: ?>
                        <span>📷</span>
                      <?php endif; ?>
                    </div>
                    <div class="info">
                      <div class="name"><?= htmlspecialchars($it['item_name']) ?></div>
                      <div class="variant">
                        <?php if (!empty($it['color'])): ?><span><?= htmlspecialchars($it['color']) ?></span><?php endif; ?>
                        <?php if (!empty($it['size'])): ?><span><?= htmlspecialchars($it['size']) ?></span><?php endif; ?>
                      </div>
                    </div>
                    <div class="qty">×<?= (int)$it['quantity'] ?></div>
                    <div class="price">
                      ₱<?= number_format($it['unit_price'], 2) ?>
                      <?php if ($row['status'] === 'Completed'): ?>
                        <a href="#" onclick="openReviewModal(<?= (int)$it['item_id'] ?>, <?= (int)$row['id'] ?>); return false;"
                          class="review-btn">⭐ Review</a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="order-foot">
              <?php if ($row['status'] === 'Pending'): ?>
                <button type="button" class="btn btn-danger cancel-order-btn"
                  data-order-id="<?= (int)$row['id'] ?>"
                  data-item-name="<?= htmlspecialchars($firstItemName) ?>">
                  Cancel Order
                </button>
              <?php endif; ?>
              <?php if ($row['status'] === 'Completed'): ?>
                <a href="../receipt.php?order_id=<?= (int)$row['id'] ?>" target="_blank" class="btn btn-ghost">
                  Download Receipt
                </a>
              <?php endif; ?>
              <a href="home.php" class="btn btn-primary">Shop Again</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <div class="em-ico"></div>
        <h3><?= $statusFilter === 'all' ? 'No orders yet' : 'No ' . htmlspecialchars($statusFilter) . ' orders' ?></h3>
        <p><?= $statusFilter === 'all'
              ? "Looks like you haven't placed any orders. Let's change that!"
              : "You don't have any orders in this category right now." ?></p>
        <a href="home.php" class="btn btn-primary">Start Shopping</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- CANCEL MODAL -->
  <div class="modal-overlay" id="cancelModal">
    <div class="modal-box">
      <h3>Cancel this order?</h3>
      <p>Are you sure you want to cancel <strong id="cancelModalItemName">this order</strong>? This action cannot be undone.</p>
      <form method="POST" action="orders.php" id="cancelModalForm">
        <input type="hidden" name="order_id" id="cancelModalOrderId" value="">
        <input type="hidden" name="cancel_order" value="1">
        <div class="btn-row">
          <button type="button" class="btn btn-ghost" id="cancelModalDismiss">Keep order</button>
          <button type="submit" class="btn btn-danger">Yes, cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- LOGOUT MODAL -->
  <div class="modal-overlay" id="logoutModal">
    <div class="modal-box">
      <div class="modal-icon"></div>
      <h3>Log Out?</h3>
      <p>Are you sure you want to log out of your account?</p>
      <div class="btn-row">
        <button class="btn btn-ghost" onclick="closeLogoutModal()">Cancel</button>
        <a href="../../logout.php" class="btn btn-danger">Yes, Log Out</a>
      </div>
    </div>
  </div>

    <!-- REVIEW MODAL -->
  <div class="modal-overlay" id="reviewModal">
    <div class="modal-box" style="max-width:900px; width:95%; height:85vh; padding:0; overflow:hidden;">
      <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 22px; border-bottom:1.5px solid #ede9fe;">
        <span style="font-weight:800; font-size:1rem;">Leave a Review</span>
        <button onclick="closeReviewModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;">✕</button>
      </div>
      <iframe id="reviewIframe" src="" style="width:100%; height:calc(100% - 55px); border:none;"></iframe>
    </div>
  </div>

<script>
  /* Cancel modal */
  const cancelModal = document.getElementById('cancelModal');
  const cancelModalDismiss = document.getElementById('cancelModalDismiss');
  const cancelModalOrderId = document.getElementById('cancelModalOrderId');
  const cancelModalItemName = document.getElementById('cancelModalItemName');

  document.querySelectorAll('.cancel-order-btn').forEach(button => {
    button.addEventListener('click', () => {
      cancelModalOrderId.value = button.dataset.orderId;
      cancelModalItemName.textContent = button.dataset.itemName || 'this order';
      cancelModal.classList.add('open');
    });
  });
  const hideCancelModal = () => cancelModal.classList.remove('open');
  cancelModalDismiss.addEventListener('click', hideCancelModal);
  cancelModal.addEventListener('click', e => { if (e.target === cancelModal) hideCancelModal(); });

  /* Logout modal */
  function openLogoutModal()  { document.getElementById('logoutModal').classList.add('open'); }
  function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('open'); }
  document.getElementById('logoutModal').addEventListener('click', e => {
    if (e.target.id === 'logoutModal') closeLogoutModal();
  });

  /* ESC closes modals */
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { hideCancelModal(); closeLogoutModal(); }
  });



  function openReviewModal(itemId, orderId) {
  document.getElementById('reviewIframe').src =
    `product_reviews.php?item_id=${itemId}&order_id=${orderId}&embed=1`;
  document.getElementById('reviewModal').classList.add('open');
  }
  function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('open');
    document.getElementById('reviewIframe').src = '';
  }
  document.getElementById('reviewModal').addEventListener('click', e => {
    if (e.target.id === 'reviewModal') closeReviewModal();
  });
</script>
</body>
</html>
