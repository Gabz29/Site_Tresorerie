<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/FiscalYear.php';

/**
 * ============================================================================
 *  CONTRÔLEUR DES EXERCICES BUDGÉTAIRES
 * ============================================================================
 *
 *  Réservé au bureau : changer d'exercice actif déplace la saisie de toute
 *  l'application d'une année sur l'autre.
 *
 *  Cet écran n'était pas prévu par le plan (la tâche 2.5 ne mentionne qu'un
 *  modèle), mais sans lui il faudrait écrire du SQL à la main dans
 *  phpMyAdmin chaque rentrée pour ouvrir l'exercice suivant.
 * ============================================================================
 */
class FiscalYearController
{
    /**
     * Liste des exercices — URL : ?page=exercices
     */
    public function index(): void
    {
        exiger_bureau();

        $titre      = 'Exercices budgétaires';
        $exercices  = FiscalYear::getAll();
        $actif      = FiscalYear::getActive();
        $flash      = lire_flash();
        $erreur     = '';
        $saisie     = ['libelle' => '', 'debut' => '', 'fin' => '', 'enveloppe' => ''];

        require __DIR__ . '/../views/fiscalyear/index.php';
    }

    /**
     * Crée un exercice (requête POST).
     */
    public function save(): void
    {
        exiger_bureau();
        verifier_csrf();

        $libelle = trim((string) ($_POST['libelle'] ?? ''));
        $debut   = trim((string) ($_POST['debut'] ?? ''));
        $fin     = trim((string) ($_POST['fin'] ?? ''));

        // Facultative : l'ISEN n'annonce pas toujours le montant en
        // septembre. Vide = 0, et le chiffre se corrigera plus tard.
        $enveloppeSaisie = trim((string) ($_POST['enveloppe'] ?? ''));
        $enveloppe       = $this->normaliserMontant($enveloppeSaisie);

        $erreur = $this->validerExercice($libelle, $debut, $fin);

        if ($erreur === '' && $enveloppeSaisie !== '' && !$this->estUnMontant($enveloppe)) {
            $erreur = 'L\'enveloppe des clubs doit être un montant (ex. 12000 ou 12000,50).';
        }

        if ($erreur !== '') {
            // On réaffiche la page avec la saisie conservée, plutôt que de
            // rediriger et de tout faire perdre.
            $titre     = 'Exercices budgétaires';
            $exercices = FiscalYear::getAll();
            $actif     = FiscalYear::getActive();
            $flash     = null;
            $saisie    = [
                'libelle'   => $libelle,
                'debut'     => $debut,
                'fin'       => $fin,
                'enveloppe' => $enveloppeSaisie,
            ];

            require __DIR__ . '/../views/fiscalyear/index.php';
            return;
        }

        FiscalYear::create($libelle, $debut, $fin, $enveloppeSaisie === '' ? '0' : $enveloppe);

        // Créé inactif volontairement : ouvrir un exercice et basculer
        // dessus sont deux décisions distinctes. On prépare souvent l'année
        // suivante avant que la précédente ne soit close.
        message_flash('succes', 'L\'exercice a été créé. Il reste à l\'activer quand vous le souhaitez.');

        rediriger('?page=exercices');
    }

    /**
     * Rend un exercice actif (requête POST).
     */
    public function activate(): void
    {
        exiger_bureau();
        verifier_csrf();

        $id = (int) ($_POST['id'] ?? 0);

        if (FiscalYear::findById($id) === null) {
            message_flash('erreur', 'Cet exercice n\'existe pas.');
            rediriger('?page=exercices');
        }

        FiscalYear::activate($id);

        message_flash('succes', 'L\'exercice actif a été changé.');
        rediriger('?page=exercices');
    }

