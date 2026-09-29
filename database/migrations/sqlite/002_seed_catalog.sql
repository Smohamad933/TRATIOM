-- Starter catalog. Prices are in Rials. Edit anytime from the admin panel.

INSERT INTO glass_sizes (id, name, code, total_volume_ml, usable_volume_ml, max_plant_capacity, is_closed_ecosystem, price_cents, stock_quantity, is_active, created_at, updated_at) VALUES
('0b7e1c3a-1a01-4c11-9a01-000000000001', 'شیشه استوانه‌ای بزرگ (دربسته)', 'CYL-L-CLOSED', 5000, 4000, 4, 1, 4500000, 24, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a01-000000000002', 'شیشه کروی دکوراتیو (درباز)', 'SPH-M-OPEN', 2500, 2000, 2, 0, 3200000, 15, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a01-000000000003', 'شیشه هندسی شش‌ضلعی (درباز)', 'HEX-L-OPEN', 4000, 3200, 3, 0, 5200000, 10, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00');

INSERT INTO plants (id, name, scientific_name, volume_occupancy_ml, light_level, moisture_level, tolerates_closed_glass, price_cents, stock_quantity, is_active, created_at, updated_at) VALUES
('0b7e1c3a-1a01-4c11-9a02-000000000001', 'سرخس بوستون', 'Nephrolepis exaltata', 350, 'medium', 'high', 1, 850000, 60, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a02-000000000002', 'هاورتیا (ساکولنت)', 'Haworthia fasciata', 200, 'bright', 'low', 0, 650000, 40, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a02-000000000003', 'خزه پین‌کوشن', 'Leucobryum glaucum', 100, 'low', 'high', 1, 400000, 120, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a02-000000000004', 'فیتونیا', 'Fittonia albivenis', 250, 'medium', 'medium', 1, 700000, 50, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a02-000000000005', 'کاکتوس مامیلاریا', 'Mammillaria elongata', 180, 'bright', 'low', 0, 550000, 35, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00');

INSERT INTO stones (id, name, type, volume_per_unit_ml, price_cents, stock_quantity, is_active, created_at, updated_at) VALUES
('0b7e1c3a-1a01-4c11-9a03-000000000001', 'سنگریزه آتشفشانی سیاه (زهکشی)', 'drainage', 500, 300000, 200, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a03-000000000002', 'شن سفید کوارتز دکوراتیو', 'decorative', 300, 250000, 150, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a03-000000000003', 'ذغال فعال (ضدقارچ)', 'substrate', 150, 180000, 100, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00');

INSERT INTO figures (id, name, volume_occupancy_ml, price_cents, stock_quantity, is_active, created_at, updated_at) VALUES
('0b7e1c3a-1a01-4c11-9a04-000000000001', 'کلبه مینیاتوری چوبی', 150, 950000, 18, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a04-000000000002', 'قارچ سرامیکی', 50, 200000, 60, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00'),
('0b7e1c3a-1a01-4c11-9a04-000000000003', 'روباه کوچک رزینی', 40, 350000, 30, 1, '2026-09-29 00:00:00', '2026-09-29 00:00:00');
