<?php
/**
 * Módulo de Gestión de Roles y Permisos por Vista - LIHO
 * Permite registrar, editar y administrar roles institucionales,
 * configurar la matriz de pantallas/vistas autorizadas por rol
 * y auditar cada cambio en los logs del sistema.
 */
date_default_timezone_set('America/Bogota');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validación de Autenticación y Rol Administrador
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (!empty($_GET['action']) || !empty($_POST['action'])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'error' => 'Sesión expirada. Inicie sesión nuevamente.'));
        exit;
    }
    header("Location: index.php");
    exit;
}

$userRole = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId   = intval($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['user_name'] ?? 'Usuario';

if ($userRole !== 'ADMINISTRADOR') {
    if (!empty($_GET['action']) || !empty($_POST['action'])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'error' => 'No tiene permisos de administrador para este módulo.'));
        exit;
    }
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/logger_helper.php';

// Asegurar tablas de BD
asegurarTablasRolesYPermisos();

// Router de Acciones AJAX (API)
$jsonPayload = null;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $jsonPayload = json_decode($rawBody, true);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? ($jsonPayload['action'] ?? ''));

// API: Listar Roles
if ($action === 'listar_roles') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $roles = obtenerRolesSistema();
    $modulosMaster = obtenerModulosSistema();

    // Adjuntar matriz de permisos a cada rol
    foreach ($roles as &$r) {
        $r['permisos_map'] = obtenerPermisosRol($r['id']);
    }

    echo json_encode(array(
        'success' => true,
        'data' => $roles,
        'modulos' => $modulosMaster,
        'total_modulos' => count($modulosMaster)
    ));
    exit;
}

// API: Obtener Detalle de un Rol
if ($action === 'obtener_rol') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $rolId = (isset($_GET['id']) && $_GET['id'] !== '') ? intval($_GET['id']) : -1;
    if ($rolId < 0) {
        echo json_encode(array('success' => false, 'error' => 'ID de rol no válido.'));
        exit;
    }

    $stmt = sqlsrv_query($con, "SELECT id, nombre, estado, descripcion, color_tema FROM roles WHERE id = ?", array($rolId));
    if ($stmt !== false && $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $r['permisos_map'] = obtenerPermisosRol($rolId);
        echo json_encode(array('success' => true, 'data' => $r));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Rol no encontrado.'));
    }
    exit;
}

// API: Guardar / Modificar Rol
if ($action === 'guardar_rol') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $data = $jsonPayload ?: $_POST;
    $idRaw = $data['id'] ?? '';
    $rolId = ($idRaw !== '' && $idRaw !== '-1' && $idRaw !== null) ? intval($idRaw) : -1;
    $nombre = trim($data['nombre'] ?? '');
    $descripcion = trim($data['descripcion'] ?? '');
    $colorTema = trim($data['color_tema'] ?? 'sky');
    $estado = (trim($data['estado'] ?? '1') === '1') ? '1' : '0';
    $modulosSeleccionados = $data['modulos'] ?? array();

    if (empty($nombre)) {
        echo json_encode(array('success' => false, 'error' => 'El nombre del rol es obligatorio.'));
        exit;
    }

    $esEdicion = ($rolId >= 0);
    $nombrePrevio = '';
    $estadoPrevio = '';
    $descPrevia   = '';

    if ($esEdicion) {
        // Verificar existencia
        $stmtEx = sqlsrv_query($con, "SELECT nombre, estado, descripcion FROM roles WHERE id = ?", array($rolId));
        if ($stmtEx !== false && $rowEx = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
            $nombrePrevio = $rowEx['nombre'];
            $estadoPrevio = $rowEx['estado'];
            $descPrevia   = $rowEx['descripcion'];
        } else {
            echo json_encode(array('success' => false, 'error' => 'El rol especificado para edición no existe.'));
            exit;
        }

        $sqlUpdate = "UPDATE roles SET nombre = ?, descripcion = ?, color_tema = ?, estado = ?, fecha_modificacion = GETDATE() WHERE id = ?";
        $stmtUp = sqlsrv_query($con, $sqlUpdate, array($nombre, $descripcion, $colorTema, $estado, $rolId));
        if ($stmtUp === false) {
            $errors = print_r(sqlsrv_errors(), true);
            echo json_encode(array('success' => false, 'error' => 'Error al actualizar el rol: ' . $errors));
            exit;
        }
    } else {
        // Verificar duplicados de nombre
        $stmtDup = sqlsrv_query($con, "SELECT TOP 1 id FROM roles WHERE LOWER(nombre) = LOWER(?)", array($nombre));
        if ($stmtDup !== false && sqlsrv_has_rows($stmtDup)) {
            echo json_encode(array('success' => false, 'error' => 'Ya existe un rol con ese nombre en el sistema.'));
            exit;
        }

        // Obtener el siguiente ID para el rol (la columna 'id' no tiene IDENTITY)
        $stmtMax = sqlsrv_query($con, "SELECT ISNULL(MAX(id), 0) + 1 AS next_id FROM roles");
        $rowMax = ($stmtMax && ($rM = sqlsrv_fetch_array($stmtMax, SQLSRV_FETCH_ASSOC))) ? $rM : array('next_id' => 1);
        $rolId = intval($rowMax['next_id'] ?? 1);

        $sqlInsert = "INSERT INTO roles (id, nombre, descripcion, color_tema, estado, fecha_creacion, fecha_modificacion) 
                      VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())";
        $stmtIns = sqlsrv_query($con, $sqlInsert, array($rolId, $nombre, $descripcion, $colorTema, $estado));
        if ($stmtIns === false) {
            $errors = print_r(sqlsrv_errors(), true);
            echo json_encode(array('success' => false, 'error' => 'Error al crear el rol: ' . $errors));
            exit;
        }
    }

    // Guardar la matriz de permisos para el rol (asegura dashboard para todos)
    guardarPermisosRol($rolId, $modulosSeleccionados, $userId, $userName);

    // Registro de Logs Auditoría
    $tipoEvento = $esEdicion ? 'MODIFICACION_ROL' : 'CREACION_ROL';
    $describLog = $esEdicion
        ? "Se modificó el rol ID #{$rolId} ('{$nombrePrevio}' -> '{$nombre}'). Estado: {$estado}. Vistas habilitadas: " . count($modulosSeleccionados)
        : "Se creó el nuevo rol ID #{$rolId} ('{$nombre}'). Estado: {$estado}. Vistas autorizadas iniciales: " . count($modulosSeleccionados);

    if (function_exists('registrar_log_sistema')) {
        registrar_log_sistema(
            'ROLES_PERMISOS',
            $tipoEvento,
            $esEdicion ? 'MODIFICACION' : 'CREACION',
            'INFO',
            "Rol #{$rolId} ({$nombre})",
            $esEdicion ? "Previo: {$nombrePrevio}" : "Nuevo Rol",
            "Nuevo: {$nombre}",
            $describLog
        );
    }

    if (function_exists('registrarLogAuditoriaUniversal')) {
        registrarLogAuditoriaUniversal(
            'ROLES_PERMISOS',
            $rolId,
            "ROL-{$rolId}",
            $tipoEvento,
            $esEdicion ? "Rol Modificado: {$nombre}" : "Rol Creado: {$nombre}",
            "Vistas: " . count($modulosSeleccionados),
            $userId,
            $userName,
            $userRole,
            $describLog,
            json_encode(array('rol_id' => $rolId, 'nombre' => $nombre, 'modulos' => $modulosSeleccionados))
        );
    }

    echo json_encode(array(
        'success' => true,
        'message' => $esEdicion ? "Rol '{$nombre}' actualizado exitosamente." : "Nuevo rol '{$nombre}' registrado exitosamente.",
        'rol_id' => $rolId
    ));
    exit;
}

