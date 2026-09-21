<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `reimbursements` (demandes de remboursement)
 * ============================================================================
 *
 *  Quelqu'un avance de l'argent pour son club ; le BDE le lui rembourse.
 *
 *  ⚠ LE BÉNÉFICIAIRE N'EST PAS FORCÉMENT UN UTILISATEUR. Ses nom, prénom et
 *  email sont du texte libre : un adhérent qui a payé des cordes de guitare
 *  n'a aucune raison d'avoir un compte dans l'application. UserID désigne
 *  qui a SAISI la demande, pas qui sera remboursé — deux personnes souvent
 *  différentes.
 *
 *  QUATRE ÉTATS :
 *    'en_attente' — déposée, pas encore examinée
 *    'valide'     — acceptée, mais pas encore payée
 *    'refuse'     — rejetée (motif dans Treasurer_Notes)
 *    'rembourse'  — payée. TransactionID pointe alors vers la dépense
 *                   générée automatiquement.
 * ============================================================================
 */
class Reimbursement
{
    public const STATUTS = ['en_attente', 'valide', 'refuse', 'rembourse'];

    /**
     * Demandes d'un exercice, éventuellement restreintes à un club.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rechercher(int $fiscalYearId, int $clubId = 0, string $statut = ''): array
    {
        $conditions = ['r.FiscalYearID = :exercice'];
        $params     = [':exercice' => $fiscalYearId];

        if ($clubId > 0) {
            $conditions[]    = 'r.ClubID = :club';
            $params[':club'] = $clubId;
        }

        if ($statut !== '') {
            $conditions[]      = 'r.Status = :statut';
            $params[':statut'] = $statut;
        }

        $sql = 'SELECT r.*, cl.Name AS ClubName, ca.Name AS CategoryName,
                       u.FirstName AS SaisiPrenom, u.LastName AS SaisiNom
                FROM reimbursements r
                INNER JOIN clubs cl ON cl.ClubID = r.ClubID
                LEFT  JOIN categories ca ON ca.CategoryID = r.CategoryID
                LEFT  JOIN users u ON u.UserID = r.UserID
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY
                    -- Les demandes en attente d\'abord : ce sont les seules
                    -- qui réclament une action du bureau.
                    CASE r.Status WHEN \'en_attente\' THEN 0 WHEN \'valide\' THEN 1 ELSE 2 END,
                    r.Purchase_Date DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT r.*, cl.Name AS ClubName, ca.Name AS CategoryName
             FROM reimbursements r
             INNER JOIN clubs cl ON cl.ClubID = r.ClubID
             LEFT  JOIN categories ca ON ca.CategoryID = r.CategoryID
             WHERE r.ReimbursementID = :id'
        );

        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Nombre de demandes en attente — pour la pastille du menu.
     */
    public static function compterEnAttente(int $fiscalYearId, int $clubId = 0): int
    {
        $sql    = "SELECT COUNT(*) FROM reimbursements WHERE FiscalYearID = :exercice AND Status = 'en_attente'";
        $params = [':exercice' => $fiscalYearId];

        if ($clubId > 0) {
            $sql            .= ' AND ClubID = :club';
            $params[':club'] = $clubId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Crée une demande, toujours à l'état 'en_attente'.
     *
     * @param array<string,mixed> $d
     */
    public static function create(array $d): int
    {
        $stmt = db()->prepare(
            "INSERT INTO reimbursements
                (Amount, Purchase_Date, Description, Status, Receipt,
                 Beneficiary_FirstName, Beneficiary_LastName, Beneficiary_Email,
                 UserID, ClubID, FiscalYearID, CategoryID)
             VALUES
                (:montant, :achat, :description, 'en_attente', :recu,
                 :prenom, :nom, :email,
                 :user, :club, :exercice, :categorie)"
        );

        $stmt->execute([
            ':montant'     => $d['montant'],
            ':achat'       => $d['achat'],
            ':description' => $d['description'],
            ':recu'        => $d['recu'] ?? null,
            ':prenom'      => $d['prenom'],
            ':nom'         => $d['nom'],
            ':email'       => $d['email'] === '' ? null : $d['email'],
            ':user'        => $d['user'],
            ':club'        => $d['club'],
            ':exercice'    => $d['exercice'],
            ':categorie'   => $d['categorie'],
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Modifie une demande encore en attente.
     *
     * @param array<string,mixed> $d
     */
    public static function update(int $id, array $d): void
    {
        $stmt = db()->prepare(
            'UPDATE reimbursements SET
                Amount = :montant, Purchase_Date = :achat, Description = :description,
                Receipt = :recu,
                Beneficiary_FirstName = :prenom, Beneficiary_LastName = :nom,
                Beneficiary_Email = :email, CategoryID = :categorie,
                ClubID = :club, FiscalYearID = :exercice
             WHERE ReimbursementID = :id'
        );

        $stmt->execute([
            ':montant'     => $d['montant'],
            ':achat'       => $d['achat'],
            ':description' => $d['description'],
            ':recu'        => $d['recu'] ?? null,
            ':prenom'      => $d['prenom'],
            ':nom'         => $d['nom'],
            ':email'       => $d['email'] === '' ? null : $d['email'],
            ':categorie'   => $d['categorie'],
            ':club'        => $d['club'],
            ':exercice'    => $d['exercice'],
            ':id'          => $id,
        ]);
    }

    /**
     * Accepte ou refuse une demande, avec l'observation du trésorier.
     */
    public static function decider(int $id, string $statut, ?string $notes): void
    {
        $stmt = db()->prepare(
            'UPDATE reimbursements
             SET Status = :statut, Treasurer_Notes = :notes, Validation_Date = NOW()
             WHERE ReimbursementID = :id'
        );

        $stmt->execute([':statut' => $statut, ':notes' => $notes, ':id' => $id]);
    }

    /**
     * Marque une demande comme payée et la relie à sa transaction.
     *
     * Appelée à l'intérieur d'une transaction SQL par le contrôleur : le
     * remboursement et la dépense doivent exister ensemble, ou pas du tout.
     */
    public static function marquerRembourse(int $id, int $transactionId): void
    {
        $stmt = db()->prepare(
            "UPDATE reimbursements
             SET Status = 'rembourse', TransactionID = :transaction, Validation_Date = NOW()
             WHERE ReimbursementID = :id"
        );

        $stmt->execute([':transaction' => $transactionId, ':id' => $id]);
    }

    /**
     * Supprime une demande.
     *
     * ⚠ Uniquement possible tant qu'aucune transaction n'a été générée :
     * sinon la dépense resterait en comptabilité sans sa pièce
     * justificative. Le contrôleur le vérifie avant d'appeler.
     */
    public static function delete(int $id): void
    {
        $stmt = db()->prepare('DELETE FROM reimbursements WHERE ReimbursementID = :id');
        $stmt->execute([':id' => $id]);
    }
}
