-- ============================================================================
-- Seed data: NZBC_Clauses (the relevant subset of the NZ Building Code we
-- expect CADViz jobs to touch) + 10 starter Coverage_Rules biased toward
-- single-storey residential timber-frame (NZS 3604) — the bulk of CADViz's
-- typical workload.
--
-- Run AFTER add_dms_schema.sql.
-- Idempotent: INSERT IGNORE skips rows that already exist.
--
-- Update path: when MBIE amends a clause, edit the row here and re-run.
-- When you want new rules, add INSERT statements at the bottom and re-run.
-- ============================================================================

-- ── NZBC clause catalogue ───────────────────────────────────────────────
-- This is a curated subset, not the full Code. Add rows as you encounter
-- jobs that touch clauses not listed here.
INSERT IGNORE INTO NZBC_Clauses (Clause_Code, Title, Description, Acceptable_Solutions, Default_Stakeholder_Roles_CSV) VALUES
  ('B1',  'Structure',                          'Low probability of rupturing, becoming unstable, losing equilibrium, or collapsing during construction and throughout life.', 'B1/AS1, B1/VM1, B1/VM4, NZS 3604, NZS 1170', 'structural'),
  ('B2',  'Durability',                          'Building elements must with normal maintenance continue to satisfy other performance requirements for not less than 5/15/50 years as required.', 'B2/AS1', 'structural,manufacturer_cladding,manufacturer_roofing'),
  ('C1',  'Objectives — Protection from Fire',  'Safeguarding people from injury or illness caused by fire.',                                                                  'C/AS2, C/VM2', 'fire'),
  ('C2',  'Prevention of Fire Occurring',       'Buildings provided with facilities to prevent fire occurring or developing.',                                                  'C/AS2',         'fire'),
  ('C3',  'Fire affecting areas beyond fire source', 'Spread of fire prevention.',                                                                                              'C/AS2',         'fire'),
  ('C4',  'Movement to Place of Safety',        'Safe evacuation in event of fire.',                                                                                            'C/AS2',         'fire'),
  ('C5',  'Access and Safety for Firefighting', 'Provision for firefighting operations.',                                                                                       'C/AS2',         'fire'),
  ('C6',  'Structural Stability',               'Structural systems maintain stability for required time during fire.',                                                         'C/AS2',         'fire,structural'),
  ('D1',  'Access Routes',                      'Access routes are safe, suitable and identifiable.',                                                                           'D1/AS1',        'consultant'),
  ('D2',  'Mechanical Installations for Access','Lifts, escalators etc.',                                                                                                       'D2/AS1, D2/AS2','consultant'),
  ('E1',  'Surface Water',                      'Surface water from rain.',                                                                                                     'E1/AS1, E1/VM1','civil'),
  ('E2',  'External Moisture',                  'Building envelope prevents penetration of water that could cause undue dampness, damage to building elements, or ill health.', 'E2/AS1, E2/VM1, E2/AS2 (BRANZ tested), NZS 4218', 'weathertightness,manufacturer_cladding,manufacturer_membrane'),
  ('E3',  'Internal Moisture',                  'Moisture from inside the building.',                                                                                           'E3/AS1',        'weathertightness'),
  ('F2',  'Hazardous Building Materials',       'Limits people''s exposure to hazardous building materials.',                                                                   'F2/AS1',        'consultant'),
  ('F4',  'Safety from Falling',                'Buildings constructed to reduce likelihood of accidental fall.',                                                               'F4/AS1',        'structural'),
  ('F5',  'Construction and Demolition Hazards','Reduce likelihood of injury during construction.',                                                                             'F5/AS1',        'consultant'),
  ('F7',  'Warning Systems',                    'Smoke alarms and other warning systems.',                                                                                      'F7/AS1',        'fire'),
  ('G4',  'Ventilation',                        'Adequate ventilation.',                                                                                                        'G4/AS1, G4/VM1','consultant'),
  ('G7',  'Natural Light',                      'Spaces with potential for sleeping have adequate openings for natural light.',                                                 'G7/AS1',        'consultant'),
  ('G9',  'Electricity',                        'Safe distribution of electricity.',                                                                                            'G9/AS1',        'consultant'),
  ('G12', 'Water Supplies',                     'Adequate potable water + protection from contamination.',                                                                      'G12/AS1, G12/AS2','civil'),
  ('G13', 'Foul Water',                         'Foul water disposal.',                                                                                                         'G13/AS1, G13/AS2, G13/AS3','civil'),
  ('H1',  'Energy Efficiency',                  'Buildings designed to use less energy.',                                                                                       'H1/AS1, H1/VM1, NZS 4218', 'energy_h1,manufacturer_joinery,manufacturer_cladding');

