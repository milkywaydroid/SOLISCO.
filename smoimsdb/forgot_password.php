<?php
/* ============================================================
   FILE: forgot_password.php
   Password reset functionality
   ============================================================ */

session_start();
require_once 'includes/config.php';

$message = ''; $error = ''; $step = 1;

/* ── Step 1: Request password reset ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset'])) {
    $email = clean($conn, $_POST['email'] ?? '');
    
    // Check if email exists in customers or staff
    $customer = $conn->query("SELECT id, full_name FROM customers WHERE email='$email'")->fetch_assoc();
    $staff = $conn->query("SELECT id, full_name FROM staff WHERE email='$email'")->fetch_assoc();
    
    if ($customer || $staff) {
        // Generate OTP (6 digits)
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Store OTP in session (in production, store in database)
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_otp'] = $otp;
        $_SESSION['reset_otp_expiry'] = $otp_expiry;
        $_SESSION['reset_type'] = $customer ? 'customer' : 'staff';
        
        // In production, send OTP via email
        // For now, display it (remove in production)
        $message = "OTP sent to $email. OTP: $otp (Valid for 15 minutes)";
        $step = 2;
    } else {
        $error = 'Email not found in our system.';
    }
}

/* ── Step 2: Verify OTP ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otp = $_POST['otp'] ?? '';
    
    if (!isset($_SESSION['reset_otp'])) {
        $error = 'Session expired. Please try again.';
        $step = 1;
    } elseif ($otp !== $_SESSION['reset_otp']) {
        $error = 'Invalid OTP.';
        $step = 2;
    } elseif (strtotime($_SESSION['reset_otp_expiry']) < time()) {
        $error = 'OTP expired. Please request a new one.';
        unset($_SESSION['reset_otp']);
        unset($_SESSION['reset_otp_expiry']);
        $step = 1;
    } else {
        $step = 3;
        $message = 'OTP verified. Please set your new password.';
    }
}

/* ── Step 3: Reset password ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
        $step = 3;
    } elseif (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters.';
        $step = 3;
    } else {
        $email = $_SESSION['reset_email'];
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password
        if ($_SESSION['reset_type'] === 'customer') {
            $conn->query("UPDATE customers SET password='$hashed' WHERE email='$email'");
        } else {
            $conn->query("UPDATE staff SET password='$hashed' WHERE email='$email'");
        }
        
        // Clear session
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_otp']);
        unset($_SESSION['reset_otp_expiry']);
        unset($_SESSION['reset_type']);
        
        $message = 'Password reset successfully! Redirecting to login...';
        header('refresh:3;url=login.php');
    }
}

// Determine current step
if (isset($_SESSION['reset_otp'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $step = 2;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password – SMOIMS</title>
  <link rel="stylesheet" href="css/style.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      background: linear-gradient(135deg, var(--pastel-accent) 0%, #a78bfa 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .reset-container {
      width: 100%;
      max-width: 420px;
    }

    .reset-card {
      background: white;
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(0,0,0,.15);
      padding: 40px;
    }

    .reset-header {
      text-align: center;
      margin-bottom: 32px;
    }

    .reset-icon {
      font-size: 3rem;
      margin-bottom: 12px;
    }

    .reset-title {
      font-size: 1.6rem;
      font-weight: 700;
      color: #333;
      margin-bottom: 8px;
    }

    .reset-subtitle {
      font-size: 0.9rem;
      color: #6b7280;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-label {
      display: block;
      font-weight: 600;
      margin-bottom: 6px;
      color: #333;
      font-size: 0.95rem;
    }

    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid #d1d5db;
      border-radius: 8px;
      font-size: 0.95rem;
      transition: border-color 0.2s, background 0.2s;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--pastel-accent);
      background: #faf5ff;
    }

    .btn-reset {
      width: 100%;
      padding: 12px;
      background: linear-gradient(135deg, var(--pastel-accent) 0%, #a78bfa 100%);
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn-reset:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(168, 85, 247, 0.3);
    }

    .reset-footer {
      text-align: center;
      margin-top: 20px;
    }

    .reset-footer a {
      color: var(--pastel-accent);
      text-decoration: none;
      font-weight: 600;
      transition: opacity 0.2s;
    }

    .reset-footer a:hover {
      opacity: 0.8;
      text-decoration: underline;
    }

    .alert {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 0.9rem;
    }

    .alert-danger {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fca5a5;
    }

    .alert-success {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #86efac;
    }

    .alert-info {
      background: #dbeafe;
      color: #1e40af;
      border: 1px solid #93c5fd;
    }

    .step-indicator {
      display: flex;
      justify-content: space-between;
      margin-bottom: 24px;
      font-size: 0.85rem;
    }

    .step {
      flex: 1;
      text-align: center;
      padding: 8px;
      border-radius: 6px;
      background: #f3f4f6;
      color: #6b7280;
    }

    .step.active {
      background: var(--pastel-accent);
      color: white;
      font-weight: 600;
    }

    .otp-input-group {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin: 20px 0;
    }

    .otp-input {
      width: 50px;
      height: 50px;
      text-align: center;
      font-size: 1.5rem;
      font-weight: 600;
      border: 2px solid #d1d5db;
      border-radius: 8px;
      transition: border-color 0.2s;
    }

    .otp-input:focus {
      outline: none;
      border-color: var(--pastel-accent);
    }
  </style>
</head>
<body>
  <div class="reset-container">
    <div class="reset-card">
      <div class="reset-header">
        <div class="reset-icon">🔐</div>
        <div class="reset-title">Reset Password</div>
        <div class="reset-subtitle">Secure your account</div>
      </div>

      <?php if ($message): ?>
        <div class="alert alert-<?= strpos($message, 'successfully') !== false ? 'success' : 'info' ?>">
          <?= htmlspecialchars($message) ?>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Step Indicator -->
      <div class="step-indicator">
        <div class="step <?= $step >= 1 ? 'active' : '' ?>">1. Email</div>
        <div class="step <?= $step >= 2 ? 'active' : '' ?>">2. OTP</div>
        <div class="step <?= $step >= 3 ? 'active' : '' ?>">3. Password</div>
      </div>

      <!-- Step 1: Request Reset -->
      <?php if ($step === 1): ?>
        <form method="POST" action="">
          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" placeholder="your@email.com" required autofocus>
          </div>
          <button type="submit" name="request_reset" class="btn-reset">Send OTP</button>
        </form>
      <?php endif; ?>

      <!-- Step 2: Verify OTP -->
      <?php if ($step === 2): ?>
        <form method="POST" action="">
          <p style="text-align: center; color: #6b7280; margin-bottom: 20px;">
            Enter the 6-digit OTP sent to your email
          </p>
          <div class="form-group">
            <label class="form-label">One-Time Password</label>
            <input type="text" name="otp" class="form-control" placeholder="000000" maxlength="6" required autofocus>
          </div>
          <button type="submit" name="verify_otp" class="btn-reset">Verify OTP</button>
        </form>
      <?php endif; ?>

      <!-- Step 3: Reset Password -->
      <?php if ($step === 3): ?>
        <form method="POST" action="">
          <div class="form-group">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" placeholder="••••••••" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
          </div>
          <button type="submit" name="reset_password" class="btn-reset">Reset Password</button>
        </form>
      <?php endif; ?>

      <div class="reset-footer">
        <a href="login.php">← Back to Login</a>
      </div>
    </div>
  </div>
</body>
</html>
