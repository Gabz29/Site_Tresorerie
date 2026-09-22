-- ============================================================================
--  JEU DE DONNÉES DE TEST — JUMÃO (Trésorerie BDE ISEN)
--  À importer dans phpMyAdmin APRÈS sql/schema.sql
--
--  POURQUOI CE FICHIER :
--  Les 8 tables créées par schema.sql sont vides. Or une page "liste des
--  clubs" branchée sur une base vide afficherait une page vide — et une page
--  vide ne permet pas de distinguer "ça marche mais il n'y a rien à montrer"
--  de "la connexion est cassée". Il faut donc des données AVANT d'écrire le
--  code PHP qui les lit.
--
--  CE FICHIER EST RÉEXÉCUTABLE :
--  il commence par vider les tables. On peut donc le relancer autant de fois
--  que nécessaire sans erreur de doublon (utile après un test qui a modifié
--  les données).
--
--  ATTENTION : ce sont des données FICTIVES, destinées au développement en
--  local uniquement. Ne jamais importer ce fichier sur le serveur ISEN en
--  production : il effacerait les vraies données.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;   -- désactive les vérifications le temps de l'import

-- ----------------------------------------------------------------------------
--  NETTOYAGE — dans l'ordre INVERSE des dépendances
--  (on supprime d'abord ce qui référence les autres)
-- ----------------------------------------------------------------------------
DELETE FROM reimbursements;
DELETE FROM transactions;
DELETE FROM disbursements;
DELETE FROM budgets;
DELETE FROM users;
DELETE FROM fiscalyear;
DELETE FROM clubs;
DELETE FROM categories;
DELETE FROM poles;

-- ============================================================================
--  0. POLES — les équipes du bureau, communes à tous les clubs
--
--  « Général » est le pôle fourre-tout, nécessaire puisque le pôle est
--  OBLIGATOIRE sur chaque écriture : une subvention reçue ou des frais
--  bancaires ne relèvent d'aucune équipe opérationnelle.
-- ============================================================================
INSERT INTO poles (PoleID, Name, IsActive) VALUES
  (1, 'Général',    1),
  (2, 'Logistique', 1),
  (3, 'Event',      1),
  (4, 'Tanière',    1),
  (5, 'Comm',       1);

-- ============================================================================
--  1. CATEGORIES
--  Type : 'depense' | 'recette' | 'both'
--
--  ⚠ UNE CATÉGORIE DIT CE QUI A ÉTÉ ACHETÉ, jamais pour quelle équipe :
--  cette seconde information est portée par le PÔLE. Un disque dur acheté
--  pour l'event est « Matériel » avec le pôle Event ; des flyers pour une
--  soirée sont « Impression & goodies » avec ce même pôle.
--
--  Les anciennes catégories « Événement » et « Communication » ont donc été
--  remplacées le 21/09/2026 : elles faisaient doublon avec les pôles Event
--  et Comm, et une écriture « pôle Event + catégorie Événement » n'apportait
--  aucune information.
-- ============================================================================
INSERT INTO categories (CategoryID, Name, `Type`, IsActive) VALUES
  -- Dépenses
  (1,  'Matériel',                'depense', 1),  -- disque dur, sono, composants
  (2,  'Location & prestations',  'depense', 1),  -- salle, DJ, prestataire
  (3,  'Nourriture & boissons',   'depense', 1),  -- buffets, courses
  (4,  'Transport',               'depense', 1),  -- déplacements, essence
  (5,  'Impression & goodies',    'depense', 1),  -- flyers, affiches, t-shirts
  (10, 'Licences & assurances',   'depense', 1),  -- licences sportives, fédérations
  (11, 'Frais bancaires',         'depense', 1),  -- tenue de compte, commissions
  -- Recettes
  (6,  'Subvention',              'recette', 1),  -- CVEC, école, collectivités
  (7,  'Cotisations',             'recette', 1),  -- adhésions des membres
  (8,  'Ventes & buvette',        'recette', 1),  -- soirées, goodies revendus
  -- Les deux
  (9,  'Divers',                  'both', 1);     -- ce qui n'entre nulle part

-- ============================================================================
--  2. CLUBS
--  Le BDE lui-même est enregistré comme un club (ClubID = 1), conformément
--  au commentaire de schema.sql.
--
--  NOTE : le Club Œnologie est volontairement archivé (IsActive = 0). Il sert
--  à vérifier que la requête Club::getAll() filtre bien sur IsActive = 1 :
--  s'il apparaît à l'écran, c'est que le filtre est manquant ou incorrect.
-- ============================================================================
--  IsBDE = 1 pour le seul BDE : son budget vient directement de l'ISEN,
--  alors que ceux des clubs sont une répartition d'une autre enveloppe.
INSERT INTO clubs (ClubID, Name, Description, IsBDE, IsActive) VALUES
  (1, 'BDE ISEN Brest',  'Bureau des Étudiants — gère le budget global et les événements de campus', 1, 1),
  (2, 'Club Robotique',  'Conception de robots pour la Coupe de France de robotique',                0, 1),
  (3, 'Club Photo',      'Ateliers photo, expositions et couverture des événements du campus',       0, 1),
  (4, 'Club Musique',    'Répétitions, concerts et prêt de matériel aux étudiants musiciens',        0, 1),
  (5, 'Club Sport',      'Tournois inter-écoles, licences et location de créneaux sportifs',         0, 1),
  (6, 'Club Œnologie',   'Club dissous en 2025 — conservé pour l''historique comptable',             0, 0);

-- ============================================================================
--  3. FISCALYEAR (exercices : septembre N -> août N+1)
--  Un seul exercice actif à la fois. Nous sommes en septembre 2026, donc
--  l'exercice courant est 2026-2027.
-- ============================================================================
--  Enveloppe_Clubs : somme reçue de l'ISEN et destinée aux clubs, que le
--  BDE répartit entre eux. À ne pas confondre avec le budget du BDE
--  lui-même (ligne 1 de la table budgets), qui est une autre enveloppe.
INSERT INTO fiscalyear (FiscalYearID, `Year`, Start_Date, End_Date, Enveloppe_Clubs, IsActive) VALUES
  (1, '2025-2026', '2025-09-01', '2026-08-31',  9000.00, 0),   -- exercice clos
  (2, '2026-2027', '2026-09-01', '2027-08-31', 12000.00, 1);   -- exercice EN COURS

-- ============================================================================
--  4. USERS — les 3 rôles du projet
--
--  IDENTIFIANT DE CONNEXION = l'EMAIL (il n'y a pas de "username").
--
--  ⚠ DOMAINE example.com, ET CE N'EST PAS UN OUBLI. Ce domaine est
--  RÉSERVÉ par la RFC 2606 pour les exemples : aucun message ne peut y
--  aboutir. Avec un domaine réel, le jour où l'application enverra des
--  mails (réinitialisation de mot de passe), un simple test avec ces
--  comptes expédierait de vrais messages vers des adresses inexistantes
--  — d'où des rebonds, et à la longue une réputation d'expéditeur
--  dégradée pour le domaine de l'école.
--  Ne JAMAIS mettre ici les vraies adresses des membres du bureau : le
--  dépôt est public, et ce qui y est commité ne peut plus être retiré.
--
--  MOT DE PASSE : les trois comptes ont le mot de passe  password123
--  Ce qui est stocké n'est PAS le mot de passe mais son EMPREINTE (hash
--  bcrypt), calculée avec password_hash(). Un fichier .sql ne pouvant pas
--  appeler une fonction PHP, les empreintes sont écrites en dur ici.
--  password_verify() les validera normalement à la tâche 2.3.
--
--  Les trois empreintes sont différentes bien que le mot de passe soit le
--  même : password_hash() ajoute un "sel" aléatoire à chaque appel, pour
--  qu'on ne puisse pas repérer deux mots de passe identiques en lisant la base.
--
--  ClubID : le club géré par l'utilisateur (NULL si aucun).
-- ============================================================================
--  Les 4 comptes couvrent les combinaisons utiles pour tester les droits :
--    nº1 bureau + admin   -> tout, y compris la gestion des comptes
--    nº2 responsable      -> son club uniquement
--    nº3 bureau sans admin-> toutes les finances, mais pas les comptes
--    nº4 DÉSACTIVÉ        -> doit être refusé à la connexion
--
--  ClubID est NULL pour les membres du bureau : la colonne signifie « club
--  auquel l'accès est limité », et le bureau n'a aucune limite. Y inscrire
--  leur club de rattachement laisserait croire, en lisant la table, que leur
--  accès est restreint. En pratique il n'existera d'ailleurs jamais de
--  « responsable du BDE » : ceux qui gèrent le BDE (P, VP, Trésorier) ont
--  précisément le rôle bureau.
INSERT INTO users (UserID, Email, Password, Role, LastName, FirstName, IsAdmin, IsActive, ClubID) VALUES
  (1, 'jean.dupont@example.com',
      '$2y$10$Ye8IARnb0Wm09ynt68U6IuEbXj21edV7UbYOSqM8dxGzivXXmBYBa',
      'bureau',      'Dupont',  'Jean',    1, 1, NULL),   -- Trésorier BDE
  (2, 'camille.martin@example.com',
      '$2y$10$WdpQOvDj4CCxY.WXK7KFBuSjbAqy89/wI1ulqKnksQHBf71t4JAza',
      'responsable', 'Martin',  'Camille', 0, 1,    2),   -- limitée au Club Robotique
  (3, 'alex.bernard@example.com',
      '$2y$10$aIjirT1moU83qLglTPTx/uiSzXFXGlft2XIM7KSLgQQITl1MyOoaW',
      'bureau',      'Bernard', 'Alex',    0, 1, NULL),   -- Vice-président BDE
  (4, 'paul.ancien@example.com',
      '$2y$10$Ye8IARnb0Wm09ynt68U6IuEbXj21edV7UbYOSqM8dxGzivXXmBYBa',
      'bureau',      'Ancien',  'Paul',    0, 0, NULL);   -- bureau précédent

