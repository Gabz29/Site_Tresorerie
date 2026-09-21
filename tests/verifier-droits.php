<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  VÉRIFICATION AUTOMATIQUE DES DROITS D'ACCÈS
 * ============================================================================
 *
 *  À lancer en ligne de commande, WAMP démarré :
 *      php tests/verifier-droits.php
 *
 *  POURQUOI CE SCRIPT ALORS QUE LA TÂCHE 2.11 PARLE DE TESTS MANUELS.
 *  La matrice « chaque page × chaque rôle » compte plus d'une centaine de
 *  cas. La parcourir à la main une fois est déjà fastidieux ; la refaire
 *  après chaque modification ne se produira jamais. Or c'est précisément ce
 *  qu'il faudrait : une page ajoutée sans protection ne se voit pas, elle
 *  s'ouvre simplement à tout le monde.
 *
 *  Ce script ne remplace pas les tests manuels : il décharge de ce qui est
 *  mécanique (qui a le droit d'ouvrir quoi) pour laisser du temps à ce
 *  qu'une machine ne sait pas juger — la clarté des messages, la cohérence
 *  des parcours, les cas métier tordus.
 *
 *  Il n'écrit RIEN en base : il ne fait qu'ouvrir des pages en lecture.
 * ============================================================================
 */

require_once __DIR__ . '/../app/config/database.php';

// L'adresse vient du .env : ces scripts doivent fonctionner sur une autre
// machine, où le site n'est pas forcément servi sous /jumao.
define('BASE', rtrim((string) env('APP_URL', 'http://localhost/jumao'), '/') . '/index.php');

// Mot de passe commun aux comptes du seed — données de DÉVELOPPEMENT.
const MDP     = 'password123';
const DOSSIER = __DIR__ . '/.cookies';

/** Profils testés, et leur email de connexion. */
const PROFILS = [
    'anonyme'     => null,
    'responsable' => 'camille.martin@example.com',  // club 2
    'bureau'      => 'alex.bernard@example.com',    // sans droit admin
    'admin'       => 'jean.dupont@example.com',     // bureau + admin
];

/**
 * Attendu pour chaque page, par profil.
 *   200 = accessible   403 = refusé   302 = redirigé (connexion ou renvoi)
 */
const ATTENDUS = [
    // page                        anonyme  responsable  bureau  admin
    'dashboard'                => [302,     200,         200,    200],
    'clubs'                    => [302,     200,         200,    200],
    'club&id=2'                => [302,     200,         200,    200],
    'club&id=1'                => [302,     403,         200,    200],
    'club-nouveau'             => [302,     403,         200,    200],
    'club-modifier&id=2'       => [302,     403,         200,    200],
    'transactions'             => [302,     200,         200,    200],
    'transaction-nouvelle'     => [302,     200,         200,    200],
    'transaction-modifier&id=1'=> [302,     200,         200,    200],
    'transaction-modifier&id=2'=> [302,     403,         200,    200],
    'transactions-export'      => [302,     200,         200,    200],
    'remboursements'           => [302,     200,         200,    200],
    'remboursement-nouveau'    => [302,     200,         200,    200],
    // Demande nº1 : en attente et sur le club 2 -> Camille peut la modifier.
    'remboursement-modifier&id=1' => [302,  200,         200,    200],
    // Demande nº2 : déjà ACCEPTÉE, donc figée, et sur un autre club.
    // Camille reçoit 403 (mauvais club, vérifié en premier) ; le bureau est
    // redirigé avec « cette demande a déjà été traitée ». Les deux sont le
    // comportement voulu — c'est l'attendu initial de ce script qui était
    // faux, pas le code.
    'remboursement-modifier&id=2' => [302,  403,         302,    302],
    'budgets'                  => [302,     403,         200,    200],
    'budget&id=1'              => [302,     403,         200,    200],
    'budget-nouveau'           => [302,     403,         200,    200],
    'exercices'                => [302,     403,         200,    200],
    'utilisateurs'             => [302,     403,         403,    200],
    'utilisateur-nouveau'      => [302,     403,         403,    200],
    'utilisateur-modifier&id=1'=> [302,     403,         403,    200],
    'references'               => [302,     403,         403,    200],
    'profil'                   => [302,     200,         200,    200],
    'page-qui-nexiste-pas'     => [302,     404,         404,    404],
];

/**
 * URL d'action, qui ne doivent RIEN faire en GET : elles doivent rediriger.
 * On vérifie ici qu'aucune n'exécute son traitement sur un simple lien.
 */
const ACTIONS_POST_SEULEMENT = [
    'club-enregistrer', 'transaction-enregistrer', 'transaction-statut',
    'transaction-supprimer', 'justificatif-supprimer', 'remboursement-enregistrer',
    'remboursement-decider', 'remboursement-payer', 'remboursement-supprimer',
    'budget-enregistrer', 'tranche-versee', 'tranche-annulee', 'tranche-rouverte',
    'exercice-creer', 'exercice-activer', 'exercice-consulter',
    'utilisateur-enregistrer', 'utilisateur-reinitialiser',
    'categorie-enregistrer', 'pole-enregistrer',
];

// ---------------------------------------------------------------------------

if (!is_dir(DOSSIER)) {
    mkdir(DOSSIER, 0755, true);
}

$echecs = 0;
$total  = 0;

/**
 * Ouvre une page et renvoie le code HTTP.
 */
function ouvrir(string $page, ?string $cookies): int
{
    $ch = curl_init(BASE . '?page=' . $page);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // on veut voir la redirection
        CURLOPT_NOBODY         => false,
    ]);

    if ($cookies !== null) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookies);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookies);
    }

    $corps = (string) curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Une page d'erreur PHP renvoie 200 : on la signale à part, sinon un
    // plantage passerait pour un succès.
    if (stripos($corps, 'Fatal error') !== false || stripos($corps, 'xdebug-error') !== false) {
        return 500;
    }

    return $code;
}

