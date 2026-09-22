<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');

$userName   = $_SESSION['user_name'] ?? 'Usuario';
$userRole   = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId     = $_SESSION['user_id'] ?? null;
$userEmail  = $_SESSION['user_email'] ?? '';
$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Permisos para ver historial de auditoría: Administradores (1) y Financiera (2)
$canViewAudit = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);
if (!$canViewAudit) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

$canEditTarifa = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// --------------------------------------------------------------------------
// CARGAR ENTIDADES PARA SELECTOR Y FILTROS
// --------------------------------------------------------------------------
$entidadesList = [];
$hoId = 4; // ID oficial de Hernán Ocazionez

$entidadesList['PROPIO'] = [
    'id'         => 'PROPIO',
    'id_int'     => $hoId,
    'nombre'     => 'HERNÁN OCAZIONEZ Y CÍA S.A.S.',
    'subtitulo'  => 'Tarifario General Matriz - Sistemas Diagnósticos',
    'tipo_ips'   => 'IPS Matriz / Propia',
    'nit'        => '800.149.695-1',
    'color_tema' => 'teal',
    'logo'       => 'assets/img/hologo.png'
];

if (isset($con) && $con !== false) {
    $sqlEnt = "SELECT id, nombre, nit, dv, logo, color_tema, ciudad, ISNULL(is_matriz, 0) AS is_matriz 
               FROM maestro_entidades 
               WHERE ISNULL(estado, 1) = 1 
               ORDER BY CASE WHEN ISNULL(is_matriz, 0) = 1 THEN 0 ELSE 1 END, id ASC";
    $stmtEnt = sqlsrv_query($con, $sqlEnt);
    if ($stmtEnt !== false) {
        while ($rE = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
            $eId = (int)$rE['id'];
            $nitComp = trim($rE['nit'] . (!empty($rE['dv']) ? '-' . $rE['dv'] : ''));
            $esMatriz = ((int)$rE['is_matriz'] === 1 || stripos($rE['nombre'], 'HERNAN') !== false || $eId === $hoId);

            if ($esMatriz) {
                $hoId = $eId;
                $hoData = [
                    'id'         => 'PROPIO',
                    'id_int'     => $hoId,
                    'nombre'     => $rE['nombre'],
                    'subtitulo'  => 'Tarifario General Matriz - Sistemas Diagnósticos',
                    'tipo_ips'   => 'IPS Matriz / Propia',
                    'nit'        => $nitComp,
                    'color_tema' => 'teal',
                    'logo'       => !empty($rE['logo']) ? $rE['logo'] : 'assets/img/hologo.png'
                ];
                $entidadesList['PROPIO'] = $hoData;
                $entidadesList[(string)$hoId] = $hoData;
            } else {
                $itemEnt = [
                    'id'         => (string)$eId,
                    'id_int'     => $eId,
                    'nombre'     => $rE['nombre'],
                    'subtitulo'  => !empty($rE['ciudad']) ? 'Sede ' . $rE['ciudad'] : 'Entidad en Convenio IPS',
                    'tipo_ips'   => 'IPS Externa',
                    'nit'        => $nitComp,
                    'color_tema' => !empty($rE['color_tema']) ? $rE['color_tema'] : 'purple',
                    'logo'       => !empty($rE['logo']) ? $rE['logo'] : ''
                ];
                $entidadesList[(string)$eId] = $itemEnt;
            }
        }
    }
}

// Entidad activa seleccionada
$entidadSeleccionada = isset($_GET['entidad_id']) ? trim($_GET['entidad_id']) : ($_SESSION['tarifario_entidad_id'] ?? 'PROPIO');
if ($entidadSeleccionada === (string)$hoId) {
    $entidadSeleccionada = 'PROPIO';
}
if (!isset($entidadesList[$entidadSeleccionada])) {
    $entidadSeleccionada = 'PROPIO';
}
$_SESSION['tarifario_entidad_id'] = $entidadSeleccionada;
$entidadActiva = $entidadesList[$entidadSeleccionada];

