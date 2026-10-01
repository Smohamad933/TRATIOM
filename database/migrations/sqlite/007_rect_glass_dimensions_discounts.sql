-- Rectangular container, container dimensions, remaining product photos, discount codes

ALTER TABLE glass_sizes ADD COLUMN width_cm REAL NULL;
ALTER TABLE glass_sizes ADD COLUMN depth_cm REAL NULL;
ALTER TABLE glass_sizes ADD COLUMN height_cm REAL NULL;

-- container dimensions in centimetres (width × depth × height)
UPDATE glass_sizes SET width_cm = 12, depth_cm = 12, height_cm = 25 WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000001' AND width_cm IS NULL;
UPDATE glass_sizes SET width_cm = 18, depth_cm = 18, height_cm = 16 WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000002' AND width_cm IS NULL;
UPDATE glass_sizes SET width_cm = 20, depth_cm = 20, height_cm = 22 WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000003' AND width_cm IS NULL;

-- new: rectangular open container
INSERT INTO glass_sizes (id, name, code, total_volume_ml, usable_volume_ml, max_plant_capacity, is_closed_ecosystem, price_cents, stock_quantity, is_active, image_url, width_cm, depth_cm, height_cm, created_at, updated_at)
SELECT '0b7e1c3a-1a01-4c11-9a01-000000000004', 'شیشه مستطیلی (درباز)', 'RECT-L-OPEN', 6000, 4800, 5, 0, 6800000, 12, 1, '/assets/img/products/glass-rect.jpg', 30, 18, 20, '2026-10-01 00:00:00', '2026-10-01 00:00:00'
WHERE NOT EXISTS (SELECT 1 FROM glass_sizes WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000004');

-- remaining product photos (only if no photo yet)
UPDATE stones  SET image_url = '/assets/img/products/stone-charcoal.jpg' WHERE id = '0b7e1c3a-1a01-4c11-9a03-000000000003' AND image_url IS NULL;
UPDATE figures SET image_url = '/assets/img/products/fig-cabin.jpg'      WHERE id = '0b7e1c3a-1a01-4c11-9a04-000000000001' AND image_url IS NULL;
UPDATE figures SET image_url = '/assets/img/products/fig-mushroom.jpg'   WHERE id = '0b7e1c3a-1a01-4c11-9a04-000000000002' AND image_url IS NULL;
UPDATE figures SET image_url = '/assets/img/products/fig-fox.jpg'        WHERE id = '0b7e1c3a-1a01-4c11-9a04-000000000003' AND image_url IS NULL;

-- Discount codes: percent or fixed amount, total-use limit, per-account limit, optional allowed accounts
CREATE TABLE discount_codes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    type VARCHAR(10) NOT NULL,
    value INT NOT NULL,
    max_discount_cents INT NULL,
    min_order_cents INT NOT NULL DEFAULT 0,
    max_uses INT NULL,
    per_user_limit INT NULL,
    allowed_mobiles TEXT NULL,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    is_active INT NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX ux_discount_codes_code ON discount_codes (code);

CREATE TABLE discount_redemptions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    discount_code_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    order_id CHAR(36) NOT NULL,
    amount_cents INT NOT NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX ix_discount_redemptions_code ON discount_redemptions (discount_code_id);
CREATE UNIQUE INDEX ux_discount_redemptions_order ON discount_redemptions (order_id);

ALTER TABLE orders ADD COLUMN discount_code VARCHAR(40) NULL;
