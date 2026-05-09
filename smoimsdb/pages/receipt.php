<?php
/* ============================================================
   SMOIMS - Receipt Generator
   FILE: pages/receipt.php
   ============================================================
*/
require_once '../includes/config.php';

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) die('Invalid order.');

/* ── Query: Order info ── */
$order = $conn->query("
    SELECT o.*, c.full_name, c.email, c.contact
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = $orderId
")->fetch_assoc();

if (!$order) die('Order not found.');

/* ── Query: Order items ── */
$items = $conn->query("
    SELECT oi.*, i.item_name
    FROM order_items oi
    JOIN inventory i ON oi.item_id = i.id
    WHERE oi.order_id = $orderId
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt #<?= $orderId ?> | SolisCo.</title>
  <link rel="stylesheet" href="../css/style.css">
  <style>
    /* ── Receipt-specific print styles ── */
    body { background: white; display:flex; justify-content:center; padding:32px 16px; }
    .receipt { max-width: 560px; width: 100%; background: white; border: 1px solid var(--pastel-border); border-radius: 16px; padding: 36px; box-shadow: var(--shadow); }
    .receipt-header { text-align: center; margin-bottom: 24px; border-bottom: 2px dashed var(--pastel-border); padding-bottom: 20px; }
    .receipt-logo { font-size: 2rem; margin-bottom: 4px; }
    .receipt-biz { font-family: var(--font-heading); font-size: 1.4rem; font-weight: 700; color: var(--purple-700); }
    .receipt-sub { font-size: 0.8rem; color: var(--pastel-muted); }
    .receipt-info { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; margin-bottom: 20px; font-size: 0.88rem; }
    .receipt-info .label { color: var(--pastel-muted); font-weight: 600; }
    .receipt-info .value { color: var(--pastel-text); font-weight: 700; }
    .receipt-total { text-align: right; margin-top: 16px; padding-top: 16px; border-top: 2px dashed var(--pastel-border); }
    .receipt-total .total-label { font-size: 0.9rem; color: var(--pastel-muted); }
    .receipt-total .total-value { font-family: var(--font-heading); font-size: 1.6rem; font-weight: 700; color: var(--purple-700); }
    .receipt-footer { text-align: center; margin-top: 24px; font-size: 0.8rem; color: var(--pastel-muted); border-top: 1px solid var(--pastel-border); padding-top: 16px; }
    @media print {
      .no-print { display: none; }
      body { padding: 0; }
      .receipt { box-shadow: none; border: none; }
    }
  </style>
</head>
<body>
  <div class="receipt">

    <!-- ── Business Header: change name/address here ── -->
    <div class="receipt-header">
      <div class="receipt-logo">
        <img src="../images/logo.png" alt="SolisCo. Logo">
      </div>
      <div class="receipt-biz">SolisCo.</div>
      <!-- ← Change address, phone, email below ── -->
      <div class="receipt-sub">123 Business Ave, Suite 100<br>Batangas, Philippines</div>
      <div class="receipt-sub">soliscompany@email.com | 09568366193</div>
    </div>

    <!-- ── Order Info ── -->
    <div class="receipt-info">
      <span class="label">Receipt #</span>   <span class="value"><?= $orderId ?></span>
      <span class="label">Date</span>        <span class="value"><?= date('M d, Y', strtotime($order['created_at'])) ?></span>
      <span class="label">Customer</span>    <span class="value"><?= htmlspecialchars($order['full_name']) ?></span>
      <span class="label">Contact</span>     <span class="value"><?= htmlspecialchars($order['contact']) ?></span>
      <span class="label">Status</span>
      <span class="value">
        <span class="badge badge-<?= strtolower($order['status']) ?>"><?= $order['status'] ?></span>
      </span>
    </div>

    <!-- ── Items Table ── -->
    <table>
      <thead>
        <tr>
          <th>Item</th>
          <th style="text-align:right">Qty</th>
          <th style="text-align:right">Unit Price</th>
          <th style="text-align:right">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($items): ?>
          <?php while ($item = $items->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($item['item_name']) ?></td>
              <td style="text-align:right"><?= $item['quantity'] ?></td>
              <td style="text-align:right">₱<?= number_format($item['unit_price'], 2) ?></td>
              <td style="text-align:right">₱<?= number_format($item['quantity'] * $item['unit_price'], 2) ?></td>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- ── Total ── -->
    <div class="receipt-total">
      <div class="total-label">TOTAL AMOUNT</div>
      <div class="total-value">₱<?= number_format($order['total_amount'], 2) ?></div>
    </div>

    <!-- ── Footer ── -->
    <div class="receipt-footer">
      Thank you for your order! 💜<br>
      <!-- ← Change footer message here ── -->
      Questions? Contact us at solis.merch@email.com
    </div>

    <!-- ── Print Button (hidden when printing) ── -->
    <div class="no-print" style="text-align:center;margin-top:20px">
      <button onclick="window.print()" class="btn btn-primary">Print Receipt</button>
      <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>

  </div>
</body>
</html>
