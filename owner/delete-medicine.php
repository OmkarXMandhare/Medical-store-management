<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$token = $_POST['csrf_token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit('Invalid security token. Return to inventory and try again.');
}

$id = filter_input(
    INPUT_POST,
    'medicine_id',
    FILTER_VALIDATE_INT
);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid medicine ID.');
}

$stmt = $pdo->prepare(
    "DELETE FROM inventory WHERE id = :id"
);

$stmt->execute([':id' => $id]);

header('Location: inventory.php?deleted=1');
exit;