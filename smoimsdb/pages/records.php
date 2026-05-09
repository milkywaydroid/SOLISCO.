<?php
require_once '../includes/config.php';
requireStaffLogin();

$search   = clean($conn, $_GET['search'] ?? '');
$dateFrom = clean($conn, $_GET['date_from'] ?? '');
$dateTo   = clean($conn, $_GET['date_to'] ?? '');
$where    = "WHERE o.status = 'Completed'";
if ($search)   $where .= " AND (c.full_name LIKE '%$search%' OR o.id LIKE '%$search%')";
if ($dateFrom) $where .= " AND DATE(o.created_at) >= '$dateFrom'";
if ($dateTo)   $where .= " AND DATE(o.created_at) <= '$dateTo'";

$records = $conn->query("SELECT o.id, c.full_name, c.email, o.total_amount, o.created_at FROM orders o JOIN customers c ON o.customer_id = c.id $where ORDER BY o.created_at DESC");
$summary = $conn->query("SELECT COUNT(*) AS total_orders, SUM(o.total_amount) AS total_revenue FROM orders o JOIN customers c ON o.customer_id = c.id $where")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Records | SolisCo.</title>
  <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="layout">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main">
    <div class="page-header"><h1>Transaction Records</h1><p>All completed order transactions.</p></div>
    <div class="stats-grid" style="grid-template-columns:repeat(2,1fr);max-width:400px">
      <div class="stat-card"><div class="stat-icon"></div><div class="stat-value"><?= $summary['total_orders'] ?? 0 ?></div><div class="stat-label">Orders</div></div>
      <div class="stat-card"><div class="stat-icon"></div><div class="stat-value">₱<?= number_format($summary['total_revenue'] ?? 0, 0) ?></div><div class="stat-label">Revenue</div></div>
    </div>
    <div class="card">
      <form method="GET" action="records.php" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end">
        <div><label class="form-label">Search</label><input type="text" name="search" class="form-control" style="max-width:200px" placeholder="Name or Order ID..." value="<?= htmlspecialchars($search) ?>"></div>
        <div><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>"></div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="records.php" class="btn btn-secondary">Clear</a>
      </form>
    </div>
    <div class="card">
      <div class="card-title">📄 Transaction List</div>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Order #</th><th>Customer</th><th>Email</th><th>Total Amount</th><th>Date</th><th>Receipt</th></tr></thead>
          <tbody>
            <?php if ($records && $records->num_rows > 0): ?>
              <?php while ($row = $records->fetch_assoc()): ?>
                <tr>
                  <td><strong>#<?= $row['id'] ?></strong></td>
                  <td><?= htmlspecialchars($row['full_name']) ?></td>
                  <td><?= htmlspecialchars($row['email']) ?></td>
                  <td>₱<?= number_format($row['total_amount'], 2) ?></td>
                  <td><?= date('M d, Y g:i A', strtotime($row['created_at'])) ?></td>
                  <td><a href="receipt.php?order_id=<?= $row['id'] ?>" target="_blank" class="btn btn-secondary btn-sm">🧾 View</a></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="6" class="text-center text-muted" style="padding:32px">No records found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>