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

limiter_debit('evenements_dus', 10);

$evenements = evenements_dus($pdo);

echo json_encode(array_map(function ($e) {
    return [
        'id'    => (int) $e['id'],
        'titre' => $e['titre'],
        'date'  => $e['date_evenement'],
        'heure' => $e['heure'],
    ];
}, $evenements));
