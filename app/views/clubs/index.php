<?php
/**
 * ============================================================================
 *  VUE — liste des clubs
 * ============================================================================
 *  Reçoit du ClubController :
 *    $clubs — tableau des clubs actifs (ClubID, Name, Description)
 *    $titre — titre de la page
 *
 *  Une vue ne fait JAMAIS de requête SQL et ne contient pas de calcul métier :
 *  elle se contente d'afficher ce qu'on lui a donné.
 *
 *  ÉTAT : version minimale de la Phase 2, destinée à prouver que les données
 *  arrivent bien jusqu'à l'écran. La reprise fidèle de Maquettes/Clubs.html
 *  (cartes, budgets, barre de recherche) est le travail de la Phase 3 (3.4).
 *  Le budget et le solde de chaque club sont volontairement absents : ils
 *  dépendent des versements et des transactions (tâche 2.6).
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';
?>

<?php
/*
 * Bouton réservé au bureau. Comme partout : ce masquage évite un lien qui
 * mènerait à un refus, il ne protège pas — c'est exiger_bureau(), dans
 * ClubController::create(), qui fait barrage.
 */
?>
<?php if (est_bureau()) : ?>
  <p class="barre-actions">
    <a class="btn" href="<?= BASE_URL ?>/index.php?page=club-nouveau">+ Nouveau club</a>
  </p>
<?php endif; ?>

<?php if ($clubs === []) : ?>

    <?php
    /*
     * Cas "aucun club" traité explicitement. Sans ce message, une page
     * vide serait indiscernable d'une panne : on ne saurait pas si la
     * base est vide ou si la connexion a échoué.
     */
    ?>
    <p class="vide">Aucun club actif enregistré.</p>

<?php else : ?>

    <p class="compteur"><?= count($clubs) ?> club(s) actif(s)</p>

    <ul class="liste-clubs">
        <?php foreach ($clubs as $club) : ?>
            <li class="club">
                <?php
                /*
                 * ⚠ RÈGLE SANS EXCEPTION : htmlspecialchars() sur TOUTE
                 * valeur venant de la base avant de l'afficher.
                 *
                 * Sans elle, un club nommé  <script>alert(1)</script>
                 * ne s'afficherait pas comme du texte : le navigateur
                 * l'exécuterait comme du code. C'est la faille XSS.
                 * Ici les données viennent du seed et sont inoffensives,
                 * mais demain elles viendront d'un formulaire rempli par
                 * un utilisateur.
                 *
                 * On applique la règle dès la première vue : repasser
                 * corriger vingt vues plus tard, personne ne le fait.
                 */
                ?>
                <h2 class="club-nom"><?= htmlspecialchars($club['Name']) ?></h2>

                <?php if ($club['Description'] !== null && $club['Description'] !== '') : ?>
                    <p class="club-description"><?= htmlspecialchars($club['Description']) ?></p>
                <?php endif; ?>

                <?php
                /*
                 * Le lien vers la fiche n'apparaît que pour les clubs que
                 * l'on a le droit de consulter : le bureau les voit tous,
                 * un responsable seulement le sien.
                 *
                 * Ce n'est QUE de l'affichage — éviter un lien qui mènerait
                 * à un refus. La protection réelle est dans
                 * ClubController::show(), qui revérifie côté serveur.
                 */
                ?>
                <?php if (peut_voir_club((int) $club['ClubID'])) : ?>
                    <p>
                        <a href="<?= BASE_URL ?>/index.php?page=club&amp;id=<?= (int) $club['ClubID'] ?>">
                            Voir le détail
                        </a>
                    </p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
