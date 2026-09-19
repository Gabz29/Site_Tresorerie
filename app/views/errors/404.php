<?php
/**
 * Page affichée quand l'adresse demandée ne correspond à aucune page connue
 * du routeur (cf. le "default" de public/index.php).
 *
 * On n'indique volontairement PAS la liste des pages existantes : inutile de
 * renseigner un visiteur qui cherche à deviner la structure du site.
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Page introuvable — Jumão</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>
  <main class="erreur">
    <h1>404 — Page introuvable</h1>
    <p>L'adresse demandée ne correspond à aucune page du site.</p>
    <p><a href="<?= BASE_URL ?>/index.php?page=clubs">Retour à la liste des clubs</a></p>
  </main>
</body>
</html>
