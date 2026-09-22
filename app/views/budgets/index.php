<?php
/**
 * ============================================================================
 *  VUE — tableau des budgets de l'exercice
 * ============================================================================
 *  Reçoit du BudgetController :
 *    $budgetBde   — le budget du BDE seul, ou null
 *    $budgets     — ceux des clubs, avec montants calculés et alerte
 *    $repartition — enveloppe clubs reçue, répartie, reste à répartir
 *    $totaux      — la ligne de total des CLUBS, ou null si aucun exercice
 *    $flash       — message de l'action précédente
 *
 *  ⚠ DEUX TABLEAUX, ET AUCUN TOTAL COMMUN. L'ISEN verse deux enveloppes de
 *  nature différente : celle du BDE, qu'il dépense, et celle des clubs,
 *  qu'il répartit. Les additionner afficherait un montant qui ne correspond
 *  à rien — et masquerait la seule question utile sur la seconde : reste-t-il
 *  quelque chose à distribuer ?
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

  <?php // ------------------------------------------- Enveloppe du BDE --- ?>
  <h2 class="titre-section">Le BDE</h2>
  <p class="note">
    Enveloppe propre du BDE, versée par la comptabilité de l'ISEN selon un
    calendrier imposé. Le BDE l'encaisse et la dépense ; il ne décide ni du
    montant ni des échéances.
  </p>

  <?php if ($budgetBde === null) : ?>
    <p class="vide">Aucun budget enregistré pour le BDE sur cet exercice.</p>
  <?php else : ?>
    <div class="tableau-conteneur">
      <table class="tableau">
        <thead>
          <tr>
            <th>Enveloppe ISEN</th>
            <th class="col-montant">Versé</th>
            <th class="col-montant">À venir</th>
            <th class="col-montant">Dépenses</th>
            <th class="col-montant">Recettes</th>
            <th class="col-montant">Solde</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr class="<?= $budgetBde['Alerte'] !== '' ? 'ligne-' . htmlspecialchars($budgetBde['Alerte']) : '' ?>">
            <td><?= $euros($budgetBde['Planned_Amount']) ?>
              <span class="note">en <?= (int) $budgetBde['Disbursement_Count'] ?> versements</span>
            </td>
            <td class="col-montant"><?= $euros($budgetBde['VersementsRecus']) ?></td>
            <td class="col-montant note"><?= $euros($budgetBde['VersementsAVenir']) ?></td>
            <td class="col-montant depense"><?= $euros($budgetBde['Depenses']) ?></td>
            <td class="col-montant recette"><?= $euros($budgetBde['Recettes']) ?></td>
            <td class="col-montant">
              <strong><?= $euros($budgetBde['SoldeDisponible']) ?></strong>
              <?php if ($budgetBde['Alerte'] === 'rouge') : ?>
                <br><span class="badge badge-rouge">à découvert</span>
              <?php elseif ($budgetBde['Alerte'] === 'orange') : ?>
                <br><span class="badge badge-orange">solde faible</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/index.php?page=budget&amp;id=<?= (int) $budgetBde['BudgetID'] ?>">Détail</a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php // ---------------------------------------- Enveloppe des clubs --- ?>
  <h2 class="titre-section">Les clubs</h2>
  <p class="note">
    Enveloppe distincte, versée au BDE par l'ISEN et <strong>destinée aux
    clubs</strong> : c'est le BDE qui décide de sa répartition, en accord
    avec eux.
  </p>

  <?php
  /*
   * L'indicateur central de ce tableau : sur ce qui a été reçu pour les
   * clubs, combien est déjà attribué. Sans lui, on verrait les budgets
   * sans jamais savoir s'il reste de quoi en accorder un autre.
   */
  ?>
  <div class="kpis">
    <div class="kpi">
      <div class="kpi-valeur"><?= $euros($repartition['enveloppe']) ?></div>
      <div class="kpi-libelle">Reçu de l'ISEN pour les clubs</div>
    </div>
    <div class="kpi">
      <div class="kpi-valeur"><?= $euros($repartition['reparti']) ?></div>
      <div class="kpi-libelle">
        Déjà réparti<?= $repartition['taux'] !== null ? ' — ' . (int) $repartition['taux'] . ' %' : '' ?>
      </div>
    </div>
    <div class="kpi <?= $repartition['reste'] < 0 ? 'kpi-alerte' : '' ?>">
      <div class="kpi-valeur"><?= $euros($repartition['reste']) ?></div>
      <div class="kpi-libelle">
        <?= $repartition['reste'] < 0 ? 'Réparti EN TROP' : 'Reste à répartir' ?>
      </div>
    </div>
  </div>

  <?php if ($repartition['reste'] < 0) : ?>
    <p class="flash flash-erreur">
      Les budgets alloués dépassent l'enveloppe reçue pour les clubs de
      <?= $euros(abs($repartition['reste'])) ?>.
    </p>
  <?php endif; ?>

  <?php if ($repartition['enveloppe'] <= 0) : ?>
    <p class="note note-alerte">
      Le montant reçu de l'ISEN pour les clubs n'est pas renseigné sur cet
      exercice : le suivi de répartition ne peut pas être calculé.
      Il se saisit dans <a href="<?= BASE_URL ?>/index.php?page=exercices">Exercices</a>.
    </p>
  <?php endif; ?>

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
      <br>Le total ci-dessus ne porte que sur les clubs — l'enveloppe du BDE
      est d'une autre nature et n'y est jamais ajoutée.
    </p>

  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
