<?php
/**
 * ============================================================================
 *  VUE — tableau de bord
 * ============================================================================
 *  Reçoit du DashboardController :
 *    $kpis, $kpisAnnee, $recus, $solde, $parMois, $evolution,
 *    $remboursements, $parCategorie, $parPole, $dernieres,
 *    $alertes, $enAttente, $filtre, $moisDisponibles, $aucunExercice
 *
 *  LES GRAPHIQUES SONT EN SVG, généré par PHP — voir partials/graphiques.php.
 *  Aucune bibliothèque, aucun CDN : le réseau de l'école peut bloquer un
 *  domaine externe, et l'application doit fonctionner hors ligne.
 *
 *  Les couleurs ne sont PAS passées ici : on transmet des noms de classes
 *  CSS (g-depense, g-recette…), et la palette vit dans style.css. Semée
 *  dans les vues, elle serait à corriger en dix endroits au moindre
 *  changement de charte.
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../partials/graphiques.php';

$euros = static fn (float|string|null $m): string => number_format((float) $m, 2, ',', ' ') . ' €';

?>

<?php if ($aucunExercice) : ?>

  <p class="flash flash-erreur">
    Aucun exercice n'existe encore. Créez-en un dans
    <a href="<?= BASE_URL ?>/index.php?page=exercices">Exercices</a> pour commencer.
  </p>

<?php else : ?>

  <?php // ---------------------------------------------------- Filtres --- ?>
  <?php
  /*
   * ⚠ UN FORMULAIRE EN GET, ET NON EN POST.
   *
   * Filtrer ne modifie rien : c'est une CONSULTATION. En GET, le filtre
   * se retrouve dans l'adresse, donc la page se partage, se met en favori
   * et se recharge sans rien rejouer. La règle « un GET se lit, un POST
   * agit » vaut dans les deux sens : ici, agir en POST serait tout aussi
   * fautif que lire en POST ailleurs.
   *
   * Corollaire : pas de jeton CSRF. Il protège les actions, et il n'y a
   * rien à protéger quand la requête ne fait qu'afficher.
   */
  ?>
  <form method="get" action="<?= BASE_URL ?>/index.php" class="barre-filtres">
    <input type="hidden" name="page" value="dashboard">

    <?php if (est_bureau()) : ?>
      <?php
      /*
       * Le choix BDE / clubs n'existe que pour le bureau : un responsable
       * ne voit que son club, la question ne se pose pas pour lui.
       * Le BDE est reconnu par clubs.IsBDE, pas par son identifiant.
       */
      ?>
      <?php
      /*
       * onchange = le filtre s'applique au clic, sans bouton à presser.
       * C'est déjà le comportement du sélecteur d'exercice dans le menu :
       * deux listes voisines qui ne réagissent pas pareil, l'une au clic
       * et l'autre seulement après validation, désorientent.
       * Le bouton « Afficher » reste là pour les navigateurs sans
       * JavaScript (<noscript> plus bas).
       */
      ?>
      <span class="filtre-groupe">
        <?php foreach (['tout' => 'Tout', 'bde' => 'BDE'] as $valeur => $libelle) : ?>
          <label class="filtre-choix <?= $filtre->portee === $valeur ? 'filtre-actif' : '' ?>">
            <input type="radio" name="portee" value="<?= $valeur ?>"
                   onchange="this.form.submit()"
                   <?= $filtre->portee === $valeur ? 'checked' : '' ?>>
            <?= $libelle ?>
          </label>
        <?php endforeach; ?>
      </span>

      <?php
      /*
       * « Clubs » n'est pas un bouton mais un MENU DÉROULANT À COCHER, pour
       * pouvoir observer un ou plusieurs clubs précis.
       *
       * <details>/<summary> est la balise HTML native du « panneau qui
       * s'ouvre ». Aucun JavaScript, aucune bibliothèque : le navigateur
       * gère l'ouverture, le clavier et l'annonce aux lecteurs d'écran.
       * Reconstruire cela à la main avec des div et du script donnerait le
       * même aspect et perdrait tout le reste.
       *
       * ⚠ open dès qu'une sélection est active : chaque case cochée
       * recharge la page, et un panneau qui se refermerait à chaque clic
       * rendrait impossible d'en cocher deux.
       */
      $nbCoches = count($filtre->clubIds);

      $nomsCoches = [];
      foreach ($clubsFiltrables as $cf) {
          if (in_array((int) $cf['ClubID'], $filtre->clubIds, true)) {
              $nomsCoches[] = (string) $cf['Name'];
          }
      }

      $resumeClubs = match (true) {
          $nbCoches === 1 => $nomsCoches[0] ?? '1 club',
          $nbCoches > 1   => $nbCoches . ' clubs',
          $filtre->portee === 'clubs' => 'Tous les clubs',
          default         => 'Clubs',
      };

      // Lien de remise à zéro : il conserve la période, sinon on perdrait
      // le mois consulté en vidant simplement la sélection de clubs.
      $urlTousClubs = BASE_URL . '/index.php?' . http_build_query(array_filter([
          'page'   => 'dashboard',
          'portee' => 'clubs',
          'mois'   => $filtre->mois,
      ]));
      ?>
      <details class="filtre-clubs" <?= $filtre->portee === 'clubs' ? 'open' : '' ?>>
        <summary class="filtre-choix <?= $filtre->portee === 'clubs' ? 'filtre-actif' : '' ?>">
          <?= htmlspecialchars($resumeClubs) ?>
        </summary>

        <div class="filtre-clubs-panneau">
          <label class="case-inline">
            <input type="radio" name="portee" value="clubs"
                   onchange="this.form.submit()"
                   <?= $filtre->portee === 'clubs' ? 'checked' : '' ?>>
            Tous les clubs
          </label>

          <hr>

          <?php if ($clubsFiltrables === []) : ?>
            <p class="note">Aucun club actif.</p>
          <?php endif; ?>

          <?php foreach ($clubsFiltrables as $cf) : ?>
            <label class="case-inline">
              <input type="checkbox" name="clubs[]" value="<?= (int) $cf['ClubID'] ?>"
                     onchange="this.form.submit()"
                     <?= in_array((int) $cf['ClubID'], $filtre->clubIds, true) ? 'checked' : '' ?>>
              <?= htmlspecialchars($cf['Name']) ?>
            </label>
          <?php endforeach; ?>

          <?php if ($nbCoches > 0) : ?>
            <?php
            /*
             * Décocher à la main obligerait à recharger la page une fois
             * par club. Un lien remet tout à zéro d'un seul coup.
             */
            ?>
            <hr>
            <a class="note" href="<?= $urlTousClubs ?>">Tout décocher</a>
          <?php endif; ?>
        </div>
      </details>
    <?php endif; ?>

    <label for="mois">Période</label>
    <select id="mois" name="mois" onchange="this.form.submit()">
      <option value="">Exercice entier</option>
      <?php foreach ($moisDisponibles as $cle => $libelle) : ?>
        <option value="<?= htmlspecialchars($cle) ?>" <?= $filtre->mois === $cle ? 'selected' : '' ?>>
          <?= htmlspecialchars($libelle) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <noscript><button type="submit" class="btn btn-petit">Afficher</button></noscript>

    <?php if ($filtre->filtrePeriode() || $filtre->portee !== 'tout') : ?>
      <a class="note" href="<?= BASE_URL ?>/index.php?page=dashboard">Tout réafficher</a>
    <?php endif; ?>
  </form>

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
  <?php $surLaPeriode = $filtre->filtrePeriode()
      ? htmlspecialchars($moisDisponibles[$filtre->mois] ?? '')
      : ''; ?>

  <div class="kpis">
    <div class="kpi">
      <div class="kpi-valeur recette"><?= $euros($kpis['recettes']) ?></div>
      <div class="kpi-libelle">
        Recettes<?= $surLaPeriode !== '' ? '<br><span class="note">' . $surLaPeriode . '</span>' : '' ?>
      </div>
    </div>
    <div class="kpi">
      <div class="kpi-valeur depense"><?= $euros($kpis['depenses']) ?></div>
      <div class="kpi-libelle">
        Dépenses<?= $surLaPeriode !== '' ? '<br><span class="note">' . $surLaPeriode . '</span>' : '' ?>
      </div>
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
    <?php if ($filtre->filtrePeriode()) : ?>
      <?php
      /*
       * Précision indispensable dès qu'un mois est sélectionné : sans
       * elle, on lirait les quatre chiffres comme portant tous sur le
       * mois, et on conclurait que le compte a fondu.
       */
      ?>
      <br><strong>Les recettes et dépenses ci-dessus portent sur
      <?= $surLaPeriode ?></strong> ; les versements reçus et le solde
      restent ceux de l'exercice entier, parce qu'un solde se cumule
      depuis septembre et ne se découpe pas en mois.
    <?php endif; ?>
    <?php if ((float) $kpis['en_attente'] > 0) : ?>
      <br><?= $euros($kpis['en_attente']) ?> d'écritures en attente ne sont pas comptées.
    <?php endif; ?>
  </p>

  <?php // --------------------------------------------- Mois par mois --- ?>
  <section class="bloc bloc-large">
    <h2>Recettes et dépenses, mois par mois</h2>
    <p class="note">
      Les mouvements de chaque mois, pris isolément. Deux barres côte à
      côte plutôt qu'empilées : la question est de savoir laquelle dépasse
      l'autre, c'est-à-dire si le mois a coûté ou rapporté.
    </p>

    <?php
    $maxMois = 0.0;
    foreach ($parMois as $m) {
        $maxMois = max($maxMois, $m['recettes'], $m['depenses']);
    }
    ?>

    <?php if ($maxMois <= 0) : ?>
      <p class="vide">Aucune transaction validée sur cet exercice.</p>
    <?php else : ?>
      <?= g_barres(
          [
              ['nom' => 'Recettes', 'classe' => 'g-recette',
               'valeurs' => array_column($parMois, 'recettes')],
              ['nom' => 'Dépenses', 'classe' => 'g-depense',
               'valeurs' => array_column($parMois, 'depenses')],
          ],
          array_column($parMois, 'libelle'),
          'Recettes et dépenses mois par mois'
      ) ?>
      <p class="note">
        <span class="puce puce-recette"></span> recettes
        &nbsp;<span class="puce puce-depense"></span> dépenses
        &nbsp;— survolez une barre pour le montant exact.
      </p>
    <?php endif; ?>
  </section>

  <?php // ------------------------------------- Évolution du compte --- ?>
  <section class="bloc bloc-large">
    <h2>Évolution du compte</h2>
    <p class="note">
      Ce qu'il restait sur le compte à la fin de chaque mois. Contrairement
      au graphique précédent, chaque point <strong>reporte les mois
      précédents</strong> : c'est la tendance qu'on lit ici, pas les
      mouvements du mois.
    </p>

    <?php
    /*
     * ⚠ CE GRAPHIQUE NE SUIT PAS LE FILTRE DE PÉRIODE — cf. Statistiques.
     * Réduit à un mois, il n'afficherait qu'un point, sans rien à quoi le
     * comparer : on perdrait précisément ce qu'on lui demande.
     */
    $aDesMouvements = false;
    foreach ($evolution as $e) {
        if (abs($e['solde']) > 0.001) {
            $aDesMouvements = true;
            break;
        }
    }
    ?>

    <?php if (!$aDesMouvements) : ?>
      <p class="vide">Aucun mouvement sur cet exercice.</p>
    <?php else : ?>
      <?= g_courbe(
          array_map(
              static fn (array $e): array => ['libelle' => $e['libelle'], 'valeur' => $e['solde']],
              $evolution
          ),
          array_map(
              static fn (array $e): ?array => $e['projection'] === null
                  ? null
                  : ['libelle' => $e['libelle'], 'valeur' => $e['projection']],
              $evolution
          ),
          'Évolution du solde mois par mois',
          $comparaison
      ) ?>
      <p class="note">
        <span class="puce puce-solde"></span> solde constaté
        &nbsp;<span class="puce puce-projection"></span> projection avec les
        versements encore attendus.
        <?php if ($exercicePrecedent !== null) : ?>
          &nbsp;<span class="puce puce-comparaison"></span>
          <?= htmlspecialchars($exercicePrecedent['Year']) ?>, au même stade
          de l'année.
        <?php endif; ?>
        <br>La projection <strong>n'anticipe aucune dépense future</strong> :
        c'est une borne haute, à lire comme « au mieux, voilà ce que j'aurai ».
        Le dernier point constaté retombe sur le « Solde disponible » affiché
        plus haut.
        <?php if ($filtre->filtrePeriode()) : ?>
          <br>Ce graphique porte toujours sur l'exercice entier, même quand
          un mois est sélectionné.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </section>

  <?php // --------------------------------- Remboursements par état --- ?>
  <section class="bloc bloc-large">
    <h2>Remboursements par état</h2>
    <p class="note">
      Montants avancés par des membres, classés au mois de l'achat. Barres
      <strong>empilées</strong> ici, et non côte à côte : ce qui compte est
      le total avancé sur le mois, et la part qui reste à rembourser.
      Les demandes refusées sont exclues — elles ne représentent aucune
      somme due.
    </p>

    <?php
    $maxRemb = 0.0;
    foreach ($remboursements as $r) {
        $maxRemb = max($maxRemb, $r['en_attente'] + $r['valide'] + $r['rembourse']);
    }
    ?>

    <?php if ($maxRemb <= 0) : ?>
      <p class="vide">Aucune demande de remboursement sur cet exercice.</p>
    <?php else : ?>
      <?php
      /*
       * ORDRE VOULU, DU BAS VERS LE HAUT : réglé, puis accepté, puis en
       * attente. Dans une barre empilée, la première série est posée au
       * sol ; on met donc en bas ce qui est classé, et on fait monter ce
       * qui réclame encore une action. Le regard suit la hauteur, et
       * c'est le rouge vif qui dépasse.
       */
      ?>
      <?= g_barres(
          [
              ['nom' => 'Remboursé', 'classe' => 'g-rembourse',
               'valeurs' => array_column($remboursements, 'rembourse')],
              ['nom' => 'Accepté, non payé', 'classe' => 'g-valide',
               'valeurs' => array_column($remboursements, 'valide')],
              ['nom' => 'En attente', 'classe' => 'g-attente',
               'valeurs' => array_column($remboursements, 'en_attente')],
          ],
          array_column($remboursements, 'libelle'),
          'Remboursements par état, mois par mois',
          true
      ) ?>
      <p class="note">
        <span class="puce puce-rembourse"></span> remboursés
        &nbsp;<span class="puce puce-valide"></span> acceptés, pas encore payés
        &nbsp;<span class="puce puce-attente"></span> en attente
      </p>
    <?php endif; ?>
  </section>

  <?php // -------------------------------------------- Où part l'argent --- ?>
  <h2 class="titre-section">Où part l'argent</h2>

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
        <p class="vide">Aucune dépense<?= $surLaPeriode !== '' ? ' en ' . $surLaPeriode : '' ?>.</p>
      <?php else : ?>
        <?= g_classement($parPole, 'g-depense', 'Dépenses par pôle') ?>
      <?php endif; ?>
    </section>

    <section class="bloc">
      <h2>Dépenses par catégorie</h2>
      <?php if ($parCategorie === []) : ?>
        <p class="vide">Aucune dépense<?= $surLaPeriode !== '' ? ' en ' . $surLaPeriode : '' ?>.</p>
      <?php else : ?>
        <?= g_classement($parCategorie, 'g-depense', 'Dépenses par catégorie') ?>
      <?php endif; ?>
    </section>

    <?php
    /*
     * PAS DE « DÉPENSES PAR CLUB » ICI — retiré le 22/09/2026.
     *
     * Le classement des clubs par montant dépensé ne répond à aucune
     * question utile : un club qui dépense beaucoup n'est pas un problème
     * s'il a le budget correspondant, et un club qui dépense peu n'est pas
     * vertueux, il est peut-être simplement à l'arrêt.
     *
     * Ce qui compte pour chaque club est déjà dit ailleurs, et mieux :
     * le bloc « À surveiller » en haut de page remonte ceux dont le SOLDE
     * est en danger, et la page Budgets donne le détail alloué / versé /
     * dépensé / solde. Un graphique de plus aurait surtout multiplié les
     * endroits où la même règle peut finir par diverger.
     */
    ?>
  </div>

  <?php // ------------------------------------------ D'où vient l'argent --- ?>
  <h2 class="titre-section">D'où vient l'argent</h2>

  <?php
  /*
   * ⚠ LE PENDANT INDISPENSABLE DU BLOC PRÉCÉDENT.
   *
   * Pendant longtemps le tableau de bord n'a ventilé que les dépenses : on
   * voyait parfaitement où partait l'argent, et nulle part d'où il venait.
   * Or une soirée qui coûte 8 900 € et en rapporte 2 300 ne se juge pas sur
   * la seule colonne des dépenses — et c'est bien la question que se pose
   * un trésorier de BDE.
   *
   * Les versements de l'ISEN ne figurent PAS ici : ce ne sont pas des
   * recettes mais des dotations, comptées à part dans « Versements reçus ».
   * Les mêler aux ventes et cotisations écraserait ces dernières, et
   * laisserait croire que l'association gagne de l'argent par elle-même
   * alors qu'elle n'en aurait reçu.
   */
  ?>
  <div class="colonnes">

    <section class="bloc">
      <h2>Recettes par catégorie</h2>
      <?php if ($recettesParCategorie === []) : ?>
        <p class="vide">Aucune recette<?= $surLaPeriode !== '' ? ' en ' . $surLaPeriode : '' ?>.</p>
      <?php else : ?>
        <?= g_classement($recettesParCategorie, 'g-recette', 'Recettes par catégorie') ?>
      <?php endif; ?>
    </section>

    <section class="bloc">
      <h2>Recettes par pôle</h2>
      <?php if ($recettesParPole === []) : ?>
        <p class="vide">Aucune recette<?= $surLaPeriode !== '' ? ' en ' . $surLaPeriode : '' ?>.</p>
      <?php else : ?>
        <?= g_classement($recettesParPole, 'g-recette', 'Recettes par pôle') ?>
      <?php endif; ?>
    </section>

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
