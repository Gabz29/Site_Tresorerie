-- ============================================================================
--  BASE DE DONNÉES — JUMÃO (Trésorerie BDE ISEN)
--  SGBD cible : MySQL 8+  /  À importer via phpMyAdmin
--  Encodage   : UTF-8 (utf8mb4) pour gérer accents et emojis
--
--  Ordre de création : les tables "parentes" (sans dépendance) d'abord,
--  puis les tables qui les référencent. On termine par la FK circulaire
--  reimbursements <-> transactions, ajoutée en ALTER à la fin.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;   -- désactive temporairement pour l'import

-- ============================================================================
--  1. CATEGORIES  (aucune dépendance)
-- ============================================================================
CREATE TABLE categories (
  CategoryID   INT           NOT NULL AUTO_INCREMENT,
  Name         VARCHAR(50)   NOT NULL,
  Type         VARCHAR(10)   NOT NULL,          -- 'depense' | 'recette' | 'both'
  CONSTRAINT categories_PK PRIMARY KEY (CategoryID),
  CONSTRAINT categories_Name_UQ UNIQUE (Name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  2. CLUBS  (le BDE lui-même est enregistré comme un club particulier)
-- ============================================================================
CREATE TABLE clubs (
  ClubID       INT           NOT NULL AUTO_INCREMENT,
  Name         VARCHAR(50)   NOT NULL,
  Description  TEXT          NULL,              -- optionnel
  IsActive     TINYINT(1)    NOT NULL DEFAULT 1,-- 1=actif, 0=archivé/dissous
  CONSTRAINT clubs_PK PRIMARY KEY (ClubID),
  CONSTRAINT clubs_Name_UQ UNIQUE (Name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  3. FISCALYEAR  (exercices budgétaires : septembre N -> août N+1)
-- ============================================================================
CREATE TABLE fiscalyear (
  FiscalYearID INT           NOT NULL AUTO_INCREMENT,
  Year         VARCHAR(10)   NOT NULL,          -- ex: '2024-2025'
  Start_Date   DATE          NOT NULL,          -- sert à rattacher une transaction
  End_Date     DATE          NOT NULL,          --   à l'exercice via sa date
  IsActive     TINYINT(1)    NOT NULL DEFAULT 0,-- un seul exercice actif à la fois
  CONSTRAINT fiscalyear_PK PRIMARY KEY (FiscalYearID),
  CONSTRAINT fiscalyear_Year_UQ UNIQUE (Year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  4. USERS  (un utilisateur gère 0 ou 1 club -> ClubID nullable)
-- ============================================================================
--     L'EMAIL SERT D'IDENTIFIANT DE CONNEXION (il n'y a pas de "username") :
--     une adresse de moins à retenir pour les membres du bureau, unicité
--     naturelle, et c'est la seule façon d'envisager plus tard une
--     réinitialisation de mot de passe par mail. Il est donc NOT NULL et
--     UNIQUE, alors qu'il était optionnel dans la première version.
--
--     IsActive : le bureau change chaque année. On ne peut PAS supprimer le
--     compte d'un ancien membre — les transactions qu'il a saisies pointent
--     vers son UserID par une clé étrangère, et l'effacer détruirait
--     l'historique comptable, qui est précisément ce que l'application doit
--     conserver. On DÉSACTIVE donc le compte : il garde ses liens, mais ne
--     permet plus de se connecter.
--
--     Role : un NIVEAU D'ACCÈS, pas un titre. 'bureau' est porté par le
--     Président, le Vice-Président ET le Trésorier du BDE — tous trois ont
--     besoin de voir et gérer l'ensemble des clubs. Le rôle ne s'appelle
--     donc pas 'tresorier' : il décrit ce qu'on peut faire, pas la fonction
--     qu'on occupe.
--
--     IsAdmin : gérer les comptes utilisateurs est une PERMISSION qui
--     s'ajoute au rôle, et non un rôle à part. Sans cela, le trésorier BDE
--     devrait posséder un second compte pour créer des accès — impossible,
--     puisque son email sert déjà d'identifiant au premier. Avec une simple
--     colonne, il reste connecté sous son identité et l'historique dit
--     « Jean Dupont a créé le compte de X » plutôt qu'un anonyme « admin ».
CREATE TABLE users (
  UserID       INT           NOT NULL AUTO_INCREMENT,
  Email        VARCHAR(250)  NOT NULL,          -- identifiant de connexion
  Password     VARCHAR(250)  NOT NULL,          -- haché avec password_hash()
  Role         VARCHAR(20)   NOT NULL,          -- 'bureau' | 'responsable'
  LastName     VARCHAR(50)   NOT NULL,
  FirstName    VARCHAR(50)   NOT NULL,
  IsAdmin      TINYINT(1)    NOT NULL DEFAULT 0,-- 1=peut gérer les comptes
  IsActive     TINYINT(1)    NOT NULL DEFAULT 1,-- 1=peut se connecter, 0=compte clos
  Created_At   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- ClubID = LE CLUB AUQUEL L'ACCÈS EST LIMITÉ, et rien d'autre.
  -- NULL = aucune limite : c'est le cas de tous les membres du bureau,
  -- qui voient l'ensemble des clubs.
  -- Ne PAS y mettre le club de rattachement d'un membre du bureau : la
  -- colonne voudrait alors dire deux choses selon le rôle, et en lisant la
  -- table on croirait son accès restreint alors qu'il ne l'est pas.
  ClubID       INT           NULL,
  CONSTRAINT users_PK PRIMARY KEY (UserID),
  CONSTRAINT users_Email_UQ UNIQUE (Email),
  CONSTRAINT users_ClubID_FK FOREIGN KEY (ClubID) REFERENCES clubs (ClubID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  5. BUDGETS  (enveloppe annuelle prévue ; 1 seul par club et par exercice)
-- ============================================================================
CREATE TABLE budgets (
  BudgetID           INT           NOT NULL AUTO_INCREMENT,
  Planned_Amount     DECIMAL(10,2) NOT NULL,    -- prévision de début d'année
  Disbursement_Count INT           NOT NULL DEFAULT 2, -- 2 clubs / 4 BDE
  Notes              TEXT          NULL,         -- optionnel
  ClubID             INT           NOT NULL,
  FiscalYearID       INT           NOT NULL,
  CONSTRAINT budgets_PK PRIMARY KEY (BudgetID),
  CONSTRAINT budgets_ClubID_FK       FOREIGN KEY (ClubID)       REFERENCES clubs (ClubID),
  CONSTRAINT budgets_FiscalYearID_FK FOREIGN KEY (FiscalYearID) REFERENCES fiscalyear (FiscalYearID),
  -- Garantit qu'un club n'a qu'UN SEUL budget par exercice :
  CONSTRAINT budgets_Club_Year_UQ UNIQUE (ClubID, FiscalYearID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  6. DISBURSEMENTS  (tranches de versement ; réel NULL tant que non reçu)
--
--     Rythme habituel : clubs = mi-octobre et fin janvier (2 tranches) ;
--     BDE = octobre, décembre, février, juin (4 tranches). Le BDE encaisse
--     la CVEC en octobre, puis répartit et échelonne vers les clubs.
--
--     Status 'annule' : le BDE se réserve le droit de NE PAS verser une
--     tranche à un club inactif, et de réattribuer la somme à un club
--     motivé. Sans ce troisième état, il faudrait soit laisser la tranche
--     éternellement en 'prevu' (elle gonflerait indéfiniment le budget
--     théorique du club), soit la supprimer — et perdre la trace de la
--     décision. Notes sert à en garder le motif.
-- ============================================================================
CREATE TABLE disbursements (
  DisbursementID INT           NOT NULL AUTO_INCREMENT,
  Number         INT           NOT NULL,        -- n° de tranche : 1,2,3,4
  Planned_Amount DECIMAL(10,2) NOT NULL,        -- prévu pour cette tranche
  Actual_Amount  DECIMAL(10,2) NULL,            -- reçu (NULL si pas encore versé)
  Planned_Date   DATE          NOT NULL,
  Actual_Date    DATE          NULL,            -- NULL tant que pas reçu
  Status         VARCHAR(20)   NOT NULL DEFAULT 'prevu', -- 'prevu'|'recu'|'annule'
  Notes          TEXT          NULL,            -- motif d'annulation, remarques
  BudgetID       INT           NOT NULL,
  CONSTRAINT disbursements_PK PRIMARY KEY (DisbursementID),
  CONSTRAINT disbursements_BudgetID_FK FOREIGN KEY (BudgetID) REFERENCES budgets (BudgetID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  7. TRANSACTIONS  (dépenses et recettes ; table centrale)
--     NB : la FK vers reimbursements est ajoutée plus bas (ALTER), car les
--     deux tables se référencent mutuellement.
-- ============================================================================
CREATE TABLE transactions (
  TransactionID  INT           NOT NULL AUTO_INCREMENT,
  Type           VARCHAR(10)   NOT NULL,        -- 'depense' | 'recette'
  Amount         DECIMAL(10,2) NOT NULL,        -- DECIMAL, jamais INT/FLOAT !
  Date           DATE          NOT NULL,
  Description    VARCHAR(255)  NOT NULL,
  Payment_Method VARCHAR(20)   NULL,            -- virement|carte|especes|cheque
  Status         VARCHAR(20)   NOT NULL DEFAULT 'valide',
  Receipt        VARCHAR(250)  NULL,            -- fichier justificatif (optionnel)
  Notes          TEXT          NULL,            -- optionnel
  Created_At     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CategoryID     INT           NULL,
  FiscalYearID   INT           NOT NULL,
  UserID         INT           NOT NULL,        -- créateur de la ligne
  ClubID         INT           NOT NULL,        -- BDE = un club
  CONSTRAINT transactions_PK PRIMARY KEY (TransactionID),
  CONSTRAINT transactions_CategoryID_FK   FOREIGN KEY (CategoryID)   REFERENCES categories (CategoryID),
  CONSTRAINT transactions_FiscalYearID_FK FOREIGN KEY (FiscalYearID) REFERENCES fiscalyear (FiscalYearID),
  CONSTRAINT transactions_UserID_FK       FOREIGN KEY (UserID)       REFERENCES users (UserID),
  CONSTRAINT transactions_ClubID_FK       FOREIGN KEY (ClubID)       REFERENCES clubs (ClubID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
--  8. REIMBURSEMENTS  (demandes de remboursement)
--     - saisi par un membre du bureau (UserID)
--     - bénéficiaire = qui a avancé l'argent (texte libre, peut ne pas avoir de compte)
--     - TransactionID (nullable) = transaction générée une fois remboursé  [option B]
-- ============================================================================
CREATE TABLE reimbursements (
  ReimbursementID       INT           NOT NULL AUTO_INCREMENT,
  Amount                DECIMAL(10,2) NOT NULL,
  Purchase_Date         DATE          NOT NULL, -- date de l'achat avancé
  Description           VARCHAR(250)  NOT NULL,
  Status                VARCHAR(20)   NOT NULL DEFAULT 'en_attente', -- en_attente|valide|refuse|rembourse
  Treasurer_Notes       TEXT          NULL,     -- optionnel
  Validation_Date       DATETIME      NULL,     -- NULL tant que pas traité
  Beneficiary_FirstName VARCHAR(50)   NOT NULL, -- qui a avancé l'argent
  Beneficiary_LastName  VARCHAR(100)  NOT NULL,
  Beneficiary_Email     VARCHAR(250)  NULL,
  UserID                INT           NOT NULL, -- qui a saisi la demande
  ClubID                INT           NOT NULL,
  FiscalYearID          INT           NOT NULL,
  CategoryID            INT           NULL,
  TransactionID         INT           NULL,     -- [option B] généré si remboursé
  CONSTRAINT reimbursements_PK PRIMARY KEY (ReimbursementID),
  CONSTRAINT reimbursements_UserID_FK        FOREIGN KEY (UserID)        REFERENCES users (UserID),
  CONSTRAINT reimbursements_ClubID_FK        FOREIGN KEY (ClubID)        REFERENCES clubs (ClubID),
  CONSTRAINT reimbursements_FiscalYearID_FK  FOREIGN KEY (FiscalYearID)  REFERENCES fiscalyear (FiscalYearID),
  CONSTRAINT reimbursements_CategoryID_FK    FOREIGN KEY (CategoryID)    REFERENCES categories (CategoryID),
  CONSTRAINT reimbursements_TransactionID_FK FOREIGN KEY (TransactionID) REFERENCES transactions (TransactionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;   -- réactive les vérifications

-- ============================================================================
--  FIN DU SCHÉMA
--  Rappels de logique métier gérés côté PHP (pas au niveau BDD) :
--   - Type/Status : valeurs contrôlées dans le code (ou passer en ENUM plus tard)
--   - Un budget doit avoir exactement Disbursement_Count lignes dans disbursements
--   - La transaction générée par un remboursement reprend le ClubID du remboursement
--   - L'exercice d'une transaction est déduit de sa Date (Start_Date <= Date <= End_Date)
-- ============================================================================