<?php
/**
 * ============================================================================
 *  VUE — demandes de remboursement
 * ============================================================================
 *  Reçoit du ReimbursementController :
 *    $demandes, $statutFiltre, $flash, $aucunExercice
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$euros = static fn (float|string|null $m): string => number_format((float) $m, 2, ',', ' ') . ' €';

$libelle = static fn (string $s): string => match ($s) {
    'en_attente' => 'En attente',
    'valide'     => 'Acceptée, à payer',
    'refuse'     => 'Refusée',
    'rembourse'  => 'Remboursée',
    default      => $s,
};
?>

<?php if ($aucunExercice) : ?>

  <p class="flash flash-erreur">
    Aucun exercice n'existe encore. Les demandes de remboursement y sont
    rattachées : créez-en un d'abord.
  </p>

<?php else : ?>

  <?php if ($flash !== null) : ?>
    <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['texte']) ?>
    </p>
  <?php endif; ?>

  <?php if (exercice_consulte_est_actif()) : ?>
    <p class="barre-actions">
      <a class="btn" href="<?= BASE_URL ?>/index.php?page=remboursement-nouveau">
        + Nouvelle demande
      </a>
    </p>
  <?php endif; ?>

  <form method="get" action="<?= BASE_URL ?>/index.php" class="filtres">
    <input type="hidden" name="page" value="remboursements">
    <div class="filtre-champ">
      <label for="f-statut">Statut</label>
      <select id="f-statut" name="statut" onchange="this.form.submit()">
        <option value="">Tous</option>
        <?php foreach (Reimbursement::STATUTS as $s) : ?>
          <option value="<?= $s ?>" <?= $statutFiltre === $s ? 'selected' : '' ?>>
            <?= htmlspecialchars($libelle($s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filtre-champ">
      <button type="submit" class="btn">Filtrer</button>
    </div>
  </form>

  <?php if ($demandes === []) : ?>

    <p class="vide">Aucune demande de remboursement.</p>

  <?php else : ?>

    <?php foreach ($demandes as $d) : ?>
      <section class="bloc demande demande-<?= htmlspecialchars($d['Status']) ?>">

        <h2>
          <?= $euros($d['Amount']) ?>
          <span class="note">— <?= htmlspecialchars($d['ClubName']) ?></span>
          <span class="badge badge-<?= $d['Status'] === 'refuse' ? 'rouge' : ($d['Status'] === 'en_attente' ? 'orange' : '') ?>">
            <?= htmlspecialchars($libelle($d['Status'])) ?>
          </span>
        </h2>

        <dl class="infos">
          <dt>Bénéficiaire</dt>
          <dd>
            <?= htmlspecialchars($d['Beneficiary_FirstName'] . ' ' . $d['Beneficiary_LastName']) ?>
            <?php if (!empty($d['Beneficiary_Email'])) : ?>
              <span class="note">— <?= htmlspecialchars($d['Beneficiary_Email']) ?></span>
            <?php endif; ?>
          </dd>

          <dt>Achat</dt>
          <dd>
            <?= htmlspecialchars($d['Description']) ?>
            <span class="note">
              le <?= htmlspecialchars(date('d/m/Y', strtotime($d['Purchase_Date']))) ?>
              · pôle <?= htmlspecialchars($d['PoleName']) ?>
              <?php if (!empty($d['CategoryName'])) : ?>
                · <?= htmlspecialchars($d['CategoryName']) ?>
              <?php endif; ?>
            </span>
          </dd>

          <?php
          /*
           * Le justificatif est mis en évidence : c'est sur lui que le
           * bureau fonde sa décision. Son absence est signalée tout
           * aussi clairement — accepter une demande sans pièce est un
           * choix, pas un oubli.
           */
          ?>
          <dt>Justificatif</dt>
          <dd>
            <?php if (!empty($d['Receipt'])) : ?>
              <a href="<?= BASE_URL ?>/index.php?page=remboursement-justificatif&amp;id=<?= (int) $d['ReimbursementID'] ?>"
                 target="_blank" rel="noopener">📎 Consulter la pièce</a>
            <?php else : ?>
              <span class="badge badge-orange">aucun justificatif fourni</span>
            <?php endif; ?>
          </dd>

          <?php if (!empty($d['Treasurer_Notes'])) : ?>
            <dt>Observation du bureau</dt>
            <dd><?= nl2br(htmlspecialchars($d['Treasurer_Notes'])) ?></dd>
          <?php endif; ?>

          <?php if ($d['TransactionID'] !== null) : ?>
            <dt>Dépense générée</dt>
            <dd>
              <?php
              /*
               * Le lien vers la transaction créée automatiquement : il
               * matérialise le rattachement, et permet de vérifier que
               * la dépense existe bien en comptabilité.
               */
              ?>
              Transaction n°<?= (int) $d['TransactionID'] ?>
              <span class="note">créée automatiquement lors du remboursement</span>
            </dd>
          <?php endif; ?>
        </dl>

        <?php if (exercice_consulte_est_actif()) : ?>
          <div class="actions-demande">

            <?php if ($d['Status'] === 'en_attente') : ?>

              <?php if (est_bureau()) : ?>
                <?php
                /*
                 * Accepter ou refuser : un seul formulaire, l'observation
                 * étant commune aux deux décisions. Le bouton cliqué
                 * détermine le statut transmis — deux boutons submit de
                 * même nom mais de valeurs différentes.
                 */
                ?>
                <form method="post" action="<?= BASE_URL ?>/index.php?page=remboursement-decider">
                  <?= champ_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int) $d['ReimbursementID'] ?>">
                  <label for="notes-<?= (int) $d['ReimbursementID'] ?>">
                    Observation <span class="note">(facultative)</span>
                  </label>
                  <textarea id="notes-<?= (int) $d['ReimbursementID'] ?>" name="notes" rows="2"
                            placeholder="Justificatif conforme / hors budget…"></textarea>
                  <button type="submit" name="statut" value="valide" class="btn">Accepter</button>
                  <button type="submit" name="statut" value="refuse" class="btn">Refuser</button>
                </form>
              <?php endif; ?>

              <?php
              // Modification et suppression tant que rien n'est décidé.
              ?>
              <a class="btn" href="<?= BASE_URL ?>/index.php?page=remboursement-modifier&amp;id=<?= (int) $d['ReimbursementID'] ?>">Modifier</a>

              <?php if (est_bureau()) : ?>
                <form method="post" action="<?= BASE_URL ?>/index.php?page=remboursement-supprimer"
                      class="form-ligne"
                      onsubmit="return confirm('Supprimer cette demande ?');">
                  <?= champ_csrf() ?>
                  <input type="hidden" name="id" value="<?= (int) $d['ReimbursementID'] ?>">
                  <button type="submit" class="btn btn-petit">Supprimer</button>
                </form>
              <?php endif; ?>

            <?php elseif ($d['Status'] === 'valide' && est_bureau()) : ?>

              <?php
              /*
               * Le paiement effectif. C'est cette action qui crée la
               * dépense en comptabilité — d'où la confirmation.
               */
              ?>
              <form method="post" action="<?= BASE_URL ?>/index.php?page=remboursement-payer"
                    onsubmit="return confirm('Confirmer le remboursement ? Une dépense sera créée automatiquement.');">
                <?= champ_csrf() ?>
                <input type="hidden" name="id" value="<?= (int) $d['ReimbursementID'] ?>">
                <label for="moyen-<?= (int) $d['ReimbursementID'] ?>">Moyen de paiement</label>
                <select id="moyen-<?= (int) $d['ReimbursementID'] ?>" name="moyen">
                  <?php foreach (Transaction::MOYENS_PAIEMENT as $m) : ?>
                    <option value="<?= $m ?>"><?= htmlspecialchars(ucfirst($m)) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Marquer comme remboursé</button>
              </form>

            <?php endif; ?>
          </div>
        <?php endif; ?>

      </section>
    <?php endforeach; ?>

  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
