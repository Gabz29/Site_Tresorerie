<?php
/**
 * ============================================================================
 *  VUE — détail d'un budget et de ses tranches de versement
 * ============================================================================
 *  Reçoit du BudgetController :
 *    $budget   — la ligne brute (Planned_Amount, Disbursement_Count...)
 *    $detail   — la version enrichie (soldes, alerte), ou null
 *    $club     — le club concerné
 *    $tranches — ses tranches de versement
 *    $flash    — message de l'action précédente
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$euros = static fn (float|string|null $m): string => number_format((float) $m, 2, ',', ' ') . ' €';

/** Libellé lisible d'un état de tranche. */
$etat = static fn (string $s): string => match ($s) {
    'recu'   => 'Versée',
    'prevu'  => 'À verser',
    'annule' => 'Annulée',
    default  => $s,
};
?>

<p><a href="<?= BASE_URL ?>/index.php?page=budgets">&larr; Retour aux budgets</a></p>

<?php if ($flash !== null) : ?>
  <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['texte']) ?>
  </p>
<?php endif; ?>

<?php if ($detail !== null) : ?>
  <section class="bloc">
    <h2>Situation</h2>

    <dl class="infos">
      <dt>Enveloppe allouée</dt>
      <dd><?= $euros($detail['Planned_Amount']) ?>
        <span class="note">en <?= (int) $budget['Disbursement_Count'] ?> tranche(s)</span>
      </dd>

      <dt>Versements reçus</dt>
      <dd><?= $euros($detail['VersementsRecus']) ?>
        <?php if ((float) $detail['VersementsAVenir'] > 0) : ?>
          <span class="note">— reste <?= $euros($detail['VersementsAVenir']) ?> à verser</span>
        <?php endif; ?>
      </dd>

      <dt>Dépenses / recettes validées</dt>
      <dd>
        <span class="depense"><?= $euros($detail['Depenses']) ?></span>
        &nbsp;/&nbsp;
        <span class="recette"><?= $euros($detail['Recettes']) ?></span>
      </dd>

      <dt>Solde disponible</dt>
      <dd>
        <strong><?= $euros($detail['SoldeDisponible']) ?></strong>
        <?php if ($detail['Alerte'] === 'rouge') : ?>
          <span class="badge badge-rouge">à découvert</span>
        <?php elseif ($detail['Alerte'] === 'orange') : ?>
          <span class="badge badge-orange">solde faible</span>
        <?php endif; ?>
      </dd>

      <?php if ($detail['TauxConsommation'] !== null) : ?>
        <dt>Enveloppe consommée</dt>
        <dd>
          <?= (int) $detail['TauxConsommation'] ?> %
          <span class="note">— indicatif : ne déclenche pas d'alerte, les
            recettes propres du club n'y sont pas déduites</span>
        </dd>
      <?php endif; ?>
    </dl>
  </section>
<?php endif; ?>

<?php if ($budget['Notes'] !== null && $budget['Notes'] !== '') : ?>
  <section class="bloc">
    <h2>Notes</h2>
    <p><?= nl2br(htmlspecialchars($budget['Notes'])) ?></p>
  </section>
<?php endif; ?>

<h2 class="titre-section">Tranches de versement</h2>

<div class="tableau-conteneur">
  <table class="tableau">
    <thead>
      <tr>
        <th>N°</th>
        <th class="col-montant">Prévu</th>
        <th>Date prévue</th>
        <th class="col-montant">Versé</th>
        <th>Date réelle</th>
        <th>État</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($tranches === []) : ?>
        <tr><td colspan="7">Aucune tranche enregistrée.</td></tr>
      <?php endif; ?>

      <?php foreach ($tranches as $t) : ?>
        <tr class="<?= $t['Status'] === 'annule' ? 'ligne-annulee' : '' ?>">
          <td><?= (int) $t['Number'] ?></td>
          <td class="col-montant"><?= $euros($t['Planned_Amount']) ?></td>
          <td><?= htmlspecialchars(date('d/m/Y', strtotime($t['Planned_Date']))) ?></td>
          <td class="col-montant">
            <?= $t['Actual_Amount'] !== null ? $euros($t['Actual_Amount']) : '—' ?>
          </td>
          <td>
            <?= $t['Actual_Date'] !== null
                ? htmlspecialchars(date('d/m/Y', strtotime($t['Actual_Date'])))
                : '—' ?>
          </td>
          <td><?= htmlspecialchars($etat($t['Status'])) ?></td>
          <td>
            <?php if ($t['Notes'] !== null && $t['Notes'] !== '') : ?>
              <span class="note"><?= htmlspecialchars($t['Notes']) ?></span><br>
            <?php endif; ?>

            <?php
            /*
             * Actions réservées au bureau ET à un exercice encore ouvert.
             * Un exercice clos est figé : ses versements ne se modifient
             * plus. Les contrôleurs le revérifient — masquer ces boutons
             * n'est qu'un confort.
             */
            ?>
            <?php if (exercice_consulte_est_actif()) : ?>

              <?php if ($t['Status'] === 'prevu') : ?>
                <?php
                /*
                 * Le montant réel est pré-rempli avec le montant prévu, et
                 * la date avec aujourd'hui : dans la grande majorité des
                 * cas, il n'y a qu'à cliquer. Les deux restent modifiables,
                 * un versement pouvant différer de ce qui était prévu.
                 */
                ?>
                <form method="post" action="<?= BASE_URL ?>/index.php?page=tranche-versee" class="form-tranche">
                  <?= champ_csrf() ?>
                  <input type="hidden" name="tranche" value="<?= (int) $t['DisbursementID'] ?>">
                  <input type="text" name="montant" inputmode="decimal" class="mini"
                         value="<?= number_format((float) $t['Planned_Amount'], 2, '.', '') ?>"
                         aria-label="Montant réellement versé">
                  <input type="date" name="date" class="mini"
                         value="<?= date('Y-m-d') ?>"
                         aria-label="Date du versement">
                  <button type="submit" class="btn btn-petit">Versée</button>
                </form>

                <form method="post" action="<?= BASE_URL ?>/index.php?page=tranche-annulee" class="form-tranche">
                  <?= champ_csrf() ?>
                  <input type="hidden" name="tranche" value="<?= (int) $t['DisbursementID'] ?>">
                  <input type="text" name="motif" class="mini" placeholder="motif (facultatif)"
                         maxlength="200" aria-label="Motif de l'annulation">
                  <button type="submit" class="btn btn-petit">Annuler</button>
                </form>

              <?php else : ?>
                <form method="post" action="<?= BASE_URL ?>/index.php?page=tranche-rouverte" class="form-tranche">
                  <?= champ_csrf() ?>
                  <input type="hidden" name="tranche" value="<?= (int) $t['DisbursementID'] ?>">
                  <button type="submit" class="btn btn-petit">Remettre en attente</button>
                </form>
              <?php endif; ?>

            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
