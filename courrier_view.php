<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, ec.nom AS createur_nom, ec.prenom AS createur_prenom,
            ep.nom AS priorite_modifiee_par_nom, ep.prenom AS priorite_modifiee_par_prenom
     FROM courriers c
     LEFT JOIN employes ec ON ec.id = c.created_by
     LEFT JOIN employes ep ON ep.id = c.priorite_modifiee_par
     WHERE c.id = :id'
);
$stmt->execute(['id' => $id]);
$courrier = $stmt->fetch();

if (!$courrier) {
    header('Location: courriers.php');
    exit;
}

if (!can_view_courrier($pdo, $courrier)) {
    http_response_code(403);
    die('Vous n\'avez pas accès à ce courrier.');
}

$page_title = $courrier['reference'];

// ---------- Enregistre l'ouverture par un compte "simple" + notifie secrétariat/admin ----------
if (is_simple()) {
    $stmt = $pdo->prepare('SELECT * FROM courrier_destinataires WHERE courrier_id = :cid AND employe_id = :eid');
    $stmt->execute(['cid' => $id, 'eid' => current_user_id()]);
    $destinataire_row = $stmt->fetch();

    if ($destinataire_row && empty($destinataire_row['date_ouverture'])) {
        $pdo->prepare('UPDATE courrier_destinataires SET date_ouverture = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $destinataire_row['id']]);

        if ($courrier['statut'] === 'envoye') {
            $pdo->prepare("UPDATE courriers SET statut = 'ouvert' WHERE id = :id")->execute(['id' => $id]);
            $courrier['statut'] = 'ouvert';
        }

        notifier_secretariat_admin(
            $pdo, $id, current_user_id(), 'ouverture',
            "{$_SESSION['prenom']} {$_SESSION['nom']} a ouvert le courrier {$courrier['reference']}."
        );
    }
}

// ---------- Données annexes ----------
$pieces = $pdo->prepare(
    'SELECT p.*, e.nom, e.prenom FROM pieces_jointes p
     LEFT JOIN employes e ON e.id = p.uploaded_by
     WHERE p.courrier_id = :id ORDER BY p.created_at DESC'
);
$pieces->execute(['id' => $id]);
$pieces = $pieces->fetchAll();

$destinataires = $pdo->prepare(
    'SELECT cd.*, e.nom, e.prenom FROM courrier_destinataires cd
     JOIN employes e ON e.id = cd.employe_id
     WHERE cd.courrier_id = :id ORDER BY cd.est_pilote DESC, e.nom'
);
$destinataires->execute(['id' => $id]);
$destinataires = $destinataires->fetchAll();

$reponse = $pdo->prepare('SELECT * FROM reponses WHERE courrier_id = :id');
$reponse->execute(['id' => $id]);
$reponse = $reponse->fetch();

$c9 = null;
if ($reponse) {
    $stmt = $pdo->prepare('SELECT * FROM c9 WHERE reponse_id = :rid');
    $stmt->execute(['rid' => $reponse['id']]);
    $c9 = $stmt->fetch() ?: null;
}

$historique = $pdo->prepare(
    'SELECT h.*, e.nom, e.prenom FROM historique_actions h
     LEFT JOIN employes e ON e.id = h.effectue_par
     WHERE h.courrier_id = :id ORDER BY h.created_at DESC'
);
$historique->execute(['id' => $id]);
$historique = $historique->fetchAll();

$tous_les_agents = [];
if (can_reassign()) {
    $tous_les_agents = $pdo->query("SELECT id, nom, prenom FROM employes WHERE role = 'simple' AND actif = TRUE ORDER BY nom")->fetchAll();
}

$retard_ligne   = est_en_retard($courrier);
$statut_affiche = statut_affichage($courrier, (bool) $reponse);

require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h3 class="mb-1"><?= htmlspecialchars($courrier['reference']) ?>
            <span class="badge badge-statut-<?= $statut_affiche ?>"><?= libelle_statut($statut_affiche) ?></span>
            <span class="badge <?= classe_priorite($courrier['priorite']) ?>"><?= libelle_priorite($courrier['priorite']) ?></span>
        </h3>
        <p class="text-muted mb-0">
            Envoyé le <?= htmlspecialchars(substr($courrier['date_envoi'], 0, 16)) ?>
            par <?= htmlspecialchars(($courrier['createur_prenom'] ?? '') . ' ' . ($courrier['createur_nom'] ?? '-')) ?>
            — Échéance : <strong class="<?= $retard_ligne ? 'text-danger' : '' ?>"><?= $courrier['date_limite'] ? htmlspecialchars(substr($courrier['date_limite'], 0, 16)) : '-' ?></strong>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (can_edit_courrier($courrier)): ?>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalModifierCourrier">
                <i class="bi bi-pencil-square"></i> Modifier
            </button>
        <?php endif; ?>
        <a href="courriers.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour à la liste</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-4 mb-3">
            <h5><?= htmlspecialchars($courrier['objet']) ?></h5>
            <div class="row small text-muted mt-2">
                <div class="col-md-6">Date du courrier : <strong class="text-dark"><?= $courrier['date_courrier'] ? htmlspecialchars($courrier['date_courrier']) : '-' ?></strong></div>
                <div class="col-md-6">Date de réception : <strong class="text-dark"><?= $courrier['date_reception'] ? htmlspecialchars($courrier['date_reception']) : '-' ?></strong></div>
            </div>
            <?php if ($courrier['observations']): ?>
                <hr>
                <p class="mb-0"><?= nl2br(htmlspecialchars($courrier['observations'])) ?></p>
            <?php endif; ?>

            <?php if ($reponse): ?>
                <hr>
                <div class="alert alert-success mb-2">
                    <i class="bi bi-reply-fill"></i> <strong>Réponse enregistrée :</strong> <?= htmlspecialchars($reponse['numero_reference']) ?>
                    <span class="text-muted small"> — envoyée le <?= htmlspecialchars(substr($reponse['date_envoi'], 0, 10)) ?></span>
                    <?php if ($reponse['chemin_fichier']): ?>
                        <br>
                        <a href="#" onclick="ouvrirApercu('reponse_fichier.php?courrier_id=<?= $id ?>&mode=consulter', '<?= htmlspecialchars($reponse['nom_fichier'], ENT_QUOTES) ?>'); return false;" class="small me-2"><i class="bi bi-eye"></i> Consulter : <?= htmlspecialchars($reponse['nom_fichier']) ?></a>
                        <a href="reponse_fichier.php?courrier_id=<?= $id ?>&mode=telecharger" class="small"><i class="bi bi-download"></i> Télécharger</a>
                    <?php endif; ?>
                    <?php if ($reponse['projet_lettre']): ?><span class="badge bg-secondary ms-1">Projet lettre</span><?php endif; ?>
                    <?php if ($reponse['projet_message']): ?><span class="badge bg-secondary ms-1">Projet message</span><?php endif; ?>
                    <?php if (can_add_reponse()): ?>
                        <form method="post" action="courrier_actions.php" class="d-inline" data-confirmer="Annuler cette réponse (par exemple en cas d'erreur de saisie) ? Le courrier repassera en cours.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="courrier_id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="annuler_reponse">
                            <button type="submit" class="btn btn-sm btn-outline-danger ms-2"><i class="bi bi-x-circle"></i> Annuler la réponse</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($c9): ?>
                    <div class="alert <?= $c9['statut'] === 'cloture' ? 'alert-success' : 'alert-danger' ?> mb-0">
                        <i class="bi bi-flag-fill"></i> <strong>C9 :</strong>
                        <?php if ($c9['statut'] === 'cloture'): ?>
                            Clôturé — réf. <?= htmlspecialchars($c9['numero_reference']) ?>
                            (envoyé le <?= htmlspecialchars(substr($c9['date_envoi'], 0, 10)) ?>, reçu le <?= htmlspecialchars(substr($c9['date_reception'], 0, 10)) ?>)
                            <?php if ($c9['chemin_fichier']): ?>
                                <br><a href="#" onclick="ouvrirApercu('c9_fichier.php?id=<?= $c9['id'] ?>&mode=consulter', '<?= htmlspecialchars($c9['nom_fichier'], ENT_QUOTES) ?>'); return false;" class="small"><i class="bi bi-eye"></i> Consulter : <?= htmlspecialchars($c9['nom_fichier']) ?></a>
                                <a href="c9_fichier.php?id=<?= $c9['id'] ?>&mode=telecharger" class="small ms-2"><i class="bi bi-download"></i> Télécharger</a>
                            <?php endif; ?>
                        <?php else: ?>
                            En attente de clôture.
                            <?php if (can_add_reponse()): ?>
                                <button type="button" class="btn btn-sm btn-danger ms-2"
                                        onclick="ouvrirC9(<?= $c9['id'] ?>, '<?= htmlspecialchars($courrier['reference'], ENT_QUOTES) ?>')">
                                    Compléter la clôture C9
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Pièce(s) jointe(s) du courrier -->
        <div class="card p-4 mb-3">
            <h6><i class="bi bi-paperclip"></i> Pièce(s) jointe(s) du courrier</h6>
            <ul class="list-group list-group-flush">
                <?php foreach ($pieces as $p): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 flex-wrap gap-2">
                        <span><i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($p['nom_fichier']) ?></span>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="ouvrirApercu('download.php?id=<?= $p['id'] ?>&courrier_id=<?= $id ?>&mode=consulter', '<?= htmlspecialchars($p['nom_fichier'], ENT_QUOTES) ?>')">
                                <i class="bi bi-eye"></i> Consulter
                            </button>
                            <a href="download.php?id=<?= $p['id'] ?>&courrier_id=<?= $id ?>&mode=telecharger" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-download"></i> Télécharger
                            </a>
                            <span class="text-muted small">
                                <?= htmlspecialchars(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? '')) ?> — <?= htmlspecialchars(substr($p['created_at'], 0, 16)) ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($pieces)): ?>
                    <li class="list-group-item px-0 text-muted">Aucune pièce jointe.</li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Ventilation + suivi ouverture/téléchargement -->
        <?php if (!is_simple()): ?>
        <div class="card p-4 mb-3">
            <h6><i class="bi bi-people"></i> Ventilation et suivi</h6>
            <table class="table table-sm mb-0">
                <thead><tr><th>Agent</th><th>Rôle</th><th>Ouvert le</th><th>Téléchargé le</th></tr></thead>
                <tbody>
                <?php foreach ($destinataires as $d): ?>
                    <tr>
                        <td><?= $d['est_pilote'] ? '<strong>' . htmlspecialchars($d['prenom'] . ' ' . $d['nom']) . '</strong>' : htmlspecialchars($d['prenom'] . ' ' . $d['nom']) ?></td>
                        <td><?= $d['est_pilote'] ? '<span class="badge bg-primary">Pilote</span>' : '<span class="badge bg-secondary">Copie</span>' ?></td>
                        <td><?= $d['date_ouverture'] ? '<span class="text-success"><i class="bi bi-eye-fill"></i> ' . htmlspecialchars(substr($d['date_ouverture'], 0, 16)) . '</span>' : '<span class="text-muted">Non consulté</span>' ?></td>
                        <td><?= $d['date_telechargement'] ? '<span class="text-success"><i class="bi bi-download"></i> ' . htmlspecialchars(substr($d['date_telechargement'], 0, 16)) . '</span>' : '<span class="text-muted">-</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Historique -->
        <div class="card p-4">
            <h6><i class="bi bi-clock-history"></i> Historique</h6>
            <ul class="list-group list-group-flush">
                <?php foreach ($historique as $h): ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between">
                            <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $h['type_action']))) ?></strong>
                            <span class="text-muted small"><?= htmlspecialchars(substr($h['created_at'], 0, 16)) ?></span>
                        </div>
                        <?php if ($h['commentaire']): ?><div><?= nl2br(htmlspecialchars($h['commentaire'])) ?></div><?php endif; ?>
                        <div class="text-muted small">Par <?= $h['nom'] ? htmlspecialchars($h['prenom'] . ' ' . $h['nom']) : 'Système' ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($historique)): ?>
                    <li class="list-group-item px-0 text-muted">Aucun historique.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-4">

        <!-- Compte simple : marquer en cours -->
        <?php if (is_simple() && !in_array($courrier['statut'], ['repondu', 'archive'], true)): ?>
        <div class="card p-4 mb-3">
            <h6><i class="bi bi-play-circle"></i> Traitement</h6>
            <form method="post" action="courrier_actions.php">
            <?= csrf_field() ?>
                <input type="hidden" name="courrier_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="marquer_en_cours">
                <button class="btn btn-primary w-100" type="submit" <?= $courrier['statut'] === 'en_cours' ? 'disabled' : '' ?>>
                    <i class="bi bi-play-fill"></i> <?= $courrier['statut'] === 'en_cours' ? 'Déjà en cours' : 'Marquer en cours' ?>
                </button>
                <p class="text-muted small mt-2 mb-0">Une fois votre réponse prête, transmettez-la au secrétariat qui enregistrera la référence dans le système.</p>
            </form>
        </div>
        <?php endif; ?>

        <!-- Secrétariat/admin : enregistrer la réponse -->
        <?php if (can_add_reponse() && !$reponse && $courrier['reponse_requise']): ?>
        <div class="card p-4 mb-3">
            <h6><i class="bi bi-reply-fill"></i> Réponse</h6>
            <button type="button" class="btn btn-success w-100"
                    onclick="ouvrirReponse(<?= $id ?>, '<?= htmlspecialchars($courrier['reference'], ENT_QUOTES) ?>')">
                <i class="bi bi-plus-circle"></i> Ajouter la réponse
            </button>
        </div>
        <?php endif; ?>

        <!-- Secrétariat/admin : réponse obligatoire ou non (toujours visible, à côté des autres actions) -->
        <?php if (can_add_reponse() && !$reponse): ?>
        <div class="card p-4 mb-3">
            <h6><i class="bi bi-slash-circle"></i> Obligation de réponse</h6>
            <?php if ($courrier['reponse_requise']): ?>
                <p class="text-muted small">Ce courrier attend actuellement une réponse. S'il s'agit d'un courrier informatif qui n'en nécessite pas, vous pouvez annuler cette obligation.</p>
                <form method="post" action="courrier_actions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="courrier_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="toggle_reponse_requise">
                    <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-x-circle"></i> Marquer "réponse non obligatoire"</button>
                </form>
            <?php else: ?>
                <div class="alert alert-secondary small mb-2"><i class="bi bi-info-circle"></i> Ce courrier est marqué comme ne nécessitant pas de réponse.</div>
                <form method="post" action="courrier_actions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="courrier_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="toggle_reponse_requise">
                    <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Rendre la réponse obligatoire</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Admin : modifier la priorité -->
        <?php if (can_modify_priorite()): ?>
        <div class="card p-4 mb-3">
            <h6><i class="bi bi-flag"></i> Modifier la priorité</h6>
            <form method="post" action="courrier_actions.php">
            <?= csrf_field() ?>
                <input type="hidden" name="courrier_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="changer_priorite">
                <select name="priorite" class="form-select mb-2">
                    <?php foreach (['tres_urgent','urgent','simple'] as $p): ?>
                        <option value="<?= $p ?>" <?= $courrier['priorite'] === $p ? 'selected' : '' ?>><?= libelle_priorite($p) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline-primary w-100" type="submit">Mettre à jour</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Admin : réaffecter la ventilation -->
        <?php if (can_reassign()): ?>
        <div class="card p-4">
            <h6><i class="bi bi-diagram-3"></i> Réaffecter la ventilation</h6>
            <form method="post" action="courrier_actions.php">
            <?= csrf_field() ?>
                <input type="hidden" name="courrier_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="reassigner">
                <?php $dest_ids = array_column($destinataires, 'employe_id'); ?>
                <?php $pilote_actuel = null; foreach ($destinataires as $d) { if ($d['est_pilote']) { $pilote_actuel = (int) $d['employe_id']; } } ?>
                <div class="row small text-muted px-2 mb-1">
                    <div class="col-8">Agent</div>
                    <div class="col-4">Pilote</div>
                </div>
                <div class="border rounded p-2 mb-2" style="max-height:220px; overflow-y:auto;">
                    <?php foreach ($tous_les_agents as $u): ?>
                        <div class="row align-items-center px-2 py-1">
                            <div class="col-8 form-check">
                                <input class="form-check-input radd-checkbox" type="checkbox" name="destinataires[]" value="<?= $u['id'] ?>" id="radd_<?= $u['id'] ?>"
                                    <?= in_array($u['id'], $dest_ids, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="radd_<?= $u['id'] ?>"><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></label>
                            </div>
                            <div class="col-4 form-check">
                                <input class="form-check-input radd-pilote" type="radio" name="pilote_id" value="<?= $u['id'] ?>" id="rpil_<?= $u['id'] ?>"
                                    <?= $pilote_actuel === (int) $u['id'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="rpil_<?= $u['id'] ?>">Pilote</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-outline-primary w-100" type="submit">Mettre à jour la ventilation</button>
            </form>
        </div>
        <script>
        document.querySelectorAll('.radd-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var radio = document.getElementById('rpil_' + this.value);
                if (!this.checked && radio) { radio.checked = false; }
            });
        });
        document.querySelectorAll('.radd-pilote').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var cb = document.getElementById('radd_' + this.value);
                if (cb) { cb.checked = true; }
            });
        });
        </script>
        <?php endif; ?>

        <!-- Secrétariat/admin : archiver / désarchiver -->
        <?php if (can_add_reponse()): ?>
        <div class="card p-4 mt-3">
            <h6><i class="bi bi-archive"></i> Classement</h6>
            <?php if ($courrier['statut'] === 'archive'): ?>
                <form method="post" action="courrier_actions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="courrier_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="desarchiver">
                    <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-box-arrow-up"></i> Désarchiver</button>
                </form>
            <?php else: ?>
                <form method="post" action="courrier_actions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="courrier_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="archiver">
                    <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-archive"></i> Archiver ce courrier</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Administrateur : suppression définitive -->
        <?php if (is_admin()): ?>
        <div class="card p-4 mt-3 border-danger">
            <h6 class="text-danger"><i class="bi bi-exclamation-triangle"></i> Zone dangereuse</h6>
            <form method="post" action="courrier_actions.php" data-confirmer="Supprimer définitivement ce courrier, sa ventilation, ses pièces jointes et son historique ? Cette action est IRRÉVERSIBLE.">
                <?= csrf_field() ?>
                <input type="hidden" name="courrier_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="supprimer">
                <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-trash"></i> Supprimer définitivement</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (can_edit_courrier($courrier)): ?>
