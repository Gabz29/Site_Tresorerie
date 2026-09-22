<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  FILTRE DU TABLEAU DE BORD
 * ============================================================================
 *
 *  Quatre réglages s'appliquent à presque tous les chiffres du tableau de
 *  bord : le club imposé (pour un responsable), la période, le périmètre
 *  BDE / clubs, et la sélection de clubs cochés dans le menu déroulant.
 *
 *  ILS SE HIÉRARCHISENT, du plus contraignant au plus large :
 *    1. le club imposé par le rôle     — un responsable ne sort pas du sien
 *    2. les clubs cochés               — la sélection de l'utilisateur
 *    3. le périmètre BDE / clubs       — le réglage général
 *  Le premier qui s'applique arrête les autres. Sans cet ordre, un
 *  responsable pourrait élargir sa vue en cochant des clubs dans l'URL.
 *
 *  POURQUOI UN OBJET PLUTÔT QUE TROIS PARAMÈTRES.
 *
 *  Statistiques compte une demi-douzaine de méthodes. Leur ajouter trois
 *  arguments à chacune donnerait des appels du genre
 *  `depensesParPole($ex, 0, null, 'clubs')`, où plus personne ne sait ce que
 *  vaut quoi, et où inverser deux arguments passerait inaperçu. Un objet se
 *  construit UNE fois dans le contrôleur et se transmet tel quel.
 *
 *  Surtout, la traduction en SQL est écrite ICI, une seule fois. Si elle
 *  était recopiée dans chaque requête, il suffirait d'en oublier une pour
 *  qu'un graphique continue d'afficher le BDE alors que l'utilisateur a
 *  demandé les clubs — une erreur silencieuse, et donc la pire.
 *
 *  ⚠ LES VALEURS SONT NORMALISÉES DANS LE CONSTRUCTEUR. Elles viennent de
 *  l'URL, donc de l'utilisateur. Le mois part en paramètre lié (pas de
 *  concaténation), mais le périmètre, lui, choisit un FRAGMENT DE SQL : on
 *  le ramène donc à l'une des trois valeurs connues, jamais à ce qui a été
 *  tapé dans la barre d'adresse.
 * ============================================================================
 */
final class FiltreStats
{
    /** Périmètres acceptés. Toute autre valeur retombe sur 'tout'. */
    public const PORTEES = ['tout', 'bde', 'clubs'];

    /** Nombre maximum de clubs cochables d'un coup — garde-fou, cf. plus bas. */
    private const MAX_CLUBS = 100;

    public readonly int $clubId;
    /** Mois au format AAAA-MM, ou null pour l'exercice entier. */
    public readonly ?string $mois;
    public readonly string $portee;
    /**
     * Clubs cochés dans le menu déroulant. Vide = pas de sélection, c'est
     * alors $portee qui décide.
     *
     * @var array<int,int>
     */
    public readonly array $clubIds;

