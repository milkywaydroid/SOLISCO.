<?php
/* ============================================================
   SMOIMS – Reports & Analytics (Full Redesign)
   FILE: pages/admin/reports.php
   ============================================================ */
require_once '../includes/config.php';
requireStaffLogin();

/* ════════════════════════════════════════════════════════
   AJAX ENDPOINTS
════════════════════════════════════════════════════════ */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    /* 1. Orders Bar Chart */
    if ($action === 'get_orders_data') {
        $filter = $_GET['filter'] ?? 'monthly';
        $labels = []; $counts = [];
        if ($filter === 'weekly') {
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $labels[] = date('D', strtotime($date));
                $res = $conn->query("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) = '$date'")->fetch_assoc();
                $counts[] = (int)($res['c'] ?? 0);
            }
        } elseif ($filter === 'yearly') {
            for ($i = 4; $i >= 0; $i--) {
                $year = date('Y') - $i;
                $labels[] = $year;
                $res = $conn->query("SELECT COUNT(*) as c FROM orders WHERE YEAR(created_at) = '$year'")->fetch_assoc();
                $counts[] = (int)($res['c'] ?? 0);
            }
        } else {
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $labels[] = date('M', strtotime($month));
                $res = $conn->query("SELECT COUNT(*) as c FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'")->fetch_assoc();
                $counts[] = (int)($res['c'] ?? 0);
            }
        }
        echo json_encode(['labels' => $labels, 'counts' => $counts]);
        exit;
    }

    /* 2. Product Performance */
    if ($action === 'get_product_data') {
        $productId = $_GET['product_id'] ?? 'all';
        $labels = []; $data = [];
        if ($productId === 'all') {
            $res = $conn->query("SELECT i.item_name, SUM(oi.quantity) as total FROM order_items oi JOIN inventory i ON oi.item_id = i.id GROUP BY oi.item_id ORDER BY total DESC LIMIT 10");
            while ($row = $res->fetch_assoc()) {
                $labels[] = $row['item_name'];
                $data[]   = (int)$row['total'];
            }
        } else {
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $labels[] = date('M', strtotime($month));
                $stmt = $conn->prepare("SELECT SUM(oi.quantity) as total FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.item_id = ? AND DATE_FORMAT(o.created_at,'%Y-%m') = ?");
                $stmt->bind_param("is", $productId, $month);
                $stmt->execute();
                $data[] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            }
        }
        echo json_encode(['labels' => $labels, 'data' => $data]);
        exit;
    }

    /* 3. Revenue Line Chart (NEW) */
    if ($action === 'get_revenue_data') {
        $filter = $_GET['filter'] ?? 'monthly';
        $labels = []; $revenue = []; $expenses = [];
        if ($filter === 'weekly') {
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $labels[] = date('D', strtotime($date));
                $r = $conn->query("SELECT COALESCE(SUM(total_amount),0) as r FROM orders WHERE status='Completed' AND DATE(created_at)='$date'")->fetch_assoc();
                $e = $conn->query("SELECT COALESCE(SUM(amount),0) as e FROM expenses WHERE expense_date='$date'")->fetch_assoc();
                $revenue[]  = (float)$r['r'];
                $expenses[] = (float)$e['e'];
            }
        } elseif ($filter === 'yearly') {
            for ($i = 4; $i >= 0; $i--) {
                $year = date('Y') - $i;
                $labels[] = $year;
                $r = $conn->query("SELECT COALESCE(SUM(total_amount),0) as r FROM orders WHERE status='Completed' AND YEAR(created_at)=$year")->fetch_assoc();
                $e = $conn->query("SELECT COALESCE(SUM(amount),0) as e FROM expenses WHERE YEAR(expense_date)=$year")->fetch_assoc();
                $revenue[]  = (float)$r['r'];
                $expenses[] = (float)$e['e'];
            }
        } else {
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $labels[] = date('M', strtotime($month));
                $r = $conn->query("SELECT COALESCE(SUM(total_amount),0) as r FROM orders WHERE status='Completed' AND DATE_FORMAT(created_at,'%Y-%m')='$month'")->fetch_assoc();
                $e = $conn->query("SELECT COALESCE(SUM(amount),0) as e FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')='$month'")->fetch_assoc();
                $revenue[]  = (float)$r['r'];
                $expenses[] = (float)$e['e'];
            }
        }
        echo json_encode(['labels' => $labels, 'revenue' => $revenue, 'expenses' => $expenses]);
        exit;
    }

    /* 4. Export CSV (NEW) */
    if ($action === 'export_csv') {
        $type = $_GET['type'] ?? 'orders';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="smoims_' . $type . '_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');

        if ($type === 'orders') {
            fputcsv($out, ['Order ID', 'Customer', 'Total (₱)', 'Status', 'Date']);
            $res = $conn->query("SELECT o.id, c.full_name, o.total_amount, o.status, o.created_at FROM orders o JOIN customers c ON o.customer_id = c.id ORDER BY o.created_at DESC");
            while ($row = $res->fetch_assoc()) {
                fputcsv($out, [$row['id'], $row['full_name'], $row['total_amount'], $row['status'], $row['created_at']]);
            }
        } elseif ($type === 'products') {
            fputcsv($out, ['Product', 'Category', 'Stock', 'Price (₱)', 'Units Sold', 'Revenue (₱)']);
            $res = $conn->query("SELECT i.item_name, i.category, i.quantity, i.price, COALESCE(SUM(oi.quantity),0) as sold, COALESCE(SUM(oi.quantity * oi.unit_price),0) as rev FROM inventory i LEFT JOIN order_items oi ON oi.item_id = i.id LEFT JOIN orders o ON oi.order_id = o.id AND o.status='Completed' GROUP BY i.id ORDER BY sold DESC");
            while ($row = $res->fetch_assoc()) {
                fputcsv($out, [$row['item_name'], $row['category'], $row['quantity'], $row['price'], $row['sold'], $row['rev']]);
            }
        } elseif ($type === 'expenses') {
            fputcsv($out, ['ID', 'Category', 'Description', 'Amount (₱)', 'Paid To', 'Date']);
            $res = $conn->query("SELECT id, category, description, amount, paid_to, expense_date FROM expenses ORDER BY expense_date DESC");
            while ($row = $res->fetch_assoc()) {
                fputcsv($out, [$row['id'], $row['category'], $row['description'], $row['amount'], $row['paid_to'], $row['expense_date']]);
            }
        }
        fclose($out);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

/* ════════════════════════════════════════════════════════
   PAGE DATA QUERIES
════════════════════════════════════════════════════════ */

/* -- Revenue & Orders -- */
$totalRevenue      = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS t FROM orders WHERE status='Completed'")->fetch_assoc()['t'] ?? 0;
$revenueThisMonth  = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS t FROM orders WHERE status='Completed' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetch_assoc()['t'] ?? 0;
$completedMonth    = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='Completed' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetch_assoc()['c'] ?? 0;
$pendingOrders     = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;

/* -- Expenses & Profit (NEW) -- */
$totalExpenses     = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses")->fetch_assoc()['t'] ?? 0;
$expensesThisMonth = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE MONTH(expense_date)=MONTH(NOW()) AND YEAR(expense_date)=YEAR(NOW())")->fetch_assoc()['t'] ?? 0;
$netProfit         = $totalRevenue - $totalExpenses;
$netProfitMonth    = $revenueThisMonth - $expensesThisMonth;

/* -- Customers (NEW) -- */
$totalCustomers    = $conn->query("SELECT COUNT(*) AS c FROM customers")->fetch_assoc()['c'] ?? 0;
$newCustomersMonth = $conn->query("SELECT COUNT(*) AS c FROM customers WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetch_assoc()['c'] ?? 0;
$repeatBuyers      = $conn->query("SELECT COUNT(*) AS c FROM (SELECT customer_id FROM orders GROUP BY customer_id HAVING COUNT(*)>1) AS r")->fetch_assoc()['c'] ?? 0;

/* -- Inventory -- */
$lowCount          = $conn->query("SELECT COUNT(*) AS c FROM inventory WHERE quantity < 10")->fetch_assoc()['c'] ?? 0;
$totalProducts     = $conn->query("SELECT COUNT(*) AS c FROM inventory")->fetch_assoc()['c'] ?? 0;

/* -- Order Status Donut -- */
$byStatus = $conn->query("SELECT status, COUNT(*) AS c FROM orders GROUP BY status");
$statusLabels = []; $statusCounts = [];
if ($byStatus) {
    while ($s = $byStatus->fetch_assoc()) {
        $statusLabels[] = $s['status'];
        $statusCounts[] = (int)$s['c'];
    }
}

/* -- Top Products -- */
$topProducts = $conn->query(
    "SELECT i.item_name, SUM(oi.quantity) AS total_sold, SUM(oi.quantity * oi.unit_price) AS revenue
       FROM order_items oi
       JOIN inventory i ON oi.item_id = i.id
       JOIN orders o    ON oi.order_id = o.id
      WHERE o.status = 'Completed'
      GROUP BY i.id, i.item_name
      ORDER BY total_sold DESC LIMIT 5"
);

/* -- Recent Orders (NEW) -- */
$recentOrders = $conn->query(
    "SELECT o.id, c.full_name, o.total_amount, o.status, o.created_at
       FROM orders o
       JOIN customers c ON o.customer_id = c.id
      ORDER BY o.created_at DESC LIMIT 8"
);

/* -- Top Expense Categories (NEW) -- */
$topExpenses = $conn->query(
    "SELECT category, SUM(amount) AS total FROM expenses GROUP BY category ORDER BY total DESC LIMIT 5"
);

/* -- Inventory selector -- */
$inventoryList = $conn->query("SELECT id, item_name FROM inventory ORDER BY item_name ASC");

/* -- Avg Rating (NEW) -- */
$avgRating = $conn->query("SELECT ROUND(AVG(rating),1) AS r FROM reviews")->fetch_assoc()['r'] ?? 0;
$totalReviews = $conn->query("SELECT COUNT(*) AS c FROM reviews")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports | SolisCo.</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* ── Tokens ── */
    :root {
      --c-bg:           #f5f3ff;
      --c-surface:      #ffffff;
      --c-border:       #ede9fe;
      --c-border-s:     #c4b5fd;
      --c-accent:       #7c3aed;
      --c-accent-lt:    #ede9fe;
      --c-accent-mid:   #a78bfa;
      --c-accent-deep:  #5b21b6;
      --c-text:         #1e1b4b;
      --c-muted:        #6b7280;
      --c-ok:           #16a34a;
      --c-ok-bg:        #dcfce7;
      --c-warn:         #d97706;
      --c-warn-bg:      #fef3c7;
      --c-err:          #dc2626;
      --c-err-bg:       #fef2f2;
      --c-info:         #0284c7;
      --c-info-bg:      #e0f2fe;
      --grad:           #564586;;
      --grad-hero:      linear-gradient(135deg,#ede9fe 0%,#fce7f3 50%,#e0e7ff 100%);
      --shadow-card:    0 2px 8px rgba(109,40,217,.06),0 8px 24px rgba(109,40,217,.05);
      --shadow-hover:   0 12px 40px rgba(109,40,217,.18);
      --r:              16px;
      --font-head:      'Sora', sans-serif;
      --font-display:   'Playfair Display', serif;
      --font-body:      'DM Sans', sans-serif;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: var(--font-body); background: var(--c-bg); color: var(--c-text); min-height: 100vh; overflow-x: hidden; }

    /* ── Animated bg blobs ── */
    body::before, body::after {
      content: ''; position: fixed; width: 500px; height: 500px;
      border-radius: 50%; filter: blur(120px); opacity: .3; z-index: 0; pointer-events: none;
      animation: blobFloat 18s ease-in-out infinite;
    }
    body::before { background: radial-gradient(circle,#c4b5fd,transparent 70%); top: -120px; left: -120px; }
    body::after  { background: radial-gradient(circle,#fbcfe8,transparent 70%); bottom: -120px; right: -120px; animation-delay: -9s; }
    @keyframes blobFloat { 0%,100%{transform:translate(0,0) scale(1)} 33%{transform:translate(50px,-30px) scale(1.08)} 66%{transform:translate(-30px,50px) scale(.96)} }

    /* ── Layout ── */
    .layout { display: flex; min-height: 100vh; position: relative; z-index: 1; }
    .main   { flex: 1; padding: 28px 28px 60px; max-width: 1280px; }

    /* ── Page header ── */
    .page-header {
      display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap;
      gap: 16px; margin-bottom: 28px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) both;
    }
    .page-header h1 { font-family: var(--font-head); font-size: 1.7rem; font-weight: 800; color: var(--c-text); }
    .page-header p  { color: var(--c-muted); font-size: .92rem; margin-top: 4px; }
    .export-bar { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-export {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border-radius: 10px; font-family: var(--font-body);
      font-size: .82rem; font-weight: 700; cursor: pointer; text-decoration: none;
      border: 1.5px solid var(--c-border-s); color: var(--c-accent-deep);
      background: rgba(255,255,255,.8); backdrop-filter: blur(8px);
      transition: all .2s;
    }
    .btn-export:hover { background: var(--c-accent-lt); border-color: var(--c-accent); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(124,58,237,.18); }
    .btn-export.primary { background: var(--grad); color: #fff; border-color: transparent; box-shadow: 0 4px 14px rgba(124,58,237,.3); }
    .btn-export.primary:hover { box-shadow: 0 8px 22px rgba(124,58,237,.45); }

    /* ── KPI Stat Cards ── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      gap: 16px; margin-bottom: 28px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .08s both;
    }
    .kpi-card {
      background: rgba(255,255,255,.85); backdrop-filter: blur(12px);
      border: 1.5px solid rgba(255,255,255,.8); border-radius: var(--r);
      padding: 20px 20px 18px; box-shadow: var(--shadow-card);
      display: flex; flex-direction: column; gap: 6px;
      transition: transform .3s, box-shadow .3s;
      position: relative; overflow: hidden;
    }
    .kpi-card::before {
      content: ''; position: absolute; inset: 0; border-radius: var(--r); padding: 1.5px;
      background: var(--grad);
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor; mask-composite: exclude;
      opacity: 0; transition: opacity .3s;
    }
    .kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
    .kpi-card:hover::before { opacity: 1; }
    .kpi-ico  { font-size: 1.6rem; line-height: 1; }
    .kpi-val  { font-family: var(--font-head); font-size: 1.55rem; font-weight: 800; color: var(--c-text); line-height: 1.1; }
    .kpi-val.ok   { color: var(--c-ok); }
    .kpi-val.warn { color: var(--c-warn); }
    .kpi-val.err  { color: var(--c-err); }
    .kpi-val.grad { background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .kpi-lbl  { font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--c-muted); }
    .kpi-sub  { font-size: .73rem; color: var(--c-muted); margin-top: 2px; }
    .kpi-sub .up   { color: var(--c-ok); font-weight: 700; }
    .kpi-sub .down { color: var(--c-err); font-weight: 700; }

    /* ── Section label ── */
    .section-label {
      font-family: var(--font-head); font-size: .72rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: 1.4px; color: var(--c-accent);
      margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
    }
    .section-label::after { content: ''; flex: 1; height: 1.5px; background: var(--c-border); border-radius: 99px; }

    /* ── Chart cards ── */
    .charts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
      gap: 18px; margin-bottom: 28px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .14s both;
    }
    .chart-card {
      background: rgba(255,255,255,.85); backdrop-filter: blur(12px);
      border: 1.5px solid rgba(255,255,255,.8); border-radius: var(--r);
      padding: 22px; box-shadow: var(--shadow-card);
    }
    .chart-card.full { grid-column: 1 / -1; }
    .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }
    .chart-header h3 { font-family: var(--font-head); font-size: 1rem; font-weight: 700; color: var(--c-text); }
    .chart-header select {
      padding: 7px 12px; border-radius: 8px; border: 1.5px solid var(--c-border);
      font-family: var(--font-body); font-size: .82rem; background: rgba(255,255,255,.9);
      color: var(--c-text); cursor: pointer; outline: none; transition: border-color .2s;
    }
    .chart-header select:focus { border-color: var(--c-accent-mid); }

    /* ── Bottom grid ── */
    .bottom-grid {
      display: grid;
      grid-template-columns: 1.4fr 1fr;
      gap: 18px; margin-bottom: 28px;
      animation: fadeUp .7s cubic-bezier(.22,1,.36,1) .2s both;
    }
    @media(max-width:900px) { .bottom-grid { grid-template-columns: 1fr; } }

    /* ── Data table ── */
    .data-card {
      background: rgba(255,255,255,.85); backdrop-filter: blur(12px);
      border: 1.5px solid rgba(255,255,255,.8); border-radius: var(--r);
      padding: 22px; box-shadow: var(--shadow-card); overflow: hidden;
    }
    .data-card h3 { font-family: var(--font-head); font-size: 1rem; font-weight: 700; margin-bottom: 16px; }
    .data-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
    .data-table th {
      text-align: left; font-size: .7rem; font-weight: 800; text-transform: uppercase;
      letter-spacing: .8px; color: var(--c-muted); padding: 0 12px 10px 0;
      border-bottom: 1.5px solid var(--c-border);
    }
    .data-table td { padding: 10px 12px 10px 0; border-bottom: 1px solid var(--c-border); vertical-align: middle; }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(237,233,254,.3); }

    /* ── Status pills ── */
    .pill {
      display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 99px;
      font-size: .7rem; font-weight: 700; white-space: nowrap;
    }
    .pill-pending    { background: var(--c-warn-bg);  color: var(--c-warn); }
    .pill-processing { background: var(--c-info-bg);  color: var(--c-info); }
    .pill-completed  { background: var(--c-ok-bg);    color: var(--c-ok);   }
    .pill-cancelled  { background: var(--c-err-bg);   color: var(--c-err);  }

    /* ── Profit row ── */
    .profit-positive { color: var(--c-ok);  font-weight: 700; }
    .profit-negative { color: var(--c-err); font-weight: 700; }

    /* ── Progress bar ── */
    .prog-wrap { background: var(--c-border); border-radius: 99px; height: 6px; margin-top: 5px; overflow: hidden; }
    .prog-bar  { height: 100%; border-radius: 99px; background: var(--grad); transition: width .6s ease; }

    /* ── Rating stars ── */
    .stars { color: #f59e0b; font-size: .9rem; letter-spacing: 1px; }

    /* ── Empty state ── */
    .empty { text-align: center; padding: 40px 24px; color: var(--c-muted); font-size: .9rem; }
    .empty .em-ico { font-size: 2.5rem; margin-bottom: 8px; opacity: .5; }

    /* ── Print styles ── */
    @media print {
      body::before, body::after { display: none; }
      .btn-export, .chart-header select { display: none !important; }
      .chart-card, .data-card, .kpi-card { box-shadow: none; border: 1px solid #ddd; }
    }

    @keyframes fadeUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }

    @media(max-width:700px) {
      .main { padding: 16px 14px 40px; }
      .kpi-grid  { grid-template-columns: repeat(2,1fr); }
      .charts-grid { grid-template-columns: 1fr; }
      .page-header { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php include '../includes/sidebar.php'; ?>

  <div class="main">

    <!-- ── PAGE HEADER + EXPORT BUTTONS ── -->
    <div class="page-header">
      <div>
        <h1>📈 Business Analytics</h1>
        <p>Real-time insights, sales performance, and financial overview.</p>
      </div>
      <div class="export-bar">
        <a class="btn-export" href="reports.php?action=export_csv&type=orders" title="Export all orders to CSV">📥 Orders CSV</a>
        <a class="btn-export" href="reports.php?action=export_csv&type=products" title="Export product performance to CSV">📥 Products CSV</a>
        <a class="btn-export" href="reports.php?action=export_csv&type=expenses" title="Export expenses to CSV">📥 Expenses CSV</a>
        <button class="btn-export primary" onclick="window.print()">🖨️ Print Report</button>
      </div>
    </div>

    <!-- ════════════ KPI STAT CARDS ════════════ -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-ico">💰</div>
        <div class="kpi-val grad">₱<?= number_format($totalRevenue, 0) ?></div>
        <div class="kpi-lbl">Total Revenue</div>
        <div class="kpi-sub"><span class="up">₱<?= number_format($revenueThisMonth, 0) ?></span> this month</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico">🧾</div>
        <div class="kpi-val err">₱<?= number_format($totalExpenses, 0) ?></div>
        <div class="kpi-lbl">Total Expenses</div>
        <div class="kpi-sub"><span class="down">₱<?= number_format($expensesThisMonth, 0) ?></span> this month</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico"><?= $netProfit >= 0 ? '📊' : '📉' ?></div>
        <div class="kpi-val <?= $netProfit >= 0 ? 'ok' : 'err' ?>">₱<?= number_format(abs($netProfit), 0) ?></div>
        <div class="kpi-lbl">Net <?= $netProfit >= 0 ? 'Profit' : 'Loss' ?></div>
        <div class="kpi-sub">
          <?php if ($netProfitMonth >= 0): ?>
            <span class="up">₱<?= number_format($netProfitMonth, 0) ?></span> profit this month
          <?php else: ?>
            <span class="down">₱<?= number_format(abs($netProfitMonth), 0) ?></span> loss this month
          <?php endif; ?>
        </div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico">✅</div>
        <div class="kpi-val ok"><?= $completedMonth ?></div>
        <div class="kpi-lbl">Completed This Month</div>
        <div class="kpi-sub"><span class="warn"><?= $pendingOrders ?></span> pending orders</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico">👥</div>
        <div class="kpi-val"><?= $totalCustomers ?></div>
        <div class="kpi-lbl">Total Customers</div>
        <div class="kpi-sub"><span class="up">+<?= $newCustomersMonth ?></span> new this month</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico">🔁</div>
        <div class="kpi-val"><?= $repeatBuyers ?></div>
        <div class="kpi-lbl">Repeat Buyers</div>
        <div class="kpi-sub"><?= $totalCustomers > 0 ? round(($repeatBuyers / $totalCustomers) * 100) : 0 ?>% of customers</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico">⚠️</div>
        <div class="kpi-val warn"><?= $lowCount ?></div>
        <div class="kpi-lbl">Low Stock Items</div>
        <div class="kpi-sub"><?= $totalProducts ?> total products</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-ico">⭐</div>
        <div class="kpi-val"><?= $avgRating ?: '—' ?></div>
        <div class="kpi-lbl">Avg. Rating</div>
        <div class="kpi-sub"><?= $totalReviews ?> review<?= $totalReviews != 1 ? 's' : '' ?> total</div>
      </div>
    </div>

    <!-- ════════════ SECTION: REVENUE & PROFIT CHART ════════════ -->
    <div class="section-label">Revenue vs Expenses</div>
    <div class="charts-grid" style="margin-bottom:18px">
      <div class="chart-card full">
        <div class="chart-header">
          <h3>💹 Revenue vs Expenses Over Time</h3>
          <select id="revenueFilter" onchange="updateRevenueChart()">
            <option value="weekly">This Week</option>
            <option value="monthly" selected>Last 6 Months</option>
            <option value="yearly">Last 5 Years</option>
          </select>
        </div>
        <canvas id="revenueLineChart" height="100"></canvas>
      </div>
    </div>

    <!-- ════════════ SECTION: ORDER & PRODUCT VOLUME ════════════ -->
    <div class="section-label">Order Volume & Product Performance</div>
    <div class="charts-grid">
      <div class="chart-card">
        <div class="chart-header">
          <h3>📦 Order Volume</h3>
          <select id="orderFilter" onchange="updateOrdersChart()">
            <option value="weekly">This Week</option>
            <option value="monthly" selected>Last 6 Months</option>
            <option value="yearly">Last 5 Years</option>
          </select>
        </div>
        <canvas id="ordersBarChart" height="200"></canvas>
      </div>

      <div class="chart-card">
        <div class="chart-header">
          <h3>🏆 Product Sales</h3>
          <select id="productFilter" onchange="updateProductChart()">
            <option value="all">All Items (Top 10)</option>
            <?php while ($item = $inventoryList->fetch_assoc()): ?>
              <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['item_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <canvas id="productBarChart" height="200"></canvas>
      </div>
    </div>

    <!-- ════════════ SECTION: BOTTOM DATA TABLES ════════════ -->
    <div class="section-label">Detailed Breakdowns</div>
    <div class="bottom-grid">

      <!-- Recent Orders Feed (NEW) -->
      <div class="data-card">
        <h3>🕐 Recent Orders</h3>
        <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>#ID</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($o = $recentOrders->fetch_assoc()): ?>
                <tr>
                  <td style="font-weight:700;color:var(--c-accent)">#<?= $o['id'] ?></td>
                  <td><?= htmlspecialchars($o['full_name']) ?></td>
                  <td style="font-weight:600">₱<?= number_format($o['total_amount'], 2) ?></td>
                  <td>
                    <span class="pill pill-<?= strtolower($o['status']) ?>">
                      <?= $o['status'] ?>
                    </span>
                  </td>
                  <td style="color:var(--c-muted);font-size:.8rem"><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty"><div class="em-ico">📋</div>No orders yet.</div>
        <?php endif; ?>
      </div>

      <!-- Right column: Donut + Top 5 Products + Expenses -->
      <div style="display:flex;flex-direction:column;gap:18px">

        <!-- Status Donut -->
        <div class="data-card">
          <h3>📊 Orders by Status</h3>
          <canvas id="statusChart" height="180"></canvas>
        </div>

        <!-- Top 5 Products -->
        <div class="data-card">
          <h3>🥇 Top 5 Products (Lifetime)</h3>
          <?php if ($topProducts && $topProducts->num_rows > 0):
            $topProducts->data_seek(0);
            $maxSold = null;
            $rows = [];
            while ($p = $topProducts->fetch_assoc()) $rows[] = $p;
            $maxSold = $rows[0]['total_sold'] ?? 1;
          ?>
            <?php foreach ($rows as $idx => $p): ?>
              <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                  <span style="font-size:.85rem;font-weight:600"><?= htmlspecialchars($p['item_name']) ?></span>
                  <span style="font-size:.8rem;color:var(--c-muted)"><?= $p['total_sold'] ?> sold · ₱<?= number_format($p['revenue'], 0) ?></span>
                </div>
                <div class="prog-wrap">
                  <div class="prog-bar" style="width:<?= $maxSold > 0 ? round(($p['total_sold'] / $maxSold) * 100) : 0 ?>%"></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty"><div class="em-ico">📦</div>No completed orders yet.</div>
          <?php endif; ?>
        </div>

        <!-- Top Expense Categories (NEW) -->
        <div class="data-card">
          <h3>🧾 Expenses by Category</h3>
          <?php if ($topExpenses && $topExpenses->num_rows > 0):
            $topExpenses->data_seek(0);
            $expRows = [];
            while ($e = $topExpenses->fetch_assoc()) $expRows[] = $e;
            $maxExp = $expRows[0]['total'] ?? 1;
          ?>
            <?php foreach ($expRows as $e): ?>
              <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                  <span style="font-size:.85rem;font-weight:600"><?= htmlspecialchars($e['category']) ?></span>
                  <span style="font-size:.8rem;color:var(--c-err);font-weight:600">₱<?= number_format($e['total'], 0) ?></span>
                </div>
                <div class="prog-wrap">
                  <div class="prog-bar" style="width:<?= $maxExp > 0 ? round(($e['total'] / $maxExp) * 100) : 0 ?>%;background:linear-gradient(90deg,#f87171,#fca5a5)"></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty"><div class="em-ico">🧾</div>No expenses recorded.</div>
          <?php endif; ?>
        </div>

      </div><!-- /right col -->
    </div><!-- /bottom-grid -->

  </div><!-- /main -->
</div><!-- /layout -->

<script>
/* ════════════════════════════════════════════════════════
   CHART DEFAULTS
════════════════════════════════════════════════════════ */
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color       = '#6b7280';

const PURPLE  = '#7c3aed';
const VIOLET  = '#a78bfa';
const PINK    = '#ec4899';
const GREEN   = '#22c55e';
const RED     = '#f87171';
const AMBER   = '#f59e0b';
const BLUE    = '#60a5fa';

let ordersChart, productChart, revenueChart;

/* ── A. Orders Bar Chart ── */
async function updateOrdersChart() {
    const filter = document.getElementById('orderFilter').value;
    const data   = await (await fetch(`reports.php?action=get_orders_data&filter=${filter}`)).json();
    if (ordersChart) ordersChart.destroy();
    ordersChart = new Chart(document.getElementById('ordersBarChart'), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Orders',
                data: data.counts,
                backgroundColor: createGradient('ordersBarChart', [PURPLE, VIOLET]),
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(196,181,253,.2)' }, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });
}

/* ── B. Product Bar Chart ── */
async function updateProductChart() {
    const pId  = document.getElementById('productFilter').value;
    const data = await (await fetch(`reports.php?action=get_product_data&product_id=${pId}`)).json();
    if (productChart) productChart.destroy();
    productChart = new Chart(document.getElementById('productBarChart'), {
        type: pId === 'all' ? 'bar' : 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: pId === 'all' ? 'Total Units Sold' : 'Monthly Sales',
                data: data.data,
                backgroundColor: pId === 'all'
                    ? createGradient('productBarChart', [GREEN, '#34d399'])
                    : 'rgba(34,197,94,.15)',
                borderColor: GREEN,
                borderRadius: 8,
                borderWidth: 2.5,
                fill: pId !== 'all',
                tension: .4,
                pointBackgroundColor: GREEN,
                pointRadius: 5,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(196,181,253,.2)' }, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });
}

/* ── C. Revenue vs Expenses Line Chart (NEW) ── */
async function updateRevenueChart() {
    const filter = document.getElementById('revenueFilter').value;
    const data   = await (await fetch(`reports.php?action=get_revenue_data&filter=${filter}`)).json();
    if (revenueChart) revenueChart.destroy();
    revenueChart = new Chart(document.getElementById('revenueLineChart'), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Revenue (₱)',
                    data: data.revenue,
                    borderColor: PURPLE,
                    backgroundColor: 'rgba(124,58,237,.12)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: .4,
                    pointBackgroundColor: PURPLE,
                    pointRadius: 5,
                },
                {
                    label: 'Expenses (₱)',
                    data: data.expenses,
                    borderColor: RED,
                    backgroundColor: 'rgba(248,113,113,.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: .4,
                    pointBackgroundColor: RED,
                    pointRadius: 5,
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', padding: 20 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ₱${ctx.parsed.y.toLocaleString()}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(196,181,253,.2)' },
                    ticks: { callback: v => '₱' + v.toLocaleString() }
                },
                x: { grid: { display: false } }
            }
        }
    });
}

/* ── D. Status Donut ── */
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($statusLabels) ?>,
        datasets: [{
            data: <?= json_encode($statusCounts) ?>,
            backgroundColor: [AMBER, BLUE, GREEN, RED],
            borderWidth: 0,
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        cutout: '68%',
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12 } } }
        }
    }
});

/* ── Helper: canvas gradient ── */
function createGradient(canvasId, [c1, c2]) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return c1;
    const ctx = canvas.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, canvas.offsetHeight || 200);
    grad.addColorStop(0, c1);
    grad.addColorStop(1, c2);
    return grad;
}

/* ── Init all charts ── */
window.addEventListener('DOMContentLoaded', () => {
    updateOrdersChart();
    updateProductChart();
    updateRevenueChart();
});
</script>
</body>
</html>