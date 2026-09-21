<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  AGRÉGATS DU TABLEAU DE BORD
 * ============================================================================
 *
 *  Ce fichier n'est PAS un modèle de table : il ne correspond à aucune
 *  entité. Il regroupe les requêtes de synthèse qui traversent plusieurs
 *  tables, et n'appartiennent donc naturellement à aucune d'elles.
 *
 *  Pourquoi les isoler ici plutôt que les disperser :
 *   - une requête de tableau de bord mélange transactions, budgets,
 *     versements et clubs ; la loger dans Transaction.php serait arbitraire ;
 *   - ce sont des LECTURES pures, sans écriture ni règle métier ;
 *   - le jour où l'on voudra les optimiser, tout est au même endroit.
 *
 *  ⚠ TOUT EST FILTRÉ PAR EXERCICE, et par club lorsqu'un responsable
 *  consulte. Le paramètre $clubId vaut 0 pour « tous les clubs » — c'est le
 *  contrôleur qui décide, jamais l'utilisateur.
 * ============================================================================
 */
class Statistiques
{
    /**
     * Chiffres clés de l'exercice.
     *
     * Une seule requête plutôt que quatre : chaque total se calcule sur les
     * mêmes lignes, autant ne les parcourir qu'une fois.
     *
     * @return array{recettes:string,depenses:string,en_attente:string,nb:int}
     */
    public static function kpis(int $fiscalYearId, int $clubId = 0): array
    {
        [$filtreClub, $params] = self::filtreClub($clubId);
        $params[':exercice']   = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN Type = 'recette' AND Status = 'valide' THEN Amount END), 0) AS recettes,
                COALESCE(SUM(CASE WHEN Type = 'depense' AND Status = 'valide' THEN Amount END), 0) AS depenses,
                -- Les écritures engagées mais pas encore débitées : elles ne
                -- comptent pas dans le solde, mais le trésorier doit les voir
                -- venir.
                COALESCE(SUM(CASE WHEN Status = 'en_attente' THEN Amount END), 0) AS en_attente,
                COUNT(*) AS nb
            FROM transactions
            WHERE FiscalYearID = :exercice {$filtreClub}
        ");

        $stmt->execute($params);

        return $stmt->fetch();
    }

    /**
     * Total des versements réellement reçus sur l'exercice.
     */
    public static function versementsRecus(int $fiscalYearId, int $clubId = 0): string
    {
        $sql = "SELECT COALESCE(SUM(d.Actual_Amount), 0)
                FROM disbursements d
                INNER JOIN budgets b ON b.BudgetID = d.BudgetID
                WHERE b.FiscalYearID = :exercice AND d.Status = 'recu'";

        $params = [':exercice' => $fiscalYearId];

        if ($clubId > 0) {
            $sql            .= ' AND b.ClubID = :club';
            $params[':club'] = $clubId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Recettes et dépenses mois par mois.
     *
     * ⚠ On ne se contente pas des mois PRÉSENTS dans les données : un mois
     * sans transaction doit apparaître vide, sinon le graphique saute des
     * colonnes et devient trompeur. Les douze mois de l'exercice sont donc
     * générés en PHP, puis garnis avec ce que renvoie la base.
     *
     * @return array<int,array{mois:string,libelle:string,recettes:float,depenses:float}>
     */
    public static function parMois(int $fiscalYearId, string $debut, string $fin, int $clubId = 0): array
    {
        [$filtreClub, $params] = self::filtreClub($clubId);
        $params[':exercice']   = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT DATE_FORMAT(Date, '%Y-%m') AS mois,
                   COALESCE(SUM(CASE WHEN Type = 'recette' THEN Amount END), 0) AS recettes,
                   COALESCE(SUM(CASE WHEN Type = 'depense' THEN Amount END), 0) AS depenses
            FROM transactions
            WHERE FiscalYearID = :exercice AND Status = 'valide' {$filtreClub}
            GROUP BY mois
        ");

        $stmt->execute($params);

        // Résultats indexés par mois, pour un accès direct ensuite.
        $donnees = [];

        foreach ($stmt->fetchAll() as $ligne) {
            $donnees[$ligne['mois']] = $ligne;
        }

        // Squelette : tous les mois de l'exercice, même vides.
        $resultat = [];
        $curseur  = new DateTimeImmutable(substr($debut, 0, 7) . '-01');
        $dernier  = new DateTimeImmutable(substr($fin, 0, 7) . '-01');

        while ($curseur <= $dernier) {
            $cle = $curseur->format('Y-m');

            $resultat[] = [
                'mois'     => $cle,
                'libelle'  => self::moisCourt((int) $curseur->format('n')) . ' ' . $curseur->format('y'),
                'recettes' => (float) ($donnees[$cle]['recettes'] ?? 0),
                'depenses' => (float) ($donnees[$cle]['depenses'] ?? 0),
            ];

            $curseur = $curseur->modify('+1 month');
        }

        return $resultat;
    }

    /**
     * Dépenses par catégorie, de la plus élevée à la plus faible.
     *
     * @return array<int,array{libelle:string,montant:float}>
     */
    public static function depensesParCategorie(int $fiscalYearId, int $clubId = 0): array
    {
        [$filtreClub, $params] = self::filtreClub($clubId, 't.');
        $params[':exercice']   = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT COALESCE(c.Name, 'Sans catégorie') AS libelle,
                   SUM(t.Amount) AS montant
            FROM transactions t
            LEFT JOIN categories c ON c.CategoryID = t.CategoryID
            WHERE t.FiscalYearID = :exercice
              AND t.Type = 'depense' AND t.Status = 'valide' {$filtreClub}
            GROUP BY libelle
            ORDER BY montant DESC
        ");

        $stmt->execute($params);

        return array_map(
            static fn (array $l): array => [
                'libelle' => (string) $l['libelle'],
                'montant' => (float) $l['montant'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Dépenses par pôle — quelle ÉQUIPE a dépensé.
     *
     * À ne pas confondre avec depensesParCategorie(), qui dit QUELLE NATURE
     * d'achat. Le pôle Event apparaît ici avec le total de ses dépenses,
     * matériel et nourriture confondus ; la répartition par nature se lit
     * sur l'autre graphique.
     *
     * @return array<int,array{libelle:string,montant:float}>
     */
    public static function depensesParPole(int $fiscalYearId, int $clubId = 0): array
    {
        [$filtreClub, $params] = self::filtreClub($clubId, 't.');
        $params[':exercice']   = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT p.Name AS libelle, SUM(t.Amount) AS montant
            FROM transactions t
            INNER JOIN poles p ON p.PoleID = t.PoleID
            WHERE t.FiscalYearID = :exercice
              AND t.Type = 'depense' AND t.Status = 'valide' {$filtreClub}
            GROUP BY p.PoleID, p.Name
            ORDER BY montant DESC
        ");

        $stmt->execute($params);

        return array_map(
            static fn (array $l): array => [
                'libelle' => (string) $l['libelle'],
                'montant' => (float) $l['montant'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Dépenses par club — n'a de sens que pour le bureau.
     *
     * @return array<int,array{libelle:string,montant:float}>
     */
    public static function depensesParClub(int $fiscalYearId): array
    {
        $stmt = db()->prepare("
            SELECT cl.Name AS libelle, SUM(t.Amount) AS montant
            FROM transactions t
            INNER JOIN clubs cl ON cl.ClubID = t.ClubID
            WHERE t.FiscalYearID = :exercice
              AND t.Type = 'depense' AND t.Status = 'valide'
            GROUP BY cl.ClubID, cl.Name
            ORDER BY montant DESC
        ");

        $stmt->execute([':exercice' => $fiscalYearId]);

        return array_map(
            static fn (array $l): array => [
                'libelle' => (string) $l['libelle'],
                'montant' => (float) $l['montant'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Dernières écritures saisies.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function dernieresTransactions(int $fiscalYearId, int $clubId = 0, int $limite = 8): array
    {
        [$filtreClub, $params] = self::filtreClub($clubId, 't.');
        $params[':exercice']   = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT t.TransactionID, t.Date, t.Type, t.Amount, t.Description,
                   t.Status, cl.Name AS ClubName
            FROM transactions t
            INNER JOIN clubs cl ON cl.ClubID = t.ClubID
            WHERE t.FiscalYearID = :exercice {$filtreClub}
            ORDER BY t.Date DESC, t.TransactionID DESC
            LIMIT :limite
        ");

        foreach ($params as $cle => $valeur) {
            $stmt->bindValue($cle, $valeur);
        }

        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Construit le fragment de filtre sur le club.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtreClub(int $clubId, string $prefixe = ''): array
    {
        if ($clubId <= 0) {
            return ['', []];
        }

        return [" AND {$prefixe}ClubID = :club", [':club' => $clubId]];
    }

    /**
     * Abréviation française d'un mois — sans dépendre de la locale du
     * serveur, qui varie d'une machine à l'autre (et vaut souvent l'anglais
     * sous Windows).
     */
    private static function moisCourt(int $mois): string
    {
        return [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.',
            5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août',
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ][$mois] ?? (string) $mois;
    }
}