// API: Toggle Estado Rol
if ($action === 'toggle_estado_rol') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $rolId = intval($_POST['id'] ?? 0);
    if ($rolId <= 0) {
        echo json_encode(array('success' => false, 'error' => 'ID de rol no válido.'));
        exit;
    }

    $stmtFetch = sqlsrv_query($con, "SELECT nombre, estado FROM roles WHERE id = ?", array($rolId));
    if ($stmtFetch && ($r = sqlsrv_fetch_array($stmtFetch, SQLSRV_FETCH_ASSOC))) {
        $nuevoEstado = ($r['estado'] === '1' || $r['estado'] === 1) ? '0' : '1';
        $sqlUp = "UPDATE roles SET estado = ?, fecha_modificacion = GETDATE() WHERE id = ?";
        sqlsrv_query($con, $sqlUp, array($nuevoEstado, $rolId));

        $txtEst = ($nuevoEstado === '1') ? 'ACTIVADO' : 'DESACTIVADO';

        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema(
                'ROLES_PERMISOS',
                'CAMBIO_ESTADO_ROL',
                'MODIFICACION',
                'WARNING',
                "Rol #{$rolId} ({$r['nombre']})",
                "Estado: " . $r['estado'],
                "Estado: {$nuevoEstado}",
                "El rol ID #{$rolId} ('{$r['nombre']}') ha sido {$txtEst} por el administrador."
            );
        }

        if (function_exists('registrarLogAuditoriaUniversal')) {
            registrarLogAuditoriaUniversal(
                'ROLES_PERMISOS',
                $rolId,
                "ROL-{$rolId}",
                'CAMBIO_ESTADO_ROL',
                "Estado anterior: {$r['estado']}",
                "Nuevo estado: {$nuevoEstado}",
                $userId,
                $userName,
                $userRole,
                "Cambio de estado a {$txtEst} para el rol #{$rolId}",
                json_encode(array('rol_id' => $rolId, 'nuevo_estado' => $nuevoEstado))
            );
        }

        echo json_encode(array('success' => true, 'nuevo_estado' => $nuevoEstado, 'message' => "Rol '{$r['nombre']}' {$txtEst} correctamente."));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Rol no encontrado.'));
    }
    exit;
}

