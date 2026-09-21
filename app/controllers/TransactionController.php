<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Pole.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/FiscalYear.php';
require_once __DIR__ . '/../config/fichiers.php';

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
        $poles         = Pole::getAll();
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
        $categories  = Category::getActives();
        $poles      = Pole::getActifs();

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
        $categories = Category::getActives();
        $poles      = Pole::getActifs();

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
            $categories  = Category::getActives();
            $poles      = Pole::getActifs();

            require __DIR__ . '/../views/transactions/form.php';
            return;
        }

        /*
         * Justificatif — traité APRÈS la validation des autres champs.
         *
         * Inutile de déplacer un fichier de 8 Mo sur le disque pour
         * découvrir ensuite que le montant est invalide et tout annuler.
         */
        $nouveauFichier = enregistrer_justificatif($_FILES['justificatif'] ?? null, $erreurFichier);

        if ($erreurFichier !== null) {
            $titre       = $id > 0 ? 'Modifier la transaction' : 'Nouvelle transaction';
            $erreur      = $erreurFichier;
            $transaction = $this->saisieVersFormulaire($id, $donnees);
            $clubs       = $this->clubsAutorises();
            $categories  = Category::getActives();
            $poles      = Pole::getActifs();

            require __DIR__ . '/../views/transactions/form.php';
            return;
        }

        if ($id > 0) {
            $ancien = Transaction::findById($id);

            // Un nouveau justificatif remplace l'ancien, qui n'a plus de
            // raison d'occuper le disque. On ne l'efface qu'une fois le
            // nouveau bien enregistré.
            $donnees['recu'] = $nouveauFichier ?? $ancien['Receipt'];

            Transaction::update($id, $donnees);

            if ($nouveauFichier !== null && !empty($ancien['Receipt'])) {
                supprimer_justificatif($ancien['Receipt']);
            }

            message_flash('succes', 'La transaction a été modifiée.');
        } else {
            $donnees['user'] = (int) $_SESSION['user_id'];
            $donnees['recu'] = $nouveauFichier;

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

    /**
     * Export CSV du résultat courant — URL : ?page=transactions-export
     *
     * Reprend EXACTEMENT les mêmes filtres que la page affichée, y compris
     * la restriction de club : un responsable n'exporte que son club, même
     * en manipulant l'URL.
     */
    public function export(): void
    {
        $exercice = exercice_consulte();

        if ($exercice === null) {
            rediriger('?page=transactions');
        }

        $filtres      = $this->filtresDepuisUrl((int) $exercice['FiscalYearID']);
        $transactions = Transaction::pourExport($filtres);

        $nomFichier = sprintf(
            'transactions-%s-%s.csv',
            preg_replace('/[^\w-]/', '', (string) $exercice['Year']),
            date('Ymd')
        );

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
        header('Cache-Control: private, no-store');

        $sortie = fopen('php://output', 'wb');

        /*
         * ⚠ 1. LE BOM UTF-8 (trois octets invisibles en tête de fichier).
         *
         * Sans lui, Excel sous Windows suppose que le fichier est encodé
         * dans le jeu de caractères local et non en UTF-8 : « Réunion »
         * s'affiche « RÃ©union ». Le BOM lui indique explicitement l'UTF-8.
         * LibreOffice et les autres tableurs s'en accommodent sans problème.
         */
        fwrite($sortie, "\xEF\xBB\xBF");

        fputcsv($sortie, [
            'Date', 'Club', 'Pôle', 'Type', 'Montant', 'Libellé', 'Catégorie',
            'Moyen de paiement', 'Statut', 'Justificatif', 'Saisi par', 'Notes',
        ], ';');

        foreach ($transactions as $t) {
            fputcsv($sortie, [
                date('d/m/Y', strtotime((string) $t['Date'])),
                $this->cellule((string) $t['ClubName']),
                $this->cellule((string) $t['PoleName']),
                $t['Type'] === 'depense' ? 'Dépense' : 'Recette',
                /*
                 * ⚠ 2. VIRGULE DÉCIMALE.
                 * MySQL renvoie « 245.50 ». Excel en configuration française
                 * attend « 245,50 » : avec un point, il lirait du texte et
                 * refuserait toute somme sur la colonne.
                 */
                number_format((float) $t['Amount'], 2, ',', ''),
                $this->cellule((string) $t['Description']),
                $this->cellule((string) ($t['CategoryName'] ?? '')),
                (string) ($t['Payment_Method'] ?? ''),
                $this->libelleStatut((string) $t['Status']),
                (string) $t['AvecJustificatif'],
                $this->cellule(trim(($t['FirstName'] ?? '') . ' ' . ($t['LastName'] ?? ''))),
                $this->cellule((string) ($t['Notes'] ?? '')),
            ], ';');
        }

        fclose($sortie);
        exit;
    }

    /**
     * Neutralise une cellule susceptible d'être interprétée comme formule.
     *
     * ⚠ 3. INJECTION DE FORMULE — le piège le moins connu des exports CSV.
     *
     * Un tableur interprète comme une FORMULE toute cellule commençant par
     * =, +, - ou @. Un libellé de transaction saisi « =1+1 » afficherait 2
     * au lieu du texte ; des formules plus élaborées peuvent lire d'autres
     * cellules, appeler une adresse extérieure, voire déclencher l'exécution
     * d'une commande selon les réglages du poste.
     *
     * Le danger est réel ici : les libellés et les notes sont saisis par les
     * utilisateurs, et le fichier sera ouvert par le trésorier sur son
     * ordinateur. On préfixe donc d'une apostrophe, que le tableur
     * comprend comme « ceci est du texte » et n'affiche pas.
     */
    private function cellule(string $valeur): string
    {
        if ($valeur !== '' && str_contains('=+-@', $valeur[0])) {
            return "'" . $valeur;
        }

        return $valeur;
    }

    private function libelleStatut(string $statut): string
    {
        return match ($statut) {
            'valide'     => 'Validée',
            'en_attente' => 'En attente',
            'annule'     => 'Annulée',
            default      => $statut,
        };
    }

    /**
     * Envoie le justificatif d'une transaction — URL : ?page=justificatif&id=5
     *
     * ⚠ C'EST ICI QUE SE JOUE LA CONFIDENTIALITÉ DES PIÈCES COMPTABLES.
     *
     * Les fichiers sont stockés hors de la racine web : Apache ne peut pas
     * les servir, et aucune URL ne mène directement à eux. Ce script est le
     * seul chemin d'accès, et il vérifie d'abord la connexion (le routeur)
     * puis le droit sur le club (transactionAutorisee).
     *
     * Une facture porte des noms, des montants, parfois un RIB : elle
     * mérite le même cloisonnement que le reste de l'application.
     */
    public function justificatif(): void
    {
        $transaction = $this->transactionAutorisee((int) ($_GET['id'] ?? 0));

        if (empty($transaction['Receipt'])) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        /*
         * Nom proposé au téléchargement, reconstruit à partir de la
         * transaction plutôt que conservé depuis l'envoi : on obtient des
         * fichiers cohérents pour l'archivage — justificatif-12-2026-10-05.pdf
         * — au lieu de « IMG_4821.jpg ».
         */
        $extension  = pathinfo((string) $transaction['Receipt'], PATHINFO_EXTENSION);
        $nomAffiche = sprintf(
            'justificatif-%d-%s.%s',
            (int) $transaction['TransactionID'],
            $transaction['Date'],
            $extension
        );

        envoyer_justificatif((string) $transaction['Receipt'], $nomAffiche);
    }

    /**
     * Retire le justificatif d'une transaction (requête POST).
     */
    public function supprimerJustificatif(): void
    {
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $transaction = $this->transactionAutorisee((int) ($_POST['id'] ?? 0));
        $id          = (int) $transaction['TransactionID'];

        if (!empty($transaction['Receipt'])) {
            // La base d'abord, le disque ensuite : si la suppression du
            // fichier échouait, mieux vaut un fichier orphelin sur le
            // disque qu'une transaction pointant vers un fichier absent.
            Transaction::retirerJustificatif($id);
            supprimer_justificatif((string) $transaction['Receipt']);
        }

        message_flash('succes', 'Le justificatif a été retiré.');
        rediriger('?page=transaction-modifier&id=' . $id);
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
            'categorie'   => (int) ($_POST['categorie'] ?? 0),
            // Le pôle est OBLIGATOIRE : toute écriture est rattachée à une
            // équipe, « Général » servant à ce qui ne relève d'aucune.
            'pole'        => (int) ($_POST['pole'] ?? 0),
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

        // Moyen de paiement et catégorie sont OBLIGATOIRES, au même titre
        // que le pôle : une écriture incomplète rend l'analyse bancale, et
        // « Divers » existe pour ce qui n'entre dans aucune catégorie.
        if (!in_array($d['moyen'], Transaction::MOYENS_PAIEMENT, true)) {
            return 'Choisissez un moyen de paiement.';
        }

        if ($d['categorie'] <= 0 || !Category::existe($d['categorie'])) {
            return 'Choisissez une catégorie.';
        }

        if ($d['pole'] <= 0 || !Pole::existe($d['pole'])) {
            return 'Choisissez un pôle.';
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
            'PoleID'         => $d['pole'],
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
            'pole'      => (int) ($_GET['pole'] ?? 0),
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
