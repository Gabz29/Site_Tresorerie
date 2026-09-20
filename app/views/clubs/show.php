<?php
/**
 * ============================================================================
 *  VUE — fiche d'un club
 * ============================================================================
 *  Reçoit du ClubController :
 *    $club         — la ligne du club
 *    $transactions — ses dépenses et recettes (50 plus récentes)
 *    $totaux       — ['depenses' => ..., 'recettes' => ...]
 *    $titre        — le nom du club
 *
 *  Le BUDGET du club n'est volontairement pas affiché : il dépend des
 *  versements reçus, qui relèvent de la tâche 2.6. Ce qui figure ici ne
 *  vient que des transactions réellement enregistrées.
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';
?>

<p><a href="<?= BASE_URL ?>/index.php?page=clubs">&larr; Retour à la liste des clubs</a></p>

<?php if ($club['Description'] !== null && $club['Description'] !== '') : ?>
  <p class="club-description"><?= htmlspecialchars($club['Description']) ?></p>
<?php endif; ?>

<?php if ((int) $club['IsActive'] !== 1) : ?>
  <p class="flash flash-erreur">Ce club est archivé. Ses données restent consultables pour l'historique.</p>
<?php endif; ?>

<section class="bloc">
  <h2>Mouvements enregistrés</h2>

  <?php
  /*
   * number_format() met en forme à la française : 1 234,50 €
   * Les montants viennent de MySQL en DECIMAL, donc sous forme de chaîne
   * ("245.50"). (float) les convertit pour l'affichage uniquement — jamais
   * pour un calcul, qui reste fait par la base.
   */
  ?>
  <dl class="infos">
    <dt>Total des recettes</dt>
    <dd><?= number_format((float) $totaux['recettes'], 2, ',', ' ') ?> €</dd>

    <dt>Total des dépenses</dt>
    <dd><?= number_format((float) $totaux['depenses'], 2, ',', ' ') ?> €</dd>
  </dl>
  <p class="note">Transactions validées uniquement.</p>
</section>

<h2 class="titre-section">Dernières transactions</h2>

<?php if ($transactions === []) : ?>

  <p class="vide">Aucune transaction enregistrée pour ce club.</p>

<?php else : ?>

  <?php
  /*
   * Le tableau est enveloppé dans un conteneur qui défile horizontalement :
   * sur un écran étroit, c'est le tableau qui glisse, pas la page entière.
   */
  ?>
  <div class="tableau-conteneur">
    <table class="tableau">
      <thead>
        <tr>
          <th>Date</th>
          <th>Libellé</th>
          <th>Catégorie</th>
          <th class="col-montant">Montant</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $t) : ?>
          <tr>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($t['Date']))) ?></td>
            <td><?= htmlspecialchars($t['Description']) ?></td>
            <td>
              <?php
              // CategoryID est nullable : la jointure peut donc ne rien
              // ramener. On affiche un tiret plutôt qu'une case vide.
              echo $t['CategoryName'] !== null
                  ? htmlspecialchars($t['CategoryName'])
                  : '—';
              ?>
            </td>
            <td class="col-montant <?= $t['Type'] === 'recette' ? 'recette' : 'depense' ?>">
              <?= $t['Type'] === 'recette' ? '+' : '−' ?>
              <?= number_format((float) $t['Amount'], 2, ',', ' ') ?> €
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
