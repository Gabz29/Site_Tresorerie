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

        /*
         * Les chiffres affichés portent sur l'exercice CONSULTÉ, pas sur
         * l'exercice actif : c'est ce qui permet de revenir sur une année
         * passée et d'y retrouver exactement les montants de l'époque.
         */
        $exercice     = exercice_consulte();
        $exerciceId   = exercice_consulte_id();

        $titre        = $club['Name'];
        $transactions = Transaction::findByClub($id, $exerciceId);
        $totaux       = Transaction::totauxParClub($id, $exerciceId);

        require __DIR__ . '/../views/clubs/show.php';
    }

    /**
     * Formulaire de création — URL : ?page=club-nouveau
     */
    public function create(): void
    {
        // Réservé au bureau. Première ligne de la méthode : avant toute
        // lecture, tout affichage, toute écriture.
        exiger_bureau();

        $titre  = 'Nouveau club';
        $club   = null;          // aucune donnée pré-remplie : c'est une création
        $erreur = '';

        require __DIR__ . '/../views/clubs/form.php';
    }

    /**
     * Formulaire de modification — URL : ?page=club-modifier&id=3
     */
    public function edit(): void
    {
        exiger_bureau();

        $id   = (int) ($_GET['id'] ?? 0);
        $club = Club::findById($id);

        if ($club === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        $titre  = 'Modifier ' . $club['Name'];
        $erreur = '';

        require __DIR__ . '/../views/clubs/form.php';
    }

    /**
     * Enregistre une création ou une modification (requête POST).
     *
     * Une seule méthode pour les deux : les règles de validation sont
     * identiques, seul l'enregistrement final diffère. Les séparer
     * obligerait à maintenir deux fois les mêmes contrôles — et à corriger
     * deux fois le jour où l'un d'eux change.
     */
    public function save(): void
    {
        exiger_bureau();
        verifier_csrf();

        // 0 = création, sinon modification du club portant cet identifiant.
        $id = (int) ($_POST['id'] ?? 0);

        $nom         = trim((string) ($_POST['nom'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        // Une case à cocher non cochée n'est PAS envoyée par le navigateur :
        // son absence vaut donc « non ». Tester sa présence, et non sa
        // valeur, est la seule façon fiable de lire une case à cocher.
        $actif = isset($_POST['actif']);

        // Le club existe-t-il, si l'on prétend le modifier ?
        $club = $id > 0 ? Club::findById($id) : null;

        if ($id > 0 && $club === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        // --- Validations, côté serveur ---------------------------------
        // Le formulaire porte déjà « required » et « maxlength », mais ce
        // sont des conforts d'affichage : n'importe qui peut envoyer une
        // requête sans passer par la page. Seuls ces contrôles protègent.

        $erreur = '';

        if ($nom === '') {
            $erreur = 'Le nom du club est obligatoire.';
        } elseif (mb_strlen($nom) > 50) {
            $erreur = 'Le nom du club est limité à 50 caractères.';
        } elseif (Club::nomExiste($nom, $id > 0 ? $id : null)) {
            $erreur = 'Un club porte déjà ce nom.';
        }

        if ($erreur !== '') {
            /*
             * On réaffiche le formulaire avec les valeurs saisies plutôt que
             * de rediriger : sans cela, la personne perdrait tout ce qu'elle
             * vient de taper. C'est l'inverse du cas « enregistrement
             * réussi », où l'on redirige pour qu'un rafraîchissement ne
             * renvoie pas le formulaire.
             */
            $titre = $id > 0 ? 'Modifier le club' : 'Nouveau club';
            $club  = [
                'ClubID'      => $id,
                'Name'        => $nom,
                'Description' => $description,
                'IsActive'    => $actif ? 1 : 0,
            ];

            require __DIR__ . '/../views/clubs/form.php';
            return;
        }

        // --- Enregistrement ---------------------------------------------
        // Description vide -> NULL, pour n'avoir qu'une seule façon de
        // représenter l'absence de description.
        $descriptionAEnregistrer = $description === '' ? null : $description;

        if ($id > 0) {
            Club::update($id, $nom, $descriptionAEnregistrer, $actif);
            message_flash('succes', 'Le club a été modifié.');
        } else {
            $id = Club::create($nom, $descriptionAEnregistrer, $actif);
            message_flash('succes', 'Le club a été créé.');
        }

        rediriger('?page=club&id=' . $id);
    }
}
