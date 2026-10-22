-- The plastic bag on each cone weighs 0.006 kg (confirmed by the owner); same for carton and sack.
UPDATE packing_types SET bag_weight=0.006 WHERE name IN ('كرتون','شكارة');
