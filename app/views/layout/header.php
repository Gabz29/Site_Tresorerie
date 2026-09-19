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

    <hr>
    <div class="section-titre">Trésorier</div>
    <span class="inactif">Budgets</span>
    <span class="inactif">Utilisateurs</span>
  </nav>
</aside>

<main class="contenu">
  <h1><?= htmlspecialchars($titre) ?></h1>
