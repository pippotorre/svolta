-- =====================================================================
--  sVolta – circolari Liceo Volta
--  Inizializzazione del database MySQL
--
--  Come usarlo in phpMyAdmin:
--    1. seleziona il database nella colonna di sinistra
--    2. scheda "Importa" > scegli questo file > "Esegui"
--
--  Il database è condiviso con eventuali altre app: ogni app usa
--  il proprio prefisso. Tutte le tabelle di questa app iniziano
--  con "svolta_". Lo script si può rieseguire senza danni
--  (crea solo le tabelle che mancano, non cancella nulla).
--
--  Dopo l'importazione apri una volta install.php?token=... :
--  crea le chiavi per le notifiche, che non si possono generare in SQL.
-- =====================================================================

SET NAMES utf8mb4;

-- Impostazioni dell'app (chiavi delle notifiche, limiti delle prove)
CREATE TABLE IF NOT EXISTS `svolta_settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='sVolta: impostazioni e chiavi VAPID';

-- Telefoni iscritti alle notifiche (solo dati tecnici anonimi)
CREATE TABLE IF NOT EXISTS `svolta_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint_hash` CHAR(64) NOT NULL,
  `endpoint` TEXT NOT NULL,
  `p256dh` VARCHAR(255) NOT NULL,
  `auth` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `last_ok_at` DATETIME NULL DEFAULT NULL,
  `fail_count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_endpoint_hash` (`endpoint_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='sVolta: dispositivi iscritti alle notifiche push';

-- Circolari viste nel feed e quando sono state notificate
CREATE TABLE IF NOT EXISTS `svolta_circolari` (
  `guid` VARCHAR(64) NOT NULL,
  `numero` INT NULL DEFAULT NULL,
  `oggetto` TEXT NOT NULL,
  `link` TEXT NOT NULL,
  `pub_date` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `notified_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`guid`),
  KEY `idx_pending` (`notified_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='sVolta: circolari lette dal feed e stato delle notifiche';

-- Registro degli invii (diagnostica: esito di ogni notifica e di ogni prova)
-- Viene creata anche in automatico dall'app al primo invio.
CREATE TABLE IF NOT EXISTS `svolta_invii` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL,
  `tipo` VARCHAR(16) NOT NULL,
  `origine` VARCHAR(16) NOT NULL,
  `guid` VARCHAR(64) NULL DEFAULT NULL,
  `numero` INT NULL DEFAULT NULL,
  `oggetto` TEXT NULL,
  `iscritti` INT NOT NULL DEFAULT 0,
  `consegnate` INT NOT NULL DEFAULT 0,
  `fallite` INT NOT NULL DEFAULT 0,
  `rimosse` INT NOT NULL DEFAULT 0,
  `codici` VARCHAR(255) NULL DEFAULT NULL,
  `errore` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='sVolta: registro degli invii delle notifiche';
