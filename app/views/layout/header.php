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
    <span class="inactif">Transactions</span>
    <span class="inactif">Remboursements</span>

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
      <span class="inactif">Budgets</span>
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
  ?>
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
