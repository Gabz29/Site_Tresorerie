<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Statistiques.php';
require_once __DIR__ . '/../models/FiltreStats.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/FiscalYear.php';
require_once __DIR__ . '/../models/Reimbursement.php';

/**
 * ============================================================================
 *  CONTRÔLEUR DU TABLEAU DE BORD
 * ============================================================================
 *
 *  Page d'accueil de l'application : la situation de l'exercice consulté en
 *  un coup d'œil.
 *
 *  Ce qu'on y montre dépend du rôle :
 *    - bureau      : tous les clubs, plus la répartition par club, plus le
 *                    choix du périmètre BDE / clubs
 *    - responsable : uniquement son club
 *
 *  Le contrôleur ne calcule rien lui-même : il demande les agrégats à
 *  Statistiques et les alertes à Budget, déjà utilisées par la page des
 *  budgets. Deux écrans qui afficheraient des chiffres calculés
 *  différemment finiraient par se contredire.
 * ============================================================================
 */
class DashboardController
{
    public function index(): void
    {
        $exercice = exercice_consulte();

        if ($exercice === null) {
            $titre         = 'Tableau de bord';
            $aucunExercice = true;

            require __DIR__ . '/../views/dashboard/index.php';
            return;
        }

        $exerciceId = (int) $exercice['FiscalYearID'];
        $debut      = (string) $exercice['Start_Date'];
        $fin        = (string) $exercice['End_Date'];

        /*
         * ⚠ LE PÉRIMÈTRE SE DÉCIDE ICI, PAS DANS L'URL.
         *
         * clubId : 0 = tous les clubs. Un responsable est ramené au sien
         * quoi qu'il envoie — c'est la même règle que partout ailleurs, et
         * FiltreStats fait primer le club sur le périmètre BDE/clubs.
         *
         * portee : n'a de sens que pour le bureau. Un responsable qui
         * ajouterait &portee=bde à la main obtiendrait 'tout', c'est-à-dire
         * son club et rien d'autre.
         */
        $clubId = est_bureau() ? 0 : (int) ($_SESSION['club_id'] ?? 0);
        $portee = est_bureau() ? (string) ($_GET['portee'] ?? 'tout') : 'tout';
        $mois   = (string) ($_GET['mois'] ?? '');

        /*
         * Les clubs cochés arrivent en clubs[]=2&clubs[]=5. On force le
         * tableau : une URL comme ?clubs=2 (sans crochets) donnerait une
         * chaîne, et array_map() plus loin échouerait sur un type inattendu.
         * Un responsable n'y a pas droit — de toute façon FiltreStats fait
         * primer son club imposé, mais autant ne pas lui faire croire que
         * la sélection a été prise en compte.
         */
        $clubsCoches = est_bureau() ? (array) ($_GET['clubs'] ?? []) : [];

        // FiltreStats normalise lui-même : un mois mal formé ou un périmètre
        // inconnu retombent sur « tout l'exercice » / « tout ».
        $filtre = new FiltreStats($clubId, $mois === '' ? null : $mois, $portee, $clubsCoches);

        // Liste du menu déroulant. Le BDE en est absent : il a son propre
        // bouton, et ce n'est pas un club.
        $clubsFiltrables = est_bureau() ? Club::sansBde() : [];

        /*
         * DEUX JEUX DE CHIFFRES CLÉS, ET C'EST INTENTIONNEL.
         *
         * $kpis suit la période choisie : « qu'a-t-on encaissé et dépensé
         * en mars » est une question légitime.
         *
         * $kpisAnnee porte toujours sur l'exercice entier, parce que le
         * SOLDE est une notion cumulée : l'argent disponible aujourd'hui ne
         * dépend pas du mois qu'on regarde. Un « solde de mars » calculé
         * sur les seuls mouvements de mars serait un chiffre faux, et
         * d'autant plus dangereux qu'il aurait l'air juste.
         */
        $kpis      = Statistiques::kpis($exerciceId, $filtre);
        $kpisAnnee = $filtre->filtrePeriode()
            ? Statistiques::kpis($exerciceId, $filtre->surToutLExercice())
            : $kpis;

        $recus = Statistiques::versementsRecus($exerciceId, $filtre->surToutLExercice());

        // Solde = versements reçus + recettes − dépenses validées.
        // Même formule que la page des budgets et que la courbe d'évolution.
        $solde = (float) $recus + (float) $kpisAnnee['recettes'] - (float) $kpisAnnee['depenses'];

        // Séries chronologiques : elles ignorent la période (cf. Statistiques).
        $parMois        = Statistiques::parMois($exerciceId, $debut, $fin, $filtre);
        $evolution      = Statistiques::evolutionSolde($exerciceId, $debut, $fin, $filtre);
        $remboursements = Statistiques::remboursementsParEtat($exerciceId, $debut, $fin, $filtre);

        /*
         * COMPARAISON AVEC L'EXERCICE PRÉCÉDENT.
         *
         * On ne garde que la suite des soldes, sans les libellés : les deux
         * années sont superposées par RANG de mois (le 3ᵉ mois de l'an
         * dernier face au 3ᵉ mois de cette année), et non par date — sans
         * quoi rien ne se superposerait jamais, les mois n'étant pas les
         * mêmes d'un exercice à l'autre.
         *
         * Le filtre suit : comparer le BDE de cette année aux clubs de
         * l'an dernier n'aurait aucun sens.
         */
        $exercicePrecedent = FiscalYear::precedent($exerciceId);
        $comparaison       = [];

        if ($exercicePrecedent !== null) {
            $comparaison = array_column(
                Statistiques::evolutionSolde(
                    (int) $exercicePrecedent['FiscalYearID'],
                    (string) $exercicePrecedent['Start_Date'],
                    (string) $exercicePrecedent['End_Date'],
                    $filtre
                ),
                'solde'
            );

            /*
             * Un exercice précédent SANS AUCUN MOUVEMENT ne se compare pas :
             * son tracé serait une ligne plate à zéro, que l'on prendrait
             * pour une année catastrophique au lieu d'une année non saisie.
             * Mieux vaut ne rien montrer que montrer un faux repère.
             */
            if (array_sum(array_map('abs', $comparaison)) < 0.01) {
                $comparaison       = [];
                $exercicePrecedent = null;
            }
        }

        // Classements : eux suivent la période.
        // Dépenses ET recettes : on veut voir où part l'argent, mais aussi
        // d'où il vient — une soirée qui coûte 8 900 € et en rapporte 2 300
        // ne se juge pas sur la seule colonne des dépenses.
        $parCategorie = Statistiques::montantsParCategorie($exerciceId, $filtre);
        $parPole      = Statistiques::montantsParPole($exerciceId, $filtre);

        $recettesParCategorie = Statistiques::montantsParCategorie($exerciceId, $filtre, 'recette');
        $recettesParPole      = Statistiques::montantsParPole($exerciceId, $filtre, 'recette');

        $dernieres = Statistiques::dernieresTransactions($exerciceId, $filtre);

        /*
         * Alertes : on réutilise Budget::avecSoldes(), qui porte déjà la
         * règle (rouge si solde négatif, orange sous 20 % de l'enveloppe).
         * La dupliquer ici garantirait qu'un jour les deux écrans ne
         * s'accordent plus.
         *
         * Elles ne suivent PAS la période : un club à découvert le reste,
         * qu'on regarde mars ou l'année. Les masquer parce qu'on a filtré
         * sur un mois calme serait exactement l'inverse du but d'une alerte.
         */
        $alertes = [];

        foreach (Budget::avecSoldes($exerciceId) as $budget) {
            if ($budget['Alerte'] === '') {
                continue;
            }

            if ($this->dansLePerimetre($budget, $filtre)) {
                $alertes[] = $budget;
            }
        }

        // Suit le périmètre choisi, mais pas la période : une demande en
        // attente réclame une action quel que soit le mois consulté.
        $enAttente = Reimbursement::compterEnAttente($exerciceId, $filtre);

        // Liste déroulante des mois : les mêmes que ceux des graphiques.
        $moisDisponibles = $this->moisDeLExercice($debut, $fin);

        $titre         = 'Tableau de bord';
        $aucunExercice = false;

        require __DIR__ . '/../views/dashboard/index.php';
    }

