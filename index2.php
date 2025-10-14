<?php
session_start();
require 'check_session.php';
require 'db_connection.php';

// Cargar todos los usuarios
$all_users = [];
$users_result = $conn->query("SELECT id, name, role, email FROM users ORDER BY name ASC");
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $all_users[] = $row;
    }
}
$admin_users_list = ($_SESSION['user_role'] === 'Admin') ? $all_users : [];

// --- LÓGICA DE SEPARACIÓN DE ITEMS PENDIENTES ---
$priority_items = []; 
$non_priority_items = []; 

// 1. Cargar Alertas Pendientes (que no estén resueltas)
$alerts_result = $conn->query(
    "SELECT a.*, t.id as task_id, t.assigned_to_user_id, u_assigned.name as assigned_to_name, t.type as task_type, t.instruction as task_instruction
     FROM alerts a
     LEFT JOIN tasks t ON t.id = (SELECT MAX(id) FROM tasks WHERE alert_id = a.id AND status != 'Cancelada')
     LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
     WHERE a.status != 'Resuelta'"
);
if ($alerts_result) {
    while ($row = $alerts_result->fetch_assoc()) {
        $row['item_type'] = 'alert';
        if ($row['priority'] === 'Critica' || $row['priority'] === 'Alta') { $priority_items[] = $row; } 
        else { $non_priority_items[] = $row; }
    }
}

// 2. Cargar Tareas Manuales Pendientes
$manual_tasks_result = $conn->query(
    "SELECT t.id, t.id as task_id, t.title, t.instruction, t.priority, t.assigned_to_user_id, u.name as assigned_to_name 
     FROM tasks t 
     JOIN users u ON t.assigned_to_user_id = u.id 
     WHERE t.alert_id IS NULL AND t.type = 'Manual' AND t.status = 'Pendiente'"
);
if ($manual_tasks_result) {
    while($row = $manual_tasks_result->fetch_assoc()) {
        $row['item_type'] = 'manual_task';
        if ($row['priority'] === 'Alta') { $priority_items[] = $row; } 
        else { $non_priority_items[] = $row; }
    }
}

// 3. Ordenar las listas por prioridad
$priority_order = ['Critica' => 3, 'Alta' => 2, 'Media' => 1, 'Baja' => 0];
usort($priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['priority']] ?? 0) <=> ($priority_order[$a['priority']] ?? 0); });
usort($non_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['priority']] ?? 0) <=> ($priority_order[$a['priority']] ?? 0); });

// 4. Cargar Tareas Completadas (Solo para Admin)
$completed_tasks = [];
if ($_SESSION['user_role'] === 'Admin') {
    $completed_result = $conn->query(
        "SELECT 
            t.id,
            COALESCE(a.title, t.title) as title,
            t.instruction,
            u.name as completed_by,
            t.created_at,
            t.completed_at,
            TIMEDIFF(t.completed_at, t.created_at) as response_time
         FROM tasks t
         JOIN users u ON t.assigned_to_user_id = u.id
         LEFT JOIN alerts a ON t.alert_id = a.id
         WHERE t.status = 'Completada'
         ORDER BY t.completed_at DESC"
    );
    if ($completed_result) { while($row = $completed_result->fetch_assoc()){ $completed_tasks[] = $row; } }
}

// Cargar recaudos, recordatorios y contadores
$recaudos = [];
$recaudos_result = $conn->query("SELECT * FROM recaudos ORDER BY close_time_scheduled ASC");
if ($recaudos_result) { while ($row = $recaudos_result->fetch_assoc()) { $recaudos[] = $row; } }

$user_reminders = [];
$current_user_id = $_SESSION['user_id'];
$reminders_result = $conn->query("SELECT id, message, created_at FROM reminders WHERE user_id = $current_user_id AND is_read = 0 ORDER BY created_at DESC");
if($reminders_result) { while($row = $reminders_result->fetch_assoc()){ $user_reminders[] = $row; } }


