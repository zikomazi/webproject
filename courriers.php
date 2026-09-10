<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Courriers';
$auto_refresh_seconds = 60;

// ---------- Filtres ----------
$f_statut       = $_GET['statut'] ?? '';
$f_priorites    = array_values(array_intersect((array) ($_GET['priorite'] ?? []), ['tres_urgent', 'urgent', 'simple']));
$f_recherche    = trim($_GET['q'] ?? '');
$f_ventilation  = $_GET['ventilation'] ?? '';
$f_pilote_only  = isset($_GET['pilote_only']);
$f_dc_debut     = $_GET['dc_debut'] ?? '';
$f_dc_fin       = $_GET['dc_fin'] ?? '';
$f_dr_debut     = $_GET['dr_debut'] ?? '';
$f_dr_fin       = $_GET['dr_fin'] ?? '';

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
    if ($f_pilote_only) {
        $conditions[] = 'cd.est_pilote = TRUE';
    }
}
if ($f_statut === 'retard') {
    $conditions[] = "c.date_limite < CURRENT_TIMESTAMP AND r.id IS NULL AND c.statut != 'archive' AND c.reponse_requise = TRUE";
} elseif ($f_statut === 'en_cours') {
    $conditions[] = "r.id IS NULL AND c.statut != 'archive' AND c.reponse_requise = TRUE AND (c.date_limite IS NULL OR c.date_limite >= CURRENT_TIMESTAMP)";
} elseif ($f_statut === 'repondu') {
    $conditions[] = 'r.id IS NOT NULL';
} elseif ($f_statut === 'sans_reponse') {
    $conditions[] = "r.id IS NULL AND c.statut != 'archive' AND c.reponse_requise = FALSE";
} elseif ($f_statut === 'archive') {
    $conditions[] = "c.statut = 'archive'";
}
if (!empty($f_priorites)) {
    $placeholders = [];
    foreach ($f_priorites as $i => $p) {
        $cle = "priorite{$i}";
        $placeholders[] = ":{$cle}";
        $params[$cle] = $p;
    }
    $conditions[] = 'c.priorite IN (' . implode(',', $placeholders) . ')';
}
if ($f_recherche !== '') {
    $conditions[] = '(c.reference ILIKE :q OR c.objet ILIKE :q)';
    $params['q'] = "%{$f_recherche}%";
}
if ($f_dc_debut !== '') {
    $conditions[] = 'c.date_courrier >= :dc_debut';
    $params['dc_debut'] = $f_dc_debut;
}
if ($f_dc_fin !== '') {
    $conditions[] = 'c.date_courrier <= :dc_fin';
    $params['dc_fin'] = $f_dc_fin;
}
if ($f_dr_debut !== '') {
    $conditions[] = 'c.date_reception >= :dr_debut';
    $params['dr_debut'] = $f_dr_debut;
}
if ($f_dr_fin !== '') {
    $conditions[] = 'c.date_reception <= :dr_fin';
    $params['dr_fin'] = $f_dr_fin;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ---------- Tri ----------
$colonnes_tri = [
    'reference'      => 'c.reference',
    'objet'          => 'c.objet',
    'date_courrier'  => 'c.date_courrier',
    'date_reception' => 'c.date_reception',
    'date_envoi'     => 'c.date_envoi',
];
$f_tri  = $_GET['tri'] ?? 'date_envoi';
$f_ordre = (($_GET['ordre'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
if (!isset($colonnes_tri[$f_tri])) {
    $f_tri = 'date_envoi';
}
$order_by = $colonnes_tri[$f_tri] . ' ' . $f_ordre . ', c.id ' . $f_ordre;

// ---------- Pagination ----------
$par_page = 25;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $par_page;

$sql_count = "SELECT COUNT(DISTINCT c.id)
              FROM courriers c
              {$join_dest}
              LEFT JOIN reponses r ON r.courrier_id = c.id
              LEFT JOIN c9 ON c9.reponse_id = r.id
              {$where}";
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params);
$total_lignes = (int) $stmt->fetchColumn();
$total_pages  = max(1, (int) ceil($total_lignes / $par_page));
$page         = min($page, $total_pages);
$offset       = ($page - 1) * $par_page;

$sql = "SELECT DISTINCT c.*,
            r.id AS reponse_id, r.numero_reference AS reponse_reference, r.date_envoi AS reponse_date_envoi,
            r.projet_lettre, r.projet_message,
            c9.id AS c9_id, c9.statut AS c9_statut, c9.numero_reference AS c9_reference,
            (SELECT pj.id FROM pieces_jointes pj WHERE pj.courrier_id = c.id ORDER BY pj.created_at DESC LIMIT 1) AS piece_jointe_id
        FROM courriers c
        {$join_dest}
        LEFT JOIN reponses r ON r.courrier_id = c.id
        LEFT JOIN c9 ON c9.reponse_id = r.id
        {$where}
        ORDER BY {$order_by}
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue('limit', $par_page, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$courriers = $stmt->fetchAll();

$ventilations = charger_ventilations($pdo, array_column($courriers, 'id'));

// Listes pour les filtres / popups
$comptes_simples = $pdo->query(
    "SELECT id, nom, prenom FROM employes WHERE role = 'simple' AND actif = TRUE ORDER BY nom"
)->fetchAll();

/** Construit une URL de filtre en conservant les autres paramètres actifs. */
function url_filtre(array $override): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return 'courriers.php' . ($qs ? '?' . $qs : '');
}

/** Lien d'en-tête de colonne triable, avec indicateur de sens actif. */
function lien_tri(string $colonne, string $libelle): string
{
    global $f_tri, $f_ordre;
    $nouvel_ordre = ($f_tri === $colonne && $f_ordre === 'ASC') ? 'desc' : 'asc';
    $icone = '';
    if ($f_tri === $colonne) {
        $icone = $f_ordre === 'ASC' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
    }
    $url = htmlspecialchars(url_filtre(['tri' => $colonne, 'ordre' => $nouvel_ordre]));
    return "<a href=\"{$url}\" class=\"text-reset text-decoration-none\">{$libelle}{$icone}</a>";
}

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3><i class="bi bi-list-ul"></i> Courriers</h3>
    <?php if (can_send_courrier()): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouveauCourrier">
            <i class="bi bi-send"></i> Nouveau courrier
        </button>
    <?php endif; ?>
</div>

<!-- ================= FILTRES (barre compacte) ================= -->
<form method="get" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Référence / objet</label>
            <input type="text" name="q" class="form-control" placeholder="Recherche libre..." value="<?= htmlspecialchars($f_recherche) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1">Statut</label>
            <select name="statut" class="form-select" onchange="this.form.submit()">
                <option value="" <?= $f_statut === '' ? 'selected' : '' ?>>Tous</option>
                <option value="en_cours" <?= $f_statut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                <option value="retard" <?= $f_statut === 'retard' ? 'selected' : '' ?>>En retard</option>
                <option value="repondu" <?= $f_statut === 'repondu' ? 'selected' : '' ?>>Répondu</option>
                <option value="sans_reponse" <?= $f_statut === 'sans_reponse' ? 'selected' : '' ?>>Sans réponse requise</option>
                <option value="archive" <?= $f_statut === 'archive' ? 'selected' : '' ?>>Archivé</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1">Priorité</label>
            <div class="dropdown">
                <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                    <?php
                    $noms_prio = array_map('libelle_priorite', $f_priorites);
                    echo $noms_prio ? htmlspecialchars(implode(', ', $noms_prio)) : 'Toutes';
                    ?>
                </button>
                <ul class="dropdown-menu p-2" style="min-width:230px;">
                    <?php foreach (['tres_urgent', 'urgent', 'simple'] as $p): ?>
                        <li class="form-check px-3">
                            <input class="form-check-input" type="checkbox" name="priorite[]" value="<?= $p ?>" id="fp_<?= $p ?>" <?= in_array($p, $f_priorites, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="fp_<?= $p ?>"><?= libelle_priorite($p) ?></label>
                        </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li class="px-3"><button type="submit" class="btn btn-sm btn-primary w-100">Appliquer</button></li>
                </ul>
            </div>
        </div>
        <?php if (!is_simple()): ?>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Personne (ventilation)</label>
            <div class="dropdown">
                <button class="form-select text-start dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <?php
                    $nom_personne = 'Tout le monde';
                    foreach ($comptes_simples as $u) {
                        if ((string) $u['id'] === (string) $f_ventilation) {
                            $nom_personne = $u['prenom'] . ' ' . $u['nom'];
                        }
                    }
                    ?>
                    <i class="bi bi-person"></i> <?= htmlspecialchars($nom_personne) ?>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?= url_filtre(['ventilation' => '', 'pilote_only' => '']) ?>">Tout le monde</a></li>
                    <?php foreach ($comptes_simples as $u): ?>
                        <li><a class="dropdown-item" href="<?= url_filtre(['ventilation' => $u['id']]) ?>"><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-md-2">
            <?php if ($f_ventilation !== ''): ?>
                <label class="form-label small text-muted mb-1 d-block">&nbsp;</label>
                <a href="<?= url_filtre(['pilote_only' => $f_pilote_only ? '' : '1']) ?>" class="filter-pill <?= $f_pilote_only ? 'active' : '' ?> d-inline-block">
                    <i class="bi bi-flag"></i> Pilote uniquement
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-2 align-items-end mt-1">
        <?php if ($f_ventilation !== ''): ?><input type="hidden" name="ventilation" value="<?= htmlspecialchars($f_ventilation) ?>"><?php endif; ?>
        <?php if ($f_pilote_only): ?><input type="hidden" name="pilote_only" value="1"><?php endif; ?>
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Date courrier — intervalle</label>
            <div class="input-group">
                <input type="date" name="dc_debut" class="form-control" value="<?= htmlspecialchars($f_dc_debut) ?>">
                <span class="input-group-text">→</span>
                <input type="date" name="dc_fin" class="form-control" value="<?= htmlspecialchars($f_dc_fin) ?>">
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Date réception — intervalle</label>
            <div class="input-group">
                <input type="date" name="dr_debut" class="form-control" value="<?= htmlspecialchars($f_dr_debut) ?>">
                <span class="input-group-text">→</span>
                <input type="date" name="dr_fin" class="form-control" value="<?= htmlspecialchars($f_dr_fin) ?>">
            </div>
        </div>
        <div class="col-md-4 d-flex justify-content-end gap-2">
            <a href="courriers.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Réinitialiser</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Filtrer</button>
        </div>
    </div>
</form>

<!-- ================= TABLEAU ================= -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small"><?= count($courriers) ?> courrier(s) affiché(s)</span>
        <input type="text" class="form-control form-control-sm recherche-instantanee" placeholder="Recherche instantanée dans le tableau..." data-recherche-table="#tableauCourriers">
        <a href="courriers_export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-spreadsheet"></i> Exporter CSV
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tableauCourriers">
            <thead class="table-light">
                <tr>
                    <th><?= lien_tri('reference', 'N° Référence') ?></th>
                    <th><?= lien_tri('objet', 'Objet') ?></th>
                    <th><?= lien_tri('date_courrier', 'Date courrier') ?></th>
                    <th><?= lien_tri('date_reception', 'Date réception') ?></th>
                    <th>Priorité</th>
                    <th>Statut</th>
                    <?php if (!is_simple()): ?><th>Ventilation</th><?php endif; ?>
                    <th>Réponse</th>
                    <th>C9</th>
                    <?php if (!is_simple()): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($courriers as $c):
                $a_reponse      = !empty($c['reponse_id']);
                $statut_affiche = statut_affichage($c, $a_reponse);
            ?>
                <tr class="<?= classe_ligne_statut($statut_affiche) ?>">
                    <td>
                        <a href="courrier_view.php?id=<?= $c['id'] ?>" class="fw-semibold text-reset"><?= htmlspecialchars($c['reference']) ?></a>
                        <div class="small mt-1">
                            <?php if ($c['piece_jointe_id']): ?>
                                <a href="#" class="text-muted me-2" onclick="event.stopPropagation(); ouvrirApercu('download.php?id=<?= $c['piece_jointe_id'] ?>&courrier_id=<?= $c['id'] ?>&mode=consulter', 'Courrier <?= htmlspecialchars($c['reference'], ENT_QUOTES) ?>'); return false;">
                                    <i class="bi bi-file-earmark-text"></i> Courrier <i class="bi bi-eye"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($c['reponse_id']): ?>
                                <a href="#" class="text-muted me-2" onclick="event.stopPropagation(); ouvrirApercu('reponse_fichier.php?courrier_id=<?= $c['id'] ?>&mode=consulter', 'Réponse <?= htmlspecialchars($c['reference'], ENT_QUOTES) ?>'); return false;">
                                    <i class="bi bi-reply"></i> Réponse <i class="bi bi-eye"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($c['c9_id'] && $c['c9_statut'] === 'cloture'): ?>
                                <a href="#" class="text-muted" onclick="event.stopPropagation(); ouvrirApercu('c9_fichier.php?id=<?= $c['c9_id'] ?>&mode=consulter', 'C9 <?= htmlspecialchars($c['reference'], ENT_QUOTES) ?>'); return false;">
                                    <i class="bi bi-flag"></i> C9 <i class="bi bi-eye"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($c['objet']) ?></td>
                    <td class="small"><?= $c['date_courrier'] ? htmlspecialchars($c['date_courrier']) : '-' ?></td>
                    <td class="small"><?= $c['date_reception'] ? htmlspecialchars($c['date_reception']) : '-' ?></td>
                    <td>
                        <?php if (!$c['reponse_requise']): ?>
                            <span class="text-muted">-</span>
                        <?php else: ?>
                            <span class="badge <?= classe_priorite($c['priorite']) ?>"><?= libelle_priorite($c['priorite']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-statut-<?= $statut_affiche ?>"><?= libelle_statut($statut_affiche) ?></span></td>
                    <?php if (!is_simple()): ?>
                        <td class="small"><?= formater_ventilation($ventilations[$c['id']] ?? []) ?></td>
                    <?php endif; ?>

                    <!-- Colonne Réponse (informative — l'édition se fait via le bouton Modifier) -->
                    <td>
                        <?php if (!$c['reponse_requise']): ?>
                            <span class="text-muted small">-</span>
                        <?php elseif ($c['reponse_id']): ?>
                            <span class="badge bg-success"><?= htmlspecialchars($c['reponse_reference']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">En attente</span>
                        <?php endif; ?>
                    </td>

                    <!-- Colonne C9 (informative — l'édition se fait via le bouton Modifier) -->
                    <td>
                        <?php if (!$c['reponse_requise']): ?>
                            <span class="text-muted small">-</span>
                        <?php elseif ($c['reponse_id'] && ($c['projet_lettre'] || $c['projet_message'])): ?>
                            <?php if ($c['c9_statut'] === 'cloture'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($c['c9_reference'] ?: 'Clôturé') ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-exclamation-circle"></i> En attente</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>

                    <?php if (!is_simple()): ?>
                    <td>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" title="Modifier"
                                    onclick="ouvrirActionsRapides(<?= $c['id'] ?>, '<?= htmlspecialchars($c['reference'], ENT_QUOTES) ?>')">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php if (is_admin()): ?>
                                <form method="post" action="courrier_actions.php" data-confirmer="Supprimer définitivement le courrier <?= htmlspecialchars($c['reference'], ENT_QUOTES) ?>, sa ventilation, ses pièces jointes et son historique ? Cette action est IRRÉVERSIBLE.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="courrier_id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="action" value="supprimer">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($courriers)): ?>
                <tr><td colspan="<?= is_simple() ? 8 : 10 ?>" class="p-0">
                    <div class="etat-vide"><i class="bi bi-inbox"></i>Aucun courrier ne correspond à ces critères.</div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<nav class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="text-muted small"><?= $total_lignes ?> résultat(s) — page <?= $page ?> / <?= $total_pages ?></span>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= url_filtre(['page' => $page - 1]) ?>">&laquo; Précédent</a>
        </li>
        <?php
        $debut = max(1, $page - 2);
        $fin   = min($total_pages, $page + 2);
        for ($p = $debut; $p <= $fin; $p++):
        ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= url_filtre(['page' => $p]) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= url_filtre(['page' => $page + 1]) ?>">Suivant &raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php if (can_send_courrier()): ?>
<!-- ================= POPUP : Nouveau courrier ================= -->
<div class="modal fade" id="modalNouveauCourrier" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="courrier_creer_action.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-send"></i> Envoyer un nouveau courrier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">N° de référence *</label>
                    <input type="text" name="reference" class="form-control" required placeholder="Saisie libre, ex: ARR-2026-014">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date du courrier</label>
                    <input type="date" name="date_courrier" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date de réception</label>
                    <input type="date" name="date_reception" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Priorité *</label>
                    <select name="priorite" id="champPrioriteCreation" class="form-select" required>
                        <option value="tres_urgent">Très urgent — délai max 6 heures</option>
                        <option value="urgent">Urgent — délai max 12 heures</option>
                        <option value="simple" selected>Routine — délai max 5 jours</option>
                        <option value="pas_de_reponse">Pas de réponse — courrier informatif, sans suivi de retard</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Pièce jointe (courrier scanné) *</label>

                    <ul class="nav nav-pills nav-sm mb-2" id="ongletsPieceJointe">
                        <li class="nav-item">
                            <button class="nav-link active" type="button" data-onglet="upload">
                                <i class="bi bi-upload"></i> Uploader un fichier
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" type="button" data-onglet="scan">
                                <i class="bi bi-printer"></i> Choisir un scan récent
                            </button>
                        </li>
                    </ul>

                    <div id="ongletUpload">
                        <input type="file" name="fichier" id="champFichier" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    </div>
                    <div id="ongletScan" style="display:none;">
                        <div class="border rounded p-2" id="listeScans" style="max-height:200px; overflow-y:auto;">
                            <p class="text-muted small mb-0 px-2 py-3 text-center">Chargement des scans récents...</p>
                        </div>
                        <div class="form-text">
                            Fichiers déposés par le logiciel du scanner réseau dans le dossier partagé du serveur.
                        </div>
                        <input type="hidden" name="scan_source" id="champScanSource" value="">
                    </div>

                    <div id="ocrStatus" class="form-text"></div>
                </div>
                <div class="col-12">
                    <label class="form-label">Objet *</label>
                    <input type="text" name="objet" maxlength="500" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Ventilation (destinataires) * — cochez, puis désignez le pilote</label>
                    <div class="border rounded p-2" style="max-height:240px; overflow-y:auto;">
                        <div class="row small text-muted px-2 mb-1">
                            <div class="col-8">Agent</div>
                            <div class="col-4">Pilote</div>
                        </div>
                        <?php foreach ($comptes_simples as $u): ?>
                            <div class="row align-items-center px-2 py-1">
                                <div class="col-8 form-check">
                                    <input class="form-check-input dest-checkbox" type="checkbox" name="destinataires[]" value="<?= $u['id'] ?>" id="dest_<?= $u['id'] ?>">
                                    <label class="form-check-label" for="dest_<?= $u['id'] ?>">
                                        <?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?>
                                    </label>
                                </div>
                                <div class="col-4 form-check">
                                    <input class="form-check-input pilote-radio" type="radio" name="pilote_id" value="<?= $u['id'] ?>" id="pilote_<?= $u['id'] ?>">
                                    <label class="form-check-label small" for="pilote_<?= $u['id'] ?>">Pilote</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($comptes_simples)): ?>
                            <p class="text-muted mb-0">Aucun compte "agent" disponible. Créez-en un dans Comptes.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-text">S'il n'y a qu'un seul destinataire, il devient automatiquement pilote.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Observations</label>
                    <textarea name="observations" class="form-control" rows="2"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Envoyer le courrier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/vendor/tesseract/tesseract.min.js"></script>
<script>
(function () {
    var modal        = document.getElementById('modalNouveauCourrier');
    var champFichier = document.getElementById('champFichier');
    var champScan    = document.getElementById('champScanSource');
    var listeScans   = document.getElementById('listeScans');
    var ocrStatusEl  = document.getElementById('ocrStatus');
    var scansCharges = false;
    var ocrWorker    = null;

    function setOcrStatus(msg, spinner) {
        ocrStatusEl.innerHTML = spinner
            ? '<span class="spinner-border spinner-border-sm text-primary me-1"></span>' + msg
            : msg;
    }

    // ---------- Onglets Uploader / Scan récent ----------
    modal.querySelectorAll('#ongletsPieceJointe .nav-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            modal.querySelectorAll('#ongletsPieceJointe .nav-link').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var onglet = btn.getAttribute('data-onglet');
            document.getElementById('ongletUpload').style.display = onglet === 'upload' ? '' : 'none';
            document.getElementById('ongletScan').style.display   = onglet === 'scan' ? '' : 'none';
            if (onglet === 'upload') {
                champScan.value = '';
            } else {
                champFichier.value = '';
                if (!scansCharges) { chargerScans(); }
            }
        });
    });

    function chargerScans() {
        scansCharges = true;
        fetch('scans_list.php', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (fichiers) {
                if (!fichiers.length) {
                    listeScans.innerHTML = '<p class="text-muted small mb-0 px-2 py-3 text-center">Aucun scan récent trouvé dans le dossier partagé.</p>';
                    return;
                }
                listeScans.innerHTML = '';
                fichiers.forEach(function (f) {
                    var item = document.createElement('div');
                    item.className = 'form-check px-2 py-1';
                    var date = new Date(f.modifie * 1000).toLocaleString('fr-FR');
                    item.innerHTML =
                        '<input class="form-check-input" type="radio" name="_scan_radio" id="scan_' + btoa(f.nom).replace(/=/g,'') + '">' +
                        '<label class="form-check-label small d-flex justify-content-between w-100" style="cursor:pointer;">' +
                        '<span><i class="bi bi-file-earmark-' + (f.extension === 'pdf' ? 'pdf' : 'image') + '"></i> ' + f.nom + '</span>' +
                        '<span class="text-muted">' + date + '</span></label>';
                    item.querySelector('input').addEventListener('change', function () { selectionnerScan(f); });
                    item.querySelector('label').addEventListener('click', function () {
                        item.querySelector('input').checked = true;
                        selectionnerScan(f);
                    });
                    listeScans.appendChild(item);
                });
            })
            .catch(function () {
                listeScans.innerHTML = '<p class="text-danger small mb-0 px-2 py-3 text-center">Impossible de charger la liste des scans.</p>';
            });
    }

    function selectionnerScan(f) {
        champScan.value = f.nom;
        if (f.extension === 'pdf') {
            setOcrStatus('OCR non disponible pour les fichiers PDF — merci de vérifier les champs manuellement.');
            return;
        }
        setOcrStatus('Chargement du scan pour analyse...', true);
        fetch('scan_fichier.php?nom=' + encodeURIComponent(f.nom))
            .then(function (r) { return r.blob(); })
            .then(function (blob) { lancerOcr(blob); })
            .catch(function () { setOcrStatus('Impossible de charger ce fichier pour analyse.'); });
    }

    // ---------- OCR automatique à la sélection d'un fichier uploadé ----------
    champFichier.addEventListener('change', function () {
        var fichier = champFichier.files[0];
        if (!fichier) return;
        var extension = fichier.name.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png'].indexOf(extension) === -1) {
            setOcrStatus(extension === 'pdf' ? 'OCR non disponible pour les fichiers PDF — merci de vérifier les champs manuellement.' : '');
            return;
        }
        lancerOcr(fichier);
    });

    function lancerOcr(source) {
        setOcrStatus('Analyse OCR en cours (pré-remplissage des champs)...', true);
        obtenirWorker()
            .then(function (worker) { return worker.recognize(source); })
            .then(function (resultat) {
                appliquerResultatsOcr(resultat.data.text || '');
                setOcrStatus('<i class="bi bi-check-circle text-success"></i> Analyse OCR terminée — merci de vérifier les champs pré-remplis.');
            })
            .catch(function () {
                setOcrStatus('OCR indisponible pour ce fichier (image de mauvaise qualité ou format non supporté).');
            });
    }

    function obtenirWorker() {
        if (ocrWorker) { return Promise.resolve(ocrWorker); }
        return Tesseract.createWorker('fra', 1, {
            workerPath: 'assets/vendor/tesseract/worker.min.js',
            corePath: 'assets/vendor/tesseract/tesseract-core-lstm.wasm.js',
            langPath: 'assets/vendor/tesseract/lang',
            gzip: true,
        }).then(function (w) { ocrWorker = w; return w; });
    }

    function appliquerResultatsOcr(texte) {
        var champObjet = modal.querySelector('[name="objet"]');
        var champRef   = modal.querySelector('[name="reference"]');
        var champDate  = modal.querySelector('[name="date_courrier"]');

        // Référence : cherche "N°"/"Réf" suivi d'un code, sinon un motif type ARR-2026-014
        if (champRef && !champRef.value) {
            var mRef = texte.match(/(?:N°|Ref\.?|Référence)\s*[:\-]?\s*([A-Z0-9\-\/]{3,20})/i)
                    || texte.match(/\b([A-Z]{2,6}[\-\/][0-9]{2,4}[\-\/][0-9]{2,6})\b/);
            if (mRef) { champRef.value = mRef[1].trim(); }
        }

        // Date : format JJ/MM/AAAA, JJ-MM-AAAA ou JJ.MM.AAAA
        if (champDate && !champDate.value) {
            var mDate = texte.match(/\b(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})\b/);
            if (mDate) {
                var jj = mDate[1].padStart(2, '0'), mm = mDate[2].padStart(2, '0'), aaaa = mDate[3];
                if (mm <= 12 && jj <= 31) { champDate.value = aaaa + '-' + mm + '-' + jj; }
            }
        }

        // Objet : première ligne suffisamment longue pour être significative
        if (champObjet && !champObjet.value) {
            var lignes = texte.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l.length > 8; });
            if (lignes.length) { champObjet.value = lignes[0].substring(0, 500); }
        }
    }

    // Empêche l'envoi si aucune pièce jointe n'a été fournie (upload ou scan)
    modal.querySelector('form').addEventListener('submit', function (e) {
        if (!champFichier.files[0] && !champScan.value) {
            e.preventDefault();
            setOcrStatus('<span class="text-danger">Merci de fournir une pièce jointe (upload ou scan récent) avant d\'envoyer.</span>');
        }
    });
})();
</script>
<?php endif; ?>

