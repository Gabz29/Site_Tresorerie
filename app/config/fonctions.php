<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  PETITES FONCTIONS UTILITAIRES, UTILISABLES PARTOUT
 * ============================================================================
 */

/**
 * Envoie le visiteur vers une autre page, puis arrête le script.
 *
 * @param string $cible Adresse relative au site, ex. '?page=clubs'
 *
 * Le exit() final n'est pas une précaution inutile : un en-tête "Location"
 * N'INTERROMPT PAS le script. Sans lui, le code continuerait de s'exécuter
 * et pourrait modifier des données ou renvoyer du contenu avant que le
 * navigateur ne suive la redirection.
 */
function rediriger(string $cible): never
{
    header('Location: ' . BASE_URL . '/index.php' . $cible);
    exit;
}

/**
 * Traduit un rôle stocké en base en libellé affichable.
 *
 * En base, le rôle est un mot-clé court et stable ('tresorier') : c'est lui
 * qu'on compare dans le code, jamais le libellé. Le texte affiché peut donc
 * changer sans toucher à la moindre condition ni à la base.
 *
 * ATTENTION AU VOCABULAIRE (cf. notes, tâche 1.1) : les maquettes emploient
 * encore "admin" pour désigner le trésorier. Ce n'est pas le cas ici —
 * l'administrateur gère les comptes, le trésorier gère l'argent.
 */
function libelle_role(string $role): string
{
    return match ($role) {
        'tresorier'   => 'Trésorier BDE',
        'responsable' => 'Responsable de club',
        'admin'       => 'Administrateur',
        default       => $role,
    };
}
