-- SmartStay ONE — notificări push (Web Push / VAPID). Doar smartconcept_one.
-- Un rând per dispozitiv abonat. endpoint_hash = sha256(endpoint): endpoint-urile sunt prea lungi pentru un index unic.
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  endpoint         TEXT         NOT NULL,
  endpoint_hash    CHAR(64)     NOT NULL,
  p256dh           VARCHAR(120) NOT NULL,
  auth             VARCHAR(40)  NOT NULL,
  user_agent       VARCHAR(255) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_success_at  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
