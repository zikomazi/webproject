<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require_role(['administrateur']);
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Gestion des comptes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $employe_id = (int) ($_POST['employe_id'] ?? 0);
    $post_action = $_POST['action'] ?? '';

    if ($employe_id === (int) current_user_id()) {
        redirect_with_message('users.php', 'Vous ne pouvez pas modifier votre propre compte depuis cette action.', 'danger');
    }

    $stmt = $pdo->prepare('SELECT nom_utilisateur, nom, prenom FROM employes WHERE id = :id');
    $stmt->execute(['id' => $employe_id]);
    $cible = $stmt->fetch();

    if ($post_action === 'toggle_actif') {
        $pdo->prepare('UPDATE employes SET actif = NOT actif WHERE id = :id')->execute(['id' => $employe_id]);
        if ($cible) {
            audit($pdo, 'changement_statut_compte', "Statut du compte {$cible['nom_utilisateur']} ({$cible['prenom']} {$cible['nom']}) modifié (activé/désactivé).");
        }
        redirect_with_message('users.php', 'Statut du compte mis à jour.');
    }

    if ($post_action === 'supprimer') {
        // Vérifie l'absence de courriers liés (créés, ou destinataire)
        $stmt = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM courriers WHERE created_by = :id1) +
                (SELECT COUNT(*) FROM courrier_destinataires WHERE employe_id = :id2) AS total'
        );
        $stmt->execute(['id1' => $employe_id, 'id2' => $employe_id]);
        $total_lies = (int) $stmt->fetchColumn();

        if ($total_lies > 0) {
            redirect_with_message('users.php', 'Suppression impossible : ce compte a des courriers liés (envoyés ou reçus). Désactivez-le à la place.', 'danger');
        }

        if ($cible) {
            audit($pdo, 'suppression_compte', "Compte {$cible['nom_utilisateur']} ({$cible['prenom']} {$cible['nom']}) supprimé définitivement.");
        }
        $pdo->prepare('DELETE FROM employes WHERE id = :id')->execute(['id' => $employe_id]);
        redirect_with_message('users.php', 'Compte supprimé définitivement.');
    }
}

$employes = $pdo->query(
    "SELECT e.*,
        (SELECT COUNT(*) FROM courriers WHERE created_by = e.id) AS nb_envoyes,
        (SELECT COUNT(*) FROM courrier_destinataires WHERE employe_id = e.id) AS nb_recus
     FROM employes e
     ORDER BY e.role, e.nom, e.prenom"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3><i class="bi bi-people"></i> Comptes utilisateurs</h3>
    <a href="user_form.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nouveau compte</a>
</div>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small"><?= count($employes) ?> compte(s)</span>
        <input type="text" class="form-control form-control-sm recherche-instantanee" placeholder="Recherche instantanée..." data-recherche-table="#tableauComptes">
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tableauComptes">
            <thead class="table-light">
                <tr>
                    <th>Nom</th>
                    <th>Identifiant</th>
                    <th>Email</th>
                    <th>Rôle</th>
                    <th>Envoyés / Reçus</th>
                    <th>Statut</th>
                    <th>Dernière connexion</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($employes as $e): $lie = ((int) $e['nb_envoyes'] + (int) $e['nb_recus']) > 0; ?>
                <tr>
                    <td><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></td>
                    <td><?= htmlspecialchars($e['nom_utilisateur']) ?></td>
                    <td><?= htmlspecialchars($e['email'] ?? '-') ?></td>
                    <td><span class="badge bg-secondary"><?= libelle_role($e['role']) ?></span></td>
                    <td class="small"><?= $e['nb_envoyes'] ?> / <?= $e['nb_recus'] ?></td>
                    <td>
                        <?php if ($e['actif']): ?>
                            <span class="badge bg-success">Actif</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Désactivé</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= $e['derniere_connexion'] ? htmlspecialchars(substr($e['derniere_connexion'], 0, 16)) : '-' ?></td>
                    <td class="d-flex gap-2">
                        <a href="user_form.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil-square"></i></a>
                        <?php if ((int) $e['id'] !== (int) current_user_id()): ?>
                            <form method="post" data-confirmer="Confirmer le changement de statut de ce compte ?">
            <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_actif">
                                <input type="hidden" name="employe_id" value="<?= $e['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit" title="Activer / Désactiver">
                                    <i class="bi bi-power"></i>
                                </button>
                            </form>
                            <?php if ($lie): ?>
                                <button class="btn btn-sm btn-outline-danger" type="button" disabled
                                        title="Suppression impossible : ce compte a des courriers liés">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                                <form method="post" data-confirmer="Supprimer définitivement ce compte ? Cette action est irréversible.">
            <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="employe_id" value="<?= $e['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
