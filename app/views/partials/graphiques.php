<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  GRAPHIQUES SVG — générés par PHP, côté serveur
 * ============================================================================
 *
 *  POURQUOI PAS UNE BIBLIOTHÈQUE JAVASCRIPT (Chart.js et consorts).
 *
 *  Un graphique n'est rien d'autre que du dessin, et le SVG est un format
 *  de dessin en BALISAGE : une courbe s'écrit <polyline points="0,10 20,5…">,
 *  exactement comme un tableau s'écrit en <table>. PHP sait donc le produire
 *  seul, et on obtient de vrais axes, de vraies échelles, de vraies courbes.
 *
 *  Ce que cela nous évite :
 *   - une dépendance externe, alors que le réseau de l'école peut bloquer un
 *     CDN et que l'application doit tourner hors ligne ;
 *   - une page vide pour qui a JavaScript désactivé ou un navigateur ancien ;
 *   - un graphique qui ne s'imprime pas et n'apparaît pas dans un PDF.
 *
 *  Ce qu'on perd : les animations et les infobulles au survol. Le survol est
 *  rattrapé par les balises <title>, que les navigateurs affichent
 *  nativement ; les animations ne servent à rien pour lire une trésorerie.
 *
 *  ⚠ TOUT TEXTE VENANT DE LA BASE PASSE PAR htmlspecialchars(). Le SVG est
 *  du XML inséré dans la page : un club nommé </text><script>… s'y
 *  exécuterait exactement comme dans du HTML.
 * ============================================================================
 */

/*
 * Géométrie commune à tous les graphiques.
 *
 * On dessine dans un repère fixe de 840 × 280, et le viewBox se charge de
 * l'adapter à la largeur réelle de l'écran. Autrement dit : on calcule en
 * unités, jamais en pixels, et le graphique reste net du téléphone au
 * grand écran sans qu'on ait à s'en occuper.
 */
const G_LARGEUR = 840;
const G_HAUTEUR = 280;
const G_GAUCHE  = 68;   // place pour les montants de l'axe vertical
const G_DROITE  = 14;
const G_HAUT    = 14;
const G_BAS     = 42;   // place pour les libellés de mois

/** Largeur utile de la zone de tracé. */
function g_largeur_utile(): float
{
    return G_LARGEUR - G_GAUCHE - G_DROITE;
}

/** Hauteur utile de la zone de tracé. */
function g_hauteur_utile(): float
{
    return G_HAUTEUR - G_HAUT - G_BAS;
}

/**
 * Choisit une échelle LISIBLE pour l'axe vertical.
 *
 * ⚠ POURQUOI NE PAS PRENDRE SIMPLEMENT LE MIN ET LE MAX DES DONNÉES.
 *
 * Un axe gradué 0 / 3 417 / 6 834 € est illisible : l'œil ne sait pas
 * situer 5 000 entre deux repères pareils. On arrondit donc les bornes à
 * un « pas rond » (1, 2, 2,5 ou 5 fois une puissance de 10), pour obtenir
 * des graduations du genre 0 / 2 000 / 4 000 / 6 000 / 8 000.
 *
 * Le zéro est toujours inclus : une courbe de solde tracée entre 4 800 et
 * 5 000 € donnerait l'impression d'un effondrement, alors qu'il ne s'agit
 * que de 4 % de variation.
 *
 * @return array{min:float,max:float,pas:float}
 */
function g_echelle(float $min, float $max): array
{
    $min = min(0.0, $min);
    $max = max(0.0, $max);

    if ($min === 0.0 && $max === 0.0) {
        // Série entièrement vide : une échelle factice évite une division
        // par zéro et affiche un graphique plat plutôt qu'une page cassée.
        return ['min' => 0.0, 'max' => 1.0, 'pas' => 1.0];
    }

    $etendue = $max - $min;

    // On vise environ 5 graduations : assez pour se repérer, assez peu
    // pour que l'axe ne devienne pas une échelle de règle.
    $brut      = $etendue / 5;
    $puissance = 10 ** floor(log10($brut));
    $rapport   = $brut / $puissance;

    $pas = match (true) {
        $rapport <= 1.0 => 1.0 * $puissance,
        $rapport <= 2.0 => 2.0 * $puissance,
        $rapport <= 2.5 => 2.5 * $puissance,
        $rapport <= 5.0 => 5.0 * $puissance,
        default         => 10.0 * $puissance,
    };

    return [
        'min' => floor($min / $pas) * $pas,
        'max' => ceil($max / $pas) * $pas,
        'pas' => $pas,
    ];
}

