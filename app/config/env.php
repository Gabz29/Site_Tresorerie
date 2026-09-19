<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  LECTURE DU FICHIER .env
 * ============================================================================
 *
 *  À QUOI SERT CE FICHIER ?
 *  Le mot de passe de la base ne doit jamais être écrit en dur dans le code :
 *  le code est versionné dans Git, le mot de passe ne doit pas l'être. On le
 *  range donc dans .env (ignoré par Git), et ce fichier sait le relire.
 *
 *  POURQUOI L'ÉCRIRE SOI-MÊME ?
 *  PHP ne sait pas lire un .env nativement. C'est habituellement le rôle
 *  d'une bibliothèque installée via Composer (vlucas/phpdotenv), que ce
 *  projet n'utilise pas. Quinze lignes suffisent ici.
 *
 *  POURQUOI SÉPARÉ DE database.php ?
 *  Un fichier = un rôle (cf. RÈGLE D'OR des notes de projet) : celui-ci lit
 *  un fichier de configuration, database.php ouvre une connexion. Le jour où
 *  une autre partie du projet aura besoin d'un réglage (une adresse mail, une
 *  taille maximale d'upload), elle appellera env() sans rien savoir de MySQL.
 * ============================================================================
 */

/**
 * Renvoie la valeur d'une variable du fichier .env.
 *
 * @param string      $cle    Nom de la variable, ex. 'DB_NAME'
 * @param string|null $defaut Valeur si la variable est absente du fichier
 */
function env(string $cle, ?string $defaut = null): ?string
{
    // "static" : la variable survit entre deux appels de la fonction.
    // Le fichier n'est donc lu qu'UNE SEULE FOIS par requête, même si env()
    // est appelée dix fois ensuite.
    static $valeurs = null;

    if ($valeurs === null) {
        $valeurs = charger_env(dirname(__DIR__, 2) . '/.env');
    }

    // array_key_exists et non isset() : isset() renvoie false pour une valeur
    // vide, or DB_PASS= (mot de passe vide) est un cas légitime sous WAMP.
    // Avec isset(), on renverrait le défaut au lieu de la chaîne vide voulue.
    return array_key_exists($cle, $valeurs) ? $valeurs[$cle] : $defaut;
}

/**
 * Lit un fichier .env et renvoie ses variables sous forme de tableau.
 *
 * Fonction SÉPARÉE de env() — et pas seulement par souci de propreté.
 * Première version de ce fichier, le code de lecture était écrit directement
 * dans env() et réutilisait le nom $cle pour sa variable de boucle : il
 * écrasait donc le paramètre de la fonction. Résultat : le tout premier appel
 * à env() renvoyait la valeur de la DERNIÈRE ligne du .env, quelle que soit
 * la clé demandée, et les appels suivants étaient corrects (le cache étant
 * rempli, la boucle ne tournait plus). Un bug quasi impossible à lire.
 * Isoler la lecture dans sa propre fonction rend l'erreur structurellement
 * impossible : les variables de boucle n'ont plus aucun voisin à écraser.
 *
 * @return array<string,string>
 */
function charger_env(string $chemin): array
{
    if (!is_readable($chemin)) {
        // Erreur volontairement explicite : c'est l'erreur nº1 quand on
        // installe le projet sur une nouvelle machine.
        throw new RuntimeException(
            "Fichier .env introuvable ou illisible ($chemin). " .
            "Copiez .env.example en .env, puis renseignez-y vos identifiants MySQL."
        );
    }

    // On lit le fichier nous-mêmes, ligne par ligne, plutôt qu'avec la
    // fonction parse_ini_file() de PHP. Deux raisons :
    //   1. parse_ini_file() ne reconnaît plus "#" comme commentaire depuis
    //      PHP 7 (elle attend ";"), alors que "#" est LA convention des
    //      fichiers .env. Une ligne de commentaire ferait échouer la
    //      lecture entière du fichier.
    //   2. elle convertit certaines valeurs au passage ("yes", "off",
    //      "null"...), ce qui réserve des surprises.
    // Quinze lignes explicites valent mieux qu'une fonction au
    // comportement caché.
    $resultat = [];

    foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        $ligne = trim($ligne);

        // Ligne vide, ou commentaire (# comme ;)
        if ($ligne === '' || $ligne[0] === '#' || $ligne[0] === ';') {
            continue;
        }

        // Ligne sans "=" : on l'ignore plutôt que de planter
        $separateur = strpos($ligne, '=');
        if ($separateur === false) {
            continue;
        }

        $nom    = trim(substr($ligne, 0, $separateur));
        $valeur = trim(substr($ligne, $separateur + 1));

        // Guillemets englobants optionnels : DB_PASS="mon mot de passe"
        $longueur = strlen($valeur);
        if ($longueur >= 2
            && ($valeur[0] === '"' || $valeur[0] === "'")
            && $valeur[$longueur - 1] === $valeur[0]
        ) {
            $valeur = substr($valeur, 1, -1);
        }

        // NOTE : on ne coupe volontairement PAS à un "#" en milieu de
        // ligne. Un mot de passe a parfaitement le droit d'en contenir,
        // et le tronquer silencieusement donnerait un bug incompréhensible.
        $resultat[$nom] = $valeur;
    }

    return $resultat;
}

/**
 * Le projet tourne-t-il en mode développement ?
 *
 * Sert à décider si l'on affiche le détail d'une erreur (pratique en local)
 * ou un message générique (obligatoire en production : un message d'erreur
 * brut renseigne un attaquant sur la structure du serveur).
 */
function est_en_debug(): bool
{
    return env('APP_DEBUG', 'false') === 'true';
}
