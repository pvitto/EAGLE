<?php
session_start();
require 'check_session.php';
if ($_SESSION['user_role'] !== 'Admin') {
    header('Location: index.php');
    exit;
}
require 'db_connection.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Rutas - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Gestionar Rutas</h1>
            <a href="index.php" class="text-blue-600 hover:underline">Volver al Panel</a>
        </div>
        
        <div class="mb-8 p-4 border rounded-lg">
            <h2 class="text-xl font-semibold mb-4">Agregar Nueva Ruta</h2>
            <form id="add-route-form" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label for="route-name" class="block text-sm font-medium text-gray-700">Nombre de la Ruta</label>
                    <input type="text" id="route-name" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <div>
                    <label for="route-description" class="block text-sm font-medium text-gray-700">Descripción (Opcional)</label>
                    <input type="text" id="route-description" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Guardar Ruta</button>
            </form>
        </div>

        <div>
            <h2 class="text-xl font-semibold mb-4">Rutas Existentes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left">
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">Descripción</th>
                            <th class="px-6 py-3">Fecha de Creación</th>
                        </tr>
                    </thead>
                    <tbody id="routes-table-body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const apiUrl = 'api/routes_api.php';

        async function fetchRoutes() {
            try {
                const response = await fetch(apiUrl);
                const routes = await response.json();
                const tbody = document.getElementById('routes-table-body');
                tbody.innerHTML = '';
                if (routes.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center p-4">No hay rutas registradas.</td></tr>';
                } else {
                    routes.forEach(route => {
                        const row = `
                            <tr class="border-b">
                                <td class="px-6 py-4 font-medium">${route.name}</td>
                                <td class="px-6 py-4">${route.description || 'N/A'}</td>
                                <td class="px-6 py-4">${new Date(route.created_at).toLocaleString('es-CO')}</td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                }
            } catch (error) { console.error('Error fetching routes:', error); }
        }

        document.getElementById('add-route-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const name = document.getElementById('route-name').value;
            const description = document.getElementById('route-description').value;
            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, description })
                });
                const result = await response.json();
                if (result.success) {
                    this.reset();
                    fetchRoutes();
                } else { alert('Error: ' + result.error); }
            } catch (error) { alert('Error de conexión.'); }
        });

        document.addEventListener('DOMContentLoaded', fetchRoutes);
    </script>
</body>
</html>