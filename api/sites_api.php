<?php
header('Content-Type: application/json');
require '../db_connection.php';
require '../check_session.php';

if ($_SESSION['user_role'] !== 'Admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!isset($_GET['client_id'])) {
        echo json_encode(['success' => false, 'error' => 'Falta el ID del cliente.']);
        exit;
    }
    $client_id = intval($_GET['client_id']);

    $stmt = $conn->prepare("SELECT id, name, address FROM client_sites WHERE client_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sites = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $sites]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['client_id'], $data['name']) || empty($data['name'])) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos (ID de cliente, nombre).']);
        exit;
    }

    $client_id = intval($data['client_id']);
    $name = trim($data['name']);
    $address = isset($data['address']) ? trim($data['address']) : null;

    $stmt = $conn->prepare("INSERT INTO client_sites (client_id, name, address) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $client_id, $name, $address);

    if ($stmt->execute()) {
        $new_site_id = $stmt->insert_id;
        echo json_encode(['success' => true, 'message' => 'Sede creada con éxito.', 'new_site_id' => $new_site_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al crear la sede: ' . $stmt->error]);
    }
    $stmt->close();

} elseif ($method === 'DELETE') {
    if (!isset($_GET['site_id'])) {
        echo json_encode(['success' => false, 'error' => 'Falta el ID de la sede.']);
        exit;
    }
    $site_id = intval($_GET['site_id']);

    $stmt = $conn->prepare("DELETE FROM client_sites WHERE id = ?");
    $stmt->bind_param("i", $site_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Sede eliminada con éxito.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'La sede no fue encontrada o ya fue eliminada.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al eliminar la sede: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
}

$conn->close();
?>