<script>
// Ne permet de cocher "pilote" que si le destinataire correspondant est sélectionné
document.querySelectorAll('.dest-checkbox').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var radio = document.getElementById('pilote_' + this.value);
        if (!this.checked && radio) {
            radio.checked = false;
        }
    });
});
document.querySelectorAll('.pilote-radio').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var cb = document.getElementById('dest_' + this.value);
        if (cb) { cb.checked = true; }
    });
});
</script>

<!-- ================= POPUP : Aperçu d'un document (courrier/réponse/C9) ================= -->
<div class="modal fade" id="modalApercu" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="height: 85vh;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye"></i> <span id="apercuNomFichier"></span></h5>
        <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="imprimerApercu()">
            <i class="bi bi-printer"></i> Imprimer
        </button>
        <a id="apercuTelechargerLien" href="#" class="btn btn-sm btn-outline-secondary me-2">
            <i class="bi bi-download"></i> Télécharger
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="apercuFrame" src="" style="width:100%; height:100%; border:0;"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ================= POPUP : Actions rapides (modifier / supprimer) ================= -->
<div class="modal fade" id="modalActionsRapides" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-lightning-charge"></i> <span id="actionsRapidesRef"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="actionsRapidesCorps">
        <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
      </div>
    </div>
  </div>