    /**
     * Met à jour l'enveloppe reçue de l'ISEN pour les clubs (requête POST).
     *
     * ⚠ MODIFIABLE APRÈS COUP, VOLONTAIREMENT.
     *
     * L'école annonce rarement le montant définitif à la rentrée : il est
     * fréquent de démarrer l'année sur une estimation, puis de la corriger.
     * Sans ce formulaire, l'écran des budgets afficherait un « reste à
     * répartir » faux toute l'année, et il faudrait passer par phpMyAdmin.
     *
     * Aucune vérification de cohérence avec les budgets déjà alloués : le
     * trésorier a le droit de saisir un montant inférieur à ce qu'il a déjà
     * réparti — c'est précisément le genre de dépassement que l'écran des
     * budgets doit rendre VISIBLE, pas empêcher d'enregistrer.
     */
    public function majEnveloppe(): void
    {
        exiger_bureau();
        verifier_csrf();

        $id      = (int) ($_POST['id'] ?? 0);
        $montant = $this->normaliserMontant((string) ($_POST['enveloppe'] ?? ''));

        if (FiscalYear::findById($id) === null) {
            message_flash('erreur', 'Cet exercice n\'existe pas.');
            rediriger('?page=exercices');
        }

        if (!$this->estUnMontant($montant)) {
            message_flash('erreur', 'L\'enveloppe des clubs doit être un montant (ex. 12000 ou 12000,50).');
            rediriger('?page=exercices');
        }

        FiscalYear::majEnveloppeClubs($id, $montant);

        message_flash('succes', 'L\'enveloppe destinée aux clubs a été enregistrée.');
        rediriger('?page=exercices');
    }

    /**
     * Contrôle les données d'un exercice ; renvoie '' si tout est correct.
     */
    private function validerExercice(string $libelle, string $debut, string $fin): string
    {
        if ($libelle === '' || $debut === '' || $fin === '') {
            return 'Tous les champs sont obligatoires.';
        }

        if (mb_strlen($libelle) > 10) {
            return 'Le libellé est limité à 10 caractères (ex. 2026-2027).';
        }

        /*
         * Les champs de type "date" du navigateur envoient AAAA-MM-JJ, mais
         * une requête peut arriver sans passer par le formulaire : on vérifie
         * que ce sont de vraies dates. DateTime::createFromFormat accepterait
         * le 31 février ; la comparaison avec la valeur reformatée écarte ces
         * dates qui n'existent pas.
         */
        if (!$this->estUneDate($debut) || !$this->estUneDate($fin)) {
            return 'Les dates saisies ne sont pas valides.';
        }

        if ($debut >= $fin) {
            // Comparaison de chaînes volontaire : au format AAAA-MM-JJ,
            // l'ordre alphabétique correspond à l'ordre chronologique.
            return 'La date de début doit précéder la date de fin.';
        }

        if (FiscalYear::libelleExiste($libelle)) {
            return 'Un exercice porte déjà ce libellé.';
        }

        if (FiscalYear::chevauche($debut, $fin)) {
            return 'Cette période recouvre celle d\'un exercice existant. '
                 . 'Une transaction serait alors rattachable à deux exercices.';
        }

        return '';
    }

    private function estUneDate(string $valeur): bool
    {
        $date = DateTime::createFromFormat('Y-m-d', $valeur);

        return $date !== false && $date->format('Y-m-d') === $valeur;
    }

    /**
     * Prépare un montant saisi pour la base : « 12 000,50 » → « 12000.50 ».
     *
     * On accepte la notation française, mais MySQL attend un point décimal.
     * Même règle que dans BudgetController, pour que les deux écrans se
     * comportent pareil.
     */
    private function normaliserMontant(string $valeur): string
    {
        return str_replace(',', '.', trim($valeur));
    }

    private function estUnMontant(string $valeur): bool
    {
        // is_numeric() seul accepterait « 1e5 » : on impose une écriture
        // décimale ordinaire, deux chiffres après la virgule au maximum.
        return (bool) preg_match('/^\d{1,8}([.,]\d{1,2})?$/', $valeur);
    }
}
