<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `budgets` (enveloppe annuelle d'un club)
 * ============================================================================
 *
 *  Un budget = un club × un exercice, avec un montant prévisionnel et un
 *  nombre de tranches de versement. La contrainte budgets_Club_Year_UQ
 *  garantit qu'un club ne peut pas avoir deux budgets sur le même exercice.
 *
 *  ⚠ NE PAS CONFONDRE TROIS MONTANTS :
 *    - Planned_Amount  : l'enveloppe ANNONCÉE au club en début d'année
 *    - versements reçus : ce qui lui a été RÉELLEMENT versé à ce jour
 *    - solde disponible : ce dont il dispose MAINTENANT, une fois ses
 *                         dépenses payées et ses recettes encaissées
 *
 *  C'est le troisième qui compte pour alerter : un club peut avoir un gros
 *  budget annoncé et n'avoir rien reçu, ou avoir tout dépensé mais encaissé
 *  autant de recettes. Voir avecSoldes().
 * ============================================================================
 */
class Budget
{
    /**
     * Budgets d'un exercice, avec pour chacun les montants calculés.
     *
     * Tout est calculé par MySQL en une seule requête plutôt qu'en bouclant
     * en PHP : une requête par club multiplierait les allers-retours avec la
     * base pour un résultat identique.
     *
     * Les sous-requêtes sont nécessaires car on agrège deux tables
     * indépendantes (versements et transactions). Les joindre directement
     * produirait un produit cartésien : chaque versement serait compté
     * autant de fois qu'il y a de transactions, et les totaux seraient faux.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function avecSoldes(int $fiscalYearId): array
    {
        $sql = "
            SELECT
                b.BudgetID,
                b.Planned_Amount,
                b.Disbursement_Count,
                b.Notes,
                c.ClubID,
                c.Name        AS ClubName,
                c.IsActive    AS ClubIsActive,

                -- Ce qui a été réellement versé au club
                COALESCE((
                    SELECT SUM(d.Actual_Amount)
                    FROM disbursements d
                    WHERE d.BudgetID = b.BudgetID AND d.Status = 'recu'
                ), 0) AS VersementsRecus,

                -- Ce qui reste à verser (tranches ni reçues ni annulées)
                COALESCE((
                    SELECT SUM(d.Planned_Amount)
                    FROM disbursements d
                    WHERE d.BudgetID = b.BudgetID AND d.Status = 'prevu'
                ), 0) AS VersementsAVenir,

                COALESCE((
                    SELECT SUM(t.Amount)
                    FROM transactions t
                    WHERE t.ClubID = b.ClubID
                      AND t.FiscalYearID = b.FiscalYearID
                      AND t.Status = 'valide'
                      AND t.Type = 'depense'
                ), 0) AS Depenses,

                COALESCE((
                    SELECT SUM(t.Amount)
                    FROM transactions t
                    WHERE t.ClubID = b.ClubID
                      AND t.FiscalYearID = b.FiscalYearID
                      AND t.Status = 'valide'
                      AND t.Type = 'recette'
                ), 0) AS Recettes

            FROM budgets b
            INNER JOIN clubs c ON c.ClubID = b.ClubID
            WHERE b.FiscalYearID = :exercice
            ORDER BY c.Name
        ";

        $stmt = db()->prepare($sql);
        $stmt->execute([':exercice' => $fiscalYearId]);

        $budgets = $stmt->fetchAll();

        // Le solde et l'alerte se déduisent des montants ci-dessus : on les
        // calcule ici plutôt que dans la vue, pour que tous les écrans
        // affichent la même chose sans réécrire la règle à chaque fois.
        foreach ($budgets as &$b) {
            $b = self::ajouterIndicateurs($b);
        }
        unset($b);   // rompt la référence, sinon la dernière ligne du
                     // tableau reste liée à $b et peut être écrasée plus tard

        return $budgets;
    }

    /**
     * Budget d'un club pour un exercice, avec ses indicateurs.
     *
     * @return array<string,mixed>|null
     */
    public static function findByClubEtExercice(int $clubId, int $fiscalYearId): ?array
    {
        foreach (self::avecSoldes($fiscalYearId) as $budget) {
            if ((int) $budget['ClubID'] === $clubId) {
                return $budget;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT BudgetID, Planned_Amount, Disbursement_Count, Notes, ClubID, FiscalYearID
             FROM budgets WHERE BudgetID = :id'
        );

        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Ce club a-t-il déjà un budget sur cet exercice ?
     *
     * Double la contrainte budgets_Club_Year_UQ : ici pour un message
     * clair, en base pour la garantie.
     */
    public static function existePourClub(int $clubId, int $fiscalYearId, ?int $exclureId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM budgets WHERE ClubID = :club AND FiscalYearID = :exercice';

        if ($exclureId !== null) {
            $sql .= ' AND BudgetID <> :id';
        }

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':club', $clubId, PDO::PARAM_INT);
        $stmt->bindValue(':exercice', $fiscalYearId, PDO::PARAM_INT);

        if ($exclureId !== null) {
            $stmt->bindValue(':id', $exclureId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Clubs actifs n'ayant pas encore de budget sur cet exercice.
     *
     * Sert à alimenter la liste déroulante du formulaire d'allocation : on
     * ne propose que ce qui est possible, plutôt que de laisser choisir un
     * club puis afficher « ce club a déjà un budget ».
     *
     * @return array<int,array<string,mixed>>
     */
    public static function clubsSansBudget(int $fiscalYearId): array
    {
        $stmt = db()->prepare(
            'SELECT c.ClubID, c.Name
             FROM clubs c
             WHERE c.IsActive = 1
               AND c.ClubID NOT IN (
                   SELECT b.ClubID FROM budgets b WHERE b.FiscalYearID = :exercice
               )
             ORDER BY c.Name'
        );

        $stmt->execute([':exercice' => $fiscalYearId]);

        return $stmt->fetchAll();
    }

    /**
     * Crée un budget et renvoie son identifiant.
     *
     * Les tranches de versement ne sont PAS créées ici : c'est
     * BudgetController qui orchestre les deux dans une même transaction SQL,
     * pour qu'un budget ne puisse jamais exister sans ses tranches.
     */
    public static function create(
        int $clubId,
        int $fiscalYearId,
        string $montant,
        int $nbTranches,
        ?string $notes
    ): int {
        $stmt = db()->prepare(
            'INSERT INTO budgets (Planned_Amount, Disbursement_Count, Notes, ClubID, FiscalYearID)
             VALUES (:montant, :nb, :notes, :club, :exercice)'
        );

        $stmt->execute([
            ':montant'  => $montant,
            ':nb'       => $nbTranches,
            ':notes'    => $notes,
            ':club'     => $clubId,
            ':exercice' => $fiscalYearId,
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Ajoute le solde disponible et le niveau d'alerte à une ligne de budget.
     *
     * ⚠ RÈGLE D'ALERTE — sur le SOLDE, pas sur la consommation du budget.
     *
     * Un club qui a dépensé toute son enveloppe mais encaissé autant de
     * recettes n'est en danger d'aucune façon : l'alerter serait du bruit,
     * et le bruit finit par être ignoré. Ce qui compte est ce dont il
     * dispose réellement.
     *
     * On se base sur les versements REÇUS, pas sur l'enveloppe annoncée : un
     * club doté de 1 500 € mais n'ayant touché que 750 € ne dispose que de
     * 750 €.
     *
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private static function ajouterIndicateurs(array $b): array
    {
        $recus    = (float) $b['VersementsRecus'];
        $depenses = (float) $b['Depenses'];
        $recettes = (float) $b['Recettes'];
        $alloue   = (float) $b['Planned_Amount'];

        $solde = $recus + $recettes - $depenses;

        $b['SoldeDisponible'] = $solde;

        // Part de l'enveloppe annuelle déjà consommée — information de
        // pilotage, qui ne déclenche aucune alerte à elle seule.
        $b['TauxConsommation'] = $alloue > 0
            ? round(($depenses / $alloue) * 100)
            : null;

        if ($solde < 0) {
            $b['Alerte'] = 'rouge';     // dépensé plus que reçu
        } elseif ($alloue > 0 && $solde < $alloue * 0.20) {
            $b['Alerte'] = 'orange';    // il reste peu
        } else {
            $b['Alerte'] = '';
        }

        return $b;
    }
}
