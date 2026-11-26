CREATE DATABASE IF NOT EXISTS crm_database
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE crm_database;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    company VARCHAR(160) DEFAULT '',
    email VARCHAR(190) DEFAULT '',
    phone VARCHAR(50) DEFAULT '',
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX customers_owner (user_id),
    CONSTRAINT customers_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    company VARCHAR(160) DEFAULT '',
    email VARCHAR(190) DEFAULT '',
    source VARCHAR(80) DEFAULT '',
    value DECIMAL(12, 2) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'New',
    converted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX leads_owner (user_id),
    CONSTRAINT leads_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS deals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(160) NOT NULL DEFAULT '',
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    stage VARCHAR(30) NOT NULL DEFAULT 'New',
    probability INT NOT NULL DEFAULT 10,
    expected_close DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX deals_owner_stage (user_id, stage),
    CONSTRAINT deals_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT deals_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    due_date DATE DEFAULT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
    status VARCHAR(30) NOT NULL DEFAULT 'To do',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX tasks_owner (user_id),
    CONSTRAINT tasks_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    details TEXT,
    contact_type VARCHAR(20) DEFAULT NULL,
    contact_id INT DEFAULT NULL,
    contact_name VARCHAR(160) NOT NULL DEFAULT '',
    happened_at DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX activities_owner_date (user_id, happened_at),
    CONSTRAINT activities_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(255) NOT NULL,
    target VARCHAR(40) NOT NULL DEFAULT 'dashboard',
    target_id INT DEFAULT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX notifications_owner_unread (user_id, read_at, created_at),
    CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