-- ============================================================================
--  5. BUDGETS — enveloppe prévisionnelle, 1 par club et par exercice
--  Disbursement_Count : 4 tranches pour le BDE, 2 pour les clubs.
-- ============================================================================
INSERT INTO budgets (BudgetID, Planned_Amount, Disbursement_Count, Notes, ClubID, FiscalYearID) VALUES
  -- Le BDE : enveloppe propre de l'ISEN, 4 versements imposés par la compta
  -- de l'école (le BDE ne décide de rien sur leur calendrier).
  (1, 30000.00, 4, 'Enveloppe BDE versée par l''ISEN — 4 tranches imposées', 1, 2),
  -- Les clubs : répartition de l'enveloppe de 12 000 € reçue pour eux.
  -- Total réparti ici : 10 500 € — il reste donc 1 500 € à attribuer, ce qui
  -- permet de vérifier l'indicateur « reste à répartir » à l'écran.
  (2,  4000.00, 2, 'Composants et déplacement Coupe de France',               2, 2),
  (3,  1500.00, 2, 'Consommables et tirages pour les expositions',            3, 2),
  (4,  2000.00, 2, 'Entretien du matériel et location de salles',             4, 2),
  (5,  3000.00, 2, 'Licences et créneaux sportifs',                           5, 2),
  -- ------------------------------------------------------------------
  -- EXERCICE CLOS 2025-2026 — ajouté le 22/09/2026.
  --
  -- POURQUOI REMPLIR UNE ANNÉE TERMINÉE : le tableau de bord compare
  -- désormais la courbe du solde à celle de l'exercice précédent. Sans
  -- données ici, la comparaison ne s'afficherait jamais, et on ne
  -- pourrait pas vérifier qu'elle fonctionne avant... l'an prochain.
  --
  -- CONTRASTE VOULU AVEC 2026-2027 : l'enveloppe clubs de 9 000 € est
  -- ici répartie INTÉGRALEMENT (3500+1200+1800+2500 = 9000), alors
  -- qu'il reste 1 500 € à attribuer sur l'exercice en cours. On voit
  -- ainsi l'indicateur « reste à répartir » dans ses deux états.
  ( 6, 28000.00, 4, 'Enveloppe BDE 2025-2026 — 4 tranches ISEN',              1, 1),
  ( 7,  3500.00, 2, 'Robot Coupe de France 2026',                             2, 1),
  ( 8,  1200.00, 2, 'Consommables et expositions',                            3, 1),
  ( 9,  1800.00, 2, 'Entretien instruments et salles de répétition',          4, 1),
  (10,  2500.00, 2, 'Licences et créneaux sportifs',                          5, 1);

