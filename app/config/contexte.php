<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/FiscalYear.php';

/**
 * ============================================================================
 *  EXERCICE CONSULTÉ
 * ============================================================================
 *
 *  ⚠ DEUX NOTIONS À NE PAS CONFONDRE :
 *
 *   - l'exercice ACTIF (fiscalyear.IsActive = 1) : celui sur lequel on
 *     SAISIT. Il est unique, et c'est le bureau qui décide d'en changer.
 *
 *   - l'exercice CONSULTÉ (ce fichier) : celui qu'on REGARDE. Chacun choisit
 *     le sien, à tout moment, sans que cela affecte les autres utilisateurs
 *     ni la saisie. Il permet de retrouver en 2028 les chiffres exacts de
 *     2026-2027.
 *
 *  Le choix est conservé en session : il suit la navigation d'une page à
 *  l'autre, sans être imposé à qui que ce soit d'autre.
 *
 *  ⚠ ET LE RATTACHEMENT D'UNE TRANSACTION, LUI, NE DÉPEND D'AUCUN DES DEUX :
 *  il se déduit de la DATE de la transaction (cf. schema.sql). Consulter
 *  2026-2027 ne doit jamais rattacher une écriture à cet exercice-là.
 * ============================================================================
 */

/**
 * L'exercice actuellement consulté, ou null si la base n'en contient aucun.
 *
 * Par défaut l'exercice actif ; sinon celui choisi par l'utilisateur.
 *
 * @return array<string,mixed>|null
 */
function exercice_consulte(): ?array
{
    static $exercice = null;
    static $charge   = false;

    if ($charge) {
        return $exercice;
    }

    $charge = true;
    $id     = $_SESSION['exercice_consulte'] ?? null;

    if ($id !== null) {
        $exercice = FiscalYear::findById((int) $id);

        // L'exercice mémorisé a pu disparaître depuis (suppression, base
        // réinitialisée). On ne laisse pas une session périmée bloquer
        // l'affichage : on oublie le choix et on retombe sur l'actif.
        if ($exercice === null) {
            unset($_SESSION['exercice_consulte']);
        }
    }

    if ($exercice === null) {
        $exercice = FiscalYear::getActive();
    }

    return $exercice;
}

/**
 * Identifiant de l'exercice consulté, ou null.
 */
function exercice_consulte_id(): ?int
{
    $exercice = exercice_consulte();

    return $exercice === null ? null : (int) $exercice['FiscalYearID'];
}

/**
 * Mémorise l'exercice que l'utilisateur souhaite consulter.
 */
function definir_exercice_consulte(int $id): void
{
    $_SESSION['exercice_consulte'] = $id;
}

/**
 * L'exercice consulté est-il celui sur lequel on saisit ?
 *
 * Sert à afficher les écrans passés en lecture seule : un exercice clos est
 * figé, on n'y ajoute ni n'y modifie plus rien (décision du 20/09/2026, même
 * principe qu'en comptabilité — on n'appose pas d'écriture sur un exercice
 * arrêté et présenté en assemblée générale).
 */
function exercice_consulte_est_actif(): bool
{
    $exercice = exercice_consulte();

    return $exercice !== null && (int) $exercice['IsActive'] === 1;
}
