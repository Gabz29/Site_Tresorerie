<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/FiltreStats.php';

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
 *  ⚠ TOUT PASSE PAR UN FiltreStats : club, période, périmètre BDE/clubs.
 *  C'est le CONTRÔLEUR qui le construit, jamais l'utilisateur — un
 *  responsable de club reste enfermé dans le sien quoi qu'il tape dans
 *  l'URL.
 *
 *  ⚠ DEUX FAMILLES DE CHIFFRES, QUI NE RÉAGISSENT PAS PAREIL À LA PÉRIODE :
 *
 *    - les CLASSEMENTS et les TOTAUX (kpis, par pôle, par catégorie, par
 *      club) répondent au filtre de période : « combien a-t-on dépensé en
 *      mars, et en quoi » est une question qui a un sens ;
 *
 *    - les SÉRIES CHRONOLOGIQUES (mois par mois, évolution du solde,
 *      remboursements par état) l'ignorent volontairement et portent
 *      toujours sur l'exercice entier. Une courbe réduite à un seul point
 *      ne raconte plus rien — c'est justement la comparaison entre les mois
 *      qui fait tout l'intérêt de ces graphiques. Elles gardent en revanche
 *      le périmètre (club, BDE/clubs).
 * ============================================================================
 */
class Statistiques
{
    /**
     * Chiffres clés de la période consultée.
     *
     * Une seule requête plutôt que quatre : chaque total se calcule sur les
     * mêmes lignes, autant ne les parcourir qu'une fois.
     *
     * @return array{recettes:string,depenses:string,en_attente:string,nb:int}
     */
    public static function kpis(int $fiscalYearId, FiltreStats $filtre): array
    {
        [$where, $params]    = $filtre->sql();
        $params[':exercice'] = $fiscalYearId;

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
            WHERE FiscalYearID = :exercice {$where}
        ");

        $stmt->execute($params);

        return $stmt->fetch();
    }

