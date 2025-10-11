<?php
// Configuración de la conexión a la base de datos para XAMPP
$servername = "localhost"; // El servidor de la base de datos es 'localhost' (usualmente en el puerto 3306 por defecto)
$username = "root";        // Usuario por defecto en XAMPP
$password = "";            // Contraseña por defecto en XAMPP es vacía
$dbname = "eagle_3_db";    // El nombre de la base de datos que creamos

// Crear la conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar la conexión
if ($conn->connect_error) {
  // Si la conexión falla, muestra un error y detiene el script.
  die("Conexión fallida: " . $conn->connect_error);
}

// Establecer el charset a UTF-8 para soportar caracteres especiales
$conn->set_charset("utf8mb4");
?>

