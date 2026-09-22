<?php
/**
 * Panel de Analítica Financiera, Rentabilidad y Business Intelligence IPS
 * Margen de intermediación, Productividad MoM, Fuga de Ingresos y Auditoría
 * Sistema de Liquidación de Honorarios Médicos - LIHO
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/permisos_helper.php';
$con = obtenerConexionLIHO();

$userId     = intval($_SESSION['user_id'] ?? 0);
$userName   = $_SESSION['user_name'] ?? 'Usuario';
$userEmail  = $_SESSION['user_email'] ?? '';
$userRole   = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userRoleId = intval($_SESSION['user_role_id'] ?? ($userRole === 'ADMINISTRADOR' ? 1 : ($userRole === 'FINANCIERO' ? 2 : ($userRole === 'MÉDICO' ? 3 : 0))));

// Control estricto de acceso por rol y permisos especiales
$isAdmin = ($userRoleId === 1 || in_array($userRole, ['ADMINISTRADOR', 'ADMIN']));
if (!$isAdmin && !tienePermisoModulo($userId, $userRoleId, 'estadisticas')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Filtros Ejecutivos
$filtroAnio     = isset($_GET['anio']) ? trim($_GET['anio']) : '';
$filtroSemestre = isset($_GET['semestre']) ? trim($_GET['semestre']) : ''; // '1' = Ene-Jun, '2' = Jul-Dic, '' = Todos
$filtroEntidad  = isset($_GET['entidad']) ? trim($_GET['entidad']) : '';   // ID entidad, 'PROPIO', o ''
$filtroSede     = isset($_GET['sede']) ? trim($_GET['sede']) : '';

// Cargar Entidades disponibles de maestro_entidades para el selector
$listaEntidades = [];
if ($con) {
    $stmtEntList = sqlsrv_query($con, "SELECT id, nombre, nit, is_matriz, color_tema 
                                       FROM maestro_entidades 
                                       WHERE estado = 1 OR id = 4 
                                       ORDER BY CASE WHEN is_matriz = 1 THEN 0 ELSE 1 END, nombre ASC");
    if ($stmtEntList) {
        while ($eRow = sqlsrv_fetch_array($stmtEntList, SQLSRV_FETCH_ASSOC)) {
            $listaEntidades[] = $eRow;
        }
    }
}

// Mapeo de Médicos a Entidad para resolver liquidaciones históricas sin entidad_id explícito
$medicosEntidadMap = [];
if ($con) {
    $stmtMedEnt = sqlsrv_query($con, "SELECT m.cedula, COALESCE(u.entidad_id, m.entidad_id) AS entidad_id, e.nombre AS entidad_nombre 
                                      FROM medicos m 
                                      LEFT JOIN usuarios u ON m.usuario_id = u.id 
                                      LEFT JOIN maestro_entidades e ON COALESCE(u.entidad_id, m.entidad_id) = e.id");
    if ($stmtMedEnt) {
        while ($mRow = sqlsrv_fetch_array($stmtMedEnt, SQLSRV_FETCH_ASSOC)) {
            $cleanCed = preg_replace('/[^0-9]/', '', (string)$mRow['cedula']);
            if (!empty($cleanCed)) {
                $medicosEntidadMap[$cleanCed] = [
                    'id'     => (int)($mRow['entidad_id'] ?? 4),
                    'nombre' => trim((string)($mRow['entidad_nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'))
                ];
            }
        }
    }
}

// Años disponibles en base a los periodos registrados
$aniosDisponibles = [];
if ($con) {
    $stmtAnios = sqlsrv_query($con, "SELECT DISTINCT SUBSTRING(periodo_desde, 1, 4) AS anio FROM liquidaciones_turnos WHERE periodo_desde IS NOT NULL AND LEN(periodo_desde) >= 4");
    if ($stmtAnios) {
        while ($aRow = sqlsrv_fetch_array($stmtAnios, SQLSRV_FETCH_ASSOC)) {
            $a = trim((string)$aRow['anio']);
            if (!empty($a) && is_numeric($a) && !in_array($a, $aniosDisponibles)) {
                $aniosDisponibles[] = $a;
            }
        }
    }
}
$currY = date('Y');
if (!in_array($currY, $aniosDisponibles)) {
    $aniosDisponibles[] = $currY;
}
rsort($aniosDisponibles);

// 1. Métricas de Médicos y Catálogo Base
$totalMedicos = 0;
$medicosActivos = 0;
$medicosInactivos = 0;
$porcentajeActivos = 0;
$totalTarifas = 0;

if ($con) {
    $stmtM = sqlsrv_query($con, "SELECT COUNT(*) AS total FROM medicos");
    if ($stmtM && $rowM = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC)) {
        $totalMedicos = (int)$rowM['total'];
    }

    $stmtMA = sqlsrv_query($con, "SELECT COUNT(*) AS total FROM medicos m INNER JOIN usuarios u ON m.usuario_id = u.id WHERE UPPER(LTRIM(RTRIM(u.estado))) = '1'");
    if ($stmtMA && $rowMA = sqlsrv_fetch_array($stmtMA, SQLSRV_FETCH_ASSOC)) {
        $medicosActivos = (int)$rowMA['total'];
    }

    $medicosInactivos = max(0, $totalMedicos - $medicosActivos);
    if ($totalMedicos > 0) {
        $porcentajeActivos = round(($medicosActivos / $totalMedicos) * 100, 1);
    }

    $stmtT = sqlsrv_query($con, "SELECT COUNT(*) AS total FROM tarifario WHERE estado = 1");
    if ($stmtT && $rowT = sqlsrv_fetch_array($stmtT, SQLSRV_FETCH_ASSOC)) {
        $totalTarifas = (int)$rowT['total'];
    }
}

// 2. Extracción y Agregación Financiera Real (Business Intelligence Filtrado)
$kpiFacturadoIPS      = 0.0;
$kpiHonorariosMedicos = 0.0;
$kpiMargenBruto       = 0.0;
$kpiMargenPct         = 0.0;
$kpiFugaValor         = 0.0;
$kpiFugaCantidad      = 0;
$kpiTotalExamenes     = 0;

$sedesData        = [];
$conceptosData    = [];
$momData          = [];
$medicosRanking   = [];

if ($con) {
    $sqlLiq = "SELECT id, periodo_desde, periodo_hasta, medico_cedula, medico_nombre, 
                      total_factura, total_a_pagar, resumen_sedes_json, estado, fecha_creacion,
                      entidad_id, entidad_nombre
               FROM liquidaciones_turnos 
               WHERE estado != 'RECHAZADA'
               ORDER BY periodo_desde ASC, id ASC";
    
    $stmtLiq = sqlsrv_query($con, $sqlLiq);
    if ($stmtLiq) {
        while ($r = sqlsrv_fetch_array($stmtLiq, SQLSRV_FETCH_ASSOC)) {
            // Determinación de fechas, año, mes y semestre
            $periodoStr = trim((string)($r['periodo_desde'] ?? ''));
            if (!empty($periodoStr) && strlen($periodoStr) >= 7) {
                $mesKey  = substr($periodoStr, 0, 7);
                $recAnio = substr($periodoStr, 0, 4);
                $recMes  = (int)substr($periodoStr, 5, 2);
            } else {
                $dt = ($r['fecha_creacion'] instanceof DateTime) ? $r['fecha_creacion'] : new DateTime();
                $mesKey  = $dt->format('Y-m');
                $recAnio = $dt->format('Y');
                $recMes  = (int)$dt->format('m');
            }
            $recSemestre = ($recMes <= 6) ? '1' : '2';

            // 1. Filtrado por Año
            if ($filtroAnio !== '' && $recAnio !== $filtroAnio) {
                continue;
            }

            // 2. Filtrado por Semestre
            if ($filtroSemestre !== '' && $recSemestre !== $filtroSemestre) {
                continue;
            }

            // 3. Resolución de Entidad
            $recEntId  = trim((string)($r['entidad_id'] ?? ''));
            $recEntNom = trim((string)($r['entidad_nombre'] ?? ''));
            $resEntId  = 4; // Por defecto Matriz HO
            $resEntNom = 'HERNÁN OCAZIONEZ Y CÍA S.A.S.';

            if (is_numeric($recEntId) && (int)$recEntId > 0) {
                $resEntId = (int)$recEntId;
                $resEntNom = !empty($recEntNom) ? $recEntNom : 'Entidad #' . $resEntId;
            } elseif ($recEntId === 'PROPIO' || stripos($recEntNom, 'OCAZIONEZ') !== false) {
                $resEntId = 4;
                $resEntNom = 'HERNÁN OCAZIONEZ Y CÍA S.A.S.';
            } else {
                $cleanCed = preg_replace('/[^0-9]/', '', (string)($r['medico_cedula'] ?? ''));
                if (!empty($cleanCed) && isset($medicosEntidadMap[$cleanCed])) {
                    $resEntId  = $medicosEntidadMap[$cleanCed]['id'];
                    $resEntNom = $medicosEntidadMap[$cleanCed]['nombre'];
                }
            }

            // 4. Filtrado por Entidad
            if ($filtroEntidad !== '') {
                if ($filtroEntidad === 'PROPIO' || $filtroEntidad === '4') {
                    if ($resEntId !== 4 && $recEntId !== 'PROPIO') {
                        continue;
                    }
                } else {
                    if ((string)$resEntId !== (string)$filtroEntidad) {
                        continue;
                    }
                }
            }

            // Procesar valores de la liquidación filtrada
            $facturaLiq = (float)($r['total_factura'] ?? 0);
            $pagarLiq   = (float)($r['total_a_pagar'] ?? 0);
            
            $kpiFacturadoIPS      += $facturaLiq;
            $kpiHonorariosMedicos += $pagarLiq;
            
            // Mes a Mes (MoM)
            if (!isset($momData[$mesKey])) {
                $momData[$mesKey] = [
                    'factura'   => 0.0,
                    'pagar'     => 0.0,
                    'margen'    => 0.0,
                    'examenes'  => 0,
                    'liquidaciones' => 0
                ];
            }
            $momData[$mesKey]['factura']       += $facturaLiq;
            $momData[$mesKey]['pagar']         += $pagarLiq;
            $momData[$mesKey]['margen']        += ($facturaLiq - $pagarLiq);
            $momData[$mesKey]['liquidaciones'] += 1;

            // Ranking Médicos
            $medCedula = trim((string)($r['medico_cedula'] ?? 'GLOBAL'));
            $medNombre = trim((string)($r['medico_nombre'] ?? 'TODOS LOS MÉDICOS'));
            if (!isset($medicosRanking[$medCedula])) {
                $medicosRanking[$medCedula] = [
                    'nombre'        => $medNombre,
                    'cedula'        => $medCedula,
                    'liquidaciones' => 0,
                    'facturado'     => 0.0,
                    'pagado'        => 0.0,
                    'retencion'     => 0.0
                ];
            }
            $medicosRanking[$medCedula]['liquidaciones'] += 1;
            $medicosRanking[$medCedula]['facturado']     += $facturaLiq;
            $medicosRanking[$medCedula]['pagado']        += $pagarLiq;
            $medicosRanking[$medCedula]['retencion']     += ($facturaLiq - $pagarLiq);

            // Desglose por Sedes y Fuga de Ingresos desde JSON
            if (!empty($r['resumen_sedes_json'])) {
                $sedesArr = json_decode($r['resumen_sedes_json'], true);
                if (is_array($sedesArr)) {
                    foreach ($sedesArr as $sNom => $sInfo) {
                        $sNomTrim = trim((string)$sNom);
                        if (!isset($sedesData[$sNomTrim])) {
                            $sedesData[$sNomTrim] = [
                                'total_pagado'     => 0.0,
                                'examenes_count'   => 0,
                                'no_cruzados_count'=> 0,
                                'no_cruzados_valor'=> 0.0
                            ];
                        }
                        
                        $exCount = (int)($sInfo['examenes_count'] ?? 0);
                        $ncCount = (int)($sInfo['no_cruzados_count'] ?? 0);
                        $ncValor = (float)($sInfo['no_cruzados_valor'] ?? 0);
                        $totSede = (float)($sInfo['total'] ?? 0);

                        $sedesData[$sNomTrim]['total_pagado']      += $totSede;
                        $sedesData[$sNomTrim]['examenes_count']    += $exCount;
                        $sedesData[$sNomTrim]['no_cruzados_count'] += $ncCount;
                        $sedesData[$sNomTrim]['no_cruzados_valor'] += $ncValor;

                        $kpiFugaCantidad  += $ncCount;
                        $kpiFugaValor     += $ncValor;
                        $kpiTotalExamenes += $exCount;

                        if (isset($momData[$mesKey])) {
                            $momData[$mesKey]['examenes'] += $exCount;
                        }

                        // Conceptos / Especialidades
                        if (isset($sInfo['conceptos']) && is_array($sInfo['conceptos'])) {
                            foreach ($sInfo['conceptos'] as $cNom => $cInfo) {
                                $cNomTrim = trim((string)$cNom);
                                if (!isset($conceptosData[$cNomTrim])) {
                                    $conceptosData[$cNomTrim] = [
                                        'cantidad' => 0,
                                        'total'    => 0.0
                                    ];
                                }
                                $conceptosData[$cNomTrim]['cantidad'] += (int)($cInfo['cantidad'] ?? 0);
                                $conceptosData[$cNomTrim]['total']    += (float)($cInfo['total'] ?? 0);
                            }
                        }
                    }
                }
            }
        }
    }
}

// Cálculo final de Margen Bruto Institucional
$kpiMargenBruto = $kpiFacturadoIPS - $kpiHonorariosMedicos;
if ($kpiFacturadoIPS > 0) {
    $kpiMargenPct = round(($kpiMargenBruto / $kpiFacturadoIPS) * 100, 1);
}

// Ordenar ranking de médicos por facturación descendente
uasort($medicosRanking, function($a, $b) {
    return $b['facturado'] <=> $a['facturado'];
});

// Ordenar sedes por volumen facturado descendente
uasort($sedesData, function($a, $b) {
    return $b['total_pagado'] <=> $a['total_pagado'];
});

// Ordenar MoM cronológicamente
ksort($momData);

// Exportación CSV de Analítica si se solicita
if (isset($_GET['export']) && $_GET['export'] === 'bi_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="LIHO_BI_Rentabilidad_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    
    $filtroTexto = [];
    if (!empty($filtroAnio)) $filtroTexto[] = "AÑO: $filtroAnio";
    if (!empty($filtroSemestre)) $filtroTexto[] = "SEMESTRE: " . ($filtroSemestre === '1' ? '1er Semestre (Ene-Jun)' : '2do Semestre (Jul-Dic)');
    if (!empty($filtroEntidad)) {
        $entNomFil = 'Entidad #' . $filtroEntidad;
        foreach ($listaEntidades as $le) {
            if ((string)$le['id'] === (string)$filtroEntidad || ($filtroEntidad === 'PROPIO' && $le['is_matriz'] == 1)) {
                $entNomFil = $le['nombre'];
                break;
            }
        }
        $filtroTexto[] = "ENTIDAD: $entNomFil";
    }
    $filtroStr = !empty($filtroTexto) ? implode(' | ', $filtroTexto) : 'TODOS LOS PERÍODOS Y ENTIDADES';

    fputcsv($fp, ['HERNAN OCAZIONEZ Y CIA S.A.S. - INFORME DE BUSINESS INTELLIGENCE Y RENTABILIDAD IPS'], ';');
    fputcsv($fp, ['FILTROS APLICADOS: ' . $filtroStr], ';');
    fputcsv($fp, ['FECHA EXTRACCION: ' . date('d/m/Y H:i:s')], ';');
    fputcsv($fp, ['FACTURACION TOTAL IPS: ' . number_format($kpiFacturadoIPS, 2, '.', '')], ';');
    fputcsv($fp, ['HONORARIOS MEDICOS: ' . number_format($kpiHonorariosMedicos, 2, '.', '')], ';');
    fputcsv($fp, ['MARGEN INTERMEDIACION IPS: ' . number_format($kpiMargenBruto, 2, '.', '') . ' (' . $kpiMargenPct . '%)'], ';');
    fputcsv($fp, ['FUGA INGRESOS EN RIESGO (NO CRUZADOS): ' . number_format($kpiFugaValor, 2, '.', '') . ' (' . $kpiFugaCantidad . ' examenes)'], ';');
    fputcsv($fp, [], ';');

    fputcsv($fp, ['RESUMEN POR SEDE IPS', 'HONORARIOS LIQUIDADOS (COP)', 'EXAMENES TOTALES', 'NO CRUZADOS (CANT)', 'FUGA EN RIESGO (COP)'], ';');
    foreach ($sedesData as $sNom => $sInfo) {
        fputcsv($fp, [
            $sNom,
            number_format($sInfo['total_pagado'], 2, '.', ''),
            $sInfo['examenes_count'],
            $sInfo['no_cruzados_count'],
            number_format($sInfo['no_cruzados_valor'], 2, '.', '')
        ], ';');
    }
    fputcsv($fp, [], ';');

    fputcsv($fp, ['DESEMPEÑO MES A MES (MoM)', 'FACTURACION IPS', 'PAGO MEDICOS', 'MARGEN BRUTO IPS', 'LIQUIDACIONES'], ';');
    foreach ($momData as $mKey => $mInfo) {
        fputcsv($fp, [
            $mKey,
            number_format($mInfo['factura'], 2, '.', ''),
            number_format($mInfo['pagar'], 2, '.', ''),
            number_format($mInfo['margen'], 2, '.', ''),
            $mInfo['liquidaciones']
        ], ';');
    }

    fclose($fp);
    exit;
}

// Preparar datos para los Gráficos de Chart.js
$chartMomLabels   = [];
$chartMomFactura  = [];
$chartMomPagar    = [];
$chartMomMargen   = [];

$mesesNombres = [
    '01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr',
    '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
    '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'
];

foreach ($momData as $mesIso => $d) {
    $partes = explode('-', $mesIso);
    $mesNom = (isset($partes[1]) && isset($mesesNombres[$partes[1]])) ? $mesesNombres[$partes[1]] . ' ' . $partes[0] : $mesIso;
    $chartMomLabels[]  = $mesNom;
    $chartMomFactura[] = round($d['factura']);
    $chartMomPagar[]   = round($d['pagar']);
    $chartMomMargen[]  = round($d['margen']);
}

// Sedes Chart Data
$chartSedesLabels  = [];
$chartSedesValores = [];
$chartSedesFuga    = [];
foreach ($sedesData as $sNom => $sInfo) {
    if ($sInfo['total_pagado'] > 0 || $sInfo['no_cruzados_valor'] > 0) {
        $chartSedesLabels[]  = $sNom;
        $chartSedesValores[] = round($sInfo['total_pagado']);
        $chartSedesFuga[]    = round($sInfo['no_cruzados_valor']);
    }
}

// Conceptos Chart Data
$chartConceptosLabels = [];
$chartConceptosValores = [];
foreach ($conceptosData as $cNom => $cInfo) {
    $chartConceptosLabels[]  = $cNom;
    $chartConceptosValores[] = round($cInfo['total']);
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Business Intelligence & Rentabilidad IPS • LIHO</title>

    <!-- Tailwind CSS v3 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#003366',
                        secondary: '#4A5568',
                        tertiary: '#008080'
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'Inter', 'system-ui', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts & Material Symbols -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="shortcut icon" href="assets/img/hologo.png">

    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            display: inline-block;
            vertical-align: middle;
            line-height: 1;
        }

        /* Micro-animaciones para el panel de filtros BI */
        @keyframes filterSlideDown {
            from {
                opacity: 0;
                transform: translateY(-8px) scale(0.99);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .filter-panel-anim {
            animation: filterSlideDown 0.32s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .filter-field-card {
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), 
                        box-shadow 0.22s cubic-bezier(0.16, 1, 0.3, 1), 
                        border-color 0.2s ease;
        }
        .filter-field-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.08);
        }
        .dark .filter-field-card:hover {
            box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.45);
        }

        .filter-pill-btn {
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .filter-pill-btn:hover:not(.is-active) {
            transform: translateY(-1px);
        }

        @keyframes badgeIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-3px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        .active-filter-badge {
            animation: badgeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans min-h-screen flex flex-col antialiased transition-colors duration-200">

    <!-- Header & Navegación Global -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Contenido Principal -->
    <main class="flex-1 w-full max-w-[98%] 2xl:max-w-[1850px] mx-auto px-3 sm:px-6 py-6 sm:py-8 space-y-6">

        <!-- Barra Superior de Título y Filtros Ejecutivos -->
        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 pb-6 border-b border-slate-200/80 dark:border-slate-800/80">
            <div>
                <div class="flex items-center gap-2 text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">
                    <a href="dashboard.php" class="hover:text-primary dark:hover:text-tertiary transition-colors flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">home</span> Inicio
                    </a>
                    <span>/</span>
                    <span class="text-primary dark:text-tertiary">Analítica Gerencial</span>
                    <span>/</span>
                    <span class="text-slate-700 dark:text-slate-300">Business Intelligence & Rentabilidad</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-gradient-to-tr from-primary to-teal-700 text-white shadow-md shadow-primary/20">
                        <span class="material-symbols-outlined text-2xl">monitoring</span>
                    </div>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight flex items-center gap-2.5">
                            Panel de Analítica Financiera y Rentabilidad IPS
                            <span class="text-xs px-3 py-1 rounded-full bg-teal-100 dark:bg-teal-950 text-tertiary font-extrabold border border-teal-300 dark:border-teal-800 flex items-center gap-1">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                BI en Vivo
                            </span>
                        </h1>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-medium mt-0.5">
                            Margen de intermediación institucional, comparativos MoM, productividad por sede y monitoreo de fuga de ingresos por no cruce.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Acciones Rápidas (Exportar BI y Recargar) -->
            <div class="flex items-center gap-2.5 self-stretch sm:self-auto shrink-0">
                <a href="?export=bi_csv<?php echo !empty($filtroAnio) ? '&anio=' . urlencode($filtroAnio) : ''; ?><?php echo !empty($filtroSemestre) ? '&semestre=' . urlencode($filtroSemestre) : ''; ?><?php echo !empty($filtroEntidad) ? '&entidad=' . urlencode($filtroEntidad) : ''; ?><?php echo !empty($filtroSede) ? '&sede=' . urlencode($filtroSede) : ''; ?>" 
                    title="Exportar informe ejecutivo completo a Microsoft Excel / CSV con los filtros aplicados"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-bold shadow-xs transition-all hover:scale-102 active:scale-98 cursor-pointer">
                    <span class="material-symbols-outlined text-lg text-emerald-600">file_download</span>
                    <span>Descargar Reporte BI (CSV)</span>
                </a>

                <a href="estadisticas.php" 
                    title="Restablecer filtros y actualizar datos"
                    class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-300 text-xs font-bold transition-all cursor-pointer">
                    <span class="material-symbols-outlined text-base">refresh</span>
                    <span>Actualizar</span>
                </a>
            </div>
        </div>

        <!-- Panel de Filtros Multidimensionales: Año Fiscal, Semestre y Entidad / IPS -->
        <div class="bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl rounded-3xl border border-slate-200/90 dark:border-slate-800 p-4 sm:p-5 shadow-lg shadow-slate-900/5 dark:shadow-black/25 relative overflow-hidden filter-panel-anim">
            
            <!-- Barra superior decorativa en gradiente -->
            <div class="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-teal-400 via-purple-500 to-blue-500"></div>

            <!-- Encabezado del Panel de Filtros -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-3.5 mb-4 border-b border-slate-100 dark:border-slate-800/80">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-teal-500 to-emerald-400 text-white shadow-md shadow-teal-500/20 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-xl">tune</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 uppercase tracking-wider">
                                Segmentación y Filtros BI
                            </h2>
                            <?php 
                            $numFiltrosActivos = (!empty($filtroAnio) ? 1 : 0) + (!empty($filtroSemestre) ? 1 : 0) + (!empty($filtroEntidad) ? 1 : 0);
                            ?>
                            <?php if ($numFiltrosActivos > 0): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-primary/10 dark:bg-primary/25 text-primary dark:text-tertiary border border-primary/20 flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                    <?php echo $numFiltrosActivos; ?> activo<?php echo $numFiltrosActivos > 1 ? 's' : ''; ?>
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">
                                    Vista Consolidada
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                            Personalice la analítica institucional por vigencia fiscal, corte semestral e IPS contratante
                        </p>
                    </div>
                </div>

                <?php if ($numFiltrosActivos > 0): ?>
                <a href="estadisticas.php" 
                    title="Restablecer todos los filtros y volver a la vista consolidada"
                    class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-rose-50 dark:bg-slate-800 dark:hover:bg-rose-950/40 text-slate-600 hover:text-rose-600 dark:text-slate-300 dark:hover:text-rose-400 text-xs font-bold transition-all flex items-center gap-1.5 border border-transparent hover:border-rose-300 dark:hover:border-rose-800 cursor-pointer shrink-0">
                    <span class="material-symbols-outlined text-sm text-rose-500">filter_alt_off</span>
                    <span>Restablecer Todo</span>
                </a>
                <?php endif; ?>
            </div>

            <!-- Formulario de Filtros con Controles Modernos -->
            <form method="GET" action="estadisticas.php" id="formFiltrosBI" class="space-y-4">
                <input type="hidden" name="semestre" id="inputSemestre" value="<?php echo htmlspecialchars($filtroSemestre); ?>" />
                <?php if (!empty($filtroSede)): ?>
                    <input type="hidden" name="sede" value="<?php echo htmlspecialchars($filtroSede); ?>" />
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-3.5 items-end">
                    
                    <!-- 1. Año Fiscal (md:col-span-3 lg:col-span-3) -->
                    <div class="md:col-span-3 lg:col-span-3">
                        <label class="block text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 mb-1.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                            <span>Año Fiscal</span>
                        </label>
                        <div class="filter-field-card relative bg-slate-50/90 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl p-1 flex items-center gap-2 focus-within:ring-4 focus-within:ring-blue-500/15 focus-within:border-blue-500 transition-all">
                            <div class="w-8 h-8 rounded-xl bg-blue-500/10 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 ml-1">
                                <span class="material-symbols-outlined text-base">calendar_today</span>
                            </div>
                            <select name="anio" onchange="submitFiltroConEfecto()" 
                                class="w-full bg-transparent text-xs font-bold text-slate-800 dark:text-slate-100 outline-none pr-8 py-1.5 cursor-pointer appearance-none">
                                <option value="" class="dark:bg-slate-900">Todos los Años</option>
                                <?php foreach ($aniosDisponibles as $a): ?>
                                    <option value="<?php echo htmlspecialchars($a); ?>" <?php echo ($filtroAnio === (string)$a) ? 'selected' : ''; ?> class="dark:bg-slate-900">
                                        Año <?php echo htmlspecialchars($a); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined text-base text-slate-400 absolute right-3 pointer-events-none transition-transform">expand_more</span>
                        </div>
                    </div>

                    <!-- 2. Semestre (Segmented Pills / Switch Moderno) (md:col-span-5 lg:col-span-5) -->
                    <div class="md:col-span-5 lg:col-span-5">
                        <label class="block text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 mb-1.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                            <span>Corte Semestral</span>
                        </label>
                        <div class="filter-field-card bg-slate-100/90 dark:bg-slate-950/70 border border-slate-200/90 dark:border-slate-800 rounded-2xl p-1 flex items-center gap-1 shadow-inner">
                            
                            <!-- Opción 1: Todo el año -->
                            <button type="button" onclick="cambiarSemestre('')"
                                class="filter-pill-btn flex-1 py-1.5 px-2 rounded-xl text-xs font-extrabold flex items-center justify-center gap-1.5 cursor-pointer <?php echo ($filtroSemestre === '') ? 'bg-white dark:bg-gradient-to-r dark:from-purple-600 dark:to-indigo-600 text-purple-700 dark:text-white shadow-sm shadow-slate-300 dark:shadow-purple-900/30 font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-white/50 dark:hover:bg-slate-800/50'; ?>">
                                <span class="material-symbols-outlined text-sm <?php echo ($filtroSemestre === '') ? 'text-purple-600 dark:text-white' : 'text-slate-400'; ?>">all_inclusive</span>
                                <span>Todo el Año</span>
                            </button>

                            <!-- Opción 2: Semestre 1 -->
                            <button type="button" onclick="cambiarSemestre('1')"
                                class="filter-pill-btn flex-1 py-1.5 px-2 rounded-xl text-xs font-extrabold flex items-center justify-center gap-1.5 cursor-pointer <?php echo ($filtroSemestre === '1') ? 'bg-white dark:bg-gradient-to-r dark:from-purple-600 dark:to-indigo-600 text-purple-700 dark:text-white shadow-sm shadow-slate-300 dark:shadow-purple-900/30 font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-white/50 dark:hover:bg-slate-800/50'; ?>">
                                <span class="material-symbols-outlined text-sm <?php echo ($filtroSemestre === '1') ? 'text-purple-600 dark:text-white' : 'text-slate-400'; ?>">timelapse</span>
                                <span>1er Sem (Ene-Jun)</span>
                            </button>

                            <!-- Opción 3: Semestre 2 -->
                            <button type="button" onclick="cambiarSemestre('2')"
                                class="filter-pill-btn flex-1 py-1.5 px-2 rounded-xl text-xs font-extrabold flex items-center justify-center gap-1.5 cursor-pointer <?php echo ($filtroSemestre === '2') ? 'bg-white dark:bg-gradient-to-r dark:from-purple-600 dark:to-indigo-600 text-purple-700 dark:text-white shadow-sm shadow-slate-300 dark:shadow-purple-900/30 font-black' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-white/50 dark:hover:bg-slate-800/50'; ?>">
                                <span class="material-symbols-outlined text-sm <?php echo ($filtroSemestre === '2') ? 'text-purple-600 dark:text-white' : 'text-slate-400'; ?>">update</span>
                                <span>2do Sem (Jul-Dic)</span>
                            </button>
                        </div>
                    </div>

                    <!-- 3. Entidad / IPS (md:col-span-4 lg:col-span-4) -->
                    <div class="md:col-span-4 lg:col-span-4">
                        <label class="block text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 mb-1.5 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-teal-500"></span>
                            <span>Entidad / IPS Contratante</span>
                        </label>
                        <div class="filter-field-card relative bg-slate-50/90 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl p-1 flex items-center gap-2 focus-within:ring-4 focus-within:ring-teal-500/15 focus-within:border-teal-500 transition-all">
                            <div class="w-8 h-8 rounded-xl bg-teal-500/10 dark:bg-teal-500/20 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0 ml-1">
                                <span class="material-symbols-outlined text-base">domain</span>
                            </div>
                            <select name="entidad" onchange="submitFiltroConEfecto()" 
                                class="w-full bg-transparent text-xs font-bold text-slate-800 dark:text-slate-100 outline-none pr-8 py-1.5 cursor-pointer appearance-none truncate">
                                <option value="" class="dark:bg-slate-900">Todas las Entidades / IPS</option>
                                <?php foreach ($listaEntidades as $ent): ?>
                                    <option value="<?php echo htmlspecialchars($ent['id']); ?>" <?php echo ($filtroEntidad === (string)$ent['id'] || ($filtroEntidad === 'PROPIO' && $ent['is_matriz'] == 1)) ? 'selected' : ''; ?> class="dark:bg-slate-900">
                                        <?php echo htmlspecialchars($ent['nombre']) . ($ent['is_matriz'] == 1 ? ' (Sede Matriz)' : ''); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="material-symbols-outlined text-base text-slate-400 absolute right-3 pointer-events-none transition-transform">expand_more</span>
                        </div>
                    </div>

                </div>
            </form>

            <!-- Badges Dinámicos de Filtros Activos con Botón Descartar individual (×) -->
            <?php if ($numFiltrosActivos > 0): ?>
            <div class="flex flex-wrap items-center gap-2 mt-4 pt-3.5 border-t border-slate-100 dark:border-slate-800/80 text-xs">
                <span class="text-[11px] font-black text-slate-400 dark:text-slate-500 uppercase tracking-wider flex items-center gap-1 mr-1">
                    <span class="material-symbols-outlined text-sm text-tertiary">check_circle</span>
                    Filtros activos:
                </span>

                <?php if (!empty($filtroAnio)): ?>
                    <span class="active-filter-badge inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border border-blue-200/80 dark:border-blue-800/80 shadow-xs transition-all hover:scale-105">
                        <span class="material-symbols-outlined text-xs">calendar_today</span>
                        <span>Año <?php echo htmlspecialchars($filtroAnio); ?></span>
                        <button type="button" onclick="descartarFiltro('anio')" title="Eliminar filtro de año" class="w-4 h-4 rounded-full bg-blue-200/70 dark:bg-blue-800/70 hover:bg-blue-300 text-blue-800 dark:text-blue-200 flex items-center justify-center ml-1 transition-colors cursor-pointer">
                            <span class="material-symbols-outlined text-[11px] leading-none font-bold">close</span>
                        </button>
                    </span>
                <?php endif; ?>

                <?php if (!empty($filtroSemestre)): ?>
                    <span class="active-filter-badge inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 border border-purple-200/80 dark:border-purple-800/80 shadow-xs transition-all hover:scale-105">
                        <span class="material-symbols-outlined text-xs">timelapse</span>
                        <span><?php echo $filtroSemestre === '1' ? '1er Semestre (Ene - Jun)' : '2do Semestre (Jul - Dic)'; ?></span>
                        <button type="button" onclick="descartarFiltro('semestre')" title="Eliminar filtro de semestre" class="w-4 h-4 rounded-full bg-purple-200/70 dark:bg-purple-800/70 hover:bg-purple-300 text-purple-800 dark:text-purple-200 flex items-center justify-center ml-1 transition-colors cursor-pointer">
                            <span class="material-symbols-outlined text-[11px] leading-none font-bold">close</span>
                        </button>
                    </span>
                <?php endif; ?>

                <?php if (!empty($filtroEntidad)): 
                    $nomEntBadge = 'Entidad #' . $filtroEntidad;
                    foreach ($listaEntidades as $le) {
                        if ((string)$le['id'] === (string)$filtroEntidad || ($filtroEntidad === 'PROPIO' && $le['is_matriz'] == 1)) {
                            $nomEntBadge = $le['nombre'];
                            break;
                        }
                    }
                ?>
                    <span class="active-filter-badge inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 border border-teal-200/80 dark:border-teal-800/80 shadow-xs transition-all hover:scale-105">
                        <span class="material-symbols-outlined text-xs">domain</span>
                        <span class="truncate max-w-[220px]"><?php echo htmlspecialchars($nomEntBadge); ?></span>
                        <button type="button" onclick="descartarFiltro('entidad')" title="Eliminar filtro de entidad" class="w-4 h-4 rounded-full bg-teal-200/70 dark:bg-teal-800/70 hover:bg-teal-300 text-teal-800 dark:text-teal-200 flex items-center justify-center ml-1 transition-colors cursor-pointer">
                            <span class="material-symbols-outlined text-[11px] leading-none font-bold">close</span>
                        </button>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- KPI Cards Ejecutivas de Rentabilidad y Auditoría -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- Card 1: Facturación Bruta IPS -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:border-primary/40 transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Facturación Total IPS</span>
                    <span class="p-2.5 rounded-xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400">
                        <span class="material-symbols-outlined text-xl">domain</span>
                    </span>
                </div>
                <div class="mt-3">
                    <p class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-mono tracking-tight">
                        $<?php echo number_format($kpiFacturadoIPS, 0, ',', '.'); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-blue-500"></span>
                        Valor liquidado por servicios prestados
                    </p>
                </div>
            </div>

            <!-- Card 2: Honorarios Médicos Girados -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:border-amber-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Honorarios a Especialistas</span>
                    <span class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400">
                        <span class="material-symbols-outlined text-xl">payments</span>
                    </span>
                </div>
                <div class="mt-3">
                    <p class="text-2xl sm:text-3xl font-black text-amber-600 dark:text-amber-400 font-mono tracking-tight">
                        $<?php echo number_format($kpiHonorariosMedicos, 0, ',', '.'); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>
                        Desembolso neto a médicos especialistas
                    </p>
                </div>
            </div>

            <!-- Card 3: Margen Bruto de Intermediación IPS -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:border-emerald-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Margen Intermediación IPS</span>
                    <span class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400">
                        <span class="material-symbols-outlined text-xl">trending_up</span>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="flex items-baseline gap-2">
                        <p class="text-2xl sm:text-3xl font-black text-emerald-600 dark:text-emerald-400 font-mono tracking-tight">
                            $<?php echo number_format($kpiMargenBruto, 0, ',', '.'); ?>
                        </p>
                        <span class="text-xs font-black px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                            +<?php echo $kpiMargenPct; ?>%
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                        Retención operativa y rentabilidad de la IPS
                    </p>
                </div>
            </div>

            <!-- Card 4: Fuga de Ingresos / No Cruzados en Riesgo -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:border-rose-500/40 transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Fuga de Ingresos (En Riesgo)</span>
                    <span class="p-2.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400">
                        <span class="material-symbols-outlined text-xl">gpp_maybe</span>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="flex items-baseline gap-2">
                        <p class="text-2xl sm:text-3xl font-black text-rose-600 dark:text-rose-400 font-mono tracking-tight">
                            $<?php echo number_format($kpiFugaValor, 0, ',', '.'); ?>
                        </p>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-rose-500"></span>
                        <?php echo $kpiFugaCantidad; ?> estudios no cruzados o pendientes de auditar
                    </p>
                </div>
            </div>

        </div>

        <!-- Gráficos Gerenciales BI (Fila 1: MoM y Rentabilidad por Sede) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Gráfico 1: Evolución Mes a Mes (MoM) Facturación vs Pago vs Margen (8 cols) -->
            <div class="lg:col-span-8 bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col justify-between">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h2 class="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary">query_stats</span>
                            Evolución Financiera Mes a Mes (MoM)
                        </h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                            Comparativa histórica: Facturación de la IPS vs. Pago a especialistas vs. Margen operativo
                        </p>
                    </div>
                    <div class="flex items-center gap-2 text-[11px] font-bold">
                        <span class="inline-flex items-center gap-1.5 text-blue-600 dark:text-blue-400">
                            <span class="w-3 h-3 rounded-sm bg-blue-600"></span> Facturado
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                            <span class="w-3 h-3 rounded-sm bg-amber-500"></span> Pagado
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                            <span class="w-3 h-3 rounded-sm bg-emerald-500"></span> Margen
                        </span>
                    </div>
                </div>

                <div class="relative h-72 sm:h-80 w-full">
                    <canvas id="chartMoM"></canvas>
                </div>
            </div>

            <!-- Gráfico 2: Monitoreo de Fuga de Ingresos por Sede (4 cols) -->
            <div class="lg:col-span-4 bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h2 class="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-rose-500">report_problem</span>
                            Fuga por Sede
                        </h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                            Estudios no cruzados en riesgo ($)
                        </p>
                    </div>
                    <span class="p-2 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400">
                        <span class="material-symbols-outlined text-lg">warning</span>
                    </span>
                </div>

                <div class="relative h-72 sm:h-80 w-full flex items-center justify-center">
                    <canvas id="chartFugaSedes"></canvas>
                </div>
            </div>

        </div>

        <!-- Gráficos Gerenciales BI (Fila 2: Volumen por Sede y Distribución de Modalidades) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Gráfico 3: Volumen y Facturación por Sede (7 cols) -->
            <div class="lg:col-span-7 bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h2 class="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary">location_city</span>
                            Productividad por Sede IPS
                        </h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                            Total honorarios liquidados y operados en cada sede institucional
                        </p>
                    </div>
                    <span class="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-tertiary">
                        <span class="material-symbols-outlined text-lg">bar_chart</span>
                    </span>
                </div>

                <div class="relative h-72 w-full">
                    <canvas id="chartSedes"></canvas>
                </div>
            </div>

            <!-- Gráfico 4: Distribución por Especialidad / Modalidad (5 cols) -->
            <div class="lg:col-span-5 bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h2 class="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-purple-500">radiology</span>
                            Distribución por Servicio
                        </h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                            Volumen económico por concepto diagnóstico
                        </p>
                    </div>
                    <span class="p-2 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400">
                        <span class="material-symbols-outlined text-lg">donut_large</span>
                    </span>
                </div>

                <div class="relative h-72 w-full flex items-center justify-center">
                    <canvas id="chartConceptos"></canvas>
                </div>
            </div>

        </div>

        <!-- Tablas de Detalle Ejecutivo: Matriz de Sedes y Ranking de Especialistas -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Tabla 1: Matriz de Rentabilidad por Sede (7 cols) -->
            <div class="lg:col-span-7 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 p-6 shadow-xs">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-sm font-black text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-base text-tertiary">table_chart</span>
                            Matriz de Rentabilidad y Auditoría por Sede
                        </h3>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                            Desglose de estudios, valores liquidados y estado de conciliación
                        </p>
                    </div>
                    <span class="text-xs font-mono font-bold text-slate-400"><?php echo count($sedesData); ?> Sedes</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="px-3 py-2.5 rounded-l-xl">Sede IPS</th>
                                <th class="px-3 py-2.5 text-right">Estudios</th>
                                <th class="px-3 py-2.5 text-right">Liquidado</th>
                                <th class="px-3 py-2.5 text-right">No Cruzados</th>
                                <th class="px-3 py-2.5 text-right rounded-r-xl">Valor en Riesgo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                            <?php foreach ($sedesData as $sNom => $sInfo): ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="px-3 py-2.5 font-bold text-slate-800 dark:text-slate-200">
                                    <?php echo htmlspecialchars($sNom); ?>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono text-slate-600 dark:text-slate-400">
                                    <?php echo number_format($sInfo['examenes_count']); ?>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold text-primary dark:text-tertiary">
                                    $<?php echo number_format($sInfo['total_pagado'], 0, ',', '.'); ?>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono">
                                    <?php if ($sInfo['no_cruzados_count'] > 0): ?>
                                        <span class="inline-flex items-center gap-1 text-rose-600 dark:text-rose-400 font-bold">
                                            <span class="material-symbols-outlined text-xs">close</span>
                                            <?php echo $sInfo['no_cruzados_count']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-emerald-500 font-bold">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold">
                                    <?php if ($sInfo['no_cruzados_valor'] > 0): ?>
                                        <span class="text-rose-600 dark:text-rose-400">
                                            $<?php echo number_format($sInfo['no_cruzados_valor'], 0, ',', '.'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400">$0</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tabla 2: Top Productividad de Especialistas (5 cols) -->
            <div class="lg:col-span-5 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 p-6 shadow-xs">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-sm font-black text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                            <span class="material-symbols-outlined text-base text-tertiary">military_tech</span>
                            Top Especialistas por Volumen
                        </h3>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                            Honorarios liquidados y retenciones practicadas
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="px-3 py-2.5 rounded-l-xl">Especialista</th>
                                <th class="px-3 py-2.5 text-center">Turnos</th>
                                <th class="px-3 py-2.5 text-right">Facturado</th>
                                <th class="px-3 py-2.5 text-right rounded-r-xl">Neto Pagado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium">
                            <?php foreach ($medicosRanking as $med): ?>
                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="px-3 py-2.5">
                                    <span class="font-bold text-slate-800 dark:text-slate-200 block truncate max-w-[180px]">
                                        <?php echo htmlspecialchars($med['nombre']); ?>
                                    </span>
                                    <span class="text-[10px] font-mono text-slate-400">CC: <?php echo htmlspecialchars($med['cedula']); ?></span>
                                </td>
                                <td class="px-3 py-2.5 text-center font-mono font-bold text-slate-600 dark:text-slate-300">
                                    <?php echo $med['liquidaciones']; ?>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold text-blue-600 dark:text-blue-400">
                                    $<?php echo number_format($med['facturado'], 0, ',', '.'); ?>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                    $<?php echo number_format($med['pagado'], 0, ',', '.'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <!-- Footer Component -->
    <?php include __DIR__ . '/includes/footer.php'; ?>

    <!-- Configuración e Inicialización de Chart.js y Filtros BI -->
    <script>
        // Control interactivo de Semestre con transición suave
        function cambiarSemestre(sem) {
            const inp = document.getElementById('inputSemestre');
            if (inp) {
                inp.value = sem;
                submitFiltroConEfecto();
            }
        }

        // Descarte individual de filtros con 1 clic
        function descartarFiltro(tipo) {
            const form = document.getElementById('formFiltrosBI');
            if (!form) return;
            if (tipo === 'anio') {
                const el = form.querySelector('[name="anio"]');
                if (el) el.value = '';
            } else if (tipo === 'semestre') {
                const el = document.getElementById('inputSemestre');
                if (el) el.value = '';
            } else if (tipo === 'entidad') {
                const el = form.querySelector('[name="entidad"]');
                if (el) el.value = '';
            }
            submitFiltroConEfecto();
        }

        // Envío con feedback visual suavizado
        function submitFiltroConEfecto() {
            const form = document.getElementById('formFiltrosBI');
            if (form) {
                const panel = form.closest('.filter-panel-anim');
                if (panel) {
                    panel.classList.add('opacity-60', 'pointer-events-none', 'scale-[0.995]', 'transition-all', 'duration-200');
                }
                form.submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const isDark = document.documentElement.classList.contains('dark');
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? '#1e293b' : '#f1f5f9';

            // 1. Gráfico MoM (Facturado vs Pagado vs Margen)
            const momLabels   = <?php echo json_encode($chartMomLabels, JSON_UNESCAPED_UNICODE); ?>;
            const momFactura  = <?php echo json_encode($chartMomFactura); ?>;
            const momPagar    = <?php echo json_encode($chartMomPagar); ?>;
            const momMargen   = <?php echo json_encode($chartMomMargen); ?>;

            const ctxMoM = document.getElementById('chartMoM').getContext('2d');
            new Chart(ctxMoM, {
                type: 'bar',
                data: {
                    labels: momLabels,
                    datasets: [
                        {
                            label: 'Facturación IPS',
                            data: momFactura,
                            backgroundColor: '#2563eb',
                            borderRadius: 6,
                            maxBarThickness: 38
                        },
                        {
                            label: 'Pago a Especialistas',
                            data: momPagar,
                            backgroundColor: '#f59e0b',
                            borderRadius: 6,
                            maxBarThickness: 38
                        },
                        {
                            type: 'line',
                            label: 'Margen de Intermediación',
                            data: momMargen,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.15)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 6,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': $' + context.raw.toLocaleString('es-CO');
                                }
                            }
                        }
                    },
                    scales: {
                        x: { 
                            ticks: { color: textColor, font: { weight: 'bold' } }, 
                            grid: { display: false } 
                        },
                        y: { 
                            ticks: { 
                                color: textColor,
                                callback: function(value) { return '$' + (value / 1000000).toFixed(0) + 'M'; }
                            }, 
                            grid: { color: gridColor } 
                        }
                    }
                }
            });

            // 2. Gráfico Fuga de Ingresos por Sede (Doughnut)
            const sedesLabels = <?php echo json_encode($chartSedesLabels, JSON_UNESCAPED_UNICODE); ?>;
            const sedesFuga   = <?php echo json_encode($chartSedesFuga); ?>;

            const ctxFuga = document.getElementById('chartFugaSedes').getContext('2d');
            new Chart(ctxFuga, {
                type: 'doughnut',
                data: {
                    labels: sedesLabels,
                    datasets: [{
                        data: sedesFuga,
                        backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#64748b'],
                        borderWidth: 2,
                        borderColor: isDark ? '#0f172a' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: textColor, font: { size: 10, weight: 'bold' } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': $' + context.raw.toLocaleString('es-CO');
                                }
                            }
                        }
                    }
                }
            });

            // 3. Gráfico Productividad por Sede (Barras Horizontales)
            const sedesValores = <?php echo json_encode($chartSedesValores); ?>;
            const ctxSedes = document.getElementById('chartSedes').getContext('2d');
            new Chart(ctxSedes, {
                type: 'bar',
                data: {
                    labels: sedesLabels,
                    datasets: [{
                        label: 'Liquidado en Sede ($)',
                        data: sedesValores,
                        backgroundColor: '#008080',
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Total: $' + context.raw.toLocaleString('es-CO');
                                }
                            }
                        }
                    },
                    scales: {
                        x: { 
                            ticks: { 
                                color: textColor,
                                callback: function(value) { return '$' + (value / 1000000).toFixed(0) + 'M'; }
                            }, 
                            grid: { color: gridColor } 
                        },
                        y: { 
                            ticks: { color: textColor, font: { size: 11, weight: 'bold' } }, 
                            grid: { display: false } 
                        }
                    }
                }
            });

            // 4. Gráfico Distribución por Concepto (Polar Area / Doughnut)
            const concLabels  = <?php echo json_encode($chartConceptosLabels, JSON_UNESCAPED_UNICODE); ?>;
            const concValores = <?php echo json_encode($chartConceptosValores); ?>;
            const ctxConc = document.getElementById('chartConceptos').getContext('2d');
            new Chart(ctxConc, {
                type: 'pie',
                data: {
                    labels: concLabels,
                    datasets: [{
                        data: concValores,
                        backgroundColor: ['#003366', '#008080', '#10b981', '#f59e0b', '#8b5cf6'],
                        borderWidth: 2,
                        borderColor: isDark ? '#0f172a' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: textColor, font: { size: 11, weight: 'bold' } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': $' + context.raw.toLocaleString('es-CO');
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
