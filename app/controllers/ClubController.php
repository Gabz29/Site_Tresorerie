<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/Transaction.php';

/**
 * ============================================================================
 *  CONTRÔLEUR DES CLUBS
 * ============================================================================
 *
 *  Rôle d'un contrôleur : recevoir la demande, appeler le ou les modèles
 *  nécessaires, puis choisir la vue à afficher.
 *
 *  Ce qu'il ne fait JAMAIS :
 *    - écrire une requête SQL       -> c'est le rôle du modèle
 *    - produire du HTML (echo)      -> c'est le rôle de la vue
 *
 *  C'est l'intermédiaire : il sait QUOI demander et QUOI afficher, sans
 *  connaître ni la structure de la base, ni la mise en page.
 * ============================================================================
 */
class ClubController
{
    /**
     * Page "liste des clubs" — URL : ?page=clubs
     */
    public function index(): void
    {
        // 1. Demander les données au modèle.
        $clubs = Club::getAll();

        // 2. Préparer ce dont la vue a besoin.
        $titre = 'Clubs';

        // 3. Afficher la vue.
        //    Les variables définies ci-dessus ($clubs, $titre) sont
        //    directement visibles dans le fichier inclus : en PHP, un fichier
        //    inclus partage les variables de l'endroit d'où on l'inclut.
        //    C'est ainsi que les données passent du contrôleur à la vue.
        require __DIR__ . '/../views/clubs/index.php';
    }

    /**
     * Fiche d'un club — URL : ?page=club&id=3
     */
    public function show(): void
    {
        /*
         * L'identifiant vient de l'URL, donc de l'extérieur. (int) le ramène
         * à un entier quoi qu'il contienne : "3abc" devient 3, "abc" devient
         * 0 — et aucun club ne porte l'identifiant 0, la recherche échouera
         * proprement. Le modèle utilise par ailleurs une requête préparée.
         */
        $id = (int) ($_GET['id'] ?? 0);

        $club = Club::findById($id);

        if ($club === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        /*
         * ⚠ LE CONTRÔLE DE DROITS EST ICI, PAS DANS LA VUE.
         *
         * Le bureau consulte n'importe quel club ; un responsable seulement
         * le sien. Masquer le lien dans la liste ne suffirait pas : il suffit
         * de taper ?page=club&id=1 pour contourner l'affichage. C'est cette
         * ligne, exécutée côté serveur, qui protège réellement.
         */
        if (!peut_voir_club($id)) {
            refuser_acces();
        }

        $titre        = $club['Name'];
        $transactions = Transaction::findByClub($id);
        $totaux       = Transaction::totauxParClub($id);

        require __DIR__ . '/../views/clubs/show.php';
    }
}
