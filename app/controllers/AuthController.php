<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';

/**
 * ============================================================================
 *  CONTRÔLEUR D'AUTHENTIFICATION
 * ============================================================================
 *
 *  Gère la connexion, la déconnexion et le compte de l'utilisateur courant.
 *
 *  Rappel du mécanisme (cf. notes, tâche 2.3) : le mot de passe n'est jamais
 *  stocké, seule son EMPREINTE l'est. Pour vérifier une connexion, on ne
 *  déchiffre rien : on recalcule l'empreinte de ce qui vient d'être saisi et
 *  on la compare — c'est le travail de password_verify().
 * ============================================================================
 */
class AuthController
{
    /**
     * Affiche le formulaire de connexion (requête GET).
     *
     * @param string $erreur Message à afficher après une tentative refusée
     */
    public function showLogin(string $erreur = ''): void
    {
        // Déjà connecté ? Inutile de redemander : on renvoie à l'accueil.
        if (est_connecte()) {
            rediriger('?page=clubs');
        }

        $titre = 'Connexion';
        require __DIR__ . '/../views/auth/login.php';
    }

    /**
     * Traite l'envoi du formulaire (requête POST).
     */
    public function login(): void
    {
        // trim() sur l'email : une adresse copiée-collée traîne souvent un
        // espace. On ne fait PAS trim() sur le mot de passe — un espace en
        // début ou en fin fait légitimement partie du mot de passe choisi.
        $email      = trim((string) ($_POST['email'] ?? ''));
        $motDePasse = (string) ($_POST['password'] ?? '');

        if ($email === '' || $motDePasse === '') {
            $this->showLogin('Veuillez remplir les deux champs.');
            return;
        }

        $user = User::findByEmail($email);

        /*
         * UN SEUL message d'erreur pour les deux cas possibles — compte
         * inexistant OU mot de passe faux.
         *
         * Distinguer les deux ("cet identifiant n'existe pas") permettrait à
         * un inconnu de découvrir quels comptes existent, en testant des noms
         * un par un. Il n'aurait alors plus qu'à s'attaquer au mot de passe
         * d'un compte dont il est sûr. Le message reste donc volontairement
         * vague.
         */
        if ($user === null || !password_verify($motDePasse, $user['Password'])) {
            $this->showLogin('Adresse email ou mot de passe incorrect.');
            return;
        }

        /*
         * COMPTE DÉSACTIVÉ — vérifié APRÈS le mot de passe, et c'est l'ordre
         * qui compte.
         *
         * Le bureau change chaque année. Un ancien membre ne doit plus
         * pouvoir se connecter, mais son compte reste en base : les
         * transactions qu'il a saisies pointent vers lui.
         *
         * Pourquoi un message explicite ici, alors qu'il est volontairement
         * vague au-dessus ? Parce qu'à ce stade la personne a DÉJÀ prouvé
         * qu'elle connaît le mot de passe : lui dire que son compte est clos
         * ne renseigne personne d'autre. Et pour elle, c'est la différence
         * entre comprendre et croire à une panne.
         */
        if ((int) $user['IsActive'] !== 1) {
            $this->showLogin('Ce compte a été désactivé. Contactez l\'administrateur du BDE.');
            return;
        }

        /*
         * ⚠ RÉGÉNÉRATION DE L'IDENTIFIANT DE SESSION — à ne pas oublier.
         *
         * Sans cela, l'application est vulnérable à la "fixation de session" :
         * un attaquant fait ouvrir au visiteur un lien contenant un numéro de
         * session qu'il connaît déjà ; si ce numéro reste le même après la
         * connexion, il se retrouve connecté sous l'identité de sa victime.
         * En changeant de numéro au moment précis où les droits changent,
         * l'ancien devient inutilisable.
         */
        session_regenerate_id(true);

        // Ce qu'on garde en session : le strict nécessaire, et JAMAIS le mot
        // de passe ni son empreinte. Le rôle y est stocké parce qu'il est
        // consulté à chaque page ; il est rechargé depuis la base au besoin.
        $_SESSION['user_id'] = (int) $user['UserID'];
        $_SESSION['email']   = $user['Email'];
        $_SESSION['role']    = $user['Role'];
        $_SESSION['prenom']  = $user['FirstName'];
        $_SESSION['nom']     = $user['LastName'];
        $_SESSION['club_id'] = $user['ClubID'] !== null ? (int) $user['ClubID'] : null;

        /*
         * Redirection après un POST réussi ("POST puis redirection vers GET").
         * Sans elle, la page de résultat resterait associée à l'envoi du
         * formulaire : un simple rafraîchissement proposerait de renvoyer les
         * données, et la touche "précédent" produirait des avertissements.
         */
        rediriger('?page=clubs');
    }

    /**
     * Déconnecte l'utilisateur courant.
     */
    public function logout(): void
    {
        // 1. Vider les données de la session en mémoire.
        $_SESSION = [];

        // 2. Demander au navigateur de supprimer le cookie de session.
        //    Sans cette étape, le cookie survit et désigne une session vide —
        //    sans danger, mais autant ne rien laisser traîner.
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        // 3. Supprimer le fichier de session sur le serveur.
        session_destroy();

        rediriger('?page=login');
    }
}
