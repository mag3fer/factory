-- Initial data copied from Al-Mohtaseb ("تكستايل لاند. 2026") on 2026-10-11
SET NAMES utf8mb4;

INSERT INTO warehouses (id, name) VALUES (1, 'المستودع الرئيسي');

INSERT INTO settings (skey, svalue) VALUES
 ('company_name', 'TEXTILE LAND'),
 ('company_phone1', '01145907988'),
 ('company_phone2', '01120160241'),
 ('bag_weight', '0.015'),
 ('default_cones', '6'),
 ('label_width_mm', '76'),
 ('label_height_mm', '130'),
 ('company_address', 'تكستايل لاند لصناعة الخيوط البولستر - مدينة بدر 250 فدان'),
 ('logo_file', ''),
 ('scale_baud', '9600');

INSERT INTO items (code, name, type, unit, spec, poy_spec, lot_no, grade, color, tare_weight) VALUES
 ('11001001', '150/144 M6031501#1', 'raw', 'kg', '150/144', NULL, 'M6031501#1', NULL, NULL, 0),
 ('1201004', 'موف', 'cone', 'pcs', NULL, NULL, NULL, NULL, 'موف', 0.150),
 ('1201005', 'بيج', 'cone', 'pcs', NULL, NULL, NULL, NULL, 'بيج', 0.150),
 ('1201006', 'بيج 180 غ', 'cone', 'pcs', NULL, NULL, NULL, NULL, 'بيج', 0.150),
 ('1201007', 'زهري 180 غ', 'cone', 'pcs', NULL, NULL, NULL, NULL, 'زهري', 0.150),
 ('1201008', 'موف 180 غ', 'cone', 'pcs', NULL, NULL, NULL, NULL, 'موف', 0.150),
 ('1201009', 'اخضر 180 غ', 'cone', 'pcs', NULL, NULL, NULL, NULL, 'اخضر', 0.150),
 ('1202001', 'كرتونة مستعمل', 'pack', 'pcs', NULL, NULL, NULL, NULL, NULL, 0),
 ('1202002', 'شكارة', 'pack', 'pcs', NULL, NULL, NULL, NULL, NULL, 0.240),
 ('1301001', '150/144 مطفي لحمة ك.ك AA M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'AA', NULL, 0),
 ('1301002', '150/144 مطفي لحمة ك.ك A M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'A', NULL, 0),
 ('1301004', '150/144 مطفي لحمة ك.ك B M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'B', NULL, 0),
 ('1301013', '150/144 مطفي لحمة ك.ش AB M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'AB', NULL, 0),
 ('1301014', '150/144 مطفي لحمة ك.ش B M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'B', NULL, 0),
 ('1301015', '150/144 مطفي لحمة ك.ش LC M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'LC', NULL, 0),
 ('1301016', '150/144 مطفي لحمة ك.ش H M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'H', NULL, 0),
 ('1301018', '150/144 مطفي لحمة ك.ش IMG M6031501#1', 'finished', 'kg', '150/144', NULL, 'M6031501#1', 'IMG', NULL, 0);

INSERT INTO parties (code, name, type) VALUES
 ('12030001', 'يمان اسكيف', 'customer'),
 ('12030002', 'جهاد اسكيف', 'customer'),
 ('12030003', 'مصنع فايبر تكس', 'customer'),
 ('12030004', 'فايبر تكس مبيع', 'customer'),
 ('12030005', 'محمد ابوسريع مبيع عوادم', 'customer'),
 ('12030008', 'ايمن عتال', 'customer'),
 ('12030009', 'احمد مليس', 'customer'),
 ('12030010', 'يحي بابجي', 'customer'),
 ('12030011', 'محمد طرقجي', 'customer'),
 ('12030012', 'محمد عبس', 'customer'),
 ('12030013', 'محمد ماياتي', 'customer'),
 ('22030002', 'فراس اسكيف زيوت', 'supplier'),
 ('22030003', 'هيثم مكتبي poy', 'supplier'),
 ('22030004', 'الالفي كونات مستعملة + كرتونة مستعملة', 'supplier'),
 ('22030005', 'جمال ابراهيم بخاخات', 'supplier'),
 ('22030007', 'محمد ابو سريع شراء كرتونة', 'supplier'),
 ('22030008', 'اشرف كونات جديدة', 'supplier'),
 ('22030009', 'فايبر تكس شراء مواد', 'supplier');