/**
 * Convertit un montant en ordonnée SVG.
 *
 * ⚠ L'AXE VERTICAL DU SVG EST INVERSÉ : y = 0 est en HAUT de l'image.
 * D'où la soustraction — sans elle, tous les graphiques seraient
 * retournés, les gros montants en bas.
 *
 * @param array{min:float,max:float,pas:float} $echelle
 */
function g_y(float $valeur, array $echelle): float
{
    $amplitude = $echelle['max'] - $echelle['min'];

    if ($amplitude <= 0) {
        return G_HAUT + g_hauteur_utile();
    }

    return G_HAUT + g_hauteur_utile() * (1 - ($valeur - $echelle['min']) / $amplitude);
}

/** Montant abrégé pour l'axe : 12 000 → « 12 k€ ». */
function g_montant_court(float $valeur): string
{
    if (abs($valeur) >= 1000) {
        $milliers = $valeur / 1000;

        // Une décimale seulement si elle apporte quelque chose : « 2,5 k€ »
        // mais « 12 k€ » et non « 12,0 k€ ».
        return rtrim(rtrim(number_format($milliers, 1, ',', ' '), '0'), ',') . ' k€';
    }

    return number_format($valeur, 0, ',', ' ') . ' €';
}

/** Montant complet, pour les infobulles. */
function g_montant(float $valeur): string
{
    return number_format($valeur, 2, ',', ' ') . ' €';
}

/**
 * Dessine le fond commun : graduations horizontales, montants de l'axe
 * vertical, ligne du zéro et libellés sous l'axe horizontal.
 *
 * Les graduations sont HORIZONTALES et non verticales : on lit un montant
 * en suivant une ligne de l'œil jusqu'à l'axe, et c'est ce trait-là qui
 * rend la lecture possible sans survoler quoi que ce soit.
 *
 * @param array{min:float,max:float,pas:float} $echelle
 * @param array<int,string>                    $libelles
 */
function g_fond(array $echelle, array $libelles): string
{
    $svg = '';

    // Graduations et montants
    for ($v = $echelle['min']; $v <= $echelle['max'] + 0.001; $v += $echelle['pas']) {
        $y    = g_y($v, $echelle);
        $zero = abs($v) < 0.001;

        /*
         * La ligne du ZÉRO porte sa propre classe : sur un graphique de
         * trésorerie, c'est le seul repère qui change de sens quand on le
         * franchit. Une graduation parmi d'autres ne le dirait pas.
         */
        $svg .= sprintf(
            '<line class="%s" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
            $zero ? 'g-zero' : 'g-grille',
            G_GAUCHE, $y, G_LARGEUR - G_DROITE, $y
        );

        $svg .= sprintf(
            '<text class="g-axe" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
            G_GAUCHE - 10, $y + 4, htmlspecialchars(g_montant_court($v))
        );
    }

    // Libellés sous l'axe horizontal, centrés sur leur colonne.
    $nb = count($libelles);

    if ($nb > 0) {
        $pas = g_largeur_utile() / $nb;

        foreach (array_values($libelles) as $i => $libelle) {
            $svg .= sprintf(
                '<text class="g-axe" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
                G_GAUCHE + $pas * ($i + 0.5),
                G_HAUTEUR - G_BAS + 18,
                htmlspecialchars($libelle)
            );
        }
    }

    return $svg;
}

/**
 * Ouvre la balise <svg>.
 *
 * role="img" + <title> : sans cela un lecteur d'écran annonce une image
 * sans nom, et l'information est simplement perdue pour qui ne voit pas
 * le graphique.
 */
