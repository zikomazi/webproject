<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require_role(['administrateur']);
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Maintenance';

// ---------- Suppression d'un fichier orphelin ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer_orphelin') {
    csrf_verify();
    $nom = basename($_POST['nom'] ?? '');
    $dossier = __DIR__ . '/assets/uploads';
    $chemin  = $dossier . '/' . $nom;
    if ($nom !== '' && file_exists($chemin) && realpath($chemin) === realpath($dossier) . DIRECTORY_SEPARATOR . $nom) {
        unlink($chemin);
        audit($pdo, 'suppression_fichier_orphelin', "Fichier orphelin supprimé : {$nom}");
        redirect_with_message('maintenance.php', 'Fichier orphelin supprimé.');
    }
    redirect_with_message('maintenance.php', 'Fichier introuvable.', 'danger');
}

// ---------- Détection des fichiers orphelins dans assets/uploads ----------
$dossier_uploads = __DIR__ . '/assets/uploads';
$fichiers_references = [];
foreach ($pdo->query('SELECT chemin FROM pieces_jointes')->fetchAll() as $row) {
    $fichiers_references[basename($row['chemin'])] = true;
}
foreach ($pdo->query("SELECT chemin_fichier FROM reponses WHERE chemin_fichier IS NOT NULL")->fetchAll() as $row) {
    $fichiers_references[basename($row['chemin_fichier'])] = true;
}
foreach ($pdo->query("SELECT chemin_fichier FROM c9 WHERE chemin_fichier IS NOT NULL")->fetchAll() as $row) {
    $fichiers_references[basename($row['chemin_fichier'])] = true;
}

$orphelins = [];
$taille_totale_uploads = 0;
$taille_orphelins = 0;
if (is_dir($dossier_uploads)) {
    foreach (scandir($dossier_uploads) as $nom) {
        if ($nom === '.' || $nom === '..' || $nom === '.gitkeep' || $nom === '.htaccess') {
            continue;
        }
        $chemin = $dossier_uploads . '/' . $nom;
        if (!is_file($chemin)) { continue; }
        $taille = filesize($chemin);
        $taille_totale_uploads += $taille;
        if (!isset($fichiers_references[$nom])) {
            $orphelins[] = ['nom' => $nom, 'taille' => $taille, 'modifie' => filemtime($chemin)];
            $taille_orphelins += $taille;
        }
    }
}

function formater_taille(int $octets): string
{
    if ($octets >= 1024 * 1024) { return round($octets / 1024 / 1024, 1) . ' Mo'; }
    if ($octets >= 1024) { return round($octets / 1024, 1) . ' Ko'; }
    return $octets . ' o';
}

// ---------- Journal d'audit (30 dernières actions) ----------
$audit_recent = $pdo->query('SELECT * FROM audit_journal ORDER BY created_at DESC LIMIT 30')->fetchAll();

// ---------- Statistiques générales ----------
$nb_courriers  = (int) $pdo->query('SELECT COUNT(*) FROM courriers')->fetchColumn();
$nb_comptes    = (int) $pdo->query('SELECT COUNT(*) FROM employes')->fetchColumn();

require __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4"><i class="bi bi-tools"></i> Maintenance</h3>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-primary p-3"><div class="display-6"><?= $nb_courriers ?></div><div class="text-muted small">Courriers</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-primary p-3"><div class="display-6"><?= $nb_comptes ?></div><div class="text-muted small">Comptes</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat stat-neutral p-3"><div class="display-6"><?= formater_taille($taille_totale_uploads) ?></div><div class="text-muted small">Espace utilisé (uploads)</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat <?= $orphelins ? 'stat-danger' : 'stat-success' ?> p-3"><div class="display-6"><?= count($orphelins) ?></div><div class="text-muted small">Fichiers orphelins</div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-file-earmark-x"></i> Fichiers orphelins <?= $orphelins ? '(' . formater_taille($taille_orphelins) . ' à libérer)' : '' ?></h6>
            <p class="text-muted small">Fichiers présents sur le serveur mais qui ne sont plus liés à aucun courrier (résidus d'essais, de bugs, ou de courriers supprimés).</p>
            <ul class="list-group list-group-flush">
                <?php foreach ($orphelins as $o): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small"><?= htmlspecialchars($o['nom']) ?></div>
                            <div class="text-muted small"><?= formater_taille($o['taille']) ?> — <?= htmlspecialchars(date('d/m/Y', $o['modifie'])) ?></div>
                        </div>
                        <form method="post" data-confirmer="Supprimer définitivement ce fichier orphelin ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="supprimer_orphelin">
                            <input type="hidden" name="nom" value="<?= htmlspecialchars($o['nom']) ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($orphelins)): ?>
                    <li class="list-group-item px-0"><div class="etat-vide"><i class="bi bi-check-circle"></i>Aucun fichier orphelin, tout est propre.</div></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-journal-text"></i> Journal d'audit (30 dernières actions sensibles)</h6>
            <ul class="list-group list-group-flush">
                <?php foreach ($audit_recent as $a): ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between">
                            <strong class="small"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $a['action']))) ?></strong>
                            <span class="text-muted small"><?= htmlspecialchars(substr($a['created_at'], 0, 16)) ?></span>
                        </div>
                        <div class="small"><?= htmlspecialchars($a['description']) ?></div>
                        <div class="text-muted small">Par <?= htmlspecialchars($a['effectue_par_nom'] ?? 'Système') ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($audit_recent)): ?>
                    <li class="list-group-item px-0"><div class="etat-vide"><i class="bi bi-journal"></i>Aucune action sensible enregistrée.</div></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card p-3 mt-3">
    <h6><i class="bi bi-hdd"></i> Sauvegarde de la base de données</h6>
    <p class="text-muted small mb-0">
        Ce site ne gère pas les sauvegardes automatiquement. Pensez à programmer une sauvegarde régulière de la base
        PostgreSQL <code>suivi</code> (ex : tâche planifiée Windows exécutant <code>pg_dump</code>), ainsi qu'une copie
        du dossier <code>assets/uploads/</code> qui contient les pièces jointes.
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
