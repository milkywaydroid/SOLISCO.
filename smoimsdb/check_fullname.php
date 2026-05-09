<?php
require_once 'includes/config.php';
header('Content-Type: application/json');

$fullname = clean($conn, $_GET['fullname'] ?? '');

if (empty($fullname)) {
    echo json_encode(['taken' => false]);
    exit;
}

$result = $conn->query("SELECT id FROM customers WHERE full_name = '$fullname' LIMIT 1");
echo json_encode(['taken' => $result && $result->num_rows > 0]);