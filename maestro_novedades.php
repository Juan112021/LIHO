<?php
/**
 * Maestro de Novedades de Honorarios y Nómina Médica por Entidad
 * IPS Hernán Ocazionez y Cía S.A.S. - LIHO
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');
require_once(__DIR__ . '/includes/liquidaciones_helper.php');

$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId    = (int)($_SESSION['user_id'] ?? 0);
$userEmail = $_SESSION['user_email'] ?? '';
$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Control estricto de acceso a Maestro de Novedades
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && $userRoleIdVal !== 2 && !tienePermisoModulo($userId, $userRoleIdVal, 'maestro_novedades') && !tienePermisoModulo($userId, $userRoleIdVal, 'maestro_entidades')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Permisos para editar (Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditNovedad = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

$con = obtenerConexionLIHO();
asegurarTablasLiquidaciones();

// --------------------------------------------------------------------------
// PROCESAMIENTO AJAX
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // 1. AJAX: CREAR NOVEDAD
    if ($action === 'crear_novedad') {
        if (!$canEditNovedad) {
            echo json_encode(['success' => false, 'error' => 'No tiene permisos para crear conceptos de novedades.']);
            exit;
        }

        $res = crearNovedadMaestroBD($_POST, $userId, $userName, $userRole);
        echo json_encode($res);
        exit;
    }

    // 2. AJAX: EDITAR NOVEDAD
    if ($action === 'editar_novedad') {
        if (!$canEditNovedad) {
            echo json_encode(['success' => false, 'error' => 'No tiene permisos para modificar conceptos de novedades.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Identificador de novedad no válido.']);
            exit;
        }

        $res = editarNovedadMaestroBD($id, $_POST, $userId, $userName, $userRole);
        echo json_encode($res);
        exit;
    }

    // 3. AJAX: CAMBIAR ESTADO
    if ($action === 'cambiar_estado') {
        if (!$canEditNovedad) {
            echo json_encode(['success' => false, 'error' => 'No tiene permisos para modificar el estado.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $nuevoEstado = (int)($_POST['estado'] ?? 1);
        $res = cambiarEstadoNovedadMaestroBD($id, $nuevoEstado, $userId, $userName, $userRole);
        echo json_encode($res);
        exit;
    }

    // 4. AJAX: ELIMINAR NOVEDAD
    if ($action === 'eliminar_novedad') {
        if (!$canEditNovedad) {
            echo json_encode(['success' => false, 'error' => 'No tiene permisos para eliminar conceptos.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido.']);
            exit;
        }

        $stmtSel = sqlsrv_query($con, "SELECT id, codigo, nombre, entidad_id FROM maestro_novedades WHERE id = ?", array($id));
        if ($stmtSel && ($rowAnt = sqlsrv_fetch_array($stmtSel, SQLSRV_FETCH_ASSOC))) {
            $stmtDel = sqlsrv_query($con, "DELETE FROM maestro_novedades WHERE id = ?", array($id));
            if ($stmtDel !== false) {
                registrarLogAuditoriaUniversal(
                    'MAESTRO_NOVEDADES',
                    $id,
                    $rowAnt['codigo'],
                    'ELIMINACION_NOVEDAD_MAESTRO',
                    $rowAnt['nombre'],
                    'ELIMINADO',
                    $userId,
                    $userName,
                    $userRole,
                    "Eliminación de la novedad '{$rowAnt['nombre']}' ({$rowAnt['codigo']}) de la entidad {$rowAnt['entidad_id']}.",
                    null,
                    'EXITOSO'
                );
                echo json_encode(['success' => true, 'message' => 'Novedad eliminada permanentemente.']);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'No fue posible eliminar la novedad.']);
        exit;
    }
}

// 5. AJAX GET: CONSULTAS
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'obtener_novedad') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = sqlsrv_query($con, "SELECT * FROM maestro_novedades WHERE id = ?", array($id));
        if ($stmt && ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
            $r['valor_predeterminado'] = floatval($r['valor_predeterminado'] ?? 0);
            echo json_encode(['success' => true, 'data' => $r]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Novedad no encontrada.']);
        }
        exit;
    }

    if ($action === 'obtener_novedades_entidad') {
        $entidadId = $_GET['entidad_id'] ?? null;
        $soloActivas = isset($_GET['todas']) ? false : true;
        $novedades = obtenerNovedadesEntidadBD($entidadId, $soloActivas);
        echo json_encode(['success' => true, 'data' => $novedades]);
        exit;
    }

    if ($action === 'registrar_log_toggle') {
        $modulo = $_GET['modulo'] ?? 'LIQUIDACION';
        $refId  = $_GET['referencia_id'] ?? '0';
        $entId  = $_GET['entidad_id'] ?? 'PROPIO';
        $medico = $_GET['medico'] ?? 'GLOBAL';
        $activo = isset($_GET['activo']) ? ($_GET['activo'] === '1' || $_GET['activo'] === 'true') : true;

        registrarLogToggleNovedades($modulo, $refId, $entId, $medico, $userId, $userName, $userRole, $activo);
        echo json_encode(['success' => true]);
        exit;
    }
}

// --------------------------------------------------------------------------
// CARGA DE DATOS PARA LA VISTA PRINCIPAL
// --------------------------------------------------------------------------
// Listado de entidades para el filtro
$entidadesMap = [];
$stmtEnt = sqlsrv_query($con, "SELECT id, nombre, nit, color_tema FROM maestro_entidades WHERE estado = 1 ORDER BY nombre ASC");
if ($stmtEnt) {
    while ($e = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
        $entidadesMap[(string)$e['id']] = $e;
    }
}

// Consulta de novedades
$sqlNov = "SELECT n.*, 
                  ISNULL(e.nombre, CASE WHEN n.entidad_id = 'PROPIO' THEN 'HERNÁN OCAZIONEZ (MATRIZ)' WHEN n.entidad_id = 'TODAS' THEN 'TODAS LAS ENTIDADES' ELSE n.entidad_id END) AS entidad_nombre_display,
                  ISNULL(e.color_tema, 'indigo') AS entidad_color
           FROM maestro_novedades n
           LEFT JOIN maestro_entidades e ON CAST(e.id AS VARCHAR) = n.entidad_id
           ORDER BY n.entidad_id ASC, n.tipo ASC, n.nombre ASC";

$stmtNov = sqlsrv_query($con, $sqlNov);
$novedadesList = [];
$totalAdiciones = 0;
$totalDeducciones = 0;
$totalActivas = 0;

if ($stmtNov) {
    while ($row = sqlsrv_fetch_array($stmtNov, SQLSRV_FETCH_ASSOC)) {
        if ($row['fecha_creacion'] instanceof DateTime) {
            $row['fecha_creacion_fmt'] = $row['fecha_creacion']->format('d/m/Y');
        } else {
            $row['fecha_creacion_fmt'] = (string)$row['fecha_creacion'];
        }
        $row['valor_predeterminado'] = floatval($row['valor_predeterminado'] ?? 0);
        
        if ($row['tipo'] === 'ADICION') {
            $totalAdiciones++;
        } else {
            $totalDeducciones++;
        }
        if ($row['estado'] == 1) {
            $totalActivas++;
        }
        $novedadesList[] = $row;
    }
}
$totalNovedades = count($novedadesList);
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maestro de Novedades por Entidad - LIHO</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/img/hologo.png">
    <link rel="icon" type="image/png" href="assets/img/hologo.png">

    <!-- Google Fonts & Material Symbols -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#14354E',
                        secondary: '#1E4A6D',
                        tertiary: '#00C1BE',
                        accent: '#00A8A5'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace']
                    }
                }
            }
        };
    </script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0,0,0,0.03); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.4); border-radius: 9999px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.7); }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans antialiased min-h-screen flex flex-col transition-colors duration-200">
    
    <!-- Navbar Oficial de LIHO -->
    <?php require_once(__DIR__ . '/includes/navbar.php'); ?>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        
        <!-- Header & Breadcrumbs -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">
                    <a href="dashboard.php" class="hover:text-primary dark:hover:text-white transition-colors">Inicio</a>
                    <span>/</span>
                    <span>Maestros de Configuración</span>
                    <span>/</span>
                    <span class="text-teal-600 dark:text-teal-400">Novedades por Entidad</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black font-outfit text-slate-900 dark:text-white flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-amber-500 text-3xl">campaign</span>
                    <span>Maestro de Novedades de Honorarios</span>
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Catálogo institucional de conceptos, bonificaciones y deducciones configurables por entidad con trazabilidad y logs.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <a href="maestro_entidades.php" class="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700/80 transition-all flex items-center gap-1.5 shadow-xs">
                    <span class="material-symbols-outlined text-base">domain</span>
                    <span>Entidades / IPS</span>
                </a>
                <?php if ($canEditNovedad): ?>
                <button type="button" onclick="abrirModalCrearNovedad()" class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 shadow-md shadow-amber-600/20 active:scale-95 transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-base">add_circle</span>
                    <span>Nueva Novedad</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Stat Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- Card 1: Total Conceptos -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800/60 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">format_list_bulleted</span>
                </div>
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Novedades</span>
                    <p class="text-2xl font-black font-outfit text-slate-900 dark:text-white" id="statTotal"><?php echo $totalNovedades; ?></p>
                </div>
            </div>

            <!-- Card 2: Adiciones (+) -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">add_circle</span>
                </div>
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Adiciones / Devengos (+)</span>
                    <p class="text-2xl font-black font-outfit text-emerald-600 dark:text-emerald-400" id="statAdiciones"><?php echo $totalAdiciones; ?></p>
                </div>
            </div>

            <!-- Card 3: Deducciones (-) -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-800/60 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">remove_circle</span>
                </div>
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Deducciones / Descuentos (-)</span>
                    <p class="text-2xl font-black font-outfit text-rose-600 dark:text-rose-400" id="statDeducciones"><?php echo $totalDeducciones; ?></p>
                </div>
            </div>

            <!-- Card 4: Activas -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-teal-50 dark:bg-teal-950/50 border border-teal-200 dark:border-teal-800/60 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">verified</span>
                </div>
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Novedades Activas</span>
                    <p class="text-2xl font-black font-outfit text-teal-600 dark:text-teal-400" id="statActivas"><?php echo $totalActivas; ?></p>
                </div>
            </div>

        </div>

        <!-- Filter & Search Toolbar -->
        <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col md:flex-row items-center justify-between gap-3">
            
            <!-- Left: Search Box -->
            <div class="relative w-full md:w-80">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                <input type="text" id="filterSearch" oninput="aplicarFiltros()" placeholder="Buscar por código, concepto o detalle..." 
                    class="w-full pl-9 pr-3 py-2 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-medium text-slate-900 dark:text-white placeholder-slate-400 outline-none focus:ring-2 focus:ring-amber-500/40" />
            </div>

            <!-- Right: Entity and Type Selects -->
            <div class="flex flex-wrap items-center gap-2.5 w-full md:w-auto">
                
                <!-- Entity Filter -->
                <div class="flex items-center gap-1.5 w-full sm:w-auto">
                    <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Entidad:</label>
                    <select id="filterEntidad" onchange="aplicarFiltros()" 
                        class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-2 focus:ring-amber-500/40">
                        <option value="">-- Todas las Entidades --</option>
                        <option value="PROPIO">HERNÁN OCAZIONEZ (MATRIZ)</option>
                        <?php foreach ($entidadesMap as $eId => $ent): ?>
                            <option value="<?php echo htmlspecialchars($eId); ?>"><?php echo htmlspecialchars($ent['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Type Filter -->
                <div class="flex items-center gap-1.5 w-full sm:w-auto">
                    <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Tipo:</label>
                    <select id="filterTipo" onchange="aplicarFiltros()" 
                        class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-2 focus:ring-amber-500/40">
                        <option value="">Todos los Tipos</option>
                        <option value="ADICION">Adición (+)</option>
                        <option value="DEDUCCION">Deducción (-)</option>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="flex items-center gap-1.5 w-full sm:w-auto">
                    <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Estado:</label>
                    <select id="filterEstado" onchange="aplicarFiltros()" 
                        class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-2 focus:ring-amber-500/40">
                        <option value="">Todos</option>
                        <option value="1">Activas</option>
                        <option value="0">Inactivas</option>
                    </select>
                </div>

            </div>

        </div>

        <!-- Table Card -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 dark:bg-slate-800/80 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800">
                            <th class="py-3 px-4 w-32">Código</th>
                            <th class="py-3 px-4">Concepto / Novedad</th>
                            <th class="py-3 px-4 w-56">Entidad Vinculada</th>
                            <th class="py-3 px-4 w-36 text-center">Tipo</th>
                            <th class="py-3 px-4 w-36 text-right">Valor Default</th>
                            <th class="py-3 px-4 w-28 text-center">Estado</th>
                            <th class="py-3 px-4 w-28 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tablaNovedadesBody" class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-200">
                        <?php if (empty($novedadesList)): ?>
                            <tr id="rowSinDatos">
                                <td colspan="7" class="py-8 text-center text-slate-400 font-medium">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <span class="material-symbols-outlined text-4xl text-slate-300 dark:text-slate-600">campaign</span>
                                        <span>No se encontraron conceptos de novedades registrados.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($novedadesList as $nov): 
                                $esAdicion = ($nov['tipo'] === 'ADICION');
                                $colorEntidad = $nov['entidad_color'] ?? 'indigo';
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors fila-novedad"
                                data-id="<?php echo $nov['id']; ?>"
                                data-codigo="<?php echo htmlspecialchars($nov['codigo']); ?>"
                                data-nombre="<?php echo htmlspecialchars($nov['nombre']); ?>"
                                data-entidad-id="<?php echo htmlspecialchars($nov['entidad_id']); ?>"
                                data-tipo="<?php echo htmlspecialchars($nov['tipo']); ?>"
                                data-estado="<?php echo $nov['estado']; ?>"
                                data-descripcion="<?php echo htmlspecialchars($nov['descripcion'] ?? ''); ?>">
                                
                                <td class="py-3 px-4 font-mono font-bold text-amber-700 dark:text-amber-400">
                                    <?php echo htmlspecialchars($nov['codigo']); ?>
                                </td>

                                <td class="py-3 px-4">
                                    <div class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($nov['nombre']); ?></div>
                                    <?php if (!empty($nov['descripcion'])): ?>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1"><?php echo htmlspecialchars($nov['descripcion']); ?></p>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 truncate max-w-[200px]">
                                        <span class="material-symbols-outlined text-xs">domain</span>
                                        <span class="truncate"><?php echo htmlspecialchars($nov['entidad_nombre_display']); ?></span>
                                    </span>
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <?php if ($esAdicion): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                            <span class="material-symbols-outlined text-xs">add_circle</span>
                                            ADICIÓN (+)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                            <span class="material-symbols-outlined text-xs">remove_circle</span>
                                            DEDUCCIÓN (-)
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3 px-4 text-right font-mono font-bold text-slate-800 dark:text-slate-200">
                                    $ <?php echo number_format($nov['valor_predeterminado'], 0, ',', '.'); ?>
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <?php if ($canEditNovedad): ?>
                                        <label class="relative inline-flex items-center cursor-pointer" title="Haga clic para activar o desactivar">
                                            <input type="checkbox" onchange="cambiarEstadoNovedad(<?php echo $nov['id']; ?>, this.checked)" <?php echo ($nov['estado'] == 1) ? 'checked' : ''; ?> class="sr-only peer">
                                            <div class="w-8 h-4 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-teal-600"></div>
                                        </label>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo ($nov['estado'] == 1) ? 'bg-teal-100 text-teal-800' : 'bg-slate-200 text-slate-600'; ?>">
                                            <?php echo ($nov['estado'] == 1) ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if ($canEditNovedad): ?>
                                            <button type="button" onclick="abrirModalEditarNovedad(<?php echo $nov['id']; ?>)" title="Editar novedad" class="p-1.5 rounded-lg text-slate-500 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-slate-800 transition-colors">
                                                <span class="material-symbols-outlined text-base">edit</span>
                                            </button>
                                            <button type="button" onclick="eliminarNovedad(<?php echo $nov['id']; ?>, '<?php echo addslashes($nov['nombre']); ?>')" title="Eliminar novedad" class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800 transition-colors">
                                                <span class="material-symbols-outlined text-base">delete</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-xs italic">Solo lectura</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="p-3 bg-slate-50 dark:bg-slate-800/60 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                <span>Mostrando <strong id="lblCantMostrada" class="text-slate-800 dark:text-slate-200"><?php echo count($novedadesList); ?></strong> concepto(s)</span>
                <span class="flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    Sincronización en vivo con liquidaciones y notas de ajuste
                </span>
            </div>
        </div>

    </main>

    <!-- MODAL: CREAR NOVEDAD -->
    <div id="modalCrearNovedad" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl max-w-lg w-full shadow-2xl overflow-hidden flex flex-col animate-in fade-in zoom-in-95 duration-150">
            
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-gradient-to-r from-amber-50 to-orange-50 dark:from-slate-800/80 dark:to-slate-800/40">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-amber-500 text-white flex items-center justify-center shadow-xs">
                        <span class="material-symbols-outlined text-lg">add_circle</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-outfit text-slate-900 dark:text-white">Nueva Novedad</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Registrar concepto para honorarios médicos o nómina</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalCrearNovedad()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formCrearNovedad" onsubmit="guardarNuevaNovedad(event)" class="p-6 space-y-4">
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Entidad -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            Entidad / IPS Emisora <span class="text-rose-500">*</span>
                        </label>
                        <select name="entidad_id" id="crear_entidad_id" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-amber-500/40 outline-none">
                            <option value="PROPIO">HERNÁN OCAZIONEZ (MATRIZ)</option>
                            <option value="TODAS">TODAS LAS ENTIDADES (GLOBAL)</option>
                            <?php foreach ($entidadesMap as $eId => $ent): ?>
                                <option value="<?php echo htmlspecialchars($eId); ?>"><?php echo htmlspecialchars($ent['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Código -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            Código Único <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="codigo" id="crear_codigo" placeholder="Ej: NOV-BON-01" required
                            class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-mono font-bold uppercase text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-amber-500/40 outline-none" />
                    </div>

                    <!-- Tipo -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            Tipo de Novedad <span class="text-rose-500">*</span>
                        </label>
                        <select name="tipo" id="crear_tipo" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-amber-500/40 outline-none">
                            <option value="ADICION">ADICIÓN (+) - A favor del médico</option>
                            <option value="DEDUCCION">DEDUCCIÓN (-) - En contra / Descuento</option>
                        </select>
                    </div>
                </div>

                <!-- Nombre Concepto -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                        Nombre del Concepto <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="nombre" id="crear_nombre" placeholder="Ej: Bonificación especial por turnos de contingencia" required
                        class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-semibold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-amber-500/40 outline-none" />
                </div>

                <!-- Valor Predeterminado -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                        Valor Predeterminado Sugerido ($ COP)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-mono text-xs font-bold">$</span>
                        <input type="number" step="100" min="0" name="valor_predeterminado" id="crear_valor" value="0" placeholder="0"
                            class="w-full pl-8 pr-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-mono font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-amber-500/40 outline-none" />
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1">Opcional. Si se establece en 0, el usuario digitará la cifra manualmente.</p>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                        Descripción o Criterio de Aplicación
                    </label>
                    <textarea name="descripcion" id="crear_descripcion" rows="2" placeholder="Detalle justificativo, condiciones de pago o reglamentación interna..."
                        class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-amber-500/40 outline-none resize-none"></textarea>
                </div>

                <!-- Footer Acciones -->
                <div class="pt-3 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end gap-2.5">
                    <button type="button" onclick="cerrarModalCrearNovedad()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitCrear" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 shadow-md shadow-amber-600/20 active:scale-95 transition-all flex items-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-base">save</span>
                        <span>Guardar Novedad</span>
                    </button>
                </div>

            </form>

        </div>
    </div>

    <!-- MODAL: EDITAR NOVEDAD -->
    <div id="modalEditarNovedad" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl max-w-lg w-full shadow-2xl overflow-hidden flex flex-col animate-in fade-in zoom-in-95 duration-150">
            
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-gradient-to-r from-slate-100 to-slate-50 dark:from-slate-800/80 dark:to-slate-800/40">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-teal-600 text-white flex items-center justify-center shadow-xs">
                        <span class="material-symbols-outlined text-lg">edit</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-outfit text-slate-900 dark:text-white">Editar Novedad</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Modificar concepto o valor sugerido</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEditarNovedad()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formEditarNovedad" onsubmit="guardarEdicionNovedad(event)" class="p-6 space-y-4">
                <input type="hidden" name="id" id="edit_id" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Entidad -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            Entidad / IPS Emisora <span class="text-rose-500">*</span>
                        </label>
                        <select name="entidad_id" id="edit_entidad_id" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-teal-500/40 outline-none">
                            <option value="PROPIO">HERNÁN OCAZIONEZ (MATRIZ)</option>
                            <option value="TODAS">TODAS LAS ENTIDADES (GLOBAL)</option>
                            <?php foreach ($entidadesMap as $eId => $ent): ?>
                                <option value="<?php echo htmlspecialchars($eId); ?>"><?php echo htmlspecialchars($ent['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Código -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            Código Único <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="codigo" id="edit_codigo" required
                            class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-mono font-bold uppercase text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-teal-500/40 outline-none" />
                    </div>

                    <!-- Tipo -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            Tipo de Novedad <span class="text-rose-500">*</span>
                        </label>
                        <select name="tipo" id="edit_tipo" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-teal-500/40 outline-none">
                            <option value="ADICION">ADICIÓN (+) - A favor del médico</option>
                            <option value="DEDUCCION">DEDUCCIÓN (-) - En contra / Descuento</option>
                        </select>
                    </div>
                </div>

                <!-- Nombre Concepto -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                        Nombre del Concepto <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="nombre" id="edit_nombre" required
                        class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-semibold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-teal-500/40 outline-none" />
                </div>

                <!-- Valor Predeterminado -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                        Valor Predeterminado Sugerido ($ COP)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-mono text-xs font-bold">$</span>
                        <input type="number" step="100" min="0" name="valor_predeterminado" id="edit_valor" placeholder="0"
                            class="w-full pl-8 pr-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-mono font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-teal-500/40 outline-none" />
                    </div>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                        Descripción o Criterio de Aplicación
                    </label>
                    <textarea name="descripcion" id="edit_descripcion" rows="2"
                        class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-teal-500/40 outline-none resize-none"></textarea>
                </div>

                <!-- Footer Acciones -->
                <div class="pt-3 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end gap-2.5">
                    <button type="button" onclick="cerrarModalEditarNovedad()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitEditar" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-teal-600 hover:bg-teal-500 shadow-md shadow-teal-600/20 active:scale-95 transition-all flex items-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-base">save</span>
                        <span>Actualizar Novedad</span>
                    </button>
                </div>

            </form>

        </div>
    </div>

    <!-- Footer Oficial -->
    <?php require_once(__DIR__ . '/includes/footer.php'); ?>

    <script>
        const SwalCustom = Swal.mixin({
            customClass: {
                confirmButton: 'px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white font-bold rounded-xl text-xs mx-1 cursor-pointer transition',
                cancelButton: 'px-4 py-2 bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl text-xs mx-1 cursor-pointer transition'
            },
            buttonsStyling: false
        });

        function aplicarFiltros() {
            const search = (document.getElementById('filterSearch').value || '').trim().toLowerCase();
            const entidad = (document.getElementById('filterEntidad').value || '').trim();
            const tipo = (document.getElementById('filterTipo').value || '').trim();
            const estado = (document.getElementById('filterEstado').value || '').trim();

            let visibles = 0;
            const filas = document.querySelectorAll('.fila-novedad');

            filas.forEach(fila => {
                const fCodigo = (fila.getAttribute('data-codigo') || '').toLowerCase();
                const fNombre = (fila.getAttribute('data-nombre') || '').toLowerCase();
                const fEntidad = (fila.getAttribute('data-entidad-id') || '');
                const fTipo = (fila.getAttribute('data-tipo') || '');
                const fEstado = (fila.getAttribute('data-estado') || '');
                const fDesc = (fila.getAttribute('data-descripcion') || '').toLowerCase();

                let matchSearch = !search || fCodigo.includes(search) || fNombre.includes(search) || fDesc.includes(search);
                let matchEntidad = !entidad || fEntidad === entidad || fEntidad === 'TODAS';
                let matchTipo = !tipo || fTipo === tipo;
                let matchEstado = !estado || fEstado === estado;

                if (matchSearch && matchEntidad && matchTipo && matchEstado) {
                    fila.style.display = '';
                    visibles++;
                } else {
                    fila.style.display = 'none';
                }
            });

            document.getElementById('lblCantMostrada').textContent = visibles;
        }

        // Modales
        function abrirModalCrearNovedad() {
            document.getElementById('formCrearNovedad').reset();
            document.getElementById('modalCrearNovedad').classList.remove('hidden');
        }

        function cerrarModalCrearNovedad() {
            document.getElementById('modalCrearNovedad').classList.add('hidden');
        }

        function abrirModalEditarNovedad(id) {
            fetch(`maestro_novedades.php?action=obtener_novedad&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data) {
                        const d = res.data;
                        document.getElementById('edit_id').value = d.id;
                        document.getElementById('edit_entidad_id').value = d.entidad_id;
                        document.getElementById('edit_codigo').value = d.codigo;
                        document.getElementById('edit_nombre').value = d.nombre;
                        document.getElementById('edit_tipo').value = d.tipo;
                        document.getElementById('edit_valor').value = d.valor_predeterminado;
                        document.getElementById('edit_descripcion').value = d.descripcion || '';
                        document.getElementById('modalEditarNovedad').classList.remove('hidden');
                    } else {
                        SwalCustom.fire({ icon: 'error', title: 'Error', text: res.error || 'No fue posible cargar los datos de la novedad.' });
                    }
                })
                .catch(err => {
                    console.error(err);
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Error de comunicación con el servidor.' });
                });
        }

        function cerrarModalEditarNovedad() {
            document.getElementById('modalEditarNovedad').classList.add('hidden');
        }

        // Envío AJAX Creación
        async function guardarNuevaNovedad(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitCrear');
            btn.disabled = true;
            btn.innerHTML = `<span class="material-symbols-outlined text-base animate-spin">sync</span><span>Guardando...</span>`;

            const formData = new FormData(document.getElementById('formCrearNovedad'));
            formData.append('action', 'crear_novedad');

            try {
                const resp = await fetch('maestro_novedades.php', { method: 'POST', body: formData });
                const res = await resp.json();

                if (res.success) {
                    cerrarModalCrearNovedad();
                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Novedad Creada!',
                        text: res.message || 'El concepto de novedad ha sido registrado exitosamente.',
                        timer: 1600,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    SwalCustom.fire({ icon: 'warning', title: 'Atención', text: res.error || 'No se pudo crear la novedad.' });
                }
            } catch(err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Error de conexión con el servidor.' });
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<span class="material-symbols-outlined text-base">save</span><span>Guardar Novedad</span>`;
            }
        }

        // Envío AJAX Edición
        async function guardarEdicionNovedad(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitEditar');
            btn.disabled = true;
            btn.innerHTML = `<span class="material-symbols-outlined text-base animate-spin">sync</span><span>Actualizando...</span>`;

            const formData = new FormData(document.getElementById('formEditarNovedad'));
            formData.append('action', 'editar_novedad');

            try {
                const resp = await fetch('maestro_novedades.php', { method: 'POST', body: formData });
                const res = await resp.json();

                if (res.success) {
                    cerrarModalEditarNovedad();
                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Novedad Actualizada!',
                        text: res.message || 'Los cambios se han guardado exitosamente.',
                        timer: 1600,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    SwalCustom.fire({ icon: 'warning', title: 'Atención', text: res.error || 'No se pudo actualizar la novedad.' });
                }
            } catch(err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Error de conexión con el servidor.' });
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<span class="material-symbols-outlined text-base">save</span><span>Actualizar Novedad</span>`;
            }
        }

        // Cambio de Estado (Activo / Inactivo)
        async function cambiarEstadoNovedad(id, nuevoEstado) {
            const formData = new FormData();
            formData.append('action', 'cambiar_estado');
            formData.append('id', id);
            formData.append('estado', nuevoEstado ? 1 : 0);

            try {
                const resp = await fetch('maestro_novedades.php', { method: 'POST', body: formData });
                const res = await resp.json();
                if (res.success) {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true
                    });
                    Toast.fire({
                        icon: 'success',
                        title: res.message || 'Estado actualizado'
                    });
                    // Actualizar dataset
                    const fila = document.querySelector(`.fila-novedad[data-id="${id}"]`);
                    if (fila) fila.setAttribute('data-estado', nuevoEstado ? '1' : '0');
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: res.error || 'No se pudo cambiar el estado.' });
                }
            } catch(err) {
                console.error(err);
            }
        }

        // Eliminar Novedad
        async function eliminarNovedad(id, nombre) {
            const confirm = await SwalCustom.fire({
                title: '¿Eliminar Novedad?',
                text: `¿Está seguro de eliminar el concepto "${nombre}"? Esta acción se registrará en la bitácora de auditoría.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, Eliminar',
                cancelButtonText: 'Cancelar'
            });

            if (!confirm.isConfirmed) return;

            const formData = new FormData();
            formData.append('action', 'eliminar_novedad');
            formData.append('id', id);

            try {
                const resp = await fetch('maestro_novedades.php', { method: 'POST', body: formData });
                const res = await resp.json();
                if (res.success) {
                    SwalCustom.fire({
                        icon: 'success',
                        title: 'Eliminada',
                        text: res.message || 'Novedad eliminada.',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: res.error || 'No se pudo eliminar.' });
                }
            } catch(err) {
                console.error(err);
            }
        }
    </script>
</body>
</html>
