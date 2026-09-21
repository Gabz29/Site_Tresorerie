<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/FiscalYear.php';

/**
 * ============================================================================
 *  CONTRÔLEUR DES TRANSACTIONS
 * ============================================================================
 *
 *  Accessible à tous les utilisateurs connectés, mais avec une portée qui
 *  dépend du rôle :
 *    - bureau      : toutes les transactions de tous les clubs
 *    - responsable : uniquement celles de SON club
 *
 *  ⚠ CETTE RESTRICTION EST IMPOSÉE CÔTÉ SERVEUR, dans clubImpose(). Un
 *  responsable qui ajouterait « &club=1 » à l'URL ne verrait pas pour autant
 *  les transactions du BDE : le filtre de club est écrasé, pas seulement
 *  masqué dans le formulaire.
 * ============================================================================
 */
class TransactionController
{
    private const PAR_PAGE = 50;

    /**
     * Liste filtrable — URL : ?page=transactions
     */
    public function index(): void
    {
        $exercice = exercice_consulte();

        if ($exercice === null) {
            $titre = 'Transactions';
            $aucunExercice = true;

            require __DIR__ . '/../views/transactions/index.php';
            return;
        }

        $filtres = $this->filtresDepuisUrl((int) $exercice['FiscalYearID']);

        $total   = Transaction::compter($filtres);
        $nbPages = max(1, (int) ceil($total / self::PAR_PAGE));

        /*
         * Numéro de page ramené dans les bornes.
         *
         * En dessous de 1, l'OFFSET deviendrait négatif et MySQL refuserait
         * la requête. Au-delà de la dernière page, on afficherait une liste
         * vide SANS lien de pagination — l'utilisateur se retrouverait
         * bloqué sur une page sans issue. On le ramène donc sur la dernière
         * page existante plutôt que de le laisser dans le vide.
         */
        $pageCourante = min($nbPages, max(1, (int) ($_GET['p'] ?? 1)));
        $offset       = ($pageCourante - 1) * self::PAR_PAGE;

        $transactions = Transaction::rechercher($filtres, self::PAR_PAGE, $offset);
        $totaux       = Transaction::totaux($filtres);

        $titre         = 'Transactions';
        $aucunExercice = false;
        $clubs         = Club::getAll();
        $categories    = Category::getAll();
        $flash         = lire_flash();

        require __DIR__ . '/../views/transactions/index.php';
    }

    /**
     * Formulaire de saisie — URL : ?page=transaction-nouvelle
     */
    public function create(): void
    {
        $this->exigerExerciceOuvert();

        $titre       = 'Nouvelle transaction';
        $transaction = null;
        $erreur      = '';
        $clubs       = $this->clubsAutorises();
        $categories  = Category::getAll();

        require __DIR__ . '/../views/transactions/form.php';
    }

    /**
     * Formulaire de modification — URL : ?page=transaction-modifier&id=5
     */
    public function edit(): void
    {
        $this->exigerExerciceOuvert();

        $transaction = $this->transactionAutorisee((int) ($_GET['id'] ?? 0));

        $titre      = 'Modifier la transaction';
        $erreur     = '';
        $clubs      = $this->clubsAutorises();
        $categories = Category::getAll();

        require __DIR__ . '/../views/transactions/form.php';
    }

    /**
     * Enregistre une création ou une modification (requête POST).
     */
    public function save(): void
    {
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $id = (int) ($_POST['id'] ?? 0);

        // Modification : on vérifie d'abord le droit sur la ligne existante.
        if ($id > 0) {
            $this->transactionAutorisee($id);
        }

        $donnees = $this->lireFormulaire();
        $erreur  = $this->valider($donnees);

        if ($erreur === '') {
            /*
             * ⚠ L'EXERCICE SE DÉDUIT DE LA DATE (cf. schema.sql).
             *
             * Ni l'exercice consulté, ni l'exercice actif : la date seule
             * décide. Sinon, saisir une dépense de mars 2027 en consultant
             * 2027-2028 la rattacherait à la mauvaise année, et fausserait
             * les totaux des deux exercices sans rien signaler.
             */
            $exercice = FiscalYear::trouverParDate($donnees['date']);

            if ($exercice === null) {
                $erreur = 'Cette date n\'appartient à aucun exercice. '
                        . 'Vérifiez l\'année, ou créez l\'exercice correspondant.';
            } elseif ((int) $exercice['IsActive'] !== 1) {
                // Conséquence directe de « exercice clos = figé » : on ne
                // peut pas dater une écriture d'une année déjà arrêtée.
                $erreur = 'Cette date appartient à l\'exercice ' . $exercice['Year']
                        . ', qui est clos. Les écritures d\'un exercice arrêté ne '
                        . 'sont plus modifiables : datez la correction sur l\'exercice courant.';
            } else {
                $donnees['exercice'] = (int) $exercice['FiscalYearID'];
            }
        }

        if ($erreur !== '') {
            // On réaffiche le formulaire garni de la saisie, pour ne pas
            // faire retaper la ligne.
            $titre       = $id > 0 ? 'Modifier la transaction' : 'Nouvelle transaction';
            $transaction = $this->saisieVersFormulaire($id, $donnees);
            $clubs       = $this->clubsAutorises();
            $categories  = Category::getAll();

            require __DIR__ . '/../views/transactions/form.php';
            return;
        }

        if ($id > 0) {
            Transaction::update($id, $donnees);
            message_flash('succes', 'La transaction a été modifiée.');
        } else {
            $donnees['user'] = (int) $_SESSION['user_id'];
            $id = Transaction::create($donnees);
            message_flash('succes', 'La transaction a été enregistrée.');
        }

        rediriger('?page=transactions');
    }

