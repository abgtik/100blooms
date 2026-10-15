<?php
// Table definitions. Written for MySQL/MariaDB (cPanel); the sqlite branch is only for local testing.
function schema_sql(string $driver): array {
  $my = $driver === 'mysql';
  $id = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
  return array_merge([
"CREATE TABLE IF NOT EXISTS orders (
  id $id,
  code VARCHAR(20) NOT NULL UNIQUE,
  token VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL,
  pay_status VARCHAR(12) NOT NULL DEFAULT 'pending',
  status VARCHAR(20) NOT NULL DEFAULT 'menunggu_bayaran',
  paid_at DATETIME NULL, pay_ref VARCHAR(60) NULL, pay_channel VARCHAR(30) NULL, bill_code VARCHAR(40) NULL,
  occasion VARCHAR(20) NOT NULL, relation VARCHAR(20) NOT NULL, gender CHAR(1) NOT NULL, call_name VARCHAR(40) NOT NULL,
  bouquet VARCHAR(20) NOT NULL, balloon VARCHAR(20) NOT NULL, cake VARCHAR(60) NOT NULL, cake_flavours TEXT NULL, extra_cakes TEXT NULL,
  photo_count INT NOT NULL DEFAULT 0, photos TEXT NULL,
  wish TEXT NOT NULL, sender VARCHAR(60) NULL,
  r_name VARCHAR(100) NOT NULL, r_phone VARCHAR(30) NOT NULL, r_addr VARCHAR(400) NOT NULL, r_post CHAR(5) NOT NULL, zone VARCHAR(10) NOT NULL,
  del_date DATE NOT NULL, del_type VARCHAR(6) NOT NULL, del_time VARCHAR(5) NULL,
  b_name VARCHAR(100) NOT NULL, b_phone VARCHAR(30) NOT NULL,
  price_lines TEXT NOT NULL, total DECIMAL(8,2) NOT NULL,
  agent_ref VARCHAR(20) NULL, voucher VARCHAR(30) NULL, discount DECIMAL(8,2) NOT NULL DEFAULT 0, notes TEXT NULL, prep TEXT NULL, del_steps TEXT NULL, lalamove_url VARCHAR(300) NULL, created_by VARCHAR(100) NULL, bq_note VARCHAR(100) NULL, pay_mode VARCHAR(10) NOT NULL DEFAULT 'gateway', recipient_photo VARCHAR(80) NULL, wish_edits INT NOT NULL DEFAULT 0, wa_log TEXT NULL, updated_by VARCHAR(100) NULL
)$tail",
"CREATE INDEX idx_orders_del ON orders (del_date)",
"CREATE INDEX idx_orders_created ON orders (created_at)",
"CREATE TABLE IF NOT EXISTS admins (
  id $id, email VARCHAR(150) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, name VARCHAR(100) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'staff', created_at DATETIME NOT NULL
)$tail",
"CREATE TABLE IF NOT EXISTS products (
  type VARCHAR(6) NOT NULL, item_id VARCHAR(60) NOT NULL, name VARCHAR(100) NOT NULL, active TINYINT NOT NULL DEFAULT 1, code VARCHAR(12) NULL, price DECIMAL(8,2) NULL,
  PRIMARY KEY (type, item_id)
)$tail",
"CREATE TABLE IF NOT EXISTS blocked_dates (d DATE PRIMARY KEY, reason VARCHAR(150) NULL)$tail",
"CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT NULL)$tail",
"CREATE TABLE IF NOT EXISTS vouchers (
  code VARCHAR(30) PRIMARY KEY, type VARCHAR(8) NOT NULL, value DECIMAL(8,2) NOT NULL DEFAULT 0, max_off DECIMAL(8,2) NULL,
  gift_name VARCHAR(100) NULL, agent_ok TINYINT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1,
  starts_on DATE NULL, ends_on DATE NULL, max_uses INT NULL, once_per_phone TINYINT NOT NULL DEFAULT 1,
  note VARCHAR(200) NULL, created_at DATETIME NOT NULL
)$tail",
"CREATE TABLE IF NOT EXISTS login_attempts (ip VARCHAR(45) NOT NULL, at DATETIME NOT NULL)$tail",
  "CREATE TABLE IF NOT EXISTS media (id $id, cat VARCHAR(12) NOT NULL, title VARCHAR(100) NOT NULL, caption TEXT NULL, file VARCHAR(160) NULL, active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$tail",
  ], agent_schema($driver));
}
function seed_products(): void {
  $C = catalog();
  $ins = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
  foreach ($C['bq'] as $k => $v) q("$ins INTO products (type,item_id,name,active) VALUES ('bq',?,?,1)", [$k, $v]);
  foreach ($C['bal'] as $k => $v) q("$ins INTO products (type,item_id,name,active) VALUES ('bal',?,?,1)", [$k, $v]);
  foreach (array_merge($C['cake_free'], $C['cake_up']) as $v) q("$ins INTO products (type,item_id,name,active) VALUES ('cake',?,?,1)", [$v, $v]);
}
