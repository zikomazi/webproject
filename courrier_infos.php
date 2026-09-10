<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();

header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courriers WHERE id = :id');
$stmt->execute(['id' => $id]);
$courrier = $stmt->fetch();

if (!$courrier || !can_view_courrier($pdo, $courrier)) {
    http_response_code(403);
    echo json_encode(['error' => 'acces_refuse']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM reponses WHERE courrier_id = :id');
$stmt->execute(['id' => $id]);
$reponse = $stmt->fetch();

$c9 = null;
if ($reponse) {
    $stmt = $pdo->prepare('SELECT * FROM c9 WHERE reponse_id = :rid');
    $stmt->execute(['rid' => $reponse['id']]);
    $c9 = $stmt->fetch() ?: null;
}

echo json_encode([
    'id'              => (int) $courrier['id'],
    'reference'       => $courrier['reference'],
    'objet'           => $courrier['objet'],
    'priorite'        => $courrier['priorite'],
    'reponse_requise' => (bool) $courrier['reponse_requise'],
    'date_courrier'   => $courrier['date_courrier'],
    'date_reception'  => $courrier['date_reception'],
    'observations'    => $courrier['observations'],
    'peut_modifier'   => can_edit_courrier($courrier),
    'peut_supprimer'  => is_admin(),
    'peut_gerer_reponse' => can_add_reponse(),
    'reponse'         => $reponse ? [
        'numero_reference' => $reponse['numero_reference'],
        'date_envoi'       => $reponse['date_envoi'] ? substr($reponse['date_envoi'], 0, 10) : null,
        'projet_lettre'    => (bool) $reponse['projet_lettre'],
        'projet_message'   => (bool) $reponse['projet_message'],
    ] : null,
    'c9' => $c9 ? [
        'id'               => (int) $c9['id'],
        'numero_reference' => $c9['numero_reference'],
        'date_envoi'       => $c9['date_envoi'] ? substr($c9['date_envoi'], 0, 10) : null,
        'date_reception'   => $c9['date_reception'] ? substr($c9['date_reception'], 0, 10) : null,
        'statut'           => $c9['statut'],
    ] : null,
]);
