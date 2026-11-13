-- Migración para bases ya instaladas (no borra datos)
SET NAMES utf8mb4;
-- Bot de Telegram: vinculación de chats con usuarios y cargas pendientes de confirmar
CREATE TABLE IF NOT EXISTS telegram_vinculos (
  chat_id BIGINT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre_telegram VARCHAR(120) NULL,
  obra_id INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tg_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_codigos (
  codigo VARCHAR(12) PRIMARY KEY,
  usuario_id INT NOT NULL,
  expira_en DATETIME NOT NULL,
  CONSTRAINT fk_tgc_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_cargas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT NOT NULL,
  usuario_id INT NOT NULL,
  obra_id INT NOT NULL,
  semana SMALLINT NOT NULL,
  fecha_corte DATE NOT NULL,
  fecha_cierre DATE NOT NULL,
  fecha_datos DATE NULL,
  archivo VARCHAR(200) NOT NULL,
  ruta VARCHAR(400) NOT NULL,
  estado ENUM('pendiente','importada','cancelada') NOT NULL DEFAULT 'pendiente',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
