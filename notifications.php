<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Notifications';
$auto_refresh_seconds = 30;

// Tout marquer comme lu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tout_marquer_lu') {
    csrf_verify();
    $pdo->prepare('UPDATE notifications SET lu = TRUE WHERE employe_id = :id')->execute(['id' => current_user_id()]);
    redirect_with_message('notifications.php', 'Toutes les notifications ont été marquées comme lues.');
}

// ---------- Pagination ----------
$par_page = 30;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $par_page;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE employe_id = :id');
$stmt->execute(['id' => current_user_id()]);
$total_lignes = (int) $stmt->fetchColumn();
$total_pages  = max(1, (int) ceil($total_lignes / $par_page));

$stmt = $pdo->prepare(
    "SELECT n.*, c.reference, c.objet, e.nom AS decl_nom, e.prenom AS decl_prenom
     FROM notifications n
     LEFT JOIN courriers c ON c.id = n.courrier_id
     LEFT JOIN employes e ON e.id = n.declenche_par
     WHERE n.employe_id = :id
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue('id', current_user_id());
$stmt->bindValue('limit', $par_page, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Marque tout comme lu à la simple consultation de la page
$pdo->prepare('UPDATE notifications SET lu = TRUE WHERE employe_id = :id')->execute(['id' => current_user_id()]);

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-bell"></i> Notifications</h3>
    <span class="text-muted small"><?= $total_lignes ?> au total</span>
</div>

<div class="list-group">
    <?php foreach ($notifications as $n): ?>
        <a href="<?= $n['courrier_id'] ? 'courrier_view.php?id=' . $n['courrier_id'] : '#' ?>"
           class="list-group-item list-group-item-action <?= $n['lu'] ? '' : 'list-group-item-primary' ?>">
            <div class="d-flex justify-content-between">
                <div>
                    <i class="bi <?= [
                        'telechargement'      => 'bi-download',
                        'ouverture'            => 'bi-eye-fill',
                        'nouveau_courrier'     => 'bi-envelope-plus-fill',
                        'reponse_enregistree'  => 'bi-reply-fill',
                        'c9_cloture'           => 'bi-flag-fill',
                        'retard'               => 'bi-exclamation-triangle-fill',
                    ][$n['type']] ?? 'bi-bell-fill' ?>"></i>
                    <?= htmlspecialchars($n['message']) ?>
                </div>
                <span class="text-muted small"><?= htmlspecialchars(substr($n['created_at'], 0, 16)) ?></span>
            </div>
            <?php if ($n['reference']): ?>
                <div class="text-muted small"><?= htmlspecialchars($n['reference'] . ' — ' . $n['objet']) ?></div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (empty($notifications)): ?>
        <div class="etat-vide"><i class="bi bi-bell-slash"></i>Aucune notification pour le moment.</div>
    <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page - 1 ?>">&laquo; Précédent</a>
        </li>
        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $page + 1 ?>">Suivant &raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
