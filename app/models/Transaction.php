<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `transactions`
 * ============================================================================
 *
 *  Table centrale de l'application : toutes les dépenses et recettes.
 *
 *  TROIS STATUTS :
 *    'valide'     — l'opération a eu lieu. SEULE À COMPTER dans les soldes.
 *    'en_attente' — engagée mais pas encore débitée (chèque remis, virement
 *                   lancé). Visible, mais hors des totaux : l'écart entre
 *                   l'engagement et le débit peut durer des semaines.
 *    'annule'     — n'a finalement pas eu lieu. Conservée et barrée à
 *                   l'écran, hors des totaux.
 *
 *  ⚠ Amount est TOUJOURS POSITIF : c'est la colonne Type ('depense' |
 *  'recette') qui donne le sens, jamais le signe du montant. Un montant
 *  négatif sur une dépense la transformerait silencieusement en recette
 *  dans les calculs de solde.
 * ============================================================================
 */
class Transaction
{
    /** Statuts admis. Le code compare ces valeurs, jamais leur libellé. */
    public const STATUTS = ['valide', 'en_attente', 'annule'];

    /** Moyens de paiement proposés à la saisie. */
    public const MOYENS_PAIEMENT = ['virement', 'carte', 'especes', 'cheque'];

    /**
     * Transactions d'un club, de la plus récente à la plus ancienne.
     *
     * La jointure sur `categories` évite de récupérer un CategoryID que la
     * vue devrait ensuite retraduire en nom : on demande à MySQL le libellé
     * directement. LEFT JOIN et non JOIN, car CategoryID est nullable —
     * avec un JOIN simple, les transactions sans catégorie disparaîtraient
     * silencieusement de la liste.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function findByClub(int $clubId, ?int $fiscalYearId, int $limite = 50): array
    {
        $stmt = db()->prepare(
            'SELECT t.TransactionID, t.Type, t.Amount, t.Date, t.Description,
                    t.Payment_Method, t.Status, c.Name AS CategoryName
             FROM transactions t
             LEFT JOIN categories c ON c.CategoryID = t.CategoryID
             WHERE t.ClubID = :club AND t.FiscalYearID = :exercice
             ORDER BY t.Date DESC, t.TransactionID DESC
             LIMIT :limite'
        );

        // bindValue avec PARAM_INT est nécessaire pour LIMIT : en requête
        // préparée non émulée, MySQL refuse une chaîne à cet endroit, et
        // execute([...]) transmet tout sous forme de chaînes.
        $stmt->bindValue(':club', $clubId, PDO::PARAM_INT);
        $stmt->bindValue(':exercice', (int) $fiscalYearId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Totaux des dépenses et des recettes validées d'un club.
     *
     * Le calcul est fait par MySQL plutôt qu'en parcourant les lignes en
     * PHP : c'est son métier, et cela reste juste même si l'on n'affiche
     * qu'une partie des transactions.
     *
     * @return array{depenses:string,recettes:string}
     */
    public static function totauxParClub(int $clubId, ?int $fiscalYearId): array
    {
        $stmt = db()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN Type = 'depense' THEN Amount END), 0) AS depenses,
                COALESCE(SUM(CASE WHEN Type = 'recette' THEN Amount END), 0) AS recettes
             FROM transactions
             WHERE ClubID = :club AND FiscalYearID = :exercice AND Status = 'valide'"
        );

        $stmt->execute([
            ':club'     => $clubId,
            ':exercice' => (int) $fiscalYearId,
        ]);

        // fetch() renvoie toujours une ligne ici (SUM sur un ensemble vide
        // donne NULL, que COALESCE transforme en 0).
        return $stmt->fetch();
    }

    /**
     * Recherche filtrée — le moteur de la page « Transactions ».
     *
     * ⚠ COMMENT ON CONSTRUIT UNE REQUÊTE À FILTRES VARIABLES SANS FAILLE.
     *
     * Les filtres changent d'un appel à l'autre : on ne peut pas écrire une
     * requête figée. La tentation serait de concaténer les valeurs saisies
     * dans le SQL — c'est exactement l'injection SQL.
     *
     * La méthode employée ici : on assemble uniquement des MORCEAUX DE
     * REQUÊTE ÉCRITS DANS CE FICHIER (« AND t.Type = :type »), et les
     * valeurs voyagent séparément dans $params. Rien de ce que tape
     * l'utilisateur n'entre jamais dans la chaîne SQL.
     *
     * @param array<string,mixed> $f     critères (voir clesFiltres())
     * @return array<int,array<string,mixed>>
     */
    public static function rechercher(array $f, int $limite = 50, int $offset = 0): array
    {
        [$where, $params] = self::construireFiltres($f);

        $sql = "SELECT t.TransactionID, t.Type, t.Amount, t.Date, t.Description,
                       t.Payment_Method, t.Status, t.Receipt, t.Notes,
                       t.ClubID, t.CategoryID, t.PoleID,
                       cl.Name AS ClubName,
                       ca.Name AS CategoryName,
                       p.Name  AS PoleName,
                       u.FirstName, u.LastName
                FROM transactions t
                INNER JOIN clubs cl ON cl.ClubID = t.ClubID
                INNER JOIN poles p ON p.PoleID = t.PoleID
                LEFT  JOIN categories ca ON ca.CategoryID = t.CategoryID
                LEFT  JOIN users u ON u.UserID = t.UserID
                {$where}
                ORDER BY t.Date DESC, t.TransactionID DESC
                LIMIT :limite OFFSET :offset";

        $stmt = db()->prepare($sql);

        foreach ($params as $cle => $valeur) {
            $stmt->bindValue($cle, $valeur);
        }

        // LIMIT et OFFSET exigent PARAM_INT : en requête préparée non
        // émulée, MySQL refuse une chaîne à cet endroit.
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Nombre de transactions correspondant aux mêmes filtres.
     *
     * Requête distincte plutôt que count() sur le résultat : celui-ci est
     * limité à une page, il ne dirait donc pas combien il y en a au total.
     */
    public static function compter(array $f): int
    {
        [$where, $params] = self::construireFiltres($f);

        $sql = "SELECT COUNT(*)
                FROM transactions t
                INNER JOIN clubs cl ON cl.ClubID = t.ClubID
                {$where}";

        $stmt = db()->prepare($sql);

        foreach ($params as $cle => $valeur) {
            $stmt->bindValue($cle, $valeur);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Totaux des transactions VALIDÉES correspondant aux filtres.
     *
     * Calculés sur l'ensemble du résultat, pas seulement sur la page
     * affichée : un total qui changerait en tournant les pages n'aurait
     * aucun sens.
     *
     * @return array{depenses:string,recettes:string}
     */
    public static function totaux(array $f): array
    {
        [$where, $params] = self::construireFiltres($f);

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN t.Type = 'depense' AND t.Status = 'valide' THEN t.Amount END), 0) AS depenses,
                    COALESCE(SUM(CASE WHEN t.Type = 'recette' AND t.Status = 'valide' THEN t.Amount END), 0) AS recettes
                FROM transactions t
                INNER JOIN clubs cl ON cl.ClubID = t.ClubID
                {$where}";

        $stmt = db()->prepare($sql);

        foreach ($params as $cle => $valeur) {
            $stmt->bindValue($cle, $valeur);
        }

        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Assemble la clause WHERE et les paramètres associés.
     *
     * @param array<string,mixed> $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function construireFiltres(array $f): array
    {
        $conditions = [];
        $params     = [];

        // L'exercice est toujours imposé : une page de transactions sans
        // exercice mélangerait toutes les années.
        $conditions[]         = 't.FiscalYearID = :exercice';
        $params[':exercice']  = (int) ($f['exercice'] ?? 0);

        // Club : imposé pour un responsable (il ne voit que le sien), libre
        // pour le bureau. Le contrôleur décide ; le modèle applique.
        if (!empty($f['club'])) {
            $conditions[]     = 't.ClubID = :club';
            $params[':club']  = (int) $f['club'];
        }

        if (!empty($f['type'])) {
            $conditions[]     = 't.Type = :type';
            $params[':type']  = (string) $f['type'];
        }

        if (!empty($f['statut'])) {
            $conditions[]        = 't.Status = :statut';
            $params[':statut']   = (string) $f['statut'];
        }

        if (!empty($f['pole'])) {
            $conditions[]     = 't.PoleID = :pole';
            $params[':pole']  = (int) $f['pole'];
        }

        if (!empty($f['categorie'])) {
            $conditions[]          = 't.CategoryID = :categorie';
            $params[':categorie']  = (int) $f['categorie'];
        }

        if (!empty($f['du'])) {
            $conditions[]   = 't.Date >= :du';
            $params[':du']  = (string) $f['du'];
        }

        if (!empty($f['au'])) {
            $conditions[]   = 't.Date <= :au';
            $params[':au']  = (string) $f['au'];
        }

        if (!empty($f['q'])) {
            // Recherche texte sur le libellé et les notes.
            // Les caractères % et _ ont un sens spécial dans LIKE : sans
            // les neutraliser, une recherche sur « 100% » remonterait
            // n'importe quoi. On les échappe avant de poser les jokers.
            $terme = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['q']);

            /*
             * ⚠ DEUX PARAMÈTRES DISTINCTS POUR LA MÊME VALEUR.
             *
             * Écrire « LIKE :q OR ... LIKE :q » paraît naturel, mais MySQL
             * refuse qu'un paramètre nommé apparaisse deux fois dans une
             * requête PRÉPARÉE NATIVEMENT : « Invalid parameter number ».
             *
             * C'est une conséquence directe de ATTR_EMULATE_PREPARES=false
             * (cf. app/config/database.php) : en mode émulé, PHP assemble la
             * requête lui-même et l'accepterait — au prix d'une protection
             * plus faible contre l'injection. On garde la préparation
             * native et on nomme deux paramètres.
             */
            $conditions[]   = '(t.Description LIKE :q1 OR t.Notes LIKE :q2)';
            $params[':q1']  = '%' . $terme . '%';
            $params[':q2']  = '%' . $terme . '%';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Toutes les transactions correspondant aux filtres, sans pagination.
     *
     * Réservé à l'export : la page web, elle, pagine toujours. On ajoute le
     * moyen de paiement et l'auteur de la saisie, utiles dans un tableur
     * mais trop détaillés pour l'écran.
     *
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    public static function pourExport(array $f): array
    {
        [$where, $params] = self::construireFiltres($f);

        $sql = "SELECT t.TransactionID, t.Date, t.Type, t.Amount, t.Description,
                       t.Payment_Method, t.Status, t.Notes,
                       cl.Name AS ClubName,
                       ca.Name AS CategoryName,
                       p.Name  AS PoleName,
                       u.FirstName, u.LastName,
                       CASE WHEN t.Receipt IS NULL THEN 'non' ELSE 'oui' END AS AvecJustificatif
                FROM transactions t
                INNER JOIN clubs cl ON cl.ClubID = t.ClubID
                INNER JOIN poles p ON p.PoleID = t.PoleID
                LEFT  JOIN categories ca ON ca.CategoryID = t.CategoryID
                LEFT  JOIN users u ON u.UserID = t.UserID
                {$where}
                ORDER BY t.Date, t.TransactionID";

        $stmt = db()->prepare($sql);

        foreach ($params as $cle => $valeur) {
            $stmt->bindValue($cle, $valeur);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Une transaction par son identifiant, avec les libellés joints.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT t.*, cl.Name AS ClubName, ca.Name AS CategoryName, p.Name AS PoleName
             FROM transactions t
             INNER JOIN clubs cl ON cl.ClubID = t.ClubID
             INNER JOIN poles p ON p.PoleID = t.PoleID
             LEFT  JOIN categories ca ON ca.CategoryID = t.CategoryID
             WHERE t.TransactionID = :id'
        );

        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Enregistre une transaction et renvoie son identifiant.
     *
     * @param array<string,mixed> $d données déjà validées par le contrôleur
     */
    public static function create(array $d): int
    {
        $stmt = db()->prepare(
            'INSERT INTO transactions
                (Type, Amount, Date, Description, Payment_Method, Status,
                 Receipt, Notes, CategoryID, PoleID, FiscalYearID, UserID, ClubID)
             VALUES
                (:type, :montant, :date, :description, :moyen, :statut,
                 :recu, :notes, :categorie, :pole, :exercice, :user, :club)'
        );

        $stmt->execute([
            ':type'        => $d['type'],
            ':montant'     => $d['montant'],
            ':date'        => $d['date'],
            ':description' => $d['description'],
            ':moyen'       => $d['moyen'],
            ':statut'      => $d['statut'],
            ':recu'        => $d['recu'] ?? null,
            ':notes'       => $d['notes'],
            ':categorie'   => $d['categorie'],
            ':pole'        => $d['pole'],
            ':exercice'    => $d['exercice'],
            // Qui a SAISI la ligne. Jamais modifié ensuite : même si
            // quelqu'un d'autre corrige la transaction, l'auteur de la
            // saisie initiale reste l'information utile pour l'historique.
            ':user'        => $d['user'],
            ':club'        => $d['club'],
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Met à jour une transaction existante.
     *
     * FiscalYearID est recalculé : si la date change et bascule sur un autre
     * exercice, le rattachement doit suivre.
     *
     * @param array<string,mixed> $d
     */
    public static function update(int $id, array $d): void
    {
        $stmt = db()->prepare(
            'UPDATE transactions SET
                Type = :type, Amount = :montant, Date = :date,
                Description = :description, Payment_Method = :moyen,
                Status = :statut, Receipt = :recu, Notes = :notes,
                CategoryID = :categorie, PoleID = :pole,
                FiscalYearID = :exercice, ClubID = :club
             WHERE TransactionID = :id'
        );

        $stmt->execute([
            ':type'        => $d['type'],
            ':montant'     => $d['montant'],
            ':date'        => $d['date'],
            ':description' => $d['description'],
            ':moyen'       => $d['moyen'],
            ':statut'      => $d['statut'],
            ':recu'        => $d['recu'] ?? null,
            ':notes'       => $d['notes'],
            ':categorie'   => $d['categorie'],
            ':pole'        => $d['pole'],
            ':exercice'    => $d['exercice'],
            ':club'        => $d['club'],
            ':id'          => $id,
        ]);
    }

    /**
     * Efface la référence au justificatif, sans toucher au reste.
     */
    public static function retirerJustificatif(int $id): void
    {
        $stmt = db()->prepare(
            'UPDATE transactions SET Receipt = NULL WHERE TransactionID = :id'
        );

        $stmt->execute([':id' => $id]);
    }

    /**
     * Change uniquement le statut (valider, annuler, remettre en attente).
     */
    public static function changerStatut(int $id, string $statut): void
    {
        $stmt = db()->prepare(
            'UPDATE transactions SET Status = :statut WHERE TransactionID = :id'
        );

        $stmt->execute([':statut' => $statut, ':id' => $id]);
    }

    /**
     * Supprime définitivement une transaction.
     *
     * ⚠ Réservé au bureau (contrôlé par le contrôleur). À n'employer que
     * pour une ligne qui n'aurait jamais dû exister — doublon, saisie dans
     * le mauvais club. Pour une opération qui n'a finalement pas eu lieu,
     * le statut 'annule' est préférable : il conserve la trace.
     *
     * ⚠ Une transaction peut être référencée par un remboursement
     * (reimbursements.TransactionID). La clé étrangère empêcherait alors la
     * suppression : c'est voulu, on ne casse pas ce lien en silence.
     */
    public static function delete(int $id): void
    {
        $stmt = db()->prepare('DELETE FROM transactions WHERE TransactionID = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Cette transaction est-elle liée à une demande de remboursement ?
     *
     * Sert à prévenir avant suppression, plutôt que de laisser MySQL
     * renvoyer une erreur de contrainte incompréhensible.
     */
    public static function estLieeAUnRemboursement(int $id): bool
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM reimbursements WHERE TransactionID = :id'
        );

        $stmt->execute([':id' => $id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}
