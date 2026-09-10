<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: calendrier.php');
    exit;
}

csrf_verify();

$action = $_POST['action'] ?? '';
$retour_mois = $_POST['retour_mois'] ?? date('Y-m');

switch ($action) {

    case 'creer':
        $titre          = trim($_POST['titre'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $date_evenement = $_POST['date_evenement'] ?? '';
        $heure          = $_POST['heure'] ?: null;
        $partage        = isset($_POST['partage']);

        if ($titre === '' || $date_evenement === '') {
            redirect_with_message("calendrier.php?mois={$retour_mois}", 'Le titre et la date sont obligatoires.', 'danger');
        }

        $pdo->prepare(
            'INSERT INTO evenements (titre, description, date_evenement, heure, partage, created_by)
             VALUES (:titre, :description, :date_evenement, :heure, :partage::boolean, :created_by)'
        )->execute([
            'titre'          => $titre,
            'description'    => $description ?: null,
            'date_evenement' => $date_evenement,
            'heure'          => $heure,
            'partage'        => $partage ? 'true' : 'false',
            'created_by'     => current_user_id(),
        ]);

        redirect_with_message("calendrier.php?mois={$retour_mois}", 'Événement ajouté avec succès.');
        break;

    case 'marquer_traite':
        $id = (int) ($_POST['id'] ?? 0);
        // On ne peut traiter que ses propres événements, ou un événement partagé
        $stmt = $pdo->prepare('SELECT * FROM evenements WHERE id = :id AND (created_by = :moi OR partage = TRUE)');
        $stmt->execute(['id' => $id, 'moi' => current_user_id()]);
        if ($stmt->fetch()) {
            $pdo->prepare("UPDATE evenements SET statut = 'traite' WHERE id = :id")->execute(['id' => $id]);
        }
        // Requête AJAX (depuis le toast) : réponse JSON courte
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        redirect_with_message("calendrier.php?mois={$retour_mois}", 'Événement marqué comme traité.');
        break;

    case 'supprimer':
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM evenements WHERE id = :id AND created_by = :moi')
            ->execute(['id' => $id, 'moi' => current_user_id()]);
        redirect_with_message("calendrier.php?mois={$retour_mois}", 'Événement supprimé.');
        break;

    default:
        redirect_with_message("calendrier.php?mois={$retour_mois}", 'Action inconnue.', 'danger');
}