    /**
     * Total des versements réellement reçus.
     *
     * Les versements se rattachent à un club par le BUDGET (b.ClubID) : le
     * filtre s'applique donc avec le préfixe « b. », et la date à retenir
     * est celle du versement effectif.
     */
    public static function versementsRecus(int $fiscalYearId, FiltreStats $filtre): string
    {
        // Club pris sur le budget (b.), date prise sur le versement (d.).
        [$where, $params] = $filtre->sql('b.', 'Actual_Date', 'd.');

        $sql = "SELECT COALESCE(SUM(d.Actual_Amount), 0)
                FROM disbursements d
                INNER JOIN budgets b ON b.BudgetID = d.BudgetID
                WHERE b.FiscalYearID = :exercice AND d.Status = 'recu' {$where}";

        $params[':exercice'] = $fiscalYearId;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Recettes et dépenses mois par mois — série chronologique.
     *
     * ⚠ On ne se contente pas des mois PRÉSENTS dans les données : un mois
     * sans transaction doit apparaître vide, sinon le graphique saute des
     * colonnes et devient trompeur. Les douze mois de l'exercice sont donc
     * générés en PHP, puis garnis avec ce que renvoie la base.
     *
     * @return array<int,array{mois:string,libelle:string,recettes:float,depenses:float}>
     */
    public static function parMois(int $fiscalYearId, string $debut, string $fin, FiltreStats $filtre): array
    {
        // surToutLExercice() : on garde le périmètre, on jette la période.
        [$where, $params]    = $filtre->surToutLExercice()->sql();
        $params[':exercice'] = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT DATE_FORMAT(Date, '%Y-%m') AS mois,
                   COALESCE(SUM(CASE WHEN Type = 'recette' THEN Amount END), 0) AS recettes,
                   COALESCE(SUM(CASE WHEN Type = 'depense' THEN Amount END), 0) AS depenses
            FROM transactions
            WHERE FiscalYearID = :exercice AND Status = 'valide' {$where}
            GROUP BY mois
        ");

        $stmt->execute($params);

        // Résultats indexés par mois, pour un accès direct ensuite.
        $donnees = [];

        foreach ($stmt->fetchAll() as $ligne) {
            $donnees[$ligne['mois']] = $ligne;
        }

        $resultat = [];

        foreach (self::moisDeLExercice($debut, $fin) as $cle => $libelle) {
            $resultat[] = [
                'mois'     => $cle,
                'libelle'  => $libelle,
                'recettes' => (float) ($donnees[$cle]['recettes'] ?? 0),
                'depenses' => (float) ($donnees[$cle]['depenses'] ?? 0),
            ];
        }

        return $resultat;
    }

    /**
     * Versements reçus mois par mois, pour la courbe du solde.
     *
     * @return array<string,float> mois AAAA-MM => montant
     */
    private static function versementsParMois(int $fiscalYearId, FiltreStats $filtre): array
    {
        [$where, $params]    = $filtre->surToutLExercice()->sql('b.');
        $params[':exercice'] = $fiscalYearId;

        /*
         * COALESCE(Actual_Date, Planned_Date) : un versement marqué reçu
         * devrait toujours porter sa date réelle, mais si elle manquait, la
         * ligne disparaîtrait de la courbe alors que son montant compte
         * dans le solde — la courbe ne retomberait plus sur le même total
         * que les indicateurs, et rien ne l'expliquerait.
         */
        $stmt = db()->prepare("
            SELECT DATE_FORMAT(COALESCE(d.Actual_Date, d.Planned_Date), '%Y-%m') AS mois,
                   SUM(d.Actual_Amount) AS montant
            FROM disbursements d
            INNER JOIN budgets b ON b.BudgetID = d.BudgetID
            WHERE b.FiscalYearID = :exercice AND d.Status = 'recu' {$where}
            GROUP BY mois
        ");

        $stmt->execute($params);

        $resultat = [];

        foreach ($stmt->fetchAll() as $ligne) {
            $resultat[(string) $ligne['mois']] = (float) $ligne['montant'];
        }

        return $resultat;
    }

    /**
     * Versements ENCORE ATTENDUS, mois par mois.
     *
     * Sert à prolonger la courbe du solde en pointillés : les tranches non
     * versées sont connues d'avance (montant et date prévue), c'est
     * justement ce qui rend une trésorerie d'association prévisible.
     *
     * @return array<string,float> mois AAAA-MM => montant
     */
    private static function versementsPrevusParMois(int $fiscalYearId, FiltreStats $filtre): array
    {
        [$where, $params]    = $filtre->surToutLExercice()->sql('b.');
        $params[':exercice'] = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT DATE_FORMAT(d.Planned_Date, '%Y-%m') AS mois,
                   SUM(d.Planned_Amount) AS montant
            FROM disbursements d
            INNER JOIN budgets b ON b.BudgetID = d.BudgetID
            WHERE b.FiscalYearID = :exercice AND d.Status = 'prevu' {$where}
            GROUP BY mois
        ");

        $stmt->execute($params);

        $resultat = [];

        foreach ($stmt->fetchAll() as $ligne) {
            $resultat[(string) $ligne['mois']] = (float) $ligne['montant'];
        }

        return $resultat;
    }

    /**
     * Évolution du solde, mois par mois — SÉRIE CUMULÉE.
     *
     * ⚠ CE GRAPHIQUE IGNORE LE FILTRE DE PÉRIODE, ET C'EST VOULU.
     *
     * Chaque point n'est pas « ce qui s'est passé ce mois-ci » mais « ce
     * qu'il restait sur le compte à la fin de ce mois ». Le limiter à un
     * mois donnerait un unique point, sans rien à quoi le comparer : le
     * graphique perdrait exactement ce qu'on lui demande, la tendance.
     *
     * Même formule que partout ailleurs :
     *     solde = versements reçus + recettes − dépenses validées
     * Le dernier point retombe donc sur le « Solde disponible » affiché en
     * haut de page. Deux chiffres censés dire la même chose et qui
     * diffèrent détruiraient la confiance dans tout l'écran.
     *
     * LA PROJECTION (clé 'projection', null avant le mois en cours) prolonge
     * la courbe avec les versements ENCORE ATTENDUS, dont on connaît le
     * montant et la date. Elle répond à la seule question qui compte
     * vraiment en trésorerie : « est-ce que je tiens jusqu'en juin ? »
     *
     * Elle n'anticipe AUCUNE dépense future — on ne les connaît pas. C'est
     * donc une borne HAUTE, à lire comme « au mieux, voilà ce que j'aurai »,
     * et surtout pas comme une prévision de solde.
     *
     * @return array<int,array{mois:string,libelle:string,solde:float,projection:float|null}>
     */
    public static function evolutionSolde(int $fiscalYearId, string $debut, string $fin, FiltreStats $filtre): array
    {
        $mouvements = self::parMois($fiscalYearId, $debut, $fin, $filtre);
        $versements = self::versementsParMois($fiscalYearId, $filtre);
        $attendus   = self::versementsPrevusParMois($fiscalYearId, $filtre);

        $moisCourant = (new DateTimeImmutable())->format('Y-m');

        $resultat = [];
        $cumul    = 0.0;
        $projete  = null;

        foreach ($mouvements as $m) {
            // Le cumul n'est jamais remis à zéro : c'est ce report d'un mois
            // sur l'autre qui fait la différence entre une courbe de solde
            // et un simple graphique de mouvements.
            $cumul += ($versements[$m['mois']] ?? 0.0) + $m['recettes'] - $m['depenses'];

            /*
             * La projection démarre au mois en cours, ACCROCHÉE au solde
             * réel de ce mois-là. Sans ce point d'ancrage commun, les deux
             * tracés partiraient de hauteurs différentes et on lirait un
             * décrochage qui n'existe pas.
             */
            if ($m['mois'] === $moisCourant) {
                $projete = $cumul;
            } elseif ($projete !== null) {
                $projete += $attendus[$m['mois']] ?? 0.0;
            }

            $resultat[] = [
                'mois'       => $m['mois'],
                'libelle'    => $m['libelle'],
                'solde'      => $cumul,
                'projection' => $projete,
            ];
        }

        return $resultat;
    }

    /**
     * Demandes de remboursement mois par mois, ventilées par état.
     *
     * Trois séries superposées sur le mois de l'ACHAT avancé : en attente,
     * acceptées (validées mais pas encore payées) et remboursées. On lit
     * d'un coup d'œil si le bureau suit le rythme des demandes ou s'il
     * accumule du retard.
     *
     * Les demandes REFUSÉES sont écartées : elles ne représentent aucune
     * somme due, et les mêler aux autres gonflerait les barres d'un argent
     * que personne n'attend.
     *
     * Série chronologique : période ignorée, périmètre conservé.
     *
     * @return array<int,array{mois:string,libelle:string,en_attente:float,valide:float,rembourse:float}>
     */
    public static function remboursementsParEtat(int $fiscalYearId, string $debut, string $fin, FiltreStats $filtre): array
    {
        [$where, $params]    = $filtre->surToutLExercice()->sql('', 'Purchase_Date');
        $params[':exercice'] = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT DATE_FORMAT(Purchase_Date, '%Y-%m') AS mois,
                   COALESCE(SUM(CASE WHEN Status = 'en_attente' THEN Amount END), 0) AS en_attente,
                   COALESCE(SUM(CASE WHEN Status = 'valide'     THEN Amount END), 0) AS valide,
                   COALESCE(SUM(CASE WHEN Status = 'rembourse'  THEN Amount END), 0) AS rembourse
            FROM reimbursements
            WHERE FiscalYearID = :exercice {$where}
            GROUP BY mois
        ");

        $stmt->execute($params);

        $donnees = [];

        foreach ($stmt->fetchAll() as $ligne) {
            $donnees[$ligne['mois']] = $ligne;
        }

        $resultat = [];

        foreach (self::moisDeLExercice($debut, $fin) as $cle => $libelle) {
            $resultat[] = [
                'mois'       => $cle,
                'libelle'    => $libelle,
                'en_attente' => (float) ($donnees[$cle]['en_attente'] ?? 0),
                'valide'     => (float) ($donnees[$cle]['valide'] ?? 0),
                'rembourse'  => (float) ($donnees[$cle]['rembourse'] ?? 0),
            ];
        }

        return $resultat;
    }

    /**
     * Montants par catégorie, du plus élevé au plus faible.
     *
     * ⚠ LE TYPE EST UN PARAMÈTRE, il n'est plus écrit en dur.
     *
     * Ces trois classements ne connaissaient que les dépenses : on voyait
     * parfaitement où partait l'argent, et nulle part d'où il venait. Pour
     * un BDE dont les soirées rapportent réellement, savoir si la buvette
     * a couvert ses frais est une question de trésorerie au moins aussi
     * utile que le classement des dépenses.
     *
     * Un paramètre plutôt que trois méthodes jumelles : la requête est
     * identique au type près, et deux copies finissent toujours par
     * diverger le jour où l'on corrige l'une en oubliant l'autre.
     *
     * @param string $type 'depense' ou 'recette'
     * @return array<int,array{libelle:string,montant:float}>
     */
    public static function montantsParCategorie(
        int $fiscalYearId,
        FiltreStats $filtre,
        string $type = 'depense'
    ): array {
        [$where, $params]    = $filtre->sql('t.');
        $params[':exercice'] = $fiscalYearId;
        $params[':type']     = self::typeValide($type);

        $stmt = db()->prepare("
            SELECT COALESCE(c.Name, 'Sans catégorie') AS libelle,
                   SUM(t.Amount) AS montant
            FROM transactions t
            LEFT JOIN categories c ON c.CategoryID = t.CategoryID
            WHERE t.FiscalYearID = :exercice
              AND t.Type = :type AND t.Status = 'valide' {$where}
            GROUP BY libelle
            ORDER BY montant DESC
        ");

        $stmt->execute($params);

        return self::enSerie($stmt->fetchAll());
    }

    /**
     * Dépenses par pôle — quelle ÉQUIPE a dépensé.
     *
     * À ne pas confondre avec depensesParCategorie(), qui dit QUELLE NATURE
     * d'achat. Le pôle Event apparaît ici avec le total de ses dépenses,
     * matériel et nourriture confondus ; la répartition par nature se lit
     * sur l'autre graphique.
     *
     * @param string $type 'depense' ou 'recette'
     * @return array<int,array{libelle:string,montant:float}>
     */
    public static function montantsParPole(
        int $fiscalYearId,
        FiltreStats $filtre,
        string $type = 'depense'
    ): array {
        [$where, $params]    = $filtre->sql('t.');
        $params[':exercice'] = $fiscalYearId;
        $params[':type']     = self::typeValide($type);

        $stmt = db()->prepare("
            SELECT p.Name AS libelle, SUM(t.Amount) AS montant
            FROM transactions t
            INNER JOIN poles p ON p.PoleID = t.PoleID
            WHERE t.FiscalYearID = :exercice
              AND t.Type = :type AND t.Status = 'valide' {$where}
            GROUP BY p.PoleID, p.Name
            ORDER BY montant DESC
        ");

        $stmt->execute($params);

        return self::enSerie($stmt->fetchAll());
    }

    /**
     * Dernières écritures saisies.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function dernieresTransactions(int $fiscalYearId, FiltreStats $filtre, int $limite = 8): array
    {
        [$where, $params]    = $filtre->sql('t.');
        $params[':exercice'] = $fiscalYearId;

        $stmt = db()->prepare("
            SELECT t.TransactionID, t.Date, t.Type, t.Amount, t.Description,
                   t.Status, cl.Name AS ClubName
            FROM transactions t
            INNER JOIN clubs cl ON cl.ClubID = t.ClubID
            WHERE t.FiscalYearID = :exercice {$where}
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
     * Les mois de l'exercice, du premier au dernier, même sans données.
     *
     * Extrait de parMois() pour servir aussi aux remboursements : les deux
     * graphiques doivent présenter EXACTEMENT les mêmes colonnes, sans quoi
     * on les comparerait de travers.
     *
     * @return array<string,string> AAAA-MM => libellé court
     */
    private static function moisDeLExercice(string $debut, string $fin): array
    {
        $mois    = [];
        $curseur = new DateTimeImmutable(substr($debut, 0, 7) . '-01');
        $dernier = new DateTimeImmutable(substr($fin, 0, 7) . '-01');

        while ($curseur <= $dernier) {
            $mois[$curseur->format('Y-m')] =
                self::moisCourt((int) $curseur->format('n')) . ' ' . $curseur->format('y');

            $curseur = $curseur->modify('+1 month');
        }

        return $mois;
    }

    /**
     * Ramène un type d'écriture à l'une des deux valeurs connues.
     *
     * Le type part en paramètre lié, il n'y a donc pas d'injection
     * possible. Mais une faute de frappe dans un appel ('depenses' au
     * pluriel) ne provoquerait aucune erreur : la requête renverrait
     * simplement zéro ligne, et le graphique afficherait « aucune
     * dépense ». Une panne silencieuse est plus coûteuse qu'un garde-fou
     * de trois lignes.
     */
    private static function typeValide(string $type): string
    {
        return $type === 'recette' ? 'recette' : 'depense';
    }

    /**
     * Normalise un résultat « libellé + montant » pour les graphiques.
     *
     * @param array<int,array<string,mixed>> $lignes
     * @return array<int,array{libelle:string,montant:float}>
     */
    private static function enSerie(array $lignes): array
    {
        return array_map(
            static fn (array $l): array => [
                'libelle' => (string) $l['libelle'],
                'montant' => (float) $l['montant'],
            ],
            $lignes
        );
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
