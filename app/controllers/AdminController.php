<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Pole.php';

/**
 * ============================================================================
 *  CONTRÔLEUR D'ADMINISTRATION — comptes utilisateurs
 * ============================================================================
 *
 *  Réservé aux comptes portant la permission IsAdmin, quel que soit leur
 *  rôle : « gérer les comptes » est une permission qui s'ajoute, pas un
 *  métier à part (cf. tâche 1.1).
 *
 *  CYCLE DE VIE D'UN COMPTE (décidé à la tâche 2.10) :
 *    1. l'admin crée le compte avec un mot de passe temporaire ;
 *    2. il le communique DE VIVE VOIX — jamais par mail, où il resterait
 *       en clair pour toujours, et parce qu'un envoi depuis PHP est de
 *       toute façon peu fiable sur un serveur d'école ;
 *    3. l'application force son changement à la première connexion ;
 *    4. en fin de mandat, le compte est DÉSACTIVÉ, jamais supprimé : les
 *       transactions saisies pointent vers lui, et l'effacer détruirait
 *       l'historique comptable.
 * ============================================================================
 */
class AdminController
{
    /** Longueur du mot de passe temporaire engendré. */
    private const LONGUEUR_MDP_TEMPORAIRE = 12;

    /**
     * Liste des comptes — URL : ?page=utilisateurs
     */
    public function index(): void
    {
        exiger_admin();

        $titre        = 'Utilisateurs';
        $utilisateurs = User::getAll();
        $flash        = lire_flash();

        require __DIR__ . '/../views/admin/utilisateurs.php';
    }

    /**
     * Formulaire de création — URL : ?page=utilisateur-nouveau
     */
    public function create(): void
    {
        exiger_admin();

        $titre       = 'Nouveau compte';
        $utilisateur = null;
        $erreur      = '';
        $clubs       = Club::getAll();

        require __DIR__ . '/../views/admin/utilisateur-form.php';
    }

    /**
     * Formulaire de modification — URL : ?page=utilisateur-modifier&id=3
     */
    public function edit(): void
    {
        exiger_admin();

        $utilisateur = $this->compteDemande((int) ($_GET['id'] ?? 0));

        $titre  = 'Modifier le compte';
        $erreur = '';
        $clubs  = Club::getAll();

        require __DIR__ . '/../views/admin/utilisateur-form.php';
    }

    /**
     * Enregistre une création ou une modification (requête POST).
     */
    public function save(): void
    {
        exiger_admin();
        verifier_csrf();

        $id      = (int) ($_POST['id'] ?? 0);
        $donnees = $this->lireFormulaire();
        $erreur  = $this->valider($donnees, $id);

        if ($erreur === '') {
            $erreur = $this->verifierGardeFous($donnees, $id);
        }

        if ($erreur !== '') {
            $titre       = $id > 0 ? 'Modifier le compte' : 'Nouveau compte';
            $utilisateur = $this->saisieVersFormulaire($id, $donnees);
            $clubs       = Club::getAll();

            require __DIR__ . '/../views/admin/utilisateur-form.php';
            return;
        }

        if ($id > 0) {
            User::updateCompte($id, $donnees);

            // Si l'admin modifie SON PROPRE compte, la session doit suivre :
            // sinon la barre latérale et les droits afficheraient l'ancien
            // état jusqu'à la prochaine connexion.
            if ($id === (int) $_SESSION['user_id']) {
                $this->rafraichirSession($donnees);
            }

            message_flash('succes', 'Le compte a été modifié.');
            rediriger('?page=utilisateurs');
        }

        $motDePasse = $this->motDePasseTemporaire();
        $donnees['motdepasse'] = $motDePasse;

        User::create($donnees);

        /*
         * Le mot de passe temporaire est affiché UNE SEULE FOIS, ici. Il
         * n'est pas envoyé par mail (il y resterait en clair indéfiniment)
         * et n'est stocké nulle part en clair : passé cet affichage, plus
         * personne ne peut le retrouver — il faudrait le réinitialiser.
         */
        message_flash(
            'succes',
            'Compte créé. Mot de passe temporaire à transmettre de vive voix : '
            . $motDePasse
            . ' — il ne sera plus affiché, et devra être changé à la première connexion.'
        );

        rediriger('?page=utilisateurs');
    }

    /**
     * Réinitialise le mot de passe d'un compte (requête POST).
     */
    public function reinitialiser(): void
    {
        exiger_admin();
        verifier_csrf();

        $compte     = $this->compteDemande((int) ($_POST['id'] ?? 0));
        $motDePasse = $this->motDePasseTemporaire();

        User::reinitialiserMotDePasse((int) $compte['UserID'], $motDePasse);

        message_flash(
            'succes',
            'Nouveau mot de passe temporaire pour '
            . $compte['FirstName'] . ' ' . $compte['LastName'] . ' : '
            . $motDePasse
            . ' — à transmettre de vive voix. Il devra être changé à la connexion.'
        );

        rediriger('?page=utilisateurs');
    }

