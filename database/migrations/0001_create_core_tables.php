<?php

declare(strict_types=1);

use App\Core\Database;

return [
    'up' => function (Database $db): void {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $sql = [
            "CREATE TABLE IF NOT EXISTS {roles} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NULL,
                permissions JSON NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {users} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                last_login_at DATETIME NULL,
                last_login_ip VARCHAR(45) NULL,
                password_changed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_users_role (role_id),
                CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES {roles}(id)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {settings} (
                `key` VARCHAR(150) NOT NULL PRIMARY KEY,
                `value` MEDIUMTEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {jobs} (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                name VARCHAR(200) NOT NULL,
                provider VARCHAR(40) NOT NULL,
                status ENUM('pending','running','enriching','done','failed','cancelled') NOT NULL DEFAULT 'pending',
                params JSON NOT NULL,
                progress JSON NULL,
                places_found INT UNSIGNED NOT NULL DEFAULT 0,
                emails_found INT UNSIGNED NOT NULL DEFAULT 0,
                api_calls INT UNSIGNED NOT NULL DEFAULT 0,
                error VARCHAR(1000) NULL,
                warnings TEXT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
                locked_by VARCHAR(64) NULL,
                heartbeat_at DATETIME NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_jobs_status (status, created_at),
                KEY idx_jobs_user (user_id, created_at)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {places} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id CHAR(36) NOT NULL,
                place_key VARCHAR(190) NOT NULL,
                keyword VARCHAR(500) NULL,
                provider_place_id VARCHAR(190) NULL,
                title VARCHAR(500) NOT NULL,
                category VARCHAR(255) NULL,
                categories TEXT NULL,
                address VARCHAR(1000) NULL,
                phone VARCHAR(64) NULL,
                website VARCHAR(1000) NULL,
                emails VARCHAR(1000) NULL,
                email_host VARCHAR(40) NULL,
                instagram VARCHAR(500) NULL,
                facebook VARCHAR(500) NULL,
                linkedin VARCHAR(500) NULL,
                tiktok VARCHAR(500) NULL,
                youtube VARCHAR(500) NULL,
                x VARCHAR(500) NULL,
                rating DECIMAL(2,1) NULL,
                review_count INT UNSIGNED NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                price_level VARCHAR(40) NULL,
                business_status VARCHAR(40) NULL,
                opening_hours TEXT NULL,
                plus_code VARCHAR(40) NULL,
                maps_url VARCHAR(1000) NULL,
                thumbnail VARCHAR(1000) NULL,
                tech JSON NULL,
                intent_score TINYINT UNSIGNED NULL,
                website_status VARCHAR(30) NULL,
                lead_status ENUM('new','contacted','qualified','won','lost') NOT NULL DEFAULT 'new',
                notes TEXT NULL,
                raw JSON NULL,
                enriched_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_places_job_key (job_id, place_key),
                KEY idx_places_key (place_key),
                KEY idx_places_status (lead_status),
                KEY idx_places_created (created_at),
                CONSTRAINT fk_places_job FOREIGN KEY (job_id) REFERENCES {jobs}(id) ON DELETE CASCADE
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {api_keys} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                prefix CHAR(12) NOT NULL UNIQUE,
                key_hash CHAR(64) NOT NULL,
                scopes JSON NOT NULL,
                last_used_at DATETIME NULL,
                last_used_ip VARCHAR(45) NULL,
                expires_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_api_keys_user (user_id),
                CONSTRAINT fk_api_keys_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {audit_logs} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(50) NULL,
                entity_id VARCHAR(64) NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                via VARCHAR(10) NOT NULL DEFAULT 'web',
                meta JSON NULL,
                created_at DATETIME NOT NULL,
                KEY idx_audit_created (created_at),
                KEY idx_audit_user (user_id),
                KEY idx_audit_action (action)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {rate_limits} (
                bucket CHAR(64) NOT NULL PRIMARY KEY,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                reset_at DATETIME NOT NULL,
                KEY idx_rl_reset (reset_at)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {cities} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                region VARCHAR(190) NULL,
                country CHAR(2) NOT NULL,
                lat DECIMAL(9,5) NOT NULL,
                lon DECIMAL(9,5) NOT NULL,
                search_name VARCHAR(190) NOT NULL,
                rank_order INT UNSIGNED NOT NULL,
                KEY idx_cities_search (search_name, rank_order)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS {update_history} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                from_version VARCHAR(30) NOT NULL,
                to_version VARCHAR(30) NOT NULL,
                status ENUM('running','success','failed','rolled_back') NOT NULL,
                backup_file VARCHAR(255) NULL,
                migration_batch INT UNSIGNED NULL,
                log MEDIUMTEXT NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                finished_at DATETIME NULL
            ) $opts",
        ];
        foreach ($sql as $s) {
            $db->pdo->exec($db->sql($s));
        }
    },
    'down' => function (Database $db): void {
        foreach (['update_history', 'cities', 'rate_limits', 'audit_logs', 'api_keys', 'places', 'jobs', 'settings', 'users', 'roles'] as $t) {
            $db->pdo->exec('DROP TABLE IF EXISTS ' . $db->table($t));
        }
    },
];
