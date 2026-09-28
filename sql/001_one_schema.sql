-- SmartStay ONE — schema smartconcept_one
-- Idempotent: safe to re-run. Applied by bin/migrate.php.
-- All DATETIME columns are UTC (connection runs with time_zone = '+00:00').

CREATE TABLE IF NOT EXISTS users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(100) NOT NULL,
  email                 VARCHAR(150) NULL,
  phone                 VARCHAR(20)  NULL,
  password_hash         VARCHAR(255) NOT NULL,
  must_change_password  TINYINT(1)   NOT NULL DEFAULT 1,
  role                  ENUM('admin','manager','maid','user') NOT NULL,
  maid_ref              VARCHAR(50)  NULL,
  active                TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at         DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_maid_ref (maid_ref),
  CONSTRAINT chk_users_identifier CHECK (email IS NOT NULL OR phone IS NOT NULL),
  CONSTRAINT chk_users_maid_ref   CHECK (role <> 'maid' OR maid_ref IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only used for role='user'. admin/manager/maid get their access from code (Access.php).
CREATE TABLE IF NOT EXISTS permissions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  module        ENUM('reservations','housekeeping','inventory','reports') NOT NULL,
  access_level  ENUM('view','edit') NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_user_module (user_id, module),
  CONSTRAINT fk_permissions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id = sha256 of the cookie token. The raw token never touches the database.
CREATE TABLE IF NOT EXISTS sessions (
  id            CHAR(64)     NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  csrf_token    CHAR(64)     NOT NULL,
  remember      TINYINT(1)   NOT NULL DEFAULT 1,
  ip_address    VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rotated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier    VARCHAR(150) NOT NULL,
  ip_address    VARCHAR(45)  NOT NULL,
  success       TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_identifier (identifier, attempted_at),
  KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NULL,
  action        VARCHAR(100) NOT NULL,
  target_type   VARCHAR(50)  NULL,
  target_id     VARCHAR(50)  NULL,
  meta          JSON         NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_target (target_type, target_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Filled by the reports cron (Stage 3). Created now so the schema is complete.
CREATE TABLE IF NOT EXISTS report_cache (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_key    VARCHAR(100) NOT NULL,
  period_start  DATE         NOT NULL,
  period_end    DATE         NOT NULL,
  payload       JSON         NOT NULL,
  computed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_period (report_key, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
