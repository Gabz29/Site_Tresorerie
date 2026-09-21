<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Reimbursement.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/FiscalYear.php';
require_once __DIR__ . '/../config/fichiers.php';

/**
 * ============================================================================
 *  CONTRÔLEUR DES REMBOURSEMENTS
 * ============================================================================
 *
 *  Qui fait quoi :
 *    - responsable : dépose et consulte les demandes de SON club
 *    - bureau      : voit tout, et seul à pouvoir accepter, refuser ou payer
 *
 *  Le moment clé est le passage à « remboursé » : il crée automatiquement la
 *  dépense correspondante. Les deux écritures doivent tenir ensemble — voir
 *  payer().
 * ============================================================================
 */
class ReimbursementController
{
    /**
     * Liste des demandes — URL : ?page=remboursements
     */
    public function index(): void
    {
        $exercice = exercice_consulte();

        if ($exercice === null) {
            $titre         = 'Remboursements';
            $aucunExercice = true;

            require __DIR__ . '/../views/remboursements/index.php';
            return;
        }

        $statut = (string) ($_GET['statut'] ?? '');

        if (!in_array($statut, Reimbursement::STATUTS, true)) {
            $statut = '';
        }

        $titre         = 'Remboursements';
        $aucunExercice = false;
        $demandes      = Reimbursement::rechercher(
            (int) $exercice['FiscalYearID'],
            $this->clubImpose(),
            $statut
        );
        $statutFiltre = $statut;
        $flash        = lire_flash();

        require __DIR__ . '/../views/remboursements/index.php';
    }

    /**
     * Formulaire de demande — URL : ?page=remboursement-nouveau
     */
    public function create(): void
    {
        $this->exigerExerciceOuvert();

        $titre      = 'Nouvelle demande de remboursement';
        $demande    = null;
        $erreur     = '';
        $clubs      = $this->clubsAutorises();
        $categories = Category::getAll();

        require __DIR__ . '/../views/remboursements/form.php';
    }

    /**
     * Formulaire de modification — URL : ?page=remboursement-modifier&id=3
     */
    public function edit(): void
    {
        $this->exigerExerciceOuvert();

        $demande = $this->demandeAutorisee((int) ($_GET['id'] ?? 0));

        // Une demande déjà traitée ne se modifie plus : son montant a servi
        // de base à une décision, et parfois à un paiement.
        if ($demande['Status'] !== 'en_attente') {
            message_flash('erreur', 'Cette demande a déjà été traitée : elle n\'est plus modifiable.');
            rediriger('?page=remboursements');
        }

        $titre      = 'Modifier la demande';
        $erreur     = '';
        $clubs      = $this->clubsAutorises();
        $categories = Category::getAll();

        require __DIR__ . '/../views/remboursements/form.php';
    }

    /**
     * Enregistre une demande (requête POST).
     */
    public function save(): void
    {
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0) {
            $existante = $this->demandeAutorisee($id);

            if ($existante['Status'] !== 'en_attente') {
                message_flash('erreur', 'Cette demande a déjà été traitée.');
                rediriger('?page=remboursements');
            }
        }

        $donnees = $this->lireFormulaire();
        $erreur  = $this->valider($donnees);

        if ($erreur === '') {
            /*
             * Même règle que pour les transactions : l'exercice se déduit de
             * la date — ici celle de l'ACHAT avancé, puisque c'est elle qui
             * situe la dépense dans le temps.
             */
            $exercice = FiscalYear::trouverParDate($donnees['achat']);

            if ($exercice === null) {
                $erreur = 'La date d\'achat n\'appartient à aucun exercice.';
            } else {
                $donnees['exercice'] = (int) $exercice['FiscalYearID'];
            }
        }

        if ($erreur !== '') {
            $titre      = $id > 0 ? 'Modifier la demande' : 'Nouvelle demande de remboursement';
            $demande    = $this->saisieVersFormulaire($id, $donnees);
            $clubs      = $this->clubsAutorises();
            $categories = Category::getAll();

            require __DIR__ . '/../views/remboursements/form.php';
            return;
        }

