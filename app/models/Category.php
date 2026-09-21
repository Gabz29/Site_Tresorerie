<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `categories`
 * ============================================================================
 *
 *  Type d'une catégorie : 'depense', 'recette' ou 'both'. Il sert à ne
 *  proposer, à la saisie, que les catégories cohérentes avec le type de la
 *  transaction — on ne classe pas une subvention reçue dans « Transport ».
 * ============================================================================
 */
class Category
{
    /**
     * Toutes les catégories, par ordre alphabétique.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAll(): array
    {
        return db()->query(
            'SELECT CategoryID, Name, Type, IsActive FROM categories ORDER BY Name'
        )->fetchAll();
    }

    /**
     * Catégories encore proposées à la saisie.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getActives(): array
    {
        return db()->query(
            'SELECT CategoryID, Name, Type FROM categories WHERE IsActive = 1 ORDER BY Type, Name'
        )->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT CategoryID, Name, Type, IsActive FROM categories WHERE CategoryID = :id');
        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    public static function nomExiste(string $nom, ?int $exclureId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM categories WHERE Name = :nom';

        if ($exclureId !== null) {
            $sql .= ' AND CategoryID <> :id';
        }

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':nom', $nom);

        if ($exclureId !== null) {
            $stmt->bindValue(':id', $exclureId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function create(string $nom, string $type): int
    {
        $stmt = db()->prepare(
            'INSERT INTO categories (Name, Type, IsActive) VALUES (:nom, :type, 1)'
        );

        $stmt->execute([':nom' => $nom, ':type' => $type]);

        return (int) db()->lastInsertId();
    }

    public static function update(int $id, string $nom, string $type, bool $actif): void
    {
        $stmt = db()->prepare(
            'UPDATE categories SET Name = :nom, Type = :type, IsActive = :actif WHERE CategoryID = :id'
        );

        $stmt->execute([
            ':nom'   => $nom,
            ':type'  => $type,
            ':actif' => $actif ? 1 : 0,
            ':id'    => $id,
        ]);
    }

    /**
     * Combien d'écritures utilisent cette catégorie ?
     *
     * Affiché dans l'écran de gestion : renommer une catégorie employée
     * 200 fois n'est pas anodin, et l'archiver l'est encore moins.
     */
    public static function compterUtilisations(int $id): int
    {
        $stmt = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM transactions WHERE CategoryID = :id1)
                  + (SELECT COUNT(*) FROM reimbursements WHERE CategoryID = :id2)'
        );

        // Deux paramètres distincts pour la même valeur : en requête préparée
        // native, MySQL refuse qu'un nom apparaisse deux fois.
        $stmt->execute([':id1' => $id, ':id2' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Catégories utilisables pour un type de transaction donné.
     *
     * Inclut les catégories 'both' (ex. « Divers »), valables des deux côtés.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pourType(string $type): array
    {
        $stmt = db()->prepare(
            "SELECT CategoryID, Name, Type
             FROM categories
             WHERE Type = :type OR Type = 'both'
             ORDER BY Name"
        );

        $stmt->execute([':type' => $type]);

        return $stmt->fetchAll();
    }

    public static function existe(int $id): bool
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM categories WHERE CategoryID = :id AND IsActive = 1');
        $stmt->execute([':id' => $id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}