-- ============================================================================
--  6. DISBURSEMENTS — tranches de versement
--  Status 'recu'  -> Actual_Amount et Actual_Date renseignés
--  Status 'prevu' -> les deux restent NULL (versement pas encore reçu)
--  Chaque budget a bien exactement Disbursement_Count lignes.
-- ============================================================================
INSERT INTO disbursements (DisbursementID, `Number`, Planned_Amount, Actual_Amount, Planned_Date, Actual_Date, `Status`, BudgetID) VALUES
  -- Budget 1 : BDE — 30 000 € en 4 versements imposés par la compta ISEN.
  -- Montants INÉGAUX à dessein (8 000 × 3 puis 6 000) : le calendrier et le
  -- découpage viennent de l'école, le BDE ne les choisit pas.
  ( 1, 1, 8000.00, 8000.00, '2026-10-01', '2026-10-03', 'recu',  1),
  ( 2, 2, 8000.00,    NULL, '2026-12-15',        NULL, 'prevu',  1),
  ( 3, 3, 8000.00,    NULL, '2027-02-15',        NULL, 'prevu',  1),
  ( 4, 4, 6000.00,    NULL, '2027-06-15',        NULL, 'prevu',  1),
  -- Budget 2 : Robotique — 4 000 € en 2 tranches
  ( 5, 1, 2000.00, 2000.00, '2026-10-15', '2026-10-17', 'recu',  2),
  ( 6, 2, 2000.00,    NULL, '2027-01-31',        NULL, 'prevu',  2),
  -- Budget 3 : Photo — 1 500 € en 2 tranches
  ( 7, 1,  750.00,  750.00, '2026-10-15', '2026-10-17', 'recu',  3),
  ( 8, 2,  750.00,    NULL, '2027-01-31',        NULL, 'prevu',  3),
  -- Budget 4 : Musique — 2 000 € en 2 tranches
  ( 9, 1, 1000.00, 1000.00, '2026-10-15', '2026-10-18', 'recu',  4),
  (10, 2, 1000.00,    NULL, '2027-01-31',        NULL, 'prevu',  4),
  -- Budget 5 : Sport — 3 000 € en 2 tranches
  (11, 1, 1500.00, 1500.00, '2026-10-15', '2026-10-18', 'recu',  5),
  (12, 2, 1500.00,    NULL, '2027-01-31',        NULL, 'prevu',  5),
  -- ------------------------------------------------------------------
  -- EXERCICE CLOS 2025-2026 : TOUTES les tranches sont 'recu'.
  -- C'est la définition même d'un exercice clos — il ne reste rien à
  -- encaisser. C'est aussi ce qui fait que la courbe de comparaison est
  -- complète sur douze mois, alors que celle de l'année en cours
  -- s'arrête au mois écoulé et se prolonge en pointillés.
  (13, 1, 8000.00, 8000.00, '2025-10-01', '2025-10-02', 'recu',  6),
  (14, 2, 8000.00, 8000.00, '2025-12-15', '2025-12-16', 'recu',  6),
  (15, 3, 6000.00, 6000.00, '2026-02-15', '2026-02-17', 'recu',  6),
  (16, 4, 6000.00, 6000.00, '2026-06-15', '2026-06-16', 'recu',  6),
  (17, 1, 1750.00, 1750.00, '2025-10-15', '2025-10-16', 'recu',  7),
  (18, 2, 1750.00, 1750.00, '2026-01-31', '2026-01-30', 'recu',  7),
  (19, 1,  600.00,  600.00, '2025-10-15', '2025-10-16', 'recu',  8),
  (20, 2,  600.00,  600.00, '2026-01-31', '2026-01-30', 'recu',  8),
  (21, 1,  900.00,  900.00, '2025-10-15', '2025-10-17', 'recu',  9),
  (22, 2,  900.00,  900.00, '2026-01-31', '2026-01-30', 'recu',  9),
  (23, 1, 1250.00, 1250.00, '2025-10-15', '2025-10-17', 'recu', 10),
  (24, 2, 1250.00, 1250.00, '2026-01-31', '2026-01-30', 'recu', 10);

