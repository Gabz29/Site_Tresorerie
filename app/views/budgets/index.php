<?php
/**
 * ============================================================================
 *  VUE — tableau des budgets de l'exercice
 * ============================================================================
 *  Reçoit du BudgetController :
 *    $budgets — un par club, avec montants calculés et niveau d'alerte
 *    $totaux  — la ligne de total, ou null si aucun exercice
 *    $flash   — message de l'action précédente
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

/** Met en forme un montant à la française : 1 234,50 € */
$euros = static fn (float|string $m): string => number_format((float) $m, 2, ',', ' ') . ' €';
?>

<?php if ($flash !== null) : ?>
  <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['texte']) ?>
  </p>
<?php endif; ?>

<?php if ($totaux === null) : ?>

  <p class="flash flash-erreur">
    Aucun exercice n'existe. Créez-en un dans
    <a href="<?= BASE_URL ?>/index.php?page=exercices">Exercices</a> avant d'allouer des budgets.
  </p>

<?php else : ?>

  <?php if (exercice_consulte_est_actif()) : ?>
    <p class="barre-actions">
      <a class="btn" href="<?= BASE_URL ?>/index.php?page=budget-nouveau">+ Allouer un budget</a>
    </p>
  <?php endif; ?>

  <?php if ($budgets === []) : ?>

    <p class="vide">Aucun budget alloué sur cet exercice.</p>

  <?php else : ?>

    <div class="tableau-conteneur">
      <table class="tableau">
        <thead>
          <tr>
            <th>Club</th>
            <th class="col-montant">Alloué</th>
            <th class="col-montant">Versé</th>
            <th class="col-montant">À verser</th>
            <th class="col-montant">Dépenses</th>
            <th class="col-montant">Recettes</th>
            <th class="col-montant">Solde</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($budgets as $b) : ?>
            <tr class="<?= $b['Alerte'] !== '' ? 'ligne-' . htmlspecialchars($b['Alerte']) : '' ?>">
              <td>
                <?= htmlspecialchars($b['ClubName']) ?>
                <?php if ((int) $b['ClubIsActive'] !== 1) : ?>
                  <span class="note">(archivé)</span>
                <?php endif; ?>
              </td>
              <td class="col-montant"><?= $euros($b['Planned_Amount']) ?></td>
              <td class="col-montant"><?= $euros($b['VersementsRecus']) ?></td>
              <td class="col-montant note"><?= $euros($b['VersementsAVenir']) ?></td>
              <td class="col-montant depense"><?= $euros($b['Depenses']) ?></td>
              <td class="col-montant recette"><?= $euros($b['Recettes']) ?></td>
              <td class="col-montant">
                <strong><?= $euros($b['SoldeDisponible']) ?></strong>
                <?php
                /*
                 * L'alerte porte sur le SOLDE, pas sur la consommation du
                 * budget : un club qui a tout dépensé mais encaissé autant
                 * de recettes ne risque rien, et l'alerter pour rien finit
                 * par rendre toutes les alertes invisibles.
                 */
                ?>
                <?php if ($b['Alerte'] === 'rouge') : ?>
                  <br><span class="badge badge-rouge">à découvert</span>
                <?php elseif ($b['Alerte'] === 'orange') : ?>
                  <br><span class="badge badge-orange">solde faible</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="<?= BASE_URL ?>/index.php?page=budget&amp;id=<?= (int) $b['BudgetID'] ?>">Détail</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>Total</th>
            <th class="col-montant"><?= $euros($totaux['alloue']) ?></th>
            <th class="col-montant"><?= $euros($totaux['recus']) ?></th>
            <th class="col-montant"><?= $euros($totaux['avenir']) ?></th>
            <th class="col-montant"><?= $euros($totaux['depenses']) ?></th>
            <th class="col-montant"><?= $euros($totaux['recettes']) ?></th>
            <th class="col-montant"><?= $euros($totaux['solde']) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <p class="note">
      <strong>Solde</strong> = versements reçus + recettes − dépenses validées.
      C'est ce dont le club dispose réellement : un budget alloué mais non
      encore versé n'y figure pas.
    </p>

  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
