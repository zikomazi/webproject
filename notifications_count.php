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

limiter_debit('notifications_count', 3);

$last_id = (int) ($_GET['last_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id, type, message, courrier_id FROM notifications
     WHERE employe_id = :id AND id > :last_id
     ORDER BY id ASC
     LIMIT 20'
);
$stmt->execute(['id' => current_user_id(), 'last_id' => $last_id]);
$nouvelles = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE employe_id = :id');
$stmt->execute(['id' => current_user_id()]);
$max_id = (int) $stmt->fetchColumn();

echo json_encode([
    'count'     => nb_notifications_non_lues($pdo),
    'nouvelles' => $nouvelles,
    'max_id'    => $max_id,
]);
