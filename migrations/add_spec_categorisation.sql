-- ─────────────────────────────────────────────────────────────────────────
-- Spec categorisation pass: fill in missing sub-categories + bulk-tag
-- Tasks_Types rows so the new "Generate quote from spec" wizard
-- (quote_from_spec.php) can filter and bulk-insert tasks by category.
-- ─────────────────────────────────────────────────────────────────────────
-- Idempotent — every INSERT uses ON DUPLICATE KEY UPDATE on
-- (Spec_SubCat_Name) and the UPDATE statements are safe to re-run
-- because they only set Spec_Subcat_ID where the keyword matches a
-- task that didn't already have one assigned.
--
-- Apply via phpMyAdmin SQL tab AFTER the main schema is in place.

-- ── 1. Make Spec_SubCat_Name UNIQUE so the upserts below are safe ──────
-- Skip if it's already unique. (MySQL has no IF NOT EXISTS for keys
-- before 8.0.29 — guard via INFORMATION_SCHEMA.)
SET @idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'Spec_SubCats'
     AND INDEX_NAME   = 'ux_subcat_name'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE Spec_SubCats ADD UNIQUE KEY ux_subcat_name (Spec_SubCat_Name)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. New category: Project Process ───────────────────────────────────
-- Houses the project-lifecycle tasks (setup, planning, issuing,
-- coordination, lodgement, internal review, etc.) that don't fit any
-- physical-element category. Order = 5 so it lists before Wall (10) etc.
INSERT INTO Spec_Cats (Spec_Cat_ID, Spec_Cat_Name, Spec_Cat_Order)
VALUES (19, 'Project Process', 5)
ON DUPLICATE KEY UPDATE Spec_Cat_Name = VALUES(Spec_Cat_Name), Spec_Cat_Order = VALUES(Spec_Cat_Order);

-- ── 3. New sub-categories ──────────────────────────────────────────────
-- Each subcat gets matched up to a parent Spec_Cat below. AUTO_INCREMENT
-- assigns the IDs, so subsequent UPDATEs reference subcats by name not
-- by ID.
INSERT INTO Spec_SubCats (Spec_SubCat_Name, Spec_Cat_ID, Spec_SubCat_Order, Internal_Use_Only) VALUES
-- Project Process (cat 19)
('PROJECT SETUP & ADMIN',           19,  10, 0),
('SITE & PROPERTY RESEARCH',        19,  20, 0),
('DESIGN DEVELOPMENT',              19,  30, 0),
('PLANNING CHECKS',                 19,  40, 0),
('CLIENT COORDINATION',             19,  50, 0),
('ENGINEERING COORDINATION',        19,  60, 0),
('ISSUING & MILESTONES',            19,  70, 0),
('INTERNAL REVIEW',                 19,  80, 0),
('CONSENT LODGEMENT',               19,  90, 0),
('AMENDMENTS',                      19, 100, 0),
('VARIATIONS',                      19, 110, 0),
('SPECIFICATIONS & QA',             19, 120, 0),
('LBP MEMOS & LICENSING',           19, 130, 0),
('PROVISIONAL ALLOWANCES',          19, 140, 0),
-- Existing Conditions (cat 16)
('BUILDING SURVEY',                 16,  10, 0),
('EXISTING DRAWINGS',               16,  20, 0),
('DEMOLITION',                      16,  30, 0),
-- Wall (cat 1)
('BLOCKWORK / MASONRY',              1,  10, 0),
('BRICK VENEER',                     1,  20, 0),
('ICF / SIPS',                       1,  30, 0),
('CROSS SECTIONS',                   1,  40, 0),
('WALL BRACING',                     1,  50, 0),
-- Joinery (cat 4)
('WINDOW SCHEDULE',                  4,  10, 0),
-- Wet Area / Bathroom / Kitchen / Laundry (cat 6)
('KITCHEN LAYOUT',                   6,  10, 0),
('BATHROOM LAYOUT',                  6,  20, 0),
-- Services (cat 14)
('PLUMBING',                        14,  10, 0),
('ELECTRICAL',                      14,  20, 0),
-- Structural (cat 11)
('FIRE / ACOUSTIC',                 11,  10, 0),
('SUBFLOOR BRACING',                11,  20, 0),
-- Ground Works (cat 13)
('SITE DRAINAGE',                   13,  10, 0),
('EARTHWORKS & SEDIMENT CONTROL',   13,  20, 0),
('RETAINING WALLS',                 13,  30, 0),
('LANDSCAPING',                     13,  40, 0),
('SITE FEATURES (PATIOS, POOLS)',   13,  50, 0),
-- Special (cat 15)
('3D RENDERING',                    15,  10, 0),
('FACADE ENGINEERING',              15,  20, 0),
-- Interior (cat 12)
('LIFT / ELEVATOR',                 12,  10, 0)
ON DUPLICATE KEY UPDATE Spec_Cat_ID = VALUES(Spec_Cat_ID), Spec_SubCat_Order = VALUES(Spec_SubCat_Order);