-- ============================================================================
--  7. TRANSACTIONS — dépenses et recettes de l'exercice en cours
--  Amount est toujours POSITIF : c'est la colonne Type ('depense'|'recette')
--  qui donne le sens, jamais le signe du montant.
--  La transaction 8 est celle générée par un remboursement (voir section 8).
-- ============================================================================
--  Rappel des pôles : 1 Général · 2 Logistique · 3 Event · 4 Tanière · 5 Comm
INSERT INTO transactions (TransactionID, `Type`, Amount, `Date`, Description, Payment_Method, `Status`, Receipt, Notes, CategoryID, PoleID, FiscalYearID, UserID, ClubID) VALUES
  (1, 'depense',  245.50, '2026-09-08', 'Achat composants électroniques',        'virement', 'valide', NULL, NULL,                          1, 2, 2, 2, 2),
  (2, 'depense',   89.90, '2026-09-10', 'Pizzas réunion de rentrée',             'carte',    'valide', NULL, NULL,                          3, 3, 2, 1, 1),
  (3, 'recette', 2000.00, '2026-09-12', 'Subvention BDE — tranche 1',            'virement', 'valide', NULL, 'Versement école reçu',        6, 1, 2, 1, 1),
  (4, 'depense',  156.00, '2026-09-15', 'Location salle pour concert',           'virement', 'valide', NULL, NULL,                          2, 3, 2, 1, 4),
  (5, 'depense',   42.30, '2026-09-16', 'Tirages photo exposition',              'carte',    'valide', NULL, NULL,                          5, 5, 2, 1, 3),
  (6, 'recette',  320.00, '2026-09-17', 'Buvette soirée de rentrée',             'especes',  'valide', NULL, 'Recette nette',               8, 4, 2, 1, 1),
  (7, 'depense',   78.00, '2026-09-18', 'Licences fédération sport',             'virement', 'valide', NULL, NULL,                         10, 1, 2, 1, 5),
  (8, 'depense',   67.00, '2026-09-13', 'Remb. T. Leroy — cordes et médiators',  'virement', 'valide', NULL, 'Généré par le remb. n°3',     1, 2, 2, 1, 4),
  -- ==================================================================
  --  EXERCICE CLOS 2025-2026 (FiscalYearID = 1) — ajouté le 22/09/2026
  --
  --  UNE ÉCRITURE AU MOINS DANS CHACUN DES DOUZE MOIS, à dessein : les
  --  graphiques mensuels et la courbe du solde n'ont d'intérêt que s'il
  --  y a quelque chose à comparer d'un mois sur l'autre. Avec les huit
  --  écritures de l'exercice en cours, toutes en septembre, on ne
  --  voyait qu'une seule colonne remplie.
  --
  --  LES RECETTES SONT VOLONTAIREMENT VARIÉES (subvention, cotisations,
  --  ventes & buvette) : la ventilation des recettes par catégorie est
  --  justement ce qui vient d'être ajouté au tableau de bord, et une
  --  seule catégorie de recette ne prouverait rien.
  --
  --  Le compte démarre NÉGATIF en septembre (−130 €) : les frais de
  --  rentrée tombent avant le premier versement de l'ISEN, qui n'arrive
  --  qu'en octobre. C'est la réalité d'une trésorerie d'association, et
  --  cela permet de vérifier que la courbe passe correctement sous la
  --  ligne du zéro.
  -- ==================================================================
  -- Septembre 2025
  ( 9, 'depense', 2600.00, '2025-09-10', 'Sono et éclairage soirée de rentrée',   'virement', 'valide', NULL, NULL,                        2,  3, 1, 1, 1),
  (10, 'recette', 1850.00, '2025-09-12', 'Buvette soirée de rentrée',             'especes',  'valide', NULL, 'Recette nette',             8,  4, 1, 1, 1),
  (11, 'recette',  940.00, '2025-09-15', 'Cotisations adhésions de rentrée',      'virement', 'valide', NULL, NULL,                        7,  1, 1, 1, 1),
  (12, 'depense',  320.00, '2025-09-22', 'Composants carte moteur',               'carte',    'valide', NULL, NULL,                        1,  2, 1, 2, 2),
  -- Octobre 2025
  (13, 'recette', 5000.00, '2025-10-05', 'Subvention CVEC',                       'virement', 'valide', NULL, 'Dossier accepté',           6,  1, 1, 1, 1),
  (14, 'depense',  780.00, '2025-10-14', 'Affiches et flyers du semestre',        'virement', 'valide', NULL, NULL,                        5,  5, 1, 1, 1),
  (15, 'depense',  210.00, '2025-10-20', 'Papier et encre pour tirages',          'carte',    'valide', NULL, NULL,                        5,  5, 1, 1, 3),
  (16, 'depense',  640.00, '2025-10-22', 'Licences fédération 2025-2026',         'virement', 'valide', NULL, NULL,                       10,  1, 1, 1, 5),
  -- Novembre 2025
  (17, 'depense', 5400.00, '2025-11-08', 'Location salle gala d''automne',        'virement', 'valide', NULL, NULL,                        2,  3, 1, 1, 1),
  (18, 'recette', 3100.00, '2025-11-09', 'Billetterie gala d''automne',           'carte',    'valide', NULL, NULL,                        8,  3, 1, 1, 1),
  (19, 'depense',  430.00, '2025-11-15', 'Entretien instruments et cordes',       'carte',    'valide', NULL, NULL,                        1,  2, 1, 1, 4),
  (20, 'depense',   95.00, '2025-11-28', 'Frais de tenue de compte',              'virement', 'valide', NULL, NULL,                       11,  1, 1, 1, 1),
  -- Décembre 2025
  (21, 'depense', 2900.00, '2025-12-06', 'Goodies et sweats de promotion',        'virement', 'valide', NULL, NULL,                        5,  5, 1, 1, 1),
  (22, 'depense',  890.00, '2025-12-12', 'Imprimante 3D pour l''atelier',         'virement', 'valide', NULL, NULL,                        1,  2, 1, 2, 2),
  (23, 'recette',  610.00, '2025-12-18', 'Vente de sweats',                       'especes',  'valide', NULL, NULL,                        8,  5, 1, 1, 1),
  -- Janvier 2026
  (24, 'depense', 1150.00, '2026-01-14', 'Créneaux gymnase, second semestre',     'virement', 'valide', NULL, NULL,                        2,  2, 1, 1, 5),
  (25, 'depense',  340.00, '2026-01-20', 'Déplacement rencontre inter-écoles',    'virement', 'valide', NULL, NULL,                        4,  2, 1, 1, 1),
  (26, 'depense',  380.00, '2026-01-26', 'Objectif d''occasion',                  'virement', 'valide', NULL, NULL,                        1,  2, 1, 1, 3),
  -- Février 2026
  (27, 'depense', 6800.00, '2026-02-07', 'DJ et sécurité, gala d''hiver',         'virement', 'valide', NULL, NULL,                        2,  3, 1, 1, 1),
  (28, 'recette', 4200.00, '2026-02-08', 'Billetterie gala d''hiver',             'carte',    'valide', NULL, NULL,                        8,  3, 1, 1, 1),
  (29, 'depense',  520.00, '2026-02-19', 'Location salle de répétition',          'virement', 'valide', NULL, NULL,                        2,  2, 1, 1, 4),
  -- Mars 2026
  (30, 'depense', 1420.00, '2026-03-10', 'Pièces robot Coupe de France',          'virement', 'valide', NULL, NULL,                        1,  2, 1, 2, 2),
  (31, 'depense',  260.00, '2026-03-18', 'Buffet réunion inter-clubs',            'carte',    'valide', NULL, NULL,                        3,  3, 1, 1, 1),
  (32, 'recette',  480.00, '2026-03-25', 'Cotisations second semestre',           'virement', 'valide', NULL, NULL,                        7,  1, 1, 1, 1),
  -- Avril 2026
  (33, 'depense',  760.00, '2026-04-08', 'Déplacement Coupe de France',           'virement', 'valide', NULL, NULL,                        4,  2, 1, 2, 2),
  (34, 'depense',  690.00, '2026-04-16', 'Arbitrage tournoi inter-écoles',        'virement', 'valide', NULL, NULL,                        2,  3, 1, 1, 5),
  (35, 'depense',  145.00, '2026-04-22', 'Assurance manifestation',               'virement', 'valide', NULL, NULL,                       10,  1, 1, 1, 1),
  -- Mai 2026
  (36, 'depense', 8900.00, '2026-05-12', 'Location du site, week-end intégration','virement', 'valide', NULL, NULL,                        2,  3, 1, 1, 1),
  (37, 'recette', 2300.00, '2026-05-13', 'Participations week-end intégration',   'virement', 'valide', NULL, NULL,                        8,  3, 1, 1, 1),
  (38, 'depense',  165.00, '2026-05-21', 'Impression expo de fin d''année',       'carte',    'valide', NULL, NULL,                        5,  5, 1, 1, 3),
  -- Juin 2026
  (39, 'depense', 3600.00, '2026-06-11', 'Traiteur soirée de fin d''année',       'virement', 'valide', NULL, NULL,                        3,  3, 1, 1, 1),
  (40, 'depense',  310.00, '2026-06-17', 'Sono concert de fin d''année',          'carte',    'valide', NULL, NULL,                        1,  2, 1, 1, 4),
  (41, 'recette', 1420.00, '2026-06-18', 'Buvette soirée de fin d''année',        'especes',  'valide', NULL, 'Recette nette',             8,  4, 1, 1, 1),
  (42, 'depense',   88.00, '2026-06-26', 'Remb. L. Faure — décoration soirée',    'virement', 'valide', NULL, 'Généré par le remb. n°5',   9,  3, 1, 1, 1),
  -- Juillet et août 2026 : l''activité s''arrête, mais le compte vit encore.
  (43, 'depense',  420.00, '2026-07-03', 'Étagères pour le local',                'carte',    'valide', NULL, NULL,                        1,  4, 1, 1, 1),
  (44, 'depense',   95.00, '2026-08-28', 'Frais de tenue de compte',              'virement', 'valide', NULL, NULL,                       11,  1, 1, 1, 1);

