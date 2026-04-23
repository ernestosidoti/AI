-- ============================================================
-- LTM AI LABORATORY — Schema v2 (metadata + regole prodotto)
-- ============================================================

USE ai_laboratory;

-- Metadata delle fonti dati (11 DB di CercaPOD)
CREATE TABLE IF NOT EXISTS db_metadata (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'es. edicus2023, pdr2024',
    label VARCHAR(150) NOT NULL,
    database_name VARCHAR(100) NOT NULL,
    table_name VARCHAR(150) NOT NULL,
    tipo_principale ENUM('residenziale','business','gas','misto') NOT NULL DEFAULT 'residenziale',
    prodotti_adatti JSON DEFAULT NULL COMMENT 'Array di codici prodotto es. ["energia","fotovoltaico","depurazione"]',
    anno INT DEFAULT NULL,
    records_count INT UNSIGNED DEFAULT 0,
    priorita TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT '1=massima, 10=minima',
    active TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT DEFAULT NULL,
    note_interne TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tipo (tipo_principale),
    KEY idx_priorita (priorita),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Regole applicate automaticamente in base al prodotto
CREATE TABLE IF NOT EXISTS product_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) NOT NULL COMMENT 'energia, fotovoltaico, depurazione, immobiliare, ecc.',
    rule_type ENUM('exclude','include','transform','note') NOT NULL DEFAULT 'exclude',
    rule_name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    rule_sql TEXT DEFAULT NULL COMMENT 'Frammento WHERE da applicare (senza AND iniziale)',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 10,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_product (product_code),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Liste città da escludere (capoluoghi + cintura grandi città)
CREATE TABLE IF NOT EXISTS city_exclusions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_code VARCHAR(50) NOT NULL COMMENT 'capoluoghi_provincia, cintura_milano, ecc.',
    city_name VARCHAR(100) NOT NULL,
    province VARCHAR(5) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_list (list_code),
    KEY idx_city (city_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella dei prodotti (ricalcata da backoffice.prodotti per comodità)
CREATE TABLE IF NOT EXISTS products_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE COMMENT 'energia, fotovoltaico, depurazione, ecc.',
    label VARCHAR(150) NOT NULL,
    backoffice_product_id INT UNSIGNED DEFAULT NULL COMMENT 'link a backoffice.prodotti.id',
    icon VARCHAR(30) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    display_order TINYINT UNSIGNED DEFAULT 10,
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
