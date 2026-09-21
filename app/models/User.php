<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `users`
 * ============================================================================
 *
 *  Comme tout modèle : uniquement du SQL, aucun HTML, aucune décision métier.
 *  C'est le contrôleur qui décidera quoi faire des données renvoyées ici.
 * ============================================================================
 */
class User
{
    /**
     * Cherche un utilisateur par son email — l'identifiant de connexion.
     *
     * ⚠ CETTE MÉTHODE NE VÉRIFIE PAS LE MOT DE PASSE.
     * Elle renvoie la ligne complète, empreinte comprise ; c'est
     * AuthController qui appellera password_verify(). Séparer les deux garde
     * le modèle dans son rôle (parler à la base) et le contrôleur dans le
     * sien (décider si la connexion est acceptée).
     *
     * @return array<string,mixed>|null  null si aucun compte ne correspond
     */
    public static function findByEmail(string $email): ?array
    {
        // Requête préparée : $email vient d'un formulaire, donc de
        // l'extérieur. Sans cela, une saisie comme  ' OR '1'='1
        // renverrait le premier utilisateur venu.
        // IsActive est remonté SANS être filtré ici : c'est volontaire.
        // Le contrôleur vérifiera d'abord le mot de passe, et ne parlera de
        // compte désactivé qu'ensuite — voir AuthController::login().
        $stmt = db()->prepare(
            'SELECT UserID, Email, Password, Role, LastName, FirstName, IsAdmin, IsActive,
                    MustChangePassword, ClubID
             FROM users
             WHERE Email = :email'
        );

        $stmt->execute([':email' => $email]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    /**
     * Tous les comptes, actifs d'abord, avec le nom de leur club.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAll(): array
    {
        return db()->query(
            'SELECT u.UserID, u.Email, u.Role, u.LastName, u.FirstName,
                    u.IsAdmin, u.IsActive, u.MustChangePassword, u.Created_At,
                    u.ClubID, c.Name AS ClubName
             FROM users u
             LEFT JOIN clubs c ON c.ClubID = u.ClubID
             ORDER BY u.IsActive DESC, u.LastName, u.FirstName'
        )->fetchAll();
    }

    /**
     * Cet email est-il déjà utilisé par un autre compte ?
     *
     * Double la contrainte users_Email_UQ : ici pour un message clair, en
     * base pour la garantie.
     */
    public static function emailExiste(string $email, ?int $exclureId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE Email = :email';

        if ($exclureId !== null) {
            $sql .= ' AND UserID <> :id';
        }

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':email', $email);

        if ($exclureId !== null) {
            $stmt->bindValue(':id', $exclureId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Nombre d'administrateurs encore actifs.
     *
     * Sert à empêcher le dernier d'entre eux de se retirer ses propres
     * droits ou de désactiver son compte : plus personne ne pourrait alors
     * créer d'accès, et il faudrait intervenir directement en base.
     */
    public static function compterAdminsActifs(): int
    {
        return (int) db()->query(
            'SELECT COUNT(*) FROM users WHERE IsAdmin = 1 AND IsActive = 1'
        )->fetchColumn();
    }

    /**
     * Crée un compte et renvoie son identifiant.
     *
     * Reçoit le mot de passe EN CLAIR et le hache ici : c'est le seul
     * endroit où il transite, et il n'est jamais écrit tel quel en base.
     *
     * @param array<string,mixed> $d
     */
    public static function create(array $d): int
    {
        $stmt = db()->prepare(
            'INSERT INTO users
                (Email, Password, Role, LastName, FirstName,
                 IsAdmin, IsActive, MustChangePassword, ClubID)
             VALUES
                (:email, :mdp, :role, :nom, :prenom,
                 :admin, 1, 1, :club)'
        );

        $stmt->execute([
            ':email'  => $d['email'],
            ':mdp'    => password_hash($d['motdepasse'], PASSWORD_DEFAULT),
            ':role'   => $d['role'],
            ':nom'    => $d['nom'],
            ':prenom' => $d['prenom'],
            ':admin'  => $d['admin'] ? 1 : 0,
            ':club'   => $d['club'],
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Met à jour un compte (hors mot de passe).
     *
     * @param array<string,mixed> $d
     */
    public static function updateCompte(int $id, array $d): void
    {
        $stmt = db()->prepare(
            'UPDATE users SET
                Email = :email, Role = :role, LastName = :nom, FirstName = :prenom,
                IsAdmin = :admin, IsActive = :actif, ClubID = :club
             WHERE UserID = :id'
        );

        $stmt->execute([
            ':email'  => $d['email'],
            ':role'   => $d['role'],
            ':nom'    => $d['nom'],
            ':prenom' => $d['prenom'],
            ':admin'  => $d['admin'] ? 1 : 0,
            ':actif'  => $d['actif'] ? 1 : 0,
            ':club'   => $d['club'],
            ':id'     => $id,
        ]);
    }

    /**
     * Réinitialise le mot de passe et impose son changement à la connexion.
     */
    public static function reinitialiserMotDePasse(int $id, string $motDePasse): void
    {
        $stmt = db()->prepare(
            'UPDATE users SET Password = :mdp, MustChangePassword = 1 WHERE UserID = :id'
        );

        $stmt->execute([
            ':mdp' => password_hash($motDePasse, PASSWORD_DEFAULT),
            ':id'  => $id,
        ]);
    }

    /**
     * Lève l'obligation de changer de mot de passe.
     *
     * Appelée après que la personne en a choisi un elle-même.
     */
    public static function motDePasseChange(int $id): void
    {
        $stmt = db()->prepare(
            'UPDATE users SET MustChangePassword = 0 WHERE UserID = :id'
        );

        $stmt->execute([':id' => $id]);
    }

    /**
     * Enregistre une nouvelle empreinte de mot de passe.
     *
     * Reçoit l'EMPREINTE déjà calculée, jamais le mot de passe en clair :
     * password_hash() appartient au contrôleur, le modèle ne fait qu'écrire.
     */
    public static function updateMotDePasse(int $id, string $empreinte): void
    {
        $stmt = db()->prepare(
            'UPDATE users SET Password = :empreinte WHERE UserID = :id'
        );

        $stmt->execute([
            ':empreinte' => $empreinte,
            ':id'        => $id,
        ]);
    }

    /**
     * Renvoie l'empreinte du mot de passe d'un compte.
     *
     * Méthode distincte de findById() : l'empreinte ne doit circuler que
     * là où elle sert vraiment — ici, pour vérifier le mot de passe actuel
     * avant d'autoriser un changement.
     */
    public static function empreinteMotDePasse(int $id): ?string
    {
        $stmt = db()->prepare('SELECT Password FROM users WHERE UserID = :id');
        $stmt->execute([':id' => $id]);

        $empreinte = $stmt->fetchColumn();

        return $empreinte === false ? null : (string) $empreinte;
    }

    /**
     * Cherche un utilisateur par son identifiant numérique.
     *
     * Utilisé pour recharger les informations du compte connecté à partir de
     * l'UserID conservé en session.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT UserID, Email, Role, LastName, FirstName, IsAdmin, IsActive,
                    MustChangePassword, ClubID
             FROM users
             WHERE UserID = :id'
        );

        $stmt->execute([':id' => $id]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }
}