function g_ouvrir(string $titreAccessible): string
{
    return sprintf(
        '<svg class="graphique" viewBox="0 0 %d %d" role="img" aria-label="%s"'
        . ' preserveAspectRatio="xMidYMid meet"><title>%s</title>',
        G_LARGEUR,
        G_HAUTEUR,
        htmlspecialchars($titreAccessible),
        htmlspecialchars($titreAccessible)
    );
}

/**
 * COURBE — l'évolution d'une grandeur dans le temps.
 *
 * Une vraie courbe, pas des barres : des barres se comparent entre elles,
 * une courbe se suit. C'est la forme qui convient à un solde, parce que la
 * question posée est « dans quel sens ça va », pas « quel mois est le plus
 * gros ».
 *
 * @param array<int,array{libelle:string,valeur:float}>      $serie
 * @param array<int,array{libelle:string,valeur:float}|null> $projection
 *        Second tracé, en pointillés (ex. le solde prévisionnel). Les
 *        entrées null coupent le trait : c'est ainsi que la projection ne
 *        démarre qu'au mois en cours.
 * @param array<int,float> $comparaison
 *        Troisième tracé, discret (ex. l'exercice précédent). Il est
 *        aligné sur le RANG du mois, pas sur sa date : le 3ᵉ point de
 *        l'an dernier se superpose au 3ᵉ point de cette année, sans quoi
 *        les deux années ne pourraient jamais se comparer.
 */
function g_courbe(
    array $serie,
    array $projection,
    string $titreAccessible,
    array $comparaison = []
): string {
    if ($serie === []) {
        return '';
    }

    $valeurs = array_map(static fn (array $p): float => $p['valeur'], $serie);

    foreach ($projection as $p) {
        if ($p !== null) {
            $valeurs[] = $p['valeur'];
        }
    }

    /*
     * L'échelle tient compte de l'an dernier, sinon une année passée plus
     * dépensière sortirait du cadre : son tracé serait rogné en haut ou en
     * bas, et la comparaison deviendrait mensongère au lieu d'être utile.
     */
    foreach ($comparaison as $v) {
        $valeurs[] = (float) $v;
    }

    $echelle = g_echelle(min($valeurs), max($valeurs));
    $pas     = g_largeur_utile() / count($serie);
    $x       = static fn (int $i): float => G_GAUCHE + $pas * ($i + 0.5);

    $svg = g_ouvrir($titreAccessible);
    $svg .= g_fond($echelle, array_map(static fn (array $p): string => $p['libelle'], $serie));

    // --- L'exercice précédent, DESSINÉ EN PREMIER ----------------------
    /*
     * L'ordre compte : en SVG, ce qui est tracé après passe par-dessus.
     * La comparaison est un repère d'arrière-plan, pas le sujet du
     * graphique — elle doit donc passer SOUS la courbe de l'année en
     * cours, jamais devant.
     */
    if (count($comparaison) > 1) {
        $pointsComp = [];

        foreach (array_values($comparaison) as $i => $v) {
            if ($i >= count($serie)) {
                break;   // exercice plus long que celui-ci : on s'arrête au cadre
            }

            $pointsComp[] = sprintf(
                '%.1f,%.1f',
                G_GAUCHE + (g_largeur_utile() / count($serie)) * ($i + 0.5),
                g_y((float) $v, $echelle)
            );
        }

        $svg .= sprintf(
            '<polyline class="g-comparaison" points="%s"/>',
            implode(' ', $pointsComp)
        );
    }

    // --- Aire sous la courbe -------------------------------------------
    /*
     * Un remplissage léger jusqu'à la ligne du zéro. Il ne porte aucune
     * information que la courbe ne donne pas déjà, mais il fait
     * immédiatement voir de quel côté du zéro on se trouve — et c'est
     * bien la première chose qu'on veut savoir d'une trésorerie.
     */
    $yZero  = g_y(0.0, $echelle);
    $chemin = sprintf('M %.1f %.1f', $x(0), $yZero);

    foreach ($serie as $i => $point) {
        $chemin .= sprintf(' L %.1f %.1f', $x($i), g_y($point['valeur'], $echelle));
    }

    $chemin .= sprintf(' L %.1f %.1f Z', $x(count($serie) - 1), $yZero);

    $svg .= sprintf('<path class="g-aire" d="%s"/>', $chemin);

    // --- La courbe elle-même -------------------------------------------
    $points = [];

    foreach ($serie as $i => $point) {
        $points[] = sprintf('%.1f,%.1f', $x($i), g_y($point['valeur'], $echelle));
    }

    $svg .= sprintf(
        '<polyline class="g-courbe" points="%s"/>',
        implode(' ', $points)
    );

    // --- La projection, en pointillés ----------------------------------
    /*
     * Le pointillé n'est pas décoratif : il dit « ce n'est pas constaté,
     * c'est attendu ». Tracer la prévision du même trait que le réalisé
     * ferait lire comme acquis de l'argent qui n'est pas encore arrivé.
     */
    $segment = [];

    foreach ($projection as $i => $point) {
        if ($point === null) {
            continue;
        }

        $segment[] = sprintf('%.1f,%.1f', $x($i), g_y($point['valeur'], $echelle));
    }

    if (count($segment) > 1) {
        $svg .= sprintf(
            '<polyline class="g-projection" points="%s"/>',
            implode(' ', $segment)
        );
    }

    // --- Points de mesure ----------------------------------------------
    foreach ($serie as $i => $point) {
        $svg .= sprintf(
            '<circle class="g-point" cx="%.1f" cy="%.1f" r="3.5">'
            . '<title>%s : %s</title></circle>',
            $x($i),
            g_y($point['valeur'], $echelle),
            htmlspecialchars($point['libelle']),
            htmlspecialchars(g_montant($point['valeur']))
        );
    }

    return $svg . '</svg>';
}

