<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: courriers.php');
    exit;
}

csrf_verify();

$courrier_id = (int) ($_POST['courrier_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courriers WHERE id = :id');
$stmt->execute(['id' => $courrier_id]);
$courrier = $stmt->fetch();

if (!$courrier) {
    redirect_with_message('courriers.php', 'Courrier introuvable.', 'danger');
}
if (!can_view_courrier($pdo, $courrier)) {
    http_response_code(403);
    die('Accès refusé.');
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ---------------- Compte simple : marquer en cours ----------------
    case 'marquer_en_cours':
        if (!is_simple()) {
            http_response_code(403);
            die('Action réservée aux agents.');
        }
        // Vérifie que l'utilisateur est bien destinataire de ce courrier
        $stmt = $pdo->prepare('SELECT 1 FROM courrier_destinataires WHERE courrier_id = :cid AND employe_id = :eid');
        $stmt->execute(['cid' => $courrier_id, 'eid' => current_user_id()]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            die('Ce courrier ne vous est pas destiné.');
        }

        $pdo->prepare("UPDATE courriers SET statut = 'en_cours' WHERE id = :id")->execute(['id' => $courrier_id]);
        log_action($pdo, $courrier_id, 'changement_statut', $courrier['statut'], 'en_cours', "{$_SESSION['prenom']} {$_SESSION['nom']} a démarré le traitement.");
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Courrier marqué en cours de traitement.');
        break;

    // ---------------- Admin : modifier la priorité ----------------
    case 'changer_priorite':
        if (!can_modify_priorite()) {
            http_response_code(403);
            die('Action réservée à l\'administrateur.');
        }
        $nouvelle_priorite = $_POST['priorite'] ?? '';
        if (!in_array($nouvelle_priorite, ['tres_urgent', 'urgent', 'simple'], true)) {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'Priorité invalide.', 'danger');
        }

        $pdo->prepare(
            'UPDATE courriers SET priorite = :priorite, priorite_modifiee_par = :modifie_par, retard_notifie = FALSE WHERE id = :id'
        )->execute(['priorite' => $nouvelle_priorite, 'modifie_par' => current_user_id(), 'id' => $courrier_id]);

        log_action($pdo, $courrier_id, 'changement_priorite', $courrier['priorite'], $nouvelle_priorite);
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Priorité mise à jour (nouvelle échéance recalculée).');
        break;

    // ---------------- Admin : réaffecter la ventilation (destinataires + pilote) ----------------
    case 'reassigner':
        if (!can_reassign()) {
            http_response_code(403);
            die('Action réservée à l\'administrateur.');
        }
        $nouveaux  = array_values(array_unique(array_map('intval', $_POST['destinataires'] ?? [])));
        $pilote_id = (int) ($_POST['pilote_id'] ?? 0);

        if (empty($nouveaux)) {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'Sélectionnez au moins un destinataire.', 'danger');
        }
        if (count($nouveaux) === 1) {
            $pilote_id = $nouveaux[0];
        } elseif (!in_array($pilote_id, $nouveaux, true)) {
            $pilote_id = $nouveaux[0];
        }

        $stmt = $pdo->prepare('SELECT employe_id FROM courrier_destinataires WHERE courrier_id = :cid');
        $stmt->execute(['cid' => $courrier_id]);
        $anciens = array_map('intval', array_column($stmt->fetchAll(), 'employe_id'));

        $a_ajouter   = array_diff($nouveaux, $anciens);
        $a_supprimer = array_diff($anciens, $nouveaux);

        $pdo->beginTransaction();

        $stmt_add = $pdo->prepare('INSERT INTO courrier_destinataires (courrier_id, employe_id, est_pilote) VALUES (:cid, :eid, :pilote::boolean)');
        foreach ($a_ajouter as $eid) {
            $stmt_add->execute(['cid' => $courrier_id, 'eid' => $eid, 'pilote' => ($eid === $pilote_id) ? 'true' : 'false']);
        }
        if ($a_supprimer) {
            $in = implode(',', array_fill(0, count($a_supprimer), '?'));
            $stmt_del = $pdo->prepare("DELETE FROM courrier_destinataires WHERE courrier_id = ? AND employe_id IN ({$in})");
            $stmt_del->execute(array_merge([$courrier_id], array_values($a_supprimer)));
        }
        // Met à jour le pilote sur l'ensemble des destinataires restants/ajoutés
        $pdo->prepare('UPDATE courrier_destinataires SET est_pilote = FALSE WHERE courrier_id = :cid')
            ->execute(['cid' => $courrier_id]);
        $pdo->prepare('UPDATE courrier_destinataires SET est_pilote = TRUE WHERE courrier_id = :cid AND employe_id = :eid')
            ->execute(['cid' => $courrier_id, 'eid' => $pilote_id]);

        $pdo->commit();

        log_action($pdo, $courrier_id, 'reassignation', implode(',', $anciens), implode(',', $nouveaux));
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Ventilation mise à jour.');
        break;

    // ---------------- Modifier les informations du courrier (formulaire complet) ----------------
    case 'modifier':
        if (!can_edit_courrier($courrier)) {
            http_response_code(403);
            die('Vous n\'avez pas les droits pour modifier ce courrier.');
        }
        $reference      = trim($_POST['reference'] ?? $courrier['reference']);
        $objet          = trim($_POST['objet'] ?? '');
        $priorite_recue = $_POST['priorite'] ?? $courrier['priorite'];
        $date_courrier  = $_POST['date_courrier'] ?: null;
        $date_reception = $_POST['date_reception'] ?: null;
        $observations   = trim($_POST['observations'] ?? '');

        if ($objet === '') {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'L\'objet ne peut pas être vide.', 'danger');
        }
        if ($reference === '') {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'La référence ne peut pas être vide.', 'danger');
        }

        // "Pas de réponse" est une option de commodité dans le menu priorité :
        // elle bascule reponse_requise à FALSE et garde une priorité technique par défaut.
        if ($priorite_recue === 'pas_de_reponse') {
            $nouvelle_priorite = $courrier['priorite'] === 'pas_de_reponse' ? 'simple' : $courrier['priorite'];
            $reponse_requise   = 'false';
        } else {
            $nouvelle_priorite = in_array($priorite_recue, ['tres_urgent', 'urgent', 'simple'], true) ? $priorite_recue : $courrier['priorite'];
            $reponse_requise   = 'true';
        }

        // Référence en doublon ?
        if ($reference !== $courrier['reference']) {
            $stmt = $pdo->prepare('SELECT 1 FROM courriers WHERE reference = :ref AND id != :id');
            $stmt->execute(['ref' => $reference, 'id' => $courrier_id]);
            if ($stmt->fetchColumn()) {
                redirect_with_message("courrier_view.php?id={$courrier_id}", 'Cette référence est déjà utilisée par un autre courrier.', 'danger');
            }
        }

        $pdo->prepare(
            'UPDATE courriers SET reference = :reference, objet = :objet, priorite = :priorite,
             reponse_requise = :reponse_requise::boolean, date_courrier = :date_courrier,
             date_reception = :date_reception, observations = :observations, retard_notifie = FALSE WHERE id = :id'
        )->execute([
            'reference' => $reference, 'objet' => $objet, 'priorite' => $nouvelle_priorite,
            'reponse_requise' => $reponse_requise, 'date_courrier' => $date_courrier, 'date_reception' => $date_reception,
            'observations' => $observations ?: null, 'id' => $courrier_id,
        ]);

        log_action($pdo, $courrier_id, 'modification', null, null, 'Informations du courrier modifiées.');
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Courrier mis à jour avec succès.');
        break;

    // ---------------- Secrétariat/admin : corriger une réponse déjà enregistrée ----------------
    case 'modifier_reponse':
        if (!can_add_reponse()) {
            http_response_code(403);
            die('Action non autorisée.');
        }
        $stmt = $pdo->prepare('SELECT * FROM reponses WHERE courrier_id = :id');
        $stmt->execute(['id' => $courrier_id]);
        $reponse = $stmt->fetch();
        if (!$reponse) {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'Aucune réponse à modifier pour ce courrier.', 'danger');
        }

        $numero_reference = trim($_POST['numero_reference'] ?? '');
        $date_envoi       = $_POST['date_envoi'] ?: $reponse['date_envoi'];
        $projet_lettre    = isset($_POST['projet_lettre']);
        $projet_message   = isset($_POST['projet_message']);

        if ($numero_reference === '') {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'Le numéro de référence de la réponse est obligatoire.', 'danger');
        }

        $pdo->prepare(
            'UPDATE reponses SET numero_reference = :ref, date_envoi = :denvoi, projet_lettre = :pl::boolean, projet_message = :pm::boolean WHERE id = :id'
        )->execute([
            'ref' => $numero_reference, 'denvoi' => $date_envoi,
            'pl' => $projet_lettre ? 'true' : 'false', 'pm' => $projet_message ? 'true' : 'false',
            'id' => $reponse['id'],
        ]);

        // Si projet lettre/message vient d'être coché et qu'aucun suivi C9 n'existe encore, on le crée.
        if ($projet_lettre || $projet_message) {
            $stmt = $pdo->prepare('SELECT id FROM c9 WHERE reponse_id = :rid');
            $stmt->execute(['rid' => $reponse['id']]);
            if (!$stmt->fetchColumn()) {
                $pdo->prepare('INSERT INTO c9 (reponse_id, statut, created_by) VALUES (:rid, :statut, :cb)')
                    ->execute(['rid' => $reponse['id'], 'statut' => 'en_attente', 'cb' => current_user_id()]);
            }
        }

        log_action($pdo, $courrier_id, 'modification_reponse', null, null, "Réponse corrigée : {$numero_reference}");
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Réponse mise à jour avec succès.');
        break;

    // ---------------- Secrétariat/admin : corriger un suivi C9 déjà renseigné ----------------
    case 'modifier_c9':
        if (!can_add_reponse()) {
            http_response_code(403);
            die('Action non autorisée.');
        }
        $c9_id = (int) ($_POST['c9_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT c9.* FROM c9 JOIN reponses r ON r.id = c9.reponse_id WHERE c9.id = :id AND r.courrier_id = :cid'
        );
        $stmt->execute(['id' => $c9_id, 'cid' => $courrier_id]);
        $c9 = $stmt->fetch();
        if (!$c9) {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'Suivi C9 introuvable.', 'danger');
        }

        $numero_reference = trim($_POST['numero_reference'] ?? '');
        $date_envoi       = $_POST['date_envoi'] ?: $c9['date_envoi'];
        $date_reception   = $_POST['date_reception'] ?: $c9['date_reception'];

        if ($numero_reference === '') {
            redirect_with_message("courrier_view.php?id={$courrier_id}", 'Le numéro de référence C9 est obligatoire.', 'danger');
        }

        $pdo->prepare(
            "UPDATE c9 SET numero_reference = :ref, date_envoi = :denvoi, date_reception = :drecep, statut = 'cloture' WHERE id = :id"
        )->execute(['ref' => $numero_reference, 'denvoi' => $date_envoi, 'drecep' => $date_reception, 'id' => $c9_id]);

        log_action($pdo, $courrier_id, 'modification_c9', null, null, "Suivi C9 corrigé : {$numero_reference}");
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Suivi C9 mis à jour avec succès.');
        break;

    // ---------------- Secrétariat/admin : archiver / désarchiver ----------------
    case 'archiver':
        if (!can_add_reponse()) {
            http_response_code(403);
            die('Action non autorisée.');
        }
        $pdo->prepare("UPDATE courriers SET statut = 'archive' WHERE id = :id")->execute(['id' => $courrier_id]);
        log_action($pdo, $courrier_id, 'archivage', $courrier['statut'], 'archive');
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Courrier archivé.');
        break;

    case 'desarchiver':
        if (!can_add_reponse()) {
            http_response_code(403);
            die('Action non autorisée.');
        }
        // On revient à un état cohérent avec la présence ou non d'une réponse
        $stmt = $pdo->prepare('SELECT id FROM reponses WHERE courrier_id = :id');
        $stmt->execute(['id' => $courrier_id]);
        $nouveau_statut = $stmt->fetchColumn() ? 'repondu' : 'en_cours';
        $pdo->prepare('UPDATE courriers SET statut = :statut WHERE id = :id')
            ->execute(['statut' => $nouveau_statut, 'id' => $courrier_id]);
        log_action($pdo, $courrier_id, 'desarchivage', 'archive', $nouveau_statut);
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Courrier désarchivé.');
        break;

    // ---------------- Secrétariat/admin : annuler une réponse enregistrée par erreur ----------------
    case 'annuler_reponse':
        if (!can_add_reponse()) {
            http_response_code(403);
            die('Action non autorisée.');
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM reponses WHERE courrier_id = :id')->execute(['id' => $courrier_id]);
        $pdo->prepare("UPDATE courriers SET statut = 'en_cours', retard_notifie = FALSE WHERE id = :id")->execute(['id' => $courrier_id]);
        $pdo->commit();
        log_action($pdo, $courrier_id, 'annulation_reponse', 'repondu', 'en_cours', 'Réponse annulée (erreur de saisie).');
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Réponse annulée. Le courrier est repassé en cours.');
        break;

    // ---------------- Secrétariat/admin : basculer l'obligation de réponse ----------------
    case 'toggle_reponse_requise':
        if (!can_add_reponse()) {
            http_response_code(403);
            die('Action non autorisée.');
        }
        $nouvelle_valeur = !$courrier['reponse_requise'];
        $pdo->prepare('UPDATE courriers SET reponse_requise = :v::boolean, retard_notifie = FALSE WHERE id = :id')
            ->execute(['v' => $nouvelle_valeur ? 'true' : 'false', 'id' => $courrier_id]);
        log_action(
            $pdo, $courrier_id, 'reponse_requise',
            $courrier['reponse_requise'] ? 'obligatoire' : 'non_obligatoire',
            $nouvelle_valeur ? 'obligatoire' : 'non_obligatoire',
            $nouvelle_valeur ? 'La réponse a été rendue de nouveau obligatoire.' : 'Le courrier a été marqué comme ne nécessitant pas de réponse.'
        );
        redirect_with_message(
            "courrier_view.php?id={$courrier_id}",
            $nouvelle_valeur ? 'La réponse est de nouveau obligatoire pour ce courrier.' : 'Ce courrier est désormais marqué "réponse non obligatoire".'
        );
        break;

    // ---------------- Administrateur : supprimer définitivement un courrier ----------------
    case 'supprimer':
        if (!is_admin()) {
            http_response_code(403);
            die('Action réservée à l\'administrateur.');
        }
        // Supprime les fichiers physiques liés avant de supprimer les lignes (cascade en base)
        $stmt = $pdo->prepare('SELECT chemin FROM pieces_jointes WHERE courrier_id = :id');
        $stmt->execute(['id' => $courrier_id]);
        foreach ($stmt->fetchAll() as $p) {
            $chemin_absolu = __DIR__ . '/' . $p['chemin'];
            if (is_file($chemin_absolu)) { @unlink($chemin_absolu); }
        }
        $stmt = $pdo->prepare(
            'SELECT chemin_fichier FROM reponses WHERE courrier_id = :id AND chemin_fichier IS NOT NULL'
        );
        $stmt->execute(['id' => $courrier_id]);
        foreach ($stmt->fetchAll() as $r) {
            $chemin_absolu = __DIR__ . '/' . $r['chemin_fichier'];
            if (is_file($chemin_absolu)) { @unlink($chemin_absolu); }
        }

        $reference = $courrier['reference'];
        audit($pdo, 'suppression_courrier', "Courrier {$reference} (objet : {$courrier['objet']}) supprimé définitivement.");
        $pdo->prepare('DELETE FROM courriers WHERE id = :id')->execute(['id' => $courrier_id]);

        redirect_with_message('courriers.php', "Courrier {$reference} supprimé définitivement.");
        break;

    default:
        redirect_with_message("courrier_view.php?id={$courrier_id}", 'Action inconnue.', 'danger');
}
