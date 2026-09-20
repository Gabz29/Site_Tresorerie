<?php
/**
 * Page affichée quand un utilisateur connecté demande une page à laquelle son
 * rôle ne lui donne pas droit (cf. refuser_acces() dans app/config/session.php).
 *
 * 403 et non 404 : la personne est identifiée et la page existe — c'est
 * l'autorisation qui manque, pas l'adresse qui est fausse.
 */
declare(strict_types=1);

$titre = 'Accès refusé';

require __DIR__ . '/../layout/header.php';
?>

<p class="flash flash-erreur">
  Vous n'avez pas les droits nécessaires pour consulter cette page.
</p>

<p class="note">
  Si vous pensez qu'il s'agit d'une erreur, contactez le bureau du BDE.
</p>

<?php require __DIR__ . '/../layout/footer.php'; ?>
