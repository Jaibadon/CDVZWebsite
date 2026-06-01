-- ============================================================================
-- Keynote → Building-Code mapping (the "train it" layer for coverage).
--
-- Two simple tables Erik manages from coverage_admin.php:
--   1. NZBC_Clauses.Default_Stakeholder_Roles_CSV  — "clause → who to notify"
--      (B1 → structural, C* → fire, E2 → weathertightness, ...). Seeded below.
--   2. Keynote_Clause_Map                          — "keynote category → clause"
--      (BUILDING WRAP/RAB (WALL) → E2, CONCRETE SLAB/FOUNDATIONS → B1, ...).
--
-- The coverage engine joins them: a changed element → its keynote code →
-- category → clause (this table) → roles (the clause's default) → notify.
--
-- Idempotent. Apply via phpMyAdmin SQL tab.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Keynote_Clause_Map (
    Map_ID           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Match_Type       VARCHAR(20)  NOT NULL DEFAULT 'category',  -- category | code_prefix | code
    Match_Value      VARCHAR(255) NOT NULL,                     -- e.g. 'BUILDING WRAP/RAB (WALL)' / 'WR' / 'WR.01'
    Clause_Code      VARCHAR(10)  NOT NULL,
    Notify_Roles_CSV VARCHAR(255) NULL,                         -- optional override; NULL = use the clause's default roles
    Active           TINYINT      NOT NULL DEFAULT 1,
    Created_By       VARCHAR(50),
    Created_At       DATETIME     NOT NULL,
    Notes            TEXT,
    INDEX (Match_Type, Match_Value),
    INDEX (Clause_Code),
    UNIQUE INDEX uniq_match_clause (Match_Type, Match_Value, Clause_Code)
) ENGINE=InnoDB;

-- ── Seed the standard NZ Building Code clauses (INSERT IGNORE keeps any rows
--    add_coverage_seed.sql already created, e.g. B1/E2, with their data). ────
INSERT IGNORE INTO NZBC_Clauses (Clause_Code, Title, Description, Acceptable_Solutions, Default_Stakeholder_Roles_CSV) VALUES
  ('B1','Structure',                       'Stability, strength, ductility throughout life and during construction.', 'B1/AS1, NZS 3604, NZS 1170', 'structural'),
  ('B2','Durability',                      'Building elements remain functional for their required durability period.', 'B2/AS1', ''),
  ('C1','Fire — Objectives',               'Protection from fire — objectives.', 'C/AS1, C/AS2', 'fire'),
  ('C2','Fire — Prevention of occurring',  'Prevention of fire occurring.', 'C/AS1, C/AS2', 'fire'),
  ('C3','Fire — Spread of fire',           'Fire affecting areas beyond the fire source.', 'C/AS1, C/AS2', 'fire'),
  ('C4','Fire — Movement to safety',       'Occupants reach a safe place.', 'C/AS1, C/AS2', 'fire'),
  ('C5','Fire — Firefighting access',      'Access and safety for firefighting.', 'C/AS1, C/AS2', 'fire'),
  ('C6','Fire — Structural stability',     'Structural stability during fire.', 'C/AS1, C/AS2', 'fire,structural'),
  ('D1','Access routes',                   'People can enter / move within the building.', 'D1/AS1', ''),
  ('D2','Mechanical access',               'Mechanical installations for access (lifts).', 'D2/AS1', ''),
  ('E1','Surface water',                   'Surface water (stormwater) controlled.', 'E1/AS1', 'civil'),
  ('E2','External Moisture',               'Envelope prevents water penetration.', 'E2/AS1, E2/AS2, NZS 4218', 'weathertightness,manufacturer_cladding,manufacturer_membrane'),
  ('E3','Internal Moisture',               'Internal moisture / wet areas controlled.', 'E3/AS1', 'weathertightness'),
  ('F1','Hazardous agents',                'Hazardous agents on site.', 'F1/AS1', ''),
  ('F2','Hazardous building materials',    'Hazardous building materials.', 'F2/AS1', ''),
  ('F4','Safety from falling',             'Barriers, balustrades, fall protection.', 'F4/AS1', ''),
  ('G4','Ventilation',                     'Adequate ventilation.', 'G4/AS1', ''),
  ('G5','Interior environment',            'Interior space / environment.', 'G5/AS1', ''),
  ('G6','Airborne & impact sound',         'Sound insulation between occupancies.', 'G6/AS1', ''),
  ('G7','Natural light',                   'Natural light to habitable spaces.', 'G7/AS1', ''),
  ('G12','Water supplies',                 'Potable water supply.', 'G12/AS1', ''),
  ('G13','Foul water',                     'Foul water drainage.', 'G13/AS1', 'civil'),
  ('H1','Energy Efficiency',               'Thermal envelope / energy use.', 'H1/AS1, H1/AS2, NZS 4218', 'energy_h1');
