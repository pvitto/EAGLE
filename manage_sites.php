<?php
$isContentOnly = isset($_GET['content_only']) && $_GET['content_only'] == '1';
if (!$isContentOnly) {
    require 'config.php';
    require 'check_session.php';
    if ($_SESSION['user_role'] !== 'Admin') {
        header('Location: index.php');
        exit;
    }
}
require 'db_connection.php';

$clients = [];
$clients_result = $conn->query("SELECT id, name FROM clients ORDER BY name ASC");
if ($clients_result) {
    while ($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

if (!$isContentOnly) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Sedes - EAGLE 3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
<?php } ?>
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-xl shadow-lg">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Gestionar Sedes</h1>

        <div class="mb-6">
            <label for="client-select-sites" class="block text-sm font-medium text-gray-700">Seleccione un Cliente</label>
            <select id="client-select-sites" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                <option value="">-- Cargar Clientes --</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="sites-content-area" class="hidden">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="p-4 border rounded-lg">
                    <h2 class="text-xl font-semibold mb-4">Agregar Nueva Sede</h2>
                    <form id="add-site-form-sites-page" class="space-y-4">
                        <input type="hidden" id="selected-client-id-sites-page">
                        <div>
                            <label for="site-name-sites-page" class="block text-sm font-medium">Nombre de la Sede</label>
                            <input type="text" id="site-name-sites-page" required class="mt-1 block w-full border border-gray-300 rounded-md p-2">
                        </div>
                        <div>
                            <label for="site-address-sites-page" class="block text-sm font-medium">Dirección (Opcional)</label>
                            <input type="text" id="site-address-sites-page" class="mt-1 block w-full border border-gray-300 rounded-md p-2">
                        </div>
                        <button type="submit" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-md">Agregar Sede</button>
                    </form>
                </div>
                <div>
                    <h2 class="text-xl font-semibold mb-4">Sedes Existentes</h2>
                    <div id="sites-list-sites-page" class="space-y-2 max-h-96 overflow-y-auto">
                        <p class="text-center text-gray-400">Seleccione un cliente para ver sus sedes.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
function initializeManageSites() {
    const clientSelect = document.getElementById('client-select-sites');
    const sitesContentArea = document.getElementById('sites-content-area');
    const selectedClientIdInput = document.getElementById('selected-client-id-sites-page');
    const addSiteForm = document.getElementById('add-site-form-sites-page');
    const sitesListDiv = document.getElementById('sites-list-sites-page');
    const apiUrlSites = '/api/sites_api.php';

    async function fetchSites(clientId) {
        sitesListDiv.innerHTML = '<p>Cargando...</p>';
        try {
            const response = await fetch(`${apiUrlSites}?client_id=${clientId}`);
            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            sitesListDiv.innerHTML = '';
            if (result.data.length === 0) {
                sitesListDiv.innerHTML = '<p>No hay sedes para este cliente.</p>';
            } else {
                result.data.forEach(site => {
                    const siteEl = document.createElement('div');
                    siteEl.className = 'p-3 border rounded-md flex justify-between items-center';
                    siteEl.innerHTML = `
                        <div>
                            <p class="font-semibold">${site.name}</p>
                            <p class="text-xs text-gray-500">${site.address || 'Sin dirección'}</p>
                        </div>
                        <button data-site-id="${site.id}" class="delete-site-btn-sites-page text-red-500 font-semibold text-xs">Eliminar</button>
                    `;
                    sitesListDiv.appendChild(siteEl);
                });
            }
        } catch (error) {
            sitesListDiv.innerHTML = `<p class="text-red-500">Error al cargar sedes: ${error.message}</p>`;
        }
    }

    clientSelect.addEventListener('change', () => {
        const clientId = clientSelect.value;
        if (clientId) {
            sitesContentArea.classList.remove('hidden');
            selectedClientIdInput.value = clientId;
            fetchSites(clientId);
        } else {
            sitesContentArea.classList.add('hidden');
        }
    });

    addSiteForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const clientId = selectedClientIdInput.value;
        const name = document.getElementById('site-name-sites-page').value;
        const address = document.getElementById('site-address-sites-page').value;

        try {
            const response = await fetch(apiUrlSites, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ client_id: clientId, name, address })
            });

            if (!response.ok) {
                // Si la respuesta no es OK, intenta leer el cuerpo como texto
                const errorText = await response.text();
                throw new Error(`Error del servidor: ${response.status} - ${errorText}`);
            }

            const result = await response.json();
            if (!result.success) {
                throw new Error(result.error || 'Ocurrió un error desconocido en la API.');
            }

            addSiteForm.reset();
            fetchSites(clientId);
        } catch (error) {
            // Ahora el error.message contendrá el HTML si la respuesta no es JSON
            alert(`Error al agregar sede: ${error.message}`);
        }
    });

    sitesListDiv.addEventListener('click', async (e) => {
        if (e.target.classList.contains('delete-site-btn-sites-page')) {
            const siteId = e.target.dataset.siteId;
            if (confirm('¿Está seguro de que desea eliminar esta sede?')) {
                try {
                    const response = await fetch(`${apiUrlSites}?site_id=${siteId}`, { method: 'DELETE' });
                    const result = await response.json();
                    if (!result.success) throw new Error(result.error);
                    fetchSites(selectedClientIdInput.value);
                } catch (error) {
                    alert(`Error al eliminar sede: ${error.message}`);
                }
            }
        }
    });
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initializeManageSites();
} else {
    document.addEventListener('DOMContentLoaded', initializeManageSites);
}
</script>

<?php if (!$isContentOnly) { ?>
</body>
</html>
<?php }
$conn->close();
?>