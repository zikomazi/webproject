<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

// ---------- Reprend exactement les mêmes filtres que courriers.php ----------
$f_statut       = $_GET['statut'] ?? '';
$f_priorite     = $_GET['priorite'] ?? '';
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
    $conditions[] = "c.date_limite < CURRENT_TIMESTAMP AND r.id IS NULL AND c.statut != 'archive'";
} elseif ($f_statut === 'en_cours') {
    $conditions[] = "r.id IS NULL AND c.statut != 'archive' AND (c.date_limite IS NULL OR c.date_limite >= CURRENT_TIMESTAMP)";
} elseif ($f_statut === 'repondu') {
    $conditions[] = 'r.id IS NOT NULL';
} elseif ($f_statut === 'archive') {
    $conditions[] = "c.statut = 'archive'";
}
if ($f_priorite !== '') {
    $conditions[] = 'c.priorite = :priorite';
    $params['priorite'] = $f_priorite;
}
if ($f_recherche !== '') {
    $conditions[] = '(c.reference ILIKE :q OR c.objet ILIKE :q)';
    $params['q'] = "%{$f_recherche}%";
}
if ($f_dc_debut !== '') { $conditions[] = 'c.date_courrier >= :dc_debut'; $params['dc_debut'] = $f_dc_debut; }
if ($f_dc_fin !== '')   { $conditions[] = 'c.date_courrier <= :dc_fin';   $params['dc_fin']   = $f_dc_fin; }
if ($f_dr_debut !== '') { $conditions[] = 'c.date_reception >= :dr_debut'; $params['dr_debut'] = $f_dr_debut; }
if ($f_dr_fin !== '')   { $conditions[] = 'c.date_reception <= :dr_fin';   $params['dr_fin']   = $f_dr_fin; }

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$sql = "SELECT DISTINCT c.*, r.numero_reference AS reponse_reference, r.id AS reponse_id
        FROM courriers c
        {$join_dest}
        LEFT JOIN reponses r ON r.courrier_id = c.id
        {$where}
        ORDER BY c.date_envoi DESC
        LIMIT 5000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$courriers = $stmt->fetchAll();

$ventilations = charger_ventilations($pdo, array_column($courriers, 'id'));

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="courriers_' . date('Y-m-d_His') . '.csv"');

$sortie = fopen('php://output', 'w');
fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8 pour un affichage correct des accents dans Excel
fputcsv($sortie, ['Référence', 'Objet', 'Date courrier', 'Date réception', 'Priorité', 'Statut', 'Ventilation', 'Référence réponse', 'Échéance'], ';');

foreach ($courriers as $c) {
    $a_reponse = !empty($c['reponse_id']);
    $statut    = statut_affichage($c, $a_reponse);
    $noms_ventilation = array_map(function ($v) {
        return $v['prenom'] . ' ' . $v['nom'] . ($v['est_pilote'] ? ' (pilote)' : '');
    }, $ventilations[$c['id']] ?? []);

    fputcsv($sortie, [
        $c['reference'],
        $c['objet'],
        $c['date_courrier'],
        $c['date_reception'],
        libelle_priorite($c['priorite']),
        libelle_statut($statut),
        implode(', ', $noms_ventilation),
        $c['reponse_reference'],
        $c['date_limite'],
    ], ';');
}

fclose($sortie);
exit;
