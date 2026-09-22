<?php
/**
 * Módulo de Auditoría y Trazabilidad de Exámenes Excluidos - LIHO
 * IPS Hernán Ocazionez y Cía S.A.S.
 */
if (isset($_GET['action']) || isset($_POST['action'])) {
    @ini_set('display_errors', '0');
    @error_reporting(0);
}

date_default_timezone_set('America/Bogota');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

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

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/liquidaciones_helper.php';

$userId     = $_SESSION['user_id'] ?? 0;
$userName   = $_SESSION['user_name'] ?? 'Usuario';
$userRole   = $_SESSION['user_role'] ?? 'MÉDICO';
$userRoleId = $_SESSION['user_role_id'] ?? 3;

if (!tienePermisoModulo($userId, $userRoleId, 'examenes_medicos') && !tienePermisoModulo($userId, $userRoleId, 'liquidaciones')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// --------------------------------------------------------------------------
// ENDPOINTS AJAX (fetch_data, ver_detalle)
// --------------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'fetch_data') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $filtros = array(
        'fecha_desde'    => trim($_GET['fecha_desde'] ?? ''),
        'fecha_hasta'    => trim($_GET['fecha_hasta'] ?? ''),
        'medico'         => trim($_GET['medico'] ?? ''),
        'motivo'         => trim($_GET['motivo'] ?? ''),
        'liquidacion_id' => trim($_GET['liquidacion_id'] ?? ''),
        'search'         => trim($_GET['search'] ?? '')
    );

    $exclusiones = obtenerExamenesExcluidosBD($filtros);

    // Calcular KPIs
    $totalExcluidos  = count($exclusiones);
    $montoExcluido   = 0;
    $medicosMap      = array();
    $motivosCount    = array();

    foreach ($exclusiones as $ex) {
        $montoExcluido += floatval($ex['valor_a_pagar'] ?? 0);
        $med = trim($ex['medico_nombre'] ?? 'Sin Médico');
        if ($med !== '') {
            $medicosMap[$med] = true;
        }
        $mot = trim($ex['motivo_exclusion'] ?? 'Otro motivo');
        if (!isset($motivosCount[$mot])) {
            $motivosCount[$mot] = 0;
        }
        $motivosCount[$mot]++;
    }

    arsort($motivosCount);
    $principalMotivo = !empty($motivosCount) ? key($motivosCount) . ' (' . current($motivosCount) . ')' : 'Ninguno';

    $kpis = array(
        'total_excluidos'    => $totalExcluidos,
        'monto_excluido'     => $montoExcluido,
        'medicos_afectados'  => count($medicosMap),
        'motivo_principal'   => $principalMotivo,
        'motivos_desglose'   => $motivosCount
    );

    echo json_encode(array(
        'success' => true,
        'data'    => $exclusiones,
        'kpis'    => $kpis
    ));
    exit;
}

if ($action === 'ver_detalle') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);

    $con = obtenerConexionLIHO();
    if ($con === false) {
        echo json_encode(array('success' => false, 'error' => 'Error de conexión a la base de datos'));
        exit;
    }

    $sql = "SELECT e.*, l.estado AS liquidacion_estado, l.total_factura, l.total_a_pagar AS liq_total_pagar,
                   l.fecha_creacion AS liq_fecha_creacion, l.usuario_creador_nombre
            FROM liquidaciones_examenes_excluidos e
            LEFT JOIN liquidaciones_turnos l ON e.liquidacion_id = l.id
            WHERE e.id = ?";
    $stmt = sqlsrv_query($con, $sql, array($id));

    if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        if ($row['fecha_exclusion'] instanceof DateTime) {
            $row['fecha_exclusion'] = $row['fecha_exclusion']->format('Y-m-d H:i:s');
        }
        if (isset($row['liq_fecha_creacion']) && $row['liq_fecha_creacion'] instanceof DateTime) {
            $row['liq_fecha_creacion'] = $row['liq_fecha_creacion']->format('Y-m-d H:i:s');
        }
        $row['valor_examen'] = floatval($row['valor_examen'] ?? 0);
        $row['valor_a_pagar'] = floatval($row['valor_a_pagar'] ?? 0);

        echo json_encode(array('success' => true, 'data' => $row));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Registro no encontrado'));
    }
    exit;
}

