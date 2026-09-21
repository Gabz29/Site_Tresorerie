<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Disbursement.php';
require_once __DIR__ . '/../models/Club.php';

/**
 * ============================================================================
 *  CONTRÔLEUR DES BUDGETS ET VERSEMENTS
 * ============================================================================
 *
 *  Réservé au bureau : allouer une enveloppe et constater un versement sont
 *  des décisions de trésorerie.
 *
 *  Les montants affichés portent toujours sur l'EXERCICE CONSULTÉ, jamais
 *  sur l'exercice actif : c'est ce qui permet de revenir sur une année
 *  passée et d'y retrouver les chiffres de l'époque.
 * ============================================================================
 */
class BudgetController
{
    /**
     * Tableau des budgets de l'exercice — URL : ?page=budgets
     */
    public function index(): void
    {
        exiger_bureau();

        $exercice = exercice_consulte();

        if ($exercice === null) {
            // Aucun exercice en base : rien à afficher, et surtout rien à
            // calculer. On le dit plutôt que de montrer un tableau vide.
            $titre    = 'Budgets';
            $budgets  = [];
            $totaux   = null;
            $flash    = lire_flash();

            require __DIR__ . '/../views/budgets/index.php';
            return;
        }

        $titre   = 'Budgets';
        $budgets = Budget::avecSoldes((int) $exercice['FiscalYearID']);
        $totaux  = $this->totaliser($budgets);
        $flash   = lire_flash();

        require __DIR__ . '/../views/budgets/index.php';
    }

