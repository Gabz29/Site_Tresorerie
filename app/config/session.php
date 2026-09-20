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
        'id'      => $_SESSION['user_id'],
        'email'   => $_SESSION['email']   ?? '',
        'role'    => $_SESSION['role']    ?? '',
        'prenom'  => $_SESSION['prenom']  ?? '',
        'nom'     => $_SESSION['nom']     ?? '',
        'club_id' => $_SESSION['club_id'] ?? null,
    ];
}

/**
 * Interdit l'accès à la page si personne n'est connecté.
 *
 * À appeler en PREMIÈRE ligne de toute action réservée. Sera mise en place
 * page par page à l'étape B de la tâche 2.3.
 */
function exiger_connexion(): void
{
    if (!est_connecte()) {
        rediriger('?page=login');
    }
}