    /**
     * Change le statut d'une transaction (requête POST).
     */
    public function changerStatut(): void
    {
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $transaction = $this->transactionAutorisee((int) ($_POST['id'] ?? 0));
        $statut      = (string) ($_POST['statut'] ?? '');

        if (!in_array($statut, Transaction::STATUTS, true)) {
            message_flash('erreur', 'Statut inconnu.');
            rediriger('?page=transactions');
        }

        Transaction::changerStatut((int) $transaction['TransactionID'], $statut);

        message_flash('succes', 'Le statut a été modifié.');
        rediriger('?page=transactions');
    }

    /**
     * Supprime définitivement une transaction (requête POST).
     */
    public function delete(): void
    {
        verifier_csrf();

        // ⚠ Suppression réservée au BUREAU, contrairement à la saisie et à
        // l'annulation. Un responsable peut neutraliser une écriture de son
        // club, pas la faire disparaître : sinon l'historique d'un club
        // dépendrait de la rigueur de chaque responsable successif.
        exiger_bureau();
        $this->exigerExerciceOuvert();

        $transaction = $this->transactionAutorisee((int) ($_POST['id'] ?? 0));
        $id          = (int) $transaction['TransactionID'];

        // Une transaction générée par un remboursement est référencée par
        // celui-ci : la clé étrangère bloquerait la suppression. On prévient
        // clairement plutôt que de laisser remonter une erreur SQL.
        if (Transaction::estLieeAUnRemboursement($id)) {
            message_flash(
                'erreur',
                'Cette transaction est liée à une demande de remboursement. '
                . 'Annulez-la plutôt que de la supprimer.'
            );
            rediriger('?page=transactions');
        }

        Transaction::delete($id);

        message_flash('succes', 'La transaction a été supprimée.');
        rediriger('?page=transactions');
    }

    // ------------------------------------------------------------------
    //  Outils internes
    // ------------------------------------------------------------------

