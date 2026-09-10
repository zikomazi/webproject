<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/includes/fonctions.php';
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_verify();

const MAX_TENTATIVES        = 5;
const DUREE_VERROUILLAGE_MIN = 15;

$nom_utilisateur = trim($_POST['nom_utilisateur'] ?? '');
$mot_de_passe    = $_POST['mot_de_passe'] ?? '';

if ($nom_utilisateur === '' || $mot_de_passe === '') {
    $_SESSION['login_erreur'] = 'Merci de renseigner votre nom d\'utilisateur et votre mot de passe.';
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, nom_utilisateur, password_hash, nom, prenom, role, actif, tentatives_echouees, verrouille_jusqua
     FROM employes WHERE nom_utilisateur = :nom_utilisateur'
);
$stmt->execute(['nom_utilisateur' => $nom_utilisateur]);
$employe = $stmt->fetch();

// Compte verrouillé suite à trop d'échecs récents ?
if ($employe && !empty($employe['verrouille_jusqua']) && strtotime($employe['verrouille_jusqua']) > time()) {
    $minutes_restantes = (int) ceil((strtotime($employe['verrouille_jusqua']) - time()) / 60);
    $_SESSION['login_erreur'] = "Compte temporairement verrouillé suite à plusieurs échecs. Réessayez dans {$minutes_restantes} minute(s).";
    header('Location: index.php');
    exit;
}

if (!$employe || !password_verify($mot_de_passe, $employe['password_hash'])) {
    if ($employe) {
        $nouvelles_tentatives = (int) $employe['tentatives_echouees'] + 1;
        if ($nouvelles_tentatives >= MAX_TENTATIVES) {
            $pdo->prepare(
                "UPDATE employes SET tentatives_echouees = 0,
                 verrouille_jusqua = CURRENT_TIMESTAMP + INTERVAL '" . DUREE_VERROUILLAGE_MIN . " minutes'
                 WHERE id = :id"
            )->execute(['id' => $employe['id']]);
            $_SESSION['login_erreur'] = 'Trop de tentatives échouées. Compte verrouillé ' . DUREE_VERROUILLAGE_MIN . ' minutes.';
        } else {
            $pdo->prepare('UPDATE employes SET tentatives_echouees = :t WHERE id = :id')
                ->execute(['t' => $nouvelles_tentatives, 'id' => $employe['id']]);
            $_SESSION['login_erreur'] = 'Identifiants incorrects.';
        }
    } else {
        $_SESSION['login_erreur'] = 'Identifiants incorrects.';
    }
    header('Location: index.php');
    exit;
}

if (!$employe['actif']) {
    $_SESSION['login_erreur'] = 'Ce compte a été désactivé. Contactez l\'administrateur.';
    header('Location: index.php');
    exit;
}

// Connexion réussie : on réinitialise le compteur d'échecs
$pdo->prepare('UPDATE employes SET tentatives_echouees = 0, verrouille_jusqua = NULL, derniere_connexion = CURRENT_TIMESTAMP WHERE id = :id')
    ->execute(['id' => $employe['id']]);

session_regenerate_id(true);
$_SESSION['employe_id']        = (int) $employe['id'];
$_SESSION['nom_utilisateur']   = $employe['nom_utilisateur'];
$_SESSION['nom']               = $employe['nom'];
$_SESSION['prenom']            = $employe['prenom'];
$_SESSION['role']              = $employe['role'];
$_SESSION['derniere_activite'] = time();

header('Location: accueil.php');
exit;
