<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Tableau de bord';
$auto_refresh_seconds = 60;

// ---------- Filtres ----------
$f_priorite    = $_GET['priorite'] ?? '';
$f_ventilation = $_GET['ventilation'] ?? '';

/** Construit une URL de filtre en conservant les autres paramètres actifs. */
function url_filtre_accueil(array $override): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return 'accueil.php' . ($qs ? '?' . $qs : '');
}

$conditions = [];
$params     = [];
$join_dest  = '';

if (is_simple()) {
    $join_dest    = 'JOIN courrier_destinataires cd ON cd.courrier_id = c.id';
    $conditions[] = 'cd.employe_id = :moi';
    $params['moi'] = current_user_id();
} elseif ($f_ventilation !== '') {
    $join_dest    = 'JOIN courrier_destinataires cd ON cd.courrier_id = c.id';
    $conditions[] = 'cd.employe_id = :ventilation';
    $params['ventilation'] = $f_ventilation;
}

if ($f_priorite !== '') {
    $conditions[] = 'c.priorite = :priorite';
    $params['priorite'] = $f_priorite;
}

// ---------- Statistiques (respectent le filtre personne/priorité) ----------
$where_stats = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql_base = "FROM courriers c {$join_dest} LEFT JOIN reponses r ON r.courrier_id = c.id {$where_stats}";

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) {$sql_base}");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) {$sql_base}" . ($where_stats ? ' AND' : ' WHERE') . ' r.id IS NOT NULL');
$stmt->execute($params);
$repondus = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT c.id) {$sql_base}" . ($where_stats ? ' AND' : ' WHERE')
    . " r.id IS NULL AND c.statut != 'archive' AND c.reponse_requise = TRUE AND (c.date_limite IS NULL OR c.date_limite >= CURRENT_TIMESTAMP)"
);
$stmt->execute($params);
$en_cours = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT c.id) {$sql_base}" . ($where_stats ? ' AND' : ' WHERE')
    . " c.date_limite < CURRENT_TIMESTAMP AND r.id IS NULL AND c.statut != 'archive' AND c.reponse_requise = TRUE"
);
$stmt->execute($params);
$retard = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT c.id) {$sql_base}" . ($where_stats ? ' AND' : ' WHERE') . ' c.reponse_requise = FALSE'
);
$stmt->execute($params);
$sans_reponse = (int) $stmt->fetchColumn();

$comptes_simples = [];
if (!is_simple()) {
    $comptes_simples = $pdo->query(
        "SELECT id, nom, prenom FROM employes WHERE role = 'simple' AND actif = TRUE ORDER BY nom"
    )->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4"><i class="bi bi-speedometer2"></i> Tableau de bord</h3>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-primary p-3 text-start">
            <i class="bi bi-envelope-fill stat-icon text-primary"></i>
            <div class="display-6"><?= $total ?></div>
            <div class="text-muted small">Courriers <?= is_simple() ? 'reçus' : 'envoyés' ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-danger p-3 text-start">
            <i class="bi bi-exclamation-triangle-fill stat-icon text-danger"></i>
            <div class="display-6 text-danger"><?= $retard ?></div>
            <div class="text-muted small">En retard</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-primary p-3 text-start">
            <i class="bi bi-hourglass-split stat-icon text-primary"></i>
            <div class="display-6 text-primary"><?= $en_cours ?></div>
            <div class="text-muted small">En cours de traitement</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-success p-3 text-start">
            <i class="bi bi-check-circle-fill stat-icon text-success"></i>
            <div class="display-6 text-success"><?= $repondus ?></div>
            <div class="text-muted small">Répondus</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-neutral p-3 text-start">
            <i class="bi bi-slash-circle stat-icon text-secondary"></i>
            <div class="display-6 text-secondary"><?= $sans_reponse ?></div>
            <div class="text-muted small">Sans réponse requise</div>
        </div>
    </div>
</div>

<!-- ================= FILTRES RAPIDES (boutons) ================= -->
<div class="card p-3 mb-3">
    <div class="row g-3">
        <div class="col-12">
            <div class="small text-muted mb-1 fw-semibold">Priorité</div>
            <div class="filter-pills">
                <a href="<?= url_filtre_accueil(['priorite' => '']) ?>" class="filter-pill <?= $f_priorite === '' ? 'active' : '' ?>">Toutes</a>
                <a href="<?= url_filtre_accueil(['priorite' => 'tres_urgent']) ?>" class="filter-pill <?= $f_priorite === 'tres_urgent' ? 'active' : '' ?>"><?= libelle_priorite('tres_urgent') ?></a>
                <a href="<?= url_filtre_accueil(['priorite' => 'urgent']) ?>" class="filter-pill <?= $f_priorite === 'urgent' ? 'active' : '' ?>"><?= libelle_priorite('urgent') ?></a>
                <a href="<?= url_filtre_accueil(['priorite' => 'simple']) ?>" class="filter-pill <?= $f_priorite === 'simple' ? 'active' : '' ?>"><?= libelle_priorite('simple') ?></a>
            </div>
        </div>
        <?php if (!is_simple()): ?>
        <div class="col-12">
            <div class="small text-muted mb-1 fw-semibold">Personne (ventilation)</div>
            <div class="filter-pills align-items-center">
                <a href="<?= url_filtre_accueil(['ventilation' => '']) ?>" class="filter-pill <?= $f_ventilation === '' ? 'active' : '' ?>">Tout le monde</a>
                <div class="dropdown">
                    <button class="filter-pill dropdown-toggle <?= $f_ventilation !== '' ? 'active' : '' ?>" type="button" data-bs-toggle="dropdown">
                        <?php
                        $nom_personne = 'Choisir une personne';
                        foreach ($comptes_simples as $u) {
                            if ((string) $u['id'] === (string) $f_ventilation) {
                                $nom_personne = $u['prenom'] . ' ' . $u['nom'];
                            }
                        }
                        ?>
                        <i class="bi bi-person"></i> <?= htmlspecialchars($nom_personne) ?>
                    </button>
                    <ul class="dropdown-menu">
                        <?php foreach ($comptes_simples as $u): ?>
                            <li><a class="dropdown-item" href="<?= url_filtre_accueil(['ventilation' => $u['id']]) ?>"><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card p-4 text-center">
    <p class="text-muted mb-3">Retrouvez la liste complète et détaillée des courriers, avec recherche, tri et actions, sur la page dédiée.</p>
    <a href="courriers.php" class="btn btn-primary">
        <i class="bi bi-list-ul"></i> Voir la liste des courriers
    </a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
