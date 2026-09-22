<?php
/**
 * Centro Integral de Auditoría y Logs del Sistema (LIHO)
 * Monitorización en tiempo real de Seguridad, Tarifarios, Liquidaciones, Notas de Ajuste, Correos, Alertas Médicas y Usuarios.
 * IPS Hernán Ocazionez y Cía S.A.S.
 */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/logger_helper.php');

$userName   = $_SESSION['user_name'] ?? 'Usuario';
$userEmail  = $_SESSION['user_email'] ?? '';
$userRole   = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userRoleId = $_SESSION['user_role_id'] ?? ($_SESSION['user_role'] ?? 3);

// Permiso: ADMINISTRADOR (1) y FINANCIERA (2)
$canViewAudit = ($userRoleId == 1 || $userRoleId == 2 || in_array($userRole, ['ADMINISTRADOR', 'FINANCIERA', 'FINANCIERO']));

if (!$canViewAudit) {
    header("Location: dashboard.php");
    exit;
}

$conn = obtenerConexionLIHO();

// ============================================================================
// HELPER: CONSULTA DE LOGS FILTRADOS
// ============================================================================
function consultarLogsSistema($con, $filtros = []) {
    if (!$con) return ['logs' => [], 'stats' => []];

    $where = [];
    $params = [];

    // Filtro Módulo
    if (!empty($filtros['modulo'])) {
        $mod = strtoupper(trim($filtros['modulo']));
        if ($mod === 'LIQUIDACIONES') {
            $where[] = "ls.modulo IN ('LIQUIDACIONES', 'NOTAS_AJUSTE', 'LIQUIDACION', 'NOTA_AJUSTE')";
        } elseif ($mod === 'CORREOS') {
            $where[] = "ls.modulo IN ('CORREOS', 'CORREO', 'CERTIFICADOS_TRIBUTARIOS')";
        } else {
            $where[] = "ls.modulo = ?";
            $params[] = $mod;
        }
    }

    // Filtro Rango de Fechas
    if (!empty($filtros['fecha_inicio'])) {
        $where[] = "ls.fecha_registro >= ?";
        $params[] = trim($filtros['fecha_inicio']) . " 00:00:00";
    }
    if (!empty($filtros['fecha_fin'])) {
        $where[] = "ls.fecha_registro <= ?";
        $params[] = trim($filtros['fecha_fin']) . " 23:59:59";
    }

    // Filtro Búsqueda Libre
    if (!empty($filtros['q'])) {
        $q = "%" . trim($filtros['q']) . "%";
        $where[] = "(ls.evento LIKE ? OR ls.usuario_nombre LIKE ? OR ls.usuario_email LIKE ? OR ls.entidad_afectada LIKE ? OR ls.detalles LIKE ? OR ls.ip_origen LIKE ?)";
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT TOP 2000 
                ls.id, ls.modulo, ls.evento, ls.tipo_accion, ls.nivel,
                ls.usuario_id, ls.usuario_nombre, ls.usuario_email, ls.usuario_rol,
                ls.ip_origen, ls.user_agent, ls.entidad_afectada,
                ls.valor_anterior, ls.valor_nuevo, ls.detalles, ls.fecha_registro
            FROM dbo.logs_sistema ls WITH (NOLOCK)
            {$whereSql}
            ORDER BY ls.fecha_registro DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    $logs = [];

    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fechaStr = 'N/A';
            if ($r['fecha_registro'] instanceof DateTime) {
                $fechaStr = $r['fecha_registro']->format('Y-m-d H:i:s');
            } elseif (!empty($r['fecha_registro'])) {
                $fechaStr = (string)$r['fecha_registro'];
            }
            $r['fecha_formatted'] = $fechaStr;
            $logs[] = $r;
        }
    }

    return $logs;
}

// Estadísticas Generales
function obtenerEstadisticasLogs($con) {
    $stats = [
        'total'         => 0,
        'seguridad'     => 0,
        'tarifarios'    => 0,
        'liquidaciones' => 0,
        'correos'       => 0,
        'alertas'       => 0,
        'fallos'        => 0
    ];
    if (!$con) return $stats;

    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN modulo = 'SEGURIDAD' THEN 1 ELSE 0 END) AS seguridad,
                SUM(CASE WHEN modulo = 'TARIFARIO' THEN 1 ELSE 0 END) AS tarifarios,
                SUM(CASE WHEN modulo IN ('LIQUIDACIONES', 'NOTAS_AJUSTE', 'LIQUIDACION', 'NOTA_AJUSTE') THEN 1 ELSE 0 END) AS liquidaciones,
                SUM(CASE WHEN modulo IN ('CORREOS', 'CORREO', 'CERTIFICADOS_TRIBUTARIOS') THEN 1 ELSE 0 END) AS correos,
                SUM(CASE WHEN modulo = 'ALERTA_MEDICOS' THEN 1 ELSE 0 END) AS alertas,
                SUM(CASE WHEN nivel IN ('WARNING', 'ERROR') OR tipo_accion = 'FALLO' THEN 1 ELSE 0 END) AS fallos
            FROM dbo.logs_sistema WITH (NOLOCK)";
    
    $stmt = sqlsrv_query($con, $sql);
    if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $stats['total']         = intval($row['total'] ?? 0);
        $stats['seguridad']     = intval($row['seguridad'] ?? 0);
        $stats['tarifarios']    = intval($row['tarifarios'] ?? 0);
        $stats['liquidaciones'] = intval($row['liquidaciones'] ?? 0);
        $stats['correos']       = intval($row['correos'] ?? 0);
        $stats['alertas']       = intval($row['alertas'] ?? 0);
        $stats['fallos']        = intval($row['fallos'] ?? 0);
    }
    return $stats;
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'fetch_logs') {
    header('Content-Type: application/json; charset=utf-8');
    $filtros = [
        'modulo'       => $_GET['modulo'] ?? '',
        'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
        'fecha_fin'    => $_GET['fecha_fin'] ?? '',
        'q'            => $_GET['q'] ?? ''
    ];
    $logs = consultarLogsSistema($conn, $filtros);
    $stats = obtenerEstadisticasLogs($conn);
    echo json_encode(['success' => true, 'logs' => $logs, 'stats' => $stats, 'total' => count($logs)]);
    exit;
}

