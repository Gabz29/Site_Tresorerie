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

    public static function existe(int $id): bool
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM poles WHERE PoleID = :id AND IsActive = 1');
        $stmt->execute([':id' => $id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}
