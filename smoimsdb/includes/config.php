<?php
// ============================================================
// SMOIMS - Config / Bootstrap
// FILE: start.php
// ============================================================

define('SITE_NAME', 'SMOIMS');
define('SITE_TAGLINE', 'Solis Merchandising Order and Inventory Management System');

// ── Database connection (OOP style) ──
$conn = new mysqli("localhost", "root", "", "smoims_db");

if ($conn->connect_error) {
    die("<div style='font-family:sans-serif;padding:20px;color:#991b1b;background:#fca5a5;border-radius:8px;margin:20px;'>
        <strong>Database Connection Failed:</strong> " . $conn->connect_error . "
        <br><small>Check your database credentials in start.php</small>
    </div>");
}

$conn->set_charset("utf8mb4");

// ── Start session if not already started ──
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Guard: redirect staff to dashboard if already logged in ──
function requireStaffLogin() {
    if (!isset($_SESSION['staff_id'])) {
        header('Location: ../mainhomepage.php');
        exit;
    }
}

// ── Guard: redirect customers to login if not logged in ──
function requireCustomerLogin() {
    if (!isset($_SESSION['customer_id'])) {
        header('Location: ../mainhomepage.php');
        exit;
    }
}

// ── Sanitize user input before using in SQL ──
function clean($conn, $val) {
    return mysqli_real_escape_string($conn, htmlspecialchars(trim($val)));
}

// ── If this file is visited directly, redirect to login ──
// Remove this block if start.php is placed inside an includes/ folder
if (basename($_SERVER['PHP_SELF']) === 'includes/config.php') {
    header('Location: index.php');
    exit;
}
?>