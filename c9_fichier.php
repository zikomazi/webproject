<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();

$c9_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c9.*, r.courrier_id FROM c9
     JOIN reponses r ON r.id = c9.reponse_id
     WHERE c9.id = :id'
);
$stmt->execute(['id' => $c9_id]);
$c9 = $stmt->fetch();

if (!$c9) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$stmt = $pdo->prepare('SELECT * FROM courriers WHERE id = :id');
$stmt->execute(['id' => $c9['courrier_id']]);
$courrier = $stmt->fetch();

if (!$courrier || !can_view_courrier($pdo, $courrier)) {
    http_response_code(403);
    die('Accès refusé.');
}

if (!$c9['chemin_fichier']) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$chemin = __DIR__ . '/' . $c9['chemin_fichier'];
if (!file_exists($chemin)) {
    http_response_code(404);
    die('Le fichier n\'existe plus sur le serveur.');
}

$mode = ($_GET['mode'] ?? 'consulter') === 'telecharger' ? 'attachment' : 'inline';
$extension = strtolower(pathinfo($c9['nom_fichier'], PATHINFO_EXTENSION));
$mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

header('Content-Type: ' . ($mimes[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: ' . $mode . '; filename="' . basename($c9['nom_fichier']) . '"');
header('Content-Length: ' . filesize($chemin));
readfile($chemin);
exit;
