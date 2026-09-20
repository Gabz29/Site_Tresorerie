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
        $saisie     = ['libelle' => '', 'debut' => '', 'fin' => ''];

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

        $erreur = $this->validerExercice($libelle, $debut, $fin);

        if ($erreur !== '') {
            // On réaffiche la page avec la saisie conservée, plutôt que de
            // rediriger et de tout faire perdre.
            $titre     = 'Exercices budgétaires';
            $exercices = FiscalYear::getAll();
            $actif     = FiscalYear::getActive();
            $flash     = null;
            $saisie    = ['libelle' => $libelle, 'debut' => $debut, 'fin' => $fin];

            require __DIR__ . '/../views/fiscalyear/index.php';
            return;
        }

        FiscalYear::create($libelle, $debut, $fin);

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
}
