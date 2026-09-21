<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `disbursements` (tranches de versement d'un budget)
 * ============================================================================
 *
 *  Le BDE encaisse la CVEC en octobre, puis répartit et échelonne les
 *  versements vers les clubs.
 *
 *  Trois états :
 *    'prevu'  — décidée, pas encore versée
 *    'recu'   — versée (montant et date réels renseignés)
 *    'annule' — décidée puis abandonnée. Le BDE se réserve le droit de ne
 *               pas verser à un club inactif et de réattribuer la somme.
 *               La tranche reste en base avec son motif : on garde la trace
 *               de la décision au lieu de l'effacer.
 * ============================================================================
 */
class Disbursement
{
    /** Rythme habituel des versements, par nombre de tranches. */
    private const MOIS_PAR_DEFAUT = [
        // Clubs : mi-octobre, puis fin janvier
        2 => [[10, 15], [1, 31]],
        // BDE : octobre, décembre, février, juin
        4 => [[10, 15], [12, 15], [2, 15], [6, 15]],
    ];

    /**
     * Tranches d'un budget, dans l'ordre.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function findByBudget(int $budgetId): array
    {
        $stmt = db()->prepare(
            'SELECT DisbursementID, Number, Planned_Amount, Actual_Amount,
                    Planned_Date, Actual_Date, Status, Notes
             FROM disbursements
             WHERE BudgetID = :budget
             ORDER BY Number'
        );

        $stmt->execute([':budget' => $budgetId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT d.DisbursementID, d.Number, d.Planned_Amount, d.Actual_Amount,
                    d.Planned_Date, d.Actual_Date, d.Status, d.Notes,
                    d.BudgetID, b.ClubID, b.FiscalYearID
             FROM disbursements d
             INNER JOIN budgets b ON b.BudgetID = d.BudgetID
             WHERE d.DisbursementID = :id'
        );

        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Crée les N tranches d'un budget, montants égaux et dates habituelles.
     *
     * ⚠ RÉPARTITION DES CENTIMES. 1 000 € en 3 tranches ne se divise pas en
     * trois parts égales : 333,33 × 3 = 999,99. Le centime manquant est
     * ajouté à la dernière tranche, pour que la somme des tranches soit
     * TOUJOURS exactement égale au montant du budget. Sans cela, un écart
     * d'un centime apparaîtrait dans les totaux — invisible, mais faux.
     */
    public static function creerTranches(
        int $budgetId,
        string $montantTotal,
        int $nbTranches,
        string $debutExercice
    ): void {
        // Les montants sont manipulés en CENTIMES (entiers) pour éviter les
        // arrondis des nombres à virgule flottante, qui font que
        // 0.1 + 0.2 ne vaut pas exactement 0.3 en informatique.
        $totalCentimes = (int) round(((float) $montantTotal) * 100);
        $partCentimes  = intdiv($totalCentimes, $nbTranches);
        $reste         = $totalCentimes - ($partCentimes * $nbTranches);

        $dates = self::datesParDefaut($nbTranches, $debutExercice);

        $stmt = db()->prepare(
            'INSERT INTO disbursements (Number, Planned_Amount, Planned_Date, Status, BudgetID)
             VALUES (:numero, :montant, :date, \'prevu\', :budget)'
        );

        for ($i = 1; $i <= $nbTranches; $i++) {
            $centimes = $partCentimes;

            if ($i === $nbTranches) {
                $centimes += $reste;   // la dernière absorbe l'arrondi
            }

            $stmt->execute([
                ':numero'  => $i,
                ':montant' => number_format($centimes / 100, 2, '.', ''),
                ':date'    => $dates[$i - 1],
                ':budget'  => $budgetId,
            ]);
        }
    }

    /**
     * Dates prévisionnelles des tranches, selon le rythme habituel.
     *
     * Un exercice court de septembre N à août N+1 : les mois de janvier à
     * août appartiennent donc à l'année civile SUIVANTE. Sans cette
     * correction, la tranche de « fin janvier » serait datée de janvier de
     * l'année écoulée, soit huit mois avant le début de l'exercice.
     *
     * @return array<int,string> dates au format AAAA-MM-JJ
     */
    private static function datesParDefaut(int $nbTranches, string $debutExercice): array
    {
        $anneeDebut = (int) date('Y', strtotime($debutExercice));
        $moisDebut  = (int) date('n', strtotime($debutExercice));

        $dates = [];

        if (isset(self::MOIS_PAR_DEFAUT[$nbTranches])) {
            foreach (self::MOIS_PAR_DEFAUT[$nbTranches] as [$mois, $jour]) {
                $annee = $mois >= $moisDebut ? $anneeDebut : $anneeDebut + 1;
                $dates[] = sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
            }

            return $dates;
        }

        // Nombre de tranches inhabituel (1, 3, 5...) : on les répartit
        // régulièrement à partir du deuxième mois de l'exercice.
        $intervalle = max(1, intdiv(12, $nbTranches));

        for ($i = 0; $i < $nbTranches; $i++) {
            $dates[] = date('Y-m-15', strtotime($debutExercice . ' +' . (1 + $i * $intervalle) . ' month'));
        }

        return $dates;
    }

    /**
     * Enregistre la réception d'une tranche.
     */
    public static function marquerRecue(int $id, string $montantReel, string $dateReelle): void
    {
        $stmt = db()->prepare(
            "UPDATE disbursements
             SET Actual_Amount = :montant, Actual_Date = :date, Status = 'recu'
             WHERE DisbursementID = :id"
        );

        $stmt->execute([
            ':montant' => $montantReel,
            ':date'    => $dateReelle,
            ':id'      => $id,
        ]);
    }

    /**
     * Annule une tranche qui ne sera pas versée, en conservant le motif.
     */
    public static function annuler(int $id, ?string $motif): void
    {
        $stmt = db()->prepare(
            "UPDATE disbursements
             SET Status = 'annule', Actual_Amount = NULL, Actual_Date = NULL, Notes = :motif
             WHERE DisbursementID = :id"
        );

        $stmt->execute([':motif' => $motif, ':id' => $id]);
    }

    /**
     * Remet une tranche en attente de versement.
     */
    public static function remettreEnAttente(int $id): void
    {
        $stmt = db()->prepare(
            "UPDATE disbursements
             SET Status = 'prevu', Actual_Amount = NULL, Actual_Date = NULL
             WHERE DisbursementID = :id"
        );

        $stmt->execute([':id' => $id]);
    }
}
