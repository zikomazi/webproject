<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();

if (!can_send_courrier()) {
    http_response_code(403);
    die('Accès refusé.');
}

$nom = basename($_GET['nom'] ?? '');
$dossier = __DIR__ . '/assets/scans_entrants';
$chemin  = $dossier . '/' . $nom;

// Sécurité : le fichier doit être strictement dans le dossier des scans
if ($nom === '' || !file_exists($chemin) || realpath($chemin) !== realpath($dossier) . DIRECTORY_SEPARATOR . $nom) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$extensions_mime = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

header('Content-Type: ' . ($extensions_mime[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . $nom . '"');
header('Content-Length: ' . filesize($chemin));
readfile($chemin);
exit;