        // Justificatif traité après validation du reste : inutile de
        // déplacer un fichier pour découvrir ensuite que le montant est faux.
        $nouveauFichier = enregistrer_justificatif($_FILES['justificatif'] ?? null, $erreurFichier);

        if ($erreurFichier !== null) {
            $titre      = $id > 0 ? 'Modifier la demande' : 'Nouvelle demande de remboursement';
            $erreur     = $erreurFichier;
            $demande    = $this->saisieVersFormulaire($id, $donnees);
            $clubs      = $this->clubsAutorises();
            $categories = Category::getAll();

            require __DIR__ . '/../views/remboursements/form.php';
            return;
        }

        if ($id > 0) {
            $ancien             = Reimbursement::findById($id);
            $donnees['recu']    = $nouveauFichier ?? $ancien['Receipt'];

            Reimbursement::update($id, $donnees);

            if ($nouveauFichier !== null && !empty($ancien['Receipt'])) {
                supprimer_justificatif($ancien['Receipt']);
            }

            message_flash('succes', 'La demande a été modifiée.');
        } else {
            $donnees['user'] = (int) $_SESSION['user_id'];
            $donnees['recu'] = $nouveauFichier;

            Reimbursement::create($donnees);
            message_flash('succes', 'La demande a été déposée. Le bureau doit maintenant l\'examiner.');
        }

