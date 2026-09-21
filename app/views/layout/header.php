<?php
/**
 * ============================================================================
 *  HAUT DE PAGE COMMUN À TOUTES LES VUES
 * ============================================================================
 *  Placé ici pour ne pas recopier le même HTML dans chaque page : une
 *  modification de la navigation se fait à un seul endroit.
 *
 *  Attend (facultatif) : $titre — titre de la page.
 *
 *  ÉTAT : version minimale de la Phase 2. La reprise fidèle des maquettes
 *  (couleurs, composants, responsive) est le travail de la Phase 3 (3.1).
 *  Le bloc utilisateur de la maquette est absent : les sessions n'existent
 *  pas encore (tâche 2.3).
 * ============================================================================
 */
declare(strict_types=1);

// Le menu affiche le nombre de demandes en attente : le modèle doit donc
// être disponible ici, quelle que soit la page qui inclut ce gabarit.
require_once __DIR__ . '/../../models/Reimbursement.php';

$titre = $titre ?? 'Jumão';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($titre) ?> — Jumão BDE</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>

<aside class="sidebar">
  <h2>Jumão BDE</h2>

  <nav>
    <?php
    /*
     * Seule la page "Clubs" existe pour l'instant : c'est donc le seul
     * vrai lien. Les autres entrées sont affichées en gris, non
     * cliquables — comme dans les maquettes — pour montrer la structure
     * à venir sans produire de liens qui mèneraient à une erreur 404.
     * Chaque entrée deviendra un lien au fur et à mesure des tâches 2.x.
     */
    ?>
    <span class="inactif">Dashboard</span>
    <a class="actif" href="<?= BASE_URL ?>/index.php?page=clubs">Clubs</a>
    <a href="<?= BASE_URL ?>/index.php?page=transactions">Transactions</a>
    <?php
    /*
     * Pastille du nombre de demandes en attente : ce sont les seules qui
     * réclament une action. Un responsable ne compte que celles de son
     * club, le bureau toutes.
     */
    $layoutExerciceId = exercice_consulte_id();
    $layoutEnAttente  = $layoutExerciceId === null ? 0 : Reimbursement::compterEnAttente(
        $layoutExerciceId,
        est_bureau() ? 0 : (int) ($_SESSION['club_id'] ?? 0)
    );
    ?>
    <a href="<?= BASE_URL ?>/index.php?page=remboursements">
      Remboursements<?php if ($layoutEnAttente > 0) : ?>
        <span class="pastille"><?= $layoutEnAttente ?></span>
      <?php endif; ?>
    </a>

    <?php
    /*
     * Entrées réservées, affichées selon les droits.
     *
     * ⚠ CE MASQUAGE N'EST PAS UNE PROTECTION : il évite d'afficher des liens
     * menant à un refus, rien de plus. Quelqu'un qui tape l'adresse
     * directement contourne l'affichage sans difficulté. La vraie barrière
     * est exiger_bureau() / exiger_admin(), côté serveur, dans le contrôleur.
     */
    ?>
    <?php if (est_bureau()) : ?>
      <hr>
      <div class="section-titre">Bureau BDE</div>
      <a href="<?= BASE_URL ?>/index.php?page=budgets">Budgets</a>
      <a href="<?= BASE_URL ?>/index.php?page=exercices">Exercices</a>
    <?php endif; ?>

    <?php if (est_admin()) : ?>
      <hr>
      <div class="section-titre">Administration</div>
      <span class="inactif">Utilisateurs</span>
    <?php endif; ?>
  </nav>

  <?php
  /*
   * Bloc de l'utilisateur connecté.
   *
   * utilisateur_courant() lit la SESSION, pas la base : ces informations y
   * ont été déposées au moment de la connexion. Inutile d'interroger MySQL
   * à chaque page pour réafficher un prénom.
   *
   * htmlspecialchars() s'applique ici aussi : ces valeurs viennent de la
   * base (donc, à terme, d'un formulaire), même si elles transitent par la
   * session. La règle ne souffre pas d'exception.
   *
   * Les entrées de menu réservées à certains rôles seront masquées ici
   * quand les pages correspondantes existeront (tâches 2.6 et 2.10).
   *
   * ⚠ POURQUOI CE NOM DE VARIABLE À RALLONGE : un fichier inclus PARTAGE
   * les variables de celui qui l'inclut. C'est ce qui permet au contrôleur
   * de transmettre ses données à la vue — mais cela veut aussi dire que
   * toute variable créée ici ÉCRASE celle du même nom dans la vue.
   * Un simple $utilisateur a déjà écrasé celui de la vue "Mon compte",
   * qui contenait la ligne lue en base : les clés ne correspondaient plus
   * ($utilisateur['Email'] contre $utilisateur['email']) et la page
   * plantait. D'où ce préfixe "layout" : ces variables appartiennent au
   * gabarit et ne doivent entrer en collision avec aucune vue.
   */
  $layoutUtilisateur = utilisateur_courant();
  $layoutExercices   = FiscalYear::getAll();
  $layoutExercice    = exercice_consulte();
  ?>

  <?php
  /*
   * Sélecteur d'exercice — visible sur toutes les pages.
   *
   * Il change ce qu'on REGARDE, jamais ce sur quoi on saisit : l'exercice
   * actif est décidé par le bureau et vaut pour tout le monde, tandis que
   * ce choix-ci n'engage que la session en cours.
   *
   * Le formulaire s'envoie tout seul au changement (onchange), pour éviter
   * un bouton « valider » que personne ne penserait à cliquer. Le bouton
   * reste néanmoins présent dans <noscript> : sans JavaScript, la liste
   * serait autrement inutilisable.
   */
  ?>
  <?php if ($layoutExercices !== []) : ?>
    <div class="exercice-choix">
      <form method="post" action="<?= BASE_URL ?>/index.php?page=exercice-consulter">
        <?= champ_csrf() ?>
        <?php
        /*
         * On mémorise la page courante pour y revenir après le changement,
         * plutôt que de renvoyer l'utilisateur à l'accueil à chaque fois.
         *
         * Cette valeur vient du navigateur : le routeur vérifie donc qu'elle
         * commence bien par "?page=" avant de rediriger. Sans ce contrôle,
         * on aurait une « redirection ouverte » — un lien vers notre site
         * qui renverrait en réalité vers un site tiers, ce qui sert
         * couramment à rendre crédible une page d'hameçonnage.
         */
        ?>
        <input type="hidden" name="retour"
               value="<?= htmlspecialchars('?' . ($_SERVER['QUERY_STRING'] ?? 'page=clubs')) ?>">
        <label for="exercice-consulte">Exercice consulté</label>
        <select id="exercice-consulte" name="exercice" onchange="this.form.submit()">
          <?php foreach ($layoutExercices as $layoutEx) : ?>
            <option value="<?= (int) $layoutEx['FiscalYearID'] ?>"
              <?= $layoutExercice !== null
                  && (int) $layoutEx['FiscalYearID'] === (int) $layoutExercice['FiscalYearID']
                  ? 'selected' : '' ?>>
              <?= htmlspecialchars($layoutEx['Year']) ?><?= (int) $layoutEx['IsActive'] === 1 ? ' (en cours)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-petit">Changer</button></noscript>
      </form>

      <?php if (!exercice_consulte_est_actif()) : ?>
        <p class="note note-alerte">Exercice clos — lecture seule</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($layoutUtilisateur !== null) : ?>
    <div class="utilisateur">
      <div class="utilisateur-nom">
        <?= htmlspecialchars($layoutUtilisateur['prenom'] . ' ' . $layoutUtilisateur['nom']) ?>
      </div>
      <div class="utilisateur-role">
        <?= htmlspecialchars(libelle_role($layoutUtilisateur['role'])) ?>
      </div>
      <a href="<?= BASE_URL ?>/index.php?page=profil">Mon compte</a>
      <a class="deconnexion" href="<?= BASE_URL ?>/index.php?page=logout">Déconnexion</a>
    </div>
  <?php endif; ?>
</aside>

<main class="contenu">
  <h1><?= htmlspecialchars($titre) ?></h1>
