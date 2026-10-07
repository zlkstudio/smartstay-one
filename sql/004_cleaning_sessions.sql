-- SmartStay ONE — cronometrarea curățeniilor din jurnalul Nuki. Doar smartconcept_one.
-- O sesiune = menajera descuie cu codul ei („Ioana Menaj”) → următoarea încuiere = gata.
-- started_at / ended_at / cleaning_date = ora LOCALĂ (Europe/Bucharest), ca în jurnalul Nuki afișat — nu UTC.
-- uq_session_start face sincronizarea idempotentă (cron + pagina pot rula în același minut).
CREATE TABLE IF NOT EXISTS cleaning_sessions (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  cleaning_date   DATE              NOT NULL,
  apartment       VARCHAR(10)       NOT NULL,
  round_no        TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  maid_name       VARCHAR(50)       NOT NULL,
  reservation_id  VARCHAR(50)       NULL,
  nights          SMALLINT UNSIGNED NULL,
  unit            ENUM('studio','apartment') NOT NULL,
  target_min      SMALLINT UNSIGNED NOT NULL,
  started_at      DATETIME          NOT NULL,
  ended_at        DATETIME          NULL,
  duration_min    SMALLINT UNSIGNED NULL,
  end_via         VARCHAR(60)       NULL,
  code_status     VARCHAR(20)       NULL,
  code_note       VARCHAR(255)      NULL,
  notified        TINYINT(1)        NOT NULL DEFAULT 0,
  created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_start (apartment, started_at),
  KEY idx_session_date (cleaning_date),
  KEY idx_session_ended (ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
