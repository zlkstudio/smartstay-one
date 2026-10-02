-- SmartStay ONE — Etapa 2 (Rezervări + Housekeeping)
-- Only smartconcept_one is touched. Legacy databases keep their own schema.

-- WhatsApp outreach (review / rebooking). Replaces reservations/data/whatsapp_contacted.json,
-- now shared across devices and users. One row per reservation + message type.
CREATE TABLE IF NOT EXISTS whatsapp_outreach (
  reservation_id  VARCHAR(50)  NOT NULL,
  msg_type        ENUM('w1','w2','w3') NOT NULL,
  platform        VARCHAR(20)  NULL,
  sent_by         INT UNSIGNED NULL,
  sent_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reservation_id, msg_type),
  KEY idx_outreach_sent (sent_at),
  CONSTRAINT fk_outreach_user FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
