<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Club.php';

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
}
