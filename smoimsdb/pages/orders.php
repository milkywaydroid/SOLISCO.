<?php
require_once '../includes/config.php';
requireStaffLogin();

$statuses = ['Pending', 'Processing', 'Completed', 'Cancelled'];
$message  = '';
$error    = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId   = (int)($_POST['order_id'] ?? 0);
    $newStatus = clean($conn, $_POST['status'] ?? '');
    $allowed   = ['Pending', 'Processing', 'Completed', 'Cancelled'];

    if (!$orderId || !in_array($newStatus, $allowed, true)) {
        $error = 'Invalid order status update request.';
    } else {
        $orderRes   = $conn->query("SELECT status FROM orders WHERE id = $orderId LIMIT 1");
        $prevStatus = $orderRes ? $orderRes->fetch_assoc()['status'] ?? '' : '';

        if (!$prevStatus) {
            $error = 'Order not found.';
        } else {
            $shouldDeduct = $newStatus === 'Completed' && $prevStatus !== 'Completed';
            $deducted = false;

            if ($shouldDeduct) {
                $items = $conn->query(
                    "SELECT oi.item_id, oi.color, oi.size, oi.quantity, i.has_sizes
                       FROM order_items oi
                       JOIN inventory i ON oi.item_id = i.id
                      WHERE oi.order_id = $orderId"
                );

                if (!$items || $items->num_rows === 0) {
                    $error = 'No items found for this order.';
                } else {
                    $updates      = [];
                    $syncColors   = [];
                    $syncProducts = [];

                    while ($item = $items->fetch_assoc()) {
                        $productId = (int)$item['item_id'];
                        $quantity  = (int)$item['quantity'];
                        $colorName = $conn->real_escape_string($item['color'] ?? '');
                        $sizeName  = $conn->real_escape_string($item['size'] ?? '');
                        $hasSizes  = (int)$item['has_sizes'];

                        if ($quantity <= 0) continue;

                        if ($hasSizes === 1) {
                            $colorRow = $conn->query(
                                "SELECT id FROM inventory_color_stock
                                  WHERE item_id = $productId
                                    AND LOWER(color_name) = LOWER('$colorName')
                                  LIMIT 1"
                            )->fetch_assoc();

                            if (!$colorRow) {
                                $updates[] = "UPDATE inventory SET quantity = quantity - $quantity WHERE id = $productId";
                                $syncProducts[$productId] = true;
                                continue;
                            }

                            $colorId = (int)$colorRow['id'];
                            $sizeRow = $conn->query(
                                "SELECT id, quantity FROM inventory_size_variants
                                  WHERE color_id = $colorId
                                    AND LOWER(size_name) = LOWER('$sizeName')
                                  LIMIT 1"
                            )->fetch_assoc();

                            if (!$sizeRow) {
                                $updates[] = "UPDATE inventory_color_stock SET stock_qty = stock_qty - $quantity WHERE id = $colorId";
                                $syncProducts[$productId] = true;
                                continue;
                            }

                            if ((int)$sizeRow['quantity'] < $quantity) {
                                $error = "Insufficient stock for {$item['color']} / {$item['size']} on product #$productId.";
                                break;
                            }

                            $updates[] = "UPDATE inventory_size_variants SET quantity = quantity - $quantity WHERE id = " . (int)$sizeRow['id'];
                            $syncColors[$colorId] = true;
                            $syncProducts[$productId] = true;
                        } else {
                            $colorRow = $conn->query(
                                "SELECT id, stock_qty FROM inventory_color_stock
                                  WHERE item_id = $productId
                                    AND LOWER(color_name) = LOWER('$colorName')
                                  LIMIT 1"
                            )->fetch_assoc();

                            if (!$colorRow) {
                                $updates[] = "UPDATE inventory SET quantity = quantity - $quantity WHERE id = $productId";
                                $syncProducts[$productId] = true;
                                continue;
                            }

                            if ((int)$colorRow['stock_qty'] < $quantity) {
                                $error = "Insufficient stock for {$item['color']} on product #$productId.";
                                break;
                            }

                            $updates[] = "UPDATE inventory_color_stock SET stock_qty = stock_qty - $quantity WHERE id = " . (int)$colorRow['id'];
                            $syncProducts[$productId] = true;
                        }
                    }

                    if (!$error) {
                        foreach ($updates as $sql) $conn->query($sql);
                        foreach (array_keys($syncColors)   as $cid) syncColorAndItemQty($conn, (int)$cid);
                        foreach (array_keys($syncProducts) as $pid) syncItemQtyFromColors($conn, (int)$pid);
                        $deducted = true;
                    }
                }
            }

            if (!$error) {
                $conn->query("UPDATE orders SET status='$newStatus' WHERE id=$orderId");
                $message = $deducted ? 'Order status updated and stock deducted.' : 'Order status updated.';
            }
        }
    }
}