    /**
     * Ce budget entre-t-il dans le périmètre consulté ?
     *
     * Même logique que FiltreStats::sql(), mais appliquée en PHP : les
     * budgets arrivent déjà chargés par Budget::avecSoldes(), qui sert aussi
     * à la page des budgets. Relancer une requête filtrée juste pour les
     * alertes ferait calculer deux fois la même chose — au risque que les
     * deux écrans finissent par diverger.
     *
     * ⚠ MÊME HIÉRARCHIE QUE FiltreStats::sql(), et dans le même ordre :
     * club imposé, puis clubs cochés, puis périmètre. Si les deux
     * divergeaient, on verrait s'afficher l'alerte d'un club absent des
     * graphiques — ou l'inverse, plus grave : un club à découvert filtré
     * hors de l'écran alors qu'il figure dans les chiffres.
     *
     * @param array<string,mixed> $budget
     */
    private function dansLePerimetre(array $budget, FiltreStats $filtre): bool
    {
        if ($filtre->clubId > 0) {
            return (int) $budget['ClubID'] === $filtre->clubId;
        }

        if ($filtre->selectionDeClubs()) {
            return in_array((int) $budget['ClubID'], $filtre->clubIds, true);
        }

        return match ($filtre->portee) {
            'bde'   => (int) $budget['ClubIsBDE'] === 1,
            'clubs' => (int) $budget['ClubIsBDE'] !== 1,
            default => true,
        };
    }

    /**
     * Les mois de l'exercice, pour la liste déroulante du filtre.
     *
     * @return array<string,string> AAAA-MM => libellé, ex. « mars 2027 »
     */
    private function moisDeLExercice(string $debut, string $fin): array
    {
        $noms = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        $mois    = [];
        $curseur = new DateTimeImmutable(substr($debut, 0, 7) . '-01');
        $dernier = new DateTimeImmutable(substr($fin, 0, 7) . '-01');

        while ($curseur <= $dernier) {
            $mois[$curseur->format('Y-m')] =
                $noms[(int) $curseur->format('n')] . ' ' . $curseur->format('Y');

            $curseur = $curseur->modify('+1 month');
        }

        return $mois;
    }
}
