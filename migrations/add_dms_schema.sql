-- ============================================================================
-- CADViz commit / element-graph schema (Phase 1).
--
-- SUPERSEDES the earlier Documents/Document_Sheets design (this file used to
-- carry that schema; it's been rewritten in place after the architecture
-- pivot to git-backed IFC versioning + Revit .NNNN.rvt backups as the binary
-- truth source).
--
-- Architecture summary:
--   • Live .rvt files          → staff's mounted Google Drive folder
--   • Historical .rvt backups  → same folder (Revit's auto-backup numbering)
--   • IFC history              → bare git repo per project on the CADViz host
--   • PDFs published at commit → Drive, referenced by sha256 in Blobs
--   • Structured metadata      → these tables
--
-- A "Commit" is the curated meaningful checkpoint — staff explicitly publish
-- it from the CADViz UI (Phase 2 Revit add-in will fire it on save-with-flag).
-- Each Commit points at:
--   • The .rvt backup number in Drive (for high-fidelity reproduction)
--   • A git SHA in the project's bare repo (for semantic IFC history)
--   • A set of output blobs (PDFs etc.) by sha256
--
-- Per-project opt-in: Projects.dms_active = 0 by default. Existing projects
-- stay invisible until staff opt them in via the activate-DMS wizard (Phase
-- 1 ships the flag + manual import path; auto-import wizard comes later).
-- ============================================================================

-- ── Drop the old DMS-v1 tables if they exist (Documents/Document_Sheets +
--    the Transmittals/Recipients/Comments rows that pointed at them).
--    Stakeholders survives — its shape didn't change.
-- Only the genuinely-obsolete v1 tables are dropped. Transmittals /
-- Transmittal_Recipients / Commit_Comments are CREATE TABLE IF NOT EXISTS
-- below — we must NOT drop those, or re-running this file would wipe
-- transmittal + ack + comment history. (The original v1→v2 cut-over did
-- drop them because they carried a Document_ID FK; that migration window
-- has passed — anyone on v2 has Commit_ID-based rows worth keeping.)
DROP TABLE IF EXISTS Document_Sheets;
DROP TABLE IF EXISTS Documents;

-- ── Project_Stakeholders: the 3rd parties tied to a project ──────────────
-- Unchanged from v1. Roles drive coverage rule "notify these stakeholder
-- types" actions. Free-text Role_Label allows naming specific manufacturer
-- reps ("James Hardie cladding rep") alongside the categorical Role.
CREATE TABLE IF NOT EXISTS Project_Stakeholders (
    Stakeholder_ID  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Proj_ID         INT NOT NULL,
    Role            VARCHAR(50) NOT NULL,
        -- structural / geotech / civil / fire
        -- / manufacturer_cladding / manufacturer_joinery / manufacturer_membrane
        -- / manufacturer_roofing / manufacturer_other
        -- / weathertightness / energy_h1 / client / council / consultant / other
    Role_Label      VARCHAR(100),
    Name            VARCHAR(255) NOT NULL,
    Email           VARCHAR(255) NOT NULL,
    Phone           VARCHAR(50),
    Company         VARCHAR(255),
    Notes           TEXT,
    Active          TINYINT NOT NULL DEFAULT 1,
    Added_By        VARCHAR(50),
    Added_At        DATETIME NOT NULL,
    INDEX (Proj_ID),
    INDEX (Email),
    INDEX (Role)
) ENGINE=InnoDB;

-- ── Commits: the curated checkpoint ──────────────────────────────────────
-- Each commit pins:
--   • Rvt_Backup_Number — the project.NNNN.rvt file in the Drive folder
--   • Ifc_Git_Sha       — the matching IFC state in the bare git repo
--   • A row in Commit_Blobs per output PDF/DWG etc. published at this revision
--
-- Status enum:
--   wip               (draft, may be amended)
--   issued            (sent to 3rd parties — locks the revision letter)
--   for_council       (formal council submission)
--   for_construction  (issued for construction)
--   for_review        (issued for stakeholder review only, no acks required yet)
--   superseded        (a later commit replaces this one)
CREATE TABLE IF NOT EXISTS Commits (
    Commit_ID           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Proj_ID             INT NOT NULL,
    Parent_Commit_ID    INT NULL,                 -- prior commit in this project's history (linear)
    Variation_ID        INT NULL,                 -- if this commit belongs to a variation sub-line (NULL = trunk)
    Message             TEXT NOT NULL,            -- staff-authored description (also feeds the timesheet row)
    Author_UserID       VARCHAR(50),              -- CADViz session UserID at commit time
    Created_At          DATETIME NOT NULL,
    Rvt_Backup_Number   INT,                      -- e.g. 42 → corresponds to project.0042.rvt
    Rvt_Backup_Path     VARCHAR(500),             -- full Drive-relative path to the backup
    Ifc_Git_Sha         CHAR(40),                 -- SHA in the project's bare git repo for the IFC at this commit
    Status              VARCHAR(30) NOT NULL DEFAULT 'wip',
    Description         VARCHAR(255),             -- short title (revision letter, e.g. "Rev C — For Council")
    Revision_Label      VARCHAR(10),              -- 'A','B','C',... or 'P1','P2' for preliminary
    Is_Legacy           TINYINT NOT NULL DEFAULT 0,    -- 1 = imported pre-system, no transmittal audit trail before this point
    Is_Superseded       TINYINT NOT NULL DEFAULT 0,
    Notes               TEXT,
    INDEX (Proj_ID),
    INDEX (Parent_Commit_ID),
    INDEX (Created_At),
    INDEX (Variation_ID)
) ENGINE=InnoDB;

-- ── Blobs: content-addressed reference to actual file bytes ──────────────
-- Bytes can live in Drive (Drive_File_ID set) or in the local shadow archive
-- (Filesystem_Path set). For Phase 1 PDFs/DWGs sit in Drive; the shadow
-- archive is reserved for backups of .rvt files about to age out of Revit's
-- max-backups cap (Phase 1 doesn't implement the shadow-archive worker;
-- column exists for forward compatibility).
CREATE TABLE IF NOT EXISTS Blobs (
    Sha256              CHAR(64) NOT NULL PRIMARY KEY,
    Drive_File_ID       VARCHAR(100) NULL,
    Drive_Url           TEXT NULL,
    Filesystem_Path     VARCHAR(500) NULL,        -- relative to CADVIZ_BLOB_ARCHIVE_PATH, NULL if Drive-only
    Size_Bytes          BIGINT,
    Content_Type        VARCHAR(100),
    Page_Count          INT NULL,                 -- PDFs only
    First_Seen_At       DATETIME NOT NULL,
    INDEX (Drive_File_ID)
) ENGINE=InnoDB;

-- ── Commit_Blobs: which blobs were published as part of this commit ──────
-- Role enum distinguishes the .rvt backup ref from output PDFs etc.
-- Path_In_Project is the canonical name within the project folder
-- (e.g. "drawings/A-2.01_Ground_Floor.pdf").
CREATE TABLE IF NOT EXISTS Commit_Blobs (
    Commit_Blob_ID      INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID           INT NOT NULL,
    Blob_Sha256         CHAR(64) NOT NULL,
    Path_In_Project     VARCHAR(500) NOT NULL,
    Role                VARCHAR(30) NOT NULL,
        -- rvt_backup / ifc / pdf_output / dwg_output / source_other
    INDEX (Commit_ID),
    INDEX (Blob_Sha256)
) ENGINE=InnoDB;

-- ── Element_Instances: per-commit element snapshot from IFC parsing ──────
-- Populated by the client-side web-ifc parser (cadviz-bim/src/lib/ifc/parser.worker.ts)
-- which POSTs the structured manifest to api/commit_create.php. Ifc_Guid is IFC's
-- 22-char compressed GlobalId — stable across exports of the same element
-- in the same project, which is what makes element-level diff possible.
-- Category is the vendor-neutral classification ("Wall", "Door", "Window",
-- "Roof", "Stair", "Foundation", "BracingElement", …) derived from the IFC
-- entity type — this is the column coverage rules predicate on (so rules
-- stay portable across whatever future source we extract from).
CREATE TABLE IF NOT EXISTS Element_Instances (
    Element_Instance_ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID           INT NOT NULL,
    Source_Blob_Sha256  CHAR(64),                 -- which IFC blob this element was extracted from
    Ifc_Guid            CHAR(22) NOT NULL,        -- IFC GlobalId (compressed)
    Ifc_Entity_Type     VARCHAR(50),              -- raw IFC type: IfcWall, IfcDoor, IfcSlab, …
    Category            VARCHAR(50),              -- normalised: Wall, Door, Window, Roof, Slab, Stair, Foundation, BracingElement, Column, Beam, Covering, Space, Member, Other
    Name                VARCHAR(255),
    Type_Name           VARCHAR(255),             -- e.g. "Generic - 200mm"
    Level_Name          VARCHAR(100),             -- storey name from IfcBuildingStorey (NULL if not in a storey)
    -- Axis-aligned bounding box in model units (whatever IfcUnit the file declares — usually mm or m).
    -- Six DOUBLEs not a JSON column so spatial range queries are indexable and cheap.
    Bbox_Min_X          DOUBLE,
    Bbox_Min_Y          DOUBLE,
    Bbox_Min_Z          DOUBLE,
    Bbox_Max_X          DOUBLE,
    Bbox_Max_Y          DOUBLE,
    Bbox_Max_Z          DOUBLE,
    INDEX (Commit_ID),
    INDEX (Ifc_Guid),
    INDEX (Category),
    INDEX (Commit_ID, Category),
    INDEX (Commit_ID, Level_Name)
) ENGINE=InnoDB;

-- ── Element_Parameters: key-value parameters on an element ───────────────
-- IFC PSet flattened into rows. Param_Value is TEXT for flexibility (scalars
-- stringified; complex values JSON-encoded). Coverage rules query by
-- (Element_Instance_ID, Param_Name) and compare values across commits.
CREATE TABLE IF NOT EXISTS Element_Parameters (
    Element_Parameter_ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Element_Instance_ID  INT NOT NULL,
    Param_Set            VARCHAR(100),            -- IFC PSet name (Pset_WallCommon, Pset_DoorCommon, …)
    Param_Name           VARCHAR(100) NOT NULL,
    Param_Value          TEXT,
    Units                VARCHAR(20),
    INDEX (Element_Instance_ID),
    INDEX (Param_Name),
    INDEX (Element_Instance_ID, Param_Name)
) ENGINE=InnoDB;

-- ── Element_Relationships: structural links between elements ─────────────
-- "wall A hosts window B", "space C contains door D", etc. Coverage rules
-- can use this to traverse: "if any exterior wall changed → notify
-- weathertightness; if any wall hosting a window changed → also check
-- the window's E2 detail".
CREATE TABLE IF NOT EXISTS Element_Relationships (
    Element_Relationship_ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID                INT NOT NULL,
    Source_Element_Ifc_Guid  CHAR(22) NOT NULL,
    Target_Element_Ifc_Guid  CHAR(22) NOT NULL,
    Relationship_Type        VARCHAR(30) NOT NULL,    -- contains / hosts / references / opens_in / bounds / connects_to
    INDEX (Commit_ID),
    INDEX (Source_Element_Ifc_Guid),
    INDEX (Target_Element_Ifc_Guid)
) ENGINE=InnoDB;

-- ── NZBC_Clauses: catalogue of NZ Building Code clauses we tag ───────────
-- Seeded by add_coverage_seed.sql. Default_Stakeholder_Roles_CSV defines
-- which stakeholder roles get auto-suggested when this clause is tagged.
CREATE TABLE IF NOT EXISTS NZBC_Clauses (
    Clause_Code                  VARCHAR(10) NOT NULL PRIMARY KEY,
    Title                        VARCHAR(255) NOT NULL,
    Description                  TEXT,
    Acceptable_Solutions         TEXT,                -- "B1/AS1, NZS 3604, NZS 1170" etc.
    Default_Stakeholder_Roles_CSV VARCHAR(255)
) ENGINE=InnoDB;

-- ── Coverage_Rules: the if-X-changes-then-Y-Z library ────────────────────
-- Trigger_Selector and Action_Payload are JSON for flexibility. The Phase 1
-- commit-handler reads these and fires matching rules against the diff
-- manifest produced by the IFC parser.
--
-- Trigger_Type enum:
--   element_added       — a new element of matching category appears
--   element_removed     — an existing element of matching category disappears
--   element_modified    — any param of the element changed
--   param_changed       — a specific param on a matching element changed
--   category_present    — on first commit only: any element of this category exists
--
-- Action_Type enum:
--   tag_nzbc            — auto-tick the listed NZBC clauses
--   notify_role         — pre-tick stakeholders in these roles for the transmittal
--   flag_review         — surface as "review needed" in the commit confirmation UI
CREATE TABLE IF NOT EXISTS Coverage_Rules (
    Coverage_Rule_ID            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Name                        VARCHAR(255) NOT NULL,
    Trigger_Type                VARCHAR(30) NOT NULL,
    Trigger_Selector            TEXT,                -- JSON: {"category":"Wall","param":"LoadBearing","to_value":"true"}
    Action_Type                 VARCHAR(30) NOT NULL,
    Action_Payload              TEXT,                -- JSON: {"clauses":["B1"],"roles":["structural"]}
    Nzbc_Clauses_CSV            VARCHAR(255),        -- denormalised for fast filter / display
    Default_Stakeholder_Roles_CSV VARCHAR(255),
    Notes                       TEXT,
    Active                      TINYINT NOT NULL DEFAULT 1,
    Created_By                  VARCHAR(50),
    Created_At                  DATETIME NOT NULL,
    INDEX (Active),
    INDEX (Trigger_Type)
) ENGINE=InnoDB;

-- ── Coverage_Rule_Firings: log of which rules fired on which commits ─────
-- Used for (a) audit, (b) training data — Disposition lets staff/engineer
-- mark each firing as accepted/rejected, giving us labelled examples for
-- the Phase 5 classifier.
CREATE TABLE IF NOT EXISTS Coverage_Rule_Firings (
    Firing_ID           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID           INT NOT NULL,
    Coverage_Rule_ID    INT NOT NULL,
    Element_Instance_ID INT NULL,                    -- which specific element triggered (where applicable)
    Element_Ifc_Guid    CHAR(22) NULL,
    Fired_At            DATETIME NOT NULL,
    Disposition         VARCHAR(20) NOT NULL DEFAULT 'pending',
        -- pending / accepted / rejected / superseded
    Disposition_By      VARCHAR(50),
    Disposition_At      DATETIME,
    Disposition_Notes   TEXT,
    INDEX (Commit_ID),
    INDEX (Coverage_Rule_ID),
    INDEX (Disposition)
) ENGINE=InnoDB;

-- ── Commit_NZBC_Tags: which clauses each commit affects + provenance ─────
-- Source enum: rule (auto-suggested by a coverage rule firing) /
--              staff (ticked by the person committing) /
--              engineer (added or confirmed by a stakeholder via ack) /
--              erik (admin override).
-- Confidence is for rule-fired tags only (rule's confidence); human tags
-- get 1.00.
CREATE TABLE IF NOT EXISTS Commit_NZBC_Tags (
    Tag_ID          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID       INT NOT NULL,
    Clause_Code     VARCHAR(10) NOT NULL,
    Source          VARCHAR(20) NOT NULL,
    Confidence      DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    Tagged_By       VARCHAR(50),
    Tagged_At       DATETIME NOT NULL,
    Notes           TEXT,
    INDEX (Commit_ID),
    INDEX (Clause_Code),
    UNIQUE INDEX (Commit_ID, Clause_Code, Source)
) ENGINE=InnoDB;

-- ── Transmittals: one "I sent the package to these stakeholders" event ───
-- Now Commit-scoped (was Document-scoped in DMS v1). Revision_Label is
-- snapshotted at send time because Commits.Revision_Label may change later.
CREATE TABLE IF NOT EXISTS Transmittals (
    Transmittal_ID  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID       INT NOT NULL,
    Revision_Label  VARCHAR(10),
    Sent_At         DATETIME NOT NULL,
    Sent_By         VARCHAR(50),
    Subject         VARCHAR(255),
    Message         TEXT,
    INDEX (Commit_ID),
    INDEX (Sent_At)
) ENGINE=InnoDB;

-- ── Transmittal_Recipients: per-stakeholder magic link + ack state ───────
-- Unchanged from DMS v1 except FK target.
-- Stakeholder_ID is NULLABLE: a recipient is either a project stakeholder
-- (Stakeholder_ID set) OR an ad-hoc address (Ad_Hoc_Email set, e.g. a
-- one-off builder/consultant emailed via the manual-email + magic-link
-- path). add_adhoc_recipients.sql back-fills this on installs created
-- before the column was nullable.
CREATE TABLE IF NOT EXISTS Transmittal_Recipients (
    Recipient_ID    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Transmittal_ID  INT NOT NULL,
    Stakeholder_ID  INT NULL,
    Ad_Hoc_Email    VARCHAR(255) NULL,
    Ad_Hoc_Name     VARCHAR(255) NULL,
    Magic_Token     CHAR(64) NOT NULL,
    Sent_At         DATETIME NOT NULL,
    First_Viewed_At DATETIME,
    Last_Viewed_At  DATETIME,
    View_Count      INT NOT NULL DEFAULT 0,
    Acked_At        DATETIME,
    Ack_Comment     TEXT,
    INDEX (Transmittal_ID),
    INDEX (Stakeholder_ID),
    UNIQUE INDEX (Magic_Token)
) ENGINE=InnoDB;

-- ── Commit_Comments: free-text annotations on a commit's revision ────────
-- Stakeholders post via the magic-link page; staff post via the commit
-- detail page. Author_Name/Email denormalised so display survives stakeholder
-- removal/edit.
CREATE TABLE IF NOT EXISTS Commit_Comments (
    Comment_ID      INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Commit_ID       INT NOT NULL,
    Stakeholder_ID  INT,                            -- NULL = staff/admin comment
    Author_Name     VARCHAR(255),
    Author_Email    VARCHAR(255),
    Body            TEXT NOT NULL,
    Posted_At       DATETIME NOT NULL,
    Posted_From_IP  VARCHAR(45),
    Page_Number     INT,                            -- optional: comment is page-specific (PDF page)
    INDEX (Commit_ID),
    INDEX (Stakeholder_ID)
) ENGINE=InnoDB;

-- ── Projects column additions (idempotent via INFORMATION_SCHEMA dance) ──
SET @col_dms := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Projects'
     AND COLUMN_NAME  = 'dms_active'
);
SET @sql_dms := IF(@col_dms = 0,
  'ALTER TABLE Projects ADD COLUMN dms_active TINYINT NOT NULL DEFAULT 0 AFTER Active',
  'SELECT 1');
PREPARE s1 FROM @sql_dms; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @col_drv := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Projects'
     AND COLUMN_NAME  = 'drive_folder_id'
);
SET @sql_drv := IF(@col_drv = 0,
  'ALTER TABLE Projects ADD COLUMN drive_folder_id VARCHAR(100) NULL AFTER dms_active',
  'SELECT 1');
PREPARE s2 FROM @sql_drv; EXECUTE s2; DEALLOCATE PREPARE s2;

-- New: where the per-project bare git repo lives on the CADViz host. Path
-- is relative to CADVIZ_GIT_REPOS_PATH (see config.cadviz.sample.php) so the
-- value here is just "<proj_id>.git" or a project-name slug — keeps the row
-- portable if the repos directory moves.
SET @col_git := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Projects'
     AND COLUMN_NAME  = 'git_repo_path'
);
SET @sql_git := IF(@col_git = 0,
  'ALTER TABLE Projects ADD COLUMN git_repo_path VARCHAR(500) NULL AFTER drive_folder_id',
  'SELECT 1');
PREPARE s3 FROM @sql_git; EXECUTE s3; DEALLOCATE PREPARE s3;
