-- Login through the Bale bot: the site creates a request, the user confirms it in the bot with one button
CREATE TABLE bale_logins (
    token VARCHAR(64) NOT NULL PRIMARY KEY,
    status VARCHAR(16) NOT NULL,
    mobile VARCHAR(15) NULL,
    chat_id BIGINT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL
);

CREATE INDEX idx_bale_logins_created ON bale_logins (created_at);
