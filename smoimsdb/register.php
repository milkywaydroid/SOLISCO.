<?php
// ============================================================
// SMOIMS - Customer Registration
// ============================================================
require_once 'includes/config.php'; 

$error   = '';
$success = '';
$fields  = []; 

$allowed_email_domains = [
    'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com',
    'icloud.com', 'protonmail.com',
    // Add your school domain here e.g. 'solis.edu.ph'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = clean($conn, $_POST['fullname'] ?? '');
    $org      = clean($conn, $_POST['organization'] ?? '');
    $email    = clean($conn, $_POST['email'] ?? '');
    $contact  = clean($conn, $_POST['contact'] ?? '');
    $bday     = clean($conn, $_POST['birthday'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    $errors = [];

    // ── Full Name ──
    if (empty($fullname)) {
        $errors[] = 'Full name is required.';
        $fields[] = 'fullname';
    } elseif (strlen($fullname) < 2) {
        $errors[] = 'Full name is too short.';
        $fields[] = 'fullname';
    } elseif (strlen($fullname) > 80) {
        $errors[] = 'Full name must not exceed 80 characters.';
        $fields[] = 'fullname';
    } elseif (!preg_match("/^[a-zA-Z\s\.\-']+$/", $fullname)) {
        $errors[] = 'Full name should only contain letters, spaces, hyphens, periods, or apostrophes.';
        $fields[] = 'fullname';
    } elseif (preg_match('/\s{2,}/', $fullname)) {
        $errors[] = 'Full name cannot contain double spaces.';
        $fields[] = 'fullname';
    } elseif (preg_match('/^[\s.\-\']|[\s.\-\']$/', $fullname)) {
        $errors[] = 'Full name cannot start or end with a space or symbol.';
        $fields[] = 'fullname';
    } elseif (count(array_filter(explode(' ', $fullname))) < 2) {
        $errors[] = 'Please enter your first and last name.';
        $fields[] = 'fullname';
    } elseif (preg_match('/(.)\1{3,}/iu', preg_replace('/\s/', '', $fullname))) {
        $errors[] = 'Full name looks invalid (too many repeated characters).';
        $fields[] = 'fullname';
    }

    // ── Email ──
    if (empty($email)) {
        $errors[] = 'Email address is required.';
        $fields[] = 'email';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
        $fields[] = 'email';
    } else {
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        if (!in_array($emailDomain, $allowed_email_domains)) {
            $errors[] = 'Please use an authorized email domain (e.g. gmail.com, yahoo.com, or your school email).';
            $fields[] = 'email';
        }
    }

    // ── Contact ──
    if (empty($contact)) {
        $errors[] = 'Contact number is required.';
        $fields[] = 'contact';
    } elseif (!preg_match('/^(09|\+639)\d{9}$/', $contact)) {
        $errors[] = 'Contact number must be in format: 09XXXXXXXXX or +639XXXXXXXXX.';
        $fields[] = 'contact';
    }

    // ── Birthday ──
    if (!empty($bday)) {
        $bdayDate = DateTime::createFromFormat('Y-m-d', $bday);
        if (!$bdayDate) {
            $errors[] = 'Please enter a valid birthday.';
            $fields[] = 'birthday';
        } elseif ($bdayDate > new DateTime()) {
            $errors[] = 'Birthday cannot be in the future.';
            $fields[] = 'birthday';
        } elseif ($bdayDate > new DateTime('-13 years')) {
            $errors[] = 'You must be at least 13 years old to register.';
            $fields[] = 'birthday';
        }
    }

    // ── Password ──
    if (empty($pass)) {
        $errors[] = 'Password is required.';
        $fields[] = 'password';
    } elseif (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
        $fields[] = 'password';
    } elseif (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/[0-9]/', $pass)) {
        $errors[] = 'Password must contain at least one letter and one number.';
        $fields[] = 'password';
    }

    // ── Confirm Password ──
    if (empty($confirm)) {
        $errors[] = 'Please confirm your password.';
        $fields[] = 'confirm';
    } elseif ($pass !== $confirm) {
        $errors[] = 'Passwords do not match.';
        $fields[] = 'confirm';
    }

    // ── Database Duplicate Checks ──
    if (empty($errors)) {

        // Duplicate full name
        $checkName = $conn->query("SELECT id FROM customers WHERE full_name = '$fullname' LIMIT 1");
        if ($checkName && $checkName->num_rows > 0) {
            $errors[] = 'That name is already registered. If this is you, please log in instead.';
            $fields[] = 'fullname';
        }

        // Duplicate email
        $checkEmail = $conn->query("SELECT id FROM customers WHERE email = '$email' LIMIT 1");
        if ($checkEmail && $checkEmail->num_rows > 0) {
            $errors[] = 'That email is already registered.';
            $fields[] = 'email';
        }

        // Duplicate contact
        $checkContact = $conn->query("SELECT id FROM customers WHERE contact = '$contact' LIMIT 1");
        if ($checkContact && $checkContact->num_rows > 0) {
            $errors[] = 'That contact number is already associated with an existing account.';
            $fields[] = 'contact';
        }

        if (empty($errors)) {
            $hashed  = password_hash($pass, PASSWORD_DEFAULT);
            $bdayVal = !empty($bday) ? "'$bday'" : "NULL";
            $sql     = "INSERT INTO customers (full_name, organization, email, contact, birthday, password)
                        VALUES ('$fullname', '$org', '$email', '$contact', $bdayVal, '$hashed')";

            if ($conn->query($sql)) {
                $newUser = $conn->query("SELECT * FROM customers WHERE email = '$email' LIMIT 1")->fetch_assoc();
                $_SESSION['customer_id']   = $newUser['id'];
                $_SESSION['customer_name'] = $newUser['full_name'];
                $_SESSION['role']          = 'customer';
                header('Location: login.php');
                exit;
            } else {
                $errors[] = 'Registration failed due to a server error.';
            }
        }
    }

    if (!empty($errors)) { $error = implode('<br>', $errors); }
}

function fieldError($fields, $name) {
    return in_array($name, $fields) ? 'style="border-color:#ef4444"' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account | SolisCo.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary: #a855f7;
      --primary-light: #f3e8ff;
      --primary-dark: #7e22ce;
      --accent: #ec4899;
      --text-main: #1f2937;
      --text-muted: #6b7280;
      --white: #ffffff;
      --error: #ef4444;
      --success: #22c55e;
      --input-border: #e9d5ff;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      color: var(--text-main);
      min-height: 100vh;
      padding: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow-x: hidden;
      background:
        radial-gradient(1200px 600px at 10% -10%, #fbcfe8 0%, transparent 60%),
        radial-gradient(900px 600px at 110% 10%, #c4b5fd 0%, transparent 55%),
        radial-gradient(900px 700px at 50% 120%, #ddd6fe 0%, transparent 55%),
        linear-gradient(135deg, #faf5ff 0%, #fdf2f8 100%);
      background-size: 200% 200%;
      animation: bgShift 18s ease infinite;
    }

    @keyframes bgShift {
      0%,100% { background-position: 0% 50%, 100% 0%, 50% 100%, 0 0; }
      50%     { background-position: 20% 60%, 80% 20%, 60% 90%, 0 0; }
    }

    .blob { position: fixed; border-radius: 50%; filter: blur(60px); opacity: 0.55; pointer-events: none; z-index: 0; animation: float 14s ease-in-out infinite; }
    .blob.b1 { width: 380px; height: 380px; background: #f0abfc; top: -120px; left: -100px; }
    .blob.b2 { width: 320px; height: 320px; background: #a78bfa; bottom: -120px; right: -80px; animation-delay: -5s; }
    .blob.b3 { width: 240px; height: 240px; background: #fbcfe8; top: 40%; right: 8%; animation-delay: -9s; }

    @keyframes float {
      0%,100% { transform: translate(0,0) scale(1); }
      50%     { transform: translate(20px,-30px) scale(1.08); }
    }

    .auth-shell {
      position: relative; z-index: 1; width: 100%; max-width: 980px;
      display: grid; grid-template-columns: 1fr 1.05fr;
      background: rgba(255,255,255,0.78);
      backdrop-filter: blur(20px) saturate(140%);
      -webkit-backdrop-filter: blur(20px) saturate(140%);
      border-radius: 28px; overflow: hidden;
      box-shadow: 0 30px 60px -20px rgba(126,34,206,0.25), 0 10px 20px -10px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.6);
      border: 1px solid rgba(255,255,255,0.6);
      animation: shellIn 0.9s cubic-bezier(0.22,1,0.36,1) both;
    }

    @keyframes shellIn {
      0%   { opacity: 0; transform: translateY(24px) scale(0.97); }
      100% { opacity: 1; transform: translateY(0) scale(1); }
    }

    .visual {
      position: relative; padding: 48px 40px; color: #fff; overflow: hidden;
      background: linear-gradient(135deg, rgba(126,34,206,0.85), rgba(236,72,153,0.78)),
        url('https://images.unsplash.com/photo-1513151233558-d860c5398176?auto=format&fit=crop&w=1200&q=80') center/cover no-repeat;
      display: flex; flex-direction: column; justify-content: space-between; min-height: 560px;
    }
    .visual::before { content: ''; position: absolute; inset: 0; background: radial-gradient(600px 300px at 20% 10%, rgba(255,255,255,0.25), transparent 60%); pointer-events: none; }

    .brand { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 800; letter-spacing: 0.5px; display: flex; align-items: center; gap: 10px; position: relative; }
    .brand .dot { width: 10px; height: 10px; border-radius: 50%; background: #fff; box-shadow: 0 0 18px #fff; animation: pulseDot 2.2s ease-in-out infinite; }
    @keyframes pulseDot { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.4); opacity: 0.7; } }

    .visual h2 { font-family: 'Playfair Display', serif; font-size: 2.2rem; line-height: 1.15; font-weight: 800; margin-bottom: 14px; position: relative; }
    .visual p.tag { font-size: 0.98rem; opacity: 0.92; max-width: 320px; line-height: 1.55; position: relative; }

    .perks { list-style: none; display: flex; flex-direction: column; gap: 12px; position: relative; }
    .perks li { display: flex; align-items: center; gap: 10px; font-size: 0.92rem; opacity: 0.95; }
    .perks .check { width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.2); display: inline-flex; align-items: center; justify-content: center; backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,0.4); }

    .form-panel { padding: 44px 44px; display: flex; flex-direction: column; max-height: 90vh; overflow-y: auto; }
    .form-panel::-webkit-scrollbar { width: 6px; }
    .form-panel::-webkit-scrollbar-thumb { background: #e9d5ff; border-radius: 10px; }

    .back-link { text-decoration: none; color: var(--primary-dark); font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 18px; transition: transform 0.25s ease, color 0.25s; width: fit-content; }
    .back-link:hover { transform: translateX(-4px); color: var(--accent); }

    .reg-logo { font-family: 'Playfair Display', serif; font-size: 1.7rem; font-weight: 800; color: var(--primary-dark); margin-bottom: 6px; background: linear-gradient(90deg, #7e22ce, #ec4899); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .reg-sub { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 22px; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }

    .form-group { margin-bottom: 14px; animation: fadeUp 0.6s ease both; }
    .form-group:nth-child(1) { animation-delay: 0.05s; }
    .form-group:nth-child(2) { animation-delay: 0.10s; }
    .form-group:nth-child(3) { animation-delay: 0.15s; }
    .form-group:nth-child(4) { animation-delay: 0.20s; }
    .form-group:nth-child(5) { animation-delay: 0.25s; }
    .form-group:nth-child(6) { animation-delay: 0.30s; }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    .form-label { display: block; font-weight: 600; font-size: 0.78rem; letter-spacing: 0.3px; text-transform: uppercase; margin-bottom: 6px; color: var(--text-main); }

    .form-control { width: 100%; padding: 11px 14px; border: 1.5px solid var(--input-border); border-radius: 12px; font-size: 0.92rem; font-family: inherit; transition: all 0.25s ease; outline: none; background: rgba(255,255,255,0.7); }
    .form-control:hover { border-color: #d8b4fe; }
    .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(168,85,247,0.18); background: #fff; transform: translateY(-1px); }

    .alert { padding: 12px 14px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 16px; line-height: 1.5; animation: shake 0.5s ease; }
    .alert-danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

    @keyframes shake { 0%,100% { transform: translateX(0); } 20%,60% { transform: translateX(-6px); } 40%,80% { transform: translateX(6px); } }

    .btn-primary { position: relative; overflow: hidden; background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%); color: white; border: none; padding: 13px; border-radius: 12px; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.3px; cursor: pointer; width: 100%; transition: all 0.3s ease; box-shadow: 0 10px 20px -8px rgba(168,85,247,0.55); margin-top: 6px; }
    .btn-primary::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.35), transparent); transition: left 0.6s ease; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 16px 28px -10px rgba(236,72,153,0.55); }
    .btn-primary:hover::before { left: 100%; }
    .btn-primary:active { transform: translateY(0); }

    .strength-bar  { height: 5px; background: #f3f4f6; border-radius: 10px; margin-top: 8px; overflow: hidden; }
    .strength-fill { height: 100%; width: 0; transition: width 0.4s ease, background 0.3s; }
    .strength-text { font-size: 0.72rem; margin-top: 5px; font-weight: 600; }
    .form-hint     { font-size: 0.72rem; margin-top: 5px; color: var(--text-muted); }
    .req { color: var(--error); }

    .checkbox-container { display: flex; align-items: flex-start; gap: 10px; margin-top: 6px; cursor: pointer; user-select: none; }
    .checkbox-container input { margin-top: 3px; accent-color: var(--primary); }
    .checkbox-text { font-size: 0.82rem; color: var(--text-muted); line-height: 1.45; }
    .checkbox-text strong { color: var(--primary-dark); }

    .login-link { text-align: center; margin-top: 18px; font-size: 0.85rem; color: var(--text-muted); }
    .login-link a { color: var(--primary-dark); font-weight: 700; text-decoration: none; position: relative; }
    .login-link a::after { content: ''; position: absolute; left: 0; bottom: -2px; width: 100%; height: 2px; background: linear-gradient(90deg, #a855f7, #ec4899); transform: scaleX(0); transform-origin: right; transition: transform 0.35s ease; }
    .login-link a:hover::after { transform: scaleX(1); transform-origin: left; }

    .terms-link { color: var(--primary-dark); font-weight: 700; text-decoration: underline; cursor: pointer; background: none; border: none; padding: 0; font-size: inherit; font-family: inherit; display: inline; }
    .terms-link:hover { color: var(--accent); }

    .modal-overlay { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(15,5,30,0.55); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 20px; }
    .modal-overlay.active { display: flex; }

    .modal-box { background: #fff; border-radius: 24px; max-width: 640px; width: 100%; max-height: 82vh; display: flex; flex-direction: column; box-shadow: 0 40px 80px -20px rgba(126,34,206,0.35), 0 10px 20px rgba(0,0,0,0.1); border: 1px solid rgba(168,85,247,0.15); animation: modalIn 0.35s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes modalIn { from { opacity: 0; transform: translateY(28px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }

    .modal-header { padding: 24px 28px 20px; border-bottom: 1px solid #f3e8ff; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .modal-header h3 { font-family: 'Playfair Display', serif; font-size: 1.35rem; font-weight: 800; background: linear-gradient(90deg, #7e22ce, #ec4899); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .modal-close { background: #f3e8ff; border: none; border-radius: 50%; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1rem; color: var(--primary-dark); transition: background 0.2s, transform 0.2s; flex-shrink: 0; }
    .modal-close:hover { background: #e9d5ff; transform: rotate(90deg); }

    .modal-tabs { display: flex; border-bottom: 1px solid #f3e8ff; padding: 0 28px; flex-shrink: 0; }
    .modal-tab { padding: 12px 18px; font-size: 0.82rem; font-weight: 600; letter-spacing: 0.3px; text-transform: uppercase; cursor: pointer; border: none; background: none; color: var(--text-muted); border-bottom: 2.5px solid transparent; margin-bottom: -1px; transition: color 0.2s, border-color 0.2s; font-family: inherit; }
    .modal-tab.active { color: var(--primary-dark); border-bottom-color: var(--primary); }

    .modal-body { padding: 24px 28px; overflow-y: auto; flex: 1; font-size: 0.88rem; line-height: 1.7; color: var(--text-main); }
    .modal-body::-webkit-scrollbar { width: 6px; }
    .modal-body::-webkit-scrollbar-thumb { background: #e9d5ff; border-radius: 10px; }
    .modal-body h4 { font-size: 0.92rem; font-weight: 700; color: var(--primary-dark); margin: 18px 0 6px; }
    .modal-body h4:first-child { margin-top: 0; }
    .modal-body p  { margin-bottom: 10px; color: var(--text-muted); }
    .modal-body ul { padding-left: 18px; margin-bottom: 10px; color: var(--text-muted); }
    .modal-body ul li { margin-bottom: 4px; }

    .modal-footer { padding: 16px 28px; border-top: 1px solid #f3e8ff; display: flex; gap: 10px; justify-content: flex-end; flex-shrink: 0; }
    .btn-outline { padding: 10px 20px; border-radius: 10px; font-size: 0.88rem; font-weight: 600; cursor: pointer; border: 1.5px solid #e9d5ff; background: none; color: var(--text-muted); transition: all 0.2s; font-family: inherit; }
    .btn-outline:hover { border-color: var(--primary); color: var(--primary-dark); }
    .btn-accept { padding: 10px 24px; border-radius: 10px; font-size: 0.88rem; font-weight: 700; cursor: pointer; border: none; background: linear-gradient(135deg, #a855f7, #ec4899); color: #fff; box-shadow: 0 6px 14px -4px rgba(168,85,247,0.5); transition: all 0.2s; font-family: inherit; }
    .btn-accept:hover { transform: translateY(-1px); box-shadow: 0 10px 20px -6px rgba(236,72,153,0.5); }

    .tab-content { display: none; }
    .tab-content.active { display: block; }

    @media (max-width: 860px) {
      .auth-shell { grid-template-columns: 1fr; max-width: 520px; }
      .visual { min-height: 220px; padding: 32px 28px; }
      .visual h2 { font-size: 1.6rem; }
      .form-panel { padding: 32px 28px; }
    }
    @media (max-width: 480px) {
      .form-row { grid-template-columns: 1fr; gap: 0; }
      .modal-box { border-radius: 16px; }
      .modal-header, .modal-body, .modal-footer { padding-left: 18px; padding-right: 18px; }
      .modal-tabs { padding: 0 18px; }
    }
  </style>
</head>

<body>
  <div class="blob b1"></div>
  <div class="blob b2"></div>
  <div class="blob b3"></div>

  <!-- Terms Modal -->
  <div class="modal-overlay" id="termsModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-box">
      <div class="modal-header">
        <h3 id="modalTitle">Terms & Privacy Policy</h3>
        <button class="modal-close" id="closeModal" aria-label="Close">✕</button>
      </div>
      <div class="modal-tabs">
        <button class="modal-tab active" data-tab="terms">Terms of Service</button>
        <button class="modal-tab" data-tab="privacy">Privacy Policy</button>
      </div>
      <div class="modal-body">
        <div class="tab-content active" id="tab-terms">
          <h4>1. Acceptance of Terms</h4>
          <p>By creating an account with SMOIMS, you agree to be bound by these Terms of Service. If you do not agree, please do not register.</p>
          <h4>2. Account Registration</h4>
          <p>You must provide accurate, current, and complete information during registration. You are responsible for maintaining the confidentiality of your credentials.</p>
          <h4>3. Ordering & Merchandise</h4>
          <ul>
            <li>All orders are subject to availability and confirmation.</li>
            <li>Prices are in Philippine Peso (₱) and subject to change.</li>
            <li>Custom orders are non-refundable once production has begun.</li>
            <li>Cancellations must be requested within 24 hours of placement.</li>
          </ul>
          <h4>4. Payment</h4>
          <p>We accept payment through methods specified at checkout. SMOIMS does not store full payment card details.</p>
          <h4>5. Delivery & Pick-up</h4>
          <p>Delivery timelines are estimates. For in-school pick-up, you will be notified once your order is ready.</p>
          <h4>6. Intellectual Property</h4>
          <p>All content on SolisCo. Website is the property of Solis Company and protected by applicable intellectual property laws.</p>
          <h4>7. Prohibited Conduct</h4>
          <ul>
            <li>You may not use SolisCo. for any unlawful purpose.</li>
            <li>Submitting false orders or fraudulent information results in immediate account termination.</li>
          </ul>
          <h4>8. Termination</h4>
          <p>We reserve the right to suspend or terminate accounts that violate these terms without prior notice.</p>
          <h4>9. Changes to Terms</h4>
          <p>SMOIMS may update these terms at any time. Continued use constitutes acceptance of revised terms.</p>
        </div>
        <div class="tab-content" id="tab-privacy">
          <h4>1. Information We Collect</h4>
          <ul>
            <li>Full name, email address, contact number, birthday</li>
            <li>Optional: organization/school affiliation</li>
            <li>Order history and transaction data</li>
          </ul>
          <h4>2. How We Use Your Information</h4>
          <ul>
            <li>To process and fulfill your merchandise orders</li>
            <li>To send order updates and account notifications</li>
            <li>To improve our services</li>
            <li>To comply with legal obligations</li>
          </ul>
          <h4>3. Data Sharing</h4>
          <p>We do not sell or rent your personal information. Data may be shared with service providers strictly for order fulfillment under confidentiality agreements.</p>
          <h4>4. Data Security</h4>
          <p>We use password hashing and encrypted connections. No method of internet transmission is 100% secure.</p>
          <h4>5. Data Retention</h4>
          <p>Data is retained as long as your account is active. You may request deletion by contacting our support team.</p>
          <h4>6. Your Rights (R.A. 10173)</h4>
          <ul>
            <li>Access the personal data we hold about you</li>
            <li>Correct inaccurate or incomplete data</li>
            <li>Object to or request erasure of your data</li>
          </ul>
          <h4>7. Cookies</h4>
          <p>SMOIMS uses session cookies strictly for authentication. No tracking or advertising cookies are used.</p>
          <h4>8. Contact Us</h4>
          <p>For privacy concerns: <strong>privacy@smoims.ph</strong> or visit the Student Affairs Office.</p>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-outline" id="declineTerms">Decline</button>
        <button class="btn-accept" id="acceptTerms">I Accept ✓</button>
      </div>
    </div>
  </div>

  <div class="auth-shell">
    <aside class="visual">
      <div class="brand"><span class="dot"></span> SMOIMS</div>
      <div>
        <h2>Join the<br>SolisCo.</h2>
        <p class="tag">Custom merchandise, made just for your team — designed with care.</p>
      </div>
      <ul class="perks">
        <li><span class="check">✓</span> Personalized merch designs</li>
        <li><span class="check">✓</span> High-quality prints and materials</li>
        <li><span class="check">✓</span> Fast and reliable production</li>
      </ul>
    </aside>

    <section class="form-panel">
      <a href="login.php" class="back-link">← Back to Login</a>

      <div class="reg-logo">Create Account</div>
      <p class="reg-sub">Join SolisCo. for your custom merchandise needs.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
      <?php endif; ?>

      <form method="POST" action="register.php" id="registerForm" novalidate>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full Name <span class="req">*</span></label>
            <input type="text" name="fullname" id="fullname" class="form-control" placeholder="Juan Dela Cruz"
              value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" <?= fieldError($fields, 'fullname') ?> required>
            <div class="form-hint" id="fullname-hint"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Organization</label>
            <input type="text" name="organization" class="form-control" placeholder="Optional"
              value="<?= htmlspecialchars($_POST['organization'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address <span class="req">*</span></label>
          <input type="email" name="email" id="email" class="form-control" placeholder="you@gmail.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" <?= fieldError($fields, 'email') ?> required>
          <div class="form-hint" id="email-hint">Allowed: gmail.com, yahoo.com, outlook.com, or school email</div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Contact Number <span class="req">*</span></label>
            <input type="text" name="contact" id="contact" class="form-control" placeholder="09XXXXXXXXX"
              value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" <?= fieldError($fields, 'contact') ?> maxlength="13" required>
            <div class="form-hint" id="contact-hint">Format: 09XXXXXXXXX</div>
          </div>
          <div class="form-group">
            <label class="form-label">Birthday</label>
            <input type="date" name="birthday" id="birthday" class="form-control"
              value="<?= htmlspecialchars($_POST['birthday'] ?? '') ?>" <?= fieldError($fields, 'birthday') ?> max="<?= date('Y-m-d') ?>">
            <div class="form-hint" id="birthday-hint">Optional (Must be 13+)</div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Password <span class="req">*</span></label>
            <input type="password" name="password" id="password" class="form-control" placeholder="••••••••"
              <?= fieldError($fields, 'password') ?> required>
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-text" id="strengthText"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm <span class="req">*</span></label>
            <input type="password" name="confirm" id="confirm" class="form-control" placeholder="••••••••"
              <?= fieldError($fields, 'confirm') ?> required>
            <div class="form-hint" id="confirm-hint"></div>
          </div>
        </div>

        <div class="form-group">
          <label class="checkbox-container">
            <input type="checkbox" id="terms" required>
            <span class="checkbox-text">I agree to the
              <button type="button" class="terms-link" id="openTermsModal">Terms and Conditions</button>
              and privacy policy.
            </span>
          </label>
          <div class="form-hint" id="terms-hint" style="color:var(--error)"></div>
        </div>

        <button type="submit" class="btn-primary" id="submitBtn">
          Create Account →
        </button>

        <div class="login-link">
          Already have an account? <a href="login.php">Sign in</a>
        </div>
      </form>
    </section>
  </div>

  <script>
    // ── Helpers ──
    function showHint(id, msg, isError = true) {
      const el = document.getElementById(id + '-hint');
      if (!el) return;
      el.textContent = msg;
      el.style.color = isError ? '#ef4444' : '#22c55e';
      const input = document.getElementById(id);
      if (input) input.style.borderColor = isError ? '#ef4444' : '#22c55e';
    }

    function clearHint(id, defaultMsg = '') {
      const el = document.getElementById(id + '-hint');
      if (!el) return;
      el.textContent = defaultMsg;
      el.style.color = '';
      const input = document.getElementById(id);
      if (input) input.style.borderColor = '';
    }

    // ── Full Name ──
    document.getElementById('fullname').addEventListener('blur', function () {
      const val = this.value.trim();
      if (!val) { showHint('fullname', 'Full name is required.'); return; }
      if (val.length < 2) { showHint('fullname', 'Name is too short.'); return; }
      if (val.length > 80) { showHint('fullname', 'Name is too long (max 80 characters).'); return; }
      if (!/^[a-zA-Z\s.\-']+$/.test(val)) { showHint('fullname', 'Only letters, spaces, hyphens, periods, or apostrophes allowed.'); return; }
      if (/\s{2,}/.test(val)) { showHint('fullname', 'No double spaces allowed.'); return; }
      if (/^[\s.\-']|[\s.\-']$/.test(val)) { showHint('fullname', 'Name cannot start or end with a space or symbol.'); return; }
      const parts = val.split(/\s+/);
      if (parts.length < 2) { showHint('fullname', 'Please enter your first and last name.'); return; }
      if (parts.some(p => p.length < 2 && !/^[A-Z]\.$/.test(p))) { showHint('fullname', 'Each name part must be at least 2 characters (initials like "J." are OK).'); return; }
      if (/(.)\1{3,}/i.test(val.replace(/\s/g, ''))) { showHint('fullname', 'Name looks invalid (repeated characters).'); return; }
      // Live duplicate check
      showHint('fullname', 'Checking…', false);
      fetch('check_fullname.php?fullname=' + encodeURIComponent(val))
        .then(r => r.json())
        .then(data => {
          if (data.taken) {
            showHint('fullname', 'That name is already registered. If this is you, please log in instead.');
          } else {
            showHint('fullname', '✓ Looks good!', false);
          }
        })
        .catch(() => showHint('fullname', '✓ Format OK', false));
    });

    // ── Email ──
    const allowedDomains = ['gmail.com','yahoo.com','outlook.com','hotmail.com','icloud.com','protonmail.com'];

    document.getElementById('email').addEventListener('blur', function () {
      const re  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      const val = this.value.trim();
      if (!val) { showHint('email', 'Email is required.'); return; }
      if (!re.test(val)) { showHint('email', 'Enter a valid email.'); return; }
      const domain = val.split('@')[1].toLowerCase();
      if (!allowedDomains.includes(domain)) { showHint('email', 'Domain not authorized. Use gmail, yahoo, outlook, or your school email.'); return; }
      // Live duplicate check
      showHint('email', 'Checking availability…', false);
      fetch('check_email.php?email=' + encodeURIComponent(val))
        .then(r => r.json())
        .then(data => {
          if (data.taken) {
            showHint('email', 'That email is already registered.');
          } else {
            showHint('email', '✓ Available!', false);
          }
        })
        .catch(() => showHint('email', '✓ Format OK', false));
    });

    // ── Contact ──
    document.getElementById('contact').addEventListener('blur', function () {
      const re = /^(09|\+639)\d{9}$/;
      if (!this.value.trim()) { showHint('contact', 'Contact is required.'); return; }
      if (!re.test(this.value.trim())) { showHint('contact', 'Invalid format. Use 09XXXXXXXXX.'); return; }
      showHint('contact', '✓ Valid!', false);
    });

    // ── Birthday ──
    document.getElementById('birthday').addEventListener('change', function () {
      if (!this.value) { clearHint('birthday', 'Optional. Must be 13+'); return; }
      const bday = new Date(this.value);
      const age13 = new Date();
      age13.setFullYear(age13.getFullYear() - 13);
      if (bday > new Date()) { showHint('birthday', 'Future date?'); }
      else if (bday > age13) { showHint('birthday', 'Must be 13+'); }
      else { showHint('birthday', '✓ Valid', false); }
    });

    // ── Password strength ──
    document.getElementById('password').addEventListener('input', function () {
      const val = this.value;
      const fill = document.getElementById('strengthFill');
      const label = document.getElementById('strengthText');
      let score = 0;
      if (val.length >= 8) score++;
      if (/[A-Z]/.test(val)) score++;
      if (/[0-9]/.test(val)) score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;
      const levels = [
        { pct: '0%',   color: '#e5e7eb', text: '' },
        { pct: '25%',  color: '#ef4444', text: 'Weak' },
        { pct: '50%',  color: '#f59e0b', text: 'Fair' },
        { pct: '75%',  color: '#3b82f6', text: 'Good' },
        { pct: '100%', color: '#22c55e', text: 'Strong!' },
      ];
      fill.style.width      = levels[score].pct;
      fill.style.background = levels[score].color;
      label.textContent     = levels[score].text;
      label.style.color     = levels[score].color;
    });

    // ── Confirm password ──
    document.getElementById('confirm').addEventListener('blur', function () {
      const pass = document.getElementById('password').value;
      if (this.value !== pass) { showHint('confirm', 'Passwords do not match.'); }
      else if (this.value) { showHint('confirm', '✓ Match!', false); }
    });

    // ── Form submit guard ──
    document.getElementById('registerForm').addEventListener('submit', function (e) {
      const terms = document.getElementById('terms');
      if (!terms.checked) {
        e.preventDefault();
        document.getElementById('terms-hint').textContent = 'Please agree to terms.';
        return;
      }
      let hasEmpty = false;
      this.querySelectorAll('input[required]').forEach(function (input) {
        if (input.type === 'checkbox') return;
        if (!input.value.trim()) { input.style.borderColor = '#ef4444'; hasEmpty = true; }
      });
      if (hasEmpty) e.preventDefault();
    });

    // ── Terms Modal ──
    const modal      = document.getElementById('termsModal');
    const openBtn    = document.getElementById('openTermsModal');
    const closeBtn   = document.getElementById('closeModal');
    const acceptBtn  = document.getElementById('acceptTerms');
    const declineBtn = document.getElementById('declineTerms');
    const termsCheck = document.getElementById('terms');

    function openModal()  { modal.classList.add('active');    document.body.style.overflow = 'hidden'; }
    function closeModal() { modal.classList.remove('active'); document.body.style.overflow = ''; }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    acceptBtn.addEventListener('click', function () { termsCheck.checked = true; document.getElementById('terms-hint').textContent = ''; closeModal(); });
    declineBtn.addEventListener('click', function () { termsCheck.checked = false; closeModal(); });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('active')) closeModal(); });

    document.querySelectorAll('.modal-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.modal-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
      });
    });
  </script>
</body>
</html>