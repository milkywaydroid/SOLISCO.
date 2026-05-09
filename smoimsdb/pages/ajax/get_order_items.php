<?php
/* ============================================================
   SMOIMS - AJAX: Get Order Items
   FILE: pages/ajax/get_order_items.php
   CONNECTED KAY ORDERS SA POV NI ADMIN
   ============================================================ */
require_once '../../includes/config.php';
requireStaffLogin();

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) exit;

// Fetch order and customer details
$orderQuery = $conn->query("SELECT o.location, c.email, c.full_name FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = $orderId");
$order = $orderQuery->fetch_assoc();

function mimeFromImageData(?string $value): string
{
    if (empty($value)) return 'image/jpeg';
    if (str_starts_with($value, 'data:image/')) {
        return preg_match('#^data:([^;]+);#', $value, $m) ? $m[1] : 'image/jpeg';
    }
    $info = @getimagesizefromstring($value);
    return $info['mime'] ?? 'image/jpeg';
}

function mimeToExtension(string $mime): string
{
    return match ($mime) {
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
}

function toDataUrl(?string $value): ?string
{
    if (empty($value)) return null;
    if (str_starts_with($value, 'data:image/')) return $value;
    $mime = mimeFromImageData($value);
    return 'data:' . $mime . ';base64,' . base64_encode($value);
}

$items = $conn->query("
    SELECT oi.*, i.item_name, i.profile_image,
           oi.item_id AS product_id,
           cs.id AS color_id,
           sv.id AS size_id
    FROM order_items oi
    JOIN inventory i ON oi.item_id = i.id
    LEFT JOIN inventory_color_stock cs ON cs.item_id = oi.item_id AND cs.color_name = oi.color
    LEFT JOIN inventory_size_variants sv ON sv.color_id = cs.id AND sv.size_name = oi.size
    WHERE oi.order_id = $orderId
");
?>
<!-- Modal Header -->
<div style="padding:18px 20px;margin-bottom:20px;background:rgba(110, 79, 255, 0.08);border:1px solid rgba(110, 79, 255, 0.18);border-radius:14px;display:flex;flex-direction:column;gap:10px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <div style="font-size:0.8rem;font-weight:700;color:var(--purple-700);letter-spacing:0.06em;text-transform:uppercase">Order Details</div>
      <div style="font-size:1.55rem;font-weight:800;color:#31215F;margin-top:8px">Order #<?= $orderId ?></div>
    </div>
    <div style="text-align:right;min-width:180px">
      <div style="font-size:0.8rem;font-weight:700;color:var(--purple-700);letter-spacing:0.06em;text-transform:uppercase">Customer</div>
      <div style="font-size:1rem;font-weight:700;color:#4A397C;margin-top:8px"><?= htmlspecialchars($order['full_name'] ?? 'Unknown customer') ?></div>
    </div>
  </div>
</div>

<!-- Customer Info Grid -->
<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:24px">
  <div style="padding:16px;background:#fff;border:1px solid rgba(110, 79, 255, 0.14);border-radius:14px;display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:1rem;line-height:1;color:var(--purple-700)">📍</div>
    <div>
      <div style="font-size:0.72rem;font-weight:700;color:var(--pastel-text);text-transform:uppercase;letter-spacing:0.08em">Delivery Location</div>
      <div style="margin-top:8px;font-size:0.95rem;color:#34215E;font-weight:600;line-height:1.4"><?= htmlspecialchars($order['location'] ?? 'Not provided') ?></div>
    </div>
  </div>
  <div style="padding:16px;background:#fff;border:1px solid rgba(110, 79, 255, 0.14);border-radius:14px;display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:1rem;line-height:1;color:var(--purple-700)">✉️</div>
    <div>
      <div style="font-size:0.72rem;font-weight:700;color:var(--pastel-text);text-transform:uppercase;letter-spacing:0.08em">Email</div>
      <div style="margin-top:8px;font-size:0.95rem;color:#34215E;font-weight:600;line-height:1.4"><?= htmlspecialchars($order['email']) ?></div>
    </div>
  </div>
</div>

<!-- Order Items -->
<?php if ($items && $items->num_rows > 0): ?>
  <?php while ($r = $items->fetch_assoc()): ?>
    <div style="border:1px solid rgba(110, 79, 255, 0.16);border-radius:16px;padding:18px;margin-bottom:18px;background:#fff;box-shadow:0 18px 55px rgba(110, 79, 255, 0.08);">
      <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;margin-bottom:18px">
        <div style="flex-shrink:0;">
          <?php $profileSrc = toDataUrl($r['profile_image']); ?>
          <?php if ($profileSrc): ?>
            <img src="<?= htmlspecialchars($profileSrc) ?>" alt="Product Image" style="width:100px;height:100px;object-fit:cover;border-radius:16px;background:#F7F3FF">
          <?php else: ?>
            <div style="width:100px;height:100px;background:#F7F3FF;border-radius:16px;display:flex;align-items:center;justify-content:center;color:var(--pastel-text);font-weight:700">No Image</div>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:220px">
          <div style="font-size:1.1rem;font-weight:700;color:var(--purple-700)"><?= htmlspecialchars($r['item_name']) ?></div>
          <div style="margin-top:14px;padding:14px;background:#FBF7FF;border:1px solid rgba(110, 79, 255, 0.12);border-radius:14px;font-size:0.95rem;color:#5B5480;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <div><span style="display:block;font-weight:700;color:#4B3A86">Color</span><?= htmlspecialchars($r['color'] ?? '—') ?></div>
            <div><span style="display:block;font-weight:700;color:#4B3A86">Size</span><?= htmlspecialchars($r['size'] ?? '—') ?></div>
            <div><span style="display:block;font-weight:700;color:#4B3A86">Qty</span><?= $r['quantity'] ?></div>
            <div><span style="display:block;font-weight:700;color:#4B3A86">Price</span>₱<?= number_format($r['unit_price'], 2) ?></div>
          </div>
        </div>
      </div>

      <div>
        <div style="font-size:0.98rem;font-weight:700;color:var(--purple-700);margin-bottom:12px">Customer Uploaded Designs</div>
        <?php
          $designs = [
            'design_front' => 'Front',
            'design_back' => 'Back',
            'design_left' => 'Left',
            'design_right' => 'Right'
          ];
          $hasImages = false;
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
          <?php foreach ($designs as $field => $label): ?>
            <?php $src = toDataUrl($r[$field]); $mime = mimeFromImageData($r[$field]); $ext = mimeToExtension($mime); if ($src): $hasImages = true; ?>
              <div style="border:1px solid rgba(110, 79, 255, 0.12);border-radius:14px;background:#F9F6FF;padding:10px;text-align:center;">
                <img src="<?= htmlspecialchars($src) ?>" alt="<?= $label ?>" style="width:100%;height:160px;object-fit:contain;background:#f0ecff;border-radius:12px;border:1px solid rgba(110, 79, 255, 0.12)">
                <div style="margin-top:8px;font-size:0.82rem;color:#6A5AA8;font-weight:700"><?= $label ?></div>
                <a href="ajax/download_order_design.php?item_id=<?= $r['id'] ?>&field=<?= $field ?>" download="order_<?= $orderId ?>_item_<?= $r['id'] ?>_<?= strtolower($label) ?>.<?= $ext ?>" style="display:inline-flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:8px 10px;background:#564586;;color:#fff;border-radius:10px;text-decoration:none;font-size:0.82rem;">Download</a>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if (!$hasImages): ?>
            <div style="grid-column:1/-1;padding:22px;text-align:center;border:1px dashed rgba(110, 79, 255, 0.24);border-radius:16px;background:#F7F4FF;color:#7A6CA7;">
              <div style="font-size:1.7rem;margin-bottom:10px;">🖼️</div>
              <div style="font-weight:700;font-size:1rem;margin-bottom:8px">No images uploaded</div>
              <div style="font-size:0.92rem;line-height:1.5;color:#6A5AA8">Customer did not attach a design image for this product.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
<?php else: ?>
  <div style="padding:24px;text-align:center;color:#7A6CA7;border:1px dashed rgba(110, 79, 255, 0.24);border-radius:16px;background:#F7F4FF;">
    <div style="font-size:1.8rem;margin-bottom:12px;">📭</div>
    <div style="font-weight:700;font-size:1.05rem;margin-bottom:6px">No items found</div>
    <div style="font-size:0.95rem;line-height:1.5;">This order does not contain any products yet.</div>
  </div>
<?php endif; ?>
