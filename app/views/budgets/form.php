<?php
/**
 * ============================================================================
 *  VUE — allouer un budget à un club
 * ============================================================================
 *  Reçoit du BudgetController :
 *    $clubs  — clubs actifs n'ayant pas encore de budget sur l'exercice
 *    $erreur — message de validation, ou ''
 *    $saisie — valeurs à réafficher après un refus
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$exerciceEnCours = exercice_consulte();
?>

<p><a href="<?= BASE_URL ?>/index.php?page=budgets">&larr; Retour aux budgets</a></p>

<?php if ($erreur !== '') : ?>
  <p class="flash flash-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
<?php endif; ?>

<?php if ($clubs === []) : ?>

  <p class="vide">
    Tous les clubs actifs ont déjà un budget sur cet exercice.
  </p>

<?php else : ?>

  <section class="bloc">
    <form method="post" action="<?= BASE_URL ?>/index.php?page=budget-enregistrer">
      <?= champ_csrf() ?>

      <p class="note">
        Exercice <strong><?= htmlspecialchars($exerciceEnCours['Year']) ?></strong>.
        Un club ne peut avoir qu'un seul budget par exercice.
      </p>

      <label for="club">Club</label>
      <?php
      /*
       * La liste ne contient que les clubs sans budget sur cet exercice :
       * on ne propose pas un choix qui serait ensuite refusé.
       */
      ?>
      <select id="club" name="club" required>
        <option value="">— choisir un club —</option>
        <?php foreach ($clubs as $c) : ?>
          <option value="<?= (int) $c['ClubID'] ?>"
            <?= $saisie['club'] === (string) $c['ClubID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['Name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="montant">Montant alloué (€)</label>
      <input type="text" id="montant" name="montant" required
             inputmode="decimal" placeholder="1500.00"
             value="<?= htmlspecialchars($saisie['montant']) ?>">

      <label for="tranches">Nombre de tranches</label>
      <select id="tranches" name="tranches" required>
        <?php foreach ([1, 2, 3, 4, 6] as $n) : ?>
          <option value="<?= $n ?>" <?= $saisie['tranches'] === (string) $n ? 'selected' : '' ?>>
            <?= $n ?> tranche<?= $n > 1 ? 's' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="note">
        Les tranches sont créées automatiquement, de montants égaux, aux dates
        habituelles : <strong>2 tranches</strong> = mi-octobre et fin janvier
        (rythme des clubs) ; <strong>4 tranches</strong> = octobre, décembre,
        février et juin (rythme du BDE). Montants et dates restent
        modifiables ensuite.
      </p>

      <label for="notes">Notes <span class="note">(facultatif)</span></label>
      <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($saisie['notes']) ?></textarea>

      <button type="submit" class="btn">Allouer le budget</button>
    </form>
  </section>

<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