-- ── Starter Coverage_Rules ───────────────────────────────────────────────
-- 10 rules covering the highest-frequency coordination failure patterns in
-- single-storey residential timber-frame work. Each rule has:
--   • Trigger_Selector — JSON predicate the rule engine matches against
--     the IFC diff manifest (element-by-element)
--   • Action_Payload   — what to do when fired (tag clauses + suggest roles)
--   • Confidence is implicit "1.00 = always fire when triggered"; coverage
--     rules don't probabilistically; their tags get Source='rule' so a
--     human can still reject them.
INSERT IGNORE INTO Coverage_Rules
  (Name, Trigger_Type, Trigger_Selector, Action_Type, Action_Payload, Nzbc_Clauses_CSV, Default_Stakeholder_Roles_CSV, Notes, Active, Created_By, Created_At)
VALUES
  ('Wall thickness changed',
   'param_changed',
   '{"category":"Wall","param_name":"Thickness"}',
   'tag_nzbc',
   '{"clauses":["B1"],"roles":["structural"]}',
   'B1', 'structural',
   'Wall geometry change usually implies bracing/lintel recalc. Structural eng must re-stamp.',
   1, 'system', NOW()),

  ('Loadbearing wall added',
   'element_added',
   '{"category":"Wall","param":{"LoadBearing":"true"}}',
   'tag_nzbc',
   '{"clauses":["B1"],"roles":["structural"]}',
   'B1', 'structural',
   'New loadbearing wall changes load paths — structural review required.',
   1, 'system', NOW()),

  ('Loadbearing wall removed',
   'element_removed',
   '{"category":"Wall","param":{"LoadBearing":"true"}}',
   'tag_nzbc',
   '{"clauses":["B1"],"roles":["structural"]}',
   'B1', 'structural',
   'Removing a loadbearing wall is high-risk — confirm beam/post substitution with structural eng before client sign-off.',
   1, 'system', NOW()),

  ('Exterior cladding type changed',
   'param_changed',
   '{"category":"Wall","param_name":"ExteriorCladdingType"}',
   'tag_nzbc',
   '{"clauses":["E2","B2"],"roles":["weathertightness","manufacturer_cladding"]}',
   'E2,B2', 'weathertightness,manufacturer_cladding',
   'Cladding swap: E2 detail set changes, durability profile changes. Manufacturer technical sign-off + weathertightness review.',
   1, 'system', NOW()),

  ('Exterior wall opening resized',
   'element_modified',
   '{"category":["Window","Door"],"param_name":["Width","Height"],"param":{"Exterior":"true"}}',
   'tag_nzbc',
   '{"clauses":["B1","E2"],"roles":["structural","weathertightness"]}',
   'B1,E2', 'structural,weathertightness',
   'Opening change → lintel recalc (B1) + flashing/junction detail review (E2). Both required.',
   1, 'system', NOW()),

  ('Roof pitch changed',
   'param_changed',
   '{"category":"Roof","param_name":"Pitch"}',
   'tag_nzbc',
   '{"clauses":["B1","E2","B2"],"roles":["structural","manufacturer_roofing"]}',
   'B1,E2,B2', 'structural,manufacturer_roofing',
   'Roof slope affects truss/rafter design (B1), water-shedding (E2), and roofing-material durability profile (B2).',
   1, 'system', NOW()),

  ('Foundation type or depth changed',
   'param_changed',
   '{"category":"Foundation","param_name":["Type","Depth"]}',
   'tag_nzbc',
   '{"clauses":["B1"],"roles":["structural","geotech"]}',
   'B1', 'structural,geotech',
   'Foundation change usually triggers geotech reassessment, especially on sloped/poor-bearing sites.',
   1, 'system', NOW()),

  ('Stair geometry changed',
   'element_modified',
   '{"category":"Stair"}',
   'tag_nzbc',
   '{"clauses":["D1","F4"],"roles":["structural"]}',
   'D1,F4', 'structural',
   'Stair tread/riser/handrail dimensions are compliance-checked against D1 + F4. Structural for support sizing.',
   1, 'system', NOW()),

  ('Window thermal performance changed',
   'param_changed',
   '{"category":"Window","param_name":["UValue","SHGC","RValue"]}',
   'tag_nzbc',
   '{"clauses":["H1"],"roles":["energy_h1","manufacturer_joinery"]}',
   'H1', 'energy_h1,manufacturer_joinery',
   'H1 modelling must be re-run when window thermal performance changes. Joinery manufacturer to confirm new spec.',
   1, 'system', NOW()),

  ('Bracing element added/modified/removed',
   'element_modified',
   '{"category":"BracingElement"}',
   'tag_nzbc',
   '{"clauses":["B1"],"roles":["structural"]}',
   'B1', 'structural',
   'NZS 3604 bracing demand vs achieved must reconcile — any change in bracing elements triggers structural recheck.',
   1, 'system', NOW());
