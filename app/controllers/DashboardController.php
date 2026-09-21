<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Statistiques.php';
require_once __DIR__ . '/../models/Budget.php';
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
 *    - bureau      : tous les clubs, plus la répartition par club
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

        // 0 = tous les clubs. Un responsable est restreint au sien, quoi
        // qu'il envoie dans l'URL.
        $clubId = est_bureau() ? 0 : (int) ($_SESSION['club_id'] ?? 0);

        $kpis      = Statistiques::kpis($exerciceId, $clubId);
        $recus     = Statistiques::versementsRecus($exerciceId, $clubId);

        /*
         * Solde = versements reçus + recettes − dépenses validées.
         * Même formule que la page des budgets : c'est ce dont on dispose
         * réellement, pas l'enveloppe annoncée.
         */
        $solde = (float) $recus + (float) $kpis['recettes'] - (float) $kpis['depenses'];

        $parMois       = Statistiques::parMois(
            $exerciceId,
            (string) $exercice['Start_Date'],
            (string) $exercice['End_Date'],
            $clubId
        );
        $parCategorie  = Statistiques::depensesParCategorie($exerciceId, $clubId);
        $parPole       = Statistiques::depensesParPole($exerciceId, $clubId);
        $parClub       = est_bureau() ? Statistiques::depensesParClub($exerciceId) : [];
        $dernieres     = Statistiques::dernieresTransactions($exerciceId, $clubId);

        /*
         * Alertes : on réutilise Budget::avecSoldes(), qui porte déjà la
         * règle (rouge si solde négatif, orange sous 20 % de l'enveloppe).
         * La dupliquer ici garantirait qu'un jour les deux écrans ne
         * s'accordent plus.
         */
        $alertes = [];

        foreach (Budget::avecSoldes($exerciceId) as $budget) {
            if ($budget['Alerte'] !== '' && ($clubId === 0 || (int) $budget['ClubID'] === $clubId)) {
                $alertes[] = $budget;
            }
        }

        $enAttente = Reimbursement::compterEnAttente($exerciceId, $clubId);

        $titre         = 'Tableau de bord';
        $aucunExercice = false;

        require __DIR__ . '/../views/dashboard/index.php';
    }
}
