-- Для MySQL / MariaDB; параметры доступа задаются вне репозитория.
CREATE DATABASE IF NOT EXISTS mixlab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mixlab;
CREATE TABLE IF NOT EXISTS news (
  id VARCHAR(100) PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  published_at VARCHAR(30) NOT NULL,
  source VARCHAR(100) NOT NULL,
  source_url VARCHAR(2048) NOT NULL,
  category VARCHAR(100) NOT NULL
);
CREATE TABLE IF NOT EXISTS app_meta (name VARCHAR(50) PRIMARY KEY, value VARCHAR(100) NOT NULL);