    /**
     * @param array<int,mixed> $clubIds identifiants cochés (venant de l'URL)
     */
    public function __construct(
        int $clubId = 0,
        ?string $mois = null,
        string $portee = 'tout',
        array $clubIds = []
    ) {
        $this->clubId = max(0, $clubId);
        $this->mois   = self::moisValide($mois) ? $mois : null;

        /*
         * Les identifiants viennent de l'URL sous forme de tableau
         * (clubs[]=2&clubs[]=5). On les ramène à des entiers positifs,
         * on écarte les doublons, et on plafonne le nombre.
         *
         * Le plafond n'est pas de la paranoïa : chaque identifiant devient
         * un paramètre lié dans un IN (...). Sans limite, une URL forgée
         * avec dix mille entrées ferait construire une requête absurde —
         * pas une faille, mais un moyen facile de mettre le serveur à
         * genoux.
         */
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $clubIds),
            static fn (int $v): bool => $v > 0
        )));

        $this->clubIds = array_slice($ids, 0, self::MAX_CLUBS);

        /*
         * Une sélection de clubs IMPLIQUE le périmètre « clubs ». Sans
         * cela, cocher deux clubs en laissant le bouton sur « Tout »
         * afficherait un écran filtré alors que l'interface annoncerait
         * le contraire.
         */
        $portee = $this->clubIds !== [] ? 'clubs' : $portee;

        $this->portee = in_array($portee, self::PORTEES, true) ? $portee : 'tout';
    }

    /**
     * Le même filtre, mais sur l'exercice entier.
     *
     * Sert aux séries chronologiques (évolution du solde, mois par mois),
     * qui gardent le périmètre choisi mais ignorent la période : une courbe
     * réduite à un seul mois ne raconte plus rien.
     */
    public function surToutLExercice(): self
    {
        return new self($this->clubId, null, $this->portee, $this->clubIds);
    }

    /** Des clubs précis ont-ils été cochés ? */
    public function selectionDeClubs(): bool
    {
        return $this->clubIds !== [];
    }

    public function filtrePeriode(): bool
    {
        return $this->mois !== null;
    }

    /**
     * Fragment SQL à coller derrière un WHERE déjà commencé, et les
     * paramètres qui vont avec.
     *
     * @param string      $prefixe      alias portant ClubID, ex. 't.'
     * @param string      $colonneDate  colonne portant la date : « Date »
     *                                  pour les transactions,
     *                                  « Purchase_Date » pour les
     *                                  remboursements
     * @param string|null $prefixeDate  alias portant cette date, quand il
     *                                  diffère de celui du club. C'est le
     *                                  cas des versements : le club vient
     *                                  du budget (b.ClubID) mais la date du
     *                                  versement lui-même (d.Actual_Date).
     *                                  null = le même que $prefixe.
     * @return array{0:string,1:array<string,mixed>}
     */
    public function sql(string $prefixe = '', string $colonneDate = 'Date', ?string $prefixeDate = null): array
    {
        $sql    = '';
        $params = [];

        if ($this->clubId > 0) {
            /*
             * Un club précis l'emporte sur le périmètre : c'est le cas d'un
             * responsable, restreint au sien. Lui appliquer en plus un
             * filtre BDE/clubs pourrait vider son écran sans explication.
             */
            $sql            .= " AND {$prefixe}ClubID = :club";
            $params[':club'] = $this->clubId;
        } elseif ($this->clubIds !== []) {
            /*
             * Sélection explicite de clubs : elle passe avant le périmètre,
             * qu'elle précise.
             *
             * ⚠ ON NE CONCATÈNE PAS LES IDENTIFIANTS DANS LA REQUÊTE, même
             * s'ils ont déjà été transformés en entiers. On fabrique autant
             * de paramètres nommés que de valeurs (:cl0, :cl1, …) : le jour
             * où quelqu'un retirera le (int) du constructeur en croyant
             * simplifier, la requête restera sûre. Une protection qui ne
             * tient qu'à un cast placé ailleurs n'est pas une protection.
             */
            $marqueurs = [];

            foreach ($this->clubIds as $rang => $id) {
                $marqueurs[]            = ":cl{$rang}";
                $params[":cl{$rang}"]   = $id;
            }

            $sql .= " AND {$prefixe}ClubID IN (" . implode(', ', $marqueurs) . ')';
        } elseif ($this->portee === 'bde') {
            $sql .= " AND {$prefixe}ClubID IN (SELECT ClubID FROM clubs WHERE IsBDE = 1)";
        } elseif ($this->portee === 'clubs') {
            $sql .= " AND {$prefixe}ClubID NOT IN (SELECT ClubID FROM clubs WHERE IsBDE = 1)";
        }

        /*
         * Une sous-requête plutôt qu'une jointure sur clubs : les requêtes
         * de Statistiques joignent déjà categories, poles ou clubs selon les
         * cas, avec des alias différents. Une sous-requête s'ajoute partout
         * à l'identique, sans rien savoir du reste de la requête.
         */

        if ($this->mois !== null) {
            $alias           = $prefixeDate ?? $prefixe;
            $sql            .= " AND DATE_FORMAT({$alias}{$colonneDate}, '%Y-%m') = :mois";
            $params[':mois'] = $this->mois;
        }

        return [$sql, $params];
    }

    /**
     * Un mois de la forme AAAA-MM, et qui existe réellement.
     *
     * Le contrôle ne sert pas à se protéger d'une injection — le mois part
     * en paramètre lié — mais à éviter qu'une valeur fantaisiste ne renvoie
     * silencieusement zéro ligne, ce qu'on lirait comme « aucune dépense ».
     */
    private static function moisValide(?string $valeur): bool
    {
        if ($valeur === null || $valeur === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $valeur . '-01');

        return $date !== false && $date->format('Y-m') === $valeur;
    }
}