-- Helper macro replacement: each UPDATE references the subcat by name via
-- a sub-SELECT. The matcher only sets Spec_Subcat_ID when it's currently
-- NULL, so re-running the migration is safe and explicit per-task
-- assignments via spec_admin.php aren't trampled.

-- ── 4. Bulk-tag Tasks_Types by keyword ─────────────────────────────────
-- The patterns are pragmatic — they catch the obvious cases. Tasks
-- nobody can categorise from the name alone stay NULL and admins assign
-- them via spec_admin.php afterwards.

-- ╭─ Project Process ──────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'PROJECT SETUP & ADMIN')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Setup%' OR Task_Name LIKE 'Form materials%'
    OR Task_Name LIKE 'Administration%' OR Task_Name LIKE 'Travel%'
    OR Task_Name = 'Draughting - General' OR Task_Name = 'Meetings');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'SITE & PROPERTY RESEARCH')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE '%Council GIS%' OR Task_Name LIKE '%Property File%'
    OR Task_Name LIKE '%Certificate of Title%' OR Task_Name LIKE '%Liase with Surveyor%'
    OR Task_Name LIKE 'Liaise with Surveyor%' OR Task_Name LIKE '%Wind Zone%'
    OR Task_Name LIKE '%Overland Flow Path%' OR Task_Name LIKE '%Stormwater Management%'
    OR Task_Name LIKE 'Check Unitary Plan%' OR Task_Name LIKE '%Geotech%'
    OR Task_Name LIKE 'Research unique construction%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'BUILDING SURVEY')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Site Visit%' OR Task_Name LIKE 'Site Measure%'
    OR Task_Name LIKE 'Assimilate Building Surveyors%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'EXISTING DRAWINGS')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Existing Model%' OR Task_Name LIKE 'Existing Elevations%'
    OR Task_Name LIKE 'Existing Floor Plan%' OR Task_Name LIKE 'Existing Roof Plan%'
    OR Task_Name LIKE 'Existing Site Plan%' OR Task_Name LIKE 'Measure and model%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'DEMOLITION')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Demolition Plan%';

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'DESIGN DEVELOPMENT')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Design Concept%' OR Task_Name LIKE 'Proposed Model%'
    OR Task_Name LIKE 'Existing and Proposed Terrain%' OR Task_Name LIKE 'Establish Project Relationship%'
    OR Task_Name LIKE 'Set Stud_Heights%' OR Task_Name LIKE 'Feasibility Study%'
    OR Task_Name LIKE 'Design Review%' OR Task_Name LIKE 'Developed Design Work%'
    OR Task_Name LIKE 'Modelling / Rendering%' OR Task_Name LIKE 'Proposed Floor Plan%'
    OR Task_Name LIKE 'Proposed Elevation%' OR Task_Name LIKE 'Proposed Roof Plan%'
    OR Task_Name LIKE 'Proposed Sections%' OR Task_Name LIKE 'Vehicle maneuverability%'
    OR Task_Name LIKE 'Site Plan Existing%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'PLANNING CHECKS')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Check Height to Boundary%' OR Task_Name LIKE 'Check Coverage%'
    OR Task_Name LIKE 'Check for proximity%' OR Task_Name LIKE 'Check if Iwi%'
    OR Task_Name LIKE '%Spec Sheet%' OR Task_Name LIKE 'Site Plan showing infringement%'
    OR Task_Name LIKE 'Elevations showing infringement%' OR Task_Name LIKE 'Floor Plans%infringement%'
    OR Task_Name = 'Risk Matrix' OR Task_Name LIKE 'Resource Consent Details%'
    OR Task_Name LIKE 'Earthworks Sediment%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'CLIENT COORDINATION')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Send 40%' OR Task_Name LIKE 'Send documents to Client%'
    OR Task_Name LIKE 'Send documentation for client%' OR Task_Name LIKE 'Assimilate minor changes from client%'
    OR Task_Name LIKE 'Meetings & Correspondence%' OR Task_Name LIKE 'Meetings and Correspondence%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ENGINEERING COORDINATION')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Send Issue to Structural%' OR Task_Name LIKE 'Send to Issue to %'
    OR Task_Name LIKE 'Send Drawings to Fire Engineer%' OR Task_Name LIKE 'Indentify Specific Design Engineering%'
    OR Task_Name LIKE 'Identify Specific Design Engineering%' OR Task_Name LIKE 'Send plans to Engineer%'
    OR Task_Name LIKE 'Assimilate Wind Map%' OR Task_Name LIKE 'Assimilate Changes from Planning%'
    OR Task_Name LIKE 'Assimilate changes from Pre-app%' OR Task_Name LIKE 'Assimilate changes from Civil Engineers%'
    OR Task_Name LIKE 'Liaise with Façade Engineer%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ISSUING & MILESTONES')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE '%Issue%' OR Task_Name LIKE 'Issue %'
    OR Task_Name LIKE 'Layout Details from Model%' OR Task_Name LIKE 'Draft Renderings issued%'
    OR Task_Name LIKE 'Final Renderings Produced%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'INTERNAL REVIEW')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Internal Review%' OR Task_Name LIKE 'Quality Assurance%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'CONSENT LODGEMENT')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Application Forms%' OR Task_Name LIKE 'Compile Building Consent Pack%'
    OR Task_Name LIKE 'Take pack to council%' OR Task_Name LIKE 'Printing in quadruplicate%'
    OR Task_Name LIKE 'Printing, Photocopying%' OR Task_Name LIKE 'Printing'
    OR Task_Name LIKE '%Lodgement%' OR Task_Name LIKE 'Book and Attend Council Pre-Application%'
    OR Task_Name LIKE 'Book and Attend Council Pre-App%' OR Task_Name LIKE 'Reclad Pre-Application Meeting%'
    OR Task_Name LIKE 'Reclad  Pre-App Meeting%' OR Task_Name LIKE 'Reclad Pre-App%'
    OR Task_Name LIKE 'Draft Subdivision Scheme Plan%' OR Task_Name LIKE 'Complete Application form%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'AMENDMENTS')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Revised %' OR Task_Name LIKE 'Ammendment LBP%'
    OR Task_Name LIKE 'Issue set for Amendment%' OR Task_Name LIKE 'General Revisions%'
    OR Task_Name LIKE 'Drafting for ammendment%' OR Task_Name LIKE 'Minor Revisions%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'VARIATIONS')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Variation LBP%' OR Task_Name LIKE 'Issue Minor Variation%'
    OR Task_Name LIKE '%As-Built / Minor variation%' OR Task_Name LIKE '%Standard Detailing%'
    OR Task_Name LIKE 'Additional Weathertightness Details%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'SPECIFICATIONS & QA')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Specification Document%' OR Task_Name LIKE 'Specifications%'
    OR Task_Name LIKE 'Compliance Path Report%' OR Task_Name LIKE 'Reclad Quality Assurance%'
    OR Task_Name LIKE 'Reclad Scope of Works%' OR Task_Name LIKE 'Accessibility Report%'
    OR Task_Name LIKE 'Produce Assessment of Environment Effects%');

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'LBP MEMOS & LICENSING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name = 'LBP Memo';

UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'PROVISIONAL ALLOWANCES')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Provisional %';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Wall (cat 1) ─────────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'BLOCKWORK / MASONRY')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Blockwork%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'BRICK VENEER')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Brick Veneer%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ICF / SIPS')
 WHERE Spec_Subcat_ID IS NULL AND (Task_Name LIKE 'ICF Wall%' OR Task_Name LIKE '%SIPS%');
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'WALL CLADDING (DETAILS)')
 WHERE (Spec_Subcat_ID IS NULL OR Spec_Subcat_ID = 1) AND Task_Name LIKE 'Wall Cladding%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'WALL BRACING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Wall Bracing%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'CROSS SECTIONS')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE '%Cross%Section%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'WALL FRAMING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Wall Framing%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'WALL CONSTRUCTION')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Precast Panel%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Roof (cat 2) ─────────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ROOF CLADDING (DETAILS)')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Roof Details%' OR Task_Name LIKE '%Roof / Wall Junctions%');
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ROOF CLADDING (PLANS, ELEVATIONS, SECTIONS)')
 WHERE Spec_Subcat_ID IS NULL AND (Task_Name LIKE 'Roof Plan%' OR Task_Name LIKE 'Proposed Roof Plan%');
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ROOF FRAMING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Roof Framing%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Floor / Foundation (cat 3, 18) ───────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'FLOOR FRAMING INT')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Floor Framing%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'FLOOR FINISHES (PLANS, SECTIONS, DETAILS)')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Floor Finishes%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'CONCRETE SLAB/FOUNDATIONS')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Concrete Floor Slab%' OR Task_Name LIKE 'Subfloor / Foundation Plan%'
    OR Task_Name LIKE 'Slab/Foundation%' OR Task_Name LIKE 'Slab / Foundation%'
    OR Task_Name LIKE 'Footing Details%');
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Joinery (cat 4) ──────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'WINDOW SCHEDULE')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Window Schedule%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'JOINERY ALUMINIUM')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Joinery Details%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'GLAZING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE '%Glazing%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Wet Area / Bathroom / Kitchen / Laundry (cat 6) ─────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'WET AREA LININGS')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Wet Area Details%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'KITCHEN LAYOUT')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Kitchen Layout%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Deck (cat 9) ─────────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'DECK CONSTRUCTION')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Deck Structure%' OR Task_Name LIKE 'Free Draining Deck%'
    OR Task_Name LIKE 'Membrane Deck%');
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Ceiling (cat 5) ──────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'CEILINGS + SOFFIT LININGS')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Reflected Ceiling%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Interior (cat 12) ────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'STAIRS + HANDRAILS')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Stair Details%' OR Task_Name LIKE 'External Stair%'
    OR Task_Name LIKE 'Post and Stair details%');
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'LIFT / ELEVATOR')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE '%for Lift%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Structural (cat 11) ──────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'STRUCTURAL (ENG. ITEMS)')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Structural Connection%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'FIRE / ACOUSTIC')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Fire and acoustic%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'SUBFLOOR BRACING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Subfloor Bracing%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Services (cat 14) ────────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'PLUMBING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Plumbing%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'ELECTRICAL')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE '%Emergency Lighting%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Ground Works (cat 13) ────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'SITE DRAINAGE')
 WHERE Spec_Subcat_ID IS NULL AND (Task_Name = 'Site Drainage' OR Task_Name LIKE 'Site Drainage%');
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'EARTHWORKS & SEDIMENT CONTROL')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Siteworks Cut/Fill%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'RETAINING WALLS')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Detailing - Site (Retaining%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'LANDSCAPING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Landscape Plan%';
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'SITE FEATURES (PATIOS, POOLS)')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE 'Swimming Pool%' OR Task_Name LIKE 'Site features%');
-- ╰─────────────────────────────────────────────────────────────────────╯

-- ╭─ Special / 3D (cat 15) ────────────────────────────────────────────╮
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = '3D RENDERING')
 WHERE Spec_Subcat_ID IS NULL AND (
       Task_Name LIKE '3D Modelling%' OR Task_Name LIKE 'Rendering%'
    OR Task_Name LIKE 'Materialisation%' OR Task_Name LIKE 'Props%'
    OR Task_Name LIKE 'Lighting%' OR Task_Name LIKE 'Post Production%'
    OR Task_Name LIKE 'Assimilate 3D Model%' OR Task_Name LIKE '3D views / Renderings%');
UPDATE Tasks_Types SET Spec_Subcat_ID = (SELECT Spec_SubCat_ID FROM Spec_SubCats WHERE Spec_SubCat_Name = 'FACADE ENGINEERING')
 WHERE Spec_Subcat_ID IS NULL AND Task_Name LIKE 'Liaise with Façade%';
-- ╰─────────────────────────────────────────────────────────────────────╯

-- Final fallback: any remaining NULL Spec_Subcat_ID stays NULL — admins
-- pick the right subcat manually via spec_admin.php, or assign a new
-- subcat if the task doesn't fit the existing taxonomy.
