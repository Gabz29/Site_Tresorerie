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

-- ============================================================================
--  1. CATEGORIES
--  Type : 'depense' | 'recette' | 'both'
-- ============================================================================
INSERT INTO categories (CategoryID, Name, `Type`) VALUES
  (1, 'Matériel',             'depense'),
  (2, 'Événement',            'depense'),
  (3, 'Nourriture & boissons','depense'),
  (4, 'Transport',            'depense'),
  (5, 'Communication',        'depense'),
  (6, 'Subvention',           'recette'),
  (7, 'Cotisation',           'recette'),
  (8, 'Buvette & ventes',     'recette'),
  (9, 'Divers',               'both');

-- ============================================================================
--  2. CLUBS
--  Le BDE lui-même est enregistré comme un club (ClubID = 1), conformément
--  au commentaire de schema.sql.
--
--  NOTE : le Club Œnologie est volontairement archivé (IsActive = 0). Il sert
--  à vérifier que la requête Club::getAll() filtre bien sur IsActive = 1 :
--  s'il apparaît à l'écran, c'est que le filtre est manquant ou incorrect.
-- ============================================================================
INSERT INTO clubs (ClubID, Name, Description, IsActive) VALUES
  (1, 'BDE ISEN Brest',  'Bureau des Étudiants — gère le budget global et les événements de campus', 1),
  (2, 'Club Robotique',  'Conception de robots pour la Coupe de France de robotique',                1),
  (3, 'Club Photo',      'Ateliers photo, expositions et couverture des événements du campus',       1),
  (4, 'Club Musique',    'Répétitions, concerts et prêt de matériel aux étudiants musiciens',        1),
  (5, 'Club Sport',      'Tournois inter-écoles, licences et location de créneaux sportifs',         1),
  (6, 'Club Œnologie',   'Club dissous en 2025 — conservé pour l''historique comptable',             0);

-- ============================================================================
--  3. FISCALYEAR (exercices : septembre N -> août N+1)
--  Un seul exercice actif à la fois. Nous sommes en septembre 2026, donc
--  l'exercice courant est 2026-2027.
-- ============================================================================
INSERT INTO fiscalyear (FiscalYearID, `Year`, Start_Date, End_Date, IsActive) VALUES
  (1, '2025-2026', '2025-09-01', '2026-08-31', 0),   -- exercice clos
  (2, '2026-2027', '2026-09-01', '2027-08-31', 1);   -- exercice EN COURS

-- ============================================================================
--  4. USERS — les 3 rôles du projet
--
--  IDENTIFIANT DE CONNEXION = l'EMAIL (il n'y a pas de "username").
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
--  Le compte nº4 est volontairement DÉSACTIVÉ (IsActive = 0) : c'est
--  l'ancien trésorier, dont les transactions restent en base. Il sert de cas
--  de test — s'il parvient à se connecter, c'est que le filtre manque.
INSERT INTO users (UserID, Email, Password, Role, LastName, FirstName, IsActive, ClubID) VALUES
  (1, 'jean.dupont@isen-ouest.yncrea.fr',
      '$2y$10$Ye8IARnb0Wm09ynt68U6IuEbXj21edV7UbYOSqM8dxGzivXXmBYBa',
      'tresorier',   'Dupont',  'Jean',    1,    1),
  (2, 'camille.martin@isen-ouest.yncrea.fr',
      '$2y$10$WdpQOvDj4CCxY.WXK7KFBuSjbAqy89/wI1ulqKnksQHBf71t4JAza',
      'responsable', 'Martin',  'Camille', 1,    2),
  (3, 'alex.bernard@isen-ouest.yncrea.fr',
      '$2y$10$aIjirT1moU83qLglTPTx/uiSzXFXGlft2XIM7KSLgQQITl1MyOoaW',
      'admin',       'Bernard', 'Alex',    1, NULL),
  (4, 'paul.ancien@isen-ouest.yncrea.fr',
      '$2y$10$Ye8IARnb0Wm09ynt68U6IuEbXj21edV7UbYOSqM8dxGzivXXmBYBa',
      'tresorier',   'Ancien',  'Paul',    0,    1);   -- bureau précédent