    /**
     * Lit et nettoie les champs du formulaire.
     *
     * @return array<string,mixed>
     */
    private function lireFormulaire(): array
    {
        $type = (string) ($_POST['type'] ?? '');

        return [
            'type'        => in_array($type, ['depense', 'recette'], true) ? $type : '',
            'montant'     => str_replace(',', '.', trim((string) ($_POST['montant'] ?? ''))),
            'date'        => trim((string) ($_POST['date'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'moyen'       => (string) ($_POST['moyen'] ?? ''),
            'statut'      => (string) ($_POST['statut'] ?? 'valide'),
            'notes'       => trim((string) ($_POST['notes'] ?? '')),
            /*
             * « Aucune catégorie » doit devenir NULL, jamais 0.
             * CategoryID est une clé étrangère : 0 ne correspond à aucune
             * catégorie et MySQL refuse l'insertion (erreur 1452). C'est
             * d'ailleurs la contrainte qui a attrapé le problème au premier
             * test — le code, lui, l'aurait laissé passer.
             */
            'categorie'   => trim((string) ($_POST['categorie'] ?? '')) === ''
                                ? null
                                : (int) $_POST['categorie'],
            'club'        => $this->clubPourSaisie(),
            'exercice'    => 0,   // renseigné après lecture de la date
        ];
    }

    /**
     * Le club auquel rattacher la saisie.
     *
     * Pour un responsable, c'est toujours SON club : la valeur envoyée par
     * le formulaire est ignorée. Sans cela, il suffirait de modifier le
     * champ caché pour imputer une dépense à un autre club.
     */
    private function clubPourSaisie(): int
    {
        if (!est_bureau()) {
            return (int) ($_SESSION['club_id'] ?? 0);
        }

        return (int) ($_POST['club'] ?? 0);
    }

    /**
     * Contrôle les données ; renvoie '' si tout est correct.
     *
     * @param array<string,mixed> $d
     */
    private function valider(array $d): string
    {
        if ($d['type'] === '') {
            return 'Choisissez s\'il s\'agit d\'une dépense ou d\'une recette.';
        }

        if ($d['description'] === '') {
            return 'Le libellé est obligatoire.';
        }

        if (mb_strlen($d['description']) > 255) {
            return 'Le libellé est limité à 255 caractères.';
        }

        // Montant TOUJOURS positif : c'est le type qui donne le sens. Un
        // montant négatif sur une dépense la transformerait en recette dans
        // les calculs de solde.
        if (!preg_match('/^\d{1,8}([.]\d{1,2})?$/', $d['montant']) || (float) $d['montant'] <= 0) {
            return 'Le montant doit être un nombre positif (ex. 42.50).';
        }

        $date = DateTime::createFromFormat('Y-m-d', $d['date']);

        if ($date === false || $date->format('Y-m-d') !== $d['date']) {
            return 'La date n\'est pas valide.';
        }

        if ($d['club'] <= 0 || Club::findById($d['club']) === null) {
            return 'Choisissez un club.';
        }

        if (!in_array($d['statut'], Transaction::STATUTS, true)) {
            return 'Statut inconnu.';
        }

        if ($d['moyen'] !== '' && !in_array($d['moyen'], Transaction::MOYENS_PAIEMENT, true)) {
            return 'Moyen de paiement inconnu.';
        }

        if ($d['categorie'] !== null && !Category::existe((int) $d['categorie'])) {
            return 'Catégorie inconnue.';
        }

        return '';
    }

    /**
     * Reconstitue une ligne au format attendu par le formulaire, à partir
     * d'une saisie refusée.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function saisieVersFormulaire(int $id, array $d): array
    {
        return [
            'TransactionID'  => $id,
            'Type'           => $d['type'],
            'Amount'         => $d['montant'],
            'Date'           => $d['date'],
            'Description'    => $d['description'],
            'Payment_Method' => $d['moyen'],
            'Status'         => $d['statut'],
            'Notes'          => $d['notes'],
            'CategoryID'     => $d['categorie'],
            'ClubID'         => $d['club'],
        ];
    }

    /**
     * Récupère une transaction en vérifiant le droit d'y toucher.
     *
     * @return array<string,mixed>
     */
    private function transactionAutorisee(int $id): array
    {
        $transaction = Transaction::findById($id);

        if ($transaction === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        // Un responsable ne touche qu'aux transactions de son club.
        if (!peut_voir_club((int) $transaction['ClubID'])) {
            refuser_acces();
        }

        return $transaction;
    }

    /**
     * Clubs proposés à la saisie : tous pour le bureau, le sien seulement
     * pour un responsable.
     *
     * @return array<int,array<string,mixed>>
     */
    private function clubsAutorises(): array
    {
        if (est_bureau()) {
            return Club::getAll();
        }

        $club = Club::findById((int) ($_SESSION['club_id'] ?? 0));

        return $club === null ? [] : [$club];
    }

    /**
     * Interrompt si l'exercice consulté est clos ou inexistant.
     */
    private function exigerExerciceOuvert(): void
    {
        if (exercice_consulte() === null || !exercice_consulte_est_actif()) {
            message_flash('erreur', 'Cet exercice est clos : les transactions n\'y sont plus modifiables.');
            rediriger('?page=transactions');
        }
    }

    /**
     * Lit les filtres depuis l'URL, en imposant ce qui doit l'être.
     *
     * @return array<string,mixed>
     */
    private function filtresDepuisUrl(int $exerciceId): array
    {
        $type   = (string) ($_GET['type'] ?? '');
        $statut = (string) ($_GET['statut'] ?? '');

        return [
            'exercice'  => $exerciceId,
            'club'      => $this->clubImpose(),
            // On n'accepte que des valeurs connues : une valeur inventée
            // est ignorée plutôt que transmise telle quelle à la requête.
            'type'      => in_array($type, ['depense', 'recette'], true) ? $type : '',
            'statut'    => in_array($statut, Transaction::STATUTS, true) ? $statut : '',
            'categorie' => (int) ($_GET['categorie'] ?? 0),
            'du'        => $this->dateOuVide((string) ($_GET['du'] ?? '')),
            'au'        => $this->dateOuVide((string) ($_GET['au'] ?? '')),
            'q'         => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    /**
     * Le club sur lequel la vue doit être restreinte.
     *
     * Renvoie 0 (= pas de restriction) pour le bureau, et l'identifiant de
     * son club pour un responsable — quelle que soit la valeur présente
     * dans l'URL.
     */
    private function clubImpose(): int
    {
        if (!est_bureau()) {
            return (int) ($_SESSION['club_id'] ?? 0);
        }

        return (int) ($_GET['club'] ?? 0);
    }

    /**
     * Renvoie la date si elle est valide, une chaîne vide sinon.
     *
     * Un filtre mal formé est ignoré plutôt que de faire échouer la page :
     * l'utilisateur voit simplement une liste non filtrée.
     */
    private function dateOuVide(string $valeur): string
    {
        if ($valeur === '') {
            return '';
        }

        $date = DateTime::createFromFormat('Y-m-d', $valeur);

        return ($date !== false && $date->format('Y-m-d') === $valeur) ? $valeur : '';
    }
}
