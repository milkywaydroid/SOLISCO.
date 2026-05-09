<?php
/* ============================================================
   FILE: pages/customer/profile.php
   Customer profile and account management — aligned UI
   ============================================================ */

require_once '../../includes/config.php';
requireCustomerLogin();

$customer_id = $_SESSION['customer_id'];
$message = ''; $error = '';

$customer = $conn->query("SELECT * FROM customers WHERE id=$customer_id")->fetch_assoc();

/* ── Update profile ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name    = clean($conn, $_POST['full_name'] ?? '');
    $email        = clean($conn, $_POST['email'] ?? '');
    $contact      = clean($conn, $_POST['contact'] ?? '');
    $organization = clean($conn, $_POST['organization'] ?? '');
    $birthday     = clean($conn, $_POST['birthday'] ?? '');

    if ($full_name && $email) {
        $check = $conn->query("SELECT id FROM customers WHERE email='$email' AND id != $customer_id");
        if ($check->num_rows > 0) {
            $error = 'This email is already registered.';
        } else {
            $conn->query("UPDATE customers SET full_name='$full_name', email='$email', contact='$contact', organization='$organization', birthday='$birthday' WHERE id=$customer_id");
            $message = 'Profile updated successfully.';
            $customer = $conn->query("SELECT * FROM customers WHERE id=$customer_id")->fetch_assoc();
        }
    } else {
        $error = 'Name and email are required.';
    }
}

/* ── Change password ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (password_verify($current_password, $customer['password'])) {
        if ($new_password === $confirm_password && strlen($new_password) >= 6) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $conn->query("UPDATE customers SET password='$hashed' WHERE id=$customer_id");
            $message = 'Password changed successfully.';
        } else {
            $error = 'Passwords do not match or are too short (min 6 characters).';
        }
    } else {
        $error = 'Current password is incorrect.';
    }
}

$orders        = $conn->query("SELECT * FROM orders WHERE customer_id=$customer_id ORDER BY created_at DESC");
$total_orders  = $orders ? $orders->num_rows : 0;
$total_spent   = 0;
$completed     = 0;
$orders_data   = [];
if ($orders) {
    while ($o = $orders->fetch_assoc()) {
        $orders_data[]  = $o;
        $total_spent   += (float)$o['total_amount'];
        if (strtolower($o['status']) === 'completed') $completed++;
    }
}

$initials = strtoupper(substr(trim($customer['full_name'] ?? 'U'), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile | SolisCo.</title>
  <link rel="stylesheet" href="../../css/style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Sora:wght@500;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root{
      --c-accent:#7c3aed; --c-accent-2:#a855f7;
      --c-bg:#f5f3ff; --c-surface:rgba(255,255,255,.72);
      --c-border:rgba(124,58,237,.12);
      --c-text:#1e1b4b; --c-muted:#6b6388;
      --r-card:20px; --r-btn:12px;
      --shadow-soft:0 8px 30px rgba(124,58,237,.08);
      --shadow-pop:0 18px 40px rgba(124,58,237,.18);
    }
    .pf-wrap{
      font-family:'DM Sans',system-ui,sans-serif;color:var(--c-text);
      background:
        radial-gradient(900px 500px at -10% -20%, #ede9fe 0%, transparent 60%),
        radial-gradient(700px 400px at 110% 10%, #fce7f3 0%, transparent 55%),
        var(--c-bg);
      min-height:100vh;padding:28px 20px 60px;
    }
    .pf-inner{max-width:1180px;margin:0 auto;}
    .pf-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
    .pf-back{
      display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:999px;
      background:var(--c-surface);backdrop-filter:blur(10px);border:1px solid var(--c-border);
      color:var(--c-text);text-decoration:none;font-weight:600;font-size:.9rem;box-shadow:var(--shadow-soft);transition:.2s;
    }
    .pf-back:hover{transform:translateX(-3px);box-shadow:var(--shadow-pop);}

    /* Hero */
    .pf-hero{
      display:grid;grid-template-columns:auto 1fr;gap:22px;align-items:center;
      background:var(--c-surface);backdrop-filter:blur(14px);
      border:1px solid var(--c-border);border-radius:var(--r-card);
      padding:26px;box-shadow:var(--shadow-soft);margin-bottom:22px;
    }
    .pf-avatar{
      width:88px;height:88px;border-radius:50%;
      background:linear-gradient(135deg,var(--c-accent),var(--c-accent-2));
      color:#fff;display:flex;align-items:center;justify-content:center;
      font-family:'Sora',sans-serif;font-size:2.2rem;font-weight:700;
      box-shadow:var(--shadow-pop);
    }
    .pf-eyebrow{font-family:'Sora',sans-serif;font-size:.78rem;letter-spacing:.18em;text-transform:uppercase;color:var(--c-accent);font-weight:600;}
    .pf-name{
      font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.2vw,2.6rem);
      margin:4px 0 4px;line-height:1.05;
      background:linear-gradient(135deg,var(--c-accent),var(--c-accent-2));
      -webkit-background-clip:text;background-clip:text;color:transparent;
    }
    .pf-meta{color:var(--c-muted);font-size:.95rem;}

    /* Stats */
    .pf-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
    @media(max-width:680px){.pf-stats{grid-template-columns:1fr 1fr;} .pf-hero{grid-template-columns:1fr;text-align:center;justify-items:center;}}
    .pf-stat{
      background:var(--c-surface);backdrop-filter:blur(10px);
      border:1px solid var(--c-border);border-radius:var(--r-card);
      padding:18px;box-shadow:var(--shadow-soft);
    }
    .pf-stat-label{font-family:'Sora',sans-serif;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--c-muted);}
    .pf-stat-value{font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:700;margin-top:6px;color:var(--c-text);}

    /* Alerts */
    .pf-alert{
      padding:12px 16px;border-radius:12px;margin-bottom:16px;font-weight:500;font-size:.92rem;
      border:1px solid transparent;
    }
    .pf-alert.ok{background:#dcfce7;color:#15803d;border-color:#bbf7d0;}
    .pf-alert.err{background:#fee2e2;color:#b91c1c;border-color:#fecaca;}

    /* Grid */
    .pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;}
    @media(max-width:900px){.pf-grid{grid-template-columns:1fr;}}

    .pf-card{
      background:var(--c-surface);backdrop-filter:blur(14px);
      border:1px solid var(--c-border);border-radius:var(--r-card);
      padding:24px;box-shadow:var(--shadow-soft);
    }
    .pf-card h2{
      font-family:'Playfair Display',serif;font-size:1.4rem;margin:0 0 18px;color:var(--c-text);
      display:flex;align-items:center;gap:10px;
    }
    .pf-card h2::after{
      content:"";flex:1;height:1px;
      background:linear-gradient(90deg,var(--c-border),transparent);
    }

    .pf-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media(max-width:520px){.pf-row2{grid-template-columns:1fr;}}
    .pf-field{margin-bottom:14px;}
    .pf-field label{
      display:block;font-family:'Sora',sans-serif;font-weight:600;font-size:.78rem;
      color:var(--c-text);margin-bottom:6px;text-transform:uppercase;letter-spacing:.08em;
    }
    .pf-input{
      width:100%;padding:11px 14px;border-radius:var(--r-btn);
      border:1.5px solid var(--c-border);background:#fff;
      font-family:'DM Sans',sans-serif;font-size:.95rem;color:var(--c-text);transition:.2s;
    }
    .pf-input:focus{outline:none;border-color:var(--c-accent);box-shadow:0 0 0 4px rgba(124,58,237,.12);}

    .pf-btn{
      width:100%;padding:13px 18px;border:none;border-radius:var(--r-btn);
      font-family:'Sora',sans-serif;font-weight:600;font-size:.95rem;cursor:pointer;
      background:linear-gradient(135deg,var(--c-accent),var(--c-accent-2));
      color:#fff;box-shadow:var(--shadow-pop);transition:.2s;margin-top:6px;
    }
    .pf-btn:hover{transform:translateY(-2px);box-shadow:0 22px 50px rgba(124,58,237,.28);}

    /* Orders */
    .pf-orders{margin-top:28px;}
    .pf-orders-head{
      display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;
    }
    .pf-orders-head h2{
      font-family:'Playfair Display',serif;font-size:1.7rem;margin:0;color:var(--c-text);
    }
    .pf-view-all{
      padding:9px 18px;border-radius:999px;text-decoration:none;font-weight:600;font-size:.85rem;
      background:#fff;color:var(--c-accent);border:1.5px solid var(--c-border);transition:.2s;
    }
    .pf-view-all:hover{border-color:var(--c-accent);transform:translateY(-2px);}

    .pf-order{
      display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;
      padding:16px 18px;border-radius:14px;background:#fff;
      border:1px solid var(--c-border);margin-bottom:10px;transition:.2s;
    }
    .pf-order:hover{transform:translateY(-2px);box-shadow:var(--shadow-pop);}
    .pf-order-id{font-family:'Sora',sans-serif;font-weight:600;color:var(--c-text);}
    .pf-order-date{font-size:.82rem;color:var(--c-muted);margin-top:2px;}
    .pf-order-meta{font-size:.85rem;color:var(--c-muted);margin-top:6px;}
    .pf-order-right{text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px;}
    .pf-status{
      display:inline-block;padding:4px 12px;border-radius:999px;
      font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
    }
    .pf-status.pending{background:#fef3c7;color:#92400e;}
    .pf-status.processing{background:#dbeafe;color:#1e40af;}
    .pf-status.completed{background:#dcfce7;color:#166534;}
    .pf-status.cancelled{background:#fee2e2;color:#b91c1c;}
    .pf-order-total{
      font-family:'Sora',sans-serif;font-weight:700;
      background:linear-gradient(135deg,var(--c-accent),var(--c-accent-2));
      -webkit-background-clip:text;background-clip:text;color:transparent;
    }

    .pf-empty{
      text-align:center;padding:48px 20px;color:var(--c-muted);
      background:var(--c-surface);border:1px dashed var(--c-border);border-radius:16px;
    }
    .pf-empty-icon{font-size:3rem;margin-bottom:10px;}
    .pf-empty .pf-btn{display:inline-block;width:auto;margin-top:14px;padding:11px 24px;text-decoration:none;}
  </style>
</head>
<body>
<div class="layout">
  <div class="main">
    <div class="pf-wrap">
      <div class="pf-inner">

        <div class="pf-top">
          <a href="home.php" class="pf-back">← Back to Shop</a>
        </div>

        <!-- Hero -->
        <div class="pf-hero">
          <div class="pf-avatar"><?= htmlspecialchars($initials) ?></div>
          <div>
            <div class="pf-eyebrow">My Account</div>
            <h1 class="pf-name"><?= htmlspecialchars($customer['full_name']) ?></h1>
            <div class="pf-meta">
              <?= htmlspecialchars($customer['email']) ?>
              <?php if (!empty($customer['organization'])): ?> · <?= htmlspecialchars($customer['organization']) ?><?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Stats -->
        <div class="pf-stats">
          <div class="pf-stat"><div class="pf-stat-label">Total Orders</div><div class="pf-stat-value"><?= $total_orders ?></div></div>
          <div class="pf-stat"><div class="pf-stat-label">Completed</div><div class="pf-stat-value"><?= $completed ?></div></div>
          <div class="pf-stat"><div class="pf-stat-label">Total Spent</div><div class="pf-stat-value">₱<?= number_format($total_spent, 2) ?></div></div>
        </div>

        <?php if ($message): ?><div class="pf-alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="pf-alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="pf-grid">
          <!-- Personal Info -->
          <div class="pf-card">
            <h2>👤 Personal Information</h2>
            <form method="POST" action="">
              <div class="pf-row2">
                <div class="pf-field">
                  <label>Full Name *</label>
                  <input type="text" name="full_name" class="pf-input" value="<?= htmlspecialchars($customer['full_name']) ?>" required>
                </div>
                <div class="pf-field">
                  <label>Email *</label>
                  <input type="email" name="email" class="pf-input" value="<?= htmlspecialchars($customer['email']) ?>" required>
                </div>
              </div>
              <div class="pf-row2">
                <div class="pf-field">
                  <label>Contact Number</label>
                  <input type="tel" name="contact" class="pf-input" value="<?= htmlspecialchars($customer['contact'] ?? '') ?>" placeholder="+63 9XX XXX XXXX">
                </div>
                <div class="pf-field">
                  <label>Organization</label>
                  <input type="text" name="organization" class="pf-input" value="<?= htmlspecialchars($customer['organization'] ?? '') ?>" placeholder="Optional">
                </div>
              </div>
              <div class="pf-field">
                <label>Birthday</label>
                <input type="date" name="birthday" class="pf-input" value="<?= htmlspecialchars($customer['birthday'] ?? '') ?>">
              </div>
              <button type="submit" name="update_profile" class="pf-btn">Save Changes</button>
            </form>
          </div>

          <!-- Security -->
          <div class="pf-card">
            <h2>Security</h2>
            <form method="POST" action="">
              <div class="pf-field">
                <label>Current Password *</label>
                <input type="password" name="current_password" class="pf-input" required>
              </div>
              <div class="pf-field">
                <label>New Password *</label>
                <input type="password" name="new_password" class="pf-input" placeholder="Minimum 6 characters" required>
              </div>
              <div class="pf-field">
                <label>Confirm New Password *</label>
                <input type="password" name="confirm_password" class="pf-input" required>
              </div>
              <button type="submit" name="change_password" class="pf-btn">Change Password</button>
            </form>
          </div>
        </div>

        <!-- Recent Orders -->
        <div class="pf-orders">
          <div class="pf-orders-head">
            <h2>Recent Orders</h2>
            <a href="orders.php" class="pf-view-all">View All →</a>
          </div>

          <?php if (!empty($orders_data)): ?>
            <?php foreach (array_slice($orders_data, 0, 5) as $order):
              $items = $conn->query("SELECT COUNT(*) as count FROM order_items WHERE order_id={$order['id']}")->fetch_assoc();
              $count = (int)$items['count'];
              $status = strtolower($order['status']);
            ?>
              <div class="pf-order">
                <div>
                  <div class="pf-order-id">Order #<?= $order['id'] ?></div>
                  <div class="pf-order-date"><?= date('M d, Y · g:i A', strtotime($order['created_at'])) ?></div>
                  <div class="pf-order-meta">
                    <?= $count ?> item<?= $count === 1 ? '' : 's' ?>
                    <?php if (!empty($order['location'])): ?> · 📍 <?= htmlspecialchars($order['location']) ?><?php endif; ?>
                  </div>
                </div>
                <div class="pf-order-right">
                  <span class="pf-status <?= htmlspecialchars($status) ?>"><?= htmlspecialchars($order['status']) ?></span>
                  <div class="pf-order-total">₱<?= number_format($order['total_amount'], 2) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="pf-empty">
              <div class="pf-empty-icon"></div>
              <p>You haven't placed any orders yet.</p>
              <a href="home.php" class="pf-btn">Start Shopping</a>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</div>
</body>
</html>
