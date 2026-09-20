<?php
/**
 * ============================================================================
 *  VUE — formulaire de club (création ET modification)
 * ============================================================================
 *  Reçoit du ClubController :
 *    $club   — null en création, la ligne du club en modification, ou les
 *              valeurs saisies quand un envoi a été refusé
 *    $erreur — message de validation, ou chaîne vide
 *    $titre  — titre de la page
 *
 *  Un seul fichier pour les deux cas : les champs sont identiques, et en
 *  maintenir deux versions garantirait qu'elles finissent par diverger.
 * ============================================================================
 */
declare(strict_types=1);

// $club === null -> création. Sinon modification.
$estModification = $club !== null && (int) ($club['ClubID'] ?? 0) > 0;

require __DIR__ . '/../layout/header.php';
?>

<p>
  <a href="<?= BASE_URL ?>/index.php?page=clubs">&larr; Retour à la liste des clubs</a>
</p>

<?php if ($erreur !== '') : ?>
  <p class="flash flash-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
<?php endif; ?>

<section class="bloc">
  <form method="post" action="<?= BASE_URL ?>/index.php?page=club-enregistrer">
    <?= champ_csrf() ?>

    <?php
    /*
     * L'identifiant voyage dans un champ caché : c'est lui qui indique au
     * contrôleur s'il s'agit d'une création (0) ou d'une modification.
     *
     * Ce champ n'est PAS une faille : le contrôleur revérifie que le club
     * existe, et exige le rôle « bureau » avant tout. Modifier cette valeur
     * dans le navigateur permettrait au mieux de modifier un autre club —
     * ce que le bureau a de toute façon le droit de faire.
     */
    ?>
    <input type="hidden" name="id" value="<?= (int) ($club['ClubID'] ?? 0) ?>">

    <label for="nom">Nom du club</label>
    <input type="text" id="nom" name="nom" maxlength="50" required
           value="<?= htmlspecialchars((string) ($club['Name'] ?? '')) ?>">

    <label for="description">Description <span class="note">(facultative)</span></label>
    <textarea id="description" name="description" rows="3"><?= htmlspecialchars((string) ($club['Description'] ?? '')) ?></textarea>

    <?php
    /*
     * Un club ne se supprime pas, il s'archive : ses transactions passées
     * pointent vers lui, et l'effacer détruirait l'historique comptable.
     * Même raisonnement que pour les comptes utilisateurs désactivés.
     */
    ?>
    <label class="case">
      <input type="checkbox" name="actif" value="1"
             <?= (int) ($club['IsActive'] ?? 1) === 1 ? 'checked' : '' ?>>
      Club actif
    </label>
    <p class="note">
      Décocher archive le club : il disparaît des listes courantes, mais ses
      transactions et son historique sont conservés.
    </p>

    <button type="submit" class="btn">
      <?= $estModification ? 'Enregistrer les modifications' : 'Créer le club' ?>
    </button>
  </form>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
