<?php
require_once 'includes/config.php';
header('Content-Type: application/json');

$email = clean($conn, $_GET['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['taken' => false]);
    exit;
}

$result = $conn->query("SELECT id FROM customers WHERE email = '$email' LIMIT 1");
echo json_encode(['taken' => $result && $result->num_rows > 0]);
