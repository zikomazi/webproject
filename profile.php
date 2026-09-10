<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$page_title = 'Mon profil';

$stmt = $pdo->prepare('SELECT * FROM employes WHERE id = :id');
$stmt->execute(['id' => current_user_id()]);
$moi = $stmt->fetch();

if (!$moi) {
    header('Location: logout.php');
    exit;
}

$erreurs_info = [];
$erreurs_pwd  = [];

// ---------------- Formulaire 1 : informations personnelles ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'informations') {
    csrf_verify();
    $nom_utilisateur = trim($_POST['nom_utilisateur'] ?? '');
    $nom             = trim($_POST['nom'] ?? '');
    $prenom          = trim($_POST['prenom'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $telephone       = trim($_POST['telephone'] ?? '');

    if ($nom_utilisateur === '' || $nom === '' || $prenom === '') {
        $erreurs_info[] = 'Nom d\'utilisateur, nom et prénom sont obligatoires.';
    }

    if (empty($erreurs_info)) {
        try {
            $pdo->prepare(
                'UPDATE employes SET nom_utilisateur = :nu, nom = :nom, prenom = :prenom,
                 email = :email, telephone = :tel WHERE id = :id'
            )->execute([
                'nu' => $nom_utilisateur, 'nom' => $nom, 'prenom' => $prenom,
                'email' => $email ?: null, 'tel' => $telephone ?: null, 'id' => current_user_id(),
            ]);

            // Met à jour la session immédiatement (navbar, etc.)
            $_SESSION['nom_utilisateur'] = $nom_utilisateur;
            $_SESSION['nom']             = $nom;
            $_SESSION['prenom']          = $prenom;

            redirect_with_message('profile.php', 'Vos informations ont été mises à jour.');
        } catch (PDOException $e) {
            $erreurs_info[] = 'Ce nom d\'utilisateur est déjà utilisé par un autre compte.';
        }
    }
}

// ---------------- Formulaire 2 : changer le mot de passe ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'mot_de_passe') {
    csrf_verify();
    $mot_de_passe_actuel = $_POST['mot_de_passe_actuel'] ?? '';
    $nouveau_mdp          = $_POST['nouveau_mot_de_passe'] ?? '';
    $confirmation          = $_POST['confirmation'] ?? '';

    if (!password_verify($mot_de_passe_actuel, $moi['password_hash'])) {
        $erreurs_pwd[] = 'Le mot de passe actuel est incorrect.';
    }
    if (strlen($nouveau_mdp) < 6) {
        $erreurs_pwd[] = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
    }
    if ($nouveau_mdp !== $confirmation) {
        $erreurs_pwd[] = 'La confirmation ne correspond pas au nouveau mot de passe.';
    }

    if (empty($erreurs_pwd)) {
        $pdo->prepare('UPDATE employes SET password_hash = :ph WHERE id = :id')
            ->execute(['ph' => password_hash($nouveau_mdp, PASSWORD_DEFAULT), 'id' => current_user_id()]);
        redirect_with_message('profile.php', 'Votre mot de passe a été changé avec succès.');
    }
}

// ---------------- Formulaire 3 : question secrète (pour "mot de passe oublié") ----------------
$erreurs_question = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'question_secrete') {
    csrf_verify();
    $question = trim($_POST['question_secrete'] ?? '');
    $reponse  = trim($_POST['reponse_secrete'] ?? '');

    if ($question === '' || $reponse === '') {
        $erreurs_question[] = 'La question et la réponse sont obligatoires.';
    }

    if (empty($erreurs_question)) {
        $pdo->prepare('UPDATE employes SET question_secrete = :q, reponse_secrete_hash = :r WHERE id = :id')
            ->execute([
                'q' => $question,
                'r' => password_hash(mb_strtolower($reponse), PASSWORD_DEFAULT),
                'id' => current_user_id(),
            ]);
        redirect_with_message('profile.php', 'Votre question de sécurité a été enregistrée.');
    }
}

require __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4"><i class="bi bi-person-circle"></i> Mon profil</h3>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-person-lines-fill"></i> Mes informations</h6>
            <?php if ($erreurs_info): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($erreurs_info as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <form method="post">
            <?= csrf_field() ?>
                <input type="hidden" name="form" value="informations">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="nom" class="form-control" required value="<?= htmlspecialchars($moi['nom']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Prénom *</label>
                        <input type="text" name="prenom" class="form-control" required value="<?= htmlspecialchars($moi['prenom']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nom d'utilisateur *</label>
                        <input type="text" name="nom_utilisateur" class="form-control" required value="<?= htmlspecialchars($moi['nom_utilisateur']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($moi['email'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Téléphone</label>
                        <input type="text" name="telephone" class="form-control" value="<?= htmlspecialchars($moi['telephone'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Rôle</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(libelle_role($moi['role'])) ?>" disabled>
                        <div class="form-text">Seul un administrateur peut changer votre rôle.</div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-circle"></i> Enregistrer</button>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4">
            <h6 class="mb-3"><i class="bi bi-shield-lock-fill"></i> Changer le mot de passe</h6>
            <?php if ($erreurs_pwd): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($erreurs_pwd as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <form method="post">
            <?= csrf_field() ?>
                <input type="hidden" name="form" value="mot_de_passe">
                <div class="mb-3">
                    <label class="form-label">Mot de passe actuel *</label>
                    <div class="champ-mdp-wrapper">
                        <input type="password" name="mot_de_passe_actuel" id="mdpActuel" class="form-control" required>
                        <button type="button" class="champ-mdp-toggle" data-cible="mdpActuel"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nouveau mot de passe *</label>
                    <div class="champ-mdp-wrapper">
                        <input type="password" name="nouveau_mot_de_passe" id="mdpNouveau" class="form-control" required minlength="6">
                        <button type="button" class="champ-mdp-toggle" data-cible="mdpNouveau"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirmer le nouveau mot de passe *</label>
                    <div class="champ-mdp-wrapper">
                        <input type="password" name="confirmation" id="mdpConfirmation" class="form-control" required minlength="6">
                        <button type="button" class="champ-mdp-toggle" data-cible="mdpConfirmation"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Changer le mot de passe</button>
            </form>
        </div>

        <div class="card p-4 mt-3">
            <h6 class="mb-3"><i class="bi bi-question-circle-fill"></i> Question de sécurité</h6>
            <p class="text-muted small">Utilisée pour réinitialiser votre mot de passe si vous l'oubliez, sans passer par l'administrateur.</p>
            <?php if ($erreurs_question): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($erreurs_question as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <?php if ($moi['question_secrete']): ?>
                <div class="alert alert-success py-2 small mb-3"><i class="bi bi-check-circle"></i> Question déjà configurée : « <?= htmlspecialchars($moi['question_secrete']) ?> »</div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="question_secrete">
                <div class="mb-3">
                    <label class="form-label">Votre question *</label>
                    <input type="text" name="question_secrete" class="form-control" required maxlength="255"
                           placeholder="Ex : Quel est le nom de mon premier animal ?"
                           value="<?= htmlspecialchars($moi['question_secrete'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Votre réponse *</label>
                    <input type="text" name="reponse_secrete" class="form-control" required maxlength="255" placeholder="Nouvelle réponse (remplace l'ancienne si déjà configurée)">
                </div>
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-check-circle"></i> Enregistrer</button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
