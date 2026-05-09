<?php
require_once '../../includes/config.php';
requireCustomerLogin();

function dbImageToDataUrl(?string $blob): ?string
{
    if (empty($blob)) return null;
    if (str_starts_with($blob, 'data:image/')) return $blob;
    return 'data:image/jpeg;base64,' . base64_encode($blob);
}

$product_id  = (int)($_GET['product_id'] ?? ($_GET['item_id'] ?? 0));
$customer_id = $_SESSION['customer_id'];
$embed       = isset($_GET['embed']) && $_GET['embed'] == '1';
$message = '';
$error = '';

// 1. Product
$product_query = $conn->query("SELECT item_name, profile_image, description, price FROM inventory WHERE id = $product_id");
$product = $product_query ? $product_query->fetch_assoc() : null;

if (!$product) {
    if ($embed) { echo '<p style="padding:24px;font-family:sans-serif;">Product not found.</p>'; exit; }
    header("Location: home.php"); exit;
}

// 2. Existing review by this customer
$my_review_query = $conn->query("SELECT * FROM reviews WHERE product_id = $product_id AND customer_id = $customer_id");
$my_review = $my_review_query ? $my_review_query->fetch_assoc() : null;

// 3. Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating  = (int)$_POST['rating'];
    $comment = clean($conn, $_POST['comment']);

    if ($rating >= 1 && $rating <= 5 && !empty($comment)) {
        if ($my_review) {
            $stmt = $conn->prepare("UPDATE reviews SET rating = ?, comment = ?, created_at = NOW() WHERE id = ?");
            $stmt->bind_param("isi", $rating, $comment, $my_review['id']);
        } else {
            $stmt = $conn->prepare("INSERT INTO reviews (product_id, customer_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("iiis", $product_id, $customer_id, $rating, $comment);
        }
        if ($stmt->execute()) {
            $qs = "product_id=$product_id&success=1" . ($embed ? '&embed=1' : '');
            header("Location: product_reviews.php?$qs"); exit;
        }
    } else {
        $error = "Please provide a rating and a comment.";
    }
}

if (isset($_GET['success'])) $message = "Review saved successfully!";