/**
 * BARRES VERTICALES — comparer des mois entre eux.
 *
 * Deux dispositions, et elles ne répondent pas à la même question :
 *   - CÔTE À CÔTE ($empilees = false) : comparer les séries entre elles.
 *     C'est ce qu'il faut pour recettes / dépenses, où l'on regarde
 *     laquelle dépasse l'autre.
 *   - EMPILÉES ($empilees = true) : voir un TOTAL et sa composition.
 *     C'est ce qu'il faut pour les remboursements, où le total dû compte
 *     autant que sa répartition entre états.
 *
 * @param array<int,array{nom:string,classe:string,valeurs:array<int,float>}> $series
 *        'classe' est un nom de classe CSS, pas une couleur : la palette
 *        est décrite UNE fois dans la feuille de style. Semée dans le PHP,
 *        elle serait à corriger en six endroits au moindre changement de
 *        charte — et on en oublierait un.
 * @param array<int,string>                                                   $libelles
 */
function g_barres(array $series, array $libelles, string $titreAccessible, bool $empilees = false): string
{
    if ($series === [] || $libelles === []) {
        return '';
    }

    $nb = count($libelles);

    // Bornes de l'échelle : le cumul si l'on empile, la plus haute valeur
    // sinon. Se tromper ici ferait dépasser les barres hors du cadre.
    $maxi = 0.0;

    for ($i = 0; $i < $nb; $i++) {
        $cumul = 0.0;

        foreach ($series as $serie) {
            $v      = (float) ($serie['valeurs'][$i] ?? 0);
            $cumul += $v;
            $maxi   = max($maxi, $empilees ? $cumul : $v);
        }
    }

    $echelle = g_echelle(0.0, $maxi);
    $pasX    = g_largeur_utile() / $nb;

    // Largeur des barres : on garde un cinquième de la colonne en
    // respiration, faute de quoi les mois se touchent et se confondent.
    $groupe  = $pasX * 0.8;
    $largeur = $empilees ? $groupe * 0.55 : $groupe / count($series);

    $svg  = g_ouvrir($titreAccessible);
    $svg .= g_fond($echelle, $libelles);

    $yZero = g_y(0.0, $echelle);

    for ($i = 0; $i < $nb; $i++) {
        $baseX = G_GAUCHE + $pasX * $i + ($pasX - ($empilees ? $largeur : $groupe)) / 2;
        $sommet = $yZero;   // point de départ de l'empilement

        foreach (array_values($series) as $rang => $serie) {
            $valeur = (float) ($serie['valeurs'][$i] ?? 0);

            if ($valeur <= 0) {
                continue;   // rien à dessiner, et un rect de hauteur 0 est inutile
            }

            $hauteur = $yZero - g_y($valeur, $echelle);

            if ($empilees) {
                $x       = $baseX;
                $sommet -= $hauteur;
                $y       = $sommet;
            } else {
                $x = $baseX + $largeur * $rang;
                $y = $yZero - $hauteur;
            }

            $svg .= sprintf(
                '<rect class="%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2">'
                . '<title>%s — %s : %s</title></rect>',
                htmlspecialchars($serie['classe']),
                $x,
                $y,
                max(1.0, $largeur - ($empilees ? 0 : 2.0)),
                max(0.5, $hauteur),
                htmlspecialchars($libelles[$i]),
                htmlspecialchars($serie['nom']),
                htmlspecialchars(g_montant($valeur))
            );
        }
    }

    return $svg . '</svg>';
}

