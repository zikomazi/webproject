<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'non_connecte']);
    exit;
}

require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

limiter_debit('verifier_retards', 30);

verifier_et_notifier_retards($pdo);

echo json_encode(['ok' => true]);
