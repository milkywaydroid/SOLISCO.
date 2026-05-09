<?php
/* ============================================================
   FILE: pages/customer/index.php
   Customer homepage / landing page — Redesigned
   ============================================================ */

require_once 'includes/config.php';

$is_logged_in = isset($_SESSION['customer_id']);
$customer_id  = $_SESSION['customer_id'] ?? null;

$featured   = $conn->query("
    SELECT i.*, 
           COALESCE(AVG(r.rating), 0) AS avg_rating, 
           COUNT(r.id)             AS review_count
    FROM inventory i
    LEFT JOIN reviews r ON r.product_id = i.id
    WHERE i.quantity > 0
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT 6
");
$categories = $conn->query("SELECT DISTINCT category FROM inventory WHERE quantity > 0 ORDER BY category ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SolisCo. | Premium Merchandise & Custom</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ============ Design Tokens ============ */
    :root {
      --pastel-accent: #a78bfa;
      --accent-2: #f0abfc;
      --accent-3: #818cf8;
      --ink: #1a1a2e;
      --ink-soft: #4b5563;
      --muted: #6b7280;
      --bg: #fbfaff;
      --surface: #ffffff;
      --surface-2: #f6f4ff;
      --border: rgba(167,139,250,.18);
      --shadow-sm: 0 4px 14px rgba(80, 60, 160, .08);
      --shadow-md: 0 14px 40px rgba(80, 60, 160, .14);
      --shadow-lg: 0 30px 70px rgba(80, 60, 160, .22);
      --grad: linear-gradient(135deg, #a78bfa 0%, #f0abfc 50%, #818cf8 100%);
      --radius: 16px;
      --ease: cubic-bezier(.22,1,.36,1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      line-height: 1.6;
      overflow-x: hidden;
    }

    ::selection { background: var(--pastel-accent); color: #fff; }

    /* ============ Scroll Reveal ============ */
    .reveal {
      opacity: 0;
      transform: translateY(40px);
      transition: opacity .9s var(--ease), transform .9s var(--ease);
      will-change: opacity, transform;
    }
    .reveal.visible { opacity: 1; transform: translateY(0); }
    .reveal.delay-1 { transition-delay: .1s; }
    .reveal.delay-2 { transition-delay: .2s; }
    .reveal.delay-3 { transition-delay: .3s; }
    .reveal.delay-4 { transition-delay: .4s; }

    /* ============ Navbar ============ */
    .navbar {
      background: rgba(255, 255, 255, .72);
      backdrop-filter: saturate(180%) blur(16px);
      -webkit-backdrop-filter: saturate(180%) blur(16px);
      padding: 14px 0;
      box-shadow: 0 1px 0 var(--border);
      position: sticky; top: 0; z-index: 100;
      transition: padding .3s var(--ease), box-shadow .3s var(--ease);
    }
    .navbar.scrolled { padding: 8px 0; box-shadow: 0 8px 30px rgba(80,60,160,.10); }
    .navbar-container {
      max-width: 1200px; margin: 0 auto; padding: 0 24px;
      display: flex; justify-content: space-between; align-items: center;
    }
    .navbar-brand {
      font-family: 'Playfair Display', serif;
      font-size: 1.5rem; font-weight: 800;
      background: var(--grad);
      -webkit-background-clip: text; background-clip: text; color: transparent;
      text-decoration: none; display: flex; align-items: center; gap: 8px;
    }
    .navbar-menu { display: flex; gap: 28px; align-items: center; }
    .navbar-menu a {
      color: var(--ink); text-decoration: none; font-weight: 500;
      font-size: .95rem; position: relative; padding: 4px 0;
      transition: color .2s var(--ease);
    }
    .navbar-menu a:not(.btn)::after {
      content: ''; position: absolute; left: 0; bottom: -2px;
      width: 100%; height: 2px;
      background: var(--grad); border-radius: 2px;
      transform: scaleX(0); transform-origin: right;
      transition: transform .35s var(--ease);
    }
    .navbar-menu a:not(.btn):hover { color: var(--pastel-accent); }
    .navbar-menu a:not(.btn):hover::after { transform: scaleX(1); transform-origin: left; }
    .navbar-menu .btn {
      padding: 10px 22px; border-radius: 999px; border: none;
      background: var(--grad); background-size: 200% 200%;
      color: #fff; cursor: pointer; font-weight: 600;
      transition: background-position .5s var(--ease), transform .25s var(--ease), box-shadow .25s var(--ease);
      box-shadow: 0 6px 20px rgba(167,139,250,.4);
    }
    .navbar-menu .btn:hover {
      background-position: 100% 0; transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(167,139,250,.55);
    }

    /* ============ Hero ============ */
    .hero {
      position: relative;
      min-height: 92vh;
      display: flex; align-items: center; justify-content: center;
      padding: 120px 24px;
      color: #fff; text-align: center;
      background:
        linear-gradient(135deg, rgba(26,26,46,.55), rgba(80,40,140,.45)),
        url('images/bgindex.jpg') center/cover no-repeat;
      overflow: hidden;
    }
    .hero::before {
      content: ''; position: absolute; inset: -20%;
      background:
        radial-gradient(600px circle at 20% 30%, rgba(240,171,252,.35), transparent 60%),
        radial-gradient(500px circle at 80% 70%, rgba(129,140,248,.35), transparent 60%);
      animation: drift 18s var(--ease) infinite alternate;
      pointer-events: none;
    }
    @keyframes drift {
      0%   { transform: translate(0,0) scale(1); }
      100% { transform: translate(40px,-30px) scale(1.1); }
    }

    .hero-container { position: relative; z-index: 2; max-width: 820px; }
    .hero-eyebrow {
      display: inline-block; padding: 6px 14px; margin-bottom: 22px;
      border-radius: 999px; background: rgba(255,255,255,.14);
      backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,.25);
      font-size: .8rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase;
      animation: fadeDown 1s var(--ease) both;
    }
    .hero h1 {
      font-family: 'Playfair Display', serif;
      font-size: clamp(2.4rem, 5.5vw, 4.4rem);
      font-weight: 800; line-height: 1.05; margin-bottom: 20px;
      animation: fadeUp 1s var(--ease) .15s both;
    }
    .hero h1 .grad-text {
      background: linear-gradient(90deg, #f0abfc, #fff, #c4b5fd);
      background-size: 200% 100%;
      -webkit-background-clip: text; background-clip: text; color: transparent;
      animation: shimmer 4s linear infinite;
    }
    @keyframes shimmer { to { background-position: 200% 0; } }

    .hero p {
      font-size: 1.15rem; max-width: 600px; margin: 0 auto 36px;
      opacity: .92; animation: fadeUp 1s var(--ease) .3s both;
    }
    .hero-buttons {
      display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;
      animation: fadeUp 1s var(--ease) .45s both;
    }
    .hero-buttons .btn {
      padding: 14px 32px; font-size: 1rem; border-radius: 999px;
      border: none; cursor: pointer; font-weight: 600; text-decoration: none;
      display: inline-flex; align-items: center; gap: 8px;
      transition: transform .3s var(--ease), box-shadow .3s var(--ease), background .3s var(--ease);
    }
    .btn-primary-light {
      background: #fff; color: var(--pastel-accent);
      box-shadow: 0 10px 30px rgba(0,0,0,.2);
    }
    .btn-primary-light:hover {
      transform: translateY(-3px) scale(1.03);
      box-shadow: 0 16px 40px rgba(0,0,0,.28);
    }
    .btn-secondary-light {
      background: transparent; color: #fff; border: 2px solid rgba(255,255,255,.6);
    }
    .btn-secondary-light:hover {
      background: rgba(255,255,255,.12);
      border-color: #fff; transform: translateY(-3px);
    }

    @keyframes fadeUp   { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

    /* Hero scroll cue */
    .scroll-cue {
      position: absolute; bottom: 28px; left: 50%; transform: translateX(-50%);
      width: 26px; height: 42px; border: 2px solid rgba(255,255,255,.6);
      border-radius: 999px; z-index: 2;
    }
    .scroll-cue::after {
      content: ''; position: absolute; top: 8px; left: 50%;
      width: 4px; height: 8px; background: #fff; border-radius: 2px;
      transform: translateX(-50%);
      animation: scrollDot 1.6s var(--ease) infinite;
    }
    @keyframes scrollDot {
      0% { opacity: 0; transform: translate(-50%, 0); }
      40% { opacity: 1; }
      100% { opacity: 0; transform: translate(-50%, 14px); }
    }

    /* ============ Section helpers ============ */
    section { scroll-margin-top: 80px; }
    .section-title {
      font-family: 'Playfair Display', serif;
      font-size: clamp(1.8rem, 3.5vw, 2.6rem);
      font-weight: 700; margin-bottom: 14px; text-align: center; color: var(--ink);
    }
    .section-sub {
      text-align: center; color: var(--muted); max-width: 620px;
      margin: 0 auto 48px; font-size: 1.05rem;
    }

    /* ============ Features ============ */
    .features { max-width: 1200px; margin: 110px auto; padding: 0 24px; }
    .features-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 24px;
    }
    .feature-card {
      position: relative; background: var(--surface);
      border: 1px solid var(--border); border-radius: var(--radius);
      padding: 36px 28px; text-align: center;
      transition: transform .4s var(--ease), box-shadow .4s var(--ease), border-color .4s var(--ease);
      overflow: hidden;
    }
    .feature-card::before {
      content: ''; position: absolute; inset: 0;
      background: var(--grad); opacity: 0;
      transition: opacity .4s var(--ease);
      z-index: 0;
    }
    .feature-card > * { position: relative; z-index: 1; transition: color .4s var(--ease); }
    .feature-card:hover {
      transform: translateY(-8px); box-shadow: var(--shadow-lg);
      border-color: transparent;
    }
    .feature-card:hover::before { opacity: 1; }
    .feature-card:hover h3, .feature-card:hover p { color: #fff; }
    .feature-icon {
      font-size: 2.6rem; margin-bottom: 18px; display: inline-block;
      transition: transform .5s var(--ease);
    }
    .feature-card:hover .feature-icon { transform: scale(1.15) rotate(-6deg); }
    .feature-card h3 { margin-bottom: 10px; font-size: 1.2rem; color: var(--ink); }
    .feature-card p  { color: var(--ink-soft); }

    /* ============ Featured Products ============ */
    .featured-section { max-width: 1240px; margin: 110px auto; padding: 0 24px; }
    .products-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
      gap: 28px;
      margin-top: 48px;
    }
    .product-card {
      position: relative;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow-sm);
      transition: transform .45s var(--ease), box-shadow .45s var(--ease), border-color .3s var(--ease);
    }
    .product-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-lg);
      border-color: rgba(167,139,250,.45);
    }
    .product-image {
      position: relative;
      width: 100%;
      aspect-ratio: 1 / 1;
      background: linear-gradient(135deg, #f5f0ff 0%, #fce7f3 100%);
      overflow: hidden;
      display: flex; align-items: center; justify-content: center;
    }
    .product-image img {
      width: 100%; height: 100%;
      object-fit: cover;
      transition: transform .8s var(--ease);
    }
    .product-card:hover .product-image img { transform: scale(1.08); }
    .product-image .no-image {
      color: var(--pastel-accent);
      font-weight: 700; letter-spacing: .08em; font-size: .85rem;
      text-transform: uppercase; opacity: .6;
    }
    .product-image::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,.45) 50%, transparent 70%);
      transform: translateX(-100%);
      transition: transform .9s var(--ease);
      pointer-events: none;
    }
    .product-card:hover .product-image::after { transform: translateX(100%); }

    .product-badge {
      position: absolute; top: 14px; left: 14px;
      background: rgba(255,255,255,.92);
      backdrop-filter: blur(6px);
      color: var(--pastel-accent);
      font-size: .7rem; font-weight: 700;
      letter-spacing: .08em; text-transform: uppercase;
      padding: 6px 10px; border-radius: 999px;
      border: 1px solid var(--border);
    }
    .product-badge.low { color: #db2777; }

    .product-info {
      padding: 20px;
      display: flex; flex-direction: column; gap: 8px;
      flex: 1;
    }
    .product-category {
      font-size: .72rem; font-weight: 600; letter-spacing: .1em;
      text-transform: uppercase; color: var(--pastel-accent);
    }
    .product-name {
      font-weight: 700; color: var(--ink);
      font-size: 1.02rem; line-height: 1.35;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
      overflow: hidden; min-height: 2.7em;
    }

    .product-rating {
      display: flex; align-items: center; gap: 6px;
      font-size: .82rem; color: var(--muted);
    }
    .stars { position: relative; display: inline-block; font-size: .95rem; line-height: 1; letter-spacing: 2px; font-family: Arial, sans-serif; }
    .stars .stars-bg   { color: #e5e0f5; }
    .stars .stars-fill {
      position: absolute; inset: 0; overflow: hidden; white-space: nowrap;
      color: #f5b301;
    }
    .product-rating .count { color: var(--muted); }
    .product-rating .no-reviews { color: var(--muted); font-style: italic; }

    .product-meta {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: 4px;
    }
    .product-price {
      font-size: 1.25rem; font-weight: 800;
      background: var(--grad);
      -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .product-stock { font-size: .78rem; color: var(--muted); }

    .product-btn {
      margin-top: 14px; width: 100%; padding: 11px 14px;
      background: var(--ink); color: #fff;
      border: none; border-radius: 12px; cursor: pointer;
      font-weight: 600; font-size: .92rem;
      transition: background .3s var(--ease), transform .2s var(--ease), box-shadow .3s var(--ease);
    }
    .product-btn:hover {
      background: var(--pastel-accent);
      transform: translateY(-1px);
      box-shadow: 0 8px 20px rgba(167,139,250,.35);
    }

    @media (max-width: 600px) {
      .products-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; }
      .product-info  { padding: 14px; }
      .product-name  { font-size: .92rem; min-height: 2.6em; }
      .product-price { font-size: 1.05rem; }
      .product-btn   { padding: 9px 10px; font-size: .85rem; }
    }

    /* ============ About ============ */
    .about {
      background: linear-gradient(180deg, var(--surface-2) 0%, #fff 100%);
      padding: 110px 24px; margin-top: 110px;
      border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
    }
    .about-container {
      max-width: 1200px; margin: 0 auto;
      display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center;
    }
    @media (max-width: 768px) { .about-container { grid-template-columns: 1fr; } }
    .about-content h2 {
      font-family: 'Playfair Display', serif;
      font-size: clamp(1.8rem, 3vw, 2.4rem); margin-bottom: 20px; color: var(--ink);
    }
    .about-content p { color: var(--ink-soft); line-height: 1.8; margin-bottom: 14px; }
    
    .about-image {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .about-image img {
      width: 500px;
      height: 500px;
      object-fit: contain;
      margin-right: 100px;
    }

    /* ============ Contact ============ */
    .contact { max-width: 1200px; margin: 110px auto; padding: 0 24px; text-align: center; }
    .contact-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 24px;
    }
    .contact-item {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 32px 24px;
      transition: transform .35s var(--ease), box-shadow .35s var(--ease);
    }
    .contact-item:hover { transform: translateY(-6px); box-shadow: var(--shadow-md); }
    .contact-item-icon { font-size: 2.2rem; margin-bottom: 14px; }
    .contact-item h3 { margin-bottom: 8px; color: var(--ink); }
    .contact-item p  { color: var(--ink-soft); }

    /* ============ Footer ============ */
    .footer {
      background: var(--ink); color: #d1d5db;
      padding: 60px 24px 30px; margin-top: 110px;
      position: relative; overflow: hidden;
    }
    .footer::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      background: var(--grad); background-size: 200% 200%;
      animation: shimmer 5s linear infinite;
    }
    .footer-container {
      max-width: 1200px; margin: 0 auto;
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 40px;
    }
    .footer-section h4 { color: #fff; margin-bottom: 18px; font-size: 1.05rem; }
    .footer-section a {
      color: #9ca3af; text-decoration: none; display: block;
      margin-bottom: 10px; transition: color .25s var(--ease), transform .25s var(--ease);
    }
    .footer-section a:hover { color: #fff; transform: translateX(4px); }
    .footer-bottom {
      text-align: center; margin-top: 40px; padding-top: 24px;
      border-top: 1px solid #374151; color: #9ca3af; font-size: .9rem;

  </style>
</head>
<body>

  <!-- Navigation -->
  <nav class="navbar" id="navbar">
    <div class="navbar-container">
      <a href="mainhomepage.php" class="navbar-brand">SOLISCO.</a>
      <div class="navbar-menu">
        <a href="#featured">Shop</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
        <?php if ($is_logged_in): ?>
          <a href="home.php">Browse Products</a>
          <a href="login.php" class="btn">Sign In</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <!-- Hero -->
  <section class="hero">
    <div class="hero-container">
      <span class="hero-eyebrow">✨ New Collection · 2026</span>
      <h1>Custom Merch &amp;<br><span class="grad-text">Designed for Your Brand</span></h1>
      <p>Discover stylish, high-quality merchandise and apparel with a wide variety of products designed to match your style and make your brand stand out.</p>
      <div class="hero-buttons">
        <?php if ($is_logged_in): ?>
          <a href="register.php" class="btn btn-primary-light">Start Shopping →</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-primary-light">Sign In to Shop →</a>
        <?php endif; ?>
        <a href="#featured" class="btn btn-secondary-light">Browse Products</a>
      </div>
    </div>
    <div class="scroll-cue" aria-hidden="true"></div>
  </section>

  <!-- Features -->
  <section class="features">
    <h2 class="section-title reveal">Why Choose Us</h2>
    <p class="section-sub reveal delay-1">Crafted with care, delivered with speed.</p>
    <div class="features-grid">
      <div class="feature-card reveal">
        <div class="feature-icon"></div>
        <h3>Premium Quality</h3>
        <p>Carefully curated products made from the finest materials.</p>
      </div>
      <div class="feature-card reveal delay-1">
        <div class="feature-icon"></div>
        <h3>Custom Design</h3>
        <p>Personalize your merchandise with custom designs and colors.</p>
      </div>
      <div class="feature-card reveal delay-2">
        <div class="feature-icon"></div>
        <h3>Attention to Detail</h3>
        <p>Every project undergoes a rigorous quality check to ensure it meets our highest standards.</p>
      </div>
    </div>
  </section>

  <!-- Featured Products -->
  <section class="featured-section" id="featured">
    <h2 class="section-title reveal">Featured Products</h2>
    <p class="section-sub reveal delay-1">Hand-picked favorites from our latest drop.</p>
    <div class="products-grid">
      <?php if ($featured && $featured->num_rows > 0): ?>
        <?php $i = 0; while ($product = $featured->fetch_assoc()): $i++;
          // --- Resolve product image (BLOB -> data URI), with fallback ---
          $img_src = '';
          foreach (['profile_image', 'image_front', 'image_back'] as $col) {
            if (!empty($product[$col])) {
              $img_src = 'data:image/jpeg;base64,' . base64_encode($product[$col]);
              break;
            }
          }

          // --- Rating math ---
          $avg   = isset($product['avg_rating'])   ? (float)$product['avg_rating']   : 0;
          $count = isset($product['review_count']) ? (int)$product['review_count']   : 0;
          $fill_pct = max(0, min(100, ($avg / 5) * 100));

          // --- Stock badge ---
          $qty = (int)$product['quantity'];
          $badge = $qty <= 5 ? '<span class="product-badge low">Low Stock</span>' : '';
          if ($i === 1 && $qty > 5) { $badge = '<span class="product-badge">New</span>'; }

          $href = $is_logged_in
            ? 'product_profile.php?id=' . (int)$product['id']
            : '../login.php';
        ?>
          <div class="product-card reveal delay-<?= min($i, 4) ?>"
               onclick="window.location.href='<?= $href ?>'">
            <div class="product-image">
              <?php if ($img_src): ?>
                <img src="<?= $img_src ?>" alt="<?= htmlspecialchars($product['item_name']) ?>" loading="lazy">
              <?php else: ?>
                <span class="no-image">No Image</span>
              <?php endif; ?>
              <?= $badge ?>
            </div>
            <div class="product-info">
              <?php if (!empty($product['category'])): ?>
                <div class="product-category"><?= htmlspecialchars($product['category']) ?></div>
              <?php endif; ?>
              <div class="product-name"><?= htmlspecialchars($product['item_name']) ?></div>

              <div class="product-rating" aria-label="Rating: <?= number_format($avg, 1) ?> out of 5">
                <span class="stars" role="img" aria-hidden="true">
                  <span class="stars-bg">★★★★★</span>
                  <span class="stars-fill" style="width: <?= $fill_pct ?>%;">★★★★★</span>
                </span>
                <?php if ($count > 0): ?>
                  <span class="count"><?= number_format($avg, 1) ?> (<?= $count ?>)</span>
                <?php else: ?>
                  <span class="no-reviews">No reviews yet</span>
                <?php endif; ?>
              </div>

              <div class="product-meta">
                <div class="product-price">₱<?= number_format($product['price'], 2) ?></div>
                <div class="product-stock"><?= $qty ?> in stock</div>
              </div>

              <button class="product-btn" type="button">View Details</button>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <p class="reveal" style="grid-column: 1 / -1; text-align:center; color: var(--muted);">No featured products available right now. Check back soon!</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- About -->
  <section class="about" id="about">
    <div class="about-container">
      <div class="about-content reveal">
        <h2>About SolisCo</h2>
        <p>SolisCo is your premier destination for high-quality branded merchandise and custom apparel. We specialize in providing businesses, organizations, and individuals with premium products that leave a lasting impression.</p>
        <p>With years of experience in the merchandise industry, we pride ourselves on delivering exceptional quality, competitive pricing, and outstanding customer service.</p>
        <p>Whether you're looking for corporate gifts, event merchandise, or personal customization — SolisCo has you covered.</p>
      </div>
      <div class="about-image">     
        <img src="images/location.jpg" alt="SolisCo"> </div>
    </div>
  </section>

  <!-- Contact -->
  <section class="contact" id="contact">
    <h2 class="section-title reveal">Get in Touch</h2>
    <p class="section-sub reveal delay-1">We'd love to hear from you.</p>
    <div class="contact-grid">
      <div class="contact-item reveal">
        <div class="contact-item-icon">📧</div>
        <h3>Email</h3>
        <p>soliscompany@gmail.com</p>
      </div>
      <div class="contact-item reveal delay-1">
        <div class="contact-item-icon">📱</div>
        <h3>Phone</h3>
        <p>+63 (0) 568 3661 93</p>
      </div>
      <div class="contact-item reveal delay-2">
        <div class="contact-item-icon">📍</div>
        <h3>Address</h3>
        <p>123 Business Ave, Suite Batangas, Philippines</p>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-section">
        <h4>About</h4>
        <a href="#about">About Us</a>
        <a href="#contact">Contact</a>
        <a href="#">Blog</a>
      </div>
      <div class="footer-section">
        <h4>Shop</h4>
        <a href="home.php">Browse Products</a>
        <a href="#">Categories</a>
        <a href="#">New Arrivals</a>
      </div>
      <div class="footer-section">
        <h4>Account</h4>
        <a href="profile.php">My Profile</a>
        <a href="orders.php">My Orders</a>
        <a href="cart.php">Cart</a>
      </div>
      <div class="footer-section">
        <h4>Follow Us</h4>
        <a href="#">Facebook</a>
        <a href="#">Instagram</a>
        <a href="#">Twitter</a>
      </div>
    </div>
    <div class="footer-bottom">
      <p>&copy; 2026 SolisCo. All rights reserved.</p>
    </div>
  </footer>

  <script>
    // Navbar shrink on scroll
    const nav = document.getElementById('navbar');
    window.addEventListener('scroll', () => {
      nav.classList.toggle('scrolled', window.scrollY > 20);
    });

    // Scroll-reveal observer
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => io.observe(el));
  </script>
</body>
</html>
