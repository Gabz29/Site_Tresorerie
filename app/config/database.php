<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * ============================================================================
 *  CONNEXION À LA BASE DE DONNÉES (PDO)
 * ============================================================================
 *
 *  SEUL ENDROIT du projet où l'on se connecte à MySQL. Si l'hébergement
 *  change un jour, ce fichier est le seul à modifier.
 *
 *  PDO = PHP Data Objects : la couche standard de PHP pour parler à une base.
 *  On l'utilise plutôt que les vieilles fonctions mysql_* (supprimées de PHP)
 *  parce qu'elle gère les REQUÊTES PRÉPARÉES, seule protection fiable contre
 *  l'injection SQL.
 *
 *  UTILISATION depuis un modèle :
 *      require_once __DIR__ . '/../config/database.php';
 *      $stmt = db()->query('SELECT ...');
 * ============================================================================
 */

/**
 * Renvoie la connexion PDO, en l'ouvrant au premier appel.
 */
function db(): PDO
{
    // "static" : la connexion est ouverte UNE SEULE FOIS par requête HTTP,
    // puis réutilisée. Sans cela, chaque modèle rouvrirait sa propre
    // connexion — inutilement lourd pour le serveur MySQL.
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $base = env('DB_NAME', '');

    // Garde-fou : avec un dbname vide, PDO se connecte SANS ERREUR au serveur
    // MySQL, et ce n'est qu'à la première requête qu'apparaît un message
    // déroutant ("Aucune base n'a été sélectionnée"). Autant refuser tout de
    // suite, avec la vraie cause.
    if ($base === '') {
        exit("DB_NAME n'est pas renseigné dans le fichier .env.");
    }

    // DSN = "Data Source Name" : la chaîne qui décrit OÙ se connecter.
    // charset=utf8mb4 est indispensable : sans lui, les accents et les
    // caractères comme "Œ" reviennent abîmés depuis la base.
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        $base
    );

    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [

            // Sans cette option, une requête SQL fautive échoue EN SILENCE et
            // renvoie simplement false : on cherche pendant des heures un bug
            // d'affichage alors que c'est le SQL qui n'est jamais parti.
            // Avec elle, PHP lève une exception explicite.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // Les résultats arrivent sous forme de tableaux associatifs :
            //   $club['Name']   plutôt que   $club[1]
            // Le code reste lisible même si l'ordre des colonnes change.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Les requêtes préparées sont envoyées telles quelles à MySQL, qui
            // sépare lui-même la requête de ses paramètres. En mode "émulé"
            // (le défaut), c'est PHP qui assemble la chaîne avant l'envoi —
            // une protection réelle mais plus faible contre l'injection SQL.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {

        // Le détail part TOUJOURS dans le journal du serveur, jamais
        // directement à l'écran.
        error_log('[Jumao] Connexion MySQL impossible : ' . $e->getMessage());

        // En production, un message d'erreur brut renseignerait un visiteur
        // sur la structure du serveur (nom de la base, utilisateur...).
        // En local, on veut au contraire pouvoir lire le problème.
        if (est_en_debug()) {
            $detail = "\n\nDétail technique : " . $e->getMessage()
                . "\n\nPistes à vérifier :"
                . "\n  - les services WAMP sont-ils démarrés (icône verte) ?"
                . "\n  - DB_NAME dans .env correspond-il à une base existante ?"
                . "\n  - DB_PORT est-il le bon (MySQL et MariaDB n'utilisent pas le même) ?";
        } else {
            $detail = '';
        }

        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
        }

        exit('Connexion à la base de données impossible.' . $detail);
    }

    return $pdo;
}
