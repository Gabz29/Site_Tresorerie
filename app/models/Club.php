<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `clubs`
 * ============================================================================
 *
 *  Un modèle par table. TOUTES les requêtes SQL concernant les clubs vivent
 *  ici et nulle part ailleurs : le jour où la structure de la table change,
 *  ce fichier est le seul à relire.
 *
 *  Un modèle ne fait JAMAIS d'echo et ne produit JAMAIS de HTML : il renvoie
 *  des données brutes. Il ignore totalement à quoi ressemblera la page.
 * ============================================================================
 */
class Club
{
    /**
     * Renvoie les clubs actifs, triés par nom.
     *
     * Le filtre IsActive = 1 écarte les clubs dissous : ils restent en base
     * pour l'historique comptable (leurs anciennes transactions y renvoient),
     * mais n'ont plus à apparaître dans les listes courantes.
     *
     * Ici on utilise query() et non prepare() : la requête ne contient AUCUNE
     * donnée venant de l'extérieur, il n'y a donc rien à sécuriser. Dès qu'un
     * paramètre entre en jeu, on passe à prepare() — voir findById().
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAll(): array
    {
        $sql = 'SELECT ClubID, Name, Description
                FROM clubs
                WHERE IsActive = 1
                ORDER BY Name';

        return db()->query($sql)->fetchAll();
    }

    /**
     * Renvoie un club par son identifiant, ou null s'il n'existe pas.
     *
     * ⚠ LE RÉFLEXE À PRENDRE DÈS MAINTENANT : requête PRÉPARÉE.
     *
     * La version dangereuse serait :
     *     "SELECT ... WHERE ClubID = " . $id          // JAMAIS
     * Si $id vaut  "1 OR 1=1", la requête renvoie tous les clubs ; avec une
     * valeur mieux choisie, elle peut lire ou détruire d'autres tables.
     * C'est l'injection SQL.
     *
     * Avec prepare()/execute(), la requête et ses valeurs partent séparément :
     * MySQL reçoit d'abord le squelette de la requête, puis les données. Une
     * valeur ne peut donc JAMAIS être interprétée comme du code SQL, quoi
     * qu'elle contienne.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT ClubID, Name, Description, IsActive
             FROM clubs
             WHERE ClubID = :id'
        );

        $stmt->execute([':id' => $id]);

        $club = $stmt->fetch();

        // fetch() renvoie false quand aucune ligne ne correspond. On traduit
        // ce false en null : le contrôleur teste alors simplement "=== null".
        return $club === false ? null : $club;
    }

    /**
     * Un club porte-t-il déjà ce nom ?
     *
     * @param int|null $exclureId Club à ignorer dans la recherche — sert en
     *                            modification : un club n'est pas en doublon
     *                            avec lui-même si l'on ne change pas son nom.
     *
     * ⚠ CETTE VÉRIFICATION NE REMPLACE PAS LA CONTRAINTE UNIQUE de la base
     * (clubs_Name_UQ), elle la double. Les deux ont des rôles différents :
     *   - ici, en PHP, pour afficher un message compréhensible ;
     *   - en base, pour GARANTIR qu'un doublon ne puisse jamais exister,
     *     même si un futur écran oubliait ce contrôle.
     * Le code peut se tromper ; la contrainte, non.
     */
    public static function nomExiste(string $nom, ?int $exclureId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM clubs WHERE Name = :nom';

        if ($exclureId !== null) {
            $sql .= ' AND ClubID <> :id';
        }

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':nom', $nom);

        if ($exclureId !== null) {
            $stmt->bindValue(':id', $exclureId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Crée un club et renvoie son identifiant.
     */
    public static function create(string $nom, ?string $description, bool $actif): int
    {
        $stmt = db()->prepare(
            'INSERT INTO clubs (Name, Description, IsActive)
             VALUES (:nom, :description, :actif)'
        );

        $stmt->execute([
            ':nom'         => $nom,
            // Une description vide est enregistrée comme NULL plutôt que
            // comme chaîne vide : deux façons de dire « rien » compliquent
            // inutilement tous les tests qui suivront.
            ':description' => $description,
            ':actif'       => $actif ? 1 : 0,
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Met à jour un club existant.
     */
    public static function update(int $id, string $nom, ?string $description, bool $actif): void
    {
        $stmt = db()->prepare(
            'UPDATE clubs
             SET Name = :nom, Description = :description, IsActive = :actif
             WHERE ClubID = :id'
        );

        $stmt->execute([
            ':nom'         => $nom,
            ':description' => $description,
            ':actif'       => $actif ? 1 : 0,
            ':id'          => $id,
        ]);
    }
}
