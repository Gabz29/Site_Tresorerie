<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  RÉCEPTION ET ENVOI DES JUSTIFICATIFS
 * ============================================================================
 *
 *  Accepter un fichier venant d'un utilisateur est l'opération la plus
 *  risquée d'une application web. Les protections mises en place ici, et la
 *  raison de chacune :
 *
 *  1. STOCKAGE HORS DE LA RACINE WEB (BDE/uploads/, pas public/uploads/).
 *     Apache ne sert que public/ : même en devinant le nom d'un fichier,
 *     personne ne peut le télécharger directement. C'est la protection la
 *     plus solide — elle ne dépend d'aucune configuration Apache, donc elle
 *     survivra au déploiement sur le serveur ISEN.
 *
 *  2. NOM ENTIÈREMENT REGÉNÉRÉ. Le nom d'origine n'est jamais réutilisé sur
 *     le disque : il peut contenir « ../ » pour sortir du dossier, des
 *     caractères que le système interprète, ou une double extension du type
 *     « facture.php.pdf ». On garde le nom d'origine en base, pour
 *     l'affichage uniquement.
 *
 *  3. TYPE RÉEL VÉRIFIÉ, pas l'extension ni le type annoncé. Le navigateur
 *     déclare ce qu'il veut : renommer un script en .pdf suffirait à
 *     tromper un contrôle d'extension. finfo lit les premiers octets du
 *     fichier pour déterminer ce qu'il est vraiment.
 *
 *  4. TAILLE LIMITÉE à 10 Mo (cf. tâche 2.7 du plan).
 *
 *  5. ENVOI CONTRÔLÉ. Le téléchargement passe par un script qui vérifie la
 *     connexion et les droits sur le club avant d'envoyer le moindre octet.
 * ============================================================================
 */

/** Taille maximale souhaitée par le projet (cf. tâche 2.7 du plan). */
const JUSTIFICATIF_TAILLE_SOUHAITEE = 10 * 1024 * 1024;   // 10 Mo

/**
 * Taille maximale RÉELLEMENT acceptée, en octets.
 *
 * ⚠ NOTRE LIMITE N'EST PAS FORCÉMENT CELLE QUI S'APPLIQUE.
 *
 * PHP écarte un fichier trop gros AVANT que la moindre ligne de notre code
 * ne s'exécute, selon deux réglages du php.ini :
 *   - upload_max_filesize : taille d'un fichier (2 Mo par défaut sous WAMP)
 *   - post_max_size       : taille de TOUT l'envoi, fichier compris
 *
 * Annoncer « 10 Mo » quand le serveur en refuse 3 serait trompeur : on
 * calcule donc la limite effective, et c'est elle qu'on affiche.
 *
 * Pour relever la limite : menu WAMP > PHP > php.ini, ajuster les deux
 * valeurs (post_max_size doit rester supérieure à upload_max_filesize),
 * puis redémarrer les services. À vérifier aussi sur le serveur ISEN.
 */
function justificatif_taille_max(): int
{
    $limites = [JUSTIFICATIF_TAILLE_SOUHAITEE];

    foreach (['upload_max_filesize', 'post_max_size'] as $reglage) {
        $valeur = octets_depuis_ini((string) ini_get($reglage));

        if ($valeur > 0) {
            $limites[] = $valeur;
        }
    }

    // La plus basse des trois l'emporte : c'est celle qui bloquera.
    return min($limites);
}

/**
 * Convertit une valeur du php.ini (« 2M », « 8M », « 512K ») en octets.
 */
function octets_depuis_ini(string $valeur): int
{
    $valeur = trim($valeur);

    if ($valeur === '') {
        return 0;
    }

    $nombre = (int) $valeur;
    $unite  = strtolower(substr($valeur, -1));

    return match ($unite) {
        'g'     => $nombre * 1024 * 1024 * 1024,
        'm'     => $nombre * 1024 * 1024,
        'k'     => $nombre * 1024,
        default => $nombre,
    };
}

/**
 * Limite effective, exprimée pour l'affichage (« 2 Mo »).
 */
function justificatif_taille_max_lisible(): string
{
    $octets = justificatif_taille_max();

    return $octets >= 1024 * 1024
        ? round($octets / (1024 * 1024), 1) . ' Mo'
        : round($octets / 1024) . ' Ko';
}

/**
 * Types réellement acceptés, et l'extension qu'on leur donne.
 *
 * On part du TYPE constaté pour choisir l'extension, et non l'inverse :
 * ainsi l'extension du fichier stocké décrit toujours son contenu réel.
 */
const JUSTIFICATIF_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',   // photos prises depuis un iPhone
];

/**
 * Dossier de stockage, hors de la racine web.
 */
function dossier_justificatifs(): string
{
    return dirname(__DIR__, 2) . '/uploads';
}

/**
 * Traite un fichier reçu et renvoie le nom sous lequel il a été stocké.
 *
 * @param array<string,mixed>|null $fichier une entrée de $_FILES
 * @param string|null              $erreur  message d'erreur, s'il y a lieu
 * @return string|null  nom du fichier stocké, ou null si rien/erreur
 */
