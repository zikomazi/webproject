<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();

if (!can_send_courrier()) {
    http_response_code(403);
    die('Vous n\'avez pas les droits pour envoyer un courrier.');
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

// ---------- Détection d'un échec lié aux limites serveur (post_max_size) ----------
// Si le POST entier dépasse post_max_size, PHP vide $_POST et $_FILES sans erreur
// explicite : on le détecte ici pour donner un message clair plutôt qu'une série
// d'erreurs de type "champ obligatoire" qui ne veut rien dire pour l'utilisateur.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    redirect_with_message(
        'courriers.php',
        'Le fichier envoyé est trop volumineux pour le serveur (limite post_max_size dépassée). '
        . 'Demandez à l\'administrateur d\'augmenter cette limite dans php.ini ou le .htaccess.',
        'danger'
    );
}

// Erreur d'upload précise (fichier trop gros pour upload_max_filesize, upload interrompu, etc.)
if (!empty($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_OK && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
    $messages_erreur_upload = [
        UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille maximale autorisée par le serveur (upload_max_filesize). Contactez l\'administrateur pour l\'augmenter.',
        UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
        UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement envoyé (connexion interrompue). Réessayez.',
        UPLOAD_ERR_NO_TMP_DIR => 'Erreur serveur : dossier temporaire manquant. Contactez l\'administrateur.',
        UPLOAD_ERR_CANT_WRITE => 'Erreur serveur : impossible d\'écrire le fichier sur le disque. Contactez l\'administrateur.',
        UPLOAD_ERR_EXTENSION  => 'Une extension PHP a bloqué l\'envoi du fichier.',
    ];
    redirect_with_message(
        'courriers.php',
        $messages_erreur_upload[$_FILES['fichier']['error']] ?? 'Erreur lors de l\'envoi du fichier (code ' . $_FILES['fichier']['error'] . ').',
        'danger'
    );
}

$reference      = trim($_POST['reference'] ?? '');
$objet          = trim($_POST['objet'] ?? '');
$priorite_recue = $_POST['priorite'] ?? 'simple';
$observations   = trim($_POST['observations'] ?? '');
$date_courrier  = $_POST['date_courrier'] ?: null;
$date_reception = $_POST['date_reception'] ?: null;
$destinataires  = array_unique(array_map('intval', $_POST['destinataires'] ?? []));
$pilote_id      = (int) ($_POST['pilote_id'] ?? 0);

// "Pas de réponse" est une option du menu priorité : elle ne modifie pas
// vraiment l'urgence, elle indique simplement que ce courrier n'attend
// pas de réponse (pas de suivi de retard, pas de bouton "Ajouter la réponse").
if ($priorite_recue === 'pas_de_reponse') {
    $priorite        = 'simple';
    $reponse_requise = false;
} else {
    $priorite        = $priorite_recue;
    $reponse_requise = true;
}

$erreurs = [];
if ($reference === '') {
    $erreurs[] = 'Le numéro de référence est obligatoire.';
}
if ($objet === '') {
    $erreurs[] = 'L\'objet est obligatoire.';
}
if (!in_array($priorite, ['tres_urgent', 'urgent', 'simple'], true)) {
    $erreurs[] = 'Priorité invalide.';
}
if (empty($destinataires)) {
    $erreurs[] = 'Sélectionnez au moins un destinataire (ventilation).';
}

// Détermine le pilote : si un seul destinataire -> lui automatiquement ;
// sinon celui coché par le secrétariat/admin (sinon le premier sélectionné).
if (!empty($destinataires)) {
    if (count($destinataires) === 1) {
        $pilote_id = $destinataires[0];
    } elseif (!in_array($pilote_id, $destinataires, true)) {
        $pilote_id = $destinataires[0];
    }
}
$scan_source = basename($_POST['scan_source'] ?? '');
$upload_fourni = !empty($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK;

if (!$upload_fourni && $scan_source === '') {
    $erreurs[] = 'La pièce jointe (courrier scanné) est obligatoire.';
}

$extensions_autorisees = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
$chemin_scan_source = null;

if (empty($erreurs) && $upload_fourni) {
    $extension = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions_autorisees, true)) {
        $erreurs[] = 'Type de fichier non autorisé.';
    }
    if ($_FILES['fichier']['size'] > 15 * 1024 * 1024) {
        $erreurs[] = 'Le fichier dépasse la taille maximale de 15 Mo.';
    }
} elseif (empty($erreurs) && $scan_source !== '') {
    $dossier_scans      = __DIR__ . '/assets/scans_entrants';
    $chemin_scan_source = $dossier_scans . '/' . $scan_source;
    // Sécurité : empêche toute tentative de sortir du dossier des scans (../..)
    if (!file_exists($chemin_scan_source) || realpath($chemin_scan_source) !== realpath($dossier_scans) . DIRECTORY_SEPARATOR . $scan_source) {
        $erreurs[] = 'Le fichier scanné sélectionné est introuvable.';
    } else {
        $extension = strtolower(pathinfo($scan_source, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions_autorisees, true)) {
            $erreurs[] = 'Type de fichier scanné non autorisé.';
        }
    }
}