</div>

<script>
function ouvrirApercu(url, nomFichier) {
    document.getElementById('apercuFrame').src = url;
    document.getElementById('apercuNomFichier').textContent = nomFichier;
    document.getElementById('apercuTelechargerLien').href = url.includes('mode=consulter')
        ? url.replace('mode=consulter', 'mode=telecharger')
        : url;
    new bootstrap.Modal(document.getElementById('modalApercu')).show();
}
function imprimerApercu() {
    var frame = document.getElementById('apercuFrame');
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch (e) { window.open(frame.src, '_blank'); }
}
document.getElementById('modalApercu').addEventListener('hidden.bs.modal', function () {
    document.getElementById('apercuFrame').src = '';
});

function ouvrirActionsRapides(id, reference) {
    var corps = document.getElementById('actionsRapidesCorps');
    document.getElementById('actionsRapidesRef').textContent = reference;
    corps.innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
    var modal = new bootstrap.Modal(document.getElementById('modalActionsRapides'));
    modal.show();

    fetch('courrier_infos.php?id=' + id)
        .then(function (r) { return r.json(); })
        .then(function (info) {
            if (info.error) {
                corps.innerHTML = '<p class="text-danger">Accès refusé.</p>';
                return;
            }
            var echap = function (s) { return (s || '').replace(/"/g, '&quot;'); };
            var html = '';

            // ---------------- Informations générales ----------------
            if (info.peut_modifier) {
                var prioriteActuelle = info.reponse_requise ? info.priorite : 'pas_de_reponse';
                var options = [
                    ['tres_urgent', 'Très urgent — délai max 6 heures'],
                    ['urgent', 'Urgent — délai max 12 heures'],
                    ['simple', 'Routine — délai max 5 jours'],
                    ['pas_de_reponse', 'Pas de réponse — courrier informatif']
                ].map(function (o) {
                    return '<option value="' + o[0] + '"' + (o[0] === prioriteActuelle ? ' selected' : '') + '>' + o[1] + '</option>';
                }).join('');

                html += '<h6 class="mb-2"><i class="bi bi-info-circle"></i> Informations générales</h6>' +
                    '<form method="post" action="courrier_actions.php" class="mb-4 pb-3 border-bottom">' +
                    '<?= csrf_field() ?>' +
                    '<input type="hidden" name="courrier_id" value="' + info.id + '">' +
                    '<input type="hidden" name="action" value="modifier">' +
                    '<div class="mb-2"><label class="form-label small">N° de référence</label>' +
                    '<input type="text" name="reference" class="form-control" required value="' + echap(info.reference) + '"></div>' +
                    '<div class="mb-2"><label class="form-label small">Objet</label>' +
                    '<input type="text" name="objet" class="form-control" required maxlength="500" value="' + echap(info.objet) + '"></div>' +
                    '<div class="mb-2"><label class="form-label small">Priorité</label>' +
                    '<select name="priorite" class="form-select">' + options + '</select></div>' +
                    '<div class="row g-2 mb-2">' +
                    '<div class="col-6"><label class="form-label small">Date courrier</label><input type="date" name="date_courrier" class="form-control" value="' + (info.date_courrier || '') + '"></div>' +
                    '<div class="col-6"><label class="form-label small">Date réception</label><input type="date" name="date_reception" class="form-control" value="' + (info.date_reception || '') + '"></div>' +
                    '</div>' +
                    '<div class="mb-2"><label class="form-label small">Observations</label>' +
                    '<textarea name="observations" class="form-control" rows="2">' + (info.observations || '') + '</textarea></div>' +
                    '<button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> Enregistrer</button>' +
                    '</form>';
            }

            // ---------------- Réponse ----------------
            if (info.peut_gerer_reponse && info.reponse_requise) {
                if (info.reponse) {
                    html += '<h6 class="mb-2"><i class="bi bi-reply-fill"></i> Réponse</h6>' +
                        '<form method="post" action="courrier_actions.php" class="mb-4 pb-3 border-bottom">' +
                        '<?= csrf_field() ?>' +
                        '<input type="hidden" name="courrier_id" value="' + info.id + '">' +
                        '<input type="hidden" name="action" value="modifier_reponse">' +
                        '<div class="mb-2"><label class="form-label small">N° de référence de la réponse</label>' +
                        '<input type="text" name="numero_reference" class="form-control" required value="' + echap(info.reponse.numero_reference) + '"></div>' +
                        '<div class="mb-2"><label class="form-label small">Date d\'envoi</label>' +
                        '<input type="date" name="date_envoi" class="form-control" value="' + (info.reponse.date_envoi || '') + '"></div>' +
                        '<div class="form-check"><input class="form-check-input" type="checkbox" name="projet_lettre" value="1" id="fpl_' + info.id + '"' + (info.reponse.projet_lettre ? ' checked' : '') + '>' +
                        '<label class="form-check-label small" for="fpl_' + info.id + '">Projet lettre</label></div>' +
                        '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="projet_message" value="1" id="fpm_' + info.id + '"' + (info.reponse.projet_message ? ' checked' : '') + '>' +
                        '<label class="form-check-label small" for="fpm_' + info.id + '">Projet message</label></div>' +
                        '<button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-check-circle"></i> Enregistrer la réponse</button>' +
                        '</form>';
                } else {
                    html += '<h6 class="mb-2"><i class="bi bi-reply"></i> Réponse</h6>' +
                        '<form method="post" action="courrier_reponse_action.php" enctype="multipart/form-data" class="mb-4 pb-3 border-bottom">' +
                        '<?= csrf_field() ?>' +
                        '<input type="hidden" name="courrier_id" value="' + info.id + '">' +
                        '<div class="mb-2"><label class="form-label small">N° de référence de la réponse</label>' +
                        '<input type="text" name="numero_reference" class="form-control" required></div>' +
                        '<div class="mb-2"><label class="form-label small">Date d\'envoi</label>' +
                        '<input type="date" name="date_envoi" class="form-control" value="' + new Date().toISOString().slice(0, 10) + '"></div>' +
                        '<div class="mb-2"><label class="form-label small">Pièce jointe</label>' +
                        '<input type="file" name="fichier" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>' +
                        '<div class="form-check"><input class="form-check-input" type="checkbox" name="projet_lettre" value="1" id="npl_' + info.id + '">' +
                        '<label class="form-check-label small" for="npl_' + info.id + '">Projet lettre</label></div>' +
                        '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="projet_message" value="1" id="npm_' + info.id + '">' +
                        '<label class="form-check-label small" for="npm_' + info.id + '">Projet message</label></div>' +
                        '<button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> Ajouter la réponse</button>' +
                        '</form>';
                }
            }

            // ---------------- C9 ----------------
            if (info.peut_gerer_reponse && info.c9) {
                html += '<h6 class="mb-2"><i class="bi bi-flag-fill"></i> Suivi C9</h6>' +
                    '<form method="post" action="courrier_actions.php" class="mb-3">' +
                    '<?= csrf_field() ?>' +
                    '<input type="hidden" name="courrier_id" value="' + info.id + '">' +
                    '<input type="hidden" name="c9_id" value="' + info.c9.id + '">' +
                    '<input type="hidden" name="action" value="modifier_c9">' +
                    '<div class="mb-2"><label class="form-label small">N° de référence C9</label>' +
                    '<input type="text" name="numero_reference" class="form-control" required value="' + echap(info.c9.numero_reference) + '"></div>' +
                    '<div class="row g-2 mb-2">' +
                    '<div class="col-6"><label class="form-label small">Date d\'envoi</label><input type="date" name="date_envoi" class="form-control" value="' + (info.c9.date_envoi || '') + '"></div>' +
                    '<div class="col-6"><label class="form-label small">Date réception</label><input type="date" name="date_reception" class="form-control" value="' + (info.c9.date_reception || '') + '"></div>' +
                    '</div>' +
                    '<button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-check-circle"></i> Enregistrer le C9</button>' +
                    '</form>';
            }

            html += '<a href="courrier_view.php?id=' + info.id + '" class="btn btn-outline-secondary w-100 mb-2"><i class="bi bi-arrow-up-right-square"></i> Voir le détail complet</a>';
            if (info.peut_supprimer) {
                html += '<form method="post" action="courrier_actions.php" data-confirmer="Supprimer définitivement ce courrier ? Cette action est irréversible.">' +
                    '<?= csrf_field() ?>' +
                    '<input type="hidden" name="courrier_id" value="' + info.id + '">' +
                    '<input type="hidden" name="action" value="supprimer">' +
                    '<button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-trash"></i> Supprimer définitivement</button>' +
                    '</form>';
            }
            corps.innerHTML = html;
        })
        .catch(function () {
            corps.innerHTML = '<p class="text-danger">Erreur de chargement.</p>';
        });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
