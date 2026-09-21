<?php
/**
 * ============================================================================
 *  VUE — tableau de bord
 * ============================================================================
 *  Reçoit du DashboardController :
 *    $kpis, $recus, $solde, $parMois, $parCategorie, $parClub,
 *    $dernieres, $alertes, $enAttente, $aucunExercice
 *
 *  LES GRAPHIQUES SONT EN HTML/CSS, sans aucune bibliothèque : une barre
 *  est un simple <div> dont on fixe la largeur en pourcentage. Pour douze
 *  mois et quelques catégories, c'est parfaitement lisible, et cela évite
 *  au projet sa première dépendance externe — le réseau de l'école pourrait
 *  bloquer un CDN, et l'application doit fonctionner hors ligne.
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$euros = static fn (float|string|null $m): string => number_format((float) $m, 2, ',', ' ') . ' €';

/**
 * Largeur d'une barre, en pourcentage du maximum de sa série.
 *
 * Le garde-fou sur $max évite une division par zéro quand toutes les
 * valeurs sont nulles — cas courant en début d'exercice.
 */
$largeur = static fn (float $valeur, float $max): string
    => $max > 0 ? number_format(($valeur / $max) * 100, 2, '.', '') : '0';
?>

<?php if ($aucunExercice) : ?>

  <p class="flash flash-erreur">
    Aucun exercice n'existe encore. Créez-en un dans
    <a href="<?= BASE_URL ?>/index.php?page=exercices">Exercices</a> pour commencer.
  </p>

