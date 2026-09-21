<?php
/**
 * ============================================================================
 *  VUE — catégories et pôles
 * ============================================================================
 *  Reçoit de l'AdminController :
 *    $categories, $poles, $usageCategories, $usagePoles, $flash
 *
 *  Chaque ligne est son propre formulaire : on modifie directement dans la
 *  liste, sans page intermédiaire. Pour des entrées à un ou deux champs,
 *  ouvrir un écran dédié pour changer un mot serait disproportionné.
 *
 *  ⚠ POURQUOI DES <div> ET NON UN <table> ICI.
 *  Le HTML n'autorise que <td> et <th> comme enfants directs d'un <tr> :
 *  un <form> glissé entre les deux est invalide, et le navigateur le
 *  déplace hors du tableau — les champs se retrouvent alors rattachés à
 *  rien, et l'envoi part vide. Il existe bien l'attribut form="..." pour
 *  relier un champ à un formulaire situé ailleurs, mais des lignes en flex
 *  sont plus simples à lire et à maintenir pour ce besoin.
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$libelleType = static fn (string $t): string => match ($t) {
    'depense' => 'Dépense',
    'recette' => 'Recette',
    'both'    => 'Les deux',
    default   => $t,
};
?>

<?php if ($flash !== null) : ?>
  <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['texte']) ?>
  </p>
<?php endif; ?>

<p class="note">
  Ces deux listes alimentent la saisie des transactions et des demandes de
  remboursement. Une entrée ne se supprime jamais — des écritures y
  renvoient — mais elle peut être <strong>archivée</strong> : elle disparaît
  alors des formulaires, tandis que les écritures passées conservent leur
  libellé. La colonne « usages » indique combien d'écritures s'y rattachent.
</p>

<div class="colonnes">

  <?php // ------------------------------------------------- Catégories --- ?>
  <section class="bloc">
    <h2>Catégories <span class="note">— ce qui a été acheté</span></h2>

    <?php foreach ($categories as $c) : ?>
      <form method="post" action="<?= BASE_URL ?>/index.php?page=categorie-enregistrer"
            class="ligne-reference <?= (int) $c['IsActive'] !== 1 ? 'archive' : '' ?>">
        <?= champ_csrf() ?>
        <input type="hidden" name="id" value="<?= (int) $c['CategoryID'] ?>">

        <input type="text" name="nom" maxlength="50" required
               value="<?= htmlspecialchars($c['Name']) ?>" aria-label="Nom de la catégorie">

        <select name="type" aria-label="Type">
          <?php foreach (['depense', 'recette', 'both'] as $t) : ?>
            <option value="<?= $t ?>" <?= $c['Type'] === $t ? 'selected' : '' ?>>
              <?= htmlspecialchars($libelleType($t)) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <span class="note usage"
              title="écritures utilisant cette catégorie"><?= (int) ($usageCategories[(int) $c['CategoryID']] ?? 0) ?></span>

        <label class="case-inline">
          <input type="checkbox" name="actif" value="1"
                 <?= (int) $c['IsActive'] === 1 ? 'checked' : '' ?>> active
        </label>

        <button type="submit" class="btn btn-petit">Enregistrer</button>
      </form>
    <?php endforeach; ?>

    <h3 class="titre-section">Ajouter une catégorie</h3>
    <form method="post" action="<?= BASE_URL ?>/index.php?page=categorie-enregistrer">
      <?= champ_csrf() ?>
      <input type="hidden" name="id" value="0">

      <label for="cat-nom">Nom</label>
      <input type="text" id="cat-nom" name="nom" maxlength="50" required
             placeholder="Frais de mission">

      <label for="cat-type">Type</label>
      <select id="cat-type" name="type">
        <option value="depense">Dépense</option>
        <option value="recette">Recette</option>
        <option value="both">Les deux</option>
      </select>

      <button type="submit" class="btn">Ajouter</button>
    </form>
  </section>

  <?php // ------------------------------------------------------ Pôles --- ?>
  <section class="bloc">
    <h2>Pôles <span class="note">— quelle équipe a dépensé</span></h2>

    <?php foreach ($poles as $p) : ?>
      <form method="post" action="<?= BASE_URL ?>/index.php?page=pole-enregistrer"
            class="ligne-reference <?= (int) $p['IsActive'] !== 1 ? 'archive' : '' ?>">
        <?= champ_csrf() ?>
        <input type="hidden" name="id" value="<?= (int) $p['PoleID'] ?>">

        <input type="text" name="nom" maxlength="50" required
               value="<?= htmlspecialchars($p['Name']) ?>" aria-label="Nom du pôle">

        <span class="note usage"
              title="écritures utilisant ce pôle"><?= (int) ($usagePoles[(int) $p['PoleID']] ?? 0) ?></span>

        <label class="case-inline">
          <input type="checkbox" name="actif" value="1"
                 <?= (int) $p['IsActive'] === 1 ? 'checked' : '' ?>> actif
        </label>

        <button type="submit" class="btn btn-petit">Enregistrer</button>
      </form>
    <?php endforeach; ?>

    <h3 class="titre-section">Ajouter un pôle</h3>
    <form method="post" action="<?= BASE_URL ?>/index.php?page=pole-enregistrer">
      <?= champ_csrf() ?>
      <input type="hidden" name="id" value="0">

      <label for="pole-nom">Nom</label>
      <input type="text" id="pole-nom" name="nom" maxlength="50" required
             placeholder="Sport">

      <button type="submit" class="btn">Ajouter</button>
    </form>

    <p class="note">
      Le pôle étant obligatoire sur chaque écriture, il doit toujours en
      rester au moins un actif.
    </p>
  </section>

</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