// 4. All reviews
$reviews = $conn->query("
    SELECT r.*, c.full_name
    FROM reviews r
    JOIN customers c ON r.customer_id = c.id
    WHERE r.product_id = $product_id
    ORDER BY r.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reviews for <?= htmlspecialchars($product['item_name']) ?> | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Sora:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../css/style.css">
  <style>
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
      --c-star: #f59e0b;
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
    body.embed { background: transparent; min-height: 0; }
    body:not(.embed)::before, body:not(.embed)::after {
      content: '';
      position: fixed; width: 500px; height: 500px;
      border-radius: 50%; filter: blur(120px);
      opacity: .35; z-index: -1; pointer-events: none;
      animation: blobFloat 18s ease-in-out infinite;
    }
    body:not(.embed)::before { background: radial-gradient(circle, #c4b5fd, transparent 70%); top: -150px; left: -150px; }
    body:not(.embed)::after  { background: radial-gradient(circle, #fbcfe8, transparent 70%); bottom: -150px; right: -150px; animation-delay: -9s; }
    @keyframes blobFloat {
      0%,100% { transform: translate(0,0) scale(1); }
      33%     { transform: translate(60px,-40px) scale(1.1); }
      66%     { transform: translate(-40px,60px) scale(.95); }
    }

    .cust-page { max-width: 1180px; margin: 0 auto; padding: 24px; position: relative; z-index: 1; }
    body.embed .cust-page { padding: 20px; max-width: 100%; }

    /* Topbar (hidden in embed) */
    .topbar {
      display: flex; justify-content: space-between; align-items: center;
      padding: 14px 22px; margin-bottom: 28px;
      background: rgba(255,255,255,.7);
      backdrop-filter: blur(20px) saturate(140%);
      border: 1.5px solid rgba(255,255,255,.8);
      border-radius: 18px;
      box-shadow: 0 4px 24px rgba(109,40,217,.06);
      position: sticky; top: 16px; z-index: 100;
    }
    .topbar-logo {
      font-family: var(--font-display); font-weight: 800; font-size: 1.4rem;
      background: var(--grad-primary);
      -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .topbar-nav { display: flex; gap: 6px; }
    .topbar-nav a {
      padding: 8px 14px; border-radius: 10px; font-weight: 600; font-size: .9rem;
      color: var(--c-text); text-decoration: none; transition: all .25s;
    }
    .topbar-nav a:hover { background: var(--c-accent-light); color: var(--c-accent); }
    body.embed .topbar { display: none; }

    /* Hero / product header */
    .hero {
      position: relative; overflow: hidden; border-radius: 28px;
      padding: 30px 32px; margin-bottom: 22px;
      background: var(--grad-hero);
      border: 1.5px solid rgba(255,255,255,.8);
      box-shadow: var(--shadow-card);
      animation: fadeUp .6s cubic-bezier(.22,1,.36,1) both;
    }
    .hero-inner { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
    .hero-thumb {
      width: 110px; height: 110px; border-radius: 18px; overflow: hidden;
      background: linear-gradient(135deg, #ede9fe, #fce7f3);
      display: flex; align-items: center; justify-content: center;
      font-size: 2rem; flex-shrink: 0;
      border: 1.5px solid rgba(255,255,255,.9);
      box-shadow: var(--shadow-card);
    }
    .hero-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .hero-info { flex: 1; min-width: 220px; }
    .hero-eyebrow {
      display: inline-block; padding: 5px 14px;
      background: rgba(255,255,255,.8); border: 1px solid rgba(255,255,255,.9);
      border-radius: 99px; font-size: .7rem; font-weight: 700;
      letter-spacing: 1.2px; text-transform: uppercase;
      color: var(--c-accent-deep); margin-bottom: 10px;
    }
    .hero h2 {
      font-family: var(--font-display); font-size: 1.8rem; font-weight: 800;
      line-height: 1.15; color: var(--c-text);
    }
    .hero h2 .accent {
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text;
      color: transparent; font-style: italic;
    }
    .hero .price {
      font-family: var(--font-display); font-size: 1.4rem; font-weight: 800; margin-top: 6px;
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .hero .desc { color: var(--c-muted); margin-top: 8px; font-size: .92rem; }

    /* Alerts */
    .alert {
      padding: 14px 18px; border-radius: 14px; margin-bottom: 16px;
      font-weight: 600; display: flex; align-items: center; gap: 10px;
      animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both;
    }
    .alert-success { background: var(--c-ok-bg); color: #14532d; border: 1px solid #86efac; }
    .alert-danger  { background: var(--c-err-bg); color: #7f1d1d; border: 1px solid #fecaca; }

    /* Layout */
    .reviews-container {
      display: grid; grid-template-columns: 1fr 360px; gap: 20px;
    }
    @media (max-width: 860px) { .reviews-container { grid-template-columns: 1fr; } }

    .panel {
      background: rgba(255,255,255,.9); backdrop-filter: blur(12px);
      border: 1.5px solid rgba(255,255,255,.8);
      border-radius: var(--r-card);
      box-shadow: var(--shadow-card);
      padding: 22px;
      animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both;
    }
    .panel h3 {
      font-family: var(--font-head); font-weight: 800; font-size: 1.05rem;
      color: var(--c-text); margin-bottom: 14px;
      display: flex; align-items: center; gap: 8px;
    }
    .panel h3 .hash {
      background: var(--grad-primary); -webkit-background-clip: text; background-clip: text; color: transparent;
    }

    .review-item {
      padding: 14px; border-radius: 14px;
      border: 1px solid var(--c-border);
      background: rgba(245,243,255,.5);
      margin-bottom: 12px;
      transition: background .2s, transform .2s;
    }
    .review-item:hover { background: var(--c-accent-light); transform: translateY(-1px); }
    .review-header {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 6px; flex-wrap: wrap; gap: 8px;
    }
    .review-author { font-weight: 700; color: var(--c-text); font-size: .95rem; }
    .review-date { font-size: .78rem; color: var(--c-muted); }
    .review-rating { color: var(--c-star); margin-bottom: 6px; font-size: 1rem; letter-spacing: 2px; }
    .review-comment { color: var(--c-text); font-size: .92rem; line-height: 1.5; }
    .review-reply {
      background: var(--c-ok-bg); border-left: 3px solid var(--c-ok);
      padding: 10px 12px; margin-top: 10px; border-radius: 8px;
    }
    .review-reply-label {
      font-size: .72rem; font-weight: 800; color: #166534;
      text-transform: uppercase; letter-spacing: .8px; margin-bottom: 4px;
    }

    .empty-reviews {
      text-align: center; padding: 36px 16px; color: var(--c-muted);
      border: 1.5px dashed var(--c-border-strong); border-radius: 16px;
    }
    .empty-reviews .em-ico { font-size: 2.4rem; opacity: .6; margin-bottom: 8px; }

    /* Form */
    .form-side { position: sticky; top: 20px; align-self: flex-start; }
    .form-group { margin-bottom: 14px; }
    .form-label {
      display: block; font-weight: 700; font-size: .8rem;
      letter-spacing: .5px; text-transform: uppercase; color: var(--c-muted);
      margin-bottom: 8px;
    }
    .rating-input { display: flex; gap: 6px; }
    .star-btn {
      background: none; border: none; font-size: 1.9rem; cursor: pointer;
      filter: grayscale(1); opacity: .35;
      transition: all .2s; padding: 0; line-height: 1;
    }
    .star-btn:hover { transform: scale(1.15); }
    .star-btn.active { filter: none; opacity: 1; transform: scale(1.05); }

    .form-control {
      width: 100%; padding: 12px 14px; border-radius: 12px;
      border: 1.5px solid var(--c-border); background: #fff;
      font-family: var(--font-body); font-size: .95rem; color: var(--c-text);
      resize: vertical; transition: border-color .2s, box-shadow .2s;
    }
    .form-control:focus {
      outline: none; border-color: var(--c-accent-mid);
      box-shadow: 0 0 0 4px rgba(167,139,250,.2);
    }

    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      padding: 12px 18px; border-radius: var(--r-btn);
      font-weight: 700; font-size: .9rem; text-decoration: none;
      border: none; cursor: pointer; transition: all .25s;
      font-family: var(--font-body);
    }
    .btn-primary {
      background: var(--grad-primary); color: #fff;
      box-shadow: 0 4px 14px rgba(124,58,237,.3); width: 100%;
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(124,58,237,.45); }
    .btn-ghost {
      background: rgba(255,255,255,.85); color: var(--c-text);
      border: 1.5px solid var(--c-border);
    }
    .btn-ghost:hover { border-color: var(--c-accent-mid); color: var(--c-accent); }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">

<?php if (!$embed): ?>
  <a href="orders.php" 
    style="
    display: inline-flex; align-items: center; gap: 8px;
    margin: 24px 24px 0;
    padding: 10px 18px; border-radius: 99px;
    background: rgba(255,255,255,.8); backdrop-filter: blur(10px);
    border: 1.5px solid #ede9fe;
    font-weight: 700; font-size: .9rem; color: #1e1b4b;
    text-decoration: none; transition: all .25s;
    box-shadow: 0 2px 8px rgba(109,40,217,.06);
  ">← Back to Orders</a>
<?php endif; ?>

<div class="cust-page">

  <!-- Product hero -->
  <section class="hero">
    <div class="hero-inner">
      <?php $imgUrl = dbImageToDataUrl($product['profile_image']); ?>
      <div class="hero-thumb">
        <?php if ($imgUrl): ?>
          <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($product['item_name']) ?>">
        <?php else: ?>
          <span>📷</span>
        <?php endif; ?>
      </div>
      <div class="hero-info">
        <span class="hero-eyebrow">Product Reviews</span>
        <h2>Reviews for <span class="accent"><?= htmlspecialchars($product['item_name']) ?></span></h2>
        <div class="price">₱<?= number_format($product['price'], 2) ?></div>
        <p class="desc"><?= htmlspecialchars($product['description'] ?? 'No description available.') ?></p>
      </div>
    </div>
  </section>

  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="reviews-container">
    <!-- Reviews list -->
    <div class="panel">
      <h3><span class="hash">#</span> Customer Feedback</h3>
      <?php if ($reviews && $reviews->num_rows > 0): ?>
        <?php while ($r = $reviews->fetch_assoc()): ?>
          <div class="review-item">
            <div class="review-header">
              <span class="review-author">👤 <?= htmlspecialchars($r['full_name']) ?></span>
              <span class="review-date">📅 <?= date('M d, Y', strtotime($r['created_at'])) ?></span>
            </div>
            <div class="review-rating"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></div>
            <p class="review-comment"><?= htmlspecialchars($r['comment']) ?></p>
            <?php if (!empty($r['admin_reply'])): ?>
              <div class="review-reply">
                <div class="review-reply-label">💬 Admin Response</div>
                <p><?= htmlspecialchars($r['admin_reply']) ?></p>
              </div>
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="empty-reviews">
          <div class="em-ico">📝</div>
          <p>No reviews yet. Be the first to share your experience!</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Review form -->
    <div class="form-side">
      <div class="panel">
        <h3><?= $my_review ? 'Edit Your Review' : 'Leave a Review' ?></h3>
        <form method="POST">
          <div class="form-group">
            <label class="form-label">Your Rating</label>
            <div class="rating-input" id="ratingInput">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="star-btn <?= ($my_review && $my_review['rating'] >= $i) ? 'active' : '' ?>"
                        onclick="setRating(<?= $i ?>)" data-rating="<?= $i ?>">⭐</button>
              <?php endfor; ?>
            </div>
            <input type="hidden" name="rating" id="ratingValue" value="<?= $my_review['rating'] ?? 0 ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Your Message</label>
            <textarea name="comment" class="form-control" rows="5" placeholder="Tell us what you think..." required><?= $my_review ? htmlspecialchars($my_review['comment']) : '' ?></textarea>
          </div>

          <button type="submit" name="submit_review" class="btn btn-primary">
            <?= $my_review ? 'Update Review' : 'Submit Review' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function setRating(rating) {
  document.getElementById('ratingValue').value = rating;
  document.querySelectorAll('.star-btn').forEach(btn => {
    const r = parseInt(btn.getAttribute('data-rating'));
    btn.classList.toggle('active', r <= rating);
  });
}
</script>
</body>
</html>
