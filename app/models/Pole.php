<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `poles` (équipes du bureau)
 * ============================================================================
 *
 *  Logistique, Event, Tanière, Comm… plus « Général » pour ce qui ne relève
 *  d'aucune équipe (subvention reçue, frais bancaires, assurance).
 *
 *  ⚠ TROISIÈME AXE D'ANALYSE, distinct des deux autres :
 *      club      = QUELLE ENTITÉ dépense
 *      pôle      = QUELLE ÉQUIPE dépense
 *      catégorie = QUELLE NATURE d'achat
 *
 *  Le pôle Event achète du matériel (sono) et de la nourriture (buffet) :
 *  sans cet axe séparé, l'un des deux serait perdu.
 *
 *  Les mêmes pôles valent pour tous les clubs, BDE compris.
 * ============================================================================
 */
class Pole
{
    /**
     * Pôles actifs, par ordre alphabétique.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getActifs(): array
    {
        return db()->query(
            'SELECT PoleID, Name FROM poles WHERE IsActive = 1 ORDER BY Name'
        )->fetchAll();
    }

    /**
     * Tous les pôles, y compris archivés — pour les filtres, qui doivent
     * pouvoir porter sur d'anciennes écritures.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAll(): array
    {
        return db()->query(
            'SELECT PoleID, Name, IsActive FROM poles ORDER BY Name'
        )->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT PoleID, Name, IsActive FROM poles WHERE PoleID = :id');
        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    public static function nomExiste(string $nom, ?int $exclureId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM poles WHERE Name = :nom';

        if ($exclureId !== null) {
            $sql .= ' AND PoleID <> :id';
        }

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':nom', $nom);

        if ($exclureId !== null) {
            $stmt->bindValue(':id', $exclureId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function create(string $nom): int
    {
        $stmt = db()->prepare('INSERT INTO poles (Name, IsActive) VALUES (:nom, 1)');
        $stmt->execute([':nom' => $nom]);

        return (int) db()->lastInsertId();
    }

    public static function update(int $id, string $nom, bool $actif): void
    {
        $stmt = db()->prepare(
            'UPDATE poles SET Name = :nom, IsActive = :actif WHERE PoleID = :id'
        );

        $stmt->execute([':nom' => $nom, ':actif' => $actif ? 1 : 0, ':id' => $id]);
    }

    /**
     * Combien d'écritures utilisent ce pôle ?
     */
    public static function compterUtilisations(int $id): int
    {
        $stmt = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM transactions WHERE PoleID = :id1)
                  + (SELECT COUNT(*) FROM reimbursements WHERE PoleID = :id2)'
        );

        $stmt->execute([':id1' => $id, ':id2' => $id]);

        return (int) $stmt->fetchColumn();
    }

    public static function existe(int $id): bool
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM poles WHERE PoleID = :id AND IsActive = 1');
        $stmt->execute([':id' => $id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}