$filterStatus = clean($conn, $_GET['status'] ?? '');
$search       = clean($conn, $_GET['search'] ?? '');
$where = "WHERE 1";
if ($filterStatus) $where .= " AND o.status='$filterStatus'";
if ($search)       $where .= " AND (c.full_name LIKE '%$search%' OR o.id LIKE '%$search%')";

$orders = $conn->query("SELECT o.*, c.full_name, c.email FROM orders o JOIN customers c ON o.customer_id = c.id $where ORDER BY o.created_at DESC");

/* KPIs for orders header */
$kpiTotal      = (int)($conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'] ?? 0);
$kpiPending    = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
$kpiProcessing = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Processing'")->fetch_assoc()['c'] ?? 0);
$kpiCompleted  = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Completed'")->fetch_assoc()['c'] ?? 0);
$kpiCancelled  = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE status='Cancelled'")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Orders | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --pastel-accent:#a78bfa; --accent-2:#f0abfc; --accent-3:#818cf8;
      --ink:#1a1a2e; --ink-soft:#4b5563; --muted:#6b7280;
      --bg:#fbfaff; --surface:#ffffff; --surface-2:#f6f4ff;
      --border:rgba(167,139,250,.18);
      --success:#10b981; --warning:#f59e0b; --danger:#ef4444; --info:#0ea5e9;
      --shadow-sm:0 4px 14px rgba(80,60,160,.08);
      --shadow-md:0 14px 40px rgba(80,60,160,.14);
      --shadow-lg:0 30px 70px rgba(80,60,160,.22);
      --grad: #564586;
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

    .layout{display:flex;min-height:100vh;align-items:flex-start}
    .main{flex:1;padding:32px 40px;min-width:0;animation:fade-up .5s var(--ease) both}
    @media (max-width:768px){.main{padding:20px}}

    @keyframes fade-up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
    @keyframes fade-in{from{opacity:0}to{opacity:1}}
    @keyframes pop-in{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}

    .menu-toggle{display:none;background:var(--surface);border:1px solid var(--border);padding:10px 14px;border-radius:12px;font-weight:700;cursor:pointer;box-shadow:var(--shadow-sm)}
    @media (max-width:768px){.menu-toggle{display:inline-flex}}

    .page-header{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;margin-bottom:28px}
    .page-header h1{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3vw,2.5rem);font-weight:800;line-height:1.1;margin-bottom:6px}
    .grad-text{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
    .page-header p{color:var(--muted);font-size:.95rem}
    .date-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:var(--surface);border:1px solid var(--border);border-radius:999px;font-weight:600;font-size:.88rem;color:var(--ink-soft);box-shadow:var(--shadow-sm)}
    .date-pill::before{content:'';width:8px;height:8px;background:var(--grad);border-radius:50%}

    /* KPI mini-cards */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:24px}
    .stat-card{position:relative;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;overflow:hidden;transition:transform .4s var(--ease),box-shadow .4s var(--ease),border-color .3s var(--ease);animation:pop-in .5s var(--ease) both}
    .stat-card:hover{transform:translateY(-5px);box-shadow:var(--shadow-md);border-color:rgba(167,139,250,.4)}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--grad)}
    .stat-card.is-pending::before{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
    .stat-card.is-processing::before{background:linear-gradient(90deg,#0ea5e9,#38bdf8)}
    .stat-card.is-completed::before{background:linear-gradient(90deg,#10b981,#34d399)}
    .stat-card.is-cancelled::before{background:linear-gradient(90deg,#ef4444,#f87171)}
    .stat-icon{width:42px;height:42px;border-radius:12px;background:var(--grad-soft);display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:12px}
    .stat-value{font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:800;line-height:1.1}
    .stat-label{color:var(--muted);font-size:.78rem;font-weight:600;margin-top:6px;text-transform:uppercase;letter-spacing:.07em}

    /* Card */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow-sm);transition:box-shadow .35s var(--ease),transform .35s var(--ease);animation:fade-up .5s var(--ease) both;margin-bottom:24px}
    .card:hover{box-shadow:var(--shadow-md)}
    .card-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;font-size:1.05rem;font-weight:700}
    .card-title .icon{width:34px;height:34px;border-radius:10px;background:var(--grad);color:#fff;display:inline-flex;align-items:center;justify-content:center;margin-right:10px;font-size:.95rem;box-shadow:0 6px 16px rgba(167,139,250,.35)}
    .card-title-left{display:flex;align-items:center}

    /* Filters */
    .filter-form{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    .form-control{padding:11px 16px;border-radius:12px;border:1px solid var(--border);background:var(--surface-2);font-family:inherit;font-size:.9rem;color:var(--ink);transition:border-color .25s var(--ease),box-shadow .25s var(--ease),background .25s var(--ease);outline:none}
    .form-control:focus{border-color:var(--pastel-accent);background:#fff;box-shadow:0 0 0 4px rgba(167,139,250,.15)}
    .form-label{font-weight:700;letter-spacing:.04em;color:var(--ink);font-size:.85rem;display:block;margin-bottom:6px}

    /* Buttons / badges */
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 18px;border-radius:999px;font-weight:600;font-size:.85rem;border:none;cursor:pointer;font-family:inherit;transition:transform .25s var(--ease),box-shadow .25s var(--ease),background .25s var(--ease),color .25s var(--ease)}
    .btn-primary{background:var(--grad);color:#fff;box-shadow:0 6px 18px rgba(167,139,250,.4)}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(167,139,250,.55)}
    .btn-secondary{background:var(--surface-2);color:var(--ink);border:1px solid var(--border)}
    .btn-secondary:hover{background:#fff;border-color:var(--pastel-accent);color:var(--pastel-accent);transform:translateY(-2px)}
    .btn-sm{padding:7px 14px;font-size:.78rem}
    .btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}

    .badge{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:999px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
    .badge-pending{background:rgba(245,158,11,.14);color:#b45309}
    .badge-processing{background:rgba(14,165,233,.14);color:#0369a1}
    .badge-completed{background:rgba(16,185,129,.14);color:#047857}
    .badge-cancelled{background:rgba(239,68,68,.14);color:#b91c1c}

    /* Alerts */
    .alert{padding:14px 18px;border-radius:14px;font-weight:600;font-size:.9rem;margin-bottom:18px;animation:fade-up .4s var(--ease) both;display:flex;align-items:center;gap:10px}
    .alert-success{background:rgba(16,185,129,.12);color:#047857;border:1px solid rgba(16,185,129,.25)}
    .alert-danger{background:rgba(239,68,68,.12);color:#b91c1c;border:1px solid rgba(239,68,68,.25)}

    /* Table */
    .table-wrapper{overflow-x:auto;border-radius:12px}
    table{width:100%;border-collapse:collapse;font-size:.9rem}
    th{text-align:left;padding:14px;font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);background:var(--surface-2);border-bottom:1px solid var(--border)}
    td{padding:16px 14px;border-bottom:1px solid var(--border);color:var(--ink-soft);vertical-align:middle}
    tr:last-child td{border-bottom:none}
    tbody tr{transition:background .25s var(--ease),transform .25s var(--ease)}
    tbody tr:hover{background:var(--surface-2);transform:translateX(2px)}
    td.strong, td .order-id{color:var(--ink);font-weight:700}
    .order-id{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
    .customer-name{color:var(--ink);font-weight:600}
    .customer-email{font-size:.78rem;color:var(--muted);margin-top:2px}
    .price{font-weight:700;color:var(--ink)}
    .cancelled-row{opacity:.65}
    .cancelled-row td{color:#8b2b2b}

    .empty{text-align:center;color:var(--muted);padding:40px;font-size:.95rem}
    .empty .empty-emoji{font-size:2.5rem;display:block;margin-bottom:10px}

    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(26,26,46,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px;animation:fade-in .25s var(--ease) both}
    .modal-overlay.open{display:flex}
    .modal-box{background:var(--surface);border-radius:22px;width:min(95vw,720px);max-height:90vh;padding:30px;overflow-y:auto;position:relative;box-shadow:var(--shadow-lg);border:1px solid var(--border);animation:pop-in .35s var(--ease) both;display:flex;flex-direction:column}
    .modal-close{position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;background:var(--surface-2);border:1px solid var(--border);font-size:1rem;cursor:pointer;color:var(--ink-soft);display:flex;align-items:center;justify-content:center;transition:transform .25s var(--ease),background .25s var(--ease)}
    .modal-close:hover{background:var(--danger);color:#fff;transform:rotate(90deg)}
    .modal-title{font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:800;margin-bottom:18px;padding-right:40px}
    #modalItems{flex:1;margin-bottom:20px;font-size:.9rem;color:var(--ink-soft)}
    .modal-form{border-top:1px solid var(--border);padding-top:18px;margin-top:auto}
    .modal-form .form-control{width:100%;margin-bottom:14px}
    .modal-form .btn{width:100%;padding:14px 18px;font-size:.95rem}

    .text-muted{color:var(--muted);font-size:.78rem;margin-top:2px}
  </style>
</head>
<body>
<div class="layout">
  <?php @include '../includes/sidebar.php'; ?>
  <main class="main">

    <div class="page-header">
      <div>
        <button class="menu-toggle" onclick="document.getElementById('appSidebar')?.classList.toggle('open')">☰ Menu</button>
        <h1 style="margin-top:8px"><span class="grad-text">Orders</span> 📦</h1>
        <p>Manage and update all customer orders in one place.</p>
      </div>
      <div class="date-pill"><?= date('l, F d, Y') ?></div>
    </div>

    <?php if ($message): ?><div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-value"><?= number_format($kpiTotal) ?></div>
        <div class="stat-label">Total Orders</div>
      </div>
      <div class="stat-card is-pending">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?= number_format($kpiPending) ?></div>
        <div class="stat-label">Pending</div>
      </div>
      <div class="stat-card is-processing">
        <div class="stat-icon">🔄</div>
        <div class="stat-value"><?= number_format($kpiProcessing) ?></div>
        <div class="stat-label">Processing</div>
      </div>
      <div class="stat-card is-completed">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?= number_format($kpiCompleted) ?></div>
        <div class="stat-label">Completed</div>
      </div>
      <div class="stat-card is-cancelled">
        <div class="stat-icon">✖️</div>
        <div class="stat-value"><?= number_format($kpiCancelled) ?></div>
        <div class="stat-label">Cancelled</div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">
        <div class="card-title-left"><span class="icon">🔍</span> Search & Filter</div>
      </div>
      <form method="GET" action="orders.php" class="filter-form">
        <input type="text" name="search" class="form-control" style="flex:1;min-width:220px" placeholder="Search by customer name or order ID..." value="<?= htmlspecialchars($search) ?>">
        <select name="status" class="form-control" style="min-width:170px">
          <option value="">All Statuses</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Apply Filter</button>
        <a href="orders.php" class="btn btn-secondary">Clear</a>
      </form>
    </div>

    <div class="card">
      <div class="card-title">
        <div class="card-title-left"><span class="icon">📋</span> All Orders</div>
        <span class="text-muted" style="margin:0"><?= $orders ? $orders->num_rows : 0 ?> result<?= ($orders && $orders->num_rows === 1) ? '' : 's' ?></span>
      </div>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Order</th>
              <th>Customer</th>
              <th>Items</th>
              <th>Total</th>
              <th>Status</th>
              <th>Date</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($orders && $orders->num_rows > 0): ?>
              <?php while ($row = $orders->fetch_assoc()): ?>
                <?php
                  $itemCount = $conn->query("SELECT COUNT(*) AS c FROM order_items WHERE order_id={$row['id']}")->fetch_assoc()['c'] ?? 0;
                  $isCancelled = $row['status'] === 'Cancelled';
                ?>
                <tr class="<?= $isCancelled ? 'cancelled-row' : '' ?>">
                  <td><span class="order-id">#<?= $row['id'] ?></span></td>
                  <td>
                    <div class="customer-name"><?= htmlspecialchars($row['full_name']) ?></div>
                    <div class="customer-email"><?= htmlspecialchars($row['email']) ?></div>
                  </td>
                  <td><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></td>
                  <td><span class="price">₱<?= number_format($row['total_amount'], 2) ?></span></td>
                  <td><span class="badge badge-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                  <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                  <td style="text-align:right">
                    <button class="btn btn-secondary btn-sm"
                      <?= $isCancelled ? 'disabled title="Cannot view cancelled order"' : 'onclick="openOrderModal('.$row['id'].', \''.htmlspecialchars($row['full_name'], ENT_QUOTES).'\', \''. $row['status'] .'\')"' ?>>
                      👁 View
                    </button>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="7" class="empty"><span class="empty-emoji">📭</span>No orders found. Try adjusting your filters.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<div class="modal-overlay" id="orderModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <div class="modal-title" id="modalTitle">Order Details</div>
    <div id="modalItems"><p class="text-muted">Loading...</p></div>
    <form method="POST" action="orders.php" class="modal-form">
      <input type="hidden" name="order_id" id="modalOrderId">
      <label class="form-label">Update Status</label>
      <select name="status" id="modalStatus" class="form-control"></select>
      <button type="submit" name="update_status" class="btn btn-primary">💾 Save Status</button>
    </form>
  </div>
</div>

<script>
function openOrderModal(id, name, status) {
  const statuses = ['Pending', 'Processing', 'Completed'];
  const modalStatus = document.getElementById('modalStatus');
  modalStatus.innerHTML = '';
  statuses.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s; opt.textContent = s;
    if (s === status) opt.selected = true;
    if (status === 'Completed' && (s === 'Pending' || s === 'Processing')) opt.disabled = true;
    modalStatus.appendChild(opt);
  });
  document.getElementById('orderModal').classList.add('open');
  document.getElementById('modalTitle').textContent = 'Order #' + id + ' — ' + name;
  document.getElementById('modalOrderId').value = id;
  fetch('ajax/get_order_items.php?order_id=' + id)
    .then(r => r.text())
    .then(html => { document.getElementById('modalItems').innerHTML = html; });
}
function closeModal() { document.getElementById('orderModal').classList.remove('open'); }
document.getElementById('orderModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
</script>
</body>
</html>
