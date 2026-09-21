<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  VÉRIFICATION DES ACTIONS QUI ÉCRIVENT
 * ============================================================================
 *
 *      php tests/verifier-ecritures.php
 *
 *  Complète verifier-droits.php, qui ne fait qu'ouvrir des pages. Ici on
 *  ENVOIE des formulaires, pour contrôler que :
 *    - un POST sans jeton CSRF est rejeté (400) ;
 *    - un profil sans les droits est refusé (403) même en envoyant la
 *      requête directement, sans passer par l'interface ;
 *    - un responsable qui force un autre club voit sa valeur écrasée.
 *
 *  ⚠ CE SCRIPT ÉCRIT EN BASE. Il nettoie derrière lui, mais à n'utiliser
 *  qu'en local, jamais sur le serveur ISEN.
 * ============================================================================
 */

require_once __DIR__ . '/../app/config/database.php';

// L'adresse vient du .env : ces scripts doivent fonctionner sur une autre
// machine, où le site n'est pas forcément servi sous /jumao.
define('BASE', rtrim((string) env('APP_URL', 'http://localhost/jumao'), '/') . '/index.php');

// Mot de passe commun aux comptes du seed — données de DÉVELOPPEMENT.
const MDP     = 'password123';
const DOSSIER = __DIR__ . '/.cookies';

$echecs = 0;
$total  = 0;

if (!is_dir(DOSSIER)) {
    mkdir(DOSSIER, 0755, true);
}

function session(string $email): string
{
    $cookies = DOSSIER . '/w_' . md5($email) . '.txt';
    @unlink($cookies);

    appel(BASE . '?page=login', null, $cookies);
    appel(BASE . '?page=login', ['email' => $email, 'password' => MDP], $cookies);

    return $cookies;
}

/**
 * @param array<string,mixed>|null $post
 * @return array{code:int,corps:string}
 */
function appel(string $url, ?array $post, string $cookies): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $corps = (string) curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'corps' => $corps];
}

function jeton(string $page, string $cookies): string
{
    preg_match('#name="csrf_token" value="([^"]*)"#', appel(BASE . "?page=$page", null, $cookies)['corps'], $m);

    return $m[1] ?? '';
}

function verifier(string $libelle, bool $ok, string $detail = ''): void
{
    global $echecs, $total;
    $total++;

    if (!$ok) {
        $echecs++;
    }

    printf("  %-52s %s%s\n", $libelle, $ok ? 'ok' : 'ÉCHEC', $detail !== '' ? "  ({$detail})" : '');
}

// ---------------------------------------------------------------------------

echo "VÉRIFICATION DES ACTIONS QUI ÉCRIVENT\n";
echo str_repeat('=', 78), "\n\n";

$admin       = session('jean.dupont@example.com');
$bureau      = session('alex.bernard@example.com');
$responsable = session('camille.martin@example.com');

echo "JETON CSRF ABSENT — toute écriture doit être rejetée en 400\n";
echo str_repeat('-', 78), "\n";

$sansJeton = [
    'club-enregistrer'        => ['nom' => 'Pirate'],
    'transaction-enregistrer' => ['type' => 'depense', 'montant' => '10'],
    'remboursement-decider'   => ['id' => 1, 'statut' => 'valide'],
    'budget-enregistrer'      => ['club' => 2, 'montant' => '100'],
    'exercice-activer'        => ['id' => 1],
    'utilisateur-enregistrer' => ['prenom' => 'X', 'nom' => 'Y'],
    'categorie-enregistrer'   => ['nom' => 'Pirate', 'type' => 'depense'],
    'pole-enregistrer'        => ['nom' => 'Pirate'],
];

foreach ($sansJeton as $action => $donnees) {
    $r = appel(BASE . "?page=$action", $donnees, $admin);
    verifier($action, $r['code'] === 400, "HTTP {$r['code']}");
}

echo "\nDROITS INSUFFISANTS — requête envoyée directement, sans l'interface\n";
echo str_repeat('-', 78), "\n";

// Un membre du bureau sans droit admin tente de gérer les comptes.
$r = appel(BASE . '?page=utilisateur-enregistrer', [
    'csrf_token' => jeton('profil', $bureau),
    'id' => 0, 'prenom' => 'Pirate', 'nom' => 'Pirate',
    'email' => 'pirate@isen.fr', 'role' => 'bureau', 'actif' => '1',
], $bureau);
verifier('bureau sans admin -> créer un compte', $r['code'] === 403, "HTTP {$r['code']}");

$r = appel(BASE . '?page=categorie-enregistrer', [
    'csrf_token' => jeton('profil', $bureau),
    'id' => 0, 'nom' => 'Pirate', 'type' => 'depense',
], $bureau);
verifier('bureau sans admin -> créer une catégorie', $r['code'] === 403, "HTTP {$r['code']}");

// Un responsable tente des actions réservées au bureau.
$r = appel(BASE . '?page=club-enregistrer', [
    'csrf_token' => jeton('profil', $responsable),
    'id' => 0, 'nom' => 'Club Pirate', 'actif' => '1',
], $responsable);
verifier('responsable -> créer un club', $r['code'] === 403, "HTTP {$r['code']}");

$r = appel(BASE . '?page=remboursement-decider', [
    'csrf_token' => jeton('profil', $responsable),
    'id' => 1, 'statut' => 'valide',
], $responsable);
verifier('responsable -> accepter un remboursement', $r['code'] === 403, "HTTP {$r['code']}");

$r = appel(BASE . '?page=transaction-supprimer', [
    'csrf_token' => jeton('profil', $responsable),
    'id' => 1,
], $responsable);
verifier('responsable -> supprimer une transaction', $r['code'] === 403, "HTTP {$r['code']}");

$r = appel(BASE . '?page=exercice-activer', [
    'csrf_token' => jeton('profil', $responsable),
    'id' => 1,
], $responsable);
verifier('responsable -> changer l\'exercice actif', $r['code'] === 403, "HTTP {$r['code']}");

echo "\nCLUB IMPOSÉ — un responsable ne peut pas écrire sur un autre club\n";
echo str_repeat('-', 78), "\n";

$libelle = 'Test cloisonnement ' . uniqid();

appel(BASE . '?page=transaction-enregistrer', [
    'csrf_token'  => jeton('transaction-nouvelle', $responsable),
    'id' => 0, 'type' => 'depense', 'montant' => '1.00',
    'date' => date('Y-m-d'), 'description' => $libelle,
    'club' => 1,            // il tente le club du BDE
    'pole' => 1, 'categorie' => 9, 'moyen' => 'carte', 'statut' => 'valide',
], $responsable);

// db() lit le .env, comme le reste de l'application : aucun identifiant
// en dur dans un fichier versionné.
$pdo = db();
$stmt = $pdo->prepare('SELECT ClubID FROM transactions WHERE Description = :d');
$stmt->execute([':d' => $libelle]);
$club = $stmt->fetchColumn();

verifier(
    'transaction forcée sur le club 1 -> enregistrée sur le club 2',
    (int) $club === 2,
    $club === false ? 'non enregistrée' : "club {$club}"
);

// Nettoyage de la ligne de test.
$pdo->prepare('DELETE FROM transactions WHERE Description = :d')->execute([':d' => $libelle]);

echo "\n", str_repeat('=', 78), "\n";

if ($echecs === 0) {
    echo "✅ {$total} vérifications, aucun écart.\n";
    exit(0);
}

echo "❌ {$echecs} écart(s) sur {$total} vérifications.\n";
exit(1);
