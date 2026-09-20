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
            'SELECT UserID, Email, Password, Role, LastName, FirstName, IsActive, ClubID
             FROM users
             WHERE Email = :email'
        );

        $stmt->execute([':email' => $email]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
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
            'SELECT UserID, Email, Role, LastName, FirstName, ClubID
             FROM users
             WHERE UserID = :id'
        );

        $stmt->execute([':id' => $id]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }
}
