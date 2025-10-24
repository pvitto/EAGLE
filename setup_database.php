<?php
header('Content-Type: text/plain; charset=utf-8');

require 'db_connection.php';

if ($conn->connect_error) {
    die("Error de Conexión: " . $conn->connect_error);
}

echo "Conexión a la base de datos establecida con éxito.\n\n";

// 1. Crear la tabla `client_sites`
$sql_create_sites_table = "
CREATE TABLE IF NOT EXISTS `client_sites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

echo "Intentando crear la tabla 'client_sites' si no existe...\n";
if ($conn->query($sql_create_sites_table) === TRUE) {
    echo "Éxito: La tabla 'client_sites' se ha creado correctamente o ya existía.\n";
} else {
    echo "Error al crear la tabla 'client_sites': " . $conn->error . "\n";
}

// 2. Añadir la columna `client_site_id` a `check_ins`
$sql_add_column_to_checkins = "
ALTER TABLE `check_ins`
ADD COLUMN `client_site_id` INT NULL DEFAULT NULL AFTER `client_id`,
ADD INDEX `idx_client_site_id` (`client_site_id`);
";

// Verificar primero si la columna ya existe
$result = $conn->query("SHOW COLUMNS FROM `check_ins` LIKE 'client_site_id'");
if ($result->num_rows == 0) {
    echo "\nIntentando añadir la columna 'client_site_id' a la tabla 'check_ins'...\n";
    if ($conn->query($sql_add_column_to_checkins) === TRUE) {
        echo "Éxito: La columna 'client_site_id' y su índice se han añadido correctamente.\n";
    } else {
        echo "Error al añadir la columna 'client_site_id': " . $conn->error . "\n";
    }
} else {
    echo "\nInformación: La columna 'client_site_id' ya existe en la tabla 'check_ins'. No se requiere ninguna acción.\n";
}

// 3. (Opcional) Añadir la FOREIGN KEY constraint
$sql_add_fk_constraint = "
ALTER TABLE `check_ins`
ADD CONSTRAINT `fk_checkins_client_site`
FOREIGN KEY (`client_site_id`) REFERENCES `client_sites`(`id`) ON DELETE SET NULL;
";

// Verificar si la constraint ya existe
$fk_exists_result = $conn->query("
    SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'check_ins'
    AND CONSTRAINT_NAME = 'fk_checkins_client_site';
");

if ($fk_exists_result->num_rows == 0) {
    echo "\nIntentando añadir la Foreign Key constraint a 'client_site_id'...\n";
    if ($conn->query($sql_add_fk_constraint) === TRUE) {
        echo "Éxito: La Foreign Key 'fk_checkins_client_site' se ha añadido correctamente.\n";
    } else {
        echo "Error al añadir la Foreign Key: " . $conn->error . "\n";
    }
} else {
    echo "\nInformación: La Foreign Key 'fk_checkins_client_site' ya existe. No se requiere ninguna acción.\n";
}


echo "\nScript de configuración de base de datos finalizado.\n";

$conn->close();
?>