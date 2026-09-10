<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Calendrier';

// ---------- Mois affiché ----------
$mois_param = $_GET['mois'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mois_param)) {
    $mois_param = date('Y-m');
}
$timestamp_mois = strtotime($mois_param . '-01');
$annee  = (int) date('Y', $timestamp_mois);
$mois   = (int) date('n', $timestamp_mois);
$mois_precedent = date('Y-m', strtotime('-1 month', $timestamp_mois));
$mois_suivant   = date('Y-m', strtotime('+1 month', $timestamp_mois));
$nb_jours       = (int) date('t', $timestamp_mois);
$premier_jour_semaine = (int) date('N', $timestamp_mois); // 1 (lundi) à 7 (dimanche)

$noms_mois = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];

// ---------- Événements du mois (les miens + les partagés) ----------
$stmt = $pdo->prepare(
    "SELECT e.*, emp.nom AS createur_nom, emp.prenom AS createur_prenom
     FROM evenements e
     LEFT JOIN employes emp ON emp.id = e.created_by
     WHERE (e.created_by = :moi OR e.partage = TRUE)
       AND EXTRACT(YEAR FROM e.date_evenement) = :annee
       AND EXTRACT(MONTH FROM e.date_evenement) = :mois
     ORDER BY e.date_evenement, e.heure NULLS LAST"
);
$stmt->execute(['moi' => current_user_id(), 'annee' => $annee, 'mois' => $mois]);
$evenements_mois = $stmt->fetchAll();

$evenements_par_jour = [];
foreach ($evenements_mois as $e) {
    $jour = (int) date('j', strtotime($e['date_evenement']));
    $evenements_par_jour[$jour][] = $e;
}

// ---------- Prochaines échéances (toutes dates futures ou en retard) ----------
$stmt = $pdo->prepare(
    "SELECT e.*, emp.nom AS createur_nom, emp.prenom AS createur_prenom
     FROM evenements e
     LEFT JOIN employes emp ON emp.id = e.created_by
     WHERE (e.created_by = :moi OR e.partage = TRUE) AND e.statut = 'a_faire'
     ORDER BY e.date_evenement ASC, e.heure ASC NULLS LAST
     LIMIT 15"
);
$stmt->execute(['moi' => current_user_id()]);
$prochaines_echeances = $stmt->fetchAll();

$aujourdhui = date('Y-m-d');

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h3 class="mb-0"><i class="bi bi-calendar3"></i> Calendrier</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouvelEvenement">
        <i class="bi bi-plus-circle"></i> Ajouter un événement
    </button>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="?mois=<?= $mois_precedent ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
                <h5 class="mb-0"><?= $noms_mois[$mois] ?> <?= $annee ?></h5>
                <a href="?mois=<?= $mois_suivant ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></a>
            </div>

            <div class="calendrier-grille">
                <?php foreach (['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $jour_nom): ?>
                    <div class="calendrier-entete"><?= $jour_nom ?></div>
                <?php endforeach; ?>

                <?php for ($i = 1; $i < $premier_jour_semaine; $i++): ?>
                    <div class="calendrier-case calendrier-case-vide"></div>
                <?php endfor; ?>

                <?php for ($jour = 1; $jour <= $nb_jours; $jour++):
                    $date_iso = sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
                    $evenements_jour = $evenements_par_jour[$jour] ?? [];
                    $est_aujourdhui = $date_iso === $aujourdhui;
                ?>
                    <div class="calendrier-case <?= $est_aujourdhui ? 'calendrier-aujourdhui' : '' ?>">
                        <div class="calendrier-numero"><?= $jour ?></div>
                        <?php foreach (array_slice($evenements_jour, 0, 3) as $e): ?>
                            <div class="calendrier-event <?= $e['statut'] === 'traite' ? 'calendrier-event-traite' : ($date_iso < $aujourdhui ? 'calendrier-event-retard' : '') ?>"
                                 title="<?= htmlspecialchars($e['titre']) ?>">
                                <?= htmlspecialchars(mb_substr($e['titre'], 0, 16)) ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($evenements_jour) > 3): ?>
                            <div class="calendrier-event-plus">+<?= count($evenements_jour) - 3 ?> autre(s)</div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-alarm"></i> Prochaines échéances</h6>
            <ul class="list-group list-group-flush">
                <?php foreach ($prochaines_echeances as $e): $en_retard = $e['date_evenement'] < $aujourdhui; ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong class="<?= $en_retard ? 'text-danger' : '' ?>"><?= htmlspecialchars($e['titre']) ?></strong>
                                <?php if ($e['partage']): ?><span class="badge bg-secondary ms-1">Partagé</span><?php endif; ?>
                                <div class="text-muted small">
                                    <?= htmlspecialchars(date('d/m/Y', strtotime($e['date_evenement']))) ?>
                                    <?= $e['heure'] ? ' à ' . htmlspecialchars(substr($e['heure'], 0, 5)) : '' ?>
                                    <?= $en_retard ? '<span class="text-danger">(en retard)</span>' : '' ?>
                                </div>
                                <?php if ($e['description']): ?><div class="small mt-1"><?= nl2br(htmlspecialchars($e['description'])) ?></div><?php endif; ?>
                            </div>
                            <form method="post" action="evenement_actions.php">
            <?= csrf_field() ?>
                                <input type="hidden" name="action" value="marquer_traite">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <input type="hidden" name="retour_mois" value="<?= $mois_param ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit" title="Marquer comme traité">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($prochaines_echeances)): ?>
                    <li class="list-group-item px-0"><div class="etat-vide"><i class="bi bi-calendar-check"></i>Aucune échéance à venir.</div></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<!-- ================= POPUP : Nouvel événement ================= -->
<div class="modal fade" id="modalNouvelEvenement" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="evenement_actions.php">
            <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-calendar-plus"></i> Nouvel événement</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="action" value="creer">
            <input type="hidden" name="retour_mois" value="<?= $mois_param ?>">
            <div class="mb-3">
                <label class="form-label">Titre *</label>
                <input type="text" name="titre" class="form-control" required maxlength="255">
            </div>
            <div class="row g-2 mb-3">
                <div class="col-8">
                    <label class="form-label">Date *</label>
                    <input type="date" name="date_evenement" class="form-control" required value="<?= $aujourdhui ?>">
                </div>
                <div class="col-4">
                    <label class="form-label">Heure</label>
                    <input type="time" name="heure" class="form-control">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <?php if (!is_simple()): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="partage" id="partageEvenement" value="1">
                <label class="form-check-label" for="partageEvenement">Partager avec tout le monde (sinon visible par vous seul)</label>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
