-- Tables of the PDO example. Written for SQLite; for MySQL use
-- BIGINT UNSIGNED AUTO_INCREMENT ids, DECIMAL(15,2) sums, VARCHAR for strings and JSON for payload.

CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number TEXT NOT NULL UNIQUE,
    total TEXT NOT NULL,                 -- so'm, "125000.00"; never a float column
    status TEXT NOT NULL DEFAULT 'new',  -- new | paid | canceled | refunded
    paid_via TEXT,
    shipped INTEGER NOT NULL DEFAULT 0   -- shipped orders are not refunded automatically
);

CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders (id),
    title TEXT NOT NULL,
    price TEXT NOT NULL,
    quantity REAL NOT NULL,
    mxik_code TEXT,
    package_code TEXT,
    vat_percent INTEGER NOT NULL DEFAULT 12
);

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,              -- payme | click | uzum | paynet | octo
    external_id TEXT NOT NULL,           -- the provider's transaction id (Octo: ours, shop_transaction_id)
    order_id INTEGER NOT NULL REFERENCES orders (id),
    amount TEXT NOT NULL,
    state INTEGER NOT NULL,              -- Uzpay\TransactionState
    reason INTEGER,
    create_time INTEGER,                 -- Unix milliseconds
    perform_time INTEGER,
    cancel_time INTEGER,
    payload TEXT,                        -- JSON
    UNIQUE (provider, external_id)
);
CREATE INDEX IF NOT EXISTS payment_transactions_order ON payment_transactions (order_id);
CREATE INDEX IF NOT EXISTS payment_transactions_time ON payment_transactions (provider, create_time);
