<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `transactions`
 * ============================================================================
 *
 *  Créé ici pour la fiche d'un club (tâche 2.4), qui doit afficher ses
 *  dépenses et recettes. Il sera étoffé à la tâche 2.7 (CRUD complet,
 *  filtres, export CSV) : les transactions sont une fonctionnalité à part
 *  entière, mais leurs requêtes appartiennent dès maintenant à ce fichier
 *  plutôt qu'à Club.php — un modèle par table.
 * ============================================================================
 */
class Transaction
{
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
}
