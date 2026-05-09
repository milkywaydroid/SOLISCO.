<?php
// ============================================================
// SMOIMS - Universal Login (Admin & Customer)
// FILE: login.php
// ============================================================
session_start();
require_once 'includes/config.php';

$error = '';

// ── ADMIN CREDENTIALS ──
$admin_email = 'admin@smoims.com';
$admin_pass_hash = password_hash('admin123456789', PASSWORD_DEFAULT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        if ($email === $admin_email && password_verify($password, $admin_pass_hash)) {
            $_SESSION['staff_id']   = 1;
            $_SESSION['staff_name'] = 'System Administrator';
            $_SESSION['role']       = 'staff';
            header('Location: pages/dashboard.php');
            exit;
        } else {
            $stmt = $conn->prepare("SELECT * FROM customers WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['customer_id']   = $user['id'];
                $_SESSION['customer_name'] = $user['full_name'];
                $_SESSION['role']          = 'customer';
                header('Location: pages/customer/home.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SolisCo. | Log In</title>
  <link rel="stylesheet" href="css/style.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }

    html, body { height: 100%; }

    body {
      margin: 0;
      font-family: 'Inter', 'Segoe UI', Tahoma, sans-serif;
      color: #2d1b4e;
      background: #faf7ff;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      overflow-x: hidden;
    }

    /* ─── Split layout shell ─── */
    .auth-shell {
      width: 100%;
      max-width: 980px;
      min-height: 600px;
      display: grid;
      grid-template-columns: 1.1fr 1fr;
      background: rgba(255,255,255,0.85);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-radius: 28px;
      overflow: hidden;
      box-shadow:
        0 30px 80px -20px rgba(124, 58, 237, 0.35),
        0 0 0 1px rgba(255,255,255,0.6) inset;
      animation: shellIn 0.9s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    @keyframes shellIn {
      0%   { opacity: 0; transform: translateY(30px) scale(0.97); }
      100% { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* ─── LEFT: Visual panel ─── */
    .visual-panel {
      position: relative;
      color: #fff;
      padding: 48px 44px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      overflow: hidden;
      background:
        linear-gradient(135deg, rgba(76, 29, 149, 0.85), rgba(236, 72, 153, 0.7)),
        url('https://images.unsplash.com/photo-1558769132-cb1aea458c5e?auto=format&fit=crop&w=1200&q=80') center/cover no-repeat;
    }

    /* Animated gradient overlay */
    .visual-panel::before {
      content: "";
      position: absolute; inset: 0;
      background: linear-gradient(-45deg, rgba(124,58,237,0.55), rgba(236,72,153,0.35), rgba(168,85,247,0.5), rgba(76,29,149,0.55));
      background-size: 400% 400%;
      animation: gradientShift 14s ease infinite;
      mix-blend-mode: overlay;
    }
    @keyframes gradientShift {
      0%,100% { background-position: 0% 50%; }
      50%     { background-position: 100% 50%; }
    }

    /* Floating blobs */
    .blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(60px);
      opacity: 0.55;
      pointer-events: none;
    }
    .blob.b1 { width: 240px; height: 240px; background: #f0abfc; top: -60px; right: -60px; animation: floatA 11s ease-in-out infinite; }
    .blob.b2 { width: 280px; height: 280px; background: #c084fc; bottom: -80px; left: -80px; animation: floatB 13s ease-in-out infinite; }
    @keyframes floatA { 0%,100%{transform:translate(0,0) scale(1);} 50%{transform:translate(-30px,40px) scale(1.1);} }
    @keyframes floatB { 0%,100%{transform:translate(0,0) scale(1);} 50%{transform:translate(40px,-30px) scale(1.08);} }

    .visual-content { position: relative; z-index: 2; }

    .brand-mark {
      display: flex;
      align-items: center;
      gap: 12px;
      animation: fadeUp 0.8s 0.2s ease-out both;
    }
    .brand-mark img {
      width: 46px; height: 46px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(255,255,255,0.6);
      box-shadow: 0 6px 16px rgba(0,0,0,0.2);
    }
    .brand-mark span {
      font-family: 'Playfair Display', serif;
      font-size: 1.25rem;
      font-weight: 700;
      letter-spacing: 0.5px;
    }

    .visual-hero {
      position: relative;
      z-index: 2;
      animation: fadeUp 0.8s 0.35s ease-out both;
    }
    .visual-hero h2 {
      font-family: 'Playfair Display', serif;
      font-size: 2.6rem;
      line-height: 1.1;
      margin: 0 0 14px;
      font-weight: 700;
      text-shadow: 0 2px 20px rgba(0,0,0,0.2);
    }
    .visual-hero p {
      font-size: 0.98rem;
      line-height: 1.6;
      max-width: 360px;
      opacity: 0.92;
      margin: 0;
    }

    .feature-pills {
      position: relative;
      z-index: 2;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      animation: fadeUp 0.8s 0.5s ease-out both;
    }
    .pill {
      padding: 7px 14px;
      background: rgba(255,255,255,0.18);
      border: 1px solid rgba(255,255,255,0.35);
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 500;
      backdrop-filter: blur(10px);
      transition: transform 0.3s ease, background 0.3s ease;
    }
    .pill:hover { transform: translateY(-2px); background: rgba(255,255,255,0.28); }

    /* ─── RIGHT: Form panel ─── */
    .form-panel {
      padding: 48px 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background: #fff;
      position: relative;
    }

    .form-header {
      margin-bottom: 26px;
      animation: fadeUp 0.7s 0.3s ease-out both;
    }
    .form-header .tagline {
      display: inline-block;
      font-style: italic;
      color: #7c3aed;
      font-size: 0.78rem;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-bottom: 8px;
    }
    .form-header h1 {
      font-family: 'Playfair Display', serif;
      background: linear-gradient(135deg, #7c3aed, #ec4899);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      margin: 0 0 6px;
      font-size: 2rem;
      font-weight: 700;
    }
    .form-header .subtitle {
      color: #6b7280;
      font-size: 0.92rem;
      margin: 0;
    }

    .form-group {
      margin-bottom: 16px;
      animation: fadeUp 0.7s ease-out both;
    }
    .form-group:nth-of-type(1) { animation-delay: 0.4s; }
    .form-group:nth-of-type(2) { animation-delay: 0.5s; }

    @keyframes fadeUp {
      0%   { opacity: 0; transform: translateY(15px); }
      100% { opacity: 1; transform: translateY(0); }
    }

    .form-label {
      display: block;
      font-weight: 600;
      margin-bottom: 6px;
      color: #4c1d95;
      font-size: 0.85rem;
    }

    .input-wrap { position: relative; }
    .input-wrap .icon {
      position: absolute;
      left: 14px; top: 50%;
      transform: translateY(-50%);
      color: #a78bfa;
      pointer-events: none;
    }
    .form-control {
      width: 100%;
      padding: 12px 14px 12px 42px;
      border: 1.5px solid #ede9fe;
      border-radius: 12px;
      font-size: 0.95rem;
      background: #faf7ff;
      transition: all 0.3s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: #a855f7;
      box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.15);
      background: #fff;
    }

    .row-between {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 18px;
      font-size: 0.82rem;
      animation: fadeUp 0.7s 0.55s ease-out both;
    }
    .remember {
      display: flex; align-items: center; gap: 6px;
      color: #6b7280; cursor: pointer;
    }
    .remember input { accent-color: #7c3aed; }

    .forgot-password-link {
      color: #7c3aed;
      text-decoration: none;
      font-weight: 600;
      position: relative;
    }
    .forgot-password-link::after {
      content: ""; position: absolute;
      left: 0; bottom: -2px;
      width: 100%; height: 1.5px;
      background: #7c3aed;
      transform: scaleX(0); transform-origin: right;
      transition: transform 0.3s ease;
    }
    .forgot-password-link:hover::after { transform: scaleX(1); transform-origin: left; }

    .btn-login {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, #7c3aed, #a855f7, #ec4899);
      background-size: 200% 200%;
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 0.98rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      cursor: pointer;
      position: relative;
      overflow: hidden;
      box-shadow: 0 8px 20px rgba(124, 58, 237, 0.35);
      transition: transform 0.25s ease, box-shadow 0.25s ease, background-position 0.6s ease;
      animation: fadeUp 0.7s 0.6s ease-out both;
    }
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 28px rgba(124, 58, 237, 0.45);
      background-position: 100% 0;
    }
    .btn-login:active { transform: translateY(0); }
    .btn-login::before {
      content: "";
      position: absolute;
      top: 0; left: -75%;
      width: 50%; height: 100%;
      background: linear-gradient(120deg, transparent, rgba(255,255,255,0.4), transparent);
      transform: skewX(-25deg);
      transition: left 0.7s ease;
    }
    .btn-login:hover::before { left: 125%; }

    .alert {
      padding: 11px;
      background: #fef2f2;
      color: #dc2626;
      border-radius: 10px;
      margin-bottom: 18px;
      font-size: 0.88rem;
      text-align: center;
      border: 1px solid #fecaca;
      animation: shake 0.45s ease, fadeUp 0.4s ease both;
    }
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      20%,60% { transform: translateX(-6px); }
      40%,80% { transform: translateX(6px); }
    }

    .footer-link {
      text-align: center;
      margin-top: 20px;
      font-size: 0.88rem;
      color: #6b7280;
      animation: fadeUp 0.7s 0.7s ease-out both;
    }
    .footer-link a {
      color: #7c3aed;
      text-decoration: none;
      font-weight: 600;
    }
    .footer-link a:hover { color: #ec4899; }

    /* Responsive */
    @media (max-width: 860px) {
      .auth-shell {
        grid-template-columns: 1fr;
        max-width: 460px;
        min-height: auto;
      }
      .visual-panel {
        padding: 32px 28px;
        min-height: 220px;
      }
      .visual-hero h2 { font-size: 1.8rem; }
      .visual-hero p { font-size: 0.9rem; }
      .form-panel { padding: 36px 28px; }
    }
    @media (max-width: 420px) {
      .form-panel { padding: 28px 22px; }
      .form-header h1 { font-size: 1.7rem; }
    }
  </style>
</head>

<body>

  <div class="auth-shell">
    <!-- LEFT: Visual panel -->
    <aside class="visual-panel">
      <span class="blob b1"></span>
      <span class="blob b2"></span>

      <div class="brand-mark visual-content">
        <img src="images/logo.jpg" alt="SolisCo. Logo">
        <span>SolisCo.</span>
      </div>

      <div class="visual-hero">
        <h2>Design It.<br>Own It.</h2>
        <p>Step into a world of bespoke merch crafted just for you. Your style, your story — perfectly stitched together.</p>
      </div>

      <div class="feature-pills">
        <span class="pill">Custom Designs</span>
        <span class="pill">Explore Imagination</span>
        <span class="pill">Premium Quality</span>
      </div>
    </aside>

    <!-- RIGHT: Form panel -->
    <main class="form-panel">
      <div class="form-header">
        <span class="tagline">Welcome Back</span>
        <h1>Sign In</h1>
        <p class="subtitle">Enter your credentials to access your account</p>
      </div>

      <?php if ($error): ?>
        <div class="alert"><?= $error ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="input-wrap">
            <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" name="email" class="form-control" placeholder="you@email.com" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-wrap">
            <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
          </div>
        </div>

        <div class="row-between">
          <label class="remember">
            <input type="checkbox" name="remember"> Remember me
          </label>
          <a href="forgot_password.php" class="forgot-password-link">Forgot Password?</a>
        </div>

        <button type="submit" class="btn-login">Sign In</button>
      </form>

      <div class="footer-link">
        New customer? <a href="register.php">Create an account</a>
      </div>
    </main>
  </div>

</body>
</html>