/**
 * BARRES HORIZONTALES — un classement.
 *
 * Horizontales et non verticales : les libellés sont ici des noms
 * (« Nourriture », « Club Photo »), pas des dates. À la verticale il
 * faudrait les incliner pour qu'ils tiennent, ce qui les rend pénibles à
 * lire ; à l'horizontale ils se lisent normalement, et l'ordre décroissant
 * saute aux yeux.
 *
 * @param array<int,array{libelle:string,montant:float}> $lignes
 */
function g_classement(array $lignes, string $classe, string $titreAccessible): string
{
    if ($lignes === []) {
        return '';
    }

    $lignes  = array_slice($lignes, 0, 10);   // au-delà, ce n'est plus un classement
    $nb      = count($lignes);
    $maxi    = max(array_map(static fn (array $l): float => (float) $l['montant'], $lignes));
    $maxi    = $maxi > 0 ? $maxi : 1.0;

    // Hauteur proportionnelle au nombre de lignes : un graphique à deux
    // entrées n'a aucune raison d'occuper la même place qu'un à dix.
    $ligneH  = 26;
    $hauteur = $nb * $ligneH + 12;
    $libelleL = 150;
    $montantL = 92;
    $pisteX  = $libelleL + 8;
    $pisteL  = G_LARGEUR - $pisteX - $montantL;

    $svg = sprintf(
        '<svg class="graphique" viewBox="0 0 %d %d" role="img" aria-label="%s"'
        . ' preserveAspectRatio="xMidYMid meet"><title>%s</title>',
        G_LARGEUR,
        $hauteur,
        htmlspecialchars($titreAccessible),
        htmlspecialchars($titreAccessible)
    );

    foreach (array_values($lignes) as $i => $ligne) {
        $montant = (float) $ligne['montant'];
        $y       = 6 + $i * $ligneH;
        $barreH  = 14;

        $svg .= sprintf(
            '<text class="g-libelle" x="%d" y="%.1f" text-anchor="end">%s</text>',
            $libelleL,
            $y + $barreH - 2,
            htmlspecialchars(mb_strimwidth($ligne['libelle'], 0, 24, '…'))
        );

        // Piste grise : elle matérialise le 100 %, sinon une barre courte
        // ne se distingue pas d'une donnée manquante.
        $svg .= sprintf(
            '<rect class="g-piste" x="%.1f" y="%.1f" width="%.1f" height="%d" rx="3"/>',
            $pisteX, $y, $pisteL, $barreH
        );

        $svg .= sprintf(
            '<rect class="%s" x="%.1f" y="%.1f" width="%.1f" height="%d" rx="3">'
            . '<title>%s : %s</title></rect>',
            htmlspecialchars($classe),
            $pisteX,
            $y,
            max(1.0, $pisteL * ($montant / $maxi)),
            $barreH,
            htmlspecialchars($ligne['libelle']),
            htmlspecialchars(g_montant($montant))
        );

        $svg .= sprintf(
            '<text class="g-valeur" x="%d" y="%.1f" text-anchor="end">%s</text>',
            G_LARGEUR - 4,
            $y + $barreH - 2,
            htmlspecialchars(g_montant($montant))
        );
    }

    return $svg . '</svg>';
}