    /**
     * Détail d'un budget et de ses tranches — URL : ?page=budget&id=3
     */
    public function show(): void
    {
        exiger_bureau();

        $id     = (int) ($_GET['id'] ?? 0);
        $budget = Budget::findById($id);

        if ($budget === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        // On récupère la version enrichie (soldes, alerte) pour afficher les
        // mêmes indicateurs que dans la liste, calculés au même endroit.
        $detail = Budget::findByClubEtExercice(
            (int) $budget['ClubID'],
            (int) $budget['FiscalYearID']
        );

        $club     = Club::findById((int) $budget['ClubID']);
        $tranches = Disbursement::findByBudget($id);
        $titre    = 'Budget — ' . ($club['Name'] ?? '');
        $flash    = lire_flash();

        require __DIR__ . '/../views/budgets/show.php';
    }

    /**
     * Formulaire d'allocation — URL : ?page=budget-nouveau
     */
    public function create(): void
    {
        exiger_bureau();
        $this->exigerExerciceOuvert();

        $exercice = exercice_consulte();

        $titre  = 'Allouer un budget';
        $clubs  = Budget::clubsSansBudget((int) $exercice['FiscalYearID']);
        $erreur = '';
        $saisie = ['club' => '', 'montant' => '', 'tranches' => '2', 'notes' => ''];

        require __DIR__ . '/../views/budgets/form.php';
    }

    /**
     * Enregistre un nouveau budget et ses tranches (requête POST).
     */
    public function save(): void
    {
        exiger_bureau();
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $exercice   = exercice_consulte();
        $exerciceId = (int) $exercice['FiscalYearID'];

        $clubId   = (int) ($_POST['club'] ?? 0);
        $montant  = $this->normaliserMontant((string) ($_POST['montant'] ?? ''));
        $tranches = (int) ($_POST['tranches'] ?? 0);
        $notes    = trim((string) ($_POST['notes'] ?? ''));

        $erreur = $this->validerBudget($clubId, $montant, $tranches, $exerciceId);

        if ($erreur !== '') {
            $titre  = 'Allouer un budget';
            $clubs  = Budget::clubsSansBudget($exerciceId);
            $saisie = [
                'club'     => (string) $clubId,
                'montant'  => $montant,
                'tranches' => (string) $tranches,
                'notes'    => $notes,
            ];

            require __DIR__ . '/../views/budgets/form.php';
            return;
        }

        /*
         * ⚠ TRANSACTION SQL — le budget et ses tranches doivent naître
         * ensemble.
         *
         * Si le budget s'enregistrait et que la création des tranches
         * échouait, on obtiendrait un budget sans aucune tranche : le club
         * ne recevrait jamais rien, et rien ne le signalerait. C'est
         * d'ailleurs un rappel explicite de schema.sql — « un budget doit
         * avoir exactement Disbursement_Count lignes dans disbursements ».
         *
         * Comme pour l'activation d'un exercice : soit les deux, soit rien.
         */
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $budgetId = Budget::create(
                $clubId,
                $exerciceId,
                $montant,
                $tranches,
                $notes === '' ? null : $notes
            );

            Disbursement::creerTranches(
                $budgetId,
                $montant,
                $tranches,
                (string) $exercice['Start_Date']
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        message_flash('succes', 'Le budget a été alloué, avec ses ' . $tranches . ' tranche(s) de versement.');
        rediriger('?page=budget&id=' . $budgetId);
    }

    /**
     * Enregistre la réception d'une tranche (requête POST).
     */
    public function marquerRecu(): void
    {
        exiger_bureau();
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $tranche = $this->trancheDemandee();

        $montant = $this->normaliserMontant((string) ($_POST['montant'] ?? ''));
        $date    = trim((string) ($_POST['date'] ?? ''));

        if (!$this->estUnMontant($montant) || (float) $montant <= 0) {
            message_flash('erreur', 'Le montant versé doit être un nombre positif.');
            rediriger('?page=budget&id=' . (int) $tranche['BudgetID']);
        }

        if (!$this->estUneDate($date)) {
            message_flash('erreur', 'La date du versement n\'est pas valide.');
            rediriger('?page=budget&id=' . (int) $tranche['BudgetID']);
        }

        Disbursement::marquerRecue((int) $tranche['DisbursementID'], $montant, $date);

        message_flash('succes', 'Versement enregistré.');
        rediriger('?page=budget&id=' . (int) $tranche['BudgetID']);
    }

    /**
     * Annule une tranche qui ne sera pas versée (requête POST).
     */
    public function annulerTranche(): void
    {
        exiger_bureau();
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $tranche = $this->trancheDemandee();
        $motif   = trim((string) ($_POST['motif'] ?? ''));

        Disbursement::annuler(
            (int) $tranche['DisbursementID'],
            $motif === '' ? null : $motif
        );

        message_flash('succes', 'La tranche a été annulée. Le montant reste disponible pour un autre club.');
        rediriger('?page=budget&id=' . (int) $tranche['BudgetID']);
    }

    /**
     * Remet une tranche en attente de versement (requête POST).
     *
     * Permet de corriger une erreur de saisie sans passer par phpMyAdmin.
     */
    public function rouvrirTranche(): void
    {
        exiger_bureau();
        verifier_csrf();
        $this->exigerExerciceOuvert();

        $tranche = $this->trancheDemandee();

        Disbursement::remettreEnAttente((int) $tranche['DisbursementID']);

        message_flash('succes', 'La tranche est de nouveau en attente de versement.');
        rediriger('?page=budget&id=' . (int) $tranche['BudgetID']);
    }

    /**
     * Récupère la tranche visée par la requête, ou interrompt en 404.
     *
     * @return array<string,mixed>
     */
    private function trancheDemandee(): array
    {
        $tranche = Disbursement::findById((int) ($_POST['tranche'] ?? 0));

        if ($tranche === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        return $tranche;
    }

    /**
     * Interrompt si l'exercice consulté est clos.
     *
     * Un exercice clos est FIGÉ (décision du 20/09/2026) : on n'y ajoute ni
     * n'y modifie plus rien. Les boutons sont déjà masqués dans les vues,
     * mais masquer n'est pas protéger — cette vérification, côté serveur,
     * est la seule barrière réelle.
     */
    private function exigerExerciceOuvert(): void
    {
        if (exercice_consulte() === null || !exercice_consulte_est_actif()) {
            message_flash('erreur', 'Cet exercice est clos : il n\'est plus modifiable.');
            rediriger('?page=budgets');
        }
    }

    private function validerBudget(int $clubId, string $montant, int $tranches, int $exerciceId): string
    {
        if ($clubId <= 0) {
            return 'Veuillez choisir un club.';
        }

        if (Club::findById($clubId) === null) {
            return 'Ce club n\'existe pas.';
        }

        if (Budget::existePourClub($clubId, $exerciceId)) {
            // Doublé par la contrainte budgets_Club_Year_UQ en base : ici
            // pour le message, là-bas pour la garantie.
            return 'Ce club a déjà un budget sur cet exercice.';
        }

        if (!$this->estUnMontant($montant) || (float) $montant <= 0) {
            return 'Le montant doit être un nombre positif (ex. 1500.00).';
        }

        // DECIMAL(10,2) : 8 chiffres avant la virgule. Au-delà, MySQL
        // refuserait la valeur — autant le dire clairement ici.
        if ((float) $montant > 99999999.99) {
            return 'Le montant dépasse la limite autorisée.';
        }

        if ($tranches < 1 || $tranches > 12) {
            return 'Le nombre de tranches doit être compris entre 1 et 12.';
        }

        return '';
    }

    /**
     * Prépare un montant saisi pour la base.
     *
     * On accepte « 1500,50 » à la française, mais MySQL attend un point
     * décimal. La conversion se fait ici, une fois, plutôt que d'imposer
     * une notation à l'utilisateur.
     */
    private function normaliserMontant(string $valeur): string
    {
        return str_replace(',', '.', trim($valeur));
    }

    private function estUnMontant(string $valeur): bool
    {
        // On accepte la virgule ou le point, mais pas les lettres ni les
        // espaces : is_numeric() seul laisserait passer "1e5".
        return (bool) preg_match('/^\d{1,8}([.,]\d{1,2})?$/', $valeur);
    }

    private function estUneDate(string $valeur): bool
    {
        $date = DateTime::createFromFormat('Y-m-d', $valeur);

        return $date !== false && $date->format('Y-m-d') === $valeur;
    }

    /**
     * Additionne les colonnes pour la ligne de total du tableau.
     *
     * @param array<int,array<string,mixed>> $budgets
     * @return array<string,float>
     */
    private function totaliser(array $budgets): array
    {
        $totaux = [
            'alloue'   => 0.0,
            'recus'    => 0.0,
            'avenir'   => 0.0,
            'depenses' => 0.0,
            'recettes' => 0.0,
            'solde'    => 0.0,
        ];

        foreach ($budgets as $b) {
            $totaux['alloue']   += (float) $b['Planned_Amount'];
            $totaux['recus']    += (float) $b['VersementsRecus'];
            $totaux['avenir']   += (float) $b['VersementsAVenir'];
            $totaux['depenses'] += (float) $b['Depenses'];
            $totaux['recettes'] += (float) $b['Recettes'];
            $totaux['solde']    += (float) $b['SoldeDisponible'];
        }

        return $totaux;
    }
}
