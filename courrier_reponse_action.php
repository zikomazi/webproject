<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();

if (!can_add_reponse()) {
    http_response_code(403);
    die('Vous n\'avez pas les droits pour enregistrer une réponse.');
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

$courrier_id      = (int) ($_POST['courrier_id'] ?? 0);
$numero_reference = trim($_POST['numero_reference'] ?? '');
$date_envoi       = $_POST['date_envoi'] ?: date('Y-m-d');
$projet_lettre    = isset($_POST['projet_lettre']);
$projet_message   = isset($_POST['projet_message']);

$stmt = $pdo->prepare('SELECT * FROM courriers WHERE id = :id');
$stmt->execute(['id' => $courrier_id]);
$courrier = $stmt->fetch();

if (!$courrier) {
    redirect_with_message('courriers.php', 'Courrier introuvable.', 'danger');
}
if ($numero_reference === '') {
    redirect_with_message('courriers.php', 'Le numéro de référence de la réponse est obligatoire.', 'danger');
}

// Une réponse existe déjà pour ce courrier ?
$stmt = $pdo->prepare('SELECT id FROM reponses WHERE courrier_id = :cid');
$stmt->execute(['cid' => $courrier_id]);
if ($stmt->fetchColumn()) {
    redirect_with_message("courrier_view.php?id={$courrier_id}", 'Une réponse est déjà enregistrée pour ce courrier.', 'danger');
}

// Pièce jointe (facultative)
$nom_fichier = null;
$chemin      = null;
if (!empty($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
    $extensions_autorisees = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $extension = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions_autorisees, true)) {
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Type de fichier non autorisé pour la pièce jointe.', 'danger');
    }
    $nom_stocke = uniqid('rep_', true) . '.' . $extension;
    $dossier    = __DIR__ . '/assets/uploads/';
    if (!is_dir($dossier)) {
        mkdir($dossier, 0755, true);
    }
    if (move_uploaded_file($_FILES['fichier']['tmp_name'], $dossier . $nom_stocke)) {
        $nom_fichier = $_FILES['fichier']['name'];
        $chemin      = 'assets/uploads/' . $nom_stocke;
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO reponses (courrier_id, numero_reference, date_envoi, nom_fichier, chemin_fichier, projet_lettre, projet_message, created_by)
         VALUES (:cid, :ref, :denvoi, :nom, :chemin, :pl::boolean, :pm::boolean, :cb)
         RETURNING id'
    );
    $stmt->execute([
        'cid'    => $courrier_id,
        'ref'    => $numero_reference,
        'denvoi' => $date_envoi,
        'nom'    => $nom_fichier,
        'chemin' => $chemin,
        'pl'     => $projet_lettre ? 'true' : 'false',
        'pm'     => $projet_message ? 'true' : 'false',
        'cb'     => current_user_id(),
    ]);
    $reponse_id = (int) $stmt->fetchColumn();

    // Statut du courrier -> répondu
    $pdo->prepare("UPDATE courriers SET statut = 'repondu' WHERE id = :id")->execute(['id' => $courrier_id]);

    // Si projet lettre/message coché -> crée le suivi C9 en attente
    if ($projet_lettre || $projet_message) {
        $pdo->prepare(
            'INSERT INTO c9 (reponse_id, statut, created_by) VALUES (:rid, :statut, :cb)'
        )->execute(['rid' => $reponse_id, 'statut' => 'en_attente', 'cb' => current_user_id()]);
    }

    log_action(
        $pdo, $courrier_id, 'reponse', $courrier['statut'], 'repondu',
        "Référence de réponse enregistrée : {$numero_reference}"
        . (($projet_lettre || $projet_message) ? ' — Suivi C9 créé (en attente).' : '')
    );

    $pdo->commit();

    // Notifie chaque agent destinataire que la réponse a été enregistrée
    notifier_destinataires(
        $pdo, $courrier_id, 'reponse_enregistree',
        "La réponse a été enregistrée pour le courrier {$courrier['reference']} (réf. réponse : {$numero_reference})."
    );
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect_with_message("courrier_view.php?id={$courrier_id}", 'Erreur lors de l\'enregistrement de la réponse : ' . $e->getMessage(), 'danger');
}

redirect_with_message("courrier_view.php?id={$courrier_id}", 'Réponse enregistrée avec succès.');