<!-- ================= POPUP : Modifier le courrier ================= -->
<div class="modal fade" id="modalModifierCourrier" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="courrier_actions.php">
        <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Modifier le courrier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="courrier_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="modifier">
            <div class="mb-3">
                <label class="form-label">Objet *</label>
                <input type="text" name="objet" class="form-control" required maxlength="500" value="<?= htmlspecialchars($courrier['objet']) ?>">
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label">Date du courrier</label>
                    <input type="date" name="date_courrier" class="form-control" value="<?= htmlspecialchars($courrier['date_courrier'] ?? '') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label">Date de réception</label>
                    <input type="date" name="date_reception" class="form-control" value="<?= htmlspecialchars($courrier['date_reception'] ?? '') ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Observations</label>
                <textarea name="observations" class="form-control" rows="3"><?= htmlspecialchars($courrier['observations'] ?? '') ?></textarea>
            </div>
            <div class="form-text">Pour changer la référence, la priorité ou la ventilation, utilisez les actions dédiées dans la colonne de droite.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (can_add_reponse()): ?>
<!-- ================= POPUP : Ajouter la réponse ================= -->
<div class="modal fade" id="modalReponse" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="courrier_reponse_action.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-reply-fill"></i> Réponse du courrier <span id="reponseRefCourrier"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="courrier_id" id="reponseCourrierId" value="<?= $id ?>">
            <div class="mb-3">
                <label class="form-label">N° de référence de la réponse *</label>
                <input type="text" name="numero_reference" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Date d'envoi *</label>
                <input type="date" name="date_envoi" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Pièce jointe</label>
                <input type="file" name="fichier" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </div>
            <div class="mb-2 fw-semibold small text-muted">Cocher si applicable (facultatif) :</div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="projet_lettre" value="1" id="projetLettre">
                <label class="form-check-label" for="projetLettre">Projet lettre</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="projet_message" value="1" id="projetMessage">
                <label class="form-check-label" for="projetMessage">Projet message</label>
            </div>
            <div class="form-text">Si l'une de ces cases est cochée, un suivi de clôture "C9" sera automatiquement créé.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Enregistrer la réponse</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= POPUP : Compléter le suivi C9 ================= -->
<div class="modal fade" id="modalC9" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="courrier_c9_action.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-flag-fill"></i> Clôture C9 — <span id="c9RefCourrier"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="c9_id" id="c9Id">
            <div class="mb-3">
                <label class="form-label">N° de référence *</label>
                <input type="text" name="numero_reference" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Date d'envoi *</label>
                <input type="date" name="date_envoi" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Date de réception *</label>
                <input type="date" name="date_reception" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Pièce jointe</label>
                <input type="file" name="fichier" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-check-circle"></i> Clôturer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ouvrirReponse(courrierId, reference) {
    document.getElementById('reponseRefCourrier').textContent = reference;
    new bootstrap.Modal(document.getElementById('modalReponse')).show();
}
function ouvrirC9(c9Id, reference) {
    document.getElementById('c9Id').value = c9Id;
    document.getElementById('c9RefCourrier').textContent = reference;
    new bootstrap.Modal(document.getElementById('modalC9')).show();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