-- ============================================================================
--  8. REIMBURSEMENTS — demandes de remboursement
--  Les 4 statuts possibles sont représentés, pour pouvoir tester chaque cas
--  d'affichage sans avoir à modifier la base à la main :
--    en_attente -> Validation_Date NULL, TransactionID NULL
--    valide     -> traité, mais pas encore payé  -> TransactionID NULL
--    rembourse  -> payé -> TransactionID pointe vers la transaction générée
--    refuse     -> motif dans Treasurer_Notes, TransactionID NULL
--
--  Le bénéficiaire (qui a avancé l'argent) est du texte libre : il peut ne pas
--  avoir de compte dans l'application. UserID est celui qui a SAISI la demande.
-- ============================================================================
INSERT INTO reimbursements (ReimbursementID, Amount, Purchase_Date, Description, `Status`, Treasurer_Notes, Validation_Date, Beneficiary_FirstName, Beneficiary_LastName, Beneficiary_Email, UserID, ClubID, FiscalYearID, CategoryID, PoleID, TransactionID) VALUES
  (1,  45.80, '2026-09-09', 'Câbles et connecteurs (avance personnelle)', 'en_attente',
      NULL,                                                  NULL,
      'Camille', 'Martin', 'camille.martin@example.com', 2, 2, 2, 1, 2, NULL),
  (2,  23.50, '2026-09-11', 'Cartouches d''encre pour affiches',          'valide',
      'Justificatif conforme, à rembourser au prochain virement', '2026-09-14 10:30:00',
      'Lucie', 'Moreau', 'lucie.moreau@example.com',     1, 3, 2, 5, 5, NULL),
  (3,  67.00, '2026-09-05', 'Cordes et médiators pour le local',          'rembourse',
      'Remboursé par virement le 13/09',                     '2026-09-13 09:15:00',
      'Thomas', 'Leroy', 'thomas.leroy@example.com',      1, 4, 2, 1, 2, 8),
  (4, 120.00, '2026-09-06', 'Enceinte portable',                          'refuse',
      'Hors budget cette année — à représenter au prochain exercice', '2026-09-12 14:00:00',
      'Sacha', 'Girard', 'sacha.girard@example.com',      1, 4, 2, 1, 3, NULL),
  -- ------------------------------------------------------------------
  -- EXERCICE CLOS 2025-2026. Aucune demande n'y reste 'en_attente' :
  -- un exercice qu'on clôt en laissant des gens attendre leur argent
  -- n'est pas clos. Les deux issues possibles d'une année terminée sont
  -- donc représentées — remboursé, ou refusé.
  (5,  88.00, '2026-06-20', 'Décoration soirée de fin d''année',          'rembourse',
      'Remboursé par virement le 26/06',                     '2026-06-24 11:00:00',
      'Léa', 'Faure', 'lea.faure@example.com',            1, 1, 1, 9, 3, 42),
  (6,  52.40, '2026-02-03', 'Gobelets réutilisables',                     'refuse',
      'Achat déjà couvert par la commande groupée du BDE',   '2026-02-05 16:20:00',
      'Hugo', 'Blanc', 'hugo.blanc@example.com',          1, 1, 1, 1, 3, NULL);

SET FOREIGN_KEY_CHECKS = 1;   -- réactive les vérifications

-- ============================================================================
--  FIN DU JEU DE DONNÉES
--
--  Résumé de ce qui a été inséré :
--    9 catégories
--    6 clubs (dont 1 archivé, pour tester le filtre IsActive)
--    2 exercices (2026-2027 actif)
--    4 utilisateurs — connexion par EMAIL, mot de passe : password123
--      jean.dupont@example.com     (bureau + admin)
--      camille.martin@example.com  (responsable, club Robotique)
--      alex.bernard@example.com    (bureau, sans droit admin)
--      paul.ancien@example.com     (DÉSACTIVÉ — doit être refusé)
--    5 budgets sur l'exercice en cours
--   12 tranches de versement (5 reçues, 7 prévues)
--    8 transactions
--    4 demandes de remboursement (un statut de chaque)
--
--  VÉRIFICATION RAPIDE (à coller dans l'onglet SQL de phpMyAdmin) :
--    SELECT ClubID, Name, IsActive FROM clubs ORDER BY Name;
--    -> 6 lignes, dont "Club Œnologie" avec IsActive = 0
-- ============================================================================
