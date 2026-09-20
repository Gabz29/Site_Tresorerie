<?php
declare(strict_types=1);

require_once __DIR__ . '/fonctions.php';

/**
 * ============================================================================
 *  SESSION ET ÉTAT DE CONNEXION
 * ============================================================================
 *
 *  Rappel du mécanisme : HTTP n'a pas de mémoire. Chaque requête est
 *  indépendante des autres, et le serveur ne sait pas d'une page à l'autre
 *  qui il a en face de lui.
 *
 *  La session résout cela : le serveur conserve un fichier de données sur
 *  SON disque (ici c:/wamp64/tmp/sess_xxx) et confie au navigateur un simple
 *  numéro, transporté par un cookie. À chaque requête, le navigateur présente
 *  le numéro, le serveur retrouve le fichier.
 *
 *  Point important : les données (identité, rôle) restent SUR LE SERVEUR.
 *  Un visiteur qui modifie ses cookies peut changer son numéro, mais ne peut
 *  pas se déclarer trésorier.
 * ============================================================================
 */

/**
 * Démarre la session, avec des cookies correctement configurés.
 * Appelée une seule fois, au tout début de public/index.php.
 */
function demarrer_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Sommes-nous en HTTPS ? En local c'est du http simple, sur le serveur
    // ISEN ce sera probablement du https : on détecte au lieu de figer.
    $enHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        // Le cookie devient invisible pour JavaScript. Si une faille XSS
        // passait malgré tout, le numéro de session ne pourrait pas être
        // volé par un script injecté dans la page.
        'httponly' => true,

        // Le cookie n'est pas envoyé quand la requête vient d'un AUTRE site.
        // Protège contre le CSRF : un site malveillant ne peut pas déclencher
        // d'action en ton nom en s'appuyant sur ta session ouverte.
        'samesite' => 'Lax',

        // En HTTPS, le cookie ne circule jamais en clair.
        'secure'   => $enHttps,
    ]);

    // Refuse un numéro de session que le serveur n'a pas lui-même émis.
    // Deuxième barrière contre la fixation de session.
    ini_set('session.use_strict_mode', '1');

    session_start();
}

/**
 * Y a-t-il un utilisateur connecté ?
 */
function est_connecte(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Informations de l'utilisateur connecté, ou null si personne ne l'est.
 *
 * Renvoie ce qui est conservé en session — assez pour l'affichage et les
 * contrôles de droits, sans requête à la base à chaque page.
 *
 * @return array<string,mixed>|null
 */
function utilisateur_courant(): ?array
{
    if (!est_connecte()) {
        return null;
    }

    return [
        'id'       => $_SESSION['user_id'],
        'email'    => $_SESSION['email']    ?? '',
        'role'     => $_SESSION['role']     ?? '',
        'prenom'   => $_SESSION['prenom']   ?? '',
        'nom'      => $_SESSION['nom']      ?? '',
        'club_id'  => $_SESSION['club_id']  ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? false,
    ];
}

/**
 * ============================================================================
 *  DROITS D'ACCÈS
 * ============================================================================
 *
 *  Deux notions DISTINCTES, à ne pas confondre :
 *
 *   - le RÔLE ('bureau' ou 'responsable') = jusqu'où va la vue sur les
 *     finances. 'bureau' est porté par le Président, le Vice-Président et le
 *     Trésorier du BDE : c'est un niveau d'accès, pas un titre.
 *
 *   - la PERMISSION IsAdmin = le droit de gérer les comptes utilisateurs.
 *     Elle s'ajoute à n'importe quel rôle, ce qui évite d'avoir à créer un
 *     second compte au trésorier pour qu'il puisse créer des accès.
 *
 *  ⚠ CES FONCTIONS SONT LA SEULE PROTECTION RÉELLE. Masquer un lien dans le
 *  menu n'empêche personne de taper l'adresse directement dans le navigateur.
 *  Ce qu'on AFFICHE relève du confort ; ce qu'on AUTORISE se vérifie ici,
 *  côté serveur, à chaque page et à chaque action.
 * ============================================================================
 */

/**
 * L'utilisateur fait-il partie du bureau BDE (P, VP, Trésorier) ?
 */
function est_bureau(): bool
{
    return ($_SESSION['role'] ?? '') === 'bureau';
}

/**
 * L'utilisateur peut-il gérer les comptes utilisateurs ?
 */
function est_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}

/**
 * L'utilisateur a-t-il le droit de consulter le détail d'un club ?
 *
 * Le bureau voit tout ; un responsable ne voit que le club dont il a la
 * charge. La LISTE des clubs reste visible de tous — savoir quels clubs
 * existent n'a rien de confidentiel, contrairement à leurs montants.
 */
