-- Crear base de datos
CREATE DATABASE IF NOT EXISTS tickmetrics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tickmetrics;

-- Estados de gestión configurables
CREATE TABLE IF NOT EXISTS estados_gestion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT '#3b82f6',
    orden INT DEFAULT 0,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tickets principales (id viene del Excel, NO es auto-increment)
CREATE TABLE IF NOT EXISTS tickets (
    id INT PRIMARY KEY,
    gravedad VARCHAR(100) DEFAULT 'No definida',
    fecha_apertura DATE NULL,
    tipo_solicitud VARCHAR(100) DEFAULT 'Desconocido',
    resumen TEXT,
    estado_excel VARCHAR(100) DEFAULT 'Abierto',
    cliente VARCHAR(200) DEFAULT '',
    modulo VARCHAR(200) DEFAULT '',
    componente VARCHAR(200) DEFAULT '',
    responsable VARCHAR(200) DEFAULT 'Sin asignar',
    ultima_actualizacion DATE NULL,
    dias_ultima_derivacion INT DEFAULT 0,
    puntaje INT DEFAULT 0,
    iteraciones INT DEFAULT 0,
    fecha_entrega DATE NULL,
    estado_gestion_id INT DEFAULT 1,
    tiempo_estimado DECIMAL(10,2) DEFAULT 0.00,
    tiempo_dedicado DECIMAL(10,2) DEFAULT 0.00,
    fue_gestionado_manualmente TINYINT(1) DEFAULT 0,
    fecha_gestion DATE NULL,
    es_activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (estado_gestion_id) REFERENCES estados_gestion(id)
) ENGINE=InnoDB;

-- Registro de tickets activos por día e histórico de derivación
CREATE TABLE IF NOT EXISTS tickets_activos_dia (
    ticket_id INT NOT NULL,
    fecha DATE NOT NULL,
    dias_ultima_derivacion INT DEFAULT 0,
    PRIMARY KEY (ticket_id, fecha),
    CONSTRAINT fk_activo_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gestión diaria inmutable (una entrada por ticket y día)
CREATE TABLE IF NOT EXISTS gestion_diaria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    fecha DATE NOT NULL,
    tiempo_estimado DECIMAL(10,2) DEFAULT 0.00,
    tiempo_dedicado DECIMAL(10,2) DEFAULT 0.00,
    estado_gestion_id INT NOT NULL DEFAULT 1,
    fue_gestionado TINYINT(1) DEFAULT 0,
    en_proceso TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_fecha (ticket_id, fecha),
    CONSTRAINT fk_gestion_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_gestion_estado FOREIGN KEY (estado_gestion_id) REFERENCES estados_gestion (id)
) ENGINE=InnoDB;

-- Historial de auditoría inmutable
CREATE TABLE IF NOT EXISTS historial_gestion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    campo_modificado VARCHAR(100) NOT NULL,
    valor_anterior TEXT,
    valor_nuevo TEXT,
    fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id),
    INDEX idx_fecha (fecha_cambio)
) ENGINE=InnoDB;

-- Sesiones de importación con snapshot para rollback
CREATE TABLE IF NOT EXISTS importaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archivo_nombre VARCHAR(255) NOT NULL,
    fecha_importacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_insertados INT DEFAULT 0,
    total_actualizados INT DEFAULT 0,
    snapshot_completo LONGTEXT,
    INDEX idx_fecha (fecha_importacion)
) ENGINE=InnoDB;

-- Configuración global del sistema
CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(100) PRIMARY KEY,
    valor TEXT
) ENGINE=InnoDB;

-- Datos iniciales: estados de gestión
INSERT IGNORE INTO estados_gestion (id, nombre, color, orden) VALUES
(1, 'Pendiente', '#94a3b8', 1),
(2, 'Devuelto', '#ef4444', 2),
(3, 'Producción', '#f59e0b', 3),
(4, 'Espera Versión', '#8b5cf6', 4),
(5, 'Cerrado', '#10b981', 5),
(6, 'Liberado', '#3b82f6', 6);

-- Datos iniciales: configuración
INSERT IGNORE INTO configuracion (clave, valor) VALUES
('stale_days_limit', '7'),
('theme', 'dark'),
('private_mode', 'false');
