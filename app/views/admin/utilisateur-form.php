<?php
/**
 * ============================================================================
 *  VUE — création et modification d'un compte
 * ============================================================================
 *  Reçoit de l'AdminController : $utilisateur (null en création), $erreur, $clubs
 * ============================================================================
 */
declare(strict_types=1);

require __DIR__ . '/../layout/header.php';

$estModification = $utilisateur !== null && (int) ($utilisateur['UserID'] ?? 0) > 0;
$estMoi          = $estModification && (int) $utilisateur['UserID'] === (int) $_SESSION['user_id'];

$val = static fn (string $cle, string $defaut = ''): string
    => htmlspecialchars((string) ($utilisateur[$cle] ?? $defaut));
?>

<p><a href="<?= BASE_URL ?>/index.php?page=utilisateurs">&larr; Retour aux utilisateurs</a></p>

<?php if ($erreur !== '') : ?>
  <p class="flash flash-erreur" role="alert"><?= htmlspecialchars($erreur) ?></p>
<?php endif; ?>

<section class="bloc">
  <form method="post" action="<?= BASE_URL ?>/index.php?page=utilisateur-enregistrer">
    <?= champ_csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($utilisateur['UserID'] ?? 0) ?>">

    <label for="prenom">Prénom</label>
    <input type="text" id="prenom" name="prenom" required maxlength="50"
           value="<?= $val('FirstName') ?>">

    <label for="nom">Nom</label>
    <input type="text" id="nom" name="nom" required maxlength="50"
           value="<?= $val('LastName') ?>">

    <label for="email">Adresse email</label>
    <input type="email" id="email" name="email" required maxlength="250"
           value="<?= $val('Email') ?>">
    <p class="note">Elle sert d'identifiant de connexion.</p>

    <?php
    /*
     * RÔLE = niveau d'accès aux finances, pas un titre. « Bureau BDE » est
     * porté par le Président, le Vice-Président et le Trésorier : tous trois
     * ont besoin de voir l'ensemble des clubs.
     */
    ?>
    <label for="role">Rôle</label>
    <select id="role" name="role" required>
      <option value="">— choisir —</option>
      <option value="bureau" <?= $val('Role') === 'bureau' ? 'selected' : '' ?>>
        Bureau BDE — accès à tous les clubs
      </option>
      <option value="responsable" <?= $val('Role') === 'responsable' ? 'selected' : '' ?>>
        Responsable de club — accès à son club uniquement
      </option>
    </select>

    <label for="club">Club</label>
    <select id="club" name="club">
      <option value="">— aucun —</option>
      <?php foreach ($clubs as $c) : ?>
        <option value="<?= (int) $c['ClubID'] ?>"
          <?= (int) ($utilisateur['ClubID'] ?? 0) === (int) $c['ClubID'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <p class="note">
      Obligatoire pour un responsable : c'est le club auquel son accès est
      limité. À laisser vide pour le bureau, qui n'a aucune limite.
    </p>

    <?php
    /*
     * IsAdmin est une PERMISSION qui s'ajoute au rôle, pas un rôle à part :
     * sans cela, le trésorier aurait besoin d'un second compte pour créer
     * des accès — impossible, son email servant déjà d'identifiant.
     */
    ?>
    <label class="case">
      <input type="checkbox" name="admin" value="1"
             <?= (int) ($utilisateur['IsAdmin'] ?? 0) === 1 ? 'checked' : '' ?>>
      Peut gérer les comptes utilisateurs
    </label>

    <?php if ($estModification) : ?>
      <label class="case">
        <input type="checkbox" name="actif" value="1"
               <?= (int) ($utilisateur['IsActive'] ?? 1) === 1 ? 'checked' : '' ?>>
        Compte actif
      </label>
      <p class="note">
        Décocher empêche la connexion, sans rien effacer : tout ce que la
        personne a saisi reste en place. C'est ce qu'on fait en fin de mandat.
      </p>

      <?php if ($estMoi) : ?>
        <p class="note note-alerte">
          Il s'agit de votre propre compte : vous ne pouvez ni le désactiver,
          ni retirer votre droit d'administration si vous êtes le dernier
          administrateur actif.
        </p>
      <?php endif; ?>
    <?php else : ?>
      <?php
      /*
       * À la création, le compte est forcément actif et reçoit un mot de
       * passe temporaire engendré par l'application — affiché UNE seule
       * fois après l'enregistrement, jamais envoyé par mail.
       */
      ?>
      <p class="note">
        Un mot de passe temporaire sera engendré et affiché <strong>une seule
        fois</strong> après l'enregistrement. Transmettez-le de vive voix : la
        personne devra en choisir un autre dès sa première connexion.
      </p>
    <?php endif; ?>

    <button type="submit" class="btn">
      <?= $estModification ? 'Enregistrer les modifications' : 'Créer le compte' ?>
    </button>
  </form>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
