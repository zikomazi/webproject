<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/includes/fonctions.php';
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();

$etape  = 1;
$erreur = null;
$succes = null;
$employe = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ---------------- Étape 1 : identifier le compte ----------------
    if (($_POST['etape'] ?? '') === '1') {
        $nom_utilisateur = trim($_POST['nom_utilisateur'] ?? '');
        $stmt = $pdo->prepare('SELECT id, nom_utilisateur, question_secrete FROM employes WHERE nom_utilisateur = :nu');
        $stmt->execute(['nu' => $nom_utilisateur]);
        $trouve = $stmt->fetch();

        if (!$trouve || empty($trouve['question_secrete'])) {
            $erreur = 'Aucune question de sécurité n\'est configurée pour ce compte. Contactez l\'administrateur pour réinitialiser votre mot de passe.';
        } else {
            $employe = $trouve;
            $etape   = 2;
        }
    }

    // ---------------- Étape 2 : vérifier la réponse et changer le mot de passe ----------------
    if (($_POST['etape'] ?? '') === '2') {
        $employe_id       = (int) ($_POST['employe_id'] ?? 0);
        $reponse_donnee   = trim($_POST['reponse_secrete'] ?? '');
        $nouveau_mdp      = $_POST['nouveau_mot_de_passe'] ?? '';
        $confirmation     = $_POST['confirmation'] ?? '';

        $stmt = $pdo->prepare('SELECT * FROM employes WHERE id = :id');
        $stmt->execute(['id' => $employe_id]);
        $employe = $stmt->fetch();

        if (!$employe || empty($employe['reponse_secrete_hash'])) {
            $erreur = 'Session expirée, merci de recommencer.';
            $etape  = 1;
        } elseif (!password_verify(mb_strtolower($reponse_donnee), $employe['reponse_secrete_hash'])) {
            $erreur = 'Réponse incorrecte.';
            $etape  = 2;
        } elseif (strlen($nouveau_mdp) < 6) {
            $erreur = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
            $etape  = 2;
        } elseif ($nouveau_mdp !== $confirmation) {
            $erreur = 'La confirmation ne correspond pas au nouveau mot de passe.';
            $etape  = 2;
        } else {
            $pdo->prepare(
                'UPDATE employes SET password_hash = :ph, tentatives_echouees = 0, verrouille_jusqua = NULL WHERE id = :id'
            )->execute(['ph' => password_hash($nouveau_mdp, PASSWORD_DEFAULT), 'id' => $employe_id]);
            $succes = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mot de passe oublié - Gestion de courrier DSG</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="card login-card shadow p-4">
        <div class="text-center mb-3">
            <i class="bi bi-shield-lock-fill" style="font-size:2.5rem;color:#1e3c72;"></i>
            <h4 class="mt-2">Mot de passe oublié</h4>
        </div>

        <?php if ($erreur): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($erreur) ?></div>
        <?php endif; ?>

        <?php if ($succes): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> Mot de passe changé avec succès.</div>
            <a href="index.php" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Aller à la connexion</a>

        <?php elseif ($etape === 1): ?>
            <p class="text-muted small">Indiquez votre nom d'utilisateur pour récupérer votre question de sécurité.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="etape" value="1">
                <div class="mb-3">
                    <label class="form-label">Nom d'utilisateur</label>
                    <input type="text" name="nom_utilisateur" class="form-control" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Continuer</button>
            </form>

        <?php else: ?>
            <p class="text-muted small">Répondez à votre question de sécurité :</p>
            <p class="fw-semibold">« <?= htmlspecialchars($employe['question_secrete']) ?> »</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="etape" value="2">
                <input type="hidden" name="employe_id" value="<?= (int) $employe['id'] ?>">
                <div class="mb-3">
                    <label class="form-label">Votre réponse</label>
                    <input type="text" name="reponse_secrete" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <div class="champ-mdp-wrapper">
                        <label class="form-label">Nouveau mot de passe</label>
                        <input type="password" name="nouveau_mot_de_passe" id="mdpOublie1" class="form-control" required minlength="6">
                        <button type="button" class="champ-mdp-toggle" data-cible="mdpOublie1" style="top:70%;"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="champ-mdp-wrapper">
                        <label class="form-label">Confirmer le mot de passe</label>
                        <input type="password" name="confirmation" id="mdpOublie2" class="form-control" required minlength="6">
                        <button type="button" class="champ-mdp-toggle" data-cible="mdpOublie2" style="top:70%;"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Changer le mot de passe</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="index.php" class="small text-muted">&laquo; Retour à la connexion</a>
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