<?php else : ?>

  <?php // ---------------------------------------------------- Alertes --- ?>
  <?php if ($alertes !== []) : ?>
    <section class="bloc bloc-large">
      <h2>À surveiller</h2>
      <ul class="liste-alertes">
        <?php foreach ($alertes as $a) : ?>
          <li>
            <span class="badge badge-<?= htmlspecialchars($a['Alerte']) ?>">
              <?= $a['Alerte'] === 'rouge' ? 'à découvert' : 'solde faible' ?>
            </span>
            <strong><?= htmlspecialchars($a['ClubName']) ?></strong>
            — solde <?= $euros($a['SoldeDisponible']) ?>
            <span class="note">
              (reçu <?= $euros($a['VersementsRecus']) ?>,
              dépensé <?= $euros($a['Depenses']) ?>)
            </span>
            <a href="<?= BASE_URL ?>/index.php?page=budget&amp;id=<?= (int) $a['BudgetID'] ?>">Détail</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($enAttente > 0) : ?>
    <p class="flash flash-erreur">
      <strong><?= (int) $enAttente ?></strong> demande(s) de remboursement en attente —
      <a href="<?= BASE_URL ?>/index.php?page=remboursements">les examiner</a>
    </p>
  <?php endif; ?>

  <?php // ------------------------------------------------------- KPIs --- ?>
  <div class="kpis">
    <div class="kpi">
      <div class="kpi-valeur recette"><?= $euros($kpis['recettes']) ?></div>
      <div class="kpi-libelle">Recettes</div>
    </div>
    <div class="kpi">
      <div class="kpi-valeur depense"><?= $euros($kpis['depenses']) ?></div>
      <div class="kpi-libelle">Dépenses</div>
    </div>
    <div class="kpi">
      <div class="kpi-valeur"><?= $euros($recus) ?></div>
      <div class="kpi-libelle">Versements reçus</div>
    </div>
    <div class="kpi <?= $solde < 0 ? 'kpi-alerte' : '' ?>">
      <div class="kpi-valeur"><?= $euros($solde) ?></div>
      <div class="kpi-libelle">Solde disponible</div>
    </div>
  </div>

  <p class="note">
    Solde = versements reçus + recettes − dépenses validées.
    <?php if ((float) $kpis['en_attente'] > 0) : ?>
      <br><?= $euros($kpis['en_attente']) ?> d'écritures en attente ne sont pas comptées.
    <?php endif; ?>
  </p>

  <?php // --------------------------------------------- Mois par mois --- ?>
  <section class="bloc bloc-large">
    <h2>Mois par mois</h2>

    <?php
    // L'échelle est commune aux recettes et aux dépenses : sinon deux
    // barres de même longueur représenteraient des montants différents.
    $maxMois = 0.0;
    foreach ($parMois as $m) {
        $maxMois = max($maxMois, $m['recettes'], $m['depenses']);
    }
    ?>

    <?php if ($maxMois <= 0) : ?>
      <p class="vide">Aucune transaction validée sur cet exercice.</p>
    <?php else : ?>
      <div class="graphe">
        <?php foreach ($parMois as $m) : ?>
          <div class="graphe-ligne">
            <div class="graphe-label"><?= htmlspecialchars($m['libelle']) ?></div>
            <div class="graphe-barres">
              <div class="barre barre-recette" style="width: <?= $largeur($m['recettes'], $maxMois) ?>%"></div>
              <div class="barre barre-depense" style="width: <?= $largeur($m['depenses'], $maxMois) ?>%"></div>
            </div>
            <div class="graphe-valeur">
              <span class="recette">+<?= $euros($m['recettes']) ?></span><br>
              <span class="depense">−<?= $euros($m['depenses']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="note">
        <span class="puce puce-recette"></span> recettes
        &nbsp;<span class="puce puce-depense"></span> dépenses
      </p>
    <?php endif; ?>
  </section>

  <?php // ----------------------------------------- Par catégorie/club --- ?>
  <div class="colonnes">

    <?php
    /*
     * Deux graphiques complémentaires, à ne pas confondre :
     *   par PÔLE      = quelle équipe a dépensé
     *   par CATÉGORIE = quelle nature d'achat
     * Le pôle Event apparaît d'un côté avec son total, de l'autre ses
     * achats se répartissent entre Matériel, Nourriture, etc.
     */
    ?>
    <section class="bloc">
      <h2>Dépenses par pôle</h2>
      <?php if ($parPole === []) : ?>
        <p class="vide">Aucune dépense.</p>
      <?php else : ?>
        <?php $maxPole = (float) $parPole[0]['montant']; ?>
        <div class="graphe">
          <?php foreach ($parPole as $p) : ?>
            <div class="graphe-ligne">
              <div class="graphe-label"><?= htmlspecialchars($p['libelle']) ?></div>
              <div class="graphe-barres">
                <div class="barre barre-depense" style="width: <?= $largeur($p['montant'], $maxPole) ?>%"></div>
              </div>
              <div class="graphe-valeur"><?= $euros($p['montant']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="bloc">
      <h2>Dépenses par catégorie</h2>
      <?php if ($parCategorie === []) : ?>
        <p class="vide">Aucune dépense.</p>
      <?php else : ?>
        <?php $maxCat = (float) $parCategorie[0]['montant']; ?>
        <div class="graphe">
          <?php foreach ($parCategorie as $c) : ?>
            <div class="graphe-ligne">
              <div class="graphe-label"><?= htmlspecialchars($c['libelle']) ?></div>
              <div class="graphe-barres">
                <div class="barre barre-depense" style="width: <?= $largeur($c['montant'], $maxCat) ?>%"></div>
              </div>
              <div class="graphe-valeur"><?= $euros($c['montant']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if (est_bureau()) : ?>
      <section class="bloc">
        <h2>Dépenses par club</h2>
        <?php if ($parClub === []) : ?>
          <p class="vide">Aucune dépense.</p>
        <?php else : ?>
          <?php $maxClub = (float) $parClub[0]['montant']; ?>
          <div class="graphe">
            <?php foreach ($parClub as $c) : ?>
              <div class="graphe-ligne">
                <div class="graphe-label"><?= htmlspecialchars($c['libelle']) ?></div>
                <div class="graphe-barres">
                  <div class="barre barre-depense" style="width: <?= $largeur($c['montant'], $maxClub) ?>%"></div>
                </div>
                <div class="graphe-valeur"><?= $euros($c['montant']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

  </div>

  <?php // ------------------------------------ Dernières transactions --- ?>
  <h2 class="titre-section">Dernières transactions</h2>

  <?php if ($dernieres === []) : ?>
    <p class="vide">Aucune transaction enregistrée.</p>
  <?php else : ?>
    <div class="tableau-conteneur">
      <table class="tableau">
        <thead>
          <tr>
            <th>Date</th><th>Club</th><th>Libellé</th><th>Statut</th>
            <th class="col-montant">Montant</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dernieres as $t) : ?>
            <tr class="<?= $t['Status'] === 'annule' ? 'ligne-annulee' : '' ?>">
              <td><?= htmlspecialchars(date('d/m/Y', strtotime($t['Date']))) ?></td>
              <td><?= htmlspecialchars($t['ClubName']) ?></td>
              <td><?= htmlspecialchars($t['Description']) ?></td>
              <td><?= htmlspecialchars(match ($t['Status']) {
                  'valide' => 'Validée', 'en_attente' => 'En attente',
                  'annule' => 'Annulée', default => $t['Status'],
              }) ?></td>
              <td class="col-montant <?= $t['Type'] === 'recette' ? 'recette' : 'depense' ?>">
                <?= $t['Type'] === 'recette' ? '+' : '−' ?><?= $euros($t['Amount']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="note">
      <a href="<?= BASE_URL ?>/index.php?page=transactions">Voir toutes les transactions</a>
    </p>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
