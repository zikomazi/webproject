<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/includes/fonctions.php';

if (isset($_SESSION['employe_id'])) {
    header('Location: accueil.php');
    exit;
}

$erreur = $_SESSION['login_erreur'] ?? null;
unset($_SESSION['login_erreur']);
$expire = isset($_GET['expire']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion - Gestion de courrier DSG</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="card login-card shadow p-4">
        <div class="text-center mb-3">
            <i class="bi bi-envelope-paper-fill" style="font-size:2.5rem;color:#1e3c72;"></i>
            <h4 class="mt-2">Gestion de courrier DSG</h4>
            <p class="text-muted small mb-0">Connectez-vous pour accéder au système</p>
        </div>

        <?php if ($expire): ?>
            <div class="alert alert-warning py-2">Votre session a expiré, merci de vous reconnecter.</div>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($erreur) ?></div>
        <?php endif; ?>

        <form action="session.php" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Nom d'utilisateur</label>
                <input type="text" name="nom_utilisateur" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Mot de passe</label>
                <div class="champ-mdp-wrapper">
                    <input type="password" name="mot_de_passe" id="mdpLogin" class="form-control" required>
                    <button type="button" class="champ-mdp-toggle" data-cible="mdpLogin"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right"></i> Se connecter
            </button>
        </form>
        <div class="text-center mt-3">
            <a href="mot_de_passe_oublie.php" class="small text-muted">Mot de passe oublié ?</a>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.champ-mdp-toggle').forEach(function (bouton) {
    bouton.addEventListener('click', function () {
        var champ = document.getElementById(bouton.getAttribute('data-cible'));
        if (!champ) return;
        var estMasque = champ.type === 'password';
        champ.type = estMasque ? 'text' : 'password';
        bouton.innerHTML = estMasque ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
});
</script>
</body>
</html>
