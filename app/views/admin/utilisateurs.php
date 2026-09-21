<?php
/**
 * ============================================================================
 *  VUE — liste des comptes utilisateurs
 * ============================================================================
 *  Reçoit de l'AdminController : $utilisateurs, $flash
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$moi = (int) $_SESSION['user_id'];
?>

<?php if ($flash !== null) : ?>
  <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['texte']) ?>
  </p>
<?php endif; ?>

<p class="barre-actions">
  <a class="btn" href="<?= BASE_URL ?>/index.php?page=utilisateur-nouveau">+ Nouveau compte</a>
</p>

<div class="tableau-conteneur">
  <table class="tableau">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Email</th>
        <th>Rôle</th>
        <th>Club</th>
        <th>Droits</th>
        <th>État</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($utilisateurs as $u) : ?>
        <?php $estMoi = (int) $u['UserID'] === $moi; ?>
        <tr class="<?= (int) $u['IsActive'] !== 1 ? 'ligne-annulee' : '' ?>">
          <td>
            <?= htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']) ?>
            <?php if ($estMoi) : ?>
              <span class="note">(vous)</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($u['Email']) ?></td>
          <td><?= htmlspecialchars(libelle_role($u['Role'])) ?></td>
          <td><?= $u['ClubName'] !== null ? htmlspecialchars($u['ClubName']) : '—' ?></td>
          <td>
            <?php if ((int) $u['IsAdmin'] === 1) : ?>
              <span class="badge">administrateur</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int) $u['IsActive'] !== 1) : ?>
              <span class="badge badge-rouge">désactivé</span>
            <?php elseif ((int) $u['MustChangePassword'] === 1) : ?>
              <?php
              /*
               * Signale les comptes dont le mot de passe temporaire n'a
               * pas encore été changé : utile pour relancer quelqu'un qui
               * ne s'est jamais connecté.
               */
              ?>
              <span class="badge badge-orange">mot de passe à changer</span>
            <?php else : ?>
              actif
            <?php endif; ?>
          </td>
          <td class="col-actions">
            <a href="<?= BASE_URL ?>/index.php?page=utilisateur-modifier&amp;id=<?= (int) $u['UserID'] ?>">Modifier</a>

            <form method="post" action="<?= BASE_URL ?>/index.php?page=utilisateur-reinitialiser"
                  class="form-ligne"
                  onsubmit="return confirm('Engendrer un nouveau mot de passe temporaire ? L\'ancien cessera aussitôt de fonctionner.');">
              <?= champ_csrf() ?>
              <input type="hidden" name="id" value="<?= (int) $u['UserID'] ?>">
              <button type="submit" class="btn btn-petit">Réinitialiser le mot de passe</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<p class="note">
  Un compte ne se supprime jamais : les transactions saisies pointent vers
  lui, et l'effacer détruirait l'historique comptable. En fin de mandat,
  décochez « compte actif » — la personne ne peut plus se connecter, mais
  tout ce qu'elle a enregistré reste en place.
</p>

<?php require __DIR__ . '/../layout/footer.php'; ?>