if ($action === 'export_excel') {
    $filtros = [
        'modulo'       => $_GET['modulo'] ?? '',
        'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
        'fecha_fin'    => $_GET['fecha_fin'] ?? '',
        'q'            => $_GET['q'] ?? ''
    ];
    $logs = consultarLogsSistema($conn, $filtros);

    $filename = "Auditoria_Logs_LIHO_" . date('Ymd_His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Logs_Auditoria</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '<style>
        body { font-family: Calibri, Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #14354E; color: #ffffff; font-weight: bold; padding: 10px; border: 1px solid #cbd5e1; text-align: left; }
        td { padding: 8px 10px; border: 1px solid #e2e8f0; font-size: 10pt; }
        .num { mso-number-format: "\@"; }
    </style></head><body>';
    echo '<table><thead><tr>
        <th>ID</th>
        <th>Fecha y Hora</th>
        <th>Modulo</th>
        <th>Evento</th>
        <th>Tipo Accion</th>
        <th>Nivel</th>
        <th>Usuario Responsable</th>
        <th>Correo Usuario</th>
        <th>Rol</th>
        <th>Entidad Afectada / Objeto</th>
        <th>Valor Anterior</th>
        <th>Valor Nuevo</th>
        <th>Detalles</th>
        <th>IP Origen</th>
        <th>Dispositivo / User Agent</th>
    </tr></thead><tbody>';

    foreach ($logs as $l) {
        echo '<tr>';
        echo '<td class="num">' . htmlspecialchars($l['id']) . '</td>';
        echo '<td>' . htmlspecialchars($l['fecha_formatted']) . '</td>';
        echo '<td>' . htmlspecialchars($l['modulo']) . '</td>';
        echo '<td>' . htmlspecialchars($l['evento']) . '</td>';
        echo '<td>' . htmlspecialchars($l['tipo_accion']) . '</td>';
        echo '<td>' . htmlspecialchars($l['nivel']) . '</td>';
        echo '<td>' . htmlspecialchars($l['usuario_nombre']) . '</td>';
        echo '<td>' . htmlspecialchars($l['usuario_email']) . '</td>';
        echo '<td>' . htmlspecialchars($l['usuario_rol']) . '</td>';
        echo '<td>' . htmlspecialchars($l['entidad_afectada']) . '</td>';
        echo '<td>' . htmlspecialchars($l['valor_anterior'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($l['valor_nuevo'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($l['detalles'] ?? '') . '</td>';
        echo '<td class="num">' . htmlspecialchars($l['ip_origen']) . '</td>';
        echo '<td>' . htmlspecialchars($l['user_agent']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$statsInit = obtenerEstadisticasLogs($conn);
$moduloPre = $_GET['modulo'] ?? '';
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Centro Integral de Logs y Auditoría | LIHO</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="shortcut icon" href="assets/img/hologo.png">

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#14354e",
                        tertiary: "#00c1be",
                        background: "#f8fafc"
                    },
                    fontFamily: {
                        sans: ["Montserrat", "sans-serif"],
                        mono: ["Consolas", "Monaco", "Courier New", "monospace"]
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Montserrat', sans-serif; }
        .tab-btn.active {
            background-color: #14354e;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(20, 53, 78, 0.25);
        }
        .dark .tab-btn.active {
            background-color: #00c1be;
            color: #0f172a;
            border-color: #00c1be;
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header / Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Content Container (Full Width Responsive) -->
    <main class="flex-grow w-full max-w-[100%] px-3 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- 1. Header Banner con Identidad Corporativa -->
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-primary via-[#1c486a] to-[#0d5958] p-6 sm:p-8 text-white shadow-xl border border-white/10">
            <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-white/10 backdrop-blur-md border border-white/20 text-tertiary text-xs font-bold uppercase tracking-wider mb-2.5 shadow-sm">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                        <span>Auditoría & Trazabilidad Global • LIHO</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl md:text-4xl font-black tracking-tight text-white flex items-center gap-3">
                        <span class="material-symbols-outlined text-3xl sm:text-4xl text-tertiary">manage_history</span>
                        Centro de Logs y Auditoría
                    </h1>
                    <p class="text-slate-200 text-xs sm:text-sm mt-1.5 max-w-3xl font-medium leading-relaxed">
                        Registro inalterable de cada evento del sistema: inicios de sesión, modificaciones de tarifas, liquidaciones y notas de ajuste, envíos de correo SMTP y alertas clínicas.
                    </p>
                </div>

                <!-- Botones Rápidos de Exportación y Refresco -->
                <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                    <button type="button" id="btnRecargarLogs" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/20 text-white text-xs font-bold transition-all duration-200 shadow-sm hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg text-tertiary">sync</span>
                        <span>Actualizar</span>
                    </button>

                    <button type="button" id="btnExportExcel" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg">download</span>
                        <span>Exportar a Excel</span>
                    </button>
                </div>
            </div>
            <!-- Círculo decorativo ambiental -->
            <div class="absolute -right-10 -bottom-10 w-80 h-80 bg-tertiary/20 rounded-full blur-3xl pointer-events-none"></div>
        </div>

        <!-- 2. Tarjetas Métricas KPI -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5 sm:gap-4">
            
            <!-- KPI 1: Total Eventos -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Total Eventos</p>
                    <h3 id="kpiTotal" class="text-2xl sm:text-3xl font-black text-primary dark:text-tertiary mt-1"><?php echo number_format($statsInit['total']); ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Trazabilidad global</p>
                </div>
                <div class="w-11 h-11 rounded-2xl bg-primary/10 dark:bg-tertiary/10 text-primary dark:text-tertiary flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-xl">dataset</span>
                </div>
            </div>

            <!-- KPI 2: Seguridad & Accesos -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Seguridad</p>
                    <h3 id="kpiSeguridad" class="text-2xl sm:text-3xl font-black text-blue-600 dark:text-blue-400 mt-1"><?php echo number_format($statsInit['seguridad']); ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Inicios y OTP</p>
                </div>
                <div class="w-11 h-11 rounded-2xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-xl">security</span>
                </div>
            </div>

            <!-- KPI 3: Tarifarios -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Tarifario</p>
                    <h3 id="kpiTarifarios" class="text-2xl sm:text-3xl font-black text-amber-600 dark:text-amber-400 mt-1"><?php echo number_format($statsInit['tarifarios']); ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Valores y estados</p>
                </div>
                <div class="w-11 h-11 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-xl">price_change</span>
                </div>
            </div>

            <!-- KPI 4: Liquidaciones & Notas -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Liquidaciones</p>
                    <h3 id="kpiLiquidaciones" class="text-2xl sm:text-3xl font-black text-emerald-600 dark:text-emerald-400 mt-1"><?php echo number_format($statsInit['liquidaciones']); ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Creación y estados</p>
                </div>
                <div class="w-11 h-11 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-xl">receipt_long</span>
                </div>
            </div>

            <!-- KPI 5: Correos SMTP -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Correos SMTP</p>
                    <h3 id="kpiCorreos" class="text-2xl sm:text-3xl font-black text-teal-600 dark:text-teal-400 mt-1"><?php echo number_format($statsInit['correos']); ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Envíos registrados</p>
                </div>
                <div class="w-11 h-11 rounded-2xl bg-teal-50 dark:bg-teal-950/50 text-teal-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-xl">outgoing_mail</span>
                </div>
            </div>

            <!-- KPI 6: Alertas Médicos -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10.5px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Alertas Médicas</p>
                    <h3 id="kpiAlertas" class="text-2xl sm:text-3xl font-black text-purple-600 dark:text-purple-400 mt-1"><?php echo number_format($statsInit['alertas']); ?></h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Recordatorios en vivo</p>
                </div>
                <div class="w-11 h-11 rounded-2xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-xl">notifications_active</span>
                </div>
            </div>

        </div>

        <!-- 3. Panel de Filtros Simplificado (Sin filtros innecesarios) -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 sm:p-6 shadow-sm border border-slate-200/80 dark:border-slate-800 space-y-4">
            
            <!-- Barra Superior: Pestañas de Módulos (Tabs) -->
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-4">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider mr-1">Módulo:</span>
                <button type="button" class="tab-btn <?php echo empty($moduloPre) ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="">
                    <span class="material-symbols-outlined text-sm">dashboard</span>
                    <span>Todos los Módulos</span>
                </button>
                <button type="button" class="tab-btn <?php echo ($moduloPre === 'SEGURIDAD') ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="SEGURIDAD">
                    <span class="material-symbols-outlined text-sm">security</span>
                    <span>Seguridad & Accesos</span>
                </button>
                <button type="button" class="tab-btn <?php echo ($moduloPre === 'TARIFARIO') ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="TARIFARIO">
                    <span class="material-symbols-outlined text-sm">price_change</span>
                    <span>Tarifarios & Parámetros</span>
                </button>
                <button type="button" class="tab-btn <?php echo ($moduloPre === 'LIQUIDACIONES') ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="LIQUIDACIONES">
                    <span class="material-symbols-outlined text-sm">receipt_long</span>
                    <span>Liquidaciones & Notas</span>
                </button>
                <button type="button" class="tab-btn <?php echo ($moduloPre === 'CORREOS') ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="CORREOS">
                    <span class="material-symbols-outlined text-sm">mail</span>
                    <span>Envíos de Correos</span>
                </button>
                <button type="button" class="tab-btn <?php echo ($moduloPre === 'ALERTA_MEDICOS') ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="ALERTA_MEDICOS">
                    <span class="material-symbols-outlined text-sm">notifications_active</span>
                    <span>Alerta Médicos</span>
                </button>
                <button type="button" class="tab-btn <?php echo ($moduloPre === 'USUARIOS') ? 'active' : ''; ?> px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer flex items-center gap-1.5" data-modulo="USUARIOS">
                    <span class="material-symbols-outlined text-sm">group</span>
                    <span>Usuarios & Roles</span>
                </button>
            </div>

            <!-- Controles Específicos: Fechas y Búsqueda en Vivo (Limpio y directo) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3.5 items-end">
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Fecha Inicial</label>
                    <input type="date" id="filtroFechaInicio" value=""
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Fecha Final</label>
                    <input type="date" id="filtroFechaFin" value=""
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                </div>

                <div class="sm:col-span-2 md:col-span-2 lg:col-span-4">
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Búsqueda Rápida en Vivo</label>
                    <div class="relative">
                        <input type="text" id="filtroBusqueda" placeholder="Buscar por usuario, correo, IP, CUPS, liquidación, médico o detalle..."
                            class="w-full pl-9 pr-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                        <span class="material-symbols-outlined absolute left-2.5 top-2 text-slate-400 text-base">search</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- 4. Tabla de Auditoría Unificada con Paginación -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200/80 dark:border-slate-800 overflow-hidden">
            
            <div class="p-4 sm:p-5 border-b border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/50">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl text-tertiary">format_list_bulleted</span>
                    <h3 class="text-sm font-black text-primary dark:text-slate-100 uppercase tracking-wider">Bitácora Global de Movimientos</h3>
                    <span id="badgeLogsCount" class="px-2 py-0.5 rounded-full text-[11px] bg-primary/10 dark:bg-tertiary/20 text-primary dark:text-tertiary font-black">0</span>
                </div>

                <div class="flex items-center gap-4">
                    <div class="text-xs text-slate-500 dark:text-slate-400 font-semibold" id="estadoCargaLogs">
                        Cargando bitácora...
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto w-full" id="tablaLogsContainer">
                <table class="w-full text-left border-collapse text-[11.5px] table-auto">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 bg-slate-100/70 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 uppercase tracking-wider text-[10px] font-extrabold">
                            <th class="py-3 px-2 text-center w-10">#</th>
                            <th class="py-3 px-2.5 w-32 whitespace-nowrap">Fecha y Hora</th>
                            <th class="py-3 px-2.5 w-36 whitespace-nowrap">Módulo</th>
                            <th class="py-3 px-2.5 w-44 whitespace-nowrap">Evento & Nivel</th>
                            <th class="py-3 px-2.5 w-48">Usuario</th>
                            <th class="py-3 px-2.5 w-56">Entidad / Objeto</th>
                            <th class="py-3 px-2.5 min-w-[220px]">Resumen de Acción</th>
                            <th class="py-3 px-2.5 text-center w-28 whitespace-nowrap">IP & Red</th>
                            <th class="py-3 px-2 text-center w-12">Detalle</th>
                        </tr>
                    </thead>
                    <tbody id="tablaLogsBody" class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300 font-medium">
                        <tr>
                            <td colspan="9" class="p-8 text-center text-slate-400 font-medium">
                                <span class="inline-flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg animate-spin">sync</span>
                                    Consultando registros de auditoría...
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 5. Barra de Paginación y Registros por Página -->
            <div class="p-4 sm:p-5 border-t border-slate-200/80 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/50 text-xs">
                
                <div class="flex items-center gap-3">
                    <span class="text-slate-500 dark:text-slate-400 font-medium" id="infoPaginacionTexto">Mostrando 0 de 0 registros</span>
                    <div class="flex items-center gap-1.5 border-l border-slate-200 dark:border-slate-700 pl-3">
                        <label class="text-slate-400 text-[11px]">Mostrar:</label>
                        <select id="selectPageSize" class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold outline-none cursor-pointer">
                            <option value="15">15</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-1" id="paginacionControles">
                    <!-- Inyectado dinámicamente -->
                </div>

            </div>

        </div>

    </main>

    <!-- MODAL: Inspección Profunda de Auditoría -->
    <div id="modalDetalleLog" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 transform scale-95 transition-transform duration-200 flex flex-col max-h-[90vh]" id="modalDetalleLogBox">
            
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-4 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-tertiary/10 text-tertiary" id="modalIconBox">
                        <span class="material-symbols-outlined text-2xl">visibility</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-primary dark:text-tertiary" id="modalTitulo">Detalle de Auditoría</h3>
                        <p class="text-xs text-slate-400" id="modalSubtitulo">ID Evento: --</p>
                    </div>
                </div>
                <button type="button" class="modal-close-btn p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="flex-grow overflow-y-auto space-y-4 pr-1 text-xs" id="modalContenidoBody">
                <!-- Inyectado dinámicamente -->
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-end shrink-0">
                <button type="button" class="modal-close-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- LOGICA JAVASCRIPT / AJAX DEL FRONTEND                                 -->
    <!-- ===================================================================== -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        let logsData = [];
        let moduloActivo = "<?php echo htmlspecialchars($moduloPre); ?>";

        // Variables de Paginación
        let currentPage = 1;
        let pageSize = 25;

        // Elementos DOM
        const tablaBody = document.getElementById('tablaLogsBody');
        const estadoCarga = document.getElementById('estadoCargaLogs');
        const badgeCount = document.getElementById('badgeLogsCount');

        const filtroFechaInicio = document.getElementById('filtroFechaInicio');
        const filtroFechaFin    = document.getElementById('filtroFechaFin');
        const filtroBusqueda    = document.getElementById('filtroBusqueda');
        const btnRecargar       = document.getElementById('btnRecargarLogs');
        const btnExportExcel    = document.getElementById('btnExportExcel');

        const selectPageSize       = document.getElementById('selectPageSize');
        const infoPaginacionTexto  = document.getElementById('infoPaginacionTexto');
        const paginacionControles  = document.getElementById('paginacionControles');

        // Control de Pestañas de Módulo
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                moduloActivo = this.dataset.modulo || '';
                currentPage = 1;
                cargarLogs();
            });
        });

        // 1. Cargar Logs desde el Servidor
        function cargarLogs() {
            estadoCarga.innerHTML = '<span class="inline-flex items-center gap-1.5 text-tertiary"><span class="material-symbols-outlined text-sm animate-spin">sync</span> Consultando...</span>';

            const fIni = filtroFechaInicio.value;
            const fFin = filtroFechaFin.value;
            const q    = filtroBusqueda.value;

            const url = `logs.php?action=fetch_logs&modulo=${encodeURIComponent(moduloActivo)}&fecha_inicio=${encodeURIComponent(fIni)}&fecha_fin=${encodeURIComponent(fFin)}&q=${encodeURIComponent(q)}`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        logsData = data.logs || [];
                        badgeCount.innerText = logsData.length;
                        estadoCarga.innerHTML = `<span class="text-emerald-600 font-bold inline-flex items-center gap-1"><span class="material-symbols-outlined text-xs">check</span> Actualizado (${logsData.length} registros)</span>`;

                        // Actualizar KPIs si vinieron
                        if (data.stats) {
                            if (document.getElementById('kpiTotal')) document.getElementById('kpiTotal').innerText = Number(data.stats.total || 0).toLocaleString('es-CO');
                            if (document.getElementById('kpiSeguridad')) document.getElementById('kpiSeguridad').innerText = Number(data.stats.seguridad || 0).toLocaleString('es-CO');
                            if (document.getElementById('kpiTarifarios')) document.getElementById('kpiTarifarios').innerText = Number(data.stats.tarifarios || 0).toLocaleString('es-CO');
                            if (document.getElementById('kpiLiquidaciones')) document.getElementById('kpiLiquidaciones').innerText = Number(data.stats.liquidaciones || 0).toLocaleString('es-CO');
                            if (document.getElementById('kpiCorreos')) document.getElementById('kpiCorreos').innerText = Number(data.stats.correos || 0).toLocaleString('es-CO');
                            if (document.getElementById('kpiAlertas')) document.getElementById('kpiAlertas').innerText = Number(data.stats.alertas || 0).toLocaleString('es-CO');
                        }

                        renderPaginacion();
                    } else {
                        estadoCarga.innerHTML = '<span class="text-rose-500 font-bold">Error en consulta</span>';
                    }
                })
                .catch(err => {
                    estadoCarga.innerHTML = '<span class="text-rose-500 font-bold">Error de red</span>';
                    console.error("Error al cargar logs:", err);
                });
        }

        // 2. Renderizar Paginación y Tabla
        function renderPaginacion() {
            const totalRegistros = logsData.length;
            const totalPaginas = Math.ceil(totalRegistros / pageSize) || 1;

            if (currentPage > totalPaginas) currentPage = totalPaginas;
            if (currentPage < 1) currentPage = 1;

            const startIndex = (currentPage - 1) * pageSize;
            const endIndex = Math.min(startIndex + pageSize, totalRegistros);
            const itemsPagina = logsData.slice(startIndex, endIndex);

            if (totalRegistros === 0) {
                infoPaginacionTexto.innerText = "Mostrando 0 de 0 registros";
                paginacionControles.innerHTML = "";
            } else {
                infoPaginacionTexto.innerText = `Mostrando ${startIndex + 1} a ${endIndex} de ${totalRegistros} registros`;
                
                // Construir botones de página
                let pagHtml = '';
                
                // Botón Anterior
                pagHtml += `
                    <button type="button" class="btn-pag p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none transition-colors cursor-pointer" ${currentPage === 1 ? 'disabled' : ''} data-page="${currentPage - 1}">
                        <span class="material-symbols-outlined text-base">chevron_left</span>
                    </button>
                `;

                // Rango de páginas visibles (hasta 5 números)
                let startPage = Math.max(1, currentPage - 2);
                let endPage = Math.min(totalPaginas, startPage + 4);
                if (endPage - startPage < 4) {
                    startPage = Math.max(1, endPage - 4);
                }

                if (startPage > 1) {
                    pagHtml += `<button type="button" class="btn-pag px-2.5 py-1 rounded-lg text-xs font-bold border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" data-page="1">1</button>`;
                    if (startPage > 2) pagHtml += `<span class="px-1 text-slate-400">...</span>`;
                }

                for (let p = startPage; p <= endPage; p++) {
                    const activeClass = (p === currentPage) 
                        ? 'bg-primary text-white dark:bg-tertiary dark:text-slate-900 border-primary dark:border-tertiary' 
                        : 'border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300';
                    pagHtml += `<button type="button" class="btn-pag px-2.5 py-1 rounded-lg text-xs font-bold border ${activeClass} transition-colors cursor-pointer" data-page="${p}">${p}</button>`;
                }

                if (endPage < totalPaginas) {
                    if (endPage < totalPaginas - 1) pagHtml += `<span class="px-1 text-slate-400">...</span>`;
                    pagHtml += `<button type="button" class="btn-pag px-2.5 py-1 rounded-lg text-xs font-bold border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" data-page="${totalPaginas}">${totalPaginas}</button>`;
                }

                // Botón Siguiente
                pagHtml += `
                    <button type="button" class="btn-pag p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none transition-colors cursor-pointer" ${currentPage === totalPaginas ? 'disabled' : ''} data-page="${currentPage + 1}">
                        <span class="material-symbols-outlined text-base">chevron_right</span>
                    </button>
                `;

                paginacionControles.innerHTML = pagHtml;

                document.querySelectorAll('.btn-pag').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const targetPage = parseInt(this.dataset.page, 10);
                        if (!isNaN(targetPage) && targetPage >= 1 && targetPage <= totalPaginas) {
                            currentPage = targetPage;
                            renderPaginacion();
                        }
                    });
                });
            }

            renderTablaLogs(itemsPagina, startIndex);
            const container = document.getElementById('tablaLogsContainer');
            if (container) container.scrollLeft = 0;
        }

        // 3. Renderizar Filas de la Tabla (Sin Emojis)
        function renderTablaLogs(lista, startIndex) {
            if (lista.length === 0) {
                tablaBody.innerHTML = `
                    <tr>
                        <td colspan="9" class="p-10 text-center text-slate-400 font-medium">
                            No se encontraron eventos o logs con los filtros especificados.
                        </td>
                    </tr>`;
                return;
            }

            let html = '';
            lista.forEach((l, idx) => {
                const globalIndex = startIndex + idx + 1;

                // Badge de Módulo (Con Iconos Vectoriales - CERO EMOJIS)
                let badgeModulo = '';
                switch (l.modulo) {
                    case 'SEGURIDAD':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 border border-blue-200/50"><span class="material-symbols-outlined text-sm">security</span><span>SEGURIDAD</span></span>';
                        break;
                    case 'TARIFARIO':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200/50"><span class="material-symbols-outlined text-sm">price_change</span><span>TARIFARIO</span></span>';
                        break;
                    case 'LIQUIDACIONES':
                    case 'LIQUIDACION':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200/50"><span class="material-symbols-outlined text-sm">receipt_long</span><span>LIQUIDACIONES</span></span>';
                        break;
                    case 'NOTAS_AJUSTE':
                    case 'NOTA_AJUSTE':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-cyan-50 dark:bg-cyan-950/40 text-cyan-700 dark:text-cyan-300 border border-cyan-200/50"><span class="material-symbols-outlined text-sm">note_alt</span><span>NOTAS AJUSTE</span></span>';
                        break;
                    case 'CORREOS':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200/50"><span class="material-symbols-outlined text-sm">mail</span><span>CORREOS</span></span>';
                        break;
                    case 'ALERTA_MEDICOS':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300 border border-purple-200/50"><span class="material-symbols-outlined text-sm">notifications_active</span><span>ALERTA MÉDICOS</span></span>';
                        break;
                    case 'USUARIOS':
                        badgeModulo = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border border-indigo-200/50"><span class="material-symbols-outlined text-sm">group</span><span>USUARIOS</span></span>';
                        break;
                    default:
                        badgeModulo = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700"><span class="material-symbols-outlined text-sm">dataset</span><span>${escapeHtml(l.modulo)}</span></span>`;
                }

                // Badge de Nivel / Evento
                let badgeNivel = '';
                if (l.nivel === 'SUCCESS') {
                    badgeNivel = '<span class="px-2 py-0.5 rounded-full text-[9.5px] font-extrabold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200/60">ÉXITO</span>';
                } else if (l.nivel === 'WARNING') {
                    badgeNivel = '<span class="px-2 py-0.5 rounded-full text-[9.5px] font-extrabold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border border-amber-200/60">ADVERTENCIA</span>';
                } else if (l.nivel === 'ERROR') {
                    badgeNivel = '<span class="px-2 py-0.5 rounded-full text-[9.5px] font-extrabold bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400 border border-rose-200/60">ERROR</span>';
                } else {
                    badgeNivel = '<span class="px-2 py-0.5 rounded-full text-[9.5px] font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">INFO</span>';
                }

                // Diff / Resumen
                let resumenAccion = escapeHtml(l.detalles || 'Sin detalles adicionales');
                if (l.valor_anterior !== null && l.valor_nuevo !== null) {
                    resumenAccion = `<div class="flex items-center gap-1.5 font-mono text-[11px]"><span class="line-through text-rose-500">${escapeHtml(l.valor_anterior)}</span> <span class="text-slate-400">→</span> <span class="font-bold text-emerald-600 dark:text-emerald-400">${escapeHtml(l.valor_nuevo)}</span></div><div class="text-[10px] text-slate-400 mt-0.5">${escapeHtml(l.detalles || '')}</div>`;
                }

                html += `
                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors group">
                    <td class="py-2.5 px-2 text-center font-bold text-slate-400 text-[10.5px]">${globalIndex}</td>
                    <td class="py-2.5 px-2.5 whitespace-nowrap">
                        <div class="font-bold text-slate-800 dark:text-slate-200 text-[11px]">${escapeHtml(l.fecha_formatted)}</div>
                    </td>
                    <td class="py-2.5 px-2.5 whitespace-nowrap">
                        ${badgeModulo}
                    </td>
                    <td class="py-2.5 px-2.5 whitespace-nowrap">
                        <div class="flex items-center gap-1.5">
                            <span class="font-mono text-[11px] font-bold text-primary dark:text-tertiary">${escapeHtml(l.evento)}</span>
                            ${badgeNivel}
                        </div>
                    </td>
                    <td class="py-2.5 px-2.5">
                        <div class="font-bold text-slate-800 dark:text-slate-200 text-[11px] truncate max-w-[180px]" title="${escapeHtml(l.usuario_nombre || 'Sistema')}">${escapeHtml(l.usuario_nombre || 'Sistema')}</div>
                        <div class="text-[10px] text-slate-400 font-mono truncate max-w-[180px]" title="${escapeHtml(l.usuario_email || '')}">
                            <span>${escapeHtml(l.usuario_email || 'N/A')}</span>
                            ${l.usuario_rol ? `<span class="ml-1 text-[9px] px-1 py-0.2 rounded bg-slate-100 dark:bg-slate-800 font-bold">${escapeHtml(l.usuario_rol)}</span>` : ''}
                        </div>
                    </td>
                    <td class="py-2.5 px-2.5">
                        <div class="font-bold text-slate-800 dark:text-slate-200 text-[11px] truncate max-w-[220px]" title="${escapeHtml(l.entidad_afectada || 'N/A')}">
                            ${escapeHtml(l.entidad_afectada || 'N/A')}
                        </div>
                    </td>
                    <td class="py-2.5 px-2.5">
                        <div class="text-[11px] truncate max-w-sm" title="${escapeHtml(l.detalles || '')}">${resumenAccion}</div>
                    </td>
                    <td class="py-2.5 px-2.5 text-center whitespace-nowrap">
                        <div class="font-mono text-[10.5px] text-slate-700 dark:text-slate-300 font-bold">${escapeHtml(l.ip_origen || '0.0.0.0')}</div>
                        <div class="text-[9.5px] text-slate-400 truncate max-w-[110px]" title="${escapeHtml(l.user_agent || '')}">${escapeHtml(l.user_agent || 'N/A')}</div>
                    </td>
                    <td class="py-2.5 px-2 text-center whitespace-nowrap">
                        <button type="button" class="btn-ver-log p-1.5 rounded-lg bg-slate-100 hover:bg-primary text-slate-600 hover:text-white dark:bg-slate-800 dark:hover:bg-tertiary dark:text-slate-300 dark:hover:text-slate-900 transition-all cursor-pointer shadow-xs"
                            title="Ver detalle completo" data-id="${l.id}">
                            <span class="material-symbols-outlined text-base">info</span>
                        </button>
                    </td>
                </tr>`;
            });

            tablaBody.innerHTML = html;

            document.querySelectorAll('.btn-ver-log').forEach(btn => {
                btn.addEventListener('click', function() {
                    const logId = parseInt(this.dataset.id, 10);
                    const item = logsData.find(x => x.id == logId);
                    if (item) abrirModalDetalle(item);
                });
            });
        }

        // 4. Modal Detalle Profundo
        function abrirModalDetalle(item) {
            document.getElementById('modalTitulo').innerText = `Evento: ${item.evento}`;
            document.getElementById('modalSubtitulo').innerText = `ID Registro: #${item.id} • ${item.fecha_formatted}`;

            let valorDiff = '';
            if (item.valor_anterior !== null || item.valor_nuevo !== null) {
                valorDiff = `
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2">
                    <span class="font-bold text-slate-500 uppercase tracking-wider text-[10px]">Trazabilidad de Cambio (Valor Anterior vs Nuevo):</span>
                    <div class="grid grid-cols-2 gap-3 pt-1">
                        <div class="p-2.5 rounded-xl bg-rose-50/60 dark:bg-rose-950/30 border border-rose-200/50">
                            <span class="text-[10px] font-black text-rose-600 uppercase">Valor Anterior</span>
                            <div class="font-mono text-xs font-bold text-slate-800 dark:text-slate-200 mt-1 break-all">${escapeHtml(item.valor_anterior || '(Vacío / Nulo)')}</div>
                        </div>
                        <div class="p-2.5 rounded-xl bg-emerald-50/60 dark:bg-emerald-950/30 border border-emerald-200/50">
                            <span class="text-[10px] font-black text-emerald-600 uppercase">Valor Nuevo</span>
                            <div class="font-mono text-xs font-bold text-slate-800 dark:text-slate-200 mt-1 break-all">${escapeHtml(item.valor_nuevo || '(Vacío / Nulo)')}</div>
                        </div>
                    </div>
                </div>`;
            }

            const bodyHtml = `
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700">
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase">Módulo</span>
                    <p class="font-black text-primary dark:text-tertiary mt-0.5">${escapeHtml(item.modulo)}</p>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase">Tipo Acción</span>
                    <p class="font-bold text-slate-700 dark:text-slate-300 mt-0.5">${escapeHtml(item.tipo_accion)}</p>
                </div>
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase">Nivel</span>
                    <p class="font-bold text-slate-700 dark:text-slate-300 mt-0.5">${escapeHtml(item.nivel)}</p>
                </div>
            </div>

            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-1.5">
                <span class="text-[10px] font-bold text-slate-400 uppercase">Usuario Responsable</span>
                <p class="font-bold text-slate-800 dark:text-slate-100 text-sm">${escapeHtml(item.usuario_nombre || 'Sistema')} (${escapeHtml(item.usuario_rol || 'N/A')})</p>
                <p class="text-xs text-tertiary font-mono">${escapeHtml(item.usuario_email || 'Sin correo asociado')}</p>
            </div>

            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase">Entidad Afectada / Objeto</span>
                <p class="font-bold text-slate-800 dark:text-slate-100">${escapeHtml(item.entidad_afectada || 'No especificada')}</p>
            </div>

            ${valorDiff}

            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase">Detalles / Contexto del Evento</span>
                <p class="text-slate-700 dark:text-slate-300 leading-relaxed font-mono text-[11px] whitespace-pre-wrap bg-white dark:bg-slate-900 p-3 rounded-xl border border-slate-200 dark:border-slate-800">${escapeHtml(item.detalles || 'Sin detalles adicionales')}</p>
            </div>

            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-1 text-[11px]">
                <span class="text-[10px] font-bold text-slate-400 uppercase">Conexión y Dispositivo</span>
                <p><strong>Dirección IP:</strong> <span class="font-mono">${escapeHtml(item.ip_origen || '0.0.0.0')}</span></p>
                <p class="truncate"><strong>User-Agent:</strong> <span class="text-slate-400">${escapeHtml(item.user_agent || 'N/A')}</span></p>
            </div>`;

            document.getElementById('modalContenidoBody').innerHTML = bodyHtml;

            const modal = document.getElementById('modalDetalleLog');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            document.getElementById('modalDetalleLogBox').classList.remove('scale-95');
        }

        // Helpers de Modales
        function cerrarModal(modalId) {
            const m = document.getElementById(modalId);
            if (!m) return;
            m.classList.add('opacity-0', 'pointer-events-none');
            const b = m.querySelector('div[id$="Box"]');
            if (b) b.classList.add('scale-95');
        }

        document.querySelectorAll('.modal-close-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.fixed');
                if (modal) cerrarModal(modal.id);
            });
        });

        // Eventos de Filtro
        btnRecargar.addEventListener('click', function() {
            currentPage = 1;
            cargarLogs();
        });
        filtroFechaInicio.addEventListener('change', function() {
            currentPage = 1;
            cargarLogs();
        });
        filtroFechaFin.addEventListener('change', function() {
            currentPage = 1;
            cargarLogs();
        });

        // Cambiar tamaño de página
        selectPageSize.addEventListener('change', function() {
            pageSize = parseInt(this.value, 10) || 25;
            currentPage = 1;
            renderPaginacion();
        });

        // Búsqueda en vivo con debounce
        let timerBusqueda;
        filtroBusqueda.addEventListener('input', function() {
            clearTimeout(timerBusqueda);
            timerBusqueda = setTimeout(function() {
                currentPage = 1;
                cargarLogs();
            }, 250);
        });

        // Exportar a Excel
        btnExportExcel.addEventListener('click', function() {
            const fIni = filtroFechaInicio.value;
            const fFin = filtroFechaFin.value;
            const q    = filtroBusqueda.value;
            window.location.href = `logs.php?action=export_excel&modulo=${encodeURIComponent(moduloActivo)}&fecha_inicio=${encodeURIComponent(fIni)}&fecha_fin=${encodeURIComponent(fFin)}&q=${encodeURIComponent(q)}`;
        });

        // Helper escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        // Carga inicial
        cargarLogs();

    });
    </script>
</body>
</html>
