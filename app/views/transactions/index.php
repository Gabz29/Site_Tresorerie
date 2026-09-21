<?php
/**
 * ============================================================================
 *  VUE — liste filtrable des transactions
 * ============================================================================
 *  Reçoit du TransactionController :
 *    $transactions, $total, $totaux, $nbPages, $pageCourante
 *    $filtres, $clubs, $categories, $flash
 *    $aucunExercice — true si la base ne contient aucun exercice
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$euros = static fn (float|string|null $m): string => number_format((float) $m, 2, ',', ' ') . ' €';

$libelleStatut = static fn (string $s): string => match ($s) {
    'valide'     => 'Validée',
    'en_attente' => 'En attente',
    'annule'     => 'Annulée',
    default      => $s,
};
?>

<?php if ($aucunExercice) : ?>

  <p class="flash flash-erreur">
    Aucun exercice n'existe encore. Les transactions sont rattachées à un
    exercice : il faut en créer un avant de pouvoir saisir.
  </p>

<?php else : ?>

  <?php if ($flash !== null) : ?>
    <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['texte']) ?>
    </p>
  <?php endif; ?>

  <?php if (exercice_consulte_est_actif()) : ?>
    <p class="barre-actions">
      <a class="btn" href="<?= BASE_URL ?>/index.php?page=transaction-nouvelle">
        + Nouvelle transaction
      </a>
    </p>
  <?php endif; ?>

  <?php
  /*
   * FORMULAIRE DE FILTRES — en GET, contrairement à tous les autres
   * formulaires du projet.
   *
   * C'est le bon usage ici : un filtre ne modifie rien, il sélectionne ce
   * qu'on affiche. Le passer en GET met les critères dans l'URL, donc
   * l'adresse devient partageable et le bouton « précédent » fonctionne.
   * Et comme rien n'est modifié, aucun jeton CSRF n'est nécessaire.
   */
  ?>
  <form method="get" action="<?= BASE_URL ?>/index.php" class="filtres">
    <input type="hidden" name="page" value="transactions">

    <?php
    /*
     * Chaque libellé est groupé avec son champ : sans cette enveloppe, le
     * conteneur flex traiterait le <label> et le <select> comme deux
     * éléments indépendants, et ils se sépareraient au repli des lignes.
     */
    ?>
    <?php if (est_bureau()) : ?>
      <div class="filtre-champ">
        <label for="f-club">Club</label>
        <select id="f-club" name="club">
          <option value="">Tous</option>
          <?php foreach ($clubs as $c) : ?>
            <option value="<?= (int) $c['ClubID'] ?>"
              <?= (int) $filtres['club'] === (int) $c['ClubID'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['Name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="filtre-champ">
      <label for="f-type">Type</label>
      <select id="f-type" name="type">
        <option value="">Tous</option>
        <option value="depense" <?= $filtres['type'] === 'depense' ? 'selected' : '' ?>>Dépenses</option>
        <option value="recette" <?= $filtres['type'] === 'recette' ? 'selected' : '' ?>>Recettes</option>
      </select>
    </div>

    <div class="filtre-champ">
      <label for="f-statut">Statut</label>
      <select id="f-statut" name="statut">
        <option value="">Tous</option>
        <?php foreach (Transaction::STATUTS as $s) : ?>
          <option value="<?= $s ?>" <?= $filtres['statut'] === $s ? 'selected' : '' ?>>
            <?= htmlspecialchars($libelleStatut($s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filtre-champ">
      <label for="f-cat">Catégorie</label>
      <select id="f-cat" name="categorie">
        <option value="">Toutes</option>
        <?php foreach ($categories as $c) : ?>
          <option value="<?= (int) $c['CategoryID'] ?>"
            <?= (int) $filtres['categorie'] === (int) $c['CategoryID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['Name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filtre-champ">
      <label for="f-du">Du</label>
      <input type="date" id="f-du" name="du" value="<?= htmlspecialchars($filtres['du']) ?>">
    </div>

    <div class="filtre-champ">
      <label for="f-au">Au</label>
      <input type="date" id="f-au" name="au" value="<?= htmlspecialchars($filtres['au']) ?>">
    </div>

    <div class="filtre-champ">
      <label for="f-q">Recherche</label>
      <input type="search" id="f-q" name="q" placeholder="libellé ou notes"
             value="<?= htmlspecialchars($filtres['q']) ?>">
    </div>

    <div class="filtre-champ">
      <button type="submit" class="btn">Filtrer</button>
      <a class="btn" href="<?= BASE_URL ?>/index.php?page=transactions">Réinitialiser</a>
    </div>
  </form>

  <p class="resume-filtres">
    <strong><?= (int) $total ?></strong> transaction(s) —
    recettes <span class="recette"><?= $euros($totaux['recettes']) ?></span>,
    dépenses <span class="depense"><?= $euros($totaux['depenses']) ?></span>
    <span class="note">(validées uniquement, sur l'ensemble du résultat)</span>
  </p>

  <?php if ($transactions === []) : ?>

    <p class="vide">Aucune transaction ne correspond à ces critères.</p>

  <?php else : ?>

    <div class="tableau-conteneur">
      <table class="tableau">
        <thead>
          <tr>
            <th>Date</th>
            <th>Club</th>
            <th>Libellé</th>
            <th>Catégorie</th>
            <th>Statut</th>
            <th class="col-montant">Montant</th>
            <?php if (exercice_consulte_est_actif()) : ?>
              <th></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $t) : ?>
            <tr class="<?= $t['Status'] === 'annule' ? 'ligne-annulee' : '' ?>">
              <td><?= htmlspecialchars(date('d/m/Y', strtotime($t['Date']))) ?></td>
              <td><?= htmlspecialchars($t['ClubName']) ?></td>
              <td><?= htmlspecialchars($t['Description']) ?></td>
              <td><?= $t['CategoryName'] !== null ? htmlspecialchars($t['CategoryName']) : '—' ?></td>
              <td>
                <?= htmlspecialchars($libelleStatut($t['Status'])) ?>
              </td>
              <td class="col-montant <?= $t['Type'] === 'recette' ? 'recette' : 'depense' ?>">
                <?= $t['Type'] === 'recette' ? '+' : '−' ?><?= $euros($t['Amount']) ?>
              </td>

              <?php if (exercice_consulte_est_actif()) : ?>
                <td class="col-actions">
                  <a href="<?= BASE_URL ?>/index.php?page=transaction-modifier&amp;id=<?= (int) $t['TransactionID'] ?>">Modifier</a>

                  <?php if ($t['Status'] !== 'annule') : ?>
                    <form method="post" action="<?= BASE_URL ?>/index.php?page=transaction-statut" class="form-ligne">
                      <?= champ_csrf() ?>
                      <input type="hidden" name="id" value="<?= (int) $t['TransactionID'] ?>">
                      <input type="hidden" name="statut" value="annule">
                      <button type="submit" class="btn btn-petit">Annuler</button>
                    </form>
                  <?php else : ?>
                    <form method="post" action="<?= BASE_URL ?>/index.php?page=transaction-statut" class="form-ligne">
                      <?= champ_csrf() ?>
                      <input type="hidden" name="id" value="<?= (int) $t['TransactionID'] ?>">
                      <input type="hidden" name="statut" value="valide">
                      <button type="submit" class="btn btn-petit">Rétablir</button>
                    </form>
                  <?php endif; ?>

                  <?php
                  /*
                   * Suppression réservée au bureau. Un responsable peut
                   * annuler une écriture de son club (elle reste visible,
                   * barrée, hors des totaux) mais pas la faire disparaître.
                   * Le contrôleur revérifie : masquer n'est pas protéger.
                   */
                  ?>
                  <?php if (est_bureau()) : ?>
                    <form method="post" action="<?= BASE_URL ?>/index.php?page=transaction-supprimer"
                          class="form-ligne"
                          onsubmit="return confirm('Supprimer définitivement cette transaction ? Pour conserver la trace, utilisez plutôt Annuler.');">
                      <?= champ_csrf() ?>
                      <input type="hidden" name="id" value="<?= (int) $t['TransactionID'] ?>">
                      <button type="submit" class="btn btn-petit">Supprimer</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($nbPages > 1) : ?>
      <?php
      /*
       * Pagination. Les filtres courants sont recopiés dans chaque lien,
       * sinon changer de page les perdrait tous.
       */
      $lien = static function (int $p) use ($filtres): string {
          $params = array_filter([
              'page'      => 'transactions',
              'club'      => $filtres['club'] ?: null,
              'type'      => $filtres['type'] ?: null,
              'statut'    => $filtres['statut'] ?: null,
              'categorie' => $filtres['categorie'] ?: null,
              'du'        => $filtres['du'] ?: null,
              'au'        => $filtres['au'] ?: null,
              'q'         => $filtres['q'] ?: null,
              'p'         => $p,
          ]);

          return BASE_URL . '/index.php?' . http_build_query($params);
      };
      ?>
      <nav class="pagination">
        <?php if ($pageCourante > 1) : ?>
          <a href="<?= htmlspecialchars($lien($pageCourante - 1)) ?>">&larr; Précédent</a>
        <?php endif; ?>

        <span class="note">Page <?= $pageCourante ?> sur <?= $nbPages ?></span>

        <?php if ($pageCourante < $nbPages) : ?>
          <a href="<?= htmlspecialchars($lien($pageCourante + 1)) ?>">Suivant &rarr;</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
