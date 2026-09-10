<?php
/**
 * session_timer.php — à inclure en tout premier sur chaque page protégée.
 */

require __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const DUREE_MAX_INACTIVITE = 1800; // 30 minutes

if (isset($_SESSION['employe_id'])) {
    if (isset($_SESSION['derniere_activite'])
        && (time() - $_SESSION['derniere_activite']) > DUREE_MAX_INACTIVITE) {
        session_unset();
        session_destroy();
        header('Location: index.php?expire=1');
        exit;
    }
    $_SESSION['derniere_activite'] = time();
}
