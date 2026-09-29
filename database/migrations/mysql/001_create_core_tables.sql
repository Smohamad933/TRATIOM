-- Terrarium Configurator schema (MySQL 8.0+ / InnoDB / utf8mb4)
-- Money columns are integer Rials (*_cents naming kept for compatibility with the domain layer).

CREATE TABLE users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    mobile VARCHAR(15) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mobile VARCHAR(15) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_otp_mobile_created (mobile, created_at),
    KEY idx_otp_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE glass_sizes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL,
    total_volume_ml INT UNSIGNED NOT NULL,
    usable_volume_ml INT UNSIGNED NOT NULL,
    max_plant_capacity SMALLINT UNSIGNED NOT NULL,
    is_closed_ecosystem TINYINT(1) NOT NULL DEFAULT 0,
    price_cents BIGINT UNSIGNED NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_glass_sizes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plants (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    scientific_name VARCHAR(150) NULL,
    volume_occupancy_ml INT UNSIGNED NOT NULL,
    light_level VARCHAR(20) NOT NULL,
    moisture_level VARCHAR(20) NOT NULL,
    tolerates_closed_glass TINYINT(1) NOT NULL DEFAULT 1,
    price_cents BIGINT UNSIGNED NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stones (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type VARCHAR(50) NOT NULL,
    volume_per_unit_ml INT UNSIGNED NOT NULL,
    price_cents BIGINT UNSIGNED NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE figures (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    volume_occupancy_ml INT UNSIGNED NOT NULL,
    price_cents BIGINT UNSIGNED NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compatibility_rules (
    id CHAR(36) NOT NULL PRIMARY KEY,
    rule_type VARCHAR(50) NOT NULL,
    source_type VARCHAR(50) NOT NULL,
    source_id CHAR(36) NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id CHAR(36) NULL,
    is_compatible TINYINT(1) NOT NULL DEFAULT 0,
    reason_message TEXT NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_compat_lookup (source_type, source_id, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NULL,
    glass_size_id CHAR(36) NOT NULL,
    calculated_price_cents BIGINT UNSIGNED NOT NULL,
    is_valid TINYINT(1) NOT NULL DEFAULT 0,
    snapshot_data JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_configurations_user (user_id),
    CONSTRAINT fk_configurations_glass FOREIGN KEY (glass_size_id) REFERENCES glass_sizes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    order_number VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL,
    total_price_cents BIGINT UNSIGNED NOT NULL,
    discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shipping_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'IRR',
    payment_gateway VARCHAR(30) NULL,
    recipient_name VARCHAR(150) NOT NULL,
    recipient_phone VARCHAR(20) NOT NULL,
    shipping_address TEXT NOT NULL,
    postal_code VARCHAR(20) NULL,
    customer_note TEXT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_orders_number (order_number),
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    configuration_id CHAR(36) NULL,
    unit_price_cents BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    snapshot_data JSON NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'IRR',
    status VARCHAR(30) NOT NULL,
    authority VARCHAR(191) NULL,
    reference_id VARCHAR(191) NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    gateway_response JSON NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payments_idempotency (idempotency_key),
    UNIQUE KEY uq_payments_gateway_authority (gateway, authority),
    KEY idx_payments_order (order_id),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
