<?php
declare(strict_types=1);

return <<<'SQL'
CREATE TABLE IF NOT EXISTS bk_settings (
 setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
 setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_services (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 description VARCHAR(600) NOT NULL DEFAULT '',
 duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
 buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 price_minor INT UNSIGNED NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_staff (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 role_title VARCHAR(100) NOT NULL DEFAULT '',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_service_staff (
 service_id BIGINT UNSIGNED NOT NULL,
 staff_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (service_id, staff_id),
 CONSTRAINT bk_ss_service_fk FOREIGN KEY (service_id) REFERENCES bk_services(id) ON DELETE CASCADE,
 CONSTRAINT bk_ss_staff_fk FOREIGN KEY (staff_id) REFERENCES bk_staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_hours (
 weekday TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 opens TIME NOT NULL DEFAULT '10:00:00',
 closes TIME NOT NULL DEFAULT '18:00:00',
 closed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_time_off (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 staff_id BIGINT UNSIGNED NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 note VARCHAR(180) NOT NULL DEFAULT '',
 INDEX bk_time_off_range (staff_id, starts_at, ends_at),
 CONSTRAINT bk_off_staff_fk FOREIGN KEY (staff_id) REFERENCES bk_staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_appointments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(30) NOT NULL UNIQUE,
 token_hash CHAR(64) NOT NULL UNIQUE,
 request_key CHAR(64) NOT NULL UNIQUE,
 service_id BIGINT UNSIGNED NOT NULL,
 staff_id BIGINT UNSIGNED NOT NULL,
 service_name VARCHAR(120) NOT NULL,
 duration_minutes SMALLINT UNSIGNED NOT NULL,
 buffer_minutes SMALLINT UNSIGNED NOT NULL,
 price_minor INT UNSIGNED NOT NULL,
 currency_code CHAR(3) NOT NULL,
 client_name VARCHAR(100) NOT NULL,
 client_phone VARCHAR(30) NOT NULL,
 client_email VARCHAR(190) NOT NULL DEFAULT '',
 notes VARCHAR(1200) NOT NULL DEFAULT '',
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 busy_until DATETIME NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 INDEX bk_booking_schedule (staff_id, status, starts_at, busy_until),
 INDEX bk_booking_date (starts_at),
 CONSTRAINT bk_appt_service_fk FOREIGN KEY (service_id) REFERENCES bk_services(id),
 CONSTRAINT bk_appt_staff_fk FOREIGN KEY (staff_id) REFERENCES bk_staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 appointment_id BIGINT UNSIGNED NOT NULL,
 amount_minor INT UNSIGNED NOT NULL,
 entry_type VARCHAR(12) NOT NULL DEFAULT 'payment',
 method VARCHAR(20) NOT NULL,
 note VARCHAR(250) NOT NULL DEFAULT '',
 recorded_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX bk_payments_booking (appointment_id),
 CONSTRAINT bk_payment_appt_fk FOREIGN KEY (appointment_id) REFERENCES bk_appointments(id),
 CONSTRAINT bk_payment_user_fk FOREIGN KEY (recorded_by) REFERENCES bk_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_rate_limits (
 key_hash CHAR(64) NOT NULL PRIMARY KEY,
 window_started DATETIME NOT NULL,
 hits INT UNSIGNED NOT NULL DEFAULT 1,
 INDEX bk_rate_window (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bk_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 event VARCHAR(60) NOT NULL,
 detail VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX bk_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