// Référence déjà utilisée ?
if (empty($erreurs)) {
    $stmt = $pdo->prepare('SELECT 1 FROM courriers WHERE reference = :reference');
    $stmt->execute(['reference' => $reference]);
    if ($stmt->fetchColumn()) {
        $erreurs[] = 'Ce numéro de référence est déjà utilisé.';
    }
}

if (!empty($erreurs)) {
    redirect_with_message('courriers.php', implode(' ', $erreurs), 'danger');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO courriers (reference, objet, priorite, observations, date_courrier, date_reception, reponse_requise, created_by)
         VALUES (:reference, :objet, :priorite, :observations, :date_courrier, :date_reception, :reponse_requise::boolean, :created_by)
         RETURNING id'
    );
    $stmt->execute([
        'reference'       => $reference,
        'objet'           => $objet,
        'priorite'        => $priorite,
        'observations'    => $observations ?: null,
        'date_courrier'   => $date_courrier,
        'date_reception'  => $date_reception,
        'reponse_requise' => $reponse_requise ? 'true' : 'false',
        'created_by'      => current_user_id(),
    ]);
    $courrier_id = (int) $stmt->fetchColumn();

    // Ventilation (destinataires) avec un seul pilote
    $stmt_dest = $pdo->prepare(
        'INSERT INTO courrier_destinataires (courrier_id, employe_id, est_pilote) VALUES (:cid, :eid, :pilote::boolean)'
    );
    foreach ($destinataires as $employe_id) {
        $stmt_dest->execute([
            'cid'    => $courrier_id,
            'eid'    => $employe_id,
            'pilote' => ($employe_id === $pilote_id) ? 'true' : 'false',
        ]);
    }

    // Pièce jointe : soit un upload manuel, soit une copie depuis le dossier des scans
    $dossier = __DIR__ . '/assets/uploads/';
    if (!is_dir($dossier)) {
        mkdir($dossier, 0755, true);
    }

    if ($upload_fourni) {
        $extension  = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
        $nom_stocke = uniqid('pj_', true) . '.' . $extension;
        if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $dossier . $nom_stocke)) {
            throw new RuntimeException('Impossible d\'enregistrer le fichier.');
        }
        $nom_original = $_FILES['fichier']['name'];
        $type_mime    = $_FILES['fichier']['type'];
        $taille       = $_FILES['fichier']['size'];
    } else {
        $extension  = strtolower(pathinfo($scan_source, PATHINFO_EXTENSION));
        $nom_stocke = uniqid('scan_', true) . '.' . $extension;
        // On DÉPLACE (et non copie) le fichier : il disparaît du dossier des scans,
        // ce qui empêche qu'un autre secrétaire le sélectionne aussi entre-temps.
        if (!rename($chemin_scan_source, $dossier . $nom_stocke)) {
            throw new RuntimeException('Impossible de déplacer le fichier scanné (peut-être déjà utilisé par un autre courrier).');
        }
        $nom_original = $scan_source;
        $type_mime    = mime_content_type($dossier . $nom_stocke) ?: null;
        $taille       = filesize($dossier . $nom_stocke);
    }

    $pdo->prepare(
        'INSERT INTO pieces_jointes (courrier_id, nom_fichier, chemin, type_mime, taille, uploaded_by)
         VALUES (:cid, :nom, :chemin, :mime, :taille, :uploaded_by)'
    )->execute([
        'cid'         => $courrier_id,
        'nom'         => $nom_original,
        'chemin'      => 'assets/uploads/' . $nom_stocke,
        'mime'        => $type_mime,
        'taille'      => $taille,
        'uploaded_by' => current_user_id(),
    ]);

    log_action($pdo, $courrier_id, 'creation', null, null, "Courrier {$reference} envoyé à " . count($destinataires) . ' destinataire(s).');

    $pdo->commit();

    // Notifie chaque destinataire (compte simple) qu'il a reçu un nouveau courrier
    notifier_destinataires(
        $pdo, $courrier_id, 'nouveau_courrier',
        "Vous avez reçu un nouveau courrier : {$reference} — {$objet}"
    );
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect_with_message('courriers.php', 'Erreur lors de l\'envoi du courrier : ' . $e->getMessage(), 'danger');
}

redirect_with_message("courrier_view.php?id={$courrier_id}", "Courrier {$reference} envoyé avec succès.");