// Cargar lista de médicos únicos de las exclusiones o catálogo para el filtro
$con = obtenerConexionLIHO();
$medicosFiltro = array();
if ($con !== false) {
    $stmtM = sqlsrv_query($con, "SELECT DISTINCT medico_cedula, medico_nombre FROM liquidaciones_examenes_excluidos WHERE medico_nombre IS NOT NULL ORDER BY medico_nombre ASC");
    if ($stmtM !== false) {
        while ($r = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC)) {
            $medicosFiltro[] = $r;
        }
    }
}

// Fechas por defecto: mes actual
$fechaDesdeDefault = date('Y-m-01');
$fechaHastaDefault = date('Y-m-t');
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50 dark:bg-slate-950">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Auditoría de Exámenes Excluidos | LIHO IPS</title>

    <!-- Tailwind CSS v3 CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#0f172a',
                        secondary: '#1e293b',
                        tertiary: '#0d9488',
                        accent: '#f59e0b',
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        outfit: ['"Outfit"', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <!-- SweetAlert2 & Animate.css -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(100, 116, 139, 0.3); border-radius: 9999px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(100, 116, 139, 0.5); }
    </style>
</head>
<body class="h-full font-sans text-slate-800 dark:text-slate-100 bg-slate-50 dark:bg-slate-950 flex flex-col antialiased">

    <!-- Top Navigation Bar -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <!-- Header Section -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-500 dark:bg-amber-500/20">
                        <span class="material-symbols-outlined text-2xl">do_not_disturb_on</span>
                    </span>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-black font-outfit text-slate-900 dark:text-white tracking-tight">
                            Auditoría de Exámenes Excluidos
                        </h1>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                            Historial y justificaciones obligatorias de exámenes omitidos durante la conciliación y liquidación.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <button type="button" onclick="exportarExclusionesAExcel()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md shadow-emerald-600/20 transition-all cursor-pointer hover:scale-105 active:scale-95">
                    <span class="material-symbols-outlined text-base">file_download</span>
                    <span>Exportar a Excel (.xls)</span>
                </button>
                <a href="examenes_medicos.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition-all cursor-pointer">
                    <span class="material-symbols-outlined text-base">arrow_back</span>
                    <span>Ir a Cruce de Exámenes</span>
                </a>
            </div>
        </div>

        <!-- 4 KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Card 1: Total Excluidos -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-amber-500/10 dark:bg-amber-500/20 text-amber-500 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">rule</span>
                </div>
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 tracking-wider block">Total Excluidos</span>
                    <span id="kpiTotalExcluidos" class="text-xl font-black font-outfit text-slate-900 dark:text-white">0</span>
                    <span class="text-[10px] text-slate-500 block">Exámenes auditados</span>
                </div>
            </div>

            <!-- Card 2: Monto No Pagado -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/10 dark:bg-rose-500/20 text-rose-500 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">payments</span>
                </div>
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 tracking-wider block">Monto Total Excluido</span>
                    <span id="kpiMontoExcluido" class="text-xl font-black font-outfit text-rose-600 dark:text-rose-400 font-mono">$0</span>
                    <span class="text-[10px] text-slate-500 block">Valor omitido de pago</span>
                </div>
            </div>

            <!-- Card 3: Médicos con Exclusiones -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-sky-500/10 dark:bg-sky-500/20 text-sky-500 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">medical_services</span>
                </div>
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 tracking-wider block">Médicos con Exclusión</span>
                    <span id="kpiMedicosAfectados" class="text-xl font-black font-outfit text-slate-900 dark:text-white">0</span>
                    <span class="text-[10px] text-slate-500 block">Profesionales registrados</span>
                </div>
            </div>

            <!-- Card 4: Principal Causal -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-teal-500/10 dark:bg-teal-500/20 text-teal-500 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">fact_check</span>
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 tracking-wider block">Causal Más Frecuente</span>
                    <span id="kpiMotivoPrincipal" class="text-xs font-bold text-slate-900 dark:text-white truncate block" title="Causal principal">-</span>
                    <span class="text-[10px] text-slate-500 block">Motivo predominante</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar Card -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs">
            <form id="formFiltrosExclusiones" onsubmit="event.preventDefault(); cargarExclusiones();" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5 items-end">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Fecha Desde</label>
                    <input type="date" id="filtro_fecha_desde" value="<?php echo $fechaDesdeDefault; ?>"
                        class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary outline-none">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Fecha Hasta</label>
                    <input type="date" id="filtro_fecha_hasta" value="<?php echo $fechaHastaDefault; ?>"
                        class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary outline-none">
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Médico</label>
                    <select id="filtro_medico" class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary outline-none">
                        <option value="">-- Todos los Médicos --</option>
                        <?php foreach ($medicosFiltro as $m): ?>
                            <option value="<?php echo htmlspecialchars($m['medico_nombre']); ?>">
                                <?php echo htmlspecialchars($m['medico_nombre'] . ($m['medico_cedula'] ? ' (' . $m['medico_cedula'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Causal / Motivo</label>
                    <select id="filtro_motivo" class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-semibold text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary outline-none">
                        <option value="">-- Todos los Motivos --</option>
                        <option value="Examen no realizado / cancelado">Examen no realizado / cancelado</option>
                        <option value="Examen duplicado en sistema">Examen duplicado en sistema</option>
                        <option value="Error de asignación de médico / no corresponde">Error de asignación de médico / no corresponde</option>
                        <option value="Pendiente de refacturación / glosa">Pendiente de refacturación / glosa</option>
                        <option value="Tarifa o CUPS no convenido / en revisión">Tarifa o CUPS no convenido / en revisión</option>
                        <option value="Lectura rechazada / sin informe válido">Lectura rechazada / sin informe válido</option>
                        <option value="Exclusión manual por auditoría médica">Exclusión manual por auditoría médica</option>
                        <option value="Otro motivo">Otro motivo</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="flex-1 py-2 px-3 rounded-xl bg-primary hover:bg-slate-800 text-white font-bold text-xs transition-all shadow-sm flex items-center justify-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-base">search</span>
                        <span>Consultar</span>
                    </button>
                    <button type="button" onclick="limpiarFiltros()" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition-colors" title="Limpiar filtros">
                        <span class="material-symbols-outlined text-base">restart_alt</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Table Container Card -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col">
            
            <!-- Quick Search Bar inside Table Header -->
            <div class="p-4 sm:p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/50">
                <div class="relative w-full sm:w-96">
                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-base pointer-events-none">search</span>
                    <input type="text" id="tableQuickSearch" oninput="filtrarTablaEnMemoria()" 
                        placeholder="Buscar por paciente, cédula, cups, examen, médico, auditor o detalle..." 
                        class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary outline-none font-medium">
                </div>
                
                <div class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                    Mostrando <strong id="lblMostrandoCount" class="text-slate-900 dark:text-white">0</strong> exclusiones registradas
                </div>
            </div>

            <!-- Responsive Table -->
            <div class="overflow-x-auto min-h-[350px] relative">
                
                <!-- Loading Spinner -->
                <div id="tableLoading" class="hidden absolute inset-0 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xs flex flex-col items-center justify-center z-20">
                    <div class="animate-spin rounded-full h-10 w-10 border-4 border-slate-200 border-t-tertiary mb-3"></div>
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300">Cargando registros de auditoría...</p>
                </div>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100/80 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-800 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                            <th class="py-3 px-3.5 text-center">ID</th>
                            <th class="py-3 px-3.5">Fecha Exclusión</th>
                            <th class="py-3 px-3.5">Médico</th>
                            <th class="py-3 px-3.5">Paciente / Cédula</th>
                            <th class="py-3 px-3.5">CUPS / Examen</th>
                            <th class="py-3 px-3.5">Causal de Exclusión</th>
                            <th class="py-3 px-3.5">Detalle y Justificación</th>
                            <th class="py-3 px-3.5 text-right">Valor A Pagar</th>
                            <th class="py-3 px-3.5 text-center">Liquidación</th>
                            <th class="py-3 px-3.5">Auditor / Usuario</th>
                            <th class="py-3 px-3.5 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody" class="divide-y divide-slate-100 dark:divide-slate-800/60 font-medium">
                        <!-- Filas renderizadas con JS -->
                    </tbody>
                </table>
            </div>

            <!-- Table Pagination Footer -->
            <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400 bg-slate-50/50 dark:bg-slate-900/50">
                <div class="flex items-center gap-1">
                    <span>Página</span>
                    <strong id="lblPaginaActual" class="text-slate-800 dark:text-slate-200">1</strong>
                    <span>de</span>
                    <strong id="lblTotalPaginas" class="text-slate-800 dark:text-slate-200">1</strong>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" id="btnPagPrev" onclick="cambiarPagina(-1)" disabled 
                        class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                        Anterior
                    </button>
                    <button type="button" id="btnPagNext" onclick="cambiarPagina(1)" disabled 
                        class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                        Siguiente
                    </button>
                </div>
            </div>

        </div>

    </main>

    <!-- Modal Detalle Completo de Exclusión -->
    <div id="modalDetalleExclusion" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden opacity-0 pointer-events-none transition-all duration-200">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden transform scale-95 transition-transform duration-200 font-sans">
            
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
                <div class="flex items-center gap-2.5">
                    <span class="p-2 rounded-xl bg-amber-500/10 text-amber-500">
                        <span class="material-symbols-outlined text-xl">fact_check</span>
                    </span>
                    <div>
                        <h3 class="font-black text-base font-outfit text-slate-900 dark:text-white">Auditoría Detallada de Exclusión</h3>
                        <p id="modalSubtitulo" class="text-[11px] text-slate-400">ID Exclusión: -</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalDetalle()" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-4 custom-scrollbar text-xs">
                
                <!-- Justificación Card -->
                <div class="p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">Causal Registrada</span>
                        <span id="modMotivo" class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-200">-</span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block mb-1">Detalle y Justificación</span>
                        <p id="modDetalle" class="text-slate-800 dark:text-slate-200 bg-white dark:bg-slate-900 p-3 rounded-xl border border-amber-200/60 dark:border-amber-900/60 italic leading-relaxed whitespace-pre-wrap">-</p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-2 border-t border-amber-200/60 dark:border-amber-900/60 text-[11px]">
                        <div>
                            <span class="text-slate-400">Usuario Auditor:</span>
                            <strong id="modUsuario" class="text-slate-800 dark:text-slate-200 block">-</strong>
                        </div>
                        <div>
                            <span class="text-slate-400">Fecha y Hora de Exclusión:</span>
                            <strong id="modFechaExclusion" class="text-slate-800 dark:text-slate-200 font-mono block">-</strong>
                        </div>
                    </div>
                </div>

                <!-- Datos del Examen -->
                <div class="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200 dark:border-slate-700/80 space-y-3">
                    <h4 class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-tertiary">medical_information</span>
                        Información del Examen Clínico
                    </h4>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Médico</span>
                            <strong id="modMedico" class="text-slate-800 dark:text-slate-200 block">-</strong>
                            <span id="modCedula" class="text-[10px] font-mono text-slate-400">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Paciente</span>
                            <strong id="modPaciente" class="text-slate-800 dark:text-slate-200 block">-</strong>
                            <span id="modDocPac" class="text-[10px] font-mono text-slate-400">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Entidad / EPS</span>
                            <span id="modEntidad" class="text-slate-700 dark:text-slate-300 font-medium">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">CUPS</span>
                            <strong id="modCups" class="text-slate-800 dark:text-slate-200 font-mono">-</strong>
                        </div>
                        <div class="sm:col-span-2">
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Nombre del Examen</span>
                            <span id="modExamen" class="text-slate-800 dark:text-slate-200 font-medium">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Fuente / Ingreso</span>
                            <span id="modFuenteIngreso" class="font-mono text-slate-700 dark:text-slate-300">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Monto Examen (Servinte)</span>
                            <span id="modValExamen" class="font-mono font-bold text-slate-700 dark:text-slate-300">$0</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Monto a Pagar (Tarifario)</span>
                            <span id="modValPagar" class="font-mono font-extrabold text-emerald-600 dark:text-emerald-400 text-sm">$0</span>
                        </div>
                    </div>
                </div>

                <!-- Liquidación Vinculada -->
                <div class="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200 dark:border-slate-700/80 space-y-2">
                    <h4 class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-primary dark:text-tertiary">receipt_long</span>
                        Liquidación Asociada
                    </h4>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">ID Liquidación</span>
                            <strong id="modLiqId" class="text-primary dark:text-tertiary font-mono text-sm">-</strong>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Periodo</span>
                            <span id="modLiqPeriodo" class="font-mono text-slate-700 dark:text-slate-300">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Estado Liquidación</span>
                            <span id="modLiqEstado" class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-slate-200 dark:bg-slate-700">-</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-slate-400 block">Total Pagado en Liq.</span>
                            <span id="modLiqTotal" class="font-mono font-bold text-slate-800 dark:text-slate-200">$0</span>
                        </div>
                    </div>
                </div>

            </div>

            <div class="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50 flex justify-end">
                <button type="button" onclick="cerrarModalDetalle()" class="px-5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-bold text-xs transition-colors cursor-pointer">
                    Cerrar Detalle
                </button>
            </div>

        </div>
    </div>

    <script>
        let allExclusiones = [];
        let filteredExclusiones = [];
        let paginaActual = 1;
        const registrosPorPagina = 25;

        const SwalCustom = Swal.mixin({
            customClass: {
                popup: 'bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl text-slate-100 font-sans p-6',
                title: 'text-lg font-black font-outfit text-white tracking-wide',
                htmlContainer: 'text-xs text-slate-300 font-medium leading-relaxed',
                confirmButton: 'px-5 py-2.5 rounded-xl bg-primary hover:bg-slate-800 dark:bg-tertiary dark:hover:bg-teal-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer mx-1 border border-white/10',
                cancelButton: 'px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-all cursor-pointer mx-1 border border-slate-700',
            },
            buttonsStyling: false
        });

        function htmlspecialchars(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function cargarExclusiones() {
            const spinner = document.getElementById('tableLoading');
            if (spinner) spinner.classList.remove('hidden');

            const fDesde = document.getElementById('filtro_fecha_desde').value;
            const fHasta = document.getElementById('filtro_fecha_hasta').value;
            const medico = document.getElementById('filtro_medico').value;
            const motivo = document.getElementById('filtro_motivo').value;

            const url = `examenes_excluidos.php?action=fetch_data&fecha_desde=${encodeURIComponent(fDesde)}&fecha_hasta=${encodeURIComponent(fHasta)}&medico=${encodeURIComponent(medico)}&motivo=${encodeURIComponent(motivo)}`;

            try {
                const res = await fetch(url);
                const json = await res.json();

                if (json.success) {
                    allExclusiones = json.data || [];
                    actualizarKPIs(json.kpis || {});
                    filtrarTablaEnMemoria();
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: json.error || 'No se pudieron consultar los datos' });
                }
            } catch (err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Error de comunicación con el servidor' });
            } finally {
                if (spinner) spinner.classList.add('hidden');
            }
        }

        function actualizarKPIs(kpis) {
            document.getElementById('kpiTotalExcluidos').textContent = (kpis.total_excluidos || 0).toLocaleString('es-CO');
            document.getElementById('kpiMontoExcluido').textContent = '$' + (kpis.monto_excluido || 0).toLocaleString('es-CO');
            document.getElementById('kpiMedicosAfectados').textContent = (kpis.medicos_afectados || 0).toLocaleString('es-CO');
            document.getElementById('kpiMotivoPrincipal').textContent = kpis.motivo_principal || '-';
            document.getElementById('kpiMotivoPrincipal').title = kpis.motivo_principal || '';
        }

        function limpiarFiltros() {
            document.getElementById('filtro_fecha_desde').value = '<?php echo $fechaDesdeDefault; ?>';
            document.getElementById('filtro_fecha_hasta').value = '<?php echo $fechaHastaDefault; ?>';
            document.getElementById('filtro_medico').value = '';
            document.getElementById('filtro_motivo').value = '';
            document.getElementById('tableQuickSearch').value = '';
            cargarExclusiones();
        }

        function filtrarTablaEnMemoria() {
            const q = document.getElementById('tableQuickSearch').value.toLowerCase().trim();

            if (!q) {
                filteredExclusiones = [...allExclusiones];
            } else {
                filteredExclusiones = allExclusiones.filter(r => {
                    const fields = [
                        r.id,
                        r.medico_nombre,
                        r.medico_cedula,
                        r.paciente,
                        r.documento_paciente,
                        r.cups,
                        r.examen_nombre,
                        r.motivo_exclusion,
                        r.detalle_exclusion,
                        r.usuario_nombre,
                        r.fuente,
                        r.ingreso,
                        r.liquidacion_id
                    ].map(v => String(v || '').toLowerCase());

                    return fields.some(f => f.includes(q));
                });
            }

            paginaActual = 1;
            renderizarTabla();
        }

        function renderizarTabla() {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            const total = filteredExclusiones.length;
            document.getElementById('lblMostrandoCount').textContent = total.toLocaleString('es-CO');

            if (total === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="11" class="py-16 text-center text-slate-400 dark:text-slate-500">
                            <span class="material-symbols-outlined text-4xl block mb-2 opacity-50">search_off</span>
                            <p class="font-bold text-sm">No se encontraron registros de exclusión</p>
                            <p class="text-xs text-slate-400">Prueba ajustando el rango de fechas o los filtros seleccionados.</p>
                        </td>
                    </tr>
                `;
                document.getElementById('lblPaginaActual').textContent = '1';
                document.getElementById('lblTotalPaginas').textContent = '1';
                document.getElementById('btnPagPrev').disabled = true;
                document.getElementById('btnPagNext').disabled = true;
                return;
            }

            const totalPaginas = Math.ceil(total / registrosPorPagina) || 1;
            if (paginaActual > totalPaginas) paginaActual = totalPaginas;

            const inicio = (paginaActual - 1) * registrosPorPagina;
            const fin = Math.min(inicio + registrosPorPagina, total);
            const paginaItems = filteredExclusiones.slice(inicio, fin);

            paginaItems.forEach(r => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors cursor-pointer';
                tr.onclick = () => verDetalleExclusion(r.id);

                const liqBadge = r.liquidacion_id 
                    ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md font-mono text-[10px] font-bold bg-teal-50 dark:bg-teal-950/80 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800">
                        #${r.liquidacion_id}
                       </span>`
                    : `<span class="text-slate-400 text-[10px]">-</span>`;

                tr.innerHTML = `
                    <td class="py-3 px-3.5 text-center font-mono text-slate-500">#${r.id}</td>
                    <td class="py-3 px-3.5 whitespace-nowrap">
                        <div class="font-medium text-slate-800 dark:text-slate-200">${htmlspecialchars(r.fecha_exclusion ? r.fecha_exclusion.substring(0, 10) : '-')}</div>
                        <div class="text-[10px] font-mono text-slate-400">${htmlspecialchars(r.fecha_exclusion ? r.fecha_exclusion.substring(11, 19) : '')}</div>
                    </td>
                    <td class="py-3 px-3.5">
                        <div class="font-bold text-slate-900 dark:text-white">${htmlspecialchars(r.medico_nombre || 'Sin Médico')}</div>
                        <div class="text-[10px] font-mono text-slate-400">${htmlspecialchars(r.medico_cedula || '')}</div>
                    </td>
                    <td class="py-3 px-3.5">
                        <div class="font-bold text-slate-800 dark:text-slate-200">${htmlspecialchars(r.paciente || 'N/A')}</div>
                        <div class="text-[10px] font-mono text-slate-400">${htmlspecialchars(r.documento_paciente || '')}</div>
                    </td>
                    <td class="py-3 px-3.5 max-w-[200px]">
                        <div class="font-semibold text-slate-900 dark:text-white truncate" title="${htmlspecialchars(r.examen_nombre)}">${htmlspecialchars(r.examen_nombre || '-')}</div>
                        <div class="text-[10px] font-mono text-teal-600 dark:text-teal-400 font-bold">CUPS: ${htmlspecialchars(r.cups || '-')}</div>
                    </td>
                    <td class="py-3 px-3.5">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 dark:bg-amber-950/90 dark:text-amber-300 border border-amber-300 dark:border-amber-700">
                            <span class="material-symbols-outlined text-xs">do_not_disturb_on</span>
                            ${htmlspecialchars(r.motivo_exclusion || 'Exclusión')}
                        </span>
                    </td>
                    <td class="py-3 px-3.5 max-w-[220px]">
                        <div class="text-slate-600 dark:text-slate-300 truncate italic text-[11px]" title="${htmlspecialchars(r.detalle_exclusion)}">
                            "${htmlspecialchars(r.detalle_exclusion || 'Sin detalle')}"
                        </div>
                    </td>
                    <td class="py-3 px-3.5 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                        $${Number(r.valor_a_pagar || 0).toLocaleString('es-CO')}
                    </td>
                    <td class="py-3 px-3.5 text-center whitespace-nowrap">
                        ${liqBadge}
                    </td>
                    <td class="py-3 px-3.5 whitespace-nowrap">
                        <div class="font-medium text-slate-800 dark:text-slate-200">${htmlspecialchars(r.usuario_nombre || 'Sistema')}</div>
                        <div class="text-[10px] text-slate-400">${htmlspecialchars(r.usuario_rol || '')}</div>
                    </td>
                    <td class="py-3 px-3.5 text-center whitespace-nowrap" onclick="event.stopPropagation();">
                        <button type="button" onclick="verDetalleExclusion(${r.id})" class="p-1.5 rounded-xl text-slate-400 hover:text-tertiary hover:bg-teal-50 dark:hover:bg-slate-800 transition-colors" title="Ver detalle completo">
                            <span class="material-symbols-outlined text-base">visibility</span>
                        </button>
                    </td>
                `;

                tbody.appendChild(tr);
            });

            document.getElementById('lblPaginaActual').textContent = paginaActual;
            document.getElementById('lblTotalPaginas').textContent = totalPaginas;
            document.getElementById('btnPagPrev').disabled = (paginaActual <= 1);
            document.getElementById('btnPagNext').disabled = (paginaActual >= totalPaginas);
        }

        function cambiarPagina(delta) {
            paginaActual += delta;
            renderizarTabla();
        }

        async function verDetalleExclusion(id) {
            try {
                const res = await fetch(`examenes_excluidos.php?action=ver_detalle&id=${id}`);
                const json = await res.json();

                if (!json.success || !json.data) {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: json.error || 'No se encontró el registro' });
                    return;
                }

                const d = json.data;

                document.getElementById('modalSubtitulo').textContent = `ID Exclusión: #${d.id} | Origen: ${d.origen || 'PROTEO'}`;
                document.getElementById('modMotivo').textContent = d.motivo_exclusion || 'Exclusión manual';
                document.getElementById('modDetalle').textContent = d.detalle_exclusion || 'Sin justificación detallada';
                document.getElementById('modUsuario').textContent = `${d.usuario_nombre || 'Sistema'} (${d.usuario_rol || 'Rol'})`;
                document.getElementById('modFechaExclusion').textContent = d.fecha_exclusion || '-';

                document.getElementById('modMedico').textContent = d.medico_nombre || 'Sin Médico';
                document.getElementById('modCedula').textContent = d.medico_cedula ? `C.C. ${d.medico_cedula}` : '';
                document.getElementById('modPaciente').textContent = d.paciente || 'N/A';
                document.getElementById('modDocPac').textContent = d.documento_paciente ? `Doc: ${d.documento_paciente}` : '';
                document.getElementById('modEntidad').textContent = d.entidad || '-';
                document.getElementById('modCups').textContent = d.cups || '-';
                document.getElementById('modExamen').textContent = d.examen_nombre || '-';
                document.getElementById('modFuenteIngreso').textContent = `Fuente ${d.fuente || '-'} / Ingreso ${d.ingreso || '-'}`;
                document.getElementById('modValExamen').textContent = `$${Number(d.valor_examen || 0).toLocaleString('es-CO')}`;
                document.getElementById('modValPagar').textContent = `$${Number(d.valor_a_pagar || 0).toLocaleString('es-CO')}`;

                document.getElementById('modLiqId').textContent = d.liquidacion_id ? `#${d.liquidacion_id}` : 'No vinculada';
                document.getElementById('modLiqPeriodo').textContent = (d.periodo_desde && d.periodo_hasta) ? `${d.periodo_desde} AL ${d.periodo_hasta}` : '-';
                document.getElementById('modLiqEstado').textContent = d.liquidacion_estado || 'N/A';
                document.getElementById('modLiqTotal').textContent = `$${Number(d.liq_total_pagar || 0).toLocaleString('es-CO')}`;

                abrirModalDetalle();
            } catch (err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Error al consultar el detalle' });
            }
        }

        function abrirModalDetalle() {
            const modal = document.getElementById('modalDetalleExclusion');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0', 'pointer-events-none');
                modal.firstElementChild.classList.remove('scale-95');
                modal.firstElementChild.classList.add('scale-100');
            }, 10);
        }

        function cerrarModalDetalle() {
            const modal = document.getElementById('modalDetalleExclusion');
            modal.firstElementChild.classList.remove('scale-100');
            modal.firstElementChild.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 200);
        }

        function exportarExclusionesAExcel() {
            if (filteredExclusiones.length === 0) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Datos', text: 'No hay exclusiones para exportar con los filtros actuales.' });
                return;
            }

            let tableHtml = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head>
                    <meta charset="utf-8"/>
                    <style>
                        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 11px; }
                        th { background: #0f172a; color: white; padding: 8px; border: 1px solid #cbd5e1; font-weight: bold; }
                        td { padding: 6px; border: 1px solid #cbd5e1; }
                        .num { text-align: right; }
                        .motivo { background: #fef3c7; color: #92400e; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <h2>Auditoría de Exámenes Excluidos de Liquidación (${filteredExclusiones.length} registros) - LIHO IPS</h2>
                    <p><strong>Fecha de Generación:</strong> ${new Date().toLocaleString()}</p>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Fecha Exclusión</th>
                                <th>ID Liquidación</th>
                                <th>Periodo Liquidación</th>
                                <th>Médico</th>
                                <th>Cédula Médico</th>
                                <th>Paciente</th>
                                <th>Documento Paciente</th>
                                <th>Entidad</th>
                                <th>CUPS</th>
                                <th>Nombre Examen</th>
                                <th>Fuente</th>
                                <th>Ingreso</th>
                                <th>Causal Exclusión</th>
                                <th>Detalle Justificación</th>
                                <th>Valor Examen</th>
                                <th>Valor A Pagar</th>
                                <th>Usuario Auditor</th>
                                <th>Rol Auditor</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            filteredExclusiones.forEach(r => {
                tableHtml += `
                    <tr>
                        <td>${r.id}</td>
                        <td>${htmlspecialchars(r.fecha_exclusion || '')}</td>
                        <td>${r.liquidacion_id || ''}</td>
                        <td>${htmlspecialchars((r.periodo_desde && r.periodo_hasta) ? `${r.periodo_desde} al ${r.periodo_hasta}` : '')}</td>
                        <td>${htmlspecialchars(r.medico_nombre || '')}</td>
                        <td>${htmlspecialchars(r.medico_cedula || '')}</td>
                        <td>${htmlspecialchars(r.paciente || '')}</td>
                        <td>${htmlspecialchars(r.documento_paciente || '')}</td>
                        <td>${htmlspecialchars(r.entidad || '')}</td>
                        <td>${htmlspecialchars(r.cups || '')}</td>
                        <td>${htmlspecialchars(r.examen_nombre || '')}</td>
                        <td>${htmlspecialchars(r.fuente || '')}</td>
                        <td>${htmlspecialchars(r.ingreso || '')}</td>
                        <td class="motivo">${htmlspecialchars(r.motivo_exclusion || '')}</td>
                        <td>${htmlspecialchars(r.detalle_exclusion || '')}</td>
                        <td class="num">${Number(r.valor_examen || 0)}</td>
                        <td class="num">${Number(r.valor_a_pagar || 0)}</td>
                        <td>${htmlspecialchars(r.usuario_nombre || '')}</td>
                        <td>${htmlspecialchars(r.usuario_rol || '')}</td>
                    </tr>
                `;
            });

            tableHtml += `
                        </tbody>
                    </table>
                </body>
                </html>
            `;

            const blob = new Blob([tableHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `Auditoria_Examenes_Excluidos_${new Date().toISOString().slice(0, 10)}.xls`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        // Cargar datos al iniciar
        document.addEventListener('DOMContentLoaded', () => {
            cargarExclusiones();
        });
    </script>
</body>
</html>