// Función de paletas sincronizada
if (!function_exists('obtenerPaletaEntidad')) {
    function obtenerPaletaEntidad($colorTema, $entId = '') {
        $c = strtolower(trim((string)$colorTema));
        if ($entId === 'PROPIO' || empty($entId) || $c === 'teal') {
            return [
                'key'            => 'teal',
                'nombre'         => 'Verde Esmeralda Institucional',
                'primary_hex'    => '#00b4b0',
                'secondary_hex'  => '#059669',
                'hex'            => '#00b4b0',
                'hex_dark'       => '#009693',
                'badge_border'   => 'border-teal-500/40 dark:border-teal-500/50',
                'badge_bg'       => 'bg-teal-50/70 dark:bg-teal-950/40',
                'badge_shadow'   => 'shadow-teal-500/10 dark:shadow-none',
                'logo_border'    => 'border-teal-300 dark:border-teal-700',
                'logo_ring'      => 'ring-4 ring-teal-500/20',
                'dot_pulse'      => 'bg-emerald-500',
                'pill_text'      => 'text-teal-600 dark:text-teal-400',
                'accent_text'    => 'text-teal-700 dark:text-teal-300',
                'blur_ambient'   => 'from-teal-500/25 via-emerald-500/15 to-transparent',
                'tab_active'     => 'bg-teal-600 hover:bg-teal-700 text-white shadow-md shadow-teal-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-md shadow-emerald-600/25',
                'ring_focus'     => 'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20',
                'card_badge'     => 'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-200 border-teal-200 dark:border-teal-700/60',
                'pill'           => 'bg-teal-50 dark:bg-teal-950/50 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800/60',
                'border_accent'  => 'border-teal-500',
            ];
        }
        return [
            'key'            => 'purple',
            'nombre'         => 'Púrpura Institucional',
            'primary_hex'    => '#8b5cf6',
            'secondary_hex'  => '#7c3aed',
            'hex'            => '#8b5cf6',
            'hex_dark'       => '#7c3aed',
            'badge_border'   => 'border-purple-500/40 dark:border-purple-500/50',
            'badge_bg'       => 'bg-purple-50/70 dark:bg-purple-950/40',
            'badge_shadow'   => 'shadow-purple-500/10 dark:shadow-none',
            'logo_border'    => 'border-purple-300 dark:border-purple-700',
            'logo_ring'      => 'ring-4 ring-purple-500/20',
            'dot_pulse'      => 'bg-purple-500',
            'pill_text'      => 'text-purple-600 dark:text-purple-400',
            'accent_text'    => 'text-purple-700 dark:text-purple-300',
            'blur_ambient'   => 'from-purple-500/25 via-violet-500/15 to-transparent',
            'tab_active'     => 'bg-purple-600 hover:bg-purple-700 text-white shadow-md shadow-purple-600/30',
            'btn_gradient'   => 'bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white shadow-md shadow-purple-600/25',
            'ring_focus'     => 'focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20',
            'card_badge'     => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-200 border-purple-200 dark:border-purple-700/60',
            'pill'           => 'bg-purple-50 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60',
            'border_accent'  => 'border-purple-500',
        ];
    }
}
$paletaModal = obtenerPaletaEntidad($entidadActiva['color_tema'] ?? 'teal', $entidadSeleccionada);

// --------------------------------------------------------------------------
// EXPORTACIÓN A EXCEL / CSV CON UTF-8 BOM
// --------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $filename = "auditoria_tarifario_" . date('Y-m-d_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($output, [
        'ID AUDITORIA',
        'FECHA Y HORA',
        'CODIGO CUPS',
        'EXAMEN / DESCRIPCION',
        'CAMPO MODIFICADO',
        'VALOR ANTERIOR',
        'VALOR NUEVO',
        'MOTIVO / JUSTIFICACION',
        'TIPO ACCION',
        'USUARIO RESPONSABLE',
        'CORREO USUARIO',
        'ROL USUARIO',
        'IP ORIGEN',
        'USER AGENT'
    ], ';');

    if (isset($con) && $con !== false) {
        $sqlExport = "SELECT id, fecha_cambio, codigo_examen, examen_nombre, campo_modificado, 
                             valor_anterior, valor_nuevo, motivo_cambio, tipo_accion, 
                             usuario_nombre, usuario_email, usuario_rol, ip_origen, user_agent 
                      FROM historial_cambios_tarifario 
                      ORDER BY id DESC";
        $stmtExp = sqlsrv_query($con, $sqlExport);
        if ($stmtExp !== false) {
            while ($r = sqlsrv_fetch_array($stmtExp, SQLSRV_FETCH_ASSOC)) {
                $fStr = '';
                if ($r['fecha_cambio'] instanceof DateTime) {
                    $fStr = $r['fecha_cambio']->format('Y-m-d H:i:s');
                } else {
                    $fStr = (string)$r['fecha_cambio'];
                }

                fputcsv($output, [
                    $r['id'],
                    $fStr,
                    $r['codigo_examen'],
                    $r['examen_nombre'],
                    $r['campo_modificado'],
                    $r['valor_anterior'],
                    $r['valor_nuevo'],
                    $r['motivo_cambio'],
                    $r['tipo_accion'],
                    $r['usuario_nombre'],
                    $r['usuario_email'],
                    $r['usuario_rol'],
                    $r['ip_origen'],
                    $r['user_agent']
                ], ';');
            }
        }
    }
    fclose($output);
    exit;
}