    /**
     * Listes de référence : catégories et pôles — URL : ?page=references
     *
     * Les deux tiennent sur une seule page : ce sont de courtes listes que
     * l'on consulte et modifie ensemble, en début d'exercice le plus
     * souvent. Deux écrans séparés auraient multiplié les allers-retours
     * pour rien.
     */
    public function references(): void
    {
        exiger_admin();

        $titre      = 'Catégories et pôles';
        $categories = Category::getAll();
        $poles      = Pole::getAll();
        $flash      = lire_flash();

        // Nombre d'écritures par entrée : archiver une catégorie employée
        // 200 fois n'est pas le même geste que d'en archiver une inutilisée.
        $usageCategories = [];
        foreach ($categories as $c) {
            $usageCategories[(int) $c['CategoryID']] = Category::compterUtilisations((int) $c['CategoryID']);
        }

        $usagePoles = [];
        foreach ($poles as $p) {
            $usagePoles[(int) $p['PoleID']] = Pole::compterUtilisations((int) $p['PoleID']);
        }

        require __DIR__ . '/../views/admin/references.php';
    }

    /**
     * Crée ou modifie une catégorie (requête POST).
     */
    public function enregistrerCategorie(): void
    {
        exiger_admin();
        verifier_csrf();

        $id   = (int) ($_POST['id'] ?? 0);
        $nom  = trim((string) ($_POST['nom'] ?? ''));
        $type = (string) ($_POST['type'] ?? '');
        // Une création est toujours active ; en modification, la case décide.
        $actif = $id === 0 || isset($_POST['actif']);

        $erreur = '';

        if ($nom === '') {
            $erreur = 'Le nom de la catégorie est obligatoire.';
        } elseif (mb_strlen($nom) > 50) {
            $erreur = 'Le nom est limité à 50 caractères.';
        } elseif (!in_array($type, ['depense', 'recette', 'both'], true)) {
            $erreur = 'Type de catégorie inconnu.';
        } elseif (Category::nomExiste($nom, $id > 0 ? $id : null)) {
            $erreur = 'Une catégorie porte déjà ce nom.';
        } elseif ($id > 0 && Category::findById($id) === null) {
            $erreur = 'Cette catégorie n\'existe pas.';
        }

        if ($erreur !== '') {
            message_flash('erreur', $erreur);
            rediriger('?page=references');
        }

        if ($id > 0) {
            Category::update($id, $nom, $type, $actif);
            message_flash('succes', 'Catégorie modifiée.');
        } else {
            Category::create($nom, $type);
            message_flash('succes', 'Catégorie créée.');
        }

        rediriger('?page=references');
    }

