<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();

if (!can_add_reponse()) {
    http_response_code(403);
    die('Vous n\'avez pas les droits pour cette action.');
}

require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: courriers.php');
    exit;
}

csrf_verify();

$c9_id             = (int) ($_POST['c9_id'] ?? 0);
$numero_reference  = trim($_POST['numero_reference'] ?? '');
$date_envoi        = $_POST['date_envoi'] ?: null;
$date_reception    = $_POST['date_reception'] ?: null;

$stmt = $pdo->prepare(
    'SELECT c9.*, r.courrier_id, c.reference FROM c9
     JOIN reponses r ON r.id = c9.reponse_id
     JOIN courriers c ON c.id = r.courrier_id
     WHERE c9.id = :id'
);
$stmt->execute(['id' => $c9_id]);
$c9 = $stmt->fetch();

if (!$c9) {
    redirect_with_message('courriers.php', 'Suivi C9 introuvable.', 'danger');
}
if ($numero_reference === '') {
    redirect_with_message("courrier_view.php?id={$c9['courrier_id']}", 'Le numéro de référence est obligatoire.', 'danger');
}

// Pièce jointe (facultative)
$nom_fichier = $c9['nom_fichier'];
$chemin      = $c9['chemin_fichier'];
if (!empty($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
    $extensions_autorisees = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $extension = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions_autorisees, true)) {
        redirect_with_message("courrier_view.php?id={$c9['courrier_id']}", 'Type de fichier non autorisé.', 'danger');
    }
    $nom_stocke = uniqid('c9_', true) . '.' . $extension;
    $dossier    = __DIR__ . '/assets/uploads/';
    if (!is_dir($dossier)) {
        mkdir($dossier, 0755, true);
    }
    if (move_uploaded_file($_FILES['fichier']['tmp_name'], $dossier . $nom_stocke)) {
        $nom_fichier = $_FILES['fichier']['name'];
        $chemin      = 'assets/uploads/' . $nom_stocke;
    }
}

$pdo->prepare(
    "UPDATE c9 SET numero_reference = :ref, date_envoi = :denvoi, date_reception = :drecep,
     nom_fichier = :nom, chemin_fichier = :chemin, statut = 'cloture' WHERE id = :id"
)->execute([
    'ref'    => $numero_reference,
    'denvoi' => $date_envoi,
    'drecep' => $date_reception,
    'nom'    => $nom_fichier,
    'chemin' => $chemin,
    'id'     => $c9_id,
]);

log_action($pdo, $c9['courrier_id'], 'c9', 'en_attente', 'cloture', "Clôture C9 enregistrée : {$numero_reference}");

notifier_destinataires(
    $pdo, $c9['courrier_id'], 'c9_cloture',
    "Le suivi C9 du courrier {$c9['reference']} a été clôturé (réf. : {$numero_reference})."
);

redirect_with_message("courrier_view.php?id={$c9['courrier_id']}", 'Suivi C9 clôturé avec succès.');
