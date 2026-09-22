<?php
/**
 * ============================================================================
 *  VUE — exercices budgétaires
 * ============================================================================
 *  Reçoit du FiscalYearController :
 *    $exercices — tous les exercices, du plus récent au plus ancien
 *    $actif     — l'exercice actif, ou null
 *    $flash     — message de l'action précédente, ou null
 *    $erreur    — message de validation du formulaire, ou ''
 *    $saisie    — valeurs saisies à réafficher après un refus
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';
?>

<?php if ($flash !== null) : ?>
  <p class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['texte']) ?>
  </p>
<?php endif; ?>

<?php if ($actif === null) : ?>
  <p class="flash flash-erreur">
    Aucun exercice n'est actif. La saisie des transactions et des budgets
    ne peut pas fonctionner tant qu'un exercice n'est pas ouvert.
  </p>
<?php endif; ?>

<p class="note">
  Un exercice court de septembre à août, au rythme de l'année universitaire.
  Un seul peut être actif à la fois : c'est celui sur lequel porteront les
  saisies et le tableau de bord.
</p>

<p class="note">
  <strong>Enveloppe clubs</strong> : la somme que l'ISEN verse au BDE
  <em>pour les clubs</em>, et que le BDE répartit ensuite entre eux. Elle
  est distincte de l'enveloppe propre du BDE, qui se saisit comme un budget
  ordinaire dans <a href="<?= BASE_URL ?>/index.php?page=budgets">Budgets</a>.
  C'est elle qui permet d'afficher ce qu'il reste à répartir.
</p>

<div class="tableau-conteneur">
  <table class="tableau">
    <thead>
      <tr>
        <th>Exercice</th>
        <th>Début</th>
        <th>Fin</th>
        <th>Enveloppe clubs</th>
        <th>État</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($exercices === []) : ?>
        <tr><td colspan="6">Aucun exercice enregistré.</td></tr>
      <?php endif; ?>

      <?php foreach ($exercices as $e) : ?>
        <tr>
          <td><?= htmlspecialchars($e['Year']) ?></td>
          <td><?= htmlspecialchars(date('d/m/Y', strtotime($e['Start_Date']))) ?></td>
          <td><?= htmlspecialchars(date('d/m/Y', strtotime($e['End_Date']))) ?></td>
          <td>
            <?php
            /*
             * Modifiable même sur un exercice clos : le montant définitif
             * arrive parfois après la clôture, et on veut pouvoir corriger
             * l'historique plutôt que de le laisser faux.
             */
            ?>
            <form method="post" action="<?= BASE_URL ?>/index.php?page=exercice-enveloppe" class="form-tranche">
              <?= champ_csrf() ?>
              <input type="hidden" name="id" value="<?= (int) $e['FiscalYearID'] ?>">
              <?php // Libellé invisible mais lu par les lecteurs d'écran :
                    // sans lui, ce champ n'est qu'une case sans nom. ?>
              <label class="sr-only" for="env-<?= (int) $e['FiscalYearID'] ?>">
                Enveloppe clubs de l'exercice <?= htmlspecialchars($e['Year']) ?>
              </label>
              <input type="text" inputmode="decimal" class="mini" size="9"
                     id="env-<?= (int) $e['FiscalYearID'] ?>" name="enveloppe"
                     value="<?= htmlspecialchars(number_format((float) $e['Enveloppe_Clubs'], 2, ',', '')) ?>">
              <span class="note">€</span>
              <button type="submit" class="btn btn-petit">Enregistrer</button>
            </form>
          </td>
          <td>
            <?= (int) $e['IsActive'] === 1
                ? '<strong>Actif</strong>'
                : '<span class="note">Clos</span>' ?>
          </td>
          <td>
            <?php if ((int) $e['IsActive'] !== 1) : ?>
              <?php
              /*
               * ⚠ UN FORMULAIRE, PAS UN LIEN.
               *
               * Il serait plus court d'écrire <a href="...?page=activer&id=3">,
               * mais une adresse consultée en GET ne doit JAMAIS modifier de
               * données : les navigateurs préchargent les liens, un moteur
               * d'indexation les suit, et un simple retour en arrière
               * rejouerait l'action. Un GET se lit, un POST agit.
               *
               * Le formulaire permet en outre de joindre le jeton CSRF, ce
               * qu'un lien ne pourrait pas faire.
               */
              ?>
              <form method="post" action="<?= BASE_URL ?>/index.php?page=exercice-activer" class="form-ligne">
                <?= champ_csrf() ?>
                <input type="hidden" name="id" value="<?= (int) $e['FiscalYearID'] ?>">
                <button type="submit" class="btn btn-petit">Rendre actif</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<section class="bloc">
  <h2>Ouvrir un nouvel exercice</h2>

  <?php if ($erreur !== '') : ?>
    <p class="flash flash-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/index.php?page=exercice-creer">
    <?= champ_csrf() ?>

    <label for="libelle">Libellé</label>
    <input type="text" id="libelle" name="libelle" maxlength="10" required
           placeholder="2027-2028"
           value="<?= htmlspecialchars($saisie['libelle']) ?>">

    <label for="debut">Date de début</label>
    <input type="date" id="debut" name="debut" required
           value="<?= htmlspecialchars($saisie['debut']) ?>">

    <label for="fin">Date de fin</label>
    <input type="date" id="fin" name="fin" required
           value="<?= htmlspecialchars($saisie['fin']) ?>">

    <?php
    /*
     * Volontairement FACULTATIF : l'ISEN annonce rarement le montant à la
     * rentrée. Le rendre obligatoire empêcherait d'ouvrir l'exercice tant
     * que l'école n'a pas répondu — alors que la saisie doit pouvoir
     * commencer avant. Le chiffre se complète ensuite dans le tableau.
     */
    ?>
    <label for="enveloppe">Enveloppe destinée aux clubs (facultatif)</label>
    <input type="text" id="enveloppe" name="enveloppe" inputmode="decimal"
           placeholder="12000"
           value="<?= htmlspecialchars($saisie['enveloppe']) ?>">

    <button type="submit" class="btn">Créer l'exercice</button>
  </form>

  <p class="note">
    Le nouvel exercice est créé <strong>inactif</strong> : ouvrir l'année
    suivante et basculer dessus sont deux décisions distinctes.
  </p>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
