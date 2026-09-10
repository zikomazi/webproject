<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();

header('Content-Type: application/json');

if (!can_send_courrier()) {
    http_response_code(403);
    echo json_encode(['error' => 'non_autorise']);
    exit;
}

$dossier = __DIR__ . '/assets/scans_entrants';
$extensions_autorisees = ['pdf', 'jpg', 'jpeg', 'png'];
$fichiers = [];

if (is_dir($dossier)) {
    foreach (scandir($dossier) as $nom) {
        if ($nom === '.' || $nom === '..' || $nom === '.gitkeep') {
            continue;
        }
        $chemin = $dossier . '/' . $nom;
        if (!is_file($chemin)) {
            continue;
        }
        $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions_autorisees, true)) {
            continue;
        }
        $fichiers[] = [
            'nom'      => $nom,
            'taille'   => filesize($chemin),
            'modifie'  => filemtime($chemin),
            'extension' => $extension,
        ];
    }
}

usort($fichiers, function ($a, $b) { return $b['modifie'] <=> $a['modifie']; });

echo json_encode(array_slice($fichiers, 0, 30));