// --------------------------------------------------------------------------
// CONSULTA DE REGISTROS DE HISTORIAL
// --------------------------------------------------------------------------
$historialData = [];
$totalCambios = 0;
$cambiosPrecios = 0;
$cambiosEstados = 0;
$usuariosDistintos = [];
$fechaUltimoCambio = 'N/A';

if (isset($con) && $con !== false) {
    // Consulta principal de auditoría
    $sqlAudit = "SELECT TOP 1000 
                    h.id, h.tarifario_id, h.codigo_examen, h.examen_nombre, 
                    h.usuario_id, h.usuario_nombre, h.usuario_email, h.usuario_rol, 
                    h.campo_modificado, h.valor_anterior, h.valor_nuevo, h.motivo_cambio, 
                    h.ip_origen, h.user_agent, h.tipo_accion, h.fecha_cambio,
                    t.entidad_id, e.nombre AS entidad_nombre
                 FROM historial_cambios_tarifario h WITH (NOLOCK)
                 LEFT JOIN tarifario t WITH (NOLOCK) ON h.tarifario_id = t.id
                 LEFT JOIN maestro_entidades e WITH (NOLOCK) ON t.entidad_id = e.id
                 ORDER BY h.id DESC";

    $stmtAudit = sqlsrv_query($con, $sqlAudit);
    if ($stmtAudit !== false) {
        $idx = 0;
        while ($row = sqlsrv_fetch_array($stmtAudit, SQLSRV_FETCH_ASSOC)) {
            $fFormat = 'N/A';
            $fRaw = '';
            if ($row['fecha_cambio'] instanceof DateTime) {
                $fFormat = $row['fecha_cambio']->format('d/m/Y H:i');
                $fRaw = $row['fecha_cambio']->format('Y-m-d H:i:s');
                if ($idx === 0) {
                    $fechaUltimoCambio = $fFormat;
                }
            } elseif (!empty($row['fecha_cambio'])) {
                $fFormat = (string)$row['fecha_cambio'];
                $fRaw = (string)$row['fecha_cambio'];
                if ($idx === 0) {
                    $fechaUltimoCambio = $fFormat;
                }
            }

            $row['fecha_formatted'] = $fFormat;
            $row['fecha_raw']       = $fRaw;

            // Conteo de métricas
            $campo = strtoupper(trim((string)$row['campo_modificado']));
            if (in_array($campo, ['VALOR_BASE', 'VALOR_UND', 'VALOR_TEXTO', 'COLUMNA1'])) {
                $cambiosPrecios++;
            } elseif (in_array($campo, ['ESTADO', 'ACTIVO'])) {
                $cambiosEstados++;
            }

            if (!empty($row['usuario_nombre'])) {
                $usuariosDistintos[trim($row['usuario_nombre'])] = true;
            }

            $historialData[] = $row;
            $idx++;
        }
    }
    $totalCambios = count($historialData);
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Historial y Auditoría de Tarifario | LIHO</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Outfit & Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
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
                        outfit: ["Outfit", "sans-serif"]
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header / Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Container (Aprovechamiento de pantalla ancha) -->
    <main class="flex-grow max-w-[98%] 2xl:max-w-[1850px] w-full mx-auto px-3 sm:px-6 py-6 sm:py-8 space-y-6">

        <!-- Encabezado de Sección y Pestañas de Navegación del Módulo -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-slate-200/80 dark:bg-slate-800 text-xs font-bold uppercase tracking-wider mb-2 border border-slate-300/60 dark:border-slate-700/60">
                    <span class="material-symbols-outlined text-sm text-teal-600 dark:text-teal-400">history_toggle_off</span>
                    <span class="text-slate-600 dark:text-slate-300">Auditoría y Control de Cambios en Tarifarios</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight font-outfit">
                    Historial de Tarifario
                </h1>
                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                    Trazabilidad completa en tiempo real de modificaciones de precios, estados y parámetros de exámenes
                </p>
            </div>

            <!-- Toolbar Superior: Pestañas de Navegación Segmentada -->
            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                <div class="inline-flex p-1 bg-slate-200/80 dark:bg-slate-900/90 rounded-2xl border border-slate-300/70 dark:border-slate-800 shadow-xs">
                    <a href="tarifario.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">request_quote</span>
                        <span>Tarifario General</span>
                    </a>
                    <a href="tarifario_especial.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-purple-500">star</span>
                        <span>Tarifas Especiales</span>
                    </a>
                    <a href="maestro_porcentajes.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-emerald-500">percent</span>
                        <span>Modalidades y %</span>
                    </a>
                    <a href="historial_tarifario.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-extrabold <?php echo $paletaModal['tab_active']; ?> transition-all flex items-center gap-1.5 shadow-sm">
                        <span class="material-symbols-outlined text-sm">history</span>
                        <span>Historial / Auditoría</span>
                    </a>
                </div>

                <a href="historial_tarifario.php?action=export_csv" 
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all hover:scale-105 active:scale-95 cursor-pointer">
                    <span class="material-symbols-outlined text-base">download</span>
                    <span>Exportar Auditoría a Excel</span>
                </a>
            </div>
        </div>

        <!-- Tarjetas KPIs Resumen de Auditoría -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Total Auditorías -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5" style="border-left: 4px solid <?php echo $paletaModal['hex']; ?>;">
                <div class="p-3 rounded-2xl <?php echo $paletaModal['badge_bg']; ?> <?php echo $paletaModal['pill_text']; ?> shrink-0">
                    <span class="material-symbols-outlined text-2xl">history</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Registros</p>
                    <p class="text-2xl font-black text-slate-900 dark:text-white tracking-tight" id="kpiTotal"><?php echo $totalCambios; ?></p>
                </div>
            </div>

            <!-- Cambios de Valor / Precios -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">payments</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Cambios de Precio</p>
                    <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight" id="kpiPrecios"><?php echo $cambiosPrecios; ?></p>
                </div>
            </div>

            <!-- Cambios de Estado -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="p-3 rounded-2xl bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">toggle_on</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Activaciones / Desactivaciones</p>
                    <p class="text-2xl font-black text-amber-600 dark:text-amber-400 tracking-tight" id="kpiEstados"><?php echo $cambiosEstados; ?></p>
                </div>
            </div>

            <!-- Usuarios Distintos -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="p-3 rounded-2xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">group</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Usuarios Auditados</p>
                    <p class="text-2xl font-black text-blue-600 dark:text-blue-400 tracking-tight"><?php echo count($usuariosDistintos); ?></p>
                </div>
            </div>

            <!-- Última Modificación -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="p-3 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">update</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Última Modificación</p>
                    <p class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate mt-1"><?php echo $fechaUltimoCambio; ?></p>
                </div>
            </div>
        </div>

        <!-- Filtros de Auditoría y Búsqueda -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5">
                <!-- Buscador rápido -->
                <div class="relative lg:col-span-2">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                    <input type="text" id="searchInput" oninput="aplicarFiltrosAuditoria()" placeholder="Buscar por CUPS, examen, usuario o motivo..." 
                           class="w-full pl-10 pr-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none" />
                </div>

                <!-- Filtro por Campo Modificado -->
                <div>
                    <select id="filterCampo" onchange="aplicarFiltrosAuditoria()" class="w-full py-2.5 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none cursor-pointer">
                        <option value="">Todos los Campos</option>
                        <option value="VALOR_BASE">Valor Base (Precio)</option>
                        <option value="VALOR_TEXTO">Valor en Texto</option>
                        <option value="VALOR_UND">Valor Unidad</option>
                        <option value="ESTADO">Estado (Activa / Deshabilitada)</option>
                        <option value="TIPO_PACIENTE">Tipo Paciente (E / P)</option>
                        <option value="EXAMEN">Nombre del Examen</option>
                    </select>
                </div>

                <!-- Filtro por Tipo de Acción -->
                <div>
                    <select id="filterAccion" onchange="aplicarFiltrosAuditoria()" class="w-full py-2.5 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none cursor-pointer">
                        <option value="">Todas las Acciones</option>
                        <option value="EDICION">Edición de Tarifa</option>
                        <option value="ACTIVACION">Activación</option>
                        <option value="DESACTIVACION">Desactivación</option>
                    </select>
                </div>

                <!-- Selector de Registros por Página -->
                <div>
                    <select id="perPageSelect" onchange="cambiarRegistrosPorPagina(this.value)" class="w-full py-2.5 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none cursor-pointer">
                        <option value="15">15 por página</option>
                        <option value="25" selected>25 por página</option>
                        <option value="50">50 por página</option>
                        <option value="100">100 por página</option>
                        <option value="all">Ver todos los registros</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Tabla Principal de Auditoría -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs overflow-hidden" style="border-top: 3px solid <?php echo $paletaModal['hex']; ?>;">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tablaAuditoria">
                    <thead>
                        <tr class="bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 uppercase text-[10px] font-extrabold tracking-wider border-b border-slate-200 dark:border-slate-800">
                            <th class="py-3.5 px-4 whitespace-nowrap">FECHA Y HORA</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">CÓDIGO CUPS</th>
                            <th class="py-3.5 px-4 min-w-[240px]">EXAMEN / DESCRIPCIÓN</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">CAMPO MODIFICADO</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">VALOR ANTERIOR</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">VALOR NUEVO</th>
                            <th class="py-3.5 px-4 min-w-[200px]">MOTIVO / JUSTIFICACIÓN</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">USUARIO RESPONSABLE</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">IP ORIGEN</th>
                            <th class="py-3.5 px-4 text-center whitespace-nowrap">DETALLE</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-xs font-medium" id="tablaAuditoriaBody">
                        <!-- Renderizado dinámico con JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Footer Paginación -->
            <div class="py-3.5 px-6 bg-slate-50/80 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400 font-medium">
                <div>
                    Mostrando <strong id="lblPagFrom" class="text-slate-900 dark:text-white font-bold">0</strong> a <strong id="lblPagTo" class="text-slate-900 dark:text-white font-bold">0</strong> de <strong id="lblPagTotal" class="text-slate-900 dark:text-white font-bold">0</strong> registros auditados
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" id="btnPagPrev" onclick="cambiarPagina(-1)" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none font-bold transition-all flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-base">chevron_left</span>
                        <span>Anterior</span>
                    </button>

                    <span class="px-3 py-1.5 text-xs font-bold text-slate-700 dark:text-slate-300">
                        Página <span id="lblPaginaActual" class="<?php echo $paletaModal['pill_text']; ?> font-black">1</span> de <span id="lblTotalPaginas" class="font-black">1</span>
                    </span>

                    <button type="button" id="btnPagNext" onclick="cambiarPagina(1)" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none font-bold transition-all flex items-center gap-1 cursor-pointer">
                        <span>Siguiente</span>
                        <span class="material-symbols-outlined text-base">chevron_right</span>
                    </button>
                </div>
            </div>
        </div>

    </main>

    <!-- Modal Detalle Técnico de Auditoría -->
    <div id="modalDetalleAuditoria" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm transition-all">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-xl w-full overflow-hidden flex flex-col p-6 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl">manage_search</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-sm text-slate-900 dark:text-white">Detalle de Registro de Auditoría</h3>
                        <p class="text-[11px] text-slate-400 font-mono" id="mdlAuditId">Auditoría #0</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalDetalle()" class="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-white rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>

            <div class="space-y-3 text-xs" id="mdlAuditContent">
                <!-- Inyectado dinámicamente -->
            </div>

            <div class="pt-2 flex justify-end">
                <button type="button" onclick="cerrarModalDetalle()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold text-xs hover:bg-slate-200 dark:hover:bg-slate-700 transition cursor-pointer">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <!-- Script de Datos y Control -->
    <script>
        const allAuditData = <?php echo json_encode($historialData); ?>;
        let filteredAuditData = [...allAuditData];
        let paginaActual = 1;
        let registrosPorPagina = 25;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatMontoIfNumber(val) {
            if (val === null || val === undefined || val === '') return '-';
            const num = parseFloat(String(val).replace(/[^0-9.-]/g, ''));
            if (!isNaN(num) && /^\$?\s*[0-9]+/.test(String(val).trim())) {
                return '$ ' + num.toLocaleString('es-CO');
            }
            return String(val);
        }

        function obtenerEtiquetaCampo(campo) {
            const c = String(campo || '').toUpperCase().trim();
            if (c === 'VALOR_BASE' || c === 'VALOR_UND' || c === 'COLUMNA1') {
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 font-bold text-[10px] uppercase"><span class="w-1 h-1 rounded-full bg-emerald-500"></span> Precio Tarifa</span>`;
            }
            if (c === 'VALOR_TEXTO') {
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-400 border border-teal-200 dark:border-teal-800 font-bold text-[10px] uppercase">Formato Texto</span>`;
            }
            if (c === 'ESTADO') {
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-800 font-bold text-[10px] uppercase"><span class="w-1 h-1 rounded-full bg-amber-500"></span> Estado</span>`;
            }
            if (c === 'TIPO_PACIENTE') {
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400 border border-sky-200 dark:border-sky-800 font-bold text-[10px] uppercase">Tipo Paciente</span>`;
            }
            if (c === 'EXAMEN') {
                return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800 font-bold text-[10px] uppercase">Descripción Examen</span>`;
            }
            return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold text-[10px] uppercase font-mono">${escapeHtml(campo)}</span>`;
        }

        function aplicarFiltrosAuditoria() {
            const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
            const fCampo = document.getElementById('filterCampo').value.toUpperCase().trim();
            const fAccion = document.getElementById('filterAccion').value.toUpperCase().trim();

            filteredAuditData = allAuditData.filter(item => {
                // Buscador texto libre
                if (q !== '') {
                    const matchQ = (item.codigo_examen && item.codigo_examen.toLowerCase().includes(q)) ||
                                   (item.examen_nombre && item.examen_nombre.toLowerCase().includes(q)) ||
                                   (item.usuario_nombre && item.usuario_nombre.toLowerCase().includes(q)) ||
                                   (item.motivo_cambio && item.motivo_cambio.toLowerCase().includes(q)) ||
                                   (item.ip_origen && item.ip_origen.toLowerCase().includes(q));
                    if (!matchQ) return false;
                }

                // Filtro por Campo
                if (fCampo !== '') {
                    const itemCampo = String(item.campo_modificado || '').toUpperCase().trim();
                    if (fCampo === 'VALOR_BASE') {
                        if (!['VALOR_BASE', 'VALOR_UND', 'COLUMNA1'].includes(itemCampo)) return false;
                    } else if (itemCampo !== fCampo) {
                        return false;
                    }
                }

                // Filtro por Acción
                if (fAccion !== '') {
                    const itemAccion = String(item.tipo_accion || '').toUpperCase().trim();
                    if (itemAccion !== fAccion) return false;
                }

                return true;
            });

            paginaActual = 1;
            renderTablaAuditoria();
        }

        function cambiarRegistrosPorPagina(val) {
            registrosPorPagina = (val === 'all') ? 999999 : parseInt(val);
            paginaActual = 1;
            renderTablaAuditoria();
        }

        function cambiarPagina(delta) {
            paginaActual += delta;
            renderTablaAuditoria();
        }

        function renderTablaAuditoria() {
            const tbody = document.getElementById('tablaAuditoriaBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            const total = filteredAuditData.length;
            if (total === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="text-center py-12 text-slate-400 font-medium">
                            <span class="material-symbols-outlined text-4xl mb-2 text-slate-300 dark:text-slate-600 opacity-60">history_toggle_off</span>
                            <p class="font-bold text-sm text-slate-700 dark:text-slate-300">No se encontraron registros de auditoría para los criterios seleccionados.</p>
                            <p class="text-xs text-slate-400">Prueba cambiando los filtros de búsqueda.</p>
                        </td>
                    </tr>
                `;
                document.getElementById('lblPagFrom').textContent = '0';
                document.getElementById('lblPagTo').textContent = '0';
                document.getElementById('lblPagTotal').textContent = '0';
                document.getElementById('lblPaginaActual').textContent = '1';
                document.getElementById('lblTotalPaginas').textContent = '1';
                document.getElementById('btnPagPrev').disabled = true;
                document.getElementById('btnPagNext').disabled = true;
                return;
            }

            const totalPaginas = Math.ceil(total / registrosPorPagina);
            if (paginaActual > totalPaginas) paginaActual = totalPaginas;
            if (paginaActual < 1) paginaActual = 1;

            const inicio = (paginaActual - 1) * registrosPorPagina;
            const fin = Math.min(inicio + registrosPorPagina, total);
            const paginaItems = filteredAuditData.slice(inicio, fin);

            paginaItems.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors border-b border-slate-100 dark:border-slate-800/60';

                // Formateo de valores antes vs después
                const campo = String(item.campo_modificado || '').toUpperCase().trim();
                let valAntHtml = `<span class="text-slate-500 line-through font-mono text-[11px]">${escapeHtml(item.valor_anterior || '-')}</span>`;
                let valNueHtml = `<span class="text-slate-800 dark:text-white font-bold font-mono text-[11px]">${escapeHtml(item.valor_nuevo || '-')}</span>`;

                if (in_array_js(campo, ['VALOR_BASE', 'VALOR_UND', 'COLUMNA1'])) {
                    const numAnt = parseFloat(String(item.valor_anterior).replace(/[^0-9.-]/g, '')) || 0;
                    const numNue = parseFloat(String(item.valor_nuevo).replace(/[^0-9.-]/g, '')) || 0;
                    valAntHtml = `<span class="text-slate-500 line-through font-mono text-[11px]">$ ${numAnt.toLocaleString('es-CO')}</span>`;
                    
                    const dif = numNue - numAnt;
                    const difSign = dif >= 0 ? '+' : '';
                    const difCls = dif >= 0 ? 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200 dark:border-emerald-800' : 'text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/60 border-rose-200 dark:border-rose-800';
                    
                    valNueHtml = `
                        <div class="flex items-center gap-1.5">
                            <span class="font-bold text-slate-800 dark:text-white font-mono text-[11px]">$ ${numNue.toLocaleString('es-CO')}</span>
                            ${dif !== 0 ? `<span class="px-1.5 py-0.2 rounded text-[9px] font-black border ${difCls}">${difSign}$ ${dif.toLocaleString('es-CO')}</span>` : ''}
                        </div>
                    `;
                } else if (campo === 'ESTADO') {
                    valAntHtml = (item.valor_anterior === '1' || item.valor_anterior === 'Activa') ? 
                        '<span class="text-emerald-600 font-bold text-[10px]">Activa</span>' : 
                        '<span class="text-rose-600 font-bold text-[10px]">Deshabilitada</span>';
                    valNueHtml = (item.valor_nuevo === '1' || item.valor_nuevo === 'Activa') ? 
                        '<span class="text-emerald-600 font-bold text-[10px] bg-emerald-50 dark:bg-emerald-950/60 px-2 py-0.5 rounded border border-emerald-200 dark:border-emerald-800">Activa</span>' : 
                        '<span class="text-rose-600 font-bold text-[10px] bg-rose-50 dark:bg-rose-950/60 px-2 py-0.5 rounded border border-rose-200 dark:border-rose-800">Deshabilitada</span>';
                }

                const jsonStr = escapeHtml(JSON.stringify(item));

                tr.innerHTML = `
                    <td class="py-3.5 px-4 font-mono text-[11px] text-slate-500 dark:text-slate-400 whitespace-nowrap">
                        <div class="flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-slate-400 text-xs">schedule</span>
                            <span>${item.fecha_formatted || '-'}</span>
                        </div>
                    </td>
                    <td class="py-3.5 px-4 whitespace-nowrap">
                        <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary border border-slate-200 dark:border-slate-700 font-mono text-xs font-black shadow-2xs">
                            ${escapeHtml(item.codigo_examen || '-')}
                        </span>
                    </td>
                    <td class="py-3.5 px-4 font-bold text-slate-800 dark:text-slate-200 min-w-[240px]">
                        <span>${escapeHtml(item.examen_nombre || '-')}</span>
                    </td>
                    <td class="py-3.5 px-4 whitespace-nowrap">
                        ${obtenerEtiquetaCampo(item.campo_modificado)}
                    </td>
                    <td class="py-3.5 px-4 whitespace-nowrap">
                        ${valAntHtml}
                    </td>
                    <td class="py-3.5 px-4 whitespace-nowrap">
                        ${valNueHtml}
                    </td>
                    <td class="py-3.5 px-4 text-slate-600 dark:text-slate-300 min-w-[200px] text-xs">
                        <span class="italic">${escapeHtml(item.motivo_cambio || 'Sin justificación')}</span>
                    </td>
                    <td class="py-3.5 px-4 whitespace-nowrap">
                        <div class="flex flex-col">
                            <span class="font-bold text-slate-900 dark:text-white text-xs">${escapeHtml(item.usuario_nombre || 'Sistema')}</span>
                            <span class="text-[10px] text-slate-400 font-mono">${escapeHtml(item.usuario_rol || '-')}</span>
                        </div>
                    </td>
                    <td class="py-3.5 px-4 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                        <span class="inline-flex items-center gap-1">
                            <span class="material-symbols-outlined text-[12px] text-slate-400">lan</span>
                            <span>${escapeHtml(item.ip_origen || '127.0.0.1')}</span>
                        </span>
                    </td>
                    <td class="py-3.5 px-4 text-center whitespace-nowrap">
                        <button type="button" onclick="abrirModalDetalle(${item.id})" class="p-1.5 rounded-xl text-slate-500 hover:text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-950/50 transition cursor-pointer" title="Ver detalles técnicos">
                            <span class="material-symbols-outlined text-base">info</span>
                        </button>
                    </td>
                `;

                tbody.appendChild(tr);
            });

            document.getElementById('lblPagFrom').textContent = (inicio + 1).toLocaleString('es-CO');
            document.getElementById('lblPagTo').textContent = fin.toLocaleString('es-CO');
            document.getElementById('lblPagTotal').textContent = total.toLocaleString('es-CO');
            document.getElementById('lblPaginaActual').textContent = paginaActual.toLocaleString('es-CO');
            document.getElementById('lblTotalPaginas').textContent = totalPaginas.toLocaleString('es-CO');
            document.getElementById('btnPagPrev').disabled = (paginaActual <= 1);
            document.getElementById('btnPagNext').disabled = (paginaActual >= totalPaginas);
        }

        function in_array_js(val, arr) {
            return arr.indexOf(val) !== -1;
        }

        function abrirModalDetalle(id) {
            const item = allAuditData.find(x => x.id == id);
            if (!item) return;

            document.getElementById('mdlAuditId').textContent = `Auditoría #${item.id} • ${item.fecha_formatted}`;
            
            const html = `
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Código CUPS:</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">${escapeHtml(item.codigo_examen)}</span>
                    </div>
                    <div class="flex justify-between items-start">
                        <span class="text-slate-400">Examen:</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200 text-right">${escapeHtml(item.examen_nombre)}</span>
                    </div>
                    <div class="flex justify-between items-center border-t border-slate-200 dark:border-slate-700 pt-2">
                        <span class="text-slate-400">Campo Afectado:</span>
                        <span>${obtenerEtiquetaCampo(item.campo_modificado)}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Valor Previo:</span>
                        <span class="font-mono text-slate-500 line-through">${escapeHtml(item.valor_anterior || '-')}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Valor Asignado:</span>
                        <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">${escapeHtml(item.valor_nuevo || '-')}</span>
                    </div>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Motivo de Modificación:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">${escapeHtml(item.motivo_cambio || 'Sin justificación')}</span>
                    </div>
                    <div class="flex justify-between items-center border-t border-slate-200 dark:border-slate-700 pt-2">
                        <span class="text-slate-400">Usuario Responsable:</span>
                        <span class="font-bold text-slate-900 dark:text-white">${escapeHtml(item.usuario_nombre)} (${escapeHtml(item.usuario_rol)})</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Correo Electrónico:</span>
                        <span class="font-mono text-slate-500">${escapeHtml(item.usuario_email || 'N/A')}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Dirección IP:</span>
                        <span class="font-mono font-bold text-slate-600 dark:text-slate-300">${escapeHtml(item.ip_origen || '127.0.0.1')}</span>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[11px] text-slate-500 font-mono break-all">
                    <span class="font-bold text-slate-400 block text-[9px] uppercase tracking-wider mb-1">Navegador / Dispositivo (User Agent):</span>
                    ${escapeHtml(item.user_agent || 'No capturado')}
                </div>
            `;

            document.getElementById('mdlAuditContent').innerHTML = html;
            const modal = document.getElementById('modalDetalleAuditoria');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function cerrarModalDetalle() {
            const modal = document.getElementById('modalDetalleAuditoria');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Inicializar tabla al cargar
        document.addEventListener('DOMContentLoaded', () => {
            renderTablaAuditoria();
        });
    </script>

</body>
</html>
