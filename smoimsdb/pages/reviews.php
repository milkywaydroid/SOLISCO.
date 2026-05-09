<?php
require_once '../includes/config.php';
requireStaffLogin();

$message = '';
$error   = '';

/* ── Handle: Submit Admin Reply ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reply'])) {
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $reply    = clean($conn, $_POST['reply_text'] ?? '');

    if (!$reviewId || $reply === '') {
        $error = 'Reply cannot be empty.';
    } else {
        if ($conn->query("UPDATE reviews SET admin_reply = '$reply' WHERE id = $reviewId")) {
            $message = 'Reply posted successfully!';
        } else {
            $error = 'Failed to post reply. Please try again.';
        }
    }
}

/* ── Filters ── */
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterRating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$filterReply  = isset($_GET['reply']) ? $_GET['reply'] : ''; // '', 'replied', 'pending'

$where = [];
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where[] = "(c.full_name LIKE '%$s%' OR i.item_name LIKE '%$s%' OR r.comment LIKE '%$s%')";
}
if ($filterRating >= 1 && $filterRating <= 5) {
    $where[] = "r.rating = $filterRating";
}
if ($filterReply === 'replied') {
    $where[] = "(r.admin_reply IS NOT NULL AND r.admin_reply <> '')";
} elseif ($filterReply === 'pending') {
    $where[] = "(r.admin_reply IS NULL OR r.admin_reply = '')";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── Query: Reviews ── */
$reviews = $conn->query("
    SELECT r.*, i.item_name, c.full_name
    FROM reviews r
    JOIN inventory i ON r.product_id = i.id
    JOIN customers c ON r.customer_id = c.id
    $whereSql
    ORDER BY r.created_at DESC
");

/* ── KPI Stats (overall, ignore filters) ── */
$kpiTotal     = (int)($conn->query("SELECT COUNT(*) c FROM reviews")->fetch_assoc()['c'] ?? 0);
$kpiAvg       = (float)($conn->query("SELECT COALESCE(AVG(rating),0) a FROM reviews")->fetch_assoc()['a'] ?? 0);
$kpiReplied   = (int)($conn->query("SELECT COUNT(*) c FROM reviews WHERE admin_reply IS NOT NULL AND admin_reply <> ''")->fetch_assoc()['c'] ?? 0);
$kpiPending   = $kpiTotal - $kpiReplied;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reviews | SolisCo.</title>
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

    /* KPI cards */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:24px}
    .stat-card{position:relative;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;overflow:hidden;transition:transform .4s var(--ease),box-shadow .4s var(--ease),border-color .3s var(--ease);animation:pop-in .5s var(--ease) both}
    .stat-card:hover{transform:translateY(-5px);box-shadow:var(--shadow-md);border-color:rgba(167,139,250,.4)}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--grad)}
    .stat-card.is-rating::before{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
    .stat-card.is-replied::before{background:linear-gradient(90deg,#10b981,#34d399)}
    .stat-card.is-pending::before{background:linear-gradient(90deg,#0ea5e9,#38bdf8)}
    .stat-icon{width:42px;height:42px;border-radius:12px;background:var(--grad-soft);display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:12px}
    .stat-value{font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:800;line-height:1.1}
    .stat-label{color:var(--muted);font-size:.78rem;font-weight:600;margin-top:6px;text-transform:uppercase;letter-spacing:.07em}

    /* Card */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow-sm);transition:box-shadow .35s var(--ease),transform .35s var(--ease);animation:fade-up .5s var(--ease) both;margin-bottom:24px}
    .card:hover{box-shadow:var(--shadow-md)}
    .card-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;font-size:1.05rem;font-weight:700;flex-wrap:wrap;gap:10px}
    .card-title .icon{width:34px;height:34px;border-radius:10px;background:var(--grad);color:#fff;display:inline-flex;align-items:center;justify-content:center;margin-right:10px;font-size:.95rem;box-shadow:0 6px 16px rgba(167,139,250,.35)}
    .card-title-left{display:flex;align-items:center}

    /* Filters */
    .filter-form{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    .form-control{padding:11px 16px;border-radius:12px;border:1px solid var(--border);background:var(--surface-2);font-family:inherit;font-size:.9rem;color:var(--ink);transition:border-color .25s var(--ease),box-shadow .25s var(--ease),background .25s var(--ease);outline:none}
    .form-control:focus{border-color:var(--pastel-accent);background:#fff;box-shadow:0 0 0 4px rgba(167,139,250,.15)}
    .form-label{font-weight:700;letter-spacing:.04em;color:var(--ink);font-size:.85rem;display:block;margin-bottom:6px}

    /* Buttons */
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 18px;border-radius:999px;font-weight:600;font-size:.85rem;border:none;cursor:pointer;font-family:inherit;transition:transform .25s var(--ease),box-shadow .25s var(--ease),background .25s var(--ease),color .25s var(--ease)}
    .btn-primary{background:var(--grad);color:#fff;box-shadow:0 6px 18px rgba(167,139,250,.4)}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(167,139,250,.55)}
    .btn-secondary{background:var(--surface-2);color:var(--ink);border:1px solid var(--border)}
    .btn-secondary:hover{background:#fff;border-color:var(--pastel-accent);color:var(--pastel-accent);transform:translateY(-2px)}
    .btn-sm{padding:8px 14px;font-size:.78rem}

    /* Badges */
    .badge{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:999px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
    .badge-replied{background:rgba(16,185,129,.14);color:#047857}
    .badge-pending{background:rgba(245,158,11,.14);color:#b45309}

    /* Alerts */
    .alert{padding:14px 18px;border-radius:14px;font-weight:600;font-size:.9rem;margin-bottom:18px;animation:fade-up .4s var(--ease) both;display:flex;align-items:center;gap:10px}
    .alert-success{background:rgba(16,185,129,.12);color:#047857;border:1px solid rgba(16,185,129,.25)}
    .alert-danger{background:rgba(239,68,68,.12);color:#b91c1c;border:1px solid rgba(239,68,68,.25)}

    /* Reviews list */
    .reviews-grid{display:grid;grid-template-columns:1fr;gap:18px}
    .review-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow-sm);transition:box-shadow .35s var(--ease),transform .35s var(--ease);animation:fade-up .45s var(--ease) both;position:relative;overflow:hidden}
    .review-card::before{content:'';position:absolute;top:0;left:0;bottom:0;width:4px;background:var(--grad)}
    .review-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px)}
    .review-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:10px}
    .review-customer{display:flex;align-items:center;gap:12px}
    .avatar{width:42px;height:42px;border-radius:50%;background:var(--grad);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;box-shadow:0 6px 16px rgba(167,139,250,.35);flex-shrink:0}
    .review-customer .name{font-weight:700;color:var(--ink);font-size:.95rem}
    .review-customer .meta{font-size:.78rem;color:var(--muted);margin-top:2px}
    .review-customer .meta strong{color:var(--ink-soft);font-weight:600}
    .stars{color:#f59e0b;font-size:1.05rem;letter-spacing:2px;line-height:1}
    .stars .empty{color:#e5e7eb}

    .review-comment{margin:14px 0 12px;color:var(--ink-soft);font-style:italic;font-size:.95rem;line-height:1.6;padding:14px 16px;background:var(--surface-2);border-radius:12px;border-left:3px solid var(--pastel-accent)}
    .review-meta-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;font-size:.78rem;color:var(--muted)}

    .reply-box{margin-top:14px;background:var(--grad-soft);border-radius:12px;padding:14px 16px;border:1px solid var(--border)}
    .reply-box .reply-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--pastel-accent);margin-bottom:6px;display:flex;align-items:center;gap:6px}
    .reply-box p{color:var(--ink);font-size:.9rem;line-height:1.5}

    .reply-form{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
    .reply-form .form-control{flex:1;min-width:240px}

    .empty{text-align:center;color:var(--muted);padding:60px 20px;font-size:.95rem}
    .empty .empty-emoji{font-size:3rem;display:block;margin-bottom:12px}

    .text-muted{color:var(--muted);font-size:.78rem}
  </style>
</head>
<body>
<div class="layout">
  <?php @include '../includes/sidebar.php'; ?>
  <main class="main">

    <div class="page-header">
      <div>
        <button class="menu-toggle" onclick="document.getElementById('appSidebar')?.classList.toggle('open')">☰ Menu</button>
        <h1 style="margin-top:8px"><span class="grad-text">Customer Reviews</span> ⭐</h1>
        <p>Monitor feedback and engage with your customers.</p>
      </div>
      <div class="date-pill"><?= date('l, F d, Y') ?></div>
    </div>

    <?php if ($message): ?><div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon">💬</div>
        <div class="stat-value"><?= number_format($kpiTotal) ?></div>
        <div class="stat-label">Total Reviews</div>
      </div>
      <div class="stat-card is-rating">
        <div class="stat-icon">⭐</div>
        <div class="stat-value"><?= number_format($kpiAvg, 1) ?> <span style="font-size:1rem;color:var(--muted);font-weight:600">/ 5</span></div>
        <div class="stat-label">Average Rating</div>
      </div>
      <div class="stat-card is-replied">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?= number_format($kpiReplied) ?></div>
        <div class="stat-label">Replied</div>
      </div>
      <div class="stat-card is-pending">
        <div class="stat-icon">📩</div>
        <div class="stat-value"><?= number_format($kpiPending) ?></div>
        <div class="stat-label">Awaiting Reply</div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">
        <div class="card-title-left"><span class="icon">🔍</span> Search & Filter</div>
      </div>
      <form method="GET" action="reviews.php" class="filter-form">
        <input type="text" name="search" class="form-control" style="flex:1;min-width:220px" placeholder="Search by customer, product, or comment..." value="<?= htmlspecialchars($search) ?>">
        <select name="rating" class="form-control" style="min-width:150px">
          <option value="0">All Ratings</option>
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= $filterRating === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?> (<?= $i ?>)</option>
          <?php endfor; ?>
        </select>
        <select name="reply" class="form-control" style="min-width:170px">
          <option value="">All Reviews</option>
          <option value="replied" <?= $filterReply === 'replied' ? 'selected' : '' ?>>Replied</option>
          <option value="pending" <?= $filterReply === 'pending' ? 'selected' : '' ?>>Awaiting Reply</option>
        </select>
        <button type="submit" class="btn btn-primary">Apply Filter</button>
        <a href="reviews.php" class="btn btn-secondary">Clear</a>
      </form>
    </div>

    <div class="card">
      <div class="card-title">
        <div class="card-title-left"><span class="icon">💬</span> All Reviews</div>
        <span class="text-muted"><?= $reviews ? $reviews->num_rows : 0 ?> result<?= ($reviews && $reviews->num_rows === 1) ? '' : 's' ?></span>
      </div>

      <div class="reviews-grid">
        <?php if ($reviews && $reviews->num_rows > 0): ?>
          <?php while ($r = $reviews->fetch_assoc()):
            $hasReply = !empty(trim((string)$r['admin_reply']));
            $initial  = strtoupper(mb_substr(trim($r['full_name']), 0, 1));
            $rating   = (int)$r['rating'];
          ?>
            <div class="review-card">
              <div class="review-head">
                <div class="review-customer">
                  <div class="avatar"><?= htmlspecialchars($initial ?: '?') ?></div>
                  <div>
                    <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                    <div class="meta">on <strong><?= htmlspecialchars($r['item_name']) ?></strong></div>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
                  <div class="stars">
                    <?= str_repeat('★', $rating) ?><span class="empty"><?= str_repeat('★', 5 - $rating) ?></span>
                  </div>
                  <span class="badge badge-<?= $hasReply ? 'replied' : 'pending' ?>">
                    <?= $hasReply ? '✓ Replied' : '⏳ Pending' ?>
                  </span>
                </div>
              </div>

              <div class="review-comment">"<?= htmlspecialchars($r['comment']) ?>"</div>

              <div class="review-meta-row">
                <span>📅 <?= date('M d, Y · g:i A', strtotime($r['created_at'])) ?></span>
                <span class="text-muted">Review #<?= (int)$r['id'] ?></span>
              </div>

              <?php if ($hasReply): ?>
                <div class="reply-box">
                  <div class="reply-label">💬 Admin Response</div>
                  <p><?= nl2br(htmlspecialchars($r['admin_reply'])) ?></p>
                </div>
              <?php else: ?>
                <form method="POST" action="reviews.php" class="reply-form">
                  <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                  <input type="text" name="reply_text" class="form-control" placeholder="Write a thoughtful reply..." required>
                  <button type="submit" name="submit_reply" class="btn btn-primary btn-sm">📤 Send Reply</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="empty">
            <span class="empty-emoji">📭</span>
            No reviews found. Try adjusting your filters.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
