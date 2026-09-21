<?php
/**
 * ============================================================================
 *  VUE — saisie d'une transaction (création et modification)
 * ============================================================================
 *  Reçoit du TransactionController :
 *    $transaction — null en création, la ligne sinon (ou la saisie refusée)
 *    $erreur      — message de validation, ou ''
 *    $clubs       — clubs autorisés pour cet utilisateur
 *    $categories  — toutes les catégories
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$estModification = $transaction !== null && (int) ($transaction['TransactionID'] ?? 0) > 0;

/** Valeur d'un champ, reprise de la transaction si elle existe. */
$val = static fn (string $cle, string $defaut = ''): string
    => htmlspecialchars((string) ($transaction[$cle] ?? $defaut));
?>

<p><a href="<?= BASE_URL ?>/index.php?page=transactions">&larr; Retour aux transactions</a></p>

<?php if ($erreur !== '') : ?>
  <p class="flash flash-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
<?php endif; ?>

<section class="bloc">
  <form method="post" action="<?= BASE_URL ?>/index.php?page=transaction-enregistrer">
    <?= champ_csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($transaction['TransactionID'] ?? 0) ?>">

    <label for="type">Type</label>
    <select id="type" name="type" required>
      <option value="depense" <?= $val('Type') === 'depense' ? 'selected' : '' ?>>Dépense</option>
      <option value="recette" <?= $val('Type') === 'recette' ? 'selected' : '' ?>>Recette</option>
    </select>

    <?php
    /*
     * Le montant est toujours saisi POSITIF : c'est le type choisi
     * au-dessus qui donne le sens. Un montant négatif sur une dépense la
     * transformerait en recette dans les calculs de solde.
     */
    ?>
    <label for="montant">Montant (€)</label>
    <input type="text" id="montant" name="montant" required inputmode="decimal"
           placeholder="42.50" value="<?= $val('Amount') ?>">

    <label for="date">Date</label>
    <?php
    /*
     * C'est cette date qui détermine l'exercice de rattachement — pas
     * l'exercice affiché dans la barre latérale. Une date hors de tout
     * exercice, ou dans un exercice clos, est refusée à l'enregistrement.
     */
    ?>
    <input type="date" id="date" name="date" required
           value="<?= $val('Date', date('Y-m-d')) ?>">
    <p class="note">
      L'exercice comptable est déterminé par cette date.
    </p>

    <label for="description">Libellé</label>
    <input type="text" id="description" name="description" required maxlength="255"
           placeholder="Achat de composants électroniques"
           value="<?= $val('Description') ?>">

    <label for="club">Club</label>
    <?php if (count($clubs) === 1) : ?>
      <?php
      /*
       * Un responsable n'a qu'un club : on l'affiche sans le rendre
       * modifiable. Le contrôleur impose de toute façon son club, quelle
       * que soit la valeur envoyée.
       */
      ?>
      <input type="text" id="club" value="<?= htmlspecialchars($clubs[0]['Name']) ?>" disabled>
      <input type="hidden" name="club" value="<?= (int) $clubs[0]['ClubID'] ?>">
    <?php else : ?>
      <select id="club" name="club" required>
        <option value="">— choisir —</option>
        <?php foreach ($clubs as $c) : ?>
          <option value="<?= (int) $c['ClubID'] ?>"
            <?= (int) ($transaction['ClubID'] ?? 0) === (int) $c['ClubID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['Name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <label for="categorie">Catégorie <span class="note">(facultative)</span></label>
    <select id="categorie" name="categorie">
      <option value="">— aucune —</option>
      <?php foreach ($categories as $c) : ?>
        <option value="<?= (int) $c['CategoryID'] ?>"
          data-type="<?= htmlspecialchars($c['Type']) ?>"
          <?= (int) ($transaction['CategoryID'] ?? 0) === (int) $c['CategoryID'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="moyen">Moyen de paiement <span class="note">(facultatif)</span></label>
    <select id="moyen" name="moyen">
      <option value="">— non précisé —</option>
      <?php foreach (Transaction::MOYENS_PAIEMENT as $m) : ?>
        <option value="<?= $m ?>" <?= $val('Payment_Method') === $m ? 'selected' : '' ?>>
          <?= htmlspecialchars(ucfirst($m)) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="statut">Statut</label>
    <select id="statut" name="statut" required>
      <option value="valide" <?= $val('Status', 'valide') === 'valide' ? 'selected' : '' ?>>
        Validée — compte dans le solde
      </option>
      <option value="en_attente" <?= $val('Status') === 'en_attente' ? 'selected' : '' ?>>
        En attente — engagée, pas encore débitée
      </option>
      <option value="annule" <?= $val('Status') === 'annule' ? 'selected' : '' ?>>
        Annulée — conservée, hors du solde
      </option>
    </select>

    <label for="notes">Notes <span class="note">(facultatif)</span></label>
    <textarea id="notes" name="notes" rows="2"><?= $val('Notes') ?></textarea>

    <button type="submit" class="btn">
      <?= $estModification ? 'Enregistrer les modifications' : 'Enregistrer la transaction' ?>
    </button>
  </form>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
