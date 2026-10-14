-- The old raw 11001001 (M6031501#1) is used up: deactivate it so the scale no longer offers it
-- or its 1301xxx counts. Re-enable from الأصناف (tick نشط) if more of it arrives.
UPDATE items SET active = 0 WHERE type = 'raw' AND code = '11001001';
