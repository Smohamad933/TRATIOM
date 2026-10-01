-- Default (temporary) product photos. Only fills items that have no image yet,
-- so photos uploaded from the admin panel are never overwritten.
UPDATE glass_sizes SET image_url = '/assets/img/products/glass-cylinder.jpg' WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000001' AND image_url IS NULL;
UPDATE glass_sizes SET image_url = '/assets/img/products/glass-sphere.jpg'   WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000002' AND image_url IS NULL;
UPDATE glass_sizes SET image_url = '/assets/img/products/glass-hex.jpg'      WHERE id = '0b7e1c3a-1a01-4c11-9a01-000000000003' AND image_url IS NULL;
UPDATE plants SET image_url = '/assets/img/products/plant-fern.jpg'      WHERE id = '0b7e1c3a-1a01-4c11-9a02-000000000001' AND image_url IS NULL;
UPDATE plants SET image_url = '/assets/img/products/plant-haworthia.jpg' WHERE id = '0b7e1c3a-1a01-4c11-9a02-000000000002' AND image_url IS NULL;
UPDATE plants SET image_url = '/assets/img/products/plant-moss.jpg'      WHERE id = '0b7e1c3a-1a01-4c11-9a02-000000000003' AND image_url IS NULL;
UPDATE plants SET image_url = '/assets/img/products/plant-fittonia.jpg'  WHERE id = '0b7e1c3a-1a01-4c11-9a02-000000000004' AND image_url IS NULL;
UPDATE plants SET image_url = '/assets/img/products/plant-cactus.jpg'    WHERE id = '0b7e1c3a-1a01-4c11-9a02-000000000005' AND image_url IS NULL;
UPDATE stones SET image_url = '/assets/img/products/stone-volcanic.jpg'  WHERE id = '0b7e1c3a-1a01-4c11-9a03-000000000001' AND image_url IS NULL;
UPDATE stones SET image_url = '/assets/img/products/stone-quartz.jpg'    WHERE id = '0b7e1c3a-1a01-4c11-9a03-000000000002' AND image_url IS NULL;