// --------------------------------------------------------------------------------------------------
// RENDERIZADO HTML DE LA PÁGINA
// --------------------------------------------------------------------------------------------------
$paletaColores = array(
    'sky' => array('border_glow' => 'hover:border-sky-500/60 hover:shadow-sky-500/10', 'accent_bar' => 'from-sky-500 to-blue-600', 'badge' => 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300 border-sky-200 dark:border-sky-800'),
    'teal' => array('border_glow' => 'hover:border-teal-500/60 hover:shadow-teal-500/10', 'accent_bar' => 'from-teal-500 to-emerald-600', 'badge' => 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300 border-teal-200 dark:border-teal-800'),
    'indigo' => array('border_glow' => 'hover:border-indigo-500/60 hover:shadow-indigo-500/10', 'accent_bar' => 'from-indigo-500 to-purple-600', 'badge' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800'),
    'purple' => array('border_glow' => 'hover:border-purple-500/60 hover:shadow-purple-500/10', 'accent_bar' => 'from-purple-500 to-pink-600', 'badge' => 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300 border-purple-200 dark:border-purple-800'),
    'amber' => array('border_glow' => 'hover:border-amber-500/60 hover:shadow-amber-500/10', 'accent_bar' => 'from-amber-500 to-orange-600', 'badge' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border-amber-200 dark:border-amber-800'),
    'rose' => array('border_glow' => 'hover:border-rose-500/60 hover:shadow-rose-500/10', 'accent_bar' => 'from-rose-500 to-red-600', 'badge' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border-rose-200 dark:border-rose-800'),
    'emerald' => array('border_glow' => 'hover:border-emerald-500/60 hover:shadow-emerald-500/10', 'accent_bar' => 'from-emerald-500 to-teal-600', 'badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800')
);
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Roles y Permisos por Vista | LIHO</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#0f172a',
                        secondary: '#1e293b',
                        tertiary: '#009b98'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />

    <style>
        @keyframes cardCascade {
            from { opacity: 0; transform: translateY(14px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .rol-card-animated {
            animation: cardCascade 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            will-change: transform, opacity;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 99px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.5); }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans min-h-full flex flex-col antialiased selection:bg-tertiary selection:text-white">

    <!-- Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Header Principal Módulo -->
    <header class="bg-white dark:bg-slate-900 border-b border-slate-200/80 dark:border-slate-800 shadow-2xs">
        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <!-- Título y Breadcrumb -->
                <div>
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full text-[10px] font-extrabold bg-tertiary/10 text-tertiary border border-tertiary/20 uppercase tracking-widest">
                            <span class="material-symbols-outlined text-xs">admin_panel_settings</span>
                            <span>Seguridad & Control de Acceso</span>
                        </span>
                        <span class="text-slate-300 dark:text-slate-700">•</span>
                        <span class="text-xs text-slate-500 font-medium">RBAC Universal</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-outfit font-extrabold text-slate-900 dark:text-white tracking-tight">
                        Gestión de Roles y Permisos por Vista
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-medium max-w-2xl mt-1 leading-relaxed">
                        Administre los roles institucionales, configure la matriz de vistas autorizadas por pantalla y audite la trazabilidad completa en los logs del sistema.
                    </p>
                </div>

                <!-- Botón de Acción Principal -->
                <div class="flex items-center gap-3 shrink-0">
                    <a href="usuarios.php" class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition-all flex items-center gap-2 border border-slate-200 dark:border-slate-700">
                        <span class="material-symbols-outlined text-base">manage_accounts</span>
                        <span>Usuarios y Permisos Especiales</span>
                    </a>
                    <button type="button" onclick="abrirModalCrearRol()" class="px-5 py-2.5 rounded-2xl bg-gradient-to-r from-tertiary via-[#00b2af] to-[#009b98] text-white font-black text-xs shadow-lg shadow-tertiary/25 hover:shadow-xl hover:shadow-tertiary/35 hover:-translate-y-0.5 transition-all duration-200 flex items-center gap-2 cursor-pointer">
                        <span class="material-symbols-outlined text-lg">add_circle</span>
                        <span>+ Crear Nuevo Rol</span>
                    </button>
                </div>
            </div>

            <!-- KPIs Rápidos de Roles -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 pt-5 border-t border-slate-100 dark:border-slate-800/80">
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-tertiary/15 text-tertiary flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-xl">shield_person</span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Roles</span>
                        <span class="block text-base font-black text-slate-900 dark:text-white font-mono" id="kpiTotalRoles">0</span>
                    </div>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-xl">verified_user</span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Roles Activos</span>
                        <span class="block text-base font-black text-slate-900 dark:text-white font-mono" id="kpiRolesActivos">0</span>
                    </div>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-xl">group</span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Usuarios Asignados</span>
                        <span class="block text-base font-black text-slate-900 dark:text-white font-mono" id="kpiTotalUsuariosAsignados">0</span>
                    </div>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-purple-500/15 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-xl">grid_view</span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Módulos de Sistema</span>
                        <span class="block text-base font-black text-slate-900 dark:text-white font-mono" id="kpiTotalModulosSistema">0</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Contenido Principal -->
    <main class="flex-1 max-w-[1400px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Toolbar de Filtros y Conmutador de Vistas -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <!-- Buscador en Vivo -->
            <div class="relative w-full sm:w-96">
                <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg pointer-events-none">search</span>
                <input type="text" id="searchInput" placeholder="Buscar rol por nombre o descripción..." class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 pl-10 pr-4 py-2.5 rounded-2xl text-xs font-semibold text-slate-800 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-tertiary transition-all" />
            </div>

            <!-- Filtros de Estado y Switcher de Vista -->
            <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-3">
                <!-- Filtros Estado -->
                <div class="flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-2xl text-xs font-bold border border-slate-200/60 dark:border-slate-700/60">
                    <button type="button" onclick="filtrarEstado('TODOS')" id="btnFilterTODOS" class="px-3 py-1.5 rounded-xl transition-all cursor-pointer bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-2xs">Todos</button>
                    <button type="button" onclick="filtrarEstado('ACTIVOS')" id="btnFilterACTIVOS" class="px-3 py-1.5 rounded-xl transition-all cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white">Activos</button>
                    <button type="button" onclick="filtrarEstado('INACTIVOS')" id="btnFilterINACTIVOS" class="px-3 py-1.5 rounded-xl transition-all cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white">Inactivos</button>
                </div>

                <!-- Conmutador [ Tarjetas | Tabla ] -->
                <div class="flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-2xl text-xs font-bold border border-slate-200/60 dark:border-slate-700/60 shrink-0">
                    <button type="button" id="btnVistaCards" onclick="cambiarVista('cards')" class="px-3 py-1.5 rounded-xl transition-all flex items-center gap-1.5 cursor-pointer bg-white dark:bg-slate-700 text-tertiary shadow-2xs font-extrabold">
                        <span class="material-symbols-outlined text-base">grid_view</span>
                        <span class="hidden md:inline">Tarjetas</span>
                    </button>
                    <button type="button" id="btnVistaTabla" onclick="cambiarVista('tabla')" class="px-3 py-1.5 rounded-xl transition-all flex items-center gap-1.5 cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white">
                        <span class="material-symbols-outlined text-base">table_rows</span>
                        <span class="hidden md:inline">Tabla</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- VISTA 1: TARJETAS DINÁMICAS (PREDETERMINADA) -->
        <div id="contenedorCards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
            <div class="col-span-full py-16 text-center text-slate-400 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs">
                <span class="material-symbols-outlined text-4xl animate-spin text-tertiary mb-2">sync</span>
                <p class="font-bold text-xs">Cargando catálogo de roles del sistema...</p>
            </div>
        </div>

        <!-- VISTA 2: TABLA DETALLADA -->
        <div id="contenedorTabla" class="hidden bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50/80 dark:bg-slate-800/80 border-b border-slate-200/80 dark:border-slate-800 font-outfit uppercase text-[10px] font-black tracking-wider text-slate-400">
                        <tr>
                            <th class="py-3.5 px-4">ID & Rol</th>
                            <th class="py-3.5 px-4">Descripción</th>
                            <th class="py-3.5 px-4 text-center">Usuarios</th>
                            <th class="py-3.5 px-4 text-center">Vistas Autorizadas</th>
                            <th class="py-3.5 px-4 text-center">Estado</th>
                            <th class="py-3.5 px-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="rolesTableBody" class="divide-y divide-slate-100 dark:divide-slate-800/60 font-medium">
                        <!-- Filas inyectadas por JS -->
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Crear / Editar Rol & Matriz de Vistas -->
    <div id="modalRol" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden animate__animated animate__fadeIn animate__faster">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
            <!-- Header Modal -->
            <div class="px-6 py-4 border-b border-slate-200/80 dark:border-slate-800 flex items-center justify-between bg-slate-50/60 dark:bg-slate-800/40">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-tertiary/15 text-tertiary flex items-center justify-center font-black">
                        <span class="material-symbols-outlined text-xl">admin_panel_settings</span>
                    </div>
                    <div>
                        <h3 class="font-outfit font-extrabold text-base text-slate-900 dark:text-white" id="modalRolTitulo">
                            Configurar Rol de Usuario
                        </h3>
                        <p class="text-xs text-slate-400 font-medium">Defina los datos del rol y seleccione la matriz de pantallas autorizadas</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalRol()" class="p-2 rounded-xl text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Body Modal -->
            <form id="formRol" onsubmit="guardarRolSubmit(event)" class="flex-1 overflow-y-auto custom-scrollbar p-6 space-y-6">
                <input type="hidden" id="inputRolId" value="0" />

                <!-- Datos Principales del Rol -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 rounded-2xl bg-slate-50/80 dark:bg-slate-800/40 border border-slate-200/60 dark:border-slate-800">
                    <!-- Nombre Rol -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 uppercase mb-1">Nombre del Rol <span class="text-rose-500">*</span></label>
                        <input type="text" id="inputRolNombre" required placeholder="Ej: Auditor Financiero" class="w-full px-3.5 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none" />
                    </div>

                    <!-- Color Tema -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 uppercase mb-1">Color Distintivo</label>
                        <select id="selectRolColor" class="w-full px-3.5 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none">
                            <option value="sky">Azul Cielo (Sky)</option>
                            <option value="teal">Verde Azulado (Teal)</option>
                            <option value="indigo">Índigo Corporativo</option>
                            <option value="purple">Púrpura Ejecutivo</option>
                            <option value="amber">Ámbar Auditoría</option>
                            <option value="rose">Rosa / Rojo Especial</option>
                            <option value="emerald">Esmeralda Activo</option>
                        </select>
                    </div>

                    <!-- Estado -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 uppercase mb-1">Estado del Rol</label>
                        <select id="selectRolEstado" class="w-full px-3.5 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none">
                            <option value="1">Activo (Habilitado)</option>
                            <option value="0">Inactivo (Deshabilitado)</option>
                        </select>
                    </div>

                    <!-- Descripción -->
                    <div class="md:col-span-3">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 uppercase mb-1">Descripción del Perfil</label>
                        <input type="text" id="inputRolDescripcion" placeholder="Ej: Personal responsable de revisar liquidaciones y notas de ajuste..." class="w-full px-3.5 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none" />
                    </div>
                </div>

                <!-- Matriz de Accesos / Módulos -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary text-lg">grid_view</span>
                            <h4 class="font-outfit font-black text-sm text-slate-900 dark:text-white uppercase tracking-wider">
                                Matriz de Vistas / Pantallas Autorizadas
                            </h4>
                        </div>

                        <!-- Botones de Acción Rápida -->
                        <div class="flex items-center gap-2 text-xs">
                            <button type="button" onclick="marcarTodosModulos(true)" class="px-2.5 py-1 rounded-lg bg-teal-50 hover:bg-teal-100 dark:bg-teal-950/60 dark:hover:bg-teal-900 text-teal-700 dark:text-teal-300 font-bold transition-all border border-teal-200 dark:border-teal-800">
                                Seleccionar Todos
                            </button>
                            <button type="button" onclick="marcarTodosModulos(false)" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 font-bold transition-all border border-slate-200 dark:border-slate-700">
                                Desmarcar Todos
                            </button>
                        </div>
                    </div>

                    <!-- Container Grid de Módulos Inyectado por JS -->
                    <div id="matrizModulosContainer" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- JS renders modules cards -->
                    </div>
                </div>

                <!-- Footer Modal Botones -->
                <div class="pt-4 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-end gap-3">
                    <button type="button" onclick="cerrarModalRol()" class="px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-xs hover:bg-slate-100 dark:hover:bg-slate-800 transition-all cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarRol" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-tertiary via-[#00b2af] to-[#009b98] text-white font-black text-xs shadow-md hover:shadow-lg transition-all flex items-center gap-2 cursor-pointer">
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                        <span>Guardar Rol y Permisos</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <!-- Scripts de la Aplicación -->
    <script>
        const SwalCustom = Swal.mixin({
            customClass: {
                popup: 'bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl text-slate-100 font-sans p-6',
                title: 'text-base font-black font-sans text-white tracking-wide',
                htmlContainer: 'text-xs text-slate-300 font-medium leading-relaxed',
                confirmButton: 'px-5 py-2.5 rounded-xl bg-tertiary hover:bg-teal-600 text-white font-bold text-xs shadow-md transition-all cursor-pointer mx-1 border border-white/10',
                cancelButton: 'px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-all cursor-pointer mx-1 border border-slate-700',
                input: 'bg-slate-800 border border-slate-700 text-white rounded-xl text-xs focus:ring-2 focus:ring-tertiary outline-none p-3'
            },
            buttonsStyling: false,
            showClass: { popup: 'animate__animated animate__fadeInDown animate__faster' },
            hideClass: { popup: 'animate__animated animate__fadeOutUp animate__faster' }
        });

        let rolesDataCache = [];
        let modulosMasterCache = {};
        let activeFilterEstado = 'TODOS';
        let activeVista = localStorage.getItem('liho_roles_vista') || 'cards';

        document.addEventListener('DOMContentLoaded', () => {
            cargarRoles();

            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', () => renderRoles());
            }

            cambiarVista(activeVista);
        });

        function cambiarVista(vista) {
            activeVista = vista;
            localStorage.setItem('liho_roles_vista', vista);
            const contCards = document.getElementById('contenedorCards');
            const contTabla = document.getElementById('contenedorTabla');
            const btnCards = document.getElementById('btnVistaCards');
            const btnTabla = document.getElementById('btnVistaTabla');

            if (vista === 'cards') {
                contCards.classList.remove('hidden');
                contTabla.classList.add('hidden');
                btnCards.className = "px-3 py-1.5 rounded-xl transition-all flex items-center gap-1.5 cursor-pointer bg-white dark:bg-slate-700 text-tertiary shadow-2xs font-extrabold";
                btnTabla.className = "px-3 py-1.5 rounded-xl transition-all flex items-center gap-1.5 cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white";
            } else {
                contCards.classList.add('hidden');
                contTabla.classList.remove('hidden');
                btnTabla.className = "px-3 py-1.5 rounded-xl transition-all flex items-center gap-1.5 cursor-pointer bg-white dark:bg-slate-700 text-tertiary shadow-2xs font-extrabold";
                btnCards.className = "px-3 py-1.5 rounded-xl transition-all flex items-center gap-1.5 cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white";
            }
        }

        function filtrarEstado(est) {
            activeFilterEstado = est;
            ['TODOS', 'ACTIVOS', 'INACTIVOS'].forEach(k => {
                const btn = document.getElementById('btnFilter' + k);
                if (btn) {
                    if (k === est) {
                        btn.className = "px-3 py-1.5 rounded-xl transition-all cursor-pointer bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-2xs";
                    } else {
                        btn.className = "px-3 py-1.5 rounded-xl transition-all cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white";
                    }
                }
            });
            renderRoles();
        }

        async function cargarRoles() {
            try {
                const res = await fetch('gestion_roles.php?action=listar_roles');
                const result = await res.json();
                if (result.success) {
                    rolesDataCache = result.data || [];
                    modulosMasterCache = result.modulos || {};
                    
                    // Actualizar KPIs
                    let totalUsersSum = 0;
                    let activosCount = 0;
                    rolesDataCache.forEach(r => {
                        totalUsersSum += parseInt(r.total_usuarios || 0, 10);
                        if (r.estado == 1 || r.estado === '1') activosCount++;
                    });

                    document.getElementById('kpiTotalRoles').textContent = rolesDataCache.length;
                    document.getElementById('kpiRolesActivos').textContent = activosCount;
                    document.getElementById('kpiTotalUsuariosAsignados').textContent = totalUsersSum;
                    document.getElementById('kpiTotalModulosSistema').textContent = Object.keys(modulosMasterCache).length;

                    renderRoles();
                }
            } catch(e) {
                console.error(e);
            }
        }

        function renderRoles() {
            const contCards = document.getElementById('contenedorCards');
            const tbodyTabla = document.getElementById('rolesTableBody');
            contCards.innerHTML = '';
            tbodyTabla.innerHTML = '';

            const searchVal = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
            const totalModulosMaster = Object.keys(modulosMasterCache).length;

            const filtered = rolesDataCache.filter(r => {
                const esActivo = (r.estado == 1 || r.estado === '1');
                if (activeFilterEstado === 'ACTIVOS' && !esActivo) return false;
                if (activeFilterEstado === 'INACTIVOS' && esActivo) return false;

                if (searchVal) {
                    const matchNom = (r.nombre || '').toLowerCase().includes(searchVal);
                    const matchDesc = (r.descripcion || '').toLowerCase().includes(searchVal);
                    if (!matchNom && !matchDesc) return false;
                }
                return true;
            });

            if (filtered.length === 0) {
                const noResultHtml = `
                    <div class="col-span-full py-16 text-center text-slate-400 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs">
                        <span class="material-symbols-outlined text-4xl block mb-2 opacity-50">search_off</span>
                        <p class="font-bold text-xs">No se encontraron roles registrados para el filtro o criterio de búsqueda.</p>
                    </div>
                `;
                contCards.innerHTML = noResultHtml;
                tbodyTabla.innerHTML = `<tr><td colspan="6" class="py-8 text-center text-slate-400 font-medium">Sin resultados</td></tr>`;
                return;
            }

            const paletaColores = {
                sky: { border: 'hover:border-sky-500/60 hover:shadow-sky-500/10', bar: 'from-sky-500 to-blue-600', badge: 'bg-sky-100 text-sky-800 dark:bg-sky-950/80 dark:text-sky-300 border-sky-200 dark:border-sky-800' },
                teal: { border: 'hover:border-teal-500/60 hover:shadow-teal-500/10', bar: 'from-teal-500 to-emerald-600', badge: 'bg-teal-100 text-teal-800 dark:bg-teal-950/80 dark:text-teal-300 border-teal-200 dark:border-teal-800' },
                indigo: { border: 'hover:border-indigo-500/60 hover:shadow-indigo-500/10', bar: 'from-indigo-500 to-purple-600', badge: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/80 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800' },
                purple: { border: 'hover:border-purple-500/60 hover:shadow-purple-500/10', bar: 'from-purple-500 to-pink-600', badge: 'bg-purple-100 text-purple-800 dark:bg-purple-950/80 dark:text-purple-300 border-purple-200 dark:border-purple-800' },
                amber: { border: 'hover:border-amber-500/60 hover:shadow-amber-500/10', bar: 'from-amber-500 to-orange-600', badge: 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-200 dark:border-amber-800' },
                rose: { border: 'hover:border-rose-500/60 hover:shadow-rose-500/10', bar: 'from-rose-500 to-red-600', badge: 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border-rose-200 dark:border-rose-800' },
                emerald: { border: 'hover:border-emerald-500/60 hover:shadow-emerald-500/10', bar: 'from-emerald-500 to-teal-600', badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' }
            };

            filtered.forEach((r, idx) => {
                const esActivo = (r.estado == 1 || r.estado === '1');
                const themeKey = (r.color_tema && paletaColores[r.color_tema]) ? r.color_tema : (r.id == 1 ? 'teal' : (r.id == 2 ? 'indigo' : 'sky'));
                const theme = paletaColores[themeKey] || paletaColores.sky;
                const delay = min(idx * 0.05, 0.4);

                const totalModulosPermitidos = r.id == 1 ? totalModulosMaster : parseInt(r.total_modulos || 0, 10);

                // RENDER CARDS
                const cardDiv = document.createElement('div');
                cardDiv.className = `rol-card-animated bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs relative group flex flex-col justify-between overflow-hidden ${theme.border} transition-all duration-300`;
                cardDiv.style.animationDelay = `${delay}s`;

                cardDiv.innerHTML = `
                    <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r ${theme.bar} opacity-80 group-hover:opacity-100 transition-opacity"></div>

                    <div>
                        <div class="flex items-center justify-between gap-2 mb-3 pt-1">
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-black uppercase tracking-wider ${theme.badge} border">
                                ROL ID #${r.id}
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider ${esActivo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400 border border-slate-200 dark:border-slate-700'}">
                                <span class="w-1.5 h-1.5 rounded-full ${esActivo ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'}"></span>
                                <span>${esActivo ? 'Activo' : 'Inactivo'}</span>
                            </span>
                        </div>

                        <h3 class="font-outfit font-extrabold text-lg text-slate-900 dark:text-white leading-snug group-hover:text-tertiary transition-colors mb-1">
                            ${htmlspecialchars(r.nombre)}
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium line-clamp-2 min-h-[2.25rem] mb-4">
                            ${htmlspecialchars(r.descripcion || 'Sin descripción asignada para este perfil.')}
                        </p>

                        <!-- Píldoras de Métricas -->
                        <div class="grid grid-cols-2 gap-2 my-3">
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 flex items-center gap-2">
                                <span class="material-symbols-outlined text-base text-indigo-500 shrink-0">groups</span>
                                <div class="min-w-0">
                                    <span class="block text-[9px] font-bold uppercase text-slate-400">Usuarios</span>
                                    <span class="block text-xs font-black text-slate-800 dark:text-slate-200 truncate">${r.total_usuarios || 0} asignados</span>
                                </div>
                            </div>

                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 flex items-center gap-2">
                                <span class="material-symbols-outlined text-base text-teal-500 shrink-0">grid_view</span>
                                <div class="min-w-0">
                                    <span class="block text-[9px] font-bold uppercase text-slate-400">Vistas</span>
                                    <span class="block text-xs font-black text-slate-800 dark:text-slate-200 truncate">${totalModulosPermitidos} / ${totalModulosMaster}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Botones de Acción Card -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                        <button type="button" onclick="toggleEstadoRol(${r.id})" title="${esActivo ? 'Desactivar Rol' : 'Activar Rol'}" class="p-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 transition-colors">
                            <span class="material-symbols-outlined text-base ${esActivo ? 'text-amber-500' : 'text-emerald-500'}">power_settings_new</span>
                        </button>

                        <button type="button" onclick="editarRol(${r.id})" class="px-4 py-2 rounded-xl bg-tertiary/10 hover:bg-tertiary text-tertiary hover:text-white font-extrabold text-xs transition-all flex items-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-base">edit_note</span>
                            <span>Editar Rol & Vistas</span>
                        </button>
                    </div>
                `;
                contCards.appendChild(cardDiv);

                // RENDER TABLA
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors";
                tr.innerHTML = `
                    <td class="py-3.5 px-4 font-bold">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-black ${theme.badge}">#${r.id}</span>
                            <span class="font-outfit text-sm text-slate-900 dark:text-white font-extrabold">${htmlspecialchars(r.nombre)}</span>
                        </div>
                    </td>
                    <td class="py-3.5 px-4 text-slate-500 font-medium">${htmlspecialchars(r.descripcion || '-')}</td>
                    <td class="py-3.5 px-4 text-center font-mono font-bold">${r.total_usuarios || 0}</td>
                    <td class="py-3.5 px-4 text-center font-mono font-bold text-tertiary">${totalModulosPermitidos} / ${totalModulosMaster}</td>
                    <td class="py-3.5 px-4 text-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-black uppercase ${esActivo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-500'}">
                            ${esActivo ? 'Activo' : 'Inactivo'}
                        </span>
                    </td>
                    <td class="py-3.5 px-4 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" onclick="toggleEstadoRol(${r.id})" class="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500">
                                <span class="material-symbols-outlined text-base ${esActivo ? 'text-amber-500' : 'text-emerald-500'}">power_settings_new</span>
                            </button>
                            <button type="button" onclick="editarRol(${r.id})" class="p-1.5 rounded-lg bg-tertiary/10 text-tertiary hover:bg-tertiary hover:text-white transition-colors">
                                <span class="material-symbols-outlined text-base">edit_note</span>
                            </button>
                        </div>
                    </td>
                `;
                tbodyTabla.appendChild(tr);
            });
        }

        function min(a, b) { return a < b ? a : b; }
        function htmlspecialchars(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function renderMatrizModulos(mapPermisos = {}) {
            const container = document.getElementById('matrizModulosContainer');
            container.innerHTML = '';

            for (const [key, mod] of Object.entries(modulosMasterCache)) {
                const isDashboard = (key === 'dashboard');
                const isChecked = isDashboard || (mapPermisos[key] === true || mapPermisos[key] === 1);

                const modCard = document.createElement('label');
                modCard.className = `p-3 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-3 ${
                    isChecked 
                        ? 'bg-teal-50/80 dark:bg-teal-950/40 border-teal-300 dark:border-teal-700/80' 
                        : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300'
                }`;

                modCard.innerHTML = `
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-xl ${isChecked ? 'bg-tertiary text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'} flex items-center justify-center shrink-0 mt-0.5">
                            <span class="material-symbols-outlined text-lg">${mod.icono || 'grid_view'}</span>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="font-outfit font-bold text-xs text-slate-900 dark:text-white block truncate">${htmlspecialchars(mod.nombre)}</span>
                                ${isDashboard ? '<span class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">Predeterminada</span>' : ''}
                            </div>
                            <span class="text-[10px] text-slate-500 dark:text-slate-400 font-medium block leading-tight mt-0.5 line-clamp-2">${htmlspecialchars(mod.descripcion)}</span>
                        </div>
                    </div>

                    <div class="shrink-0 pt-0.5">
                        <input type="checkbox" name="modulos[]" value="${key}" ${isChecked ? 'checked' : ''} 
                               ${isDashboard ? 'data-locked="true"' : ''}
                               onchange="${isDashboard ? 'this.checked=true;alCambiarCheckboxModulo(this);' : 'alCambiarCheckboxModulo(this);'}" 
                               class="w-4 h-4 accent-tertiary rounded cursor-pointer" />
                    </div>
                `;
                container.appendChild(modCard);
            }
        }

        function alCambiarCheckboxModulo(chk) {
            const card = chk.closest('label');
            if (chk.checked) {
                card.className = 'p-3 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-3 bg-teal-50/80 dark:bg-teal-950/40 border-teal-300 dark:border-teal-700/80';
            } else {
                card.className = 'p-3 rounded-2xl border transition-all cursor-pointer flex items-start justify-between gap-3 bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300';
            }
        }

        function marcarTodosModulos(status) {
            document.querySelectorAll('#matrizModulosContainer input[type="checkbox"]').forEach(chk => {
                if (chk.dataset.locked === 'true' || chk.value === 'dashboard') {
                    chk.checked = true;
                } else {
                    chk.checked = status;
                }
                alCambiarCheckboxModulo(chk);
            });
        }

        function abrirModalCrearRol() {
            document.getElementById('formRol').reset();
            document.getElementById('inputRolId').value = "-1";
            document.getElementById('modalRolTitulo').textContent = "Crear Nuevo Rol de Usuario";
            
            // Habilitar todos los módulos por defecto para nuevo rol
            const defaultMap = {};
            for (const k of Object.keys(modulosMasterCache)) {
                defaultMap[k] = true;
            }
            renderMatrizModulos(defaultMap);

            document.getElementById('modalRol').classList.remove('hidden');
        }

        function editarRol(id) {
            let r = rolesDataCache.find(x => x.id == id);
            if (!r) {
                r = rolesDataCache.find(x => String(x.id) === String(id));
            }
            if (r) {
                document.getElementById('inputRolId').value = r.id;
                document.getElementById('inputRolNombre').value = r.nombre || '';
                document.getElementById('inputRolDescripcion').value = r.descripcion || '';
                document.getElementById('selectRolColor').value = r.color_tema || 'sky';
                document.getElementById('selectRolEstado').value = (r.estado == 1 || r.estado === '1') ? '1' : '0';
                document.getElementById('modalRolTitulo').textContent = `Editar Rol #${r.id} - ${r.nombre}`;

                renderMatrizModulos(r.permisos_map || {});
                document.getElementById('modalRol').classList.remove('hidden');
            } else {
                SwalCustom.fire({ icon: 'error', title: 'Rol no encontrado', text: 'No se pudieron cargar los datos del rol seleccionado.' });
            }
        }

        function cerrarModalRol() {
            document.getElementById('modalRol').classList.add('hidden');
        }

        async function guardarRolSubmit(e) {
            e.preventDefault();

            const id = document.getElementById('inputRolId').value;
            const nombre = document.getElementById('inputRolNombre').value.trim();
            const descripcion = document.getElementById('inputRolDescripcion').value.trim();
            const color = document.getElementById('selectRolColor').value;
            const estado = document.getElementById('selectRolEstado').value;

            const modulos = [];
            document.querySelectorAll('#matrizModulosContainer input[type="checkbox"]:checked').forEach(chk => {
                modulos.push(chk.value);
            });

            if (!nombre) {
                SwalCustom.fire({ icon: 'warning', title: 'Nombre Requerido', text: 'Ingrese el nombre del rol.' });
                return;
            }

            const btn = document.getElementById('btnGuardarRol');
            btn.disabled = true;
            btn.innerHTML = `<span class="material-symbols-outlined text-sm animate-spin">sync</span><span>Guardando...</span>`;

            try {
                const payload = {
                    action: 'guardar_rol',
                    id: id,
                    nombre: nombre,
                    descripcion: descripcion,
                    color_tema: color,
                    estado: estado,
                    modulos: modulos
                };

                const res = await fetch('gestion_roles.php?action=guardar_rol', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();

                if (result.success) {
                    cerrarModalRol();
                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Operación Exitosa!',
                        text: result.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    cargarRoles();
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: result.error || 'No se pudo guardar el rol.' });
                }
            } catch(err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error de Conexión', text: 'Ocurrió un fallo de red.' });
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<span class="material-symbols-outlined text-sm">check_circle</span><span>Guardar Rol y Permisos</span>`;
            }
        }

        async function toggleEstadoRol(id) {
            const rol = rolesDataCache.find(r => r.id == id);
            const esActivo = (rol && (rol.estado == 1 || rol.estado === '1'));
            const txtAccion = esActivo ? 'desactivar' : 'activar';

            const confirmModal = await SwalCustom.fire({
                title: `¿Desea ${txtAccion} este rol?`,
                icon: 'question',
                text: `El rol '${rol ? rol.nombre : id}' cambiará su disponibilidad en el sistema.`,
                showCancelButton: true,
                confirmButtonText: `Sí, ${txtAccion}`,
                cancelButtonText: 'Cancelar'
            });

            if (!confirmModal.isConfirmed) return;

            try {
                const formData = new FormData();
                formData.append('action', 'toggle_estado_rol');
                formData.append('id', id);

                const res = await fetch('gestion_roles.php?action=toggle_estado_rol', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.success) {
                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Estado Actualizado!',
                        text: result.message,
                        timer: 1800,
                        showConfirmButton: false
                    });
                    cargarRoles();
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: result.error || 'No se pudo cambiar el estado.' });
                }
            } catch(e) {
                console.error(e);
            }
        }
    </script>
</body>
</html>
