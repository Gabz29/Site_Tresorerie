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
require_once __DIR__ . '/../app/config/contexte.php';

demarrer_session();

/**
 * ----------------------------------------------------------------------------
 *  Quelle page est demandée ?
 * ----------------------------------------------------------------------------
 *  is_string() n'est pas une précaution inutile : une URL de la forme
 *  ?page[]=clubs fait arriver un TABLEAU dans $_GET['page']. Sans ce contrôle,
 *  la comparaison plus bas se comporterait de façon imprévisible.
 */
// Le tableau de bord est la page d'accueil : c'est la vue d'ensemble
// attendue en arrivant, plutôt que la liste des clubs.
$page = $_GET['page'] ?? 'dashboard';

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
 *  Mot de passe temporaire : changement imposé
 * ----------------------------------------------------------------------------
 *  Après une création de compte ou une réinitialisation, l'administrateur
 *  connaît le mot de passe — il vient de le dicter. Tant qu'il n'a pas été
 *  changé, toute navigation ramène à la page « Mon compte ».
 *
 *  Le contrôle est ici, dans le routeur, et non page par page : un oubli
 *  laisserait sinon une porte ouverte, sans que rien ne le signale. Même
 *  raisonnement que pour exiger_connexion() plus haut.
 */
$pagesToleree = ['profil', 'logout'];

if (est_connecte() && doit_changer_mot_de_passe() && !in_array($page, $pagesToleree, true)) {
    message_flash('erreur', 'Votre mot de passe est temporaire : choisissez-en un nouveau pour continuer.');
    rediriger('?page=profil');
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

    case 'club':
        require_once __DIR__ . '/../app/controllers/ClubController.php';
        (new ClubController())->show();
        break;

    case 'club-nouveau':
        require_once __DIR__ . '/../app/controllers/ClubController.php';
        (new ClubController())->create();
        break;

    case 'club-modifier':
        require_once __DIR__ . '/../app/controllers/ClubController.php';
        (new ClubController())->edit();
        break;

    case 'club-enregistrer':
        require_once __DIR__ . '/../app/controllers/ClubController.php';
        // Cette adresse n'existe qu'en POST : y arriver en GET (par un lien
        // ou un rafraîchissement) n'a aucun sens et ne doit rien déclencher.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=clubs');
        }
        (new ClubController())->save();
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

    case 'dashboard':
        require_once __DIR__ . '/../app/controllers/DashboardController.php';
        (new DashboardController())->index();
        break;

    case 'transactions':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        (new TransactionController())->index();
        break;

    case 'transactions-export':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        (new TransactionController())->export();
        break;

    case 'transaction-nouvelle':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        (new TransactionController())->create();
        break;

    case 'transaction-modifier':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        (new TransactionController())->edit();
        break;

    case 'transaction-enregistrer':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=transactions');
        }
        (new TransactionController())->save();
        break;

    case 'transaction-statut':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=transactions');
        }
        (new TransactionController())->changerStatut();
        break;

    case 'justificatif':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        (new TransactionController())->justificatif();
        break;

    case 'justificatif-supprimer':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=transactions');
        }
        (new TransactionController())->supprimerJustificatif();
        break;

    case 'transaction-supprimer':
        require_once __DIR__ . '/../app/controllers/TransactionController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=transactions');
        }
        (new TransactionController())->delete();
        break;

    case 'remboursements':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        (new ReimbursementController())->index();
        break;

    case 'remboursement-nouveau':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        (new ReimbursementController())->create();
        break;

    case 'remboursement-modifier':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        (new ReimbursementController())->edit();
        break;

    case 'remboursement-enregistrer':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=remboursements');
        }
        (new ReimbursementController())->save();
        break;

    case 'remboursement-justificatif':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        (new ReimbursementController())->justificatif();
        break;

    case 'remboursement-decider':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=remboursements');
        }
        (new ReimbursementController())->decider();
        break;

    case 'remboursement-payer':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=remboursements');
        }
        (new ReimbursementController())->payer();
        break;

    case 'remboursement-supprimer':
        require_once __DIR__ . '/../app/controllers/ReimbursementController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=remboursements');
        }
        (new ReimbursementController())->delete();
        break;

    case 'budgets':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        (new BudgetController())->index();
        break;

    case 'budget':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        (new BudgetController())->show();
        break;

    case 'budget-nouveau':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        (new BudgetController())->create();
        break;

    case 'budget-enregistrer':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=budgets');
        }
        (new BudgetController())->save();
        break;

    case 'tranche-versee':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=budgets');
        }
        (new BudgetController())->marquerRecu();
        break;

    case 'tranche-annulee':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=budgets');
        }
        (new BudgetController())->annulerTranche();
        break;

    case 'tranche-rouverte':
        require_once __DIR__ . '/../app/controllers/BudgetController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=budgets');
        }
        (new BudgetController())->rouvrirTranche();
        break;

    case 'exercice-consulter':
        // Changer l'exercice qu'on REGARDE. Accessible à tous — cela ne
        // modifie aucune donnée, seulement l'affichage de sa propre session.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=clubs');
        }
        verifier_csrf();
        definir_exercice_consulte((int) ($_POST['exercice'] ?? 0));

        // Retour sur la page d'où venait la demande, pour ne pas renvoyer
        // l'utilisateur à l'accueil à chaque changement d'exercice.
        $retour = $_POST['retour'] ?? '?page=clubs';
        rediriger(is_string($retour) && str_starts_with($retour, '?page=') ? $retour : '?page=clubs');
        break;

    case 'exercices':
        require_once __DIR__ . '/../app/controllers/FiscalYearController.php';
        (new FiscalYearController())->index();
        break;

    case 'exercice-creer':
        require_once __DIR__ . '/../app/controllers/FiscalYearController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=exercices');
        }
        (new FiscalYearController())->save();
        break;

    case 'exercice-activer':
        require_once __DIR__ . '/../app/controllers/FiscalYearController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=exercices');
        }
        (new FiscalYearController())->activate();
        break;

    case 'utilisateurs':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        (new AdminController())->index();
        break;

    case 'utilisateur-nouveau':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        (new AdminController())->create();
        break;

    case 'utilisateur-modifier':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        (new AdminController())->edit();
        break;

    case 'utilisateur-enregistrer':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=utilisateurs');
        }
        (new AdminController())->save();
        break;

    case 'utilisateur-reinitialiser':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=utilisateurs');
        }
        (new AdminController())->reinitialiser();
        break;

    case 'references':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        (new AdminController())->references();
        break;

    case 'categorie-enregistrer':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=references');
        }
        (new AdminController())->enregistrerCategorie();
        break;

    case 'pole-enregistrer':
        require_once __DIR__ . '/../app/controllers/AdminController.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            rediriger('?page=references');
        }
        (new AdminController())->enregistrerPole();
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