        rediriger('?page=remboursements');
    }

    /**
     * Accepte ou refuse une demande (requête POST) — réservé au bureau.
     */
    public function decider(): void
    {
        verifier_csrf();
        exiger_bureau();
        $this->exigerExerciceOuvert();

        $demande = $this->demandeAutorisee((int) ($_POST['id'] ?? 0));
        $statut  = (string) ($_POST['statut'] ?? '');
        $notes   = trim((string) ($_POST['notes'] ?? ''));

        if (!in_array($statut, ['valide', 'refuse'], true)) {
            message_flash('erreur', 'Décision inconnue.');
            rediriger('?page=remboursements');
        }

        if ($demande['Status'] === 'rembourse') {
            // Revenir sur une demande déjà payée laisserait la transaction
            // en place sans rien pour l'expliquer.
            message_flash('erreur', 'Cette demande a déjà été remboursée : sa décision n\'est plus modifiable.');
            rediriger('?page=remboursements');
        }

        Reimbursement::decider(
            (int) $demande['ReimbursementID'],
            $statut,
            $notes === '' ? null : $notes
        );

        message_flash('succes', $statut === 'valide'
            ? 'Demande acceptée. Elle reste à payer.'
            : 'Demande refusée.');

        rediriger('?page=remboursements');
    }

    /**
     * Marque une demande comme payée ET crée la dépense correspondante.
     */
    public function payer(): void
    {
        verifier_csrf();
        exiger_bureau();
        $this->exigerExerciceOuvert();

        $demande = $this->demandeAutorisee((int) ($_POST['id'] ?? 0));

        if ($demande['Status'] !== 'valide') {
            message_flash('erreur', 'Seule une demande acceptée peut être marquée comme remboursée.');
            rediriger('?page=remboursements');
        }

        /*
         * DATE DE LA TRANSACTION : celle du remboursement, c'est-à-dire
         * aujourd'hui — le moment où le BDE décaisse réellement (décision
         * du 21/09/2026).
         *
         * Et non la date d'achat : un achat de juillet remboursé en octobre
         * tomberait dans l'exercice précédent, désormais clos, où plus
         * aucune écriture n'est possible. La date d'achat reste visible sur
         * la demande, qui garde la trace complète.
         */
        $aujourdhui = date('Y-m-d');
        $exercice   = FiscalYear::trouverParDate($aujourdhui);

        if ($exercice === null || (int) $exercice['IsActive'] !== 1) {
            message_flash('erreur', 'La date du jour n\'appartient à aucun exercice ouvert : impossible d\'enregistrer la dépense.');
            rediriger('?page=remboursements');
        }

        /*
         * ⚠ TRANSACTION SQL — les deux écritures tiennent ensemble.
         *
         * Si la dépense s'enregistrait sans que la demande passe à
         * « remboursé », le bureau paierait deux fois la même avance. Si
         * l'inverse se produisait, la demande serait soldée sans que la
         * sortie d'argent apparaisse en comptabilité.
         *
         * Troisième usage de ce mécanisme, après l'activation d'un exercice
         * (2.5) et la création d'un budget avec ses tranches (2.6) : dès que
         * deux écritures n'ont de sens qu'ensemble, elles vont dans une
         * transaction.
         */
        $pdo = db();
        $pdo->beginTransaction();

        try {
            /*
             * Le justificatif est RECOPIÉ sur la dépense, pas partagé : la
             * pièce doit rester attachée à l'écriture comptable même si la
             * demande est modifiée plus tard. Voir copier_justificatif().
             */
            $transactionId = Transaction::create([
                'type'        => 'depense',
                'montant'     => (string) $demande['Amount'],
                'date'        => $aujourdhui,
                'description' => sprintf(
                    'Remb. %s %s — %s',
                    $demande['Beneficiary_FirstName'],
                    $demande['Beneficiary_LastName'],
                    $demande['Description']
                ),
                'moyen'     => (string) ($_POST['moyen'] ?? 'virement'),
                'statut'    => 'valide',
                'recu'      => copier_justificatif($demande['Receipt']),
                'notes'     => 'Généré par la demande de remboursement n°' . (int) $demande['ReimbursementID'],
                'categorie' => $demande['CategoryID'] !== null ? (int) $demande['CategoryID'] : null,
                'exercice'  => (int) $exercice['FiscalYearID'],
                'user'      => (int) $_SESSION['user_id'],
                // Le club du remboursement, jamais celui de l'utilisateur
                // connecté : c'est le club qui a bénéficié de l'achat.
                'club'      => (int) $demande['ClubID'],
            ]);

            Reimbursement::marquerRembourse((int) $demande['ReimbursementID'], $transactionId);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        message_flash('succes', 'Remboursement enregistré. La dépense correspondante a été créée automatiquement.');
        rediriger('?page=remboursements');
    }

    /**
     * Supprime une demande (requête POST) — réservé au bureau.
     */
    public function delete(): void
    {
        verifier_csrf();
        exiger_bureau();
        $this->exigerExerciceOuvert();

        $demande = $this->demandeAutorisee((int) ($_POST['id'] ?? 0));

        // Une demande déjà payée a produit une dépense : l'effacer laisserait
        // cette dépense sans justification dans la comptabilité.
        if ($demande['TransactionID'] !== null) {
            message_flash('erreur', 'Cette demande a généré une dépense : elle ne peut plus être supprimée.');
            rediriger('?page=remboursements');
        }

        Reimbursement::delete((int) $demande['ReimbursementID']);

        // Le fichier n'a plus rien à référencer : on le retire aussi, sans
        // quoi le dossier accumulerait des pièces orphelines au fil des ans.
        supprimer_justificatif($demande['Receipt']);

        message_flash('succes', 'La demande a été supprimée.');
        rediriger('?page=remboursements');
    }

    /**
     * Envoie le justificatif d'une demande — URL : ?page=remboursement-justificatif&id=3
     *
     * C'est ce qui permet au bureau de décider en connaissance de cause :
     * on ne rembourse pas quelqu'un sur parole. Mêmes contrôles que pour
     * les transactions — connexion, puis droit sur le club.
     */
    public function justificatif(): void
    {
        $demande = $this->demandeAutorisee((int) ($_GET['id'] ?? 0));

        if (empty($demande['Receipt'])) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        $extension = pathinfo((string) $demande['Receipt'], PATHINFO_EXTENSION);

        envoyer_justificatif((string) $demande['Receipt'], sprintf(
            'justificatif-remboursement-%d-%s.%s',
            (int) $demande['ReimbursementID'],
            $demande['Purchase_Date'],
            $extension
        ));
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function lireFormulaire(): array
    {
        return [
            'montant'     => str_replace(',', '.', trim((string) ($_POST['montant'] ?? ''))),
            'achat'       => trim((string) ($_POST['achat'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'prenom'      => trim((string) ($_POST['prenom'] ?? '')),
            'nom'         => trim((string) ($_POST['nom'] ?? '')),
            'email'       => trim((string) ($_POST['email'] ?? '')),
            'recu'        => null,   // renseigné après traitement du fichier
            'categorie'   => trim((string) ($_POST['categorie'] ?? '')) === ''
                                ? null
                                : (int) $_POST['categorie'],
            'club'        => $this->clubPourSaisie(),
            'exercice'    => 0,
        ];
    }

    private function clubPourSaisie(): int
    {
        if (!est_bureau()) {
            return (int) ($_SESSION['club_id'] ?? 0);
        }

        return (int) ($_POST['club'] ?? 0);
    }

    /**
     * @param array<string,mixed> $d
     */
    private function valider(array $d): string
    {
        if ($d['prenom'] === '' || $d['nom'] === '') {
            return 'Le nom et le prénom du bénéficiaire sont obligatoires.';
        }

        if (mb_strlen($d['prenom']) > 50 || mb_strlen($d['nom']) > 100) {
            return 'Le nom ou le prénom du bénéficiaire est trop long.';
        }

        // L'email est facultatif : le bénéficiaire peut être remboursé en
        // espèces ou de la main à la main. S'il est fourni, il doit être
        // exploitable — c'est par là qu'on le préviendra.
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            return 'L\'adresse email du bénéficiaire n\'est pas valide.';
        }

        if ($d['description'] === '') {
            return 'Le libellé de l\'achat est obligatoire.';
        }

        if (mb_strlen($d['description']) > 250) {
            return 'Le libellé est limité à 250 caractères.';
        }

        if (!preg_match('/^\d{1,8}([.]\d{1,2})?$/', $d['montant']) || (float) $d['montant'] <= 0) {
            return 'Le montant doit être un nombre positif (ex. 42.50).';
        }

        $date = DateTime::createFromFormat('Y-m-d', $d['achat']);

        if ($date === false || $date->format('Y-m-d') !== $d['achat']) {
            return 'La date d\'achat n\'est pas valide.';
        }

        // Un achat ne peut pas avoir eu lieu demain.
        if ($d['achat'] > date('Y-m-d')) {
            return 'La date d\'achat ne peut pas être dans le futur.';
        }

        if ($d['club'] <= 0 || Club::findById($d['club']) === null) {
            return 'Choisissez un club.';
        }

        if ($d['categorie'] !== null && !Category::existe((int) $d['categorie'])) {
            return 'Catégorie inconnue.';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function saisieVersFormulaire(int $id, array $d): array
    {
        return [
            'ReimbursementID'       => $id,
            'Amount'                => $d['montant'],
            'Purchase_Date'         => $d['achat'],
            'Description'           => $d['description'],
            'Beneficiary_FirstName' => $d['prenom'],
            'Beneficiary_LastName'  => $d['nom'],
            'Beneficiary_Email'     => $d['email'],
            'CategoryID'            => $d['categorie'],
            'ClubID'                => $d['club'],
            'Status'                => 'en_attente',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function demandeAutorisee(int $id): array
    {
        $demande = Reimbursement::findById($id);

        if ($demande === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        if (!peut_voir_club((int) $demande['ClubID'])) {
            refuser_acces();
        }

        return $demande;
    }

    private function clubImpose(): int
    {
        if (!est_bureau()) {
            return (int) ($_SESSION['club_id'] ?? 0);
        }

        return (int) ($_GET['club'] ?? 0);
    }

    /**
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

    private function exigerExerciceOuvert(): void
    {
        if (exercice_consulte() === null || !exercice_consulte_est_actif()) {
            message_flash('erreur', 'Cet exercice est clos : les remboursements n\'y sont plus modifiables.');
            rediriger('?page=remboursements');
        }
    }
}