    /**
     * Crée ou modifie un pôle (requête POST).
     */
    public function enregistrerPole(): void
    {
        exiger_admin();
        verifier_csrf();

        $id    = (int) ($_POST['id'] ?? 0);
        $nom   = trim((string) ($_POST['nom'] ?? ''));
        $actif = $id === 0 || isset($_POST['actif']);

        $erreur = '';

        if ($nom === '') {
            $erreur = 'Le nom du pôle est obligatoire.';
        } elseif (mb_strlen($nom) > 50) {
            $erreur = 'Le nom est limité à 50 caractères.';
        } elseif (Pole::nomExiste($nom, $id > 0 ? $id : null)) {
            $erreur = 'Un pôle porte déjà ce nom.';
        } elseif ($id > 0 && Pole::findById($id) === null) {
            $erreur = 'Ce pôle n\'existe pas.';
        }

        /*
         * Il doit toujours rester au moins un pôle actif : le pôle est
         * obligatoire sur chaque écriture, et les archiver tous rendrait
         * toute saisie impossible.
         */
        if ($erreur === '' && $id > 0 && !$actif && count(Pole::getActifs()) <= 1) {
            $erreur = 'C\'est le dernier pôle actif : l\'archiver empêcherait toute saisie.';
        }

        if ($erreur !== '') {
            message_flash('erreur', $erreur);
            rediriger('?page=references');
        }

        if ($id > 0) {
            Pole::update($id, $nom, $actif);
            message_flash('succes', 'Pôle modifié.');
        } else {
            Pole::create($nom);
            message_flash('succes', 'Pôle créé.');
        }

        rediriger('?page=references');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function lireFormulaire(): array
    {
        $role = (string) ($_POST['role'] ?? '');

        return [
            'email'  => trim((string) ($_POST['email'] ?? '')),
            'nom'    => trim((string) ($_POST['nom'] ?? '')),
            'prenom' => trim((string) ($_POST['prenom'] ?? '')),
            'role'   => in_array($role, ['bureau', 'responsable'], true) ? $role : '',
            'admin'  => isset($_POST['admin']),
            'actif'  => isset($_POST['actif']),
            // Un membre du bureau n'est limité à aucun club : ClubID reste
            // NULL. Y inscrire son club de rattachement laisserait croire à
            // une restriction qui n'existe pas (cf. tâche 1.1).
            'club'   => $role === 'responsable' && (int) ($_POST['club'] ?? 0) > 0
                            ? (int) $_POST['club']
                            : null,
        ];
    }

    /**
     * @param array<string,mixed> $d
     */
    private function valider(array $d, int $id): string
    {
        if ($d['prenom'] === '' || $d['nom'] === '') {
            return 'Le nom et le prénom sont obligatoires.';
        }

        if (mb_strlen($d['prenom']) > 50 || mb_strlen($d['nom']) > 50) {
            return 'Le nom et le prénom sont limités à 50 caractères.';
        }

        if ($d['email'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            return 'L\'adresse email n\'est pas valide.';
        }

        if (mb_strlen($d['email']) > 250) {
            return 'L\'adresse email est trop longue.';
        }

        if (User::emailExiste($d['email'], $id > 0 ? $id : null)) {
            return 'Cette adresse email est déjà utilisée par un autre compte.';
        }

        if ($d['role'] === '') {
            return 'Choisissez un rôle.';
        }

        // Un responsable sans club ne verrait rien du tout : son accès est
        // précisément défini par le club auquel il est rattaché.
        if ($d['role'] === 'responsable' && $d['club'] === null) {
            return 'Un responsable doit être rattaché à un club.';
        }

        if ($d['club'] !== null && Club::findById($d['club']) === null) {
            return 'Ce club n\'existe pas.';
        }

        return '';
    }

    /**
     * Empêche l'administrateur de se verrouiller dehors.
     *
     * ⚠ SANS CES CONTRÔLES, un admin peut retirer ses propres droits ou
     * désactiver son compte alors qu'il est le dernier : plus personne ne
     * pourrait alors créer ni modifier de compte, et il faudrait intervenir
     * directement dans phpMyAdmin pour rouvrir l'accès.
     *
     * @param array<string,mixed> $d
     */
    private function verifierGardeFous(array $d, int $id): string
    {
        // Ne concerne que la modification de son PROPRE compte.
        if ($id !== (int) $_SESSION['user_id']) {
            return '';
        }

        if (!$d['actif']) {
            return 'Vous ne pouvez pas désactiver votre propre compte.';
        }

        if (!$d['admin'] && User::compterAdminsActifs() <= 1) {
            return 'Vous êtes le dernier administrateur actif : vous ne pouvez pas '
                 . 'retirer votre propre droit d\'administration. Donnez-le d\'abord '
                 . 'à quelqu\'un d\'autre.';
        }

        return '';
    }

    /**
     * Engendre un mot de passe temporaire lisible mais imprévisible.
     *
     * random_int() est un générateur adapté à la cryptographie, contrairement
     * à rand(). L'alphabet écarte les caractères qu'on confond à l'oral ou à
     * la lecture (O/0, l/1/I) : ce mot de passe est dicté de vive voix.
     */
    private function motDePasseTemporaire(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max      = strlen($alphabet) - 1;
        $mdp      = '';

        for ($i = 0; $i < self::LONGUEUR_MDP_TEMPORAIRE; $i++) {
            $mdp .= $alphabet[random_int(0, $max)];
        }

        return $mdp;
    }

    /**
     * Met la session à jour après modification de son propre compte.
     *
     * @param array<string,mixed> $d
     */
    private function rafraichirSession(array $d): void
    {
        $_SESSION['email']    = $d['email'];
        $_SESSION['role']     = $d['role'];
        $_SESSION['prenom']   = $d['prenom'];
        $_SESSION['nom']      = $d['nom'];
        $_SESSION['club_id']  = $d['club'];
        $_SESSION['is_admin'] = (bool) $d['admin'];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function saisieVersFormulaire(int $id, array $d): array
    {
        return [
            'UserID'    => $id,
            'Email'     => $d['email'],
            'LastName'  => $d['nom'],
            'FirstName' => $d['prenom'],
            'Role'      => $d['role'],
            'IsAdmin'   => $d['admin'] ? 1 : 0,
            'IsActive'  => $d['actif'] ? 1 : 0,
            'ClubID'    => $d['club'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function compteDemande(int $id): array
    {
        $compte = User::findById($id);

        if ($compte === null) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            exit;
        }

        return $compte;
    }
}