-- ============================================================================
--  5. BUDGETS — enveloppe prévisionnelle, 1 par club et par exercice
--  Disbursement_Count : 4 tranches pour le BDE, 2 pour les clubs.
-- ============================================================================
INSERT INTO budgets (BudgetID, Planned_Amount, Disbursement_Count, Notes, ClubID, FiscalYearID) VALUES
  (1, 8000.00, 4, 'Budget global BDE — événements de campus, gala, intégration', 1, 2),
  (2, 1500.00, 2, 'Composants et déplacement Coupe de France',                   2, 2),
  (3,  600.00, 2, 'Consommables et tirages pour les expositions',                3, 2),
  (4,  900.00, 2, 'Entretien du matériel et location de salles',                 4, 2),
  (5, 1200.00, 2, 'Licences et créneaux sportifs',                               5, 2);

-- ============================================================================
--  6. DISBURSEMENTS — tranches de versement
--  Status 'recu'  -> Actual_Amount et Actual_Date renseignés
--  Status 'prevu' -> les deux restent NULL (versement pas encore reçu)
--  Chaque budget a bien exactement Disbursement_Count lignes.
-- ============================================================================
INSERT INTO disbursements (DisbursementID, `Number`, Planned_Amount, Actual_Amount, Planned_Date, Actual_Date, `Status`, BudgetID) VALUES
  -- Budget 1 : BDE — 4 tranches de 2000 €
  ( 1, 1, 2000.00, 2000.00, '2026-10-01', '2026-10-03', 'recu',  1),
  ( 2, 2, 2000.00,    NULL, '2027-01-05',        NULL, 'prevu',  1),
  ( 3, 3, 2000.00,    NULL, '2027-03-02',        NULL, 'prevu',  1),
  ( 4, 4, 2000.00,    NULL, '2027-05-04',        NULL, 'prevu',  1),
  -- Budget 2 : Robotique — 2 tranches de 750 €
  ( 5, 1,  750.00,  750.00, '2026-10-01', '2026-10-05', 'recu',  2),
  ( 6, 2,  750.00,    NULL, '2027-02-01',        NULL, 'prevu',  2),
  -- Budget 3 : Photo — 2 tranches de 300 €
  ( 7, 1,  300.00,  300.00, '2026-10-01', '2026-10-05', 'recu',  3),
  ( 8, 2,  300.00,    NULL, '2027-02-01',        NULL, 'prevu',  3),
  -- Budget 4 : Musique — 2 tranches de 450 €
  ( 9, 1,  450.00,  450.00, '2026-10-01', '2026-10-06', 'recu',  4),
  (10, 2,  450.00,    NULL, '2027-02-01',        NULL, 'prevu',  4),
  -- Budget 5 : Sport — 2 tranches de 600 €
  (11, 1,  600.00,  600.00, '2026-10-01', '2026-10-06', 'recu',  5),
  (12, 2,  600.00,    NULL, '2027-02-01',        NULL, 'prevu',  5);

