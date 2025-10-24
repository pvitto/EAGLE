<?php
require 'db_connection.php';

$name = 'Test Checkinero';
$email = 'checkinero@example.com';
$password = 'password';
$role = 'Checkinero';
$gender = 'M';

// Hash de la contraseña
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insertar usuario
$stmt = $conn->prepare("INSERT INTO users (name, email, password, role, gender) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $name, $email, $hashed_password, $role, $gender);

if ($stmt->execute()) {
    echo "Usuario 'Test Checkinero' creado con éxito.\n";
} else {
    echo "Error al crear el usuario: " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();
?>