/**
 * Ouvre une session pour un profil et renvoie son fichier de cookies.
 */
function connecter(?string $email): ?string
{
    if ($email === null) {
        return null;   // profil anonyme
    }

    $cookies = DOSSIER . '/' . md5($email) . '.txt';
    @unlink($cookies);

    $ch = curl_init(BASE . '?page=login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
    ]);
    curl_exec($ch);
    curl_close($ch);

    $ch = curl_init(BASE . '?page=login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_POSTFIELDS     => http_build_query(['email' => $email, 'password' => MDP]),
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 302) {
        echo "  ⚠ Connexion impossible pour {$email} (HTTP {$code})\n";
        echo "    La base est-elle à jour ? Relancez sql/seed.sql.\n";
        exit(1);
    }

    return $cookies;
}

// ---------------------------------------------------------------------------

echo "VÉRIFICATION DES DROITS D'ACCÈS\n";
echo str_repeat('=', 78), "\n\n";

$sessions = [];
foreach (PROFILS as $nom => $email) {
    $sessions[$nom] = connecter($email);
}

printf("%-30s %-10s %-12s %-9s %s\n", 'PAGE', 'anonyme', 'responsable', 'bureau', 'admin');
echo str_repeat('-', 78), "\n";

foreach (ATTENDUS as $page => $attendus) {
    $ligne   = sprintf('%-30s', $page);
    $profils = array_keys(PROFILS);

    foreach ($profils as $i => $profil) {
        $total++;
        $obtenu  = ouvrir($page, $sessions[$profil]);
        $attendu = $attendus[$i];
        $ok      = $obtenu === $attendu;

        if (!$ok) {
            $echecs++;
        }

        $largeur = [10, 12, 9, 6][$i];
        $texte   = $ok ? (string) $obtenu : "{$obtenu}!={$attendu}";
        $ligne  .= sprintf('%-' . $largeur . 's ', $texte);
    }

    echo $ligne, "\n";
}

echo "\n", str_repeat('=', 78), "\n";
echo "URL D'ACTION APPELÉES EN GET (aucune ne doit agir : redirection attendue)\n";
echo str_repeat('-', 78), "\n";

foreach (ACTIONS_POST_SEULEMENT as $action) {
    $total++;
    $code = ouvrir($action, $sessions['admin']);
    $ok   = $code === 302;

    if (!$ok) {
        $echecs++;
    }

    printf("  %-32s %s\n", $action, $ok ? 'redirigé' : "PROBLÈME : HTTP {$code}");
}

echo "\n", str_repeat('=', 78), "\n";

if ($echecs === 0) {
    echo "✅ {$total} vérifications, aucun écart.\n";
    exit(0);
}

echo "❌ {$echecs} écart(s) sur {$total} vérifications.\n";
echo "   Format « obtenu!=attendu ». 500 signale une erreur PHP dans la page.\n";
exit(1);
