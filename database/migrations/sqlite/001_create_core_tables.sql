-- Terrarium Configurator schema (SQLite - local development & tests)
-- Money columns are integer Rials (*_cents naming kept for compatibility with the domain layer).

CREATE TABLE users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    mobile VARCHAR(15) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mobile VARCHAR(15) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    is_used INTEGER NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE glass_sizes (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL,
    total_volume_ml INTEGER NOT NULL,
    usable_volume_ml INTEGER NOT NULL,
    max_plant_capacity INTEGER NOT NULL,
    is_closed_ecosystem INTEGER NOT NULL DEFAULT 0,
    price_cents INTEGER NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE plants (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    scientific_name VARCHAR(150) NULL,
    volume_occupancy_ml INTEGER NOT NULL,
    light_level VARCHAR(20) NOT NULL,
    moisture_level VARCHAR(20) NOT NULL,
    tolerates_closed_glass INTEGER NOT NULL DEFAULT 1,
    price_cents INTEGER NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE stones (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type VARCHAR(50) NOT NULL,
    volume_per_unit_ml INTEGER NOT NULL,
    price_cents INTEGER NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE figures (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    volume_occupancy_ml INTEGER NOT NULL,
    price_cents INTEGER NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE compatibility_rules (
    id CHAR(36) NOT NULL PRIMARY KEY,
    rule_type VARCHAR(50) NOT NULL,
    source_type VARCHAR(50) NOT NULL,
    source_id CHAR(36) NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id CHAR(36) NULL,
    is_compatible INTEGER NOT NULL DEFAULT 0,
    reason_message TEXT NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE configurations (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NULL,
    glass_size_id CHAR(36) NOT NULL,
    calculated_price_cents INTEGER NOT NULL,
    is_valid INTEGER NOT NULL DEFAULT 0,
    snapshot_data TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_configurations_glass FOREIGN KEY (glass_size_id) REFERENCES glass_sizes (id) ON DELETE RESTRICT
);

CREATE TABLE orders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    order_number VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL,
    total_price_cents INTEGER NOT NULL,
    discount_cents INTEGER NOT NULL DEFAULT 0,
    shipping_cents INTEGER NOT NULL DEFAULT 0,
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
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
);

CREATE TABLE order_items (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    configuration_id CHAR(36) NULL,
    unit_price_cents INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    snapshot_data TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
);

CREATE TABLE payments (
    id CHAR(36) NOT NULL PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    amount_cents INTEGER NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'IRR',
    status VARCHAR(30) NOT NULL,
    authority VARCHAR(191) NULL,
    reference_id VARCHAR(191) NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    gateway_response TEXT NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX uq_users_mobile ON users (mobile);
CREATE INDEX idx_otp_mobile_created ON otp_codes (mobile, created_at);
CREATE INDEX idx_otp_ip_created ON otp_codes (ip_address, created_at);
CREATE UNIQUE INDEX uq_api_tokens_hash ON api_tokens (token_hash);
CREATE INDEX idx_api_tokens_user ON api_tokens (user_id);
CREATE UNIQUE INDEX uq_glass_sizes_code ON glass_sizes (code);
CREATE INDEX idx_compat_lookup ON compatibility_rules (source_type, source_id, target_type, target_id);
CREATE INDEX idx_configurations_user ON configurations (user_id);
CREATE UNIQUE INDEX uq_orders_number ON orders (order_number);
CREATE INDEX idx_orders_user ON orders (user_id);
CREATE INDEX idx_orders_status ON orders (status);
CREATE INDEX idx_order_items_order ON order_items (order_id);
CREATE UNIQUE INDEX uq_payments_idempotency ON payments (idempotency_key);
CREATE UNIQUE INDEX uq_payments_gateway_authority ON payments (gateway, authority);
CREATE INDEX idx_payments_order ON payments (order_id);
CREATE INDEX idx_payments_status ON payments (status);