-- ============================================================================
--  7. TRANSACTIONS — dépenses et recettes de l'exercice en cours
--  Amount est toujours POSITIF : c'est la colonne Type ('depense'|'recette')
--  qui donne le sens, jamais le signe du montant.
--  La transaction 8 est celle générée par un remboursement (voir section 8).
-- ============================================================================
INSERT INTO transactions (TransactionID, `Type`, Amount, `Date`, Description, Payment_Method, `Status`, Receipt, Notes, CategoryID, FiscalYearID, UserID, ClubID) VALUES
  (1, 'depense',  245.50, '2026-09-08', 'Achat composants électroniques',        'virement', 'valide', NULL, NULL,                          1, 2, 2, 2),
  (2, 'depense',   89.90, '2026-09-10', 'Pizzas réunion de rentrée',             'carte',    'valide', NULL, NULL,                          3, 2, 1, 1),
  (3, 'recette', 2000.00, '2026-09-12', 'Subvention BDE — tranche 1',            'virement', 'valide', NULL, 'Versement école reçu',        6, 2, 1, 1),
  (4, 'depense',  156.00, '2026-09-15', 'Location salle pour concert',           'virement', 'valide', NULL, NULL,                          2, 2, 1, 4),
  (5, 'depense',   42.30, '2026-09-16', 'Tirages photo exposition',              'carte',    'valide', NULL, NULL,                          1, 2, 1, 3),
  (6, 'recette',  320.00, '2026-09-17', 'Buvette soirée de rentrée',             'especes',  'valide', NULL, 'Recette nette',               8, 2, 1, 1),
  (7, 'depense',   78.00, '2026-09-18', 'Licences fédération sport',             'virement', 'valide', NULL, NULL,                          9, 2, 1, 5),
  (8, 'depense',   67.00, '2026-09-13', 'Remb. T. Leroy — cordes et médiators',  'virement', 'valide', NULL, 'Généré par le remb. n°3',     1, 2, 1, 4);

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
INSERT INTO reimbursements (ReimbursementID, Amount, Purchase_Date, Description, `Status`, Treasurer_Notes, Validation_Date, Beneficiary_FirstName, Beneficiary_LastName, Beneficiary_Email, UserID, ClubID, FiscalYearID, CategoryID, TransactionID) VALUES
  (1,  45.80, '2026-09-09', 'Câbles et connecteurs (avance personnelle)', 'en_attente',
      NULL,                                                  NULL,
      'Camille', 'Martin', 'camille.martin@isen-ouest.yncrea.fr', 2, 2, 2, 1, NULL),
  (2,  23.50, '2026-09-11', 'Cartouches d''encre pour affiches',          'valide',
      'Justificatif conforme, à rembourser au prochain virement', '2026-09-14 10:30:00',
      'Lucie', 'Moreau', 'lucie.moreau@isen-ouest.yncrea.fr',     1, 3, 2, 5, NULL),
  (3,  67.00, '2026-09-05', 'Cordes et médiators pour le local',          'rembourse',
      'Remboursé par virement le 13/09',                     '2026-09-13 09:15:00',
      'Thomas', 'Leroy', 'thomas.leroy@isen-ouest.yncrea.fr',      1, 4, 2, 1, 8),
  (4, 120.00, '2026-09-06', 'Enceinte portable',                          'refuse',
      'Hors budget cette année — à représenter au prochain exercice', '2026-09-12 14:00:00',
      'Sacha', 'Girard', 'sacha.girard@isen-ouest.yncrea.fr',      1, 4, 2, 1, NULL);

SET FOREIGN_KEY_CHECKS = 1;   -- réactive les vérifications

-- ============================================================================
--  FIN DU JEU DE DONNÉES
--
--  Résumé de ce qui a été inséré :
--    9 catégories
--    6 clubs (dont 1 archivé, pour tester le filtre IsActive)
--    2 exercices (2026-2027 actif)
--    4 utilisateurs — connexion par EMAIL, mot de passe : password123
--      jean.dupont@isen-ouest.yncrea.fr     (tresorier)
--      camille.martin@isen-ouest.yncrea.fr  (responsable, club Robotique)
--      alex.bernard@isen-ouest.yncrea.fr    (admin)
--      paul.ancien@isen-ouest.yncrea.fr     (DÉSACTIVÉ — doit être refusé)
--    5 budgets sur l'exercice en cours
--   12 tranches de versement (5 reçues, 7 prévues)
--    8 transactions
--    4 demandes de remboursement (un statut de chaque)
--
--  VÉRIFICATION RAPIDE (à coller dans l'onglet SQL de phpMyAdmin) :
--    SELECT ClubID, Name, IsActive FROM clubs ORDER BY Name;
--    -> 6 lignes, dont "Club Œnologie" avec IsActive = 0
-- ============================================================================