function enregistrer_justificatif(?array $fichier, ?string &$erreur = null): ?string
{
    $erreur = null;

    // Aucun fichier envoyé : ce n'est pas une erreur, le justificatif est
    // facultatif.
    if ($fichier === null || ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($fichier['error'] !== UPLOAD_ERR_OK) {
        /*
         * UPLOAD_ERR_INI_SIZE : le fichier dépasse upload_max_filesize du
         * php.ini, et PHP l'a écarté AVANT d'arriver jusqu'ici. Notre propre
         * limite de 10 Mo ne sert alors à rien : c'est la configuration du
         * serveur qui tranche, et elle vaut souvent 2 Mo par défaut.
         * À vérifier sur le serveur ISEN (cf. phase 4).
         */
        $erreur = match ((int) $fichier['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Le fichier est trop volumineux pour être accepté par le serveur.',
            UPLOAD_ERR_PARTIAL =>
                'Le fichier n\'a été que partiellement envoyé. Réessayez.',
            default =>
                'Le fichier n\'a pas pu être reçu (erreur ' . (int) $fichier['error'] . ').',
        };

        return null;
    }

    /*
     * is_uploaded_file() garantit que le chemin vient bien d'un envoi HTTP
     * et n'a pas été fabriqué : sans ce contrôle, une faille ailleurs dans
     * le code pourrait faire copier un fichier système quelconque.
     */
    if (!is_uploaded_file($fichier['tmp_name'])) {
        $erreur = 'Fichier invalide.';
        return null;
    }

    if ($fichier['size'] > justificatif_taille_max()) {
        $erreur = 'Le fichier dépasse la taille maximale de '
                . justificatif_taille_max_lisible() . '.';
        return null;
    }

    if ($fichier['size'] === 0) {
        $erreur = 'Le fichier est vide.';
        return null;
    }

    // Type RÉEL du fichier, lu dans son contenu — jamais $fichier['type'],
    // qui est simplement ce que le navigateur a bien voulu annoncer.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type  = (string) $finfo->file($fichier['tmp_name']);

    if (!isset(JUSTIFICATIF_TYPES[$type])) {
        $erreur = 'Format non accepté. Formats possibles : PDF, JPG, PNG, WEBP, HEIC.';
        return null;
    }

    // Nom imprévisible, sans aucun lien avec le nom d'origine.
    $nom = bin2hex(random_bytes(16)) . '.' . JUSTIFICATIF_TYPES[$type];

    $dossier = dossier_justificatifs();

    if (!is_dir($dossier) && !mkdir($dossier, 0755, true) && !is_dir($dossier)) {
        $erreur = 'Le dossier de stockage est inaccessible.';
        return null;
    }

    // move_uploaded_file plutôt que copy/rename : elle refuse d'agir si le
    // fichier source n'est pas un envoi HTTP légitime.
    if (!move_uploaded_file($fichier['tmp_name'], $dossier . '/' . $nom)) {
        $erreur = 'Le fichier n\'a pas pu être enregistré.';
        return null;
    }

    return $nom;
}

/**
 * Duplique un justificatif et renvoie le nom de la copie.
 *
 * Utilisé au paiement d'un remboursement : la dépense générée reçoit sa
 * propre copie de la pièce justificative.
 *
 * ⚠ POURQUOI COPIER PLUTÔT QUE PARTAGER LE MÊME FICHIER.
 * Si la demande et la transaction pointaient vers le même fichier, retirer
 * le justificatif de la demande ferait disparaître celui de la dépense —
 * qui se retrouverait sans pièce en comptabilité, sans que rien ne le
 * signale. Chaque enregistrement porte donc sa propre copie. Le coût est
 * négligeable : quelques centaines de kilooctets.
 *
 * Renvoie null si la source est absente : l'appelant poursuit alors sans
 * justificatif plutôt que d'échouer — mieux vaut une dépense sans pièce
 * qu'un remboursement bloqué.
 */
function copier_justificatif(?string $nom): ?string
{
    if ($nom === null || $nom === '') {
        return null;
    }

    $source = dossier_justificatifs() . '/' . basename($nom);

    if (!is_file($source)) {
        return null;
    }

    $extension = pathinfo($source, PATHINFO_EXTENSION);
    $copie     = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');

    if (!copy($source, dossier_justificatifs() . '/' . $copie)) {
        return null;
    }

    return $copie;
}

/**
 * Supprime un justificatif du disque.
 *
 * Le nom vient de la base, mais on le vérifie tout de même : basename()
 * neutralise un éventuel « ../ » qui ferait sortir du dossier. Une valeur
 * corrompue en base ne doit pas permettre d'effacer un fichier ailleurs.
 */
function supprimer_justificatif(?string $nom): void
{
    if ($nom === null || $nom === '') {
        return;
    }

    $chemin = dossier_justificatifs() . '/' . basename($nom);

    if (is_file($chemin)) {
        unlink($chemin);
    }
}

/**
 * Envoie un justificatif au navigateur.
 *
 * ⚠ À n'appeler QU'APRÈS avoir vérifié les droits de l'utilisateur.
 */
function envoyer_justificatif(string $nom, string $nomAffiche): never
{
    $chemin = dossier_justificatifs() . '/' . basename($nom);

    if (!is_file($chemin)) {
        http_response_code(404);
        exit('Justificatif introuvable.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type  = (string) $finfo->file($chemin);

    // On ne renvoie que des types que l'on a nous-mêmes acceptés : si le
    // contenu du dossier avait été altéré, on n'aide pas le navigateur à
    // l'interpréter.
    if (!isset(JUSTIFICATIF_TYPES[$type])) {
        $type = 'application/octet-stream';
    }

    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($chemin));

    // "inline" : le PDF ou l'image s'ouvre dans le navigateur plutôt que de
    // déclencher un téléchargement. Le nom proposé à l'enregistrement reste
    // celui d'origine, débarrassé de tout caractère problématique.
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w\-. ]/u', '_', $nomAffiche) . '"');

    // Empêche le navigateur de « deviner » un autre type que celui annoncé
    // — sans cela, un fichier au contenu ambigu pourrait être interprété
    // comme du HTML, donc exécuter du script dans le contexte du site.
    header('X-Content-Type-Options: nosniff');

    // Les justificatifs sont confidentiels : aucun cache intermédiaire.
    header('Cache-Control: private, no-store');

    readfile($chemin);
    exit;
}
