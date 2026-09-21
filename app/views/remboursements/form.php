<?php
/**
 * ============================================================================
 *  VUE — demande de remboursement (création et modification)
 * ============================================================================
 *  Reçoit du ReimbursementController :
 *    $demande, $erreur, $clubs, $categories
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$estModification = $demande !== null && (int) ($demande['ReimbursementID'] ?? 0) > 0;

$val = static fn (string $cle, string $defaut = ''): string
    => htmlspecialchars((string) ($demande[$cle] ?? $defaut));
?>

<p><a href="<?= BASE_URL ?>/index.php?page=remboursements">&larr; Retour aux remboursements</a></p>

<?php if ($erreur !== '') : ?>
  <p class="flash flash-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
<?php endif; ?>

<section class="bloc">
  <?php // enctype obligatoire dès qu'un champ fichier est présent. ?>
  <form method="post" enctype="multipart/form-data"
        action="<?= BASE_URL ?>/index.php?page=remboursement-enregistrer">
    <?= champ_csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($demande['ReimbursementID'] ?? 0) ?>">

    <?php
    /*
     * LE BÉNÉFICIAIRE EST DU TEXTE LIBRE, et ce n'est pas un oubli : la
     * personne qui a avancé l'argent n'a aucune raison d'avoir un compte
     * dans l'application. Un adhérent qui achète des cordes de guitare pour
     * le club doit pouvoir être remboursé sans qu'on lui crée un accès.
     *
     * C'est la personne CONNECTÉE qui dépose la demande ; le bénéficiaire
     * est quelqu'un d'autre le plus souvent.
     */
    ?>
    <h2>Qui a avancé l'argent ?</h2>

    <label for="prenom">Prénom</label>
    <input type="text" id="prenom" name="prenom" required maxlength="50"
           value="<?= $val('Beneficiary_FirstName') ?>">

    <label for="nom">Nom</label>
    <input type="text" id="nom" name="nom" required maxlength="100"
           value="<?= $val('Beneficiary_LastName') ?>">

    <label for="email">Email <span class="note">(facultatif)</span></label>
    <input type="email" id="email" name="email" maxlength="250"
           value="<?= $val('Beneficiary_Email') ?>">
    <p class="note">Utile pour prévenir la personne une fois le remboursement effectué.</p>

    <h2>L'achat</h2>

    <label for="montant">Montant avancé (€)</label>
    <input type="text" id="montant" name="montant" required inputmode="decimal"
           placeholder="42.50" value="<?= $val('Amount') ?>">

    <label for="achat">Date de l'achat</label>
    <input type="date" id="achat" name="achat" required
           max="<?= date('Y-m-d') ?>"
           value="<?= $val('Purchase_Date', date('Y-m-d')) ?>">

    <label for="description">Libellé</label>
    <input type="text" id="description" name="description" required maxlength="250"
           placeholder="Câbles et connecteurs" value="<?= $val('Description') ?>">

    <label for="club">Club concerné</label>
    <?php if (count($clubs) === 1) : ?>
      <input type="text" id="club" value="<?= htmlspecialchars($clubs[0]['Name']) ?>" disabled>
      <input type="hidden" name="club" value="<?= (int) $clubs[0]['ClubID'] ?>">
    <?php else : ?>
      <select id="club" name="club" required>
        <option value="">— choisir —</option>
        <?php foreach ($clubs as $c) : ?>
          <option value="<?= (int) $c['ClubID'] ?>"
            <?= (int) ($demande['ClubID'] ?? 0) === (int) $c['ClubID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['Name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <label for="pole">Pôle</label>
    <select id="pole" name="pole" required>
      <option value="">— choisir —</option>
      <?php foreach ($poles as $p) : ?>
        <option value="<?= (int) $p['PoleID'] ?>"
          <?= (int) ($demande['PoleID'] ?? 0) === (int) $p['PoleID'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <p class="note">
      L'équipe pour laquelle l'achat a été fait. La dépense générée au
      remboursement reprendra ce pôle.
    </p>

    <label for="categorie">Catégorie</label>
    <select id="categorie" name="categorie" required>
      <option value="">— choisir —</option>
      <?php foreach ($categories as $c) : ?>
        <option value="<?= (int) $c['CategoryID'] ?>"
          <?= (int) ($demande['CategoryID'] ?? 0) === (int) $c['CategoryID'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <?php
    /*
     * Le justificatif est la pièce maîtresse d'une demande : le bureau
     * décide sur sa base. Facultatif techniquement — un ticket peut avoir
     * été perdu — mais fortement attendu.
     */
    ?>
    <label for="justificatif">Justificatif (ticket, facture)</label>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= justificatif_taille_max() ?>">
    <input type="file" id="justificatif" name="justificatif"
           accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">
    <p class="note">
      PDF ou photo, <?= htmlspecialchars(justificatif_taille_max_lisible()) ?> maximum.
      Les photos trop grandes sont réduites automatiquement avant l'envoi.
      Sans justificatif, le bureau peut refuser la demande.
    </p>
    <p class="note" id="etat-justificatif" role="status"></p>

    <?php if ($estModification && !empty($demande['Receipt'])) : ?>
      <p class="note">
        Justificatif actuel :
        <a href="<?= BASE_URL ?>/index.php?page=remboursement-justificatif&amp;id=<?= (int) $demande['ReimbursementID'] ?>"
           target="_blank" rel="noopener">consulter</a>
        — en envoyer un nouveau le remplacera.
      </p>
    <?php endif; ?>

    <button type="submit" class="btn">
      <?= $estModification ? 'Enregistrer les modifications' : 'Déposer la demande' ?>
    </button>

    <?php if (!$estModification) : ?>
      <p class="note">
        La demande sera examinée par le bureau. Une fois acceptée puis payée,
        la dépense sera enregistrée automatiquement dans la comptabilité du club.
      </p>
    <?php endif; ?>
  </form>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
