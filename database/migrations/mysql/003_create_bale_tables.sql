-- Bale (بله) bot: links a Bale chat to a verified mobile number (shared via the contact button)

CREATE TABLE bale_chats (
    chat_id BIGINT NOT NULL PRIMARY KEY,
    bale_user_id BIGINT NOT NULL,
    mobile VARCHAR(15) NULL,
    first_name VARCHAR(100) NULL,
    pending_payload VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_bale_chats_mobile ON bale_chats (mobile);

-- Every wallet transaction id may confirm only one payment (replay protection)
CREATE TABLE bale_transactions (
    transaction_id VARCHAR(100) NOT NULL PRIMARY KEY,
    payment_id CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
