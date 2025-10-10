<?php
// Configuración de la conexión a la base de datos para XAMPP
$servername = "localhost"; // Generalmente es "localhost" en XAMPP
$username = "root";      // Usuario por defecto en XAMPP
$password = "";          // Contraseña por defecto en XAMPP es vacía
$dbname = "eagle_3_db";  // El nombre de la base de datos que creamos

// Crear la conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar la conexión
if ($conn->connect_error) {
  // Detiene la ejecución y muestra el error si la conexión falla
  die("Connection failed: " . $conn->connect_error);
}

// Establecer el charset a UTF-8 para soportar caracteres especiales
$conn->set_charset("utf8mb4");
?>
