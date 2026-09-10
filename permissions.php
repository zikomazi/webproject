<?php
/**
 * permissions.php
 * Contrôle d'accès pour les 3 rôles : administrateur, secretariat, simple.
 * À inclure après session_timer.php.
 */

function current_user_id(): ?int
{
    return $_SESSION['employe_id'] ?? null;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['employe_id']);
}

function is_admin(): bool
{
    return current_role() === 'administrateur';
}

function is_secretariat(): bool
{
    return current_role() === 'secretariat';
}

function is_simple(): bool
{
    return current_role() === 'simple';
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function require_role(array $roles_autorises): void
{
    require_login();
    if (!in_array(current_role(), $roles_autorises, true)) {
        http_response_code(403);
        die('Accès refusé : vous n\'avez pas les droits nécessaires pour cette page.');
    }
}

/** Le secrétariat et l'administrateur peuvent envoyer un nouveau courrier. */
function can_send_courrier(): bool
{
    return is_admin() || is_secretariat();
}

/** Seul l'administrateur gère les comptes. */
function can_manage_users(): bool
{
    return is_admin();
}

/** Seul l'administrateur modifie la priorité et réaffecte un courrier déjà envoyé. */
function can_modify_priorite(): bool
{
    return is_admin();
}

function can_reassign(): bool
{
    return is_admin();
}

/** Le secrétariat et l'administrateur peuvent enregistrer la référence de réponse. */
function can_add_reponse(): bool
{
    return is_admin() || is_secretariat();
}

/** Un compte simple ne peut voir que les courriers qui lui ont été envoyés. */
function can_view_courrier(PDO $pdo, array $courrier): bool
{
    if (is_admin() || is_secretariat()) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM courrier_destinataires WHERE courrier_id = :cid AND employe_id = :eid');
    $stmt->execute(['cid' => $courrier['id'], 'eid' => current_user_id()]);
    return (bool) $stmt->fetchColumn();
}

/** Libellés lisibles pour l'affichage. */
/**
 * Peut modifier les informations d'un courrier (objet, dates, observations) :
 * administrateur, secrétariat, ou le créateur du courrier.
 */
function can_edit_courrier(array $courrier): bool
{
    if (is_admin() || is_secretariat()) {
        return true;
    }
    return (int) ($courrier['created_by'] ?? 0) === (int) current_user_id();
}

function libelle_role(string $role): string
{
    $labels = [
        'administrateur' => 'Administrateur',
        'secretariat'    => 'Secrétariat',
        'simple'         => 'Agent',
    ];
    return $labels[$role] ?? $role;
}

function libelle_statut(string $statut): string
{
    $labels = [
        'envoye'       => 'Envoyé',
        'ouvert'       => 'Ouvert',
        'en_cours'     => 'En cours',
        'repondu'      => 'Répondu',
        'archive'      => 'Archivé',
        'retard'       => 'En retard',
        'sans_reponse' => 'Sans réponse requise',
    ];
    return $labels[$statut] ?? $statut;
}

function libelle_priorite(string $priorite): string
{
    $labels = [
        'tres_urgent' => 'Très urgent (6h)',
        'urgent'      => 'Urgent (12h)',
        'simple'      => 'Routine (5j)',
    ];
    return $labels[$priorite] ?? $priorite;
}

/** Classe CSS de couleur associée à une priorité (voir assets/style.css). */
function classe_priorite(string $priorite): string
{
    return 'priorite-' . $priorite;
}

function libelle_c9(string $statut): string
{
    return $statut === 'cloture' ? 'Clôturé' : 'En attente';
}

/** Un courrier est en retard s'il dépasse sa date limite et n'a pas de réponse. */
function est_en_retard(array $courrier): bool
{
    if (isset($courrier['reponse_requise']) && !$courrier['reponse_requise']) {
        return false;
    }
    if (empty($courrier['date_limite']) || in_array($courrier['statut'], ['repondu', 'archive'], true)) {
        return false;
    }
    return strtotime($courrier['date_limite']) < time();
}

/**
 * Statut simplifié affiché à l'utilisateur : tant qu'il n'y a pas de
 * réponse enregistrée, le courrier reste "En cours" (peu importe s'il a
 * été ouvert ou non) ; dès qu'une réponse existe -> "Répondu" ; s'il a
 * dépassé son délai et n'a pas de réponse -> "En retard". Un courrier
 * marqué "réponse non requise" est considéré clos dès son envoi.
 */
function statut_affichage(array $courrier, bool $a_reponse): string
{
    if (($courrier['statut'] ?? '') === 'archive') {
        return 'archive';
    }
    if ($a_reponse) {
        return 'repondu';
    }
    if (isset($courrier['reponse_requise']) && !$courrier['reponse_requise']) {
        return 'sans_reponse';
    }
    if (est_en_retard($courrier)) {
        return 'retard';
    }
    return 'en_cours';
}

/** Classe CSS appliquée à la ligne du tableau : foncée si en cours, claire si répondu. */
function classe_ligne_statut(string $statut_affiche): string
{
    if ($statut_affiche === 'retard') {
        return 'ligne-retard';
    }
    if ($statut_affiche === 'repondu') {
        return 'ligne-repondu';
    }
    if ($statut_affiche === 'archive') {
        return 'ligne-archive';
    }
    if ($statut_affiche === 'sans_reponse') {
        return 'ligne-sans-reponse';
    }
    return 'ligne-en-cours';
}
