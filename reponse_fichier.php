<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();

$courrier_id = (int) ($_GET['courrier_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM courriers WHERE id = :id');
$stmt->execute(['id' => $courrier_id]);
$courrier = $stmt->fetch();

if (!$courrier || !can_view_courrier($pdo, $courrier)) {
    http_response_code(403);
    die('Accès refusé.');
}

$stmt = $pdo->prepare('SELECT * FROM reponses WHERE courrier_id = :cid');
$stmt->execute(['cid' => $courrier_id]);
$reponse = $stmt->fetch();

if (!$reponse || !$reponse['chemin_fichier']) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$chemin = __DIR__ . '/' . $reponse['chemin_fichier'];
if (!file_exists($chemin)) {
    http_response_code(404);
    die('Le fichier n\'existe plus sur le serveur.');
}

$mode = ($_GET['mode'] ?? 'consulter') === 'telecharger' ? 'attachment' : 'inline';
$extension = strtolower(pathinfo($reponse['nom_fichier'], PATHINFO_EXTENSION));
$mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

header('Content-Type: ' . ($mimes[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: ' . $mode . '; filename="' . basename($reponse['nom_fichier']) . '"');
header('Content-Length: ' . filesize($chemin));
readfile($chemin);
exit;
