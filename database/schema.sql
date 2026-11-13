-- AlertaPlan IA · Claveles — esquema MySQL 8 / MariaDB 10.6+
-- C3 · Base de datos. Cada corte es inmutable una vez aprobado; la línea base se guarda aparte del fin vigente.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS telegram_cargas, telegram_codigos, telegram_vinculos, auditoria, causas, avances_diarios, actividades, cortes, obras, usuarios;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  cargo VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  rol ENUM('planeamiento','produccion','gerencia') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE obras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(160) NOT NULL,
  cliente VARCHAR(160) NULL,
  ubicacion VARCHAR(160) NULL,
  etapa VARCHAR(120) NULL,
  dias_laborables VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
  feriados TEXT NULL,
  umbral_vigilar TINYINT NOT NULL DEFAULT 2,
  ficticia TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cortes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,
  semana SMALLINT NOT NULL,
  fecha_corte DATE NOT NULL,
  fecha_cierre DATE NOT NULL,
  fecha_datos DATE NULL,
  estado ENUM('abierto','aprobado') NOT NULL DEFAULT 'abierto',
  archivo_origen VARCHAR(200) NULL,
  importado_por INT NULL,
  importado_en DATETIME NULL,
  aprobado_por INT NULL,
  aprobado_en DATETIME NULL,
  comentario_aprobacion TEXT NULL,
  ppc DECIMAL(5,1) NULL,
  comprometidas SMALLINT NULL,
  cumplidas SMALLINT NULL,
  snapshot MEDIUMTEXT NULL,
  UNIQUE KEY uq_obra_semana (obra_id, semana),
  CONSTRAINT fk_corte_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE CASCADE,
  CONSTRAINT fk_corte_imp FOREIGN KEY (importado_por) REFERENCES usuarios(id),
  CONSTRAINT fk_corte_apr FOREIGN KEY (aprobado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE actividades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  corte_id INT NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  sector VARCHAR(80) NULL,
  responsable VARCHAR(80) NULL,
  unidad VARCHAR(20) NULL,
  inicio DATE NULL,
  fin DATE NULL,
  fin_base DATE NULL,
  total DECIMAL(14,3) NULL,
  ejecutado DECIMAL(14,3) NULL,
  rendimiento DECIMAL(14,3) NULL,
  predecesoras VARCHAR(200) NULL,
  restriccion VARCHAR(255) NULL,
  liberacion DATE NULL,
  fin_real DATE NULL,
  observacion TEXT NULL,
  fila_origen INT NULL,
  comprometida TINYINT(1) NULL,
  -- resultados del motor (se recalculan en cada cambio)
  estado VARCHAR(12) NULL,
  motivo VARCHAR(400) NULL,
  proyeccion DATE NULL,
  holgura INT NULL,
  atraso INT NULL,
  duracion INT NULL,
  continua TINYINT(1) NULL,
  programado_pct DECIMAL(5,1) NULL,
  ejecutado_pct DECIMAL(5,1) NULL,
  supuestos TEXT NULL,
  errores TEXT NULL,
  -- cierre y reprogramación
  nueva_fecha DATE NULL,
  accion TEXT NULL,
  UNIQUE KEY uq_corte_codigo (corte_id, codigo),
  CONSTRAINT fk_act_corte FOREIGN KEY (corte_id) REFERENCES cortes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE avances_diarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT NOT NULL,
  fecha DATE NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  nota TEXT NULL,
  usuario_id INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_av_act FOREIGN KEY (actividad_id) REFERENCES actividades(id) ON DELETE CASCADE,
  CONSTRAINT fk_av_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE causas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT NOT NULL UNIQUE,
  categoria_ia VARCHAR(20) NOT NULL,
  cita_ia TEXT NULL,
  confianza_ia DECIMAL(4,2) NULL,
  justificacion_ia TEXT NULL,
  modo_ia VARCHAR(20) NOT NULL,
  modelo_ia VARCHAR(80) NULL,
  validacion_ia TEXT NULL,
  estado ENUM('propuesta','aceptada','editada','rechazada') NOT NULL DEFAULT 'propuesta',
  categoria_final VARCHAR(20) NULL,
  comentario TEXT NULL,
  revisado_por INT NULL,
  revisado_en DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_causa_act FOREIGN KEY (actividad_id) REFERENCES actividades(id) ON DELETE CASCADE,
  CONSTRAINT fk_causa_rev FOREIGN KEY (revisado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  accion VARCHAR(60) NOT NULL,
  entidad VARCHAR(30) NOT NULL,
  entidad_id INT NULL,
  detalle TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_aud_fecha (creado_en),
  CONSTRAINT fk_aud_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bot de Telegram: vinculación de chats con usuarios y cargas pendientes de confirmar
CREATE TABLE telegram_vinculos (
  chat_id BIGINT PRIMARY KEY,
  usuario_id INT NOT NULL,
  nombre_telegram VARCHAR(120) NULL,
  obra_id INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tg_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE telegram_codigos (
  codigo VARCHAR(12) PRIMARY KEY,
  usuario_id INT NOT NULL,
  expira_en DATETIME NOT NULL,
  CONSTRAINT fk_tgc_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE telegram_cargas (
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
