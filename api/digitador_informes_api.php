<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

// Muestra todos los servicios que ya fueron cerrados por el digitador
$query = "
    SELECT 
        ci.id, ci.invoice_number as planilla, ci.seal_number as sello, oc.total_counted as total, 
        f.name as fondo, c.name as cliente
    FROM check_ins ci
    JOIN operator_counts oc ON ci.id = oc.check_in_id
    JOIN clients c ON ci.client_id = c.id
    LEFT JOIN funds f ON ci.fund_id = f.id
    WHERE ci.digitador_status = 'Cerrado'
    ORDER BY ci.closed_by_digitador_at DESC
";

$result = $conn->query($query);
$servicios = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $servicios[] = $row;
    }
}

echo json_encode($servicios);
$conn->close();
?>