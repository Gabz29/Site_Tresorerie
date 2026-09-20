<?php
/**
 * ============================================================================
 *  VUE — Mon compte
 * ============================================================================
 *  Reçoit d'AuthController :
 *    $utilisateur — la ligne du compte connecté, lue en base
 *    $flash       — message de résultat de l'action précédente, ou null
 *    $titre       — titre de la page
 *
 *  Deux formulaires distincts sur une même page : l'identité d'un côté, le
 *  mot de passe de l'autre. Les mélanger obligerait à ressaisir son mot de
 *  passe pour corriger une faute dans son prénom.
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';
?>

<?php if ($flash !== null) : ?>
  <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['texte']) ?>
  </p>
<?php endif; ?>

<section class="bloc">
  <h2>Informations du compte</h2>

  <?php
  /*
   * TOUT EST EN LECTURE SEULE ICI, sauf le mot de passe (bloc suivant).
   *
   * Nom et prénom : cette application est un registre comptable. Le nom
   * affiché indique qui a saisi quelle transaction ; si chacun pouvait se
   * renommer en cours d'année, l'historique deviendrait illisible et la
   * passation au bureau suivant invérifiable.
   *
   * Email : c'est l'identifiant de connexion. Une faute de frappe et la
   * personne perdrait l'accès à son propre compte, sans pouvoir se
   * reconnecter pour corriger.
   *
   * Ces champs sont modifiables par l'administrateur (tâche 2.10).
   */
  ?>
  <dl class="infos">
    <dt>Nom et prénom</dt>
    <dd><?= htmlspecialchars($utilisateur['FirstName'] . ' ' . $utilisateur['LastName']) ?></dd>

    <dt>Adresse email</dt>
    <dd><?= htmlspecialchars($utilisateur['Email']) ?>
      <span class="note">— sert d'identifiant de connexion</span>
    </dd>

    <dt>Rôle</dt>
    <dd><?= htmlspecialchars(libelle_role($utilisateur['Role'])) ?></dd>
  </dl>

  <p class="note">
    Ces informations sont gérées par l'administrateur du BDE.
    Contactez-le en cas d'erreur.
  </p>
</section>

<section class="bloc">
  <h2>Changer mon mot de passe</h2>

  <form method="post" action="<?= BASE_URL ?>/index.php?page=profil">
    <?= champ_csrf() ?>

    <?php
    /*
     * Le mot de passe actuel est redemandé bien que la personne soit déjà
     * connectée : si un poste reste ouvert sans surveillance, cela empêche
     * un tiers de s'approprier le compte en changeant le mot de passe.
     *
     * autocomplete="new-password" indique au gestionnaire de mots de passe
     * du navigateur qu'il s'agit d'une création, pas d'une connexion.
     */
    ?>
    <label for="mdp_actuel">Mot de passe actuel</label>
    <input type="password" id="mdp_actuel" name="mdp_actuel"
           autocomplete="current-password" required>

    <label for="mdp_nouveau">Nouveau mot de passe</label>
    <input type="password" id="mdp_nouveau" name="mdp_nouveau"
           autocomplete="new-password" minlength="8" required>
    <p class="note">8 caractères minimum.</p>

    <label for="mdp_confirmation">Confirmer le nouveau mot de passe</label>
    <input type="password" id="mdp_confirmation" name="mdp_confirmation"
           autocomplete="new-password" minlength="8" required>

    <button type="submit" class="btn">Changer le mot de passe</button>
  </form>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
