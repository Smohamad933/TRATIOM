-- Ready-made terrariums (presets) + an optional photo for every catalog item

ALTER TABLE glass_sizes ADD COLUMN image_url VARCHAR(255) NULL;
ALTER TABLE plants ADD COLUMN image_url VARCHAR(255) NULL;
ALTER TABLE stones ADD COLUMN image_url VARCHAR(255) NULL;
ALTER TABLE figures ADD COLUMN image_url VARCHAR(255) NULL;

CREATE TABLE presets (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(1000) NULL,
    image_url VARCHAR(255) NULL,
    configuration TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

INSERT INTO presets (id, name, description, image_url, configuration, sort_order, is_active, created_at, updated_at) VALUES
('0b7e1c3a-1a01-4c11-9a05-000000000001', 'جنگل بارانی', 'اکوسیستم دربسته و مرطوب با سرخس، فیتونیا و خزه؛ تقریباً بی‌نیاز از آبیاری.', '/assets/img/presets/rainforest.jpg',
 '{"glass_size_id":"0b7e1c3a-1a01-4c11-9a01-000000000001","plants":[{"id":"0b7e1c3a-1a01-4c11-9a02-000000000001","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a02-000000000004","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a02-000000000003","quantity":1}],"stones":[{"id":"0b7e1c3a-1a01-4c11-9a03-000000000001","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a03-000000000003","quantity":1}],"figures":[{"id":"0b7e1c3a-1a01-4c11-9a04-000000000002","quantity":1}]}', 1, 1, '2026-10-01 00:00:00', '2026-10-01 00:00:00'),
('0b7e1c3a-1a01-4c11-9a05-000000000002', 'کویر کوچک', 'ظرف درباز هندسی با ساکولنت و کاکتوس؛ کم‌آب و مقاوم، مناسب نور زیاد.', '/assets/img/presets/desert.jpg',
 '{"glass_size_id":"0b7e1c3a-1a01-4c11-9a01-000000000003","plants":[{"id":"0b7e1c3a-1a01-4c11-9a02-000000000002","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a02-000000000005","quantity":1}],"stones":[{"id":"0b7e1c3a-1a01-4c11-9a03-000000000001","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a03-000000000002","quantity":1}],"figures":[{"id":"0b7e1c3a-1a01-4c11-9a04-000000000003","quantity":1}]}', 2, 1, '2026-10-01 00:00:00', '2026-10-01 00:00:00'),
('0b7e1c3a-1a01-4c11-9a05-000000000003', 'کلبه در جنگل', 'گوی شیشه‌ای درباز با فیتونیا، تپه‌های خزه و کلبه مینیاتوری چوبی.', '/assets/img/presets/cabin.jpg',
 '{"glass_size_id":"0b7e1c3a-1a01-4c11-9a01-000000000002","plants":[{"id":"0b7e1c3a-1a01-4c11-9a02-000000000004","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a02-000000000003","quantity":1}],"stones":[{"id":"0b7e1c3a-1a01-4c11-9a03-000000000001","quantity":1},{"id":"0b7e1c3a-1a01-4c11-9a03-000000000003","quantity":1}],"figures":[{"id":"0b7e1c3a-1a01-4c11-9a04-000000000001","quantity":1}]}', 3, 1, '2026-10-01 00:00:00', '2026-10-01 00:00:00');
