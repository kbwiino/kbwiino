-- Run as the MySQL root/admin user:  sudo mysql < deploy/mysql/setup.sql   (edit the two passwords first!)
CREATE DATABASE IF NOT EXISTS researchmentor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Runtime account used by the web app and the worker: data access only.
CREATE USER IF NOT EXISTS 'researchmentor'@'localhost' IDENTIFIED BY 'CHANGE_ME_RUNTIME_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON researchmentor.* TO 'researchmentor'@'localhost';

-- Migration account: schema changes only when you deploy (run bin/migrate.php with DB_USERNAME/DB_PASSWORD overridden).
CREATE USER IF NOT EXISTS 'rm_migrator'@'localhost' IDENTIFIED BY 'CHANGE_ME_MIGRATOR_PASSWORD';
GRANT ALL PRIVILEGES ON researchmentor.* TO 'rm_migrator'@'localhost';

FLUSH PRIVILEGES;
