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
            'SELECT CategoryID, Name, Type FROM categories ORDER BY Name'
        )->fetchAll();
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
        $stmt = db()->prepare('SELECT COUNT(*) FROM categories WHERE CategoryID = :id');
        $stmt->execute([':id' => $id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}
