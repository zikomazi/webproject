<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$piece_id    = (int) ($_GET['id'] ?? 0);
$courrier_id = (int) ($_GET['courrier_id'] ?? 0);
$mode        = ($_GET['mode'] ?? 'telecharger') === 'consulter' ? 'consulter' : 'telecharger';

$stmt = $pdo->prepare('SELECT * FROM pieces_jointes WHERE id = :id AND courrier_id = :cid');
$stmt->execute(['id' => $piece_id, 'cid' => $courrier_id]);
$piece = $stmt->fetch();

if (!$piece) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$stmt = $pdo->prepare('SELECT * FROM courriers WHERE id = :id');
$stmt->execute(['id' => $courrier_id]);
$courrier = $stmt->fetch();

if (!$courrier || !can_view_courrier($pdo, $courrier)) {
    http_response_code(403);
    die('Accès refusé.');
}

// ---------- Mode "consulter" : simple aperçu, ne compte pas comme téléchargement ----------
// ---------- Mode "telecharger" : trace le téléchargement + notifie secrétariat/admin ----------
if ($mode === 'telecharger' && is_simple()) {
    $stmt = $pdo->prepare('SELECT * FROM courrier_destinataires WHERE courrier_id = :cid AND employe_id = :eid');
    $stmt->execute(['cid' => $courrier_id, 'eid' => current_user_id()]);
    $destinataire_row = $stmt->fetch();

    if ($destinataire_row && empty($destinataire_row['date_telechargement'])) {
        $pdo->prepare('UPDATE courrier_destinataires SET date_telechargement = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $destinataire_row['id']]);

        notifier_secretariat_admin(
            $pdo, $courrier_id, current_user_id(), 'telechargement',
            "{$_SESSION['prenom']} {$_SESSION['nom']} a téléchargé la pièce jointe du courrier {$courrier['reference']}."
        );
    }
}

$chemin_fichier = __DIR__ . '/' . $piece['chemin'];
if (!file_exists($chemin_fichier)) {
    http_response_code(404);
    die('Le fichier n\'existe plus sur le serveur.');
}

$disposition = $mode === 'consulter' ? 'inline' : 'attachment';

header('Content-Description: File Transfer');
header('Content-Type: ' . ($piece['type_mime'] ?: 'application/octet-stream'));
header('Content-Disposition: ' . $disposition . '; filename="' . basename($piece['nom_fichier']) . '"');
header('Content-Length: ' . filesize($chemin_fichier));
header('Cache-Control: must-revalidate');
readfile($chemin_fichier);
exit;
