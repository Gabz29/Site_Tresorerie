<?php
/**
 * ============================================================================
 *  VUE — formulaire de connexion
 * ============================================================================
 *  Reçoit d'AuthController :
 *    $erreur — message à afficher après une tentative refusée (peut être vide)
 *    $titre  — titre de la page
 *
 *  Cette page n'utilise pas layout/header.php : elle n'a ni barre latérale ni
 *  navigation, puisque le visiteur n'a encore accès à rien.
 * ============================================================================
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — Jumão BDE</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="page-login">

  <?php
  /*
   * method="post" et non "get" : en GET, les champs du formulaire partent
   * dans l'URL. Le mot de passe apparaîtrait dans la barre d'adresse, dans
   * l'historique du navigateur et dans les journaux du serveur.
   */
  ?>
  <form class="login" method="post" action="<?= BASE_URL ?>/index.php?page=login">

    <h1>Jumão BDE</h1>
    <p class="login-sous-titre">Portail de gestion — ISEN Brest</p>

    <?php if ($erreur !== '') : ?>
      <p class="login-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <label for="email">Adresse email</label>
    <?php
    /*
     * type="email" : le navigateur vérifie sommairement le format et propose
     * le clavier adapté sur mobile. Ce n'est qu'un confort — la vraie
     * vérification reste côté serveur, un contrôle fait dans le navigateur
     * pouvant toujours être contourné.
     *
     * autofocus place le curseur dans le champ au chargement.
     *
     * On ne réaffiche volontairement PAS l'adresse saisie après un échec :
     * ce serait plus confortable, mais cela confirmerait à un inconnu quelle
     * adresse il vient d'essayer. Point à rediscuter si c'est jugé trop rigide.
     */
    ?>
    <input type="email" id="email" name="email" autocomplete="username" required autofocus>

    <label for="password">Mot de passe</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit" class="btn">Se connecter</button>
  </form>

</body>
</html>
