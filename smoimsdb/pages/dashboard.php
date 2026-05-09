<?php
/* ============================================================
   FILE: pages/staff/dashboard.php
   SMOIMS Staff Dashboard — Polished, uses includes/sidebar.php
   ============================================================ */

require_once '../includes/config.php';
requireStaffLogin();

/* ---------------- Core KPIs ---------------- */
$totalOrders     = (int)($conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'] ?? 0);
$totalRevenue    = (float)($conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM orders WHERE status='Completed'")->fetch_assoc()['t'] ?? 0);
$totalExpenses   = (float)($conn->query("SELECT COALESCE(SUM(amount),0) t FROM expenses")->fetch_assoc()['t'] ?? 0);
$netProfit       = $totalRevenue - $totalExpenses;
$newOrdersToday  = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0);
$lowStockCount   = (int)($conn->query("SELECT COUNT(*) c FROM inventory WHERE quantity < 10")->fetch_assoc()['c'] ?? 0);
$totalCustomers  = (int)($conn->query("SELECT COUNT(*) c FROM customers")->fetch_assoc()['c'] ?? 0);
$pendingOrders   = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
$processingOrders= (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Processing'")->fetch_assoc()['c'] ?? 0);
$completedOrders = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Completed'")->fetch_assoc()['c'] ?? 0);
$cancelledOrders = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Cancelled'")->fetch_assoc()['c'] ?? 0);
$avgOrderValue   = $completedOrders > 0 ? $totalRevenue / $completedOrders : 0;
$avgRating       = (float)($conn->query("SELECT COALESCE(AVG(rating),0) r FROM reviews")->fetch_assoc()['r'] ?? 0);
$totalReviews    = (int)($conn->query("SELECT COUNT(*) c FROM reviews")->fetch_assoc()['c'] ?? 0);
$cartActiveItems = (int)($conn->query("SELECT COALESCE(SUM(quantity),0) c FROM cart")->fetch_assoc()['c'] ?? 0);

/* Trend deltas vs previous 30-day window */
$rev30  = (float)($conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM orders WHERE status='Completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['t']);
$revPrev= (float)($conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM orders WHERE status='Completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['t']);
$revDelta = $revPrev > 0 ? (($rev30 - $revPrev) / $revPrev) * 100 : ($rev30 > 0 ? 100 : 0);

$ord30  = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['c']);
$ordPrev= (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['c']);
$ordDelta = $ordPrev > 0 ? (($ord30 - $ordPrev) / $ordPrev) * 100 : ($ord30 > 0 ? 100 : 0);

/* ---------------- Revenue (last 14 days) ---------------- */
$revRows = $conn->query("
  SELECT DATE(created_at) d, COALESCE(SUM(total_amount),0) t, COUNT(*) c
  FROM orders
  WHERE status='Completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
  GROUP BY DATE(created_at)
");
$revMap = [];
while ($r = $revRows->fetch_assoc()) { $revMap[$r['d']] = ['t' => (float)$r['t'], 'c' => (int)$r['c']]; }
$chartLabels = []; $chartRevenue = []; $chartOrders = [];
for ($i = 13; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-$i day"));
  $chartLabels[]  = date('M j', strtotime($d));
  $chartRevenue[] = $revMap[$d]['t'] ?? 0;
  $chartOrders[]  = $revMap[$d]['c'] ?? 0;
}

/* ---------------- Lists ---------------- */
$recentOrders = $conn->query("
  SELECT o.id, c.full_name, c.organization, o.total_amount, o.status, o.created_at
  FROM orders o JOIN customers c ON o.customer_id = c.id
  ORDER BY o.created_at DESC LIMIT 8
");
$topProducts = $conn->query("
  SELECT i.item_name, i.category, i.price, i.quantity AS stock,
         COALESCE(SUM(oi.quantity),0) AS sold,
         COALESCE(SUM(oi.quantity * oi.unit_price),0) AS revenue
  FROM inventory i
  LEFT JOIN order_items oi ON oi.item_id = i.id
  LEFT JOIN orders o ON o.id = oi.order_id AND o.status='Completed'
  GROUP BY i.id ORDER BY sold DESC, revenue DESC LIMIT 5
");
$lowStockItems = $conn->query("
  SELECT id, item_name, category, quantity, price
  FROM inventory WHERE quantity < 10 ORDER BY quantity ASC LIMIT 6
");
$topCustomers = $conn->query("
  SELECT c.full_name, c.organization, COUNT(o.id) AS orders_count,
         COALESCE(SUM(o.total_amount),0) AS spent
  FROM customers c
  LEFT JOIN orders o ON o.customer_id = c.id AND o.status='Completed'
  GROUP BY c.id ORDER BY spent DESC, orders_count DESC LIMIT 5
");
$recentReviews = $conn->query("
  SELECT r.rating, r.comment, r.created_at, c.full_name, i.item_name
  FROM reviews r
  JOIN customers c ON r.customer_id = c.id
  LEFT JOIN inventory i ON i.id = r.product_id
  ORDER BY r.created_at DESC LIMIT 4
");
$expenseBreakdown = $conn->query("
  SELECT category, SUM(amount) total FROM expenses
  GROUP BY category ORDER BY total DESC LIMIT 5
");
$newCustomers = (int)($conn->query("SELECT COUNT(*) c FROM customers WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['c'] ?? 0);

$statusTotal = max(1, $pendingOrders + $processingOrders + $completedOrders + $cancelledOrders);

function pct($a, $b) { return $b > 0 ? round(($a / $b) * 100) : 0; }
function trendIcon($v) { return $v >= 0 ? '▲' : '▼'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ============ Design Tokens ============ */
    :root {
      --pastel-accent:#a78bfa; --accent-2:#f0abfc; --accent-3:#818cf8;
      --ink:#1a1a2e; --ink-soft:#4b5563; --muted:#6b7280;
      --bg:#fbfaff; --surface:#ffffff; --surface-2:#f6f4ff;
      --border:rgba(167,139,250,.18);
      --success:#10b981; --warning:#f59e0b; --danger:#ef4444; --info:#0ea5e9;
      --shadow-sm:0 4px 14px rgba(80,60,160,.08);
      --shadow-md:0 14px 40px rgba(80,60,160,.14);
      --shadow-lg:0 30px 70px rgba(80,60,160,.22);
      --grad: #564586;;
      --grad-soft:linear-gradient(135deg,#ede9fe 0%,#fce7f3 100%);
      --radius:18px;
      --ease:cubic-bezier(.22,1,.36,1);
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
      display:none;background: #564586;border:1px solid var(--border);
      padding:10px 14px;border-radius:12px;font-weight:700;cursor:pointer;
      box-shadow:var(--shadow-sm);
    }
    @media (max-width:768px){.menu-toggle{display:inline-flex}}

    /* Page header */
    .page-header{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;margin-bottom:32px;animation:fade-up .55s var(--ease) both}
    .page-header h1{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3vw,2.5rem);font-weight:800;line-height:1.1;margin-bottom:6px}
    .page-header .grad-text{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
    .page-header p{color:var(--muted);font-size:.95rem}
    .date-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:var(--surface);border:1px solid var(--border);border-radius:999px;font-weight:600;font-size:.88rem;color:var(--ink-soft);box-shadow:var(--shadow-sm);transition:transform .25s var(--ease),box-shadow .25s var(--ease)}
    .date-pill:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
    .date-pill::before{content:'';width:8px;height:8px;background:var(--grad);border-radius:50%;animation:fade-in 1.2s var(--ease) infinite alternate}

    /* KPI grid */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:28px}
    .stat-card{
      position:relative;background:var(--surface);border:1px solid var(--border);
      border-radius:var(--radius);padding:22px;overflow:hidden;
      transition:transform .4s var(--ease),box-shadow .4s var(--ease),border-color .3s var(--ease);
      animation:pop-in .5s var(--ease) both;
    }
    .stat-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-md);border-color:rgba(167,139,250,.4)}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--grad);opacity:.85;transition:height .3s var(--ease)}
    .stat-card:hover::before{height:5px}
    .stat-card::after{
      content:'';position:absolute;inset:auto -30% -60% auto;
      width:180px;height:180px;border-radius:50%;
      background:var(--grad-soft);opacity:.45;
      transition:transform .6s var(--ease);
      pointer-events:none;
    }
    .stat-card:hover::after{transform:scale(1.15) translate(-10px,-10px)}
    .stat-card.is-revenue::before{background:linear-gradient(90deg,#10b981,#34d399)}
    .stat-card.is-profit::before{background:linear-gradient(90deg,#0ea5e9,#818cf8)}
    .stat-card.is-warn::before{background:linear-gradient(90deg,#f59e0b,#f0abfc)}
    .stat-card.is-danger::before{background:linear-gradient(90deg,#ef4444,#f0abfc)}
    .stat-head{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .stat-icon{width:44px;height:44px;border-radius:12px;background:var(--grad-soft);display:flex;align-items:center;justify-content:center;font-size:1.25rem;transition:transform .35s var(--ease)}
    .stat-card:hover .stat-icon{transform:rotate(-8deg) scale(1.08)}
    .stat-trend{font-size:.78rem;font-weight:700;padding:4px 10px;border-radius:999px;display:inline-flex;align-items:center;gap:4px}
    .trend-up{background:rgba(16,185,129,.12);color:var(--success)}
    .trend-down{background:rgba(239,68,68,.12);color:var(--danger)}
    .stat-value{text-align:center;position:relative;z-index:1;font-family:'Playfair Display',serif;font-size:1.95rem;font-weight:800;line-height:1.1}
    .stat-label{text-align:center;position:relative;z-index:1;color:var(--muted);font-size:.82rem;font-weight:600;margin-top:6px;text-transform:uppercase;letter-spacing:.07em}
    .stat-sub{text-align:center;position:relative;z-index:1;margin-top:10px;font-size:.78rem;color:var(--ink-soft)}

    /* stagger KPI animation */
    .stats-grid .stat-card:nth-child(1){animation-delay:.05s}
    .stats-grid .stat-card:nth-child(2){animation-delay:.10s}
    .stats-grid .stat-card:nth-child(3){animation-delay:.15s}
    .stats-grid .stat-card:nth-child(4){animation-delay:.20s}
    .stats-grid .stat-card:nth-child(5){animation-delay:.25s}
    .stats-grid .stat-card:nth-child(6){animation-delay:.30s}
    .stats-grid .stat-card:nth-child(7){animation-delay:.35s}
    .stats-grid .stat-card:nth-child(8){animation-delay:.40s}

    /* Cards */
    .card{
      background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
      padding:24px;box-shadow:var(--shadow-sm);
      transition:box-shadow .35s var(--ease),transform .35s var(--ease),border-color .3s var(--ease);
      animation:fade-up .5s var(--ease) both;
    }
    .card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);border-color:rgba(167,139,250,.32)}
    .card-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;font-size:1.05rem;font-weight:700}
    .card-title .icon{width:34px;height:34px;border-radius:10px;background:var(--grad);color:#fff;display:inline-flex;align-items:center;justify-content:center;margin-right:10px;font-size:.95rem;box-shadow:0 6px 16px rgba(167,139,250,.35)}
    .card-title-left{display:flex;align-items:center}

    /* Grid sections */
    .grid-2{display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px}
    .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-bottom:24px}
    @media(max-width:1024px){.grid-2,.grid-3{grid-template-columns:1fr}}

    /* Buttons / badges */
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:999px;font-weight:600;font-size:.85rem;border:none;cursor:pointer;transition:transform .25s var(--ease),box-shadow .25s var(--ease),background .25s var(--ease),color .25s var(--ease)}
    .btn-primary{background:var(--grad);color:#fff;box-shadow:0 6px 18px rgba(167,139,250,.4)}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(167,139,250,.55)}
    .btn-secondary{background:var(--surface-2);color:var(--ink);border:1px solid var(--border)}
    .btn-secondary:hover{background:#fff;border-color:var(--pastel-accent);color:var(--pastel-accent);transform:translateY(-2px)}
    .btn-sm{padding:6px 12px;font-size:.78rem}

    .badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;transition:transform .2s var(--ease)}
    .badge:hover{transform:scale(1.06)}
    .badge-pending{background:rgba(245,158,11,.14);color:#b45309}
    .badge-processing{background:rgba(14,165,233,.14);color:#0369a1}
    .badge-completed{background:rgba(16,185,129,.14);color:#047857}
    .badge-cancelled{background:rgba(239,68,68,.14);color:#b91c1c}

    /* Table */
    .table-wrapper{overflow-x:auto;border-radius:12px}
    table{width:100%;border-collapse:collapse;font-size:.9rem}
    th{text-align:left;padding:12px 14px;font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);background:var(--surface-2);border-bottom:1px solid var(--border)}
    td{padding:14px;border-bottom:1px solid var(--border);color:var(--ink-soft)}
    tr:last-child td{border-bottom:none}
    tbody tr{transition:background .25s var(--ease),transform .25s var(--ease)}
    tbody tr:hover{background:var(--surface-2);transform:translateX(2px)}
    td.strong{color:var(--ink);font-weight:600}
    .price{font-weight:700;background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}

    /* Chart */
    .chart-wrap{position:relative;height:280px}
    .chart-legend{display:flex;gap:16px;font-size:.8rem;color:var(--muted);margin-top:10px;flex-wrap:wrap}
    .legend-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:middle}

    /* Status bar */
    .status-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px dashed var(--border)}
    .status-row:last-child{border-bottom:none}
    .status-label{flex:0 0 110px;font-weight:600;font-size:.85rem}
    .status-bar{flex:1;height:9px;background:var(--surface-2);border-radius:999px;overflow:hidden}
    .status-fill{height:100%;border-radius:999px;width:0;transition:width 1.1s var(--ease)}
    .fill-pending{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
    .fill-processing{background:linear-gradient(90deg,#0ea5e9,#38bdf8)}
    .fill-completed{background:linear-gradient(90deg,#10b981,#34d399)}
    .fill-cancelled{background:linear-gradient(90deg,#ef4444,#f87171)}
    .status-val{flex:0 0 60px;text-align:right;font-weight:700;color:var(--ink);font-size:.9rem}

    /* Top product / customer rows */
    .lite-list{display:flex;flex-direction:column;gap:4px}
    .lite-row{display:flex;align-items:center;gap:14px;padding:12px;border-radius:12px;transition:background .25s var(--ease),transform .25s var(--ease)}
    .lite-row:hover{background:var(--surface-2);transform:translateX(4px)}
    .rank{width:30px;height:30px;border-radius:9px;background:var(--grad-soft);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--ink);font-size:.85rem;flex-shrink:0;transition:transform .25s var(--ease)}
    .lite-row:hover .rank{transform:rotate(-6deg) scale(1.08)}
    .lite-row:nth-child(1) .rank{background:var(--grad);color:#fff;box-shadow:0 4px 14px rgba(167,139,250,.4)}
    .lite-main{flex:1;min-width:0}
    .lite-title{font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .lite-sub{font-size:.78rem;color:var(--muted)}
    .lite-val{font-weight:700;color:var(--ink);text-align:right;white-space:nowrap}

    /* Reviews */
    .review-card{padding:14px;border-radius:12px;background:var(--surface-2);margin-bottom:10px;transition:transform .25s var(--ease),box-shadow .25s var(--ease)}
    .review-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
    .review-card:last-child{margin-bottom:0}
    .review-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
    .review-name{font-weight:700;font-size:.88rem}
    .stars{color:#f59e0b;font-size:.85rem;letter-spacing:1px}
    .review-meta{font-size:.72rem;color:var(--muted);margin-bottom:6px}
    .review-text{font-size:.85rem;color:var(--ink-soft);line-height:1.5}

    .empty{text-align:center;color:var(--muted);padding:32px;font-size:.9rem}


  </style>
</head>
<body>
<div class="layout">
  <?php @include '../includes/sidebar.php'; ?>
  <main class="main">

    <!-- Header -->
    <div class="page-header">
      <div>
        <button class="menu-toggle" onclick="document.getElementById('appSidebar')?.classList.toggle('open')">☰ Menu</button>
        <h1 style="margin-top:8px">Welcome to SOLISCO. Dashboard</h1>
        <p>Here's what's happening with SolisCo. today.</p>
      </div>
      <div class="date-pill"><?= date('l, F d, Y') ?></div>
    </div>

    <!-- KPI Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-head">
          <span class="stat-trend <?= $ordDelta>=0?'trend-up':'trend-down' ?>"><?= trendIcon($ordDelta) ?> <?= number_format(abs($ordDelta),1) ?>%</span>
        </div>
        <div class="stat-value"><?= number_format($totalOrders) ?></div>
        <div class="stat-label">Total Orders</div>
        <div class="stat-sub"><?= $newOrdersToday ?> new today</div>
      </div>

      <div class="stat-card is-revenue">
        <div class="stat-head">
          <span class="stat-trend <?= $revDelta>=0?'trend-up':'trend-down' ?>"><?= trendIcon($revDelta) ?> <?= number_format(abs($revDelta),1) ?>%</span>
        </div>
        <div class="stat-value">₱<?= number_format($totalRevenue,0) ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-sub">Avg order ₱<?= number_format($avgOrderValue,0) ?></div>
      </div>

      <div class="stat-card is-profit">
        <div class="stat-head">
          <span class="stat-trend <?= $netProfit>=0?'trend-up':'trend-down' ?>"><?= $netProfit>=0?'▲ Profit':'▼ Loss' ?></span>
        </div>
        <div class="stat-value">₱<?= number_format($netProfit,0) ?></div>
        <div class="stat-label">Net Profit</div>
        <div class="stat-sub">Expenses ₱<?= number_format($totalExpenses,0) ?></div>
      </div>

      <div class="stat-card is-warn">
        <div class="stat-head">
          <span class="stat-trend trend-up"><?= $pendingOrders ?> open</span>
        </div>
        <div class="stat-value"><?= $pendingOrders + $processingOrders ?></div>
        <div class="stat-label">Active Orders</div>
        <div class="stat-sub"><?= $processingOrders ?> processing · <?= $pendingOrders ?> pending</div>
      </div>

      <div class="stat-card is-danger">
        <div class="stat-head">
          <span class="stat-trend trend-down">Restock</span>
        </div>
        <div class="stat-value"><?= $lowStockCount ?></div>
        <div class="stat-label">Low Stock</div>
        <div class="stat-sub">Items below 10 units</div>
      </div>

      <div class="stat-card">
        <div class="stat-head">
          <span class="stat-trend trend-up">+<?= $newCustomers ?></span>
        </div>
        <div class="stat-value"><?= number_format($totalCustomers) ?></div>
        <div class="stat-label">Customers</div>
        <div class="stat-sub"><?= $newCustomers ?> joined this month</div>
      </div>

      <div class="stat-card">
        <div class="stat-head">
          <span class="stat-trend trend-up"><?= $totalReviews ?> reviews</span>
        </div>
        <div class="stat-value"><?= number_format($avgRating,1) ?>/5</div>
        <div class="stat-label">Avg Rating</div>
        <div class="stat-sub">Customer satisfaction</div>
      </div>

      <div class="stat-card">
        <div class="stat-head">
          <span class="stat-trend trend-up">Active</span>
        </div>
        <div class="stat-value"><?= $cartActiveItems ?></div>
        <div class="stat-label">Items in Carts</div>
        <div class="stat-sub">Pending checkout</div>
      </div>
    </div>

    <!-- Revenue chart + Status -->
    <div class="grid-2">
      <div class="card">
        <div class="card-title">
          <div class="card-title-left"><span class="icon"></span>Revenue · Last 14 days</div>
          <a href="reports.php" class="btn btn-secondary btn-sm">Full report →</a>
        </div>
        <div class="chart-wrap"><canvas id="revenueChart"></canvas></div>
        <div class="chart-legend">
          <span><span class="legend-dot" style="background:var(--grad)"></span>Revenue (₱)</span>
          <span><span class="legend-dot" style="background:#10b981"></span>Orders</span>
        </div>
      </div>

      <div class="card">
        <div class="card-title">
          <div class="card-title-left"><span class="icon"></span>Order Status</div>
        </div>
        <div class="status-row">
          <div class="status-label">Pending</div>
          <div class="status-bar"><div class="status-fill fill-pending" data-w="<?= pct($pendingOrders,$statusTotal) ?>"></div></div>
          <div class="status-val"><?= $pendingOrders ?></div>
        </div>
        <div class="status-row">
          <div class="status-label">Processing</div>
          <div class="status-bar"><div class="status-fill fill-processing" data-w="<?= pct($processingOrders,$statusTotal) ?>"></div></div>
          <div class="status-val"><?= $processingOrders ?></div>
        </div>
        <div class="status-row">
          <div class="status-label">Completed</div>
          <div class="status-bar"><div class="status-fill fill-completed" data-w="<?= pct($completedOrders,$statusTotal) ?>"></div></div>
          <div class="status-val"><?= $completedOrders ?></div>
        </div>
        <div class="status-row">
          <div class="status-label">Cancelled</div>
          <div class="status-bar"><div class="status-fill fill-cancelled" data-w="<?= pct($cancelledOrders,$statusTotal) ?>"></div></div>
          <div class="status-val"><?= $cancelledOrders ?></div>
        </div>


        </div>
      </div>
    </div>

    <!-- Recent Orders -->
    <div class="card" style="margin-bottom:24px">
      <div class="card-title">
        <div class="card-title-left"><span class="icon"></span>Recent Orders</div>
        <a href="orders.php" class="btn btn-secondary btn-sm">View all →</a>
      </div>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>#</th><th>Customer</th><th>Organization</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
          <tbody>
          <?php if ($recentOrders && $recentOrders->num_rows > 0): while ($r = $recentOrders->fetch_assoc()): ?>
            <tr>
              <td class="strong">#<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
              <td class="strong"><?= htmlspecialchars($r['full_name']) ?></td>
              <td><?= htmlspecialchars($r['organization'] ?: '—') ?></td>
              <td><span class="price">₱<?= number_format($r['total_amount'],2) ?></span></td>
              <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
              <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
              <td><a href="orders.php?view=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="7" class="empty">No orders yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Top Products / Top Customers / Low Stock -->
    <div class="grid-3">
      <div class="card">
        <div class="card-title"><div class="card-title-left"><span class="icon">🏆</span>Top Products</div></div>
        <div class="lite-list">
          <?php if ($topProducts && $topProducts->num_rows > 0): $i=1; while ($p = $topProducts->fetch_assoc()): ?>
            <div class="lite-row">
              <div class="rank"><?= $i++ ?></div>
              <div class="lite-main">
                <div class="lite-title"><?= htmlspecialchars($p['item_name']) ?></div>
                <div class="lite-sub"><?= htmlspecialchars($p['category'] ?: 'Uncategorized') ?> · <?= (int)$p['sold'] ?> sold</div>
              </div>
              <div class="lite-val">₱<?= number_format($p['revenue'],0) ?></div>
            </div>
          <?php endwhile; else: ?>
            <div class="empty">No sales data yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-title"><div class="card-title-left"><span class="icon"></span>Top Customers</div></div>
        <div class="lite-list">
          <?php if ($topCustomers && $topCustomers->num_rows > 0): $i=1; while ($c = $topCustomers->fetch_assoc()): ?>
            <div class="lite-row">
              <div class="rank"><?= $i++ ?></div>
              <div class="lite-main">
                <div class="lite-title"><?= htmlspecialchars($c['full_name']) ?></div>
                <div class="lite-sub"><?= htmlspecialchars($c['organization'] ?: 'Individual') ?> · <?= (int)$c['orders_count'] ?> orders</div>
              </div>
              <div class="lite-val">₱<?= number_format($c['spent'],0) ?></div>
            </div>
          <?php endwhile; else: ?>
            <div class="empty">No customer activity yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-title">
          <div class="card-title-left"><span class="icon">⚠️</span>Low Stock Alert</div>
          <a href="inventory.php" class="btn btn-secondary btn-sm">Manage</a>
        </div>
        <div class="lite-list">
          <?php if ($lowStockItems && $lowStockItems->num_rows > 0): while ($s = $lowStockItems->fetch_assoc()): ?>
            <div class="lite-row">
              <div class="rank" style="background:rgba(239,68,68,.15);color:#b91c1c"><?= (int)$s['quantity'] ?></div>
              <div class="lite-main">
                <div class="lite-title"><?= htmlspecialchars($s['item_name']) ?></div>
                <div class="lite-sub"><?= htmlspecialchars($s['category'] ?: 'Uncategorized') ?> · ₱<?= number_format($s['price'],2) ?></div>
              </div>
              <a href="inventory.php?edit=<?= $s['id'] ?>" class="btn btn-primary btn-sm">Restock</a>
            </div>
          <?php endwhile; else: ?>
            <div class="empty">All items well-stocked. </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Reviews + Expenses -->
    <div class="grid-2">
      <div class="card">
        <div class="card-title">
          <div class="card-title-left"><span class="icon"></span>Recent Reviews</div>
          <a href="reviews.php" class="btn btn-secondary btn-sm">All reviews →</a>
        </div>
        <?php if ($recentReviews && $recentReviews->num_rows > 0): while ($rv = $recentReviews->fetch_assoc()): ?>
          <div class="review-card">
            <div class="review-head">
              <div class="review-name"><?= htmlspecialchars($rv['full_name']) ?></div>
              <div class="stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5-(int)$rv['rating']) ?></div>
            </div>
            <div class="review-meta"><?= htmlspecialchars($rv['item_name'] ?: 'Product') ?> · <?= date('M d, Y', strtotime($rv['created_at'])) ?></div>
            <div class="review-text"><?= htmlspecialchars(mb_strimwidth($rv['comment'] ?? '', 0, 160, '…')) ?></div>
          </div>
        <?php endwhile; else: ?>
          <div class="empty">No reviews yet.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-title">
          <div class="card-title-left"><span class="icon"></span>Expense Breakdown</div>
          <a href="expenses.php" class="btn btn-secondary btn-sm">Manage</a>
        </div>
        <?php
        $expRows = [];
        if ($expenseBreakdown) while ($e = $expenseBreakdown->fetch_assoc()) $expRows[] = $e;
        $expMax = 0; foreach ($expRows as $e) $expMax = max($expMax, (float)$e['total']);
        ?>
        <?php if (count($expRows) > 0): foreach ($expRows as $e): ?>
          <div class="status-row">
            <div class="status-label" style="flex:0 0 130px"><?= htmlspecialchars($e['category']) ?></div>
            <div class="status-bar"><div class="status-fill" data-w="<?= pct($e['total'],$expMax) ?>" style="background:var(--grad)"></div></div>
            <div class="status-val">₱<?= number_format($e['total'],0) ?></div>
          </div>
        <?php endforeach; else: ?>
          <div class="empty">No expenses recorded.</div>
        <?php endif; ?>
        <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-weight:700">
          <span>Total Expenses</span><span style="background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent">₱<?= number_format($totalExpenses,2) ?></span>
        </div>
      </div>
    </div>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  // Animate progress bar fills after load
  window.addEventListener('load', () => {
    document.querySelectorAll('.status-fill[data-w]').forEach(el => {
      requestAnimationFrame(() => { el.style.width = el.dataset.w + '%'; });
    });
  });

  const labels  = <?= json_encode($chartLabels) ?>;
  const revenue = <?= json_encode($chartRevenue) ?>;
  const orders  = <?= json_encode($chartOrders) ?>;

  const ctx = document.getElementById('revenueChart').getContext('2d');
  const grad = ctx.createLinearGradient(0, 0, 0, 280);
  grad.addColorStop(0, 'rgba(167,139,250,.45)');
  grad.addColorStop(1, 'rgba(240,171,252,.05)');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Revenue (₱)',
          data: revenue,
          borderColor: '#a78bfa',
          backgroundColor: grad,
          borderWidth: 3,
          fill: true,
          tension: .4,
          pointRadius: 4,
          pointBackgroundColor: '#fff',
          pointBorderColor: '#a78bfa',
          pointBorderWidth: 2,
          pointHoverRadius: 7,
          yAxisID: 'y'
        },
        {
          label: 'Orders',
          data: orders,
          borderColor: '#10b981',
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [6, 4],
          tension: .4,
          pointRadius: 3,
          pointBackgroundColor: '#10b981',
          yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 1100, easing: 'easeOutQuart' },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1a1a2e',
          padding: 12,
          cornerRadius: 10,
          titleFont: { family: 'Plus Jakarta Sans', weight: '700' },
          bodyFont: { family: 'Plus Jakarta Sans' },
          callbacks: {
            label: (c) => c.dataset.yAxisID === 'y'
              ? ` Revenue: ₱${c.parsed.y.toLocaleString()}`
              : ` Orders: ${c.parsed.y}`
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#6b7280', font: { family: 'Plus Jakarta Sans' } } },
        y: {
          position: 'left',
          grid: { color: 'rgba(167,139,250,.1)' },
          ticks: { color: '#6b7280', font: { family: 'Plus Jakarta Sans' }, callback: v => '₱' + v.toLocaleString() }
        },
        y1: {
          position: 'right',
          grid: { display: false },
          ticks: { color: '#10b981', font: { family: 'Plus Jakarta Sans' }, stepSize: 1 }
        }
      }
    }
  });
</script>
</body>
</html>
