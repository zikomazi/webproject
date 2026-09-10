<?php
require __DIR__ . '/session_timer.php';
require __DIR__ . '/permissions.php';
require_login();
require_role(['administrateur']);
require __DIR__ . '/connection/connection_bdd.php';
$conn = new connection_bdd();
$pdo  = $conn->getConnection();
require __DIR__ . '/includes/fonctions.php';

$id         = isset($_GET['id']) ? (int) $_GET['id'] : null;
$employe    = null;
$page_title = $id ? 'Modifier un compte' : 'Nouveau compte';

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM employes WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $employe = $stmt->fetch();
    if (!$employe) {
        redirect_with_message('users.php', 'Compte introuvable.', 'danger');
    }
}

$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_utilisateur = trim($_POST['nom_utilisateur'] ?? '');
    $nom             = trim($_POST['nom'] ?? '');
    $prenom          = trim($_POST['prenom'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $telephone       = trim($_POST['telephone'] ?? '');
    $role            = $_POST['role'] ?? 'simple';
    $mot_de_passe    = $_POST['mot_de_passe'] ?? '';

    if ($nom_utilisateur === '' || $nom === '' || $prenom === '') {
        $erreurs[] = 'Nom d\'utilisateur, nom et prénom sont obligatoires.';
    }
    if (!in_array($role, ['administrateur', 'secretariat', 'simple'], true)) {
        $erreurs[] = 'Rôle invalide.';
    }
    if (!$employe && $mot_de_passe === '') {
        $erreurs[] = 'Le mot de passe est obligatoire pour un nouveau compte.';
    }
    if ($mot_de_passe !== '' && strlen($mot_de_passe) < 6) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 6 caractères.';
    }
    // Empêche l'admin de se retirer lui-même son propre rôle d'administrateur
    if ($employe && (int) $employe['id'] === (int) current_user_id() && $role !== 'administrateur') {
        $erreurs[] = 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.';
    }

    if (empty($erreurs)) {
        try {
            if ($employe) {
                if ($mot_de_passe !== '') {
                    $pdo->prepare(
                        'UPDATE employes SET nom_utilisateur = :nu, nom = :nom, prenom = :prenom,
                         email = :email, telephone = :tel, role = :role, password_hash = :ph WHERE id = :id'
                    )->execute([
                        'nu' => $nom_utilisateur, 'nom' => $nom, 'prenom' => $prenom,
                        'email' => $email ?: null, 'tel' => $telephone ?: null, 'role' => $role,
                        'ph' => password_hash($mot_de_passe, PASSWORD_DEFAULT), 'id' => $employe['id'],
                    ]);
                } else {
                    $pdo->prepare(
                        'UPDATE employes SET nom_utilisateur = :nu, nom = :nom, prenom = :prenom,
                         email = :email, telephone = :tel, role = :role WHERE id = :id'
                    )->execute([
                        'nu' => $nom_utilisateur, 'nom' => $nom, 'prenom' => $prenom,
                        'email' => $email ?: null, 'tel' => $telephone ?: null, 'role' => $role,
                        'id' => $employe['id'],
                    ]);
                }
                redirect_with_message('users.php', 'Compte mis à jour avec succès.');
            } else {
                $pdo->prepare(
                    'INSERT INTO employes (nom_utilisateur, password_hash, nom, prenom, email, telephone, role)
                     VALUES (:nu, :ph, :nom, :prenom, :email, :tel, :role)'
                )->execute([
                    'nu' => $nom_utilisateur, 'ph' => password_hash($mot_de_passe, PASSWORD_DEFAULT),
                    'nom' => $nom, 'prenom' => $prenom, 'email' => $email ?: null,
                    'tel' => $telephone ?: null, 'role' => $role,
                ]);
                redirect_with_message('users.php', 'Compte créé avec succès.');
            }
        } catch (PDOException $e) {
            $erreurs[] = 'Ce nom d\'utilisateur existe déjà.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<h3 class="mb-4"><i class="bi bi-person-<?= $employe ? 'gear' : 'plus' ?>"></i> <?= htmlspecialchars($page_title) ?></h3>

<?php if ($erreurs): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($erreurs as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="card p-4" style="max-width:600px;">
            <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nom *</label>
            <input type="text" name="nom" class="form-control" required value="<?= htmlspecialchars($employe['nom'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Prénom *</label>
            <input type="text" name="prenom" class="form-control" required value="<?= htmlspecialchars($employe['prenom'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Nom d'utilisateur *</label>
            <input type="text" name="nom_utilisateur" class="form-control" required value="<?= htmlspecialchars($employe['nom_utilisateur'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Mot de passe <?= $employe ? '(laisser vide pour ne pas modifier)' : '*' ?></label>
            <div class="champ-mdp-wrapper">
                <input type="password" name="mot_de_passe" id="mdpUserForm" class="form-control" <?= $employe ? '' : 'required' ?>>
                <button type="button" class="champ-mdp-toggle" data-cible="mdpUserForm"><i class="bi bi-eye"></i></button>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($employe['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Téléphone</label>
            <input type="text" name="telephone" class="form-control" value="<?= htmlspecialchars($employe['telephone'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Rôle *</label>
            <select name="role" class="form-select" required <?= ($employe && (int) $employe['id'] === (int) current_user_id()) ? 'disabled' : '' ?>>
                <option value="administrateur" <?= (($employe['role'] ?? '') === 'administrateur') ? 'selected' : '' ?>>Administrateur — gère tout, comptes, priorités, affectations</option>
                <option value="secretariat" <?= (($employe['role'] ?? '') === 'secretariat') ? 'selected' : '' ?>>Secrétariat — envoie les courriers, enregistre les réponses</option>
                <option value="simple" <?= (($employe['role'] ?? 'simple') === 'simple') ? 'selected' : '' ?>>Agent (compte simple) — consulte et traite les courriers reçus</option>
            </select>
            <?php if ($employe && (int) $employe['id'] === (int) current_user_id()): ?>
                <input type="hidden" name="role" value="administrateur">
                <div class="form-text">Vous ne pouvez pas changer votre propre rôle.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Enregistrer</button>
        <a href="users.php" class="btn btn-outline-secondary">Annuler</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