function peut_voir_club(int $clubId): bool
{
    if (est_bureau()) {
        return true;
    }

    return ($_SESSION['club_id'] ?? null) === $clubId;
}

/**
 * Interrompt la requête si l'utilisateur n'est pas membre du bureau.
 */
function exiger_bureau(): void
{
    if (!est_bureau()) {
        refuser_acces();
    }
}

/**
 * Interrompt la requête si l'utilisateur ne peut pas gérer les comptes.
 */
function exiger_admin(): void
{
    if (!est_admin()) {
        refuser_acces();
    }
}

/**
 * Affiche une page « accès refusé » et arrête tout.
 *
 * 403 et non 404 : la personne est bien identifiée, la page existe, c'est
 * l'autorisation qui manque. Le code HTTP dit lequel des deux problèmes
 * s'est posé.
 */
function refuser_acces(): never
{
    http_response_code(403);
    require dirname(__DIR__) . '/views/errors/403.php';
    exit;
}

/**
 * Interdit l'accès à la page si personne n'est connecté.
 *
 * Appelée depuis le routeur pour toute page non listée comme publique.
 */
function exiger_connexion(): void
{
    if (!est_connecte()) {
        rediriger('?page=login');
    }
}

/**
 * ----------------------------------------------------------------------------
 *  MESSAGES ÉPHÉMÈRES ("flash")
 * ----------------------------------------------------------------------------
 *  Après un enregistrement réussi, on redirige (POST puis GET) — ce qui fait
 *  perdre toutes les variables PHP. Le message « Modifications enregistrées »
 *  doit donc transiter par la session, et disparaître après une seule
 *  lecture : sinon il réapparaîtrait à chaque page.
 */

/**
 * Dépose un message à afficher sur la page suivante.
 *
 * @param string $type 'succes' ou 'erreur' (détermine la couleur affichée)
 */
function message_flash(string $type, string $texte): void
{
    $_SESSION['flash'] = ['type' => $type, 'texte' => $texte];
}

/**
 * Renvoie le message en attente et le supprime.
 *
 * @return array{type:string,texte:string}|null
 */
function lire_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);   // lu une fois, puis effacé

    return $flash;
}

/**
 * ============================================================================
 *  PROTECTION CSRF
 * ============================================================================
 *
 *  LE PROBLÈME. Un visiteur connecté ouvre, dans un autre onglet, une page
 *  malveillante. Celle-ci contient un formulaire caché qui envoie un POST
 *  vers notre application. Le navigateur y joint AUTOMATIQUEMENT le cookie de
 *  session — il le fait toujours, sans se demander qui a déclenché l'envoi.
 *  Côté serveur, la requête paraît donc parfaitement légitime, et l'action
 *  s'exécute au nom de la victime (changement de mot de passe, validation
 *  d'un remboursement...).
 *
 *  L'attaquant n'a pas volé la session : il s'en sert à distance.
 *
 *  LA PARADE. Chaque formulaire embarque un jeton secret et imprévisible,
 *  également conservé en session. À la réception, les deux doivent
 *  correspondre. Le site malveillant sait fabriquer le formulaire, mais ne
 *  peut pas deviner le jeton : sa requête est rejetée.
 *
 *  Le cookie SameSite=Lax bloque déjà l'essentiel de ces attaques. Le jeton
 *  est la seconde barrière — on ne fait pas reposer la sécurité d'une
 *  opération financière sur un seul mécanisme.
 * ============================================================================
 */

/**
 * Renvoie le jeton CSRF de la session, en le créant au premier appel.
 */
function jeton_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        // random_bytes() est un générateur ADAPTÉ À LA CRYPTOGRAPHIE :
        // sa sortie est imprévisible. rand() ou uniqid() ne conviennent pas,
        // leurs valeurs pouvant se deviner à partir des précédentes.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Renvoie le champ caché à insérer dans un formulaire.
 *
 * À placer dans TOUT formulaire en POST, sans exception.
 */
function champ_csrf(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(jeton_csrf()) . '">';
}

/**
 * Vérifie le jeton reçu ; interrompt la requête s'il est absent ou faux.
 */
function verifier_csrf(): void
{
    $recu = $_POST['csrf_token'] ?? '';

    // hash_equals() plutôt que === : elle compare en temps constant, c'est-à-
    // dire qu'elle met le même temps que les chaînes diffèrent au 1er ou au
    // 30e caractère. Une comparaison ordinaire s'arrête à la première
    // différence, et ce écart de durée, mesuré sur des milliers d'essais,
    // permet de reconstituer le jeton caractère par caractère.
    if (!is_string($recu) || !hash_equals(jeton_csrf(), $recu)) {
        http_response_code(400);
        exit('Requête invalide (jeton de sécurité absent ou expiré). Revenez en arrière et réessayez.');
    }
}
