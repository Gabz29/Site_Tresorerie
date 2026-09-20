<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  POINT D'ENTRÉE UNIQUE — LE ROUTEUR
 * ============================================================================
 *
 *  Toutes les pages du site passent par CE SEUL fichier, qui lit le paramètre
 *  ?page=... et appelle le contrôleur correspondant.
 *
 *  POURQUOI UN POINT D'ENTRÉE UNIQUE plutôt qu'un fichier .php par page ?
 *   - un seul endroit où mettre ce qui est commun à toutes les pages
 *     (démarrage de session, vérification des droits, gestion des erreurs) ;
 *   - un seul fichier exposé au web, donc une seule porte à surveiller ;
 *   - les URLs ne trahissent plus l'organisation des fichiers sur le disque.
 *
 *  Flux complet (cf. section "LE FLUX D'UNE REQUÊTE" des notes de projet) :
 *     navigateur -> index.php -> contrôleur -> modèle -> PDO -> MySQL
 *                                     |
 *                                     +-> vue -> HTML renvoyé au navigateur
 * ============================================================================
 */

/**
 * ----------------------------------------------------------------------------
 *  BASE_URL — le préfixe commun à toutes les adresses du site
 * ----------------------------------------------------------------------------
 *  En local, le site vit dans un sous-dossier : http://localhost/jumao/
 *  Un lien écrit en dur "/css/style.css" pointerait vers la racine de
 *  localhost et ne trouverait rien. On calcule donc le préfixe au lieu de
 *  l'écrire, et les vues l'utilisent systématiquement :
 *
 *      <link href="<?= BASE_URL ?>/css/style.css">
 *
 *  Avantage : le même code fonctionne à la racine d'un domaine comme dans un
 *  sous-dossier — utile le jour du déploiement sur le serveur ISEN.
 *
 *  SCRIPT_NAME vaut "/jumao/index.php" -> dirname() donne "/jumao"
 *  À la racine d'un domaine, il vaudrait "/" -> rtrim() donne "" (correct).
 */
define('BASE_URL', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/'));

/**
 * ----------------------------------------------------------------------------
 *  Session
 * ----------------------------------------------------------------------------
 *  Démarrée ici, avant tout affichage : une session pose un cookie, et un
 *  cookie est un en-tête HTTP, qui doit partir AVANT le moindre caractère de
 *  HTML. C'est aussi la raison d'être du point d'entrée unique : ce qui est
 *  commun à toutes les pages se fait à un seul endroit.
 */
require_once __DIR__ . '/../app/config/session.php';

demarrer_session();

/**
 * ----------------------------------------------------------------------------
 *  Quelle page est demandée ?
 * ----------------------------------------------------------------------------
 *  is_string() n'est pas une précaution inutile : une URL de la forme
 *  ?page[]=clubs fait arriver un TABLEAU dans $_GET['page']. Sans ce contrôle,
 *  la comparaison plus bas se comporterait de façon imprévisible.
 */
$page = $_GET['page'] ?? 'clubs';

if (!is_string($page)) {
    $page = '';
}

/**
 * ----------------------------------------------------------------------------
 *  CONTRÔLE D'ACCÈS — tout est protégé, sauf exception explicite
 * ----------------------------------------------------------------------------
 *  ⚠ POURQUOI ICI, ET PAS DANS CHAQUE CONTRÔLEUR ⚠
 *
 *  L'approche intuitive serait d'appeler exiger_connexion() au début de chaque
 *  méthode de contrôleur. Elle a un défaut grave : le jour où l'on ajoute une
 *  page et qu'on oublie la ligne, cette page est LIBREMENT ACCESSIBLE — et
 *  rien ne le signale. Un oubli se traduit par un trou de sécurité silencieux.
 *
 *  Ici, c'est l'inverse : la protection est la règle, l'accès libre est
 *  l'exception, et l'exception doit être écrite. Un oubli donne alors une page
 *  inaccessible — un problème visible, qu'on corrige en dix secondes.
 *
 *  C'est le principe du « sécurisé par défaut » : quand on se trompe, on veut
 *  se tromper du côté le plus fermé.
 */
$pagesPubliques = ['login'];

if (!in_array($page, $pagesPubliques, true)) {
    exiger_connexion();
}

/**
 * ----------------------------------------------------------------------------
 *  AIGUILLAGE — liste blanche des pages autorisées
 * ----------------------------------------------------------------------------
 *  ⚠ POINT DE SÉCURITÉ ESSENTIEL ⚠
 *
 *  La façon intuitive d'écrire un routeur serait :
 *
 *      require '../app/controllers/' . $_GET['page'] . '.php';   // JAMAIS
 *
 *  C'est une faille classique (inclusion de fichier arbitraire) : un visiteur
 *  manipule l'URL et fait charger n'importe quel fichier du serveur.
 *
 *  Ici, chaque page est écrite EXPLICITEMENT. Une valeur qui n'est pas dans
 *  cette liste ne peut rien déclencher : elle tombe dans le "default".
 *  Ajouter une page = ajouter un "case". C'est volontairement manuel.
 */
switch ($page) {

    case 'clubs':
        require_once __DIR__ . '/../app/controllers/ClubController.php';
        (new ClubController())->index();
        break;

    case 'login':
        require_once __DIR__ . '/../app/controllers/AuthController.php';
        // Même adresse, deux comportements selon la méthode HTTP :
        //   GET  = le visiteur demande à voir le formulaire
        //   POST = il vient de l'envoyer
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new AuthController())->login();
        } else {
            (new AuthController())->showLogin();
        }
        break;

    case 'profil':
        require_once __DIR__ . '/../app/controllers/AuthController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new AuthController())->enregistrerProfil();
        } else {
            (new AuthController())->profil();
        }
        break;

    case 'logout':
        require_once __DIR__ . '/../app/controllers/AuthController.php';
        (new AuthController())->logout();
        break;

    default:
        // 404 = "cette adresse n'existe pas". Le code HTTP compte autant que
        // le message : sans lui, le navigateur et les moteurs de recherche
        // croiraient que la page a été trouvée.
        http_response_code(404);
        require __DIR__ . '/../app/views/errors/404.php';
        break;
}
