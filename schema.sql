SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  english_name VARCHAR(200) NOT NULL,
  tamil_name VARCHAR(200) NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'Nos',
  default_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_product_english (english_name), INDEX idx_product_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  mobile VARCHAR(20) NOT NULL DEFAULT '',
  address TEXT NOT NULL,
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customer_name (name), INDEX idx_customer_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_no INT UNSIGNED NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  amount_received DECIMAL(12,2) NOT NULL DEFAULT 0,
  closing_balance DECIMAL(12,2) NULL,
  share_token CHAR(64) NOT NULL UNIQUE,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bill_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_bill_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bill_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  english_name VARCHAR(200) NOT NULL,
  tamil_name VARCHAR(200) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  rate DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_item_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_payment_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('shop_name','SGAS'),('next_bill_no','1'),('reminder_tamil','வணக்கம் {name}, உங்கள் SGAS கணக்கில் செலுத்த வேண்டிய நிலுவைத் தொகை ₹{balance} உள்ளது. நன்றி — SGAS')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
