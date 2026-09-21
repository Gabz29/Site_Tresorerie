<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * ============================================================================
 *  MODÈLE — table `fiscalyear` (les exercices budgétaires)
 * ============================================================================
 *
 *  Un exercice court de septembre N à août N+1, au rythme de l'année
 *  universitaire et non de l'année civile.
 *
 *  Règle centrale : UN SEUL exercice actif à la fois. C'est lui que les
 *  écrans proposent par défaut pour la saisie, et sur lequel porte le
 *  tableau de bord.
 *
 *  Les exercices clos ne sont jamais supprimés : les transactions et les
 *  budgets y renvoient par clé étrangère, et c'est tout l'intérêt de
 *  l'application de conserver l'historique d'une année sur l'autre.
 * ============================================================================
 */
class FiscalYear
{
    /**
     * Tous les exercices, du plus récent au plus ancien.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getAll(): array
    {
        return db()->query(
            'SELECT FiscalYearID, Year, Start_Date, End_Date, IsActive
             FROM fiscalyear
             ORDER BY Start_Date DESC'
        )->fetchAll();
    }

    /**
     * L'exercice actif, ou null s'il n'y en a aucun.
     *
     * Peut légitimement renvoyer null : au tout premier lancement, aucun
     * exercice n'existe encore. Les appelants doivent traiter ce cas plutôt
     * que de supposer qu'il y a toujours un exercice en cours.
     *
     * @return array<string,mixed>|null
     */
    public static function getActive(): ?array
    {
        $ligne = db()->query(
            'SELECT FiscalYearID, Year, Start_Date, End_Date, IsActive
             FROM fiscalyear
             WHERE IsActive = 1
             LIMIT 1'
        )->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT FiscalYearID, Year, Start_Date, End_Date, IsActive
             FROM fiscalyear
             WHERE FiscalYearID = :id'
        );

        $stmt->execute([':id' => $id]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * L'exercice auquel appartient une date, ou null si aucun ne la couvre.
     *
     * ⚠ C'EST LA RÈGLE DE RATTACHEMENT DES TRANSACTIONS (cf. schema.sql) :
     * l'exercice d'une écriture se déduit de sa DATE, jamais de l'exercice
     * qu'on est en train de consulter ni de l'exercice actif.
     *
     * Sans cette règle, saisir une dépense datée de mars 2027 en consultant
     * l'exercice 2027-2028 la rattacherait à la mauvaise année : les totaux
     * des DEUX exercices seraient faux, et rien ne le signalerait.
     *
     * Renvoie null quand la date ne tombe dans aucun exercice (typiquement
     * une faute de frappe sur l'année) : l'appelant doit refuser la saisie
     * plutôt que d'inventer un rattachement.
     *
     * @return array<string,mixed>|null
     */
    public static function trouverParDate(string $date): ?array
    {
        $stmt = db()->prepare(
            'SELECT FiscalYearID, Year, Start_Date, End_Date, IsActive
             FROM fiscalyear
             WHERE :date BETWEEN Start_Date AND End_Date
             LIMIT 1'
        );

        $stmt->execute([':date' => $date]);
        $ligne = $stmt->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Un exercice porte-t-il déjà ce libellé ?
     */
    public static function libelleExiste(string $libelle): bool
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM fiscalyear WHERE Year = :libelle');
        $stmt->execute([':libelle' => $libelle]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Un exercice existant recouvre-t-il tout ou partie de cette période ?
     *
     * ⚠ RÈGLE MÉTIER IMPORTANTE. D'après schema.sql, l'exercice d'une
     * transaction se déduit de sa DATE (Start_Date <= Date <= End_Date). Si
     * deux exercices se chevauchaient, une même transaction appartiendrait à
     * deux exercices : les totaux annuels deviendraient faux, sans qu'aucune
     * erreur ne se manifeste.
     *
     * Deux périodes se chevauchent dès lors que chacune commence avant que
     * l'autre ne finisse — une seule condition suffit à couvrir tous les cas
     * de figure (inclusion, recouvrement partiel d'un côté ou de l'autre).
     */
    public static function chevauche(string $debut, string $fin, ?int $exclureId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM fiscalyear
                WHERE Start_Date <= :fin AND End_Date >= :debut';

        if ($exclureId !== null) {
            $sql .= ' AND FiscalYearID <> :id';
        }

        $stmt = db()->prepare($sql);
        $stmt->bindValue(':debut', $debut);
        $stmt->bindValue(':fin', $fin);

        if ($exclureId !== null) {
            $stmt->bindValue(':id', $exclureId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Crée un exercice (inactif par défaut) et renvoie son identifiant.
     */
    public static function create(string $libelle, string $debut, string $fin): int
    {
        $stmt = db()->prepare(
            'INSERT INTO fiscalyear (Year, Start_Date, End_Date, IsActive)
             VALUES (:libelle, :debut, :fin, 0)'
        );

        $stmt->execute([
            ':libelle' => $libelle,
            ':debut'   => $debut,
            ':fin'     => $fin,
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Rend un exercice actif, et désactive tous les autres.
     *
     * ⚠ POURQUOI UNE TRANSACTION SQL (beginTransaction / commit).
     *
     * L'opération se fait en DEUX requêtes : désactiver tout le monde, puis
     * activer celui-ci. Si la première réussit et que la seconde échoue
     * (coupure, erreur, serveur arrêté au mauvais moment), la base se
     * retrouve SANS AUCUN exercice actif — un état incohérent, que rien ne
     * signalerait, et qui casserait la saisie pour tout le monde.
     *
     * Une transaction rend les deux requêtes indivisibles : soit les deux
     * s'appliquent, soit aucune. Il n'existe pas d'état intermédiaire.
     *
     * C'est le premier endroit du projet où deux écritures doivent tenir
     * ensemble. Ce sera aussi le cas au versement d'un budget (2.6) et au
     * remboursement générant une transaction (2.8).
     */
    public static function activate(int $id): void
    {
        $pdo = db();

        $pdo->beginTransaction();

        try {
            $pdo->exec('UPDATE fiscalyear SET IsActive = 0');

            $stmt = $pdo->prepare('UPDATE fiscalyear SET IsActive = 1 WHERE FiscalYearID = :id');
            $stmt->execute([':id' => $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            // rollBack() annule TOUTES les modifications faites depuis
            // beginTransaction() : la base revient exactement à son état
            // d'avant. On relance ensuite l'erreur, pour que l'appelant
            // sache que l'opération a échoué — l'avaler ici laisserait
            // croire à une réussite.
            $pdo->rollBack();
            throw $e;
        }
    }
}
