<?php
session_start();
require 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        header('Location: login.php?error=Email y contraseña son requeridos');
        exit;
    }

    $stmt = $conn->prepare("SELECT id, name, email, role, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // ¡CAMBIO IMPORTANTE!
        // Ahora se comparan las contraseñas como texto simple.
        // Esto NO es seguro y solo se debe usar para prototipos locales.
        if ($password === $user['password']) {
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            header('Location: index.php');
            exit;
        } else {
            header('Location: login.php?error=Contraseña incorrecta');
            exit;
        }
    } else {
        header('Location: login.php?error=Usuario no encontrado');
        exit;
    }

    $stmt->close();
    $conn->close();
}

