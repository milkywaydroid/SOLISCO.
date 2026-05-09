<?php
/* ============================================================
   FILE: pages/customer/product_profile.php
   Detailed product profile page
   ============================================================ */

require_once '../../includes/config.php';
requireCustomerLogin();

$product_id = (int)($_GET['id'] ?? 0);
$customer_id = $_SESSION['customer_id'];

// Get product info
$product = $conn->query("SELECT * FROM inventory WHERE id=$product_id")->fetch_assoc();
if (!$product) {
    header('Location: home.php');
    exit;
}

// Get product reviews
$reviews = $conn->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id=$product_id");
$review_stats = $reviews->fetch_assoc();

// Get product images
function getProductImages(string $itemName): array {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $itemName));
    $slug = trim($slug, '-');
    $base = __DIR__ . '/../../assets/products/' . $slug . '/';
    $webBase = '../../assets/products/' . $slug . '/';

    $imgs = [];
    foreach (['front', 'back', 'left', 'right', 'profile'] as $side) {
        $imgs[$side] = null;
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            if (file_exists($base . $side . '.' . $ext)) {
                $imgs[$side] = $webBase . $side . '.' . $ext;
                break;
            }
        }
    }
    return $imgs;
}

$images = getProductImages($product['item_name']);
$main_image = $images['profile'] ?? $images['front'] ?? '../../images/placeholder.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($product['item_name']) ?> | SolisCo.</title>
  <link rel="stylesheet" href="../../css/style.css">
  <style>
    .product-container { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 30px; }
    @media (max-width: 768px) { .product-container { grid-template-columns: 1fr; gap: 24px; } }
    
    .product-images { display: flex; flex-direction: column; gap: 12px; }
    .main-image { width: 100%; aspect-ratio: 1; background: #f9fafb; border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .main-image img { width: 100%; height: 100%; object-fit: contain; }
    .main-image-placeholder { font-size: 4rem; }
    
    .thumbnail-row { display: flex; gap: 8px; }
    .thumbnail { width: 80px; height: 80px; background: #f9fafb; border-radius: 8px; border: 2px solid #e5e7eb; cursor: pointer; display: flex; align-items: center; justify-content: center; overflow: hidden; transition: border-color 0.2s; }
    .thumbnail:hover, .thumbnail.active { border-color: var(--pastel-accent); }
    .thumbnail img { width: 100%; height: 100%; object-fit: contain; }
    
    .product-details h1 { font-size: 2rem; margin-bottom: 12px; color: #333; }
    .product-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
    .rating-stars { font-size: 1rem; color: #f59e0b; }
    .rating-count { color: #6b7280; font-size: 0.9rem; }
    
    .price-section { background: #f9fafb; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
    .price { font-size: 2rem; font-weight: 700; color: var(--pastel-accent); margin-bottom: 8px; }
    .stock-status { font-size: 0.95rem; margin-bottom: 12px; }
    .stock-status.in-stock { color: #22c55e; }
    .stock-status.low-stock { color: #f59e0b; }
    .stock-status.out-of-stock { color: #dc2626; }
    
    .description { color: #374151; line-height: 1.8; margin-bottom: 24px; }
    
    .customize-form { background: #f9fafb; border-radius: 12px; padding: 20px; }
    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 6px; color: #333; }
    .form-control { width: 100%; padding: 8px 12px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 0.95rem; }
    .form-control:focus { outline: none; border-color: var(--pastel-accent); background: #faf5ff; }
    
    .button-group { display: flex; gap: 12px; margin-top: 20px; }
    .btn-add-cart { flex: 1; padding: 12px; background: var(--pastel-accent); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
    .btn-add-cart:hover { opacity: 0.9; }
    .btn-back { flex: 1; padding: 12px; background: #e5e7eb; color: #333; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
    .btn-back:hover { background: #d1d5db; }
    
    .reviews-section { margin-top: 40px; padding-top: 40px; border-top: 2px solid #e5e7eb; }
    .reviews-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .reviews-header h2 { margin: 0; }
    .btn-leave-review { padding: 8px 16px; background: var(--pastel-accent); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
    
    .review-item { background: #f9fafb; border-radius: 12px; padding: 16px; margin-bottom: 12px; }
    .review-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
    .review-author { font-weight: 600; color: #333; }
    .review-date { font-size: 0.85rem; color: #6b7280; }
    .review-rating { color: #f59e0b; margin-bottom: 8px; }
    .review-comment { color: #374151; line-height: 1.6; }
  </style>
</head>
<body>
<div class="layout">
  <div class="main">
    <div class="container">
      <a href="home.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Products</a>

      <div class="product-container">
        <!-- Product Images -->
        <div class="product-images">
          <div class="main-image" id="mainImage">
            <?php if ($main_image !== '../../images/placeholder.png' && file_exists($main_image)): ?>
              <img src="<?= htmlspecialchars($main_image) ?>" alt="<?= htmlspecialchars($product['item_name']) ?>">
            <?php else: ?>
              <div class="main-image-placeholder">📦</div>
            <?php endif; ?>
          </div>
          <div class="thumbnail-row">
            <?php foreach (['profile', 'front', 'back', 'left', 'right'] as $side): ?>
              <?php if ($images[$side]): ?>
                <div class="thumbnail <?= $side === 'profile' ? 'active' : '' ?>" onclick="changeImage('<?= htmlspecialchars($images[$side]) ?>')">
                  <img src="<?= htmlspecialchars($images[$side]) ?>" alt="<?= $side ?>">
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Product Details -->
        <div class="product-details">
          <h1><?= htmlspecialchars($product['item_name']) ?></h1>

          <!-- Rating -->
          <div class="product-rating">
            <div class="rating-stars">
              <?php 
                $avg = round($review_stats['avg_rating'] ?? 0);
                echo str_repeat('⭐', $avg);
              ?>
            </div>
            <span class="rating-count"><?= (int)$review_stats['total_reviews'] ?> reviews</span>
          </div>

          <!-- Price & Stock -->
          <div class="price-section">
            <div class="price">₱<?= number_format($product['price'], 2) ?></div>
            <div class="stock-status <?= $product['quantity'] > 0 ? ($product['quantity'] < 10 ? 'low-stock' : 'in-stock') : 'out-of-stock' ?>">
              <?php 
                if ($product['quantity'] == 0) {
                  echo '❌ Out of Stock';
                } elseif ($product['quantity'] < 10) {
                  echo '⚠️ Only ' . $product['quantity'] . ' left';
                } else {
                  echo '✅ In Stock';
                }
              ?>
            </div>
          </div>

          <!-- Description -->
          <?php if ($product['description']): ?>
            <div class="description">
              <strong>Description:</strong><br>
              <?= nl2br(htmlspecialchars($product['description'])) ?>
            </div>
          <?php endif; ?>

          <!-- Customize & Add to Cart -->
          <form method="POST" action="cart.php" enctype="multipart/form-data" class="customize-form">
            <input type="hidden" name="item_id" value="<?= $product['id'] ?>">
            <input type="hidden" name="unit_price" value="<?= $product['price'] ?>">

            <div class="form-group">
              <label class="form-label">Quantity *</label>
              <input type="number" name="quantity" class="form-control" value="1" min="1" max="<?= $product['quantity'] ?>" required>
            </div>

            <?php if ($product['available_colors']): ?>
              <div class="form-group">
                <label class="form-label">Color</label>
                <select name="color" class="form-control">
                  <option value="">-- Select Color --</option>
                  <?php foreach (explode(',', $product['available_colors']) as $color): ?>
                    <option value="<?= htmlspecialchars(trim($color)) ?>"><?= htmlspecialchars(trim($color)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($product['has_sizes']): ?>
              <div class="form-group">
                <label class="form-label">Size</label>
                <select name="size" class="form-control">
                  <option value="">-- Select Size --</option>
                  <option value="XS">Extra Small (XS)</option>
                  <option value="S">Small (S)</option>
                  <option value="M">Medium (M)</option>
                  <option value="L">Large (L)</option>
                  <option value="XL">Extra Large (XL)</option>
                  <option value="XXL">2XL</option>
                </select>
              </div>
            <?php endif; ?>

            <div class="form-group">
              <label class="form-label">Delivery Location</label>
              <input type="text" name="location" class="form-control" placeholder="Enter your delivery address">
            </div>

            <div class="form-group">
              <label class="form-label">Upload Design (Optional)</label>
              <input type="file" name="design" class="form-control" accept="image/*">
            </div>

            <div class="button-group">
              <button type="submit" name="add_to_cart" class="btn-add-cart" <?= $product['quantity'] > 0 ? '' : 'disabled' ?>>
                🛒 Add to Cart
              </button>
              <button type="button" class="btn-back" onclick="history.back()">← Back</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Reviews Section -->
      <div class="reviews-section">
        <div class="reviews-header">
          <h2>Customer Reviews</h2>
          <a href="product_reviews.php?product_id=<?= $product['id'] ?>" class="btn-leave-review">⭐ Leave Review</a>
        </div>

        <?php 
          $product_reviews = $conn->query("
              SELECT r.*, c.full_name 
              FROM reviews r 
              JOIN customers c ON r.customer_id = c.id 
              WHERE r.product_id=$product_id 
              ORDER BY r.created_at DESC 
              LIMIT 5
          ");
        ?>

        <?php if ($product_reviews && $product_reviews->num_rows > 0): ?>
          <?php while ($review = $product_reviews->fetch_assoc()): ?>
            <div class="review-item">
              <div class="review-header">
                <div class="review-author"><?= htmlspecialchars($review['full_name']) ?></div>
                <div class="review-date"><?= date('M d, Y', strtotime($review['created_at'])) ?></div>
              </div>
              <div class="review-rating"><?= str_repeat('⭐', $review['rating']) ?></div>
              <div class="review-comment"><?= htmlspecialchars($review['comment']) ?></div>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <p style="color: #6b7280; text-align: center; padding: 20px;">No reviews yet. Be the first to review!</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function changeImage(src) {
  const mainImage = document.getElementById('mainImage');
  mainImage.innerHTML = '<img src="' + src + '" alt="Product">';
  
  // Update active thumbnail
  document.querySelectorAll('.thumbnail').forEach(thumb => {
    thumb.classList.remove('active');
    if (thumb.querySelector('img').src === src) {
      thumb.classList.add('active');
    }
  });
}
</script>
</body>
</html>