// Contadores para el resumen
$total_alerts_count = $alerts_result ? $alerts_result->num_rows : 0;
$priority_alerts_count = 0;
foreach($priority_items as $item){
    if($item['item_type'] === 'alert'){
        $priority_alerts_count++;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAGLE 3.0 - Sistema de Alertas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .nav-tab { cursor: pointer; padding: 0.75rem 1.5rem; font-weight: 500; color: #4b5563; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-tab:hover { color: #111827; }
        .nav-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        #user-modal-overlay, #reminders-panel { transition: opacity 0.3s ease; }
        @keyframes pulse-bg-red { 0%, 100% { background-color: #fee2e2; } 50% { background-color: #fecaca; } }
        .animate-pulse-red { animation: pulse-bg-red 2s infinite; }
        @keyframes pulse-bg-orange { 0%, 100% { background-color: #ffedd5; } 50% { background-color: #fed7aa; } }
        .animate-pulse-orange { animation: pulse-bg-orange 2.5s infinite; }
        .task-form, .cash-breakdown { transition: all 0.4s ease-in-out; max-height: 0; overflow: hidden; padding-top: 0; padding-bottom: 0; opacity: 0;}
        .task-form.active, .cash-breakdown.active { max-height: 500px; padding-top: 1rem; padding-bottom: 1rem; opacity: 1;}
        .details-row { border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="user-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div id="user-modal" class="bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all scale-95 opacity-0">
            <div class="p-6">
                <div class="flex justify-between items-center pb-3 border-b"><h3 id="modal-title" class="text-xl font-bold text-gray-900"></h3><button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button></div>
                <form id="user-form" class="mt-6 space-y-4"><input type="hidden" id="user-id" name="id"><div><label for="user-name" class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label><input type="text" id="user-name" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div><div><label for="user-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" id="user-email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div><div><label for="user-role" class="block text-sm font-medium text-gray-700 mb-1">Rol</label><select id="user-role" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md" required><option value="Operador">Operador</option><option value="Checkinero">Checkinero</option><option value="Digitador">Digitador</option><option value="Admin">Admin</option></select></div><div><label for="user-password" class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label><input type="password" id="user-password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md"><p id="password-hint" class="text-xs text-gray-500 mt-1"></p></div><div class="pt-4 flex justify-end space-x-3"><button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">Guardar</button></div></form>
            </div>
        </div>
    </div>
    
    <div id="app" class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">
        <header class="flex flex-col sm:flex-row justify-between sm:items-center mb-6 border-b pb-4">
             <div><h1 class="text-2xl md:text-3xl font-bold text-gray-900">EAGLE 3.0</h1><p class="text-sm text-gray-500">Sistema Integrado de Operaciones y Alertas</p></div>
            <div class="text-sm text-gray-600 mt-2 sm:mt-0 flex items-center space-x-4">
                <div class="text-right">
                    <p class="font-semibold">Bienvenido, <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <a href="logout.php" class="text-blue-600 hover:underline">Cerrar Sesión</a>
                </div>
                <div class="relative">
                    <button onclick="toggleReminders()" class="relative text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                        <?php if(count($user_reminders) > 0): ?>
                        <span class="absolute top-0 right-0 block h-2 w-2 rounded-full bg-red-500 ring-2 ring-white"></span>
                        <?php endif; ?>
                    </button>
                    <div id="reminders-panel" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl p-4 hidden z-20">
                        <h4 class="font-bold text-gray-800 mb-2">Tus Recordatorios</h4>
                        <div id="reminders-list" class="space-y-2 max-h-64 overflow-y-auto">
                            <?php if(empty($user_reminders)): ?>
                                <p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>
                            <?php else: foreach($user_reminders as $reminder): ?>
                                <div class="p-2 bg-blue-50 rounded-md border border-blue-200 text-sm">
                                    <p class="text-gray-700"><?php echo htmlspecialchars($reminder['message']); ?></p>
                                    <div class="flex justify-between items-center mt-1">
                                        <p class="text-xs text-gray-400"><?php echo date('d M, h:i a', strtotime($reminder['created_at'])); ?></p>
                                        <button onclick="markReminderAsRead(<?php echo $reminder['id']; ?>, this)" class="text-xs text-blue-600 hover:underline">Marcar como leído</button>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <nav class="mb-8">
            <div class="border-b border-gray-200">
                <div class="-mb-px flex space-x-4">
                    <button id="tab-operaciones" class="nav-tab active" onclick="switchTab('operaciones')">Panel de Operaciones</button>
                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                        <button id="tab-roles" class="nav-tab" onclick="switchTab('roles')">Gestión de Roles</button>
                        <button id="tab-trazabilidad" class="nav-tab" onclick="switchTab('trazabilidad')">Trazabilidad</button>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <main>
            <div id="content-operaciones">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Recaudos de Hoy</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">$</div></div><p class="text-3xl font-bold text-gray-900 mt-2">$197.030.000</p><p class="text-sm text-green-600 mt-2">▲ 12% vs ayer</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Cierres Pendientes</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">🕔</div></div><p class="text-3xl font-bold text-gray-900 mt-2">3</p><p class="text-sm text-gray-500 mt-2">Programados para hoy</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Alertas Activas</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">❗</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo $total_alerts_count; ?></p><p class="text-sm text-gray-500 mt-2"><?php echo $priority_alerts_count; ?> Prioritarias</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Tasa de Cumplimiento</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">📈</div></div><p class="text-3xl font-bold text-gray-900 mt-2">94%</p><p class="text-sm text-green-600 mt-2">▲ 3% vs semana pasada</p></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-4">
                        <h2 class="text-xl font-bold text-gray-900">Alertas y Tareas Prioritarias</h2>
                        
                        <?php foreach ($priority_items as $item): ?>
                            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                                <?php
                                $is_manual = $item['item_type'] === 'manual_task';
                                $id = $is_manual ? $item['task_id'] : $item['id'];
                                $is_assigned = $is_manual || ($item['item_type'] === 'alert' && $item['status'] === 'Asignada');
                                $color = ['Critica' => ['bg' => 'bg-red-100', 'border' => 'border-red-500', 'text' => 'text-red-800'], 'Alta' => ['bg' => 'bg-orange-100', 'border' => 'border-orange-500', 'text' => 'text-orange-800'], 'Media' => ['bg' => 'bg-yellow-100', 'border' => 'border-yellow-400', 'text' => 'text-yellow-800']][$item['priority']] ?? [];
                                ?>
                                <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                    <div class="flex justify-between items-start">
                                        <p class="font-semibold <?php echo $color['text']; ?> text-lg"><?php echo ($is_manual ? 'Tarea Manual: ' : '') . htmlspecialchars($item['title']); ?></p>
                                        <?php if ($is_assigned): ?>
                                            <button onclick="completeTask(<?php echo $item['task_id']; ?>)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300" title="Marcar como completada">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? $item['instruction'] : $item['description']); ?></p>
                                    <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                        <button onclick="toggleForm('assign-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo $is_assigned ? 'Re-asignar' : 'Asignar'; ?></button>
                                        <button onclick="toggleForm('reminder-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                        <div class="flex-grow text-right text-sm"><?php if($is_assigned): ?><span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?></span><?php endif; ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="bg-white p-6 rounded-xl shadow-sm mt-8">
                             <h2 class="text-lg font-semibold mb-4 text-gray-900">Recaudos del Día</h2>
                             <div class="overflow-x-auto">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-xs text-gray-500 uppercase bg-gray-50"><tr><th class="px-6 py-3">Tienda</th><th class="px-6 py-3">Monto</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3"></th></tr></thead>
                                    <tbody id="recaudos-tbody">
                                        <?php foreach ($recaudos as $recaudo): ?>
                                        <tr class="border-b"><td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['store_name']); ?></td><td class="px-6 py-4 font-mono">$<?php echo number_format($recaudo['expected_amount'], 0, ',', '.'); ?></td><td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full <?php echo $recaudo['status'] === 'Completado' ? 'bg-green-100 text-green-800' : ($recaudo['status'] === 'En Progreso' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'); ?>"><?php echo $recaudo['status']; ?></span></td><td class="px-6 py-4 text-right"><?php if ($recaudo['status'] === 'Completado'): ?><button onclick="toggleBreakdown(<?php echo $recaudo['id']; ?>)" class="text-blue-600 text-xs font-semibold">Desglose</button><?php endif; ?></td></tr>
                                        <tr class="details-row hidden" id="breakdown-row-<?php echo $recaudo['id']; ?>"><td colspan="4" class="p-0"><div id="breakdown-content-<?php echo $recaudo['id']; ?>" class="cash-breakdown bg-gray-50"><div class="p-4 grid grid-cols-3 gap-x-8 gap-y-2 text-xs"><span><strong>$100.000:</strong> <?php echo number_format($recaudo['bills_100k'] ?? 0); ?></span><span><strong>$50.000:</strong> <?php echo number_format($recaudo['bills_50k'] ?? 0); ?></span><span><strong>$20.000:</strong> <?php echo number_format($recaudo['bills_20k'] ?? 0); ?></span><span><strong>$10.000:</strong> <?php echo number_format($recaudo['bills_10k'] ?? 0); ?></span><span><strong>$5.000:</strong> <?php echo number_format($recaudo['bills_5k'] ?? 0); ?></span><span><strong>$2.000:</strong> <?php echo number_format($recaudo['bills_2k'] ?? 0); ?></span><span class="col-span-3"><strong>Monedas:</strong> $<?php echo number_format($recaudo['coins'] ?? 0, 0, ',', '.'); ?></span></div></div></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-8">
                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Crear Tarea Manual</h2>
                            <form id="manual-task-form" class="space-y-3">
                                <div><label for="manual-task-title" class="text-sm font-medium">Título</label><input type="text" id="manual-task-title" required class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                <div><label for="manual-task-desc" class="text-sm font-medium">Descripción</label><textarea id="manual-task-desc" rows="3" class="w-full p-2 text-sm border rounded-md mt-1"></textarea></div>
                                <div><label for="manual-task-priority" class="text-sm font-medium">Prioridad</label><select id="manual-task-priority" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="Alta">Alta</option><option value="Media" selected>Media</option><option value="Baja">Baja</option></select></div>
                                <div><label for="manual-task-user" class="text-sm font-medium">Asignar a</label><select id="manual-task-user" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="">Seleccionar...</option><?php foreach ($all_users as $user):?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo $user['role']; ?>)</option><?php endforeach; ?></select></div>
                                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 rounded-md">Crear Tarea</button>
                            </form>
                        </div>

                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Tareas y Alertas no Prioritarias</h2>
                            <div id="non-priority-list" class="space-y-3">
                               <?php if (empty($non_priority_items)): ?><p class="text-sm text-gray-500">No hay items no prioritarios.</p>
                                <?php else: foreach ($non_priority_items as $item): ?>
                                <?php 
                                    $item_color_class = 'bg-gray-100 text-gray-800';
                                    if ($item['priority'] === 'Media') $item_color_class = 'bg-yellow-100 text-yellow-800';
                                ?>
                                <div class="p-3 bg-gray-50 rounded-lg border">
                                    <div class="flex justify-between items-start">
                                        <p class="pr-2 font-medium text-sm"><?php echo htmlspecialchars($item['title']); ?></p>
                                        <span class="text-xs font-bold px-2 py-0.5 rounded-full <?php echo $item_color_class; ?>"><?php echo htmlspecialchars($item['priority']); ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($item['item_type'] === 'alert' ? $item['description'] : $item['instruction']); ?></p>
                                    <?php if(isset($item['assigned_to_name'])): ?>
                                    <p class="text-xs text-green-700 font-semibold mt-2">Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($_SESSION['user_role'] === 'Admin'): ?>
            <div id="content-roles" class="hidden">
                 <div class="flex justify-between items-center mb-4"><h2 class="text-xl font-bold">Gestionar Usuarios</h2><button onclick="openModal()" class="bg-green-600 text-white font-semibold px-4 py-2 rounded-lg">Agregar Usuario</button></div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm"><thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">Email</th><th class="px-6 py-3">Rol</th><th class="px-6 py-3 text-center">Acciones</th></tr></thead><tbody id="user-table-body"></tbody></table>
                </div>
            </div>
            <div id="content-trazabilidad" class="hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Trazabilidad de Tareas Completadas</h2>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Tarea</th><th class="px-6 py-3">Completada por</th><th class="px-6 py-3">Tiempo de Respuesta</th><th class="px-6 py-3">Fecha Finalización</th></tr></thead>
                        <tbody>
                            <?php if(empty($completed_tasks)): ?>
                                <tr><td colspan="4" class="p-6 text-center text-gray-500">Aún no hay tareas completadas.</td></tr>
                            <?php else: foreach($completed_tasks as $task): ?>
                            <tr class="border-b">
                                <td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($task['title']); ?></td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($task['completed_by']); ?></td>
                                <td class="px-6 py-4 font-mono"><?php echo htmlspecialchars($task['response_time']); ?></td>
                                <td class="px-6 py-4"><?php echo date('d M Y, h:i a', strtotime($task['completed_at'])); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
    const allUsers = <?php echo json_encode($all_users); ?>;
    const adminUsersData = <?php echo json_encode($admin_users_list); ?>;
    
    // --- LÓGICA RESTAURADA Y COMPLETA ---
    const remindersPanel = document.getElementById('reminders-panel');
    function toggleReminders() { remindersPanel.classList.toggle('hidden'); }
    async function markReminderAsRead(reminderId, button) {
        try {
            const response = await fetch(`api/alerts_api.php?reminder_id=${reminderId}`, { method: 'DELETE' });
            const result = await response.json();
            if (result.success) { 
                button.closest('.p-2').remove();
                if (document.getElementById('reminders-list').children.length === 1) {
                    document.querySelector('.relative button span').style.display = 'none';
                    document.getElementById('reminders-list').innerHTML = '<p class="text-sm text-gray-500">No tienes recordatorios.</p>';
                }
            } else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    }

    function toggleForm(formId, button) {
        const form = document.getElementById(formId);
        const parentItem = button.closest('.bg-white');
        parentItem.querySelectorAll('.task-form').forEach(f => {
            if (f.id !== formId) f.classList.remove('active');
        });
        form.classList.toggle('active');
    }
    
    async function completeTask(taskId) {
        if (!confirm('¿Estás seguro de que quieres marcar esta tarea como completada?')) return;
        try {
            const response = await fetch('api/task_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: taskId })
            });
            const result = await response.json();
            if (result.success) {
                alert('Tarea completada con éxito.');
                location.reload();
            } else {
                alert('Error al completar la tarea: ' + result.error);
            }
        } catch (error) {
            alert('Error de conexión al completar la tarea.');
        }
    }

    async function assignManualTask(taskId) {
        const userId = document.getElementById(`assign-user-task-${taskId}`).value;
        const instruction = document.getElementById(`task-instruction-task-${taskId}`).value;
        await sendTaskRequest(null, userId, instruction, 'Asignacion', taskId);
    }
    
    async function assignTask(alertId, taskId = null) {
        const userId = document.getElementById(`assign-user-alert-${alertId}`).value;
        const instruction = document.getElementById(`task-instruction-alert-${alertId}`).value;
        await sendTaskRequest(alertId, userId, instruction, 'Asignacion', taskId);
    }

    async function setReminder(alertId, taskId) {
        const selectorId = alertId ? `reminder-user-alert-${alertId}` : `reminder-user-task-${taskId}`;
        const userId = document.getElementById(selectorId).value;
        await sendTaskRequest(alertId, userId, 'Recordatorio', 'Recordatorio', taskId);
    }

    async function sendTaskRequest(alertId, userId, instruction, type, taskId = null) {
        if (!userId) { alert('Por favor, selecciona un usuario.'); return; }
        try {
            const payload = { assign_to: userId, instruction: instruction, type: type, task_id: taskId };
            if(alertId) payload.alert_id = alertId;

            const response = await fetch('api/alerts_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                alert('Acción completada con éxito.');
                location.reload(); 
            } else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    }
    
    function toggleBreakdown(id) { document.getElementById(`breakdown-row-${id}`).classList.toggle('hidden'); setTimeout(() => { document.getElementById(`breakdown-content-${id}`).classList.toggle('active'); }, 10); }
    
    document.getElementById('manual-task-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const title = document.getElementById('manual-task-title').value;
        const instruction = document.getElementById('manual-task-desc').value;
        const userId = document.getElementById('manual-task-user').value;
        const priority = document.getElementById('manual-task-priority').value;
        
        if (!userId) { alert('Selecciona un usuario.'); return; }

        try {
            const response = await fetch('api/alerts_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: title, assign_to: userId, instruction: instruction, type: 'Manual', priority: priority })
            });
            const result = await response.json();
            if (result.success) { alert('Tarea creada.'); location.reload(); } 
            else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    });

    const modalOverlay = document.getElementById('user-modal-overlay');
    const modal = document.getElementById('user-modal');
    const modalTitle = document.getElementById('modal-title');
    const userForm = document.getElementById('user-form');
    const userIdInput = document.getElementById('user-id');
    const passwordHint = document.getElementById('password-hint');
    const userPasswordInput = document.getElementById('user-password');

    function openModal(user = null) {
        userForm.reset();
        if (user) {
            modalTitle.textContent = 'Editar Usuario';
            userIdInput.value = user.id;
            document.getElementById('user-name').value = user.name;
            document.getElementById('user-email').value = user.email;
            document.getElementById('user-role').value = user.role;
            userPasswordInput.required = false;
            passwordHint.textContent = 'Dejar en blanco para no cambiar.';
        } else {
            modalTitle.textContent = 'Agregar Nuevo Usuario';
            userIdInput.value = '';
            userPasswordInput.required = true;
            passwordHint.textContent = 'La contraseña es requerida.';
        }
        modalOverlay.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('scale-95', 'opacity-0'); modal.classList.add('scale-100', 'opacity-100'); }, 10);
    }

    function closeModal() {
        modal.classList.remove('scale-100', 'opacity-100');
        modal.classList.add('scale-95', 'opacity-0');
        setTimeout(() => modalOverlay.classList.add('hidden'), 300);
    }

    userForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        const formData = new FormData(userForm);
        try {
            const response = await fetch('api/users_api.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) { closeModal(); location.reload(); }
            else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    });

    async function deleteUser(id) {
        if (!confirm('¿Eliminar usuario?')) return;
        try {
            const response = await fetch(`api/users_api.php?id=${id}`, { method: 'DELETE' });
            const result = await response.json();
            if (result.success) { location.reload(); }
            else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    }

    function populateUserTable(users) {
        const tbody = document.getElementById('user-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!users || users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="p-6 text-center">No hay usuarios.</td></tr>';
            return;
        }
        users.forEach(user => {
            const userJson = JSON.stringify({id: user.id, name: user.name, email: user.email, role: user.role}).replace(/'/g, "&apos;");
            tbody.innerHTML += `<tr id="user-row-${user.id}"><td class="px-6 py-4">${user.name}</td><td class="px-6 py-4">${user.email}</td><td class="px-6 py-4"><span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">${user.role}</span></td><td class="px-6 py-4 text-center"><button onclick='openModal(${userJson})' class="font-medium text-blue-600">Editar</button><button onclick="deleteUser(${user.id})" class="font-medium text-red-600 ml-4">Eliminar</button></td></tr>`;
        });
    }
    
    function switchTab(tabName) {
        const contentPanels = ['operaciones', 'roles', 'trazabilidad'];
        contentPanels.forEach(panel => {
            const content = document.getElementById(`content-${panel}`);
            const tab = document.getElementById(`tab-${panel}`);
            if (content) {
                content.classList.toggle('hidden', panel !== tabName);
            }
            if (tab) {
                tab.classList.toggle('active', panel === tabName);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('user-table-body')) {
            populateUserTable(adminUsersData);
        }
    });
    </script>
</body>
</html>

