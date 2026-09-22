<?php
/**
 * Módulo de Certificados Tributarios Anuales de Ingresos y Retenciones
 * Cumplimiento Art. 381 y 383 del Estatuto Tributario Nacional de Colombia
 * Sistema de Liquidación de Honorarios Médicos - LIHO
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/conexion.php';
$con = obtenerConexionLIHO();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId     = $_SESSION['user_id'] ?? null;
$userRole   = strtoupper($_SESSION['user_role'] ?? 'SIN ROL');
$userRoleId = intval($_SESSION['user_role_id'] ?? 0);
$userName   = $_SESSION['user_name'] ?? 'Usuario';

// Obtener datos del usuario logueado
$userCedula = '';
$userEmail  = '';
if ($userId && $con) {
    $stmtU = sqlsrv_query($con, "SELECT u.id, u.nombre_completo, u.email, u.cedula, u.rol_id, r.nombre AS rol_nombre 
                                FROM usuarios u 
                                LEFT JOIN roles r ON u.rol_id = r.id 
                                WHERE u.id = ?", array($userId));
    if ($stmtU && $rowU = sqlsrv_fetch_array($stmtU, SQLSRV_FETCH_ASSOC)) {
        $userCedula = trim((string)($rowU['cedula'] ?? ''));
        $userEmail  = trim((string)($rowU['email'] ?? ''));
        $userRoleId = intval($rowU['rol_id'] ?? 0);
        $rolNom     = strtoupper(trim((string)($rowU['rol_nombre'] ?? '')));
        if ($userRoleId === 1 || $rolNom === 'ADMIN' || $rolNom === 'ADMINISTRADOR') {
            $userRole = 'ADMINISTRADOR';
        } elseif ($userRoleId === 2 || $rolNom === 'FINANCIERO' || $rolNom === 'AUXILIAR') {
            $userRole = 'FINANCIERO';
        } elseif ($userRoleId === 3 || $rolNom === 'MEDICO' || $rolNom === 'MÉDICO') {
            $userRole = 'MÉDICO';
        }
    }
}

$isMedico = ($userRole === 'MÉDICO' || $userRoleId === 3);

// Parámetros de consulta
$currentYear = intval(date('Y'));
$selectedAnio = isset($_GET['anio']) ? intval($_GET['anio']) : $currentYear;
if ($selectedAnio < 2020 || $selectedAnio > 2035) {
    $selectedAnio = $currentYear;
}

// Entidad Retenedora seleccionada
$selectedEntidadId = isset($_GET['entidad_id']) ? intval($_GET['entidad_id']) : 0;

// Cargar Entidad Matriz por defecto o la seleccionada
$entidadInfo = null;
if ($con) {
    if ($selectedEntidadId > 0) {
        $stmtEnt = sqlsrv_query($con, "SELECT TOP 1 id, nombre, nit, dv, direccion, ciudad, telefono, email, is_matriz, logo FROM maestro_entidades WHERE id = ?", array($selectedEntidadId));
        if ($stmtEnt && $rEnt = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
            $entidadInfo = $rEnt;
        }
    }
    if (!$entidadInfo) {
        $stmtEnt = sqlsrv_query($con, "SELECT TOP 1 id, nombre, nit, dv, direccion, ciudad, telefono, email, is_matriz, logo FROM maestro_entidades WHERE is_matriz = 1");
        if ($stmtEnt && $rEnt = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
            $entidadInfo = $rEnt;
            $selectedEntidadId = intval($rEnt['id']);
        }
    }
}

// Valores por defecto institucionales si no hay registro
if (!$entidadInfo) {
    $selectedEntidadId = 4;
    $entidadInfo = [
        'id' => 4,
        'nombre' => 'HERNÁN OCAZIONEZ Y CÍA S.A.S.',
        'nit' => '800149695',
        'dv' => '1',
        'direccion' => 'Calle 50 # 50-50',
        'ciudad' => 'Medellín',
        'telefono' => '6044445566',
        'email' => 'desarrollo@hernanocazionez.com',
        'logo' => 'assets/img/Logo original.png',
        'is_matriz' => 1
    ];
} else {
    $selectedEntidadId = intval($entidadInfo['id']);
}

$isMatriz = !empty($entidadInfo['is_matriz']);

// Logo institucional oficial para fondos oscuros (idéntico al reporte de liquidaciones)
$headerLogoPath = 'assets/img/Ho_Fondo_Osc.png';
if (!file_exists(__DIR__ . '/' . $headerLogoPath)) {
    $headerLogoPath = 'assets/img/Logo original.png';
}

// Cargar lista de entidades para filtro (solo Admin y Financiero)
$listaEntidades = [];
if ($con && !$isMedico) {
    $stmtAllEnt = sqlsrv_query($con, "SELECT id, nombre, nit, dv, is_matriz FROM maestro_entidades WHERE estado = 1 ORDER BY is_matriz DESC, nombre ASC");
    if ($stmtAllEnt) {
        while ($re = sqlsrv_fetch_array($stmtAllEnt, SQLSRV_FETCH_ASSOC)) {
            $listaEntidades[] = $re;
        }
    }
}

// Cargar lista de médicos correspondientes a la entidad seleccionada (Admin/Financiero)
$listaMedicos = [];
$medicosMap   = [];

if ($con && !$isMedico) {
    // 1. Médicos registrados en la tabla medicos asociados a esta entidad
    $sqlMeds = "SELECT m.cedula, m.usuario_proteo, m.pnom, m.snom, m.pape, m.sape, u.nombre_completo, u.email, m.entidad_id
                FROM medicos m 
                LEFT JOIN usuarios u ON m.usuario_id = u.id 
                WHERE (m.entidad_id = ? OR u.entidad_id = ?" . ($isMatriz ? " OR m.entidad_id IS NULL OR m.entidad_id = 0" : "") . ")";
    $paramsMeds = array($selectedEntidadId, $selectedEntidadId);
    $stmtMeds = sqlsrv_query($con, $sqlMeds, $paramsMeds);
    if ($stmtMeds) {
        while ($rm = sqlsrv_fetch_array($stmtMeds, SQLSRV_FETCH_ASSOC)) {
            $ced = trim((string)($rm['cedula'] ?? ''));
            if (empty($ced)) continue;
            $cleanCed = ltrim($ced, 'C');
            
            $nombre = trim((string)($rm['nombre_completo'] ?? ''));
            if (empty($nombre)) {
                $nombre = trim((string)($rm['pnom'] ?? '') . ' ' . (string)($rm['snom'] ?? '') . ' ' . (string)($rm['pape'] ?? '') . ' ' . (string)($rm['sape'] ?? ''));
            }
            if (empty($nombre)) {
                $nombre = 'MÉDICO ESPECIALISTA';
            }
            
            $medicosMap[$cleanCed] = [
                'medico_cedula' => $cleanCed,
                'medico_nombre' => mb_strtoupper($nombre, 'UTF-8'),
                'usuario_proteo' => trim((string)($rm['usuario_proteo'] ?? ('C' . $cleanCed))),
                'email' => trim((string)($rm['email'] ?? ''))
            ];
        }
    }
    
    // 2. Complementar con médicos que tengan liquidaciones registradas bajo esta entidad
    if ($isMatriz) {
        $sqlLiqMeds = "SELECT DISTINCT medico_cedula, medico_nombre 
                       FROM liquidaciones_turnos 
                       WHERE (entidad_id = ? OR entidad_id = 'PROPIO' OR entidad_id IS NULL OR entidad_id = '') 
                         AND medico_cedula IS NOT NULL AND medico_cedula != '' AND medico_cedula != 'GLOBAL'";
        $paramsLiqMeds = array((string)$selectedEntidadId);
    } else {
        $sqlLiqMeds = "SELECT DISTINCT medico_cedula, medico_nombre 
                       FROM liquidaciones_turnos 
                       WHERE entidad_id = ? 
                         AND medico_cedula IS NOT NULL AND medico_cedula != '' AND medico_cedula != 'GLOBAL'";
        $paramsLiqMeds = array((string)$selectedEntidadId);
    }
    
    $stmtLiqMeds = sqlsrv_query($con, $sqlLiqMeds, $paramsLiqMeds);
    if ($stmtLiqMeds) {
        while ($rlm = sqlsrv_fetch_array($stmtLiqMeds, SQLSRV_FETCH_ASSOC)) {
            $rawCed = trim((string)($rlm['medico_cedula'] ?? ''));
            if (empty($rawCed) || $rawCed === 'GLOBAL') continue;
            $cleanCed = ltrim($rawCed, 'C');
            
            if (!isset($medicosMap[$cleanCed])) {
                $medicosMap[$cleanCed] = [
                    'medico_cedula' => $cleanCed,
                    'medico_nombre' => mb_strtoupper(trim((string)($rlm['medico_nombre'] ?? 'MÉDICO ESPECIALISTA')), 'UTF-8'),
                    'usuario_proteo' => $rawCed,
                    'email' => ''
                ];
            }
        }
    }
    
    // Ordenar alfabéticamente por nombre del especialista
    $listaMedicos = array_values($medicosMap);
    usort($listaMedicos, function($a, $b) {
        return strcmp($a['medico_nombre'], $b['medico_nombre']);
    });
}

// Determinar médico seleccionado
if ($isMedico) {
    $selectedCedula = $userCedula;
} else {
    $requestedCedula = isset($_GET['medico_cedula']) ? trim($_GET['medico_cedula']) : '';
    $cleanRequestedCed = ltrim($requestedCedula, 'C');
    
    // Verificar si el médico solicitado pertenece a la lista disponible de esta entidad
    $selectedCedula = '';
    if (!empty($requestedCedula) && !empty($listaMedicos)) {
        foreach ($listaMedicos as $m) {
            if ($cleanRequestedCed === ltrim($m['medico_cedula'], 'C') || $requestedCedula === $m['usuario_proteo']) {
                $selectedCedula = $m['medico_cedula'];
                break;
            }
        }
    }
    
    // Si no fue solicitado o no pertenece a esta entidad, seleccionar el primero de la lista
    if (empty($selectedCedula) && !empty($listaMedicos)) {
        // En matriz, si existe Alberto con liquidaciones, preferirlo para consistencia visual
        $arangoIdx = null;
        foreach ($listaMedicos as $idx => $m) {
            if (strpos($m['medico_nombre'], 'ARANGO') !== false) {
                $arangoIdx = $idx;
                break;
            }
        }
        if ($isMatriz && $arangoIdx !== null) {
            $selectedCedula = $listaMedicos[$arangoIdx]['medico_cedula'];
        } else {
            $selectedCedula = $listaMedicos[0]['medico_cedula'];
        }
    }
}

// Cargar datos detallados del médico seleccionado
$medicoDatos = null;
if (!empty($selectedCedula) && $con) {
    $cleanCed = ltrim($selectedCedula, 'C');
    $cedConC  = 'C' . $cleanCed;
    
    // 1. Buscar en medicos y usuarios
    $stmtUserDet = sqlsrv_query($con, "SELECT u.email, u.nombre_completo, m.pnom, m.snom, m.pape, m.sape, m.usuario_proteo, m.cedula 
                                       FROM medicos m 
                                       LEFT JOIN usuarios u ON m.usuario_id = u.id 
                                       WHERE m.cedula = ? OR m.usuario_proteo = ? OR u.cedula = ?", 
                               array($cleanCed, $cedConC, $cleanCed));
    if ($stmtUserDet && $rDet = sqlsrv_fetch_array($stmtUserDet, SQLSRV_FETCH_ASSOC)) {
        $nomCalc = trim($rDet['nombre_completo'] ?? '');
        if (empty($nomCalc)) {
            $nomCalc = trim(($rDet['pnom'] ?? '') . ' ' . ($rDet['snom'] ?? '') . ' ' . ($rDet['pape'] ?? '') . ' ' . ($rDet['sape'] ?? ''));
        }
        $medicoDatos = [
            'cedula' => $rDet['cedula'] ?: $cleanCed,
            'nombre' => $nomCalc ?: 'Médico Especialista',
            'email' => $rDet['email'] ?? '',
            'usuario_proteo' => $rDet['usuario_proteo'] ?? $cedConC
        ];
    }
    
    // 2. Fallback a liquidaciones_turnos si no se halló en medicos
    if (!$medicoDatos) {
        $stmtUltimaLiq = sqlsrv_query($con, "SELECT TOP 1 medico_cedula, medico_nombre FROM liquidaciones_turnos WHERE medico_cedula = ? OR medico_cedula = ? ORDER BY id DESC", array($selectedCedula, $cedConC));
        if ($stmtUltimaLiq && $rUlt = sqlsrv_fetch_array($stmtUltimaLiq, SQLSRV_FETCH_ASSOC)) {
            $medicoDatos = [
                'cedula' => $cleanCed,
                'nombre' => $rUlt['medico_nombre'],
                'email' => '',
                'usuario_proteo' => $rUlt['medico_cedula']
            ];
        }
    }
}

// Cargar liquidaciones del año gravable seleccionado para el médico y la entidad
$liquidaciones = [];
$totales = [
    'bruto'        => 0.0,
    'rete_fuente'  => 0.0,
    'rete_383'     => 0.0,
    'total_rete'   => 0.0,
    'ibc'          => 0.0,
    'salud'        => 0.0,
    'pension'      => 0.0,
    'arl'          => 0.0,
    'afc'          => 0.0,
    'solidaridad'  => 0.0,
    'total_ded'    => 0.0,
    'neto_pagado'  => 0.0,
    'cantidad_liq' => 0
];

$fechaMinima = null;
$fechaMaxima = null;

if (!empty($selectedCedula) && $con) {
    $cleanCed = ltrim($selectedCedula, 'C');
    $cedConC  = 'C' . $cleanCed;
    
    // Filtro por año en periodo_desde o en fecha_creacion, y filtrado por entidad
    $sqlLiq = "SELECT id, periodo_desde, periodo_hasta, medico_cedula, medico_nombre,
                      total_factura, ded_retencion, ded_rete_383, ded_ibc, ded_salud, 
                      ded_pension, ded_arl, ded_afc, ded_solidaridad, total_deducciones, 
                      total_a_pagar, estado, fecha_creacion, fecha_aprobacion, hash_integridad, entidad_nombre, entidad_id
               FROM liquidaciones_turnos 
               WHERE (medico_cedula = ? OR medico_cedula = ? OR REPLACE(medico_cedula, 'C', '') = ?) 
                 AND (LEFT(periodo_desde, 4) = ? OR YEAR(fecha_creacion) = ?)
                 AND estado = 'APROBADA'";
    
    $paramsLiq = array($selectedCedula, $cedConC, $cleanCed, (string)$selectedAnio, $selectedAnio);
    
    if ($isMatriz) {
        $sqlLiq .= " AND (entidad_id = ? OR entidad_id = 'PROPIO' OR entidad_id IS NULL OR entidad_id = '')";
        $paramsLiq[] = (string)$selectedEntidadId;
    } else {
        $sqlLiq .= " AND (entidad_id = ?)";
        $paramsLiq[] = (string)$selectedEntidadId;
    }
    
    $sqlLiq .= " ORDER BY periodo_desde ASC, id ASC";
    
    $stmtLiq = sqlsrv_query($con, $sqlLiq, $paramsLiq);
    
    if ($stmtLiq) {
        while ($rowL = sqlsrv_fetch_array($stmtLiq, SQLSRV_FETCH_ASSOC)) {
            $liquidaciones[] = $rowL;
            
            $bruto   = (float)($rowL['total_factura'] ?? 0);
            $rf      = (float)($rowL['ded_retencion'] ?? 0);
            $r383    = (float)($rowL['ded_rete_383'] ?? 0);
            $ibc     = (float)($rowL['ded_ibc'] ?? 0);
            $salud   = (float)($rowL['ded_salud'] ?? 0);
            $pension = (float)($rowL['ded_pension'] ?? 0);
            $arl     = (float)($rowL['ded_arl'] ?? 0);
            $afc     = (float)($rowL['ded_afc'] ?? 0);
            $sol     = (float)($rowL['ded_solidaridad'] ?? 0);
            $dedTot  = (float)($rowL['total_deducciones'] ?? 0);
            $neto    = (float)($rowL['total_a_pagar'] ?? 0);
            
            $totales['bruto']       += $bruto;
            $totales['rete_fuente'] += $rf;
            $totales['rete_383']    += $r383;
            $totales['total_rete']  += ($rf + $r383);
            $totales['ibc']         += $ibc;
            $totales['salud']       += $salud;
            $totales['pension']     += $pension;
            $totales['arl']         += $arl;
            $totales['afc']         += $afc;
            $totales['solidaridad'] += $sol;
            $totales['total_ded']   += $dedTot;
            $totales['neto_pagado'] += $neto;
            $totales['cantidad_liq']++;

            // Detección de fechas mínimas y máximas de turnos
            $fD = trim((string)($rowL['periodo_desde'] ?? ''));
            $fH = trim((string)($rowL['periodo_hasta'] ?? ''));
            if (!empty($fD)) {
                if ($fechaMinima === null || $fD < $fechaMinima) $fechaMinima = $fD;
            }
            if (!empty($fH)) {
                if ($fechaMaxima === null || $fH > $fechaMaxima) $fechaMaxima = $fH;
            }
        }
    }
}

// Cálculo de porcentajes para conceptos fiscales y deducciones
$brutoVal   = floatval($totales['bruto'] ?? 0);
$ibcVal     = floatval($totales['ibc'] ?? 0);
$saludVal   = floatval($totales['salud'] ?? 0);
$pensionVal = floatval(($totales['pension'] ?? 0) + ($totales['solidaridad'] ?? 0));
$arlVal     = floatval($totales['arl'] ?? 0);
$afcVal     = floatval($totales['afc'] ?? 0);
$rete383Val = floatval($totales['rete_383'] ?? 0);
$reteFteVal = floatval($totales['rete_fuente'] ?? 0);
$totReteVal = floatval($totales['total_rete'] ?? 0);
$netoVal    = floatval($totales['neto_pagado'] ?? 0);

$pctIbcCol        = ($ibcVal > 0) ? '40.0%' : '';
$pctSaludCol      = ($saludVal > 0) ? '12.5%' : '';
$pctPensionCol    = ($pensionVal > 0) ? '16.0%' : '';
$pctArlCol        = ($arlVal > 0) ? '2.436%' : '';
$pctAfcCol        = ($afcVal > 0 && $brutoVal > 0) ? number_format(($afcVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
$pctRete383Col    = ''; // Se retira para Art. 383 E.T. ya que aplica tabla progresiva en UVT, no una tarifa porcentual fija
$pctReteFuenteCol = ($reteFteVal > 0 && $brutoVal > 0) ? number_format(($reteFteVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
$pctTotReteCol    = ($totReteVal > 0 && $brutoVal > 0) ? number_format(($totReteVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
$pctNetoCol       = ($netoVal > 0 && $brutoVal > 0) ? number_format(($netoVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
$pctTotReteStr    = ($totReteVal > 0 && $brutoVal > 0) ? ' (' . $pctTotReteCol . ')' : '';
$pctNetoStr       = ($netoVal > 0 && $brutoVal > 0) ? ' (' . $pctNetoCol . ')' : '';

// Formateo de fechas para despliegue amigable
$mesesEsCompleto = [
    '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
    '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
    '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
];

if (!function_exists('formatFechaEspanol')) {
    function formatFechaEspanol($fStr, $mesesArr) {
        if (empty($fStr) || strlen($fStr) < 10) return $fStr;
        $p = explode('-', $fStr);
        if (count($p) === 3) {
            $mes = $mesesArr[$p[1]] ?? $p[1];
            return (int)$p[2] . ' de ' . $mes . ' de ' . $p[0];
        }
        return $fStr;
    }
}

if (!function_exists('formatFechaCorta')) {
    function formatFechaCorta($fStr) {
        if (empty($fStr) || strlen($fStr) < 10) return $fStr;
        $p = explode('-', $fStr);
        if (count($p) === 3) {
            return $p[2] . '/' . $p[1] . '/' . $p[0];
        }
        return $fStr;
    }
}

// Vigencia Fiscal Oficial (Estatuto Tributario Nacional)
$textoVigenciaFiscalCorta = "01/01/{$selectedAnio} al 31/12/{$selectedAnio}";
$textoVigenciaFiscalLarga = "01 de Enero de {$selectedAnio} al 31 de Diciembre de {$selectedAnio}";

// Exportación a Excel / CSV si se solicita
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($liquidaciones)) {
    $filename = "Certificado_Tributario_" . preg_replace('/[^A-Za-z0-9]/', '', $selectedCedula) . "_{$selectedAnio}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // BOM UTF-8 para apertura correcta en Microsoft Excel
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    
    // Encabezado institucional
    fputcsv($fp, [mb_strtoupper($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.', 'UTF-8')], ';');
    fputcsv($fp, ['NIT: ' . ($entidadInfo['nit'] ?? '800149695') . (!empty($entidadInfo['dv']) ? '-' . $entidadInfo['dv'] : '-1')], ';');
    fputcsv($fp, ['CERTIFICADO DE INGRESOS Y RETENCIONES - ART. 381 Y 383 E.T.'], ';');
    fputcsv($fp, ['AÑO GRAVABLE: ' . $selectedAnio], ';');
    fputcsv($fp, ['VIGENCIA FISCAL: ' . $textoVigenciaFiscalCorta], ';');
    fputcsv($fp, ['ESPECIALISTA: ' . ($medicoDatos['nombre'] ?? 'N/A') . ' - CC: ' . ($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C'))], ';');
    fputcsv($fp, ['FECHA EXPEDICION: ' . date('d/m/Y H:i:s')], ';');
    fputcsv($fp, [], ';');
    
    // Encabezados de columnas
    fputcsv($fp, [
        'ID Liquidacion',
        'Periodo Desde',
        'Periodo Hasta',
        'Honorarios Brutos (COP)',
        'ReteFuente Tradicional (COP)',
        'ReteFuente Art. 383 (COP)',
        'Total Retenciones (COP)',
        'IBC Calculado (COP)',
        'Aporte Salud (COP)',
        'Aporte Pension (COP)',
        'Aporte ARL (COP)',
        'Ahorro AFC (COP)',
        'Fondo Solidaridad (COP)',
        'Total Deducciones (COP)',
        'Neto Pagado (COP)',
        'Estado',
        'Hash Integridad'
    ], ';');
    
    foreach ($liquidaciones as $l) {
        fputcsv($fp, [
            $l['id'],
            $l['periodo_desde'],
            $l['periodo_hasta'],
            number_format((float)$l['total_factura'], 2, '.', ''),
            number_format((float)$l['ded_retencion'], 2, '.', ''),
            number_format((float)$l['ded_rete_383'], 2, '.', ''),
            number_format((float)$l['ded_retencion'] + (float)$l['ded_rete_383'], 2, '.', ''),
            number_format((float)$l['ded_ibc'], 2, '.', ''),
            number_format((float)$l['ded_salud'], 2, '.', ''),
            number_format((float)$l['ded_pension'], 2, '.', ''),
            number_format((float)$l['ded_arl'], 2, '.', ''),
            number_format((float)$l['ded_afc'], 2, '.', ''),
            number_format((float)$l['ded_solidaridad'], 2, '.', ''),
            number_format((float)$l['total_deducciones'], 2, '.', ''),
            number_format((float)$l['total_a_pagar'], 2, '.', ''),
            $l['estado'],
            $l['hash_integridad'] ?? 'N/A'
        ], ';');
    }
    
    // Fila de totales
    fputcsv($fp, [
        'TOTALES ANUALES',
        '',
        '',
        number_format($totales['bruto'], 2, '.', ''),
        number_format($totales['rete_fuente'], 2, '.', ''),
        number_format($totales['rete_383'], 2, '.', ''),
        number_format($totales['total_rete'], 2, '.', ''),
        number_format($totales['ibc'], 2, '.', ''),
        number_format($totales['salud'], 2, '.', ''),
        number_format($totales['pension'], 2, '.', ''),
        number_format($totales['arl'], 2, '.', ''),
        number_format($totales['afc'], 2, '.', ''),
        number_format($totales['solidaridad'], 2, '.', ''),
        number_format($totales['total_ded'], 2, '.', ''),
        number_format($totales['neto_pagado'], 2, '.', ''),
        $totales['cantidad_liq'] . ' liquidaciones',
        ''
    ], ';');
    
    fclose($fp);

    require_once __DIR__ . '/includes/logger_helper.php';
    if (function_exists('registrar_log_sistema')) {
        registrar_log_sistema(
            'CERTIFICADOS_TRIBUTARIOS',
            'EXPORTAR_CSV_CERTIFICADO',
            'EXPORTACION',
            "Exportación de Anexo CSV de Certificado Tributario {$selectedAnio} para Dr(a). " . ($medicoDatos['nombre'] ?? 'N/A') . " (CC: {$selectedCedula}). Total Bruto: $" . number_format($totales['bruto'], 2, ',', '.') . " | Total Liquidaciones: " . count($liquidaciones) . ".",
            [
                'entidad_afectada' => $entidadInfo['nombre'] ?? 'LIHO',
                'nivel'            => 'INFO'
            ]
        );
    }

    exit;
}

// Generación de Hash Institucional de Verificación Único para este Certificado
$hashCadena = ($entidadInfo['nit'] ?? '800149695') . '|' . $selectedCedula . '|' . $selectedAnio . '|' . $totales['bruto'] . '|' . $totales['total_rete'] . '|LIHO_DIAN_CERT';
$certificadoHash = strtoupper(hash('sha256', $hashCadena));
$certificadoCodigo = 'CERT-' . $selectedAnio . '-' . substr($certificadoHash, 0, 8) . '-' . substr($selectedCedula, -4);

// 1. Descarga o Previsualización Directa de PDF Oficial si se solicita (?export=pdf)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/includes/pdf_certificados.php';
    $datosCert = [
        'selectedAnio' => $selectedAnio,
        'textoVigenciaFiscalLarga' => $textoVigenciaFiscalLarga,
        'entidadInfo' => $entidadInfo,
        'medicoDatos' => $medicoDatos,
        'totales' => $totales,
        'liquidaciones' => $liquidaciones,
        'certificadoHash' => $certificadoHash,
        'certificadoCodigo' => $certificadoCodigo
    ];
    $pdfBinary = generarPDFCertificadoTributario($datosCert);
    $safeCed = preg_replace('/[^A-Za-z0-9]/', '', $selectedCedula);
    $filename = "Certificado_Tributario_{$selectedAnio}_{$safeCed}.pdf";

    require_once __DIR__ . '/includes/logger_helper.php';
    if (function_exists('registrar_log_sistema')) {
        registrar_log_sistema(
            'CERTIFICADOS_TRIBUTARIOS',
            'DESCARGA_PDF_CERTIFICADO',
            'EXPORTACION',
            "Descarga / Visualización de Certificado Tributario Oficial {$selectedAnio} en PDF para Dr(a). " . ($medicoDatos['nombre'] ?? 'N/A') . " (CC: {$selectedCedula}) | Hash: {$certificadoHash}",
            [
                'entidad_afectada' => $entidadInfo['nombre'] ?? 'LIHO',
                'valor_nuevo'      => $certificadoHash,
                'nivel'            => 'INFO'
            ]
        );
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdfBinary;
    exit;
}

// 2. Procesamiento AJAX de Envío de Certificado por Correo al Médico
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enviar_correo') {
    header('Content-Type: application/json; charset=UTF-8');
    
    $correoDestino = strtolower(trim((string)($_POST['correo_destino'] ?? '')));
    $asunto = trim((string)($_POST['asunto'] ?? ''));

    if (empty($correoDestino) || !filter_var($correoDestino, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'La dirección de correo electrónico del médico no es válida o se encuentra vacía.'
        ]);
        exit;
    }

    if (empty($asunto)) {
        $asunto = "[LIHO] Certificado de Ingresos y Retenciones {$selectedAnio} - " . ($medicoDatos['nombre'] ?? 'Médico Especialista');
    }

    require_once __DIR__ . '/includes/pdf_certificados.php';
    require_once __DIR__ . '/includes/smtp_mailer.php';

    $datosCert = [
        'selectedAnio' => $selectedAnio,
        'textoVigenciaFiscalLarga' => $textoVigenciaFiscalLarga,
        'entidadInfo' => $entidadInfo,
        'medicoDatos' => $medicoDatos,
        'totales' => $totales,
        'liquidaciones' => $liquidaciones,
        'certificadoHash' => $certificadoHash,
        'certificadoCodigo' => $certificadoCodigo
    ];

    try {
        $pdfBinary = generarPDFCertificadoTributario($datosCert);
        $safeCed = preg_replace('/[^A-Za-z0-9]/', '', $selectedCedula);
        $nombrePdf = "Certificado_Tributario_{$selectedAnio}_{$safeCed}.pdf";

        $attachments = [
            [
                'name' => $nombrePdf,
                'data' => $pdfBinary,
                'type' => 'application/pdf'
            ]
        ];

        // Copias de Auditoría y Control Financiero
        $copiasCC = ['coordinacionsistemas@hernanocazionez.com.co', 'juane6462@gmail.com'];

        // Logotipo institucional embebido
        $logoPath = __DIR__ . '/assets/img/Ho_Fondo_Osc.png';
        if (!file_exists($logoPath)) $logoPath = __DIR__ . '/assets/img/logo_email_optimized.png';
        $embeddedImages = file_exists($logoPath) ? ['logo_liho' => $logoPath] : [];

        $nombreMed = htmlspecialchars($medicoDatos['nombre'] ?? 'Médico Especialista');
        $cedMed = htmlspecialchars($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C'));
        $entidadNom = htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.');
        $brutoFmt = number_format($totales['bruto'], 2, ',', '.');
        $reteFmt = number_format($totales['total_rete'], 2, ',', '.');
        $segSocFmt = number_format($totales['salud'] + $totales['pension'] + $totales['arl'], 2, ',', '.');
        $netoFmt = number_format($totales['neto_pagado'], 2, ',', '.');
        $cantTurnos = $totales['cantidad_liq'];

        $logoImgHtml = !empty($embeddedImages) 
            ? '<img src="cid:logo_liho" alt="Hernán Ocazionez" style="max-height: 44px; margin: 0 auto 10px auto; display: block;" />' 
            : '';

        $bodyHtml = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars($asunto) . '</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 16px 12px; color: #1e293b; }
                .wrapper { width: 100%; max-width: 620px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.07); border: 1px solid #e2e8f0; }
                .header { background: #0f172a; padding: 26px 20px; text-align: center; color: #ffffff; }
                .header h3 { margin: 0; font-size: 15px; font-weight: 900; letter-spacing: 0.5px; color: #ffffff; text-transform: uppercase; }
                .header p { margin: 4px 0 0 0; font-size: 11px; font-weight: 800; color: #00c1be; text-transform: uppercase; letter-spacing: 0.8px; }
                .content { padding: 24px 20px; }
                .badge-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
                .badge-title { font-size: 13px; font-weight: 900; color: #0f172a; }
                .badge-year { background-color: #0d9488; color: #ffffff; padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 900; }
                .card-summary { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; margin: 16px 0; }
                .card-summary table { width: 100%; font-size: 12px; border-collapse: collapse; }
                .card-summary td { padding: 6px 0; }
                .row-total { background-color: #f0fdfa; font-weight: 900; color: #0d9488; }
                .box-att { background-color: #ffffff; border: 1.5px dashed #0d9488; border-radius: 12px; padding: 12px 14px; margin: 16px 0; }
                .box-hash { background-color: #0f172a; border-radius: 10px; padding: 10px 12px; margin: 16px 0; color: #ffffff; }
                .hash-title { font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
                .hash-code { font-family: monospace; font-size: 9px; color: #38bdf8; word-break: break-all; margin-top: 4px; line-height: 1.3; }
                .footer { background-color: #f1f5f9; padding: 16px 20px; text-align: center; font-size: 10px; color: #64748b; border-top: 1px solid #e2e8f0; line-height: 1.5; }
            </style>
        </head>
        <body>
            <div class="wrapper">
                <div class="header">
                    ' . $logoImgHtml . '
                    <h3>' . $entidadNom . '</h3>
                    <p>LIHO - SISTEMA DE GESTIÓN TRIBUTARIA</p>
                </div>
                <div class="content">
                    <div class="badge-bar">
                        <span class="badge-title">Certificado Tributario de Ingresos y Retenciones</span>
                        <span class="badge-year">AÑO GRAVABLE ' . $selectedAnio . '</span>
                    </div>

                    <p style="font-size: 13px; margin: 0 0 10px 0; color: #0f172a; line-height: 1.4;">
                        Estimado(a) <strong>Dr(a). ' . $nombreMed . '</strong>,
                    </p>
                    <p style="font-size: 12px; color: #475569; line-height: 1.5; margin: 0 0 16px 0;">
                        La Dirección Financiera y Contabilidad de <strong>' . $entidadNom . '</strong> hace entrega formal de su <strong>Certificado Oficial de Ingresos y Retenciones</strong> para el año gravable <strong>' . $selectedAnio . '</strong>, expedido en cumplimiento del <strong>Artículo 381 y 383 del Estatuto Tributario Nacional</strong>.
                    </p>

                    <div class="card-summary">
                        <table>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="color: #64748b;">Ingresos Brutos por Honorarios:</td>
                                <td style="text-align: right; font-weight: 900; font-family: monospace; color: #0f172a;">$' . $brutoFmt . '</td>
                            </tr>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="color: #64748b;">Total Retención en la Fuente Practicada' . $pctTotReteStr . ':</td>
                                <td style="text-align: right; font-weight: 900; font-family: monospace; color: #b91c1c;">$' . $reteFmt . '</td>
                            </tr>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="color: #64748b;">Aportes a Seguridad Social (Salud, Pensión, ARL):</td>
                                <td style="text-align: right; font-weight: 700; font-family: monospace; color: #475569;">$' . $segSocFmt . '</td>
                            </tr>
                            <tr class="row-total">
                                <td style="padding: 8px 6px;">Total Neto Girado / Pagado' . $pctNetoStr . ':</td>
                                <td style="padding: 8px 6px; text-align: right; font-family: monospace; font-size: 13px;">$' . $netoFmt . '</td>
                            </tr>
                        </table>
                    </div>

                    <!-- Clave de Apertura de Seguridad y Adjunto -->
                    <div style="background-color: #f0fdf4; border: 1.5px solid #22c55e; border-radius: 12px; padding: 12px 14px; margin: 16px 0;">
                        <span style="font-size: 11px; font-weight: 800; color: #15803d; text-transform: uppercase; display: inline-flex; align-items: center;">
                            <svg style="width: 14px; height: 14px; fill: #15803d; margin-right: 6px; vertical-align: -2px;" viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                            DOCUMENTO PROTEGIDO CON CONTRASEÑA:
                        </span>
                        <p style="margin: 4px 0 0 0; font-size: 11.5px; color: #166534; line-height: 1.4;">
                            Por su estricta seguridad y confidencialidad fiscal, el archivo PDF oficial adjunto se encuentra cifrado. <strong>Su contraseña de apertura es su número de documento de identidad</strong> (sin puntos, guiones ni espacios).
                        </p>
                    </div>

                    <div class="box-att">
                        <span style="font-size: 11px; font-weight: 800; color: #0d9488; text-transform: uppercase; display: inline-flex; align-items: center;">
                            <svg style="width: 14px; height: 14px; fill: #0d9488; margin-right: 6px; vertical-align: -2px;" viewBox="0 0 24 24"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5c0 1.38 1.12 2.5 2.5 2.5s2.5-1.12 2.5-2.5V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
                            DOCUMENTO OFICIAL ADJUNTO:
                        </span>
                        <p style="margin: 3px 0 0 0; font-size: 12px; font-weight: 700; color: #0f172a; font-family: monospace;">' . htmlspecialchars($nombrePdf) . '</p>
                        <span style="font-size: 10px; color: #64748b;">Incluye Certificado Oficial DIAN y Anexo Cronológico Detallado de ' . $cantTurnos . ' liquidaciones aprobadas (2 Hojas)</span>
                    </div>

                    <div class="box-hash">
                        <span class="hash-title">HUELLA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256):</span>
                        <div class="hash-code">' . $certificadoHash . '</div>
                    </div>

                    <p style="font-size: 10px; color: #64748b; line-height: 1.4; margin: 14px 0 0 0;">
                        * De conformidad con el Artículo 10 del Decreto 836 de 1991, este certificado tributario no requiere firma autógrafa para su plena validez jurídica y probatoria ante la DIAN.
                    </p>
                </div>
                <div class="footer">
                    Fecha de Emisión: ' . date('d/m/Y h:i A') . '<br>
                    © ' . date('Y') . ' IPS Hernán Ocazionez y Cía S.A.S. • Portal Operativo LIHO
                </div>
            </div>
        </body>
        </html>';

        $enviado = enviarCorreoSMTP($correoDestino, $asunto, $bodyHtml, null, $embeddedImages, '', $copiasCC, $attachments);

        require_once __DIR__ . '/includes/logger_helper.php';
        require_once __DIR__ . '/includes/email_logger.php';

        $detallesEnvio = "Certificado Tributario {$selectedAnio} enviado a {$correoDestino} | Especialista: {$nombreMed} (CC: {$cedMed}) | Entidad: {$entidadNom} | Bruto: $" . number_format($totales['bruto'], 2, ',', '.') . " | Rete: $" . number_format($totales['total_rete'], 2, ',', '.') . " | Neto: $" . number_format($totales['neto_pagado'], 2, ',', '.') . " | Liquidaciones: " . count($liquidaciones) . " | Hash: {$certificadoHash}";

        if ($enviado) {
            // 1. Registro en dbo.logs_sistema (Centro Integral de Auditoría LIHO - Visible en tab Correos)
            if (function_exists('registrar_log_sistema')) {
                registrar_log_sistema(
                    'CORREOS',
                    'ENVIO_CERTIFICADO_TRIBUTARIO',
                    'ENVIO_CORREO',
                    $detallesEnvio,
                    [
                        'entidad_afectada' => "Dr(a). {$nombreMed} (CC: {$cedMed})",
                        'valor_nuevo'      => $certificadoHash,
                        'nivel'            => 'SUCCESS'
                    ]
                );
            }

            // 2. Registro en tabla unificada de historial de correos
            if (function_exists('registrarLogCorreo')) {
                registrarLogCorreo(
                    $_SESSION['user_id'] ?? 0,
                    $correoDestino,
                    $asunto,
                    "Certificado Tributario {$selectedAnio}",
                    $detallesEnvio,
                    'EXITOSO'
                );
            }

            echo json_encode([
                'success' => true,
                'message' => "El Certificado Tributario Oficial {$selectedAnio} fue enviado exitosamente al correo {$correoDestino}."
            ]);
        } else {
            $detallesFallo = "Fallo al enviar Certificado Tributario {$selectedAnio} a {$correoDestino} (Médico: {$nombreMed}, CC: {$cedMed}). El servidor SMTP no pudo realizar la entrega.";

            // 1. Registro de fallo en dbo.logs_sistema
            if (function_exists('registrar_log_sistema')) {
                registrar_log_sistema(
                    'CORREOS',
                    'FALLO_ENVIO_CERTIFICADO',
                    'FALLO',
                    $detallesFallo,
                    [
                        'entidad_afectada' => "Dr(a). {$nombreMed} (CC: {$cedMed})",
                        'nivel'            => 'ERROR'
                    ]
                );
            }

            // 2. Registro de fallo en historial de correos
            if (function_exists('registrarLogCorreo')) {
                registrarLogCorreo(
                    $_SESSION['user_id'] ?? 0,
                    $correoDestino,
                    $asunto,
                    "Certificado Tributario {$selectedAnio}",
                    $detallesFallo,
                    'FALLIDO'
                );
            }

            echo json_encode([
                'success' => false,
                'message' => "No fue posible realizar la entrega del correo a través del servidor SMTP. Por favor verifique la dirección del médico o intente nuevamente."
            ]);
        }
    } catch (Exception $e) {
        require_once __DIR__ . '/includes/logger_helper.php';
        require_once __DIR__ . '/includes/email_logger.php';

        $detallesError = "Excepción al generar o enviar Certificado Tributario {$selectedAnio} a {$correoDestino}: " . $e->getMessage();

        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema(
                'CORREOS',
                'ERROR_GENERACION_ENVIO_CERTIFICADO',
                'FALLO',
                $detallesError,
                [
                    'entidad_afectada' => "Dr(a). " . ($medicoDatos['nombre'] ?? 'N/A') . " (CC: {$selectedCedula})",
                    'nivel'            => 'ERROR'
                ]
            );
        }

        if (function_exists('registrarLogCorreo')) {
            registrarLogCorreo(
                $_SESSION['user_id'] ?? 0,
                $correoDestino,
                $asunto,
                "Certificado Tributario {$selectedAnio}",
                $detallesError,
                'FALLIDO'
            );
        }

        echo json_encode([
            'success' => false,
            'message' => "Error al generar o enviar el certificado: " . $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificados Tributarios Anuales • LIHO</title>
    
    <!-- Logo Favicon Institucional -->
    <link rel="shortcut icon" href="assets/img/hologo.png">
    <link rel="icon" type="image/png" href="assets/img/hologo.png">
    
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
    
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            display: inline-block;
            vertical-align: middle;
            line-height: 1;
        }

        /* Animaciones Suaves de Entrada */
        @keyframes fadeSlideUp {
            0% {
                opacity: 0;
                transform: translateY(14px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-card-entry {
            animation: fadeSlideUp 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        /* Animación suave para popovers y menús desplegables */
        .custom-popover {
            opacity: 0;
            visibility: hidden;
            transform: scale(0.97) translateY(-8px);
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.2s cubic-bezier(0.16, 1, 0.3, 1),
                        visibility 0.2s;
            pointer-events: none;
        }

        .custom-popover.is-open {
            opacity: 1 !important;
            visibility: visible !important;
            transform: scale(1) translateY(0) !important;
            pointer-events: auto !important;
        }

        /* Scrollbar estilizado moderno */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 9999px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6);
        }

        /* Efecto de mesa de trabajo / canvas para visualización de documentos */
        .paper-sheet {
            background-color: #f1f5f9;
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 20px 20px;
        }
        .dark .paper-sheet {
            background-color: #020617;
            background-image: radial-gradient(#1e293b 1px, transparent 1px);
            background-size: 20px 20px;
        }

        /* Hoja de Papel Oficial (Visualizador Documental Premium WYSIWYG) */
        .certificate-paper-sheet {
            background-color: #ffffff !important;
            color: #0f172a !important;
            color-scheme: light !important;
            box-shadow: 0 20px 45px -15px rgba(15, 23, 42, 0.15), 0 0 0 1px rgba(15, 23, 42, 0.08);
            transition: all 0.25s ease;
        }
        .dark .certificate-paper-sheet {
            box-shadow: 0 25px 55px -12px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(255, 255, 255, 0.12);
        }

        /* Inmunidad absoluta contra inversión en modo oscuro dentro de la hoja */
        .certificate-paper-sheet,
        .certificate-paper-sheet * {
            color-scheme: light !important;
        }

        /* Estilos de Filas Totales en Pantalla e Impresión (Blanco Puro Siempre Visible) */
        .cert-row-total-navy,
        .cert-row-total-navy td,
        .cert-table-navy tfoot tr.cert-row-total-navy td,
        .cert-table-navy tfoot tr td {
            background-color: #0f172a !important;
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        .cert-row-total-teal,
        .cert-row-total-teal td,
        .cert-table-navy tfoot tr.cert-row-total-teal td {
            background-color: #0d9488 !important;
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        /* ==========================================================================
           REGLAS MAESTRAS DE IMPRESIÓN OFICIAL DIAN (PDF CORPORATIVO IMPECABLE)
           Colores corporativos idénticos al Reporte Oficial de Liquidaciones
           ========================================================================== */
        @media print {
            /* 1. Supresión absoluta de elementos web no imprimibles */
            header, nav, footer, #modalSoporteRIHO, #openSoporteBtn, #themeToggleBtn, 
            #printActionsBar, #filterContainer, .no-print, #toastNotification,
            #viewerToolbar, #sheetDivider {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* 2. Configuración de página estándar Letter con márgenes simétricos */
            @page {
                size: letter portrait;
                margin: 8mm 10mm 8mm 10mm;
            }

            /* 3. Base de impresión limpia con preservación cromática obligatoria */
            html, html.dark, body {
                background: #ffffff !important;
                background-color: #ffffff !important;
                color: #0f172a !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                font-size: 8.5pt !important;
                line-height: 1.25 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            main, .paper-sheet, .certificate-paper-sheet, .certificate-sheet {
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: #ffffff !important;
                background-color: #ffffff !important;
            }

            #sheetPage1, #sheetPage2 {
                display: block !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                background: #ffffff !important;
                background-color: #ffffff !important;
            }

            #sheetPage1 {
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            #sheetPage2 {
                page-break-before: always !important;
                break-before: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* 4. Estilos Corporativos Oficiales LIHO (Idénticos al PDF de Liquidación) */
            
            /* Encabezado Corporativo Slate-900 LIHO */
            .cert-header-navy {
                background-color: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-radius: 6pt !important;
                padding: 8pt 10pt !important;
            }

            .cert-header-navy h2 {
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                font-size: 11pt !important;
                font-weight: 900 !important;
            }

            .cert-header-navy .cert-text-teal {
                color: #00c1be !important;
                -webkit-text-fill-color: #00c1be !important;
                font-weight: 800 !important;
            }

            .cert-header-navy .cert-text-slate {
                color: #94a3b8 !important;
                -webkit-text-fill-color: #94a3b8 !important;
            }

            /* Badge Teal-600 en Cabecera */
            .cert-badge-teal {
                background-color: #0d9488 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-radius: 4pt !important;
                padding: 3pt 8pt !important;
            }

            .cert-badge-teal * {
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
            }

            /* Tarjeta de Datos del Médico y Periodo (Fondo Slate-50 y Borde Slate-300) */
            .cert-card-meta {
                background-color: #f8fafc !important;
                border: 1px solid #cbd5e1 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-radius: 4pt !important;
                padding: 6pt 8pt !important;
            }

            .cert-card-meta .meta-label {
                color: #64748b !important;
                -webkit-text-fill-color: #64748b !important;
                font-weight: 700 !important;
                font-size: 7.5pt !important;
            }

            .cert-card-meta .meta-value {
                color: #0f172a !important;
                -webkit-text-fill-color: #0f172a !important;
                font-weight: 800 !important;
                font-size: 8pt !important;
            }

            .cert-card-meta .meta-teal {
                color: #0d9488 !important;
                -webkit-text-fill-color: #0d9488 !important;
                font-weight: 800 !important;
            }

            /* Tabla de Conceptos Fiscales Oficial */
            .cert-table-navy {
                border-collapse: collapse !important;
                width: 100% !important;
            }

            .cert-table-navy thead th {
                background-color: #0f172a !important;
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                border: 1px solid #0f172a !important;
                padding: 3pt 6pt !important;
                font-size: 7.5pt !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .cert-table-navy tbody tr:not(.cert-row-total-navy):not(.cert-row-total-teal) td {
                color: #1e293b !important;
                -webkit-text-fill-color: #1e293b !important;
                border: 1px solid #cbd5e1 !important;
                padding: 2.2pt 5pt !important;
                font-size: 7.5pt !important;
            }

            /* Fila Total Retenciones Practicadas (Slate-900 / Navy) - Máxima Especificidad Garantizada */
            .cert-row-total-navy,
            .cert-row-total-navy td,
            .cert-table-navy tbody tr.cert-row-total-navy td,
            .cert-table-navy tfoot tr,
            .cert-table-navy tfoot tr td,
            .cert-table-navy tfoot tr th {
                background-color: #0f172a !important;
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                border: 1px solid #0f172a !important;
                font-weight: 800 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .cert-row-total-navy td,
            .cert-row-total-navy td *,
            .cert-table-navy tbody tr.cert-row-total-navy td,
            .cert-table-navy tbody tr.cert-row-total-navy td *,
            .cert-table-navy tfoot tr td,
            .cert-table-navy tfoot tr td * {
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                padding: 3pt 6pt !important;
            }

            /* Fila Valor Neto Total Girado (Teal-600 LIHO - Total a Pagar) - Máxima Especificidad Garantizada */
            .cert-row-total-teal,
            .cert-row-total-teal td,
            .cert-table-navy tbody tr.cert-row-total-teal td,
            .cert-table-navy tfoot tr.cert-row-total-teal td {
                background-color: #0d9488 !important;
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                border: 1px solid #0d9488 !important;
                font-weight: 900 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .cert-row-total-teal td,
            .cert-row-total-teal td *,
            .cert-table-navy tbody tr.cert-row-total-teal td,
            .cert-table-navy tbody tr.cert-row-total-teal td * {
                color: #ffffff !important;
                -webkit-text-fill-color: #ffffff !important;
                padding: 3.5pt 6pt !important;
                font-size: 8.5pt !important;
            }

            /* Tarjeta Huella Criptográfica SHA-256 (Menta y Teal) */
            .cert-hash-box {
                background-color: #f0fdfa !important;
                border: 1.5px solid #0d9488 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-radius: 4pt !important;
                padding: 4pt 8pt !important;
            }

            .cert-hash-title {
                color: #0d9488 !important;
                -webkit-text-fill-color: #0d9488 !important;
                font-weight: 800 !important;
                font-size: 7.5pt !important;
            }

            .cert-status-badge {
                background-color: transparent !important;
                border: 1px solid #0d9488 !important;
                color: #0d9488 !important;
                -webkit-text-fill-color: #0d9488 !important;
                padding: 1pt 4pt !important;
                font-size: 7pt !important;
                font-weight: 800 !important;
            }

            /* 5. HOJA 1: EL CERTIFICADO OFICIAL COMPLETO (EXACTAMENTE PÁGINA 1) */
            .official-cert-page {
                page-break-before: auto !important;
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: block !important;
                box-sizing: border-box !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                max-height: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: #ffffff !important;
                overflow: visible !important;
            }

            .official-cert-page > * + * {
                margin-top: 4pt !important;
            }

            /* HOJA 2: ANEXO DETALLADO CRONOLÓGICO (EXACTAMENTE PÁGINA 2, NUNCA PÁGINA 3) */
            .official-annex-page {
                page-break-before: always !important;
                break-before: page !important;
                page-break-after: avoid !important;
                break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: block !important;
                box-sizing: border-box !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                max-height: none !important;
                padding: 0 !important;
                margin: 0 !important;
                border-top: none !important;
                background: #ffffff !important;
                overflow: visible !important;
            }

            /* Logo tamaño perfecto para impresión */
            .print-logo {
                height: 38pt !important;
                width: auto !important;
            }
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans min-h-screen flex flex-col antialiased transition-colors duration-200">

    <!-- Header & Navegación Global -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Contenido Principal -->
    <main class="flex-1 w-full max-w-[98%] 2xl:max-w-[1850px] mx-auto px-3 sm:px-6 py-6 sm:py-8 space-y-6">

        <!-- Barra Superior de Título y Acciones de la Vista -->
        <div id="printActionsBar" class="animate-card-entry flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 pb-6 border-b border-slate-200/80 dark:border-slate-800/80">
            <div>
                <div class="flex items-center gap-2 text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">
                    <a href="dashboard.php" class="hover:text-primary dark:hover:text-tertiary transition-colors flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">home</span> Inicio
                    </a>
                    <span>/</span>
                    <span class="text-primary dark:text-tertiary">Gestión Tributaria</span>
                    <span>/</span>
                    <span class="text-slate-700 dark:text-slate-300">Certificados Tributarios Anuales</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-teal-50 dark:bg-teal-950/60 border border-teal-200/60 dark:border-teal-800/40 text-tertiary shadow-sm transition-transform duration-300 hover:scale-105">
                        <span class="material-symbols-outlined text-2xl">workspace_premium</span>
                    </div>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                            Certificados de Ingresos y Retenciones
                            <span class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 font-extrabold border border-emerald-300 dark:border-emerald-800 shadow-xs">
                                Art. 381 y 383 E.T.
                            </span>
                        </h1>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-medium mt-0.5">
                            Generación y descarga de certificados fiscales oficiales para declaración de renta de especialistas médicos.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Botones de Acción (Enviar al Médico, Imprimir PDF y Exportar CSV/Excel) -->
            <div class="flex items-center gap-2.5 self-stretch sm:self-auto shrink-0 flex-wrap">
                <a href="?anio=<?php echo $selectedAnio; ?>&medico_cedula=<?php echo urlencode($selectedCedula); ?>&entidad_id=<?php echo $selectedEntidadId; ?>&export=csv" 
                    title="Descargar relación de liquidaciones para Excel"
                    class="group inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-bold shadow-xs transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0">
                    <span class="material-symbols-outlined text-lg text-emerald-600 transition-transform duration-300 group-hover:scale-110">file_download</span>
                    <span>Descargar Excel (CSV)</span>
                </a>

                <button type="button" onclick="abrirModalEnviarCorreo()"
                    title="Enviar Certificado Tributario en PDF al correo del médico especialista"
                    class="group inline-flex items-center justify-center gap-2 px-4.5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:opacity-95 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer">
                    <span class="material-symbols-outlined text-lg transition-transform duration-300 group-hover:scale-110">outgoing_mail</span>
                    <span>Enviar al Médico</span>
                </button>

                <button type="button" onclick="prepareAndPrint()"
                    title="Imprimir documento oficial o guardar como PDF"
                    class="group inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-primary via-[#004d66] to-tertiary hover:opacity-95 text-white text-xs font-black shadow-md shadow-primary/20 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer">
                    <span class="material-symbols-outlined text-lg transition-transform duration-300 group-hover:rotate-6">print</span>
                    <span>Imprimir / Guardar PDF</span>
                </button>
            </div>
        </div>

        <!-- FORMULARIO OCULTO PARA AUTO-SUBMIT DE LOS SELECTORES PERSONALIZADOS -->
        <form id="filterMasterForm" method="GET" action="certificados_tributarios.php" class="hidden">
            <input type="hidden" name="anio" id="hiddenInputAnio" value="<?php echo $selectedAnio; ?>">
            <input type="hidden" name="medico_cedula" id="hiddenInputMedico" value="<?php echo htmlspecialchars($selectedCedula); ?>">
            <input type="hidden" name="entidad_id" id="hiddenInputEntidad" value="<?php echo $selectedEntidadId; ?>">
        </form>

        <!-- PANEL DE FILTROS REDISEÑADO CON ALTO Z-INDEX Y VISUALIZACIÓN CLARA DE FECHAS -->
        <div id="filterContainer" class="animate-card-entry relative z-20 bg-white/95 dark:bg-slate-900/95 backdrop-blur-md rounded-3xl border border-slate-200/90 dark:border-slate-800 p-4 sm:p-6 shadow-md">
            
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                
                <!-- 1. AÑO GRAVABLE Y PERIODO DETALLADO DE FECHAS (DESDE - HASTA) -->
                <div class="lg:col-span-5 bg-slate-50/90 dark:bg-slate-800/50 p-3.5 rounded-2xl border border-slate-200/80 dark:border-slate-700/60">
                    <div class="flex items-center justify-between mb-2.5">
                        <label class="text-[11px] font-black text-slate-700 dark:text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-tertiary">calendar_month</span>
                            Año Gravable & Periodo Fiscal
                        </label>
                        <span class="text-[10px] font-bold text-tertiary px-2 py-0.5 rounded-md bg-teal-50 dark:bg-teal-950/80 border border-tertiary/30">
                            Vigencia DIAN
                        </span>
                    </div>

                    <!-- Cápsulas de selección de año -->
                    <div class="flex items-center gap-2 p-1 bg-white dark:bg-slate-900 rounded-xl border border-slate-200/80 dark:border-slate-700/80 shadow-inner mb-3">
                        <?php for ($y = $currentYear; $y >= 2024; $y--): 
                            $isActiveYear = ($selectedAnio === $y);
                        ?>
                            <button type="button" 
                                onclick="selectYear(<?php echo $y; ?>)"
                                class="flex-1 flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-xs font-black transition-all duration-200 cursor-pointer <?php echo $isActiveYear ? 'bg-gradient-to-r from-primary to-tertiary text-white shadow-md shadow-primary/25 scale-[1.02]' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'; ?>">
                                <span class="material-symbols-outlined text-sm <?php echo $isActiveYear ? 'text-white' : 'text-slate-400'; ?>">event</span>
                                <span><?php echo $y; ?></span>
                                <?php if ($y === $currentYear): ?>
                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $isActiveYear ? 'bg-emerald-300' : 'bg-tertiary'; ?>"></span>
                                <?php endif; ?>
                            </button>
                        <?php endfor; ?>
                    </div>

                    <!-- Badge informativo de la Vigencia Fiscal Oficial (Estatuto Tributario) con formato largo en letras sin cortes -->
                    <div class="p-3 rounded-xl bg-teal-50/90 dark:bg-slate-800/90 border border-teal-200/70 dark:border-teal-900/50 flex items-start gap-3">
                        <div class="p-2 rounded-lg bg-tertiary text-white shrink-0 shadow-xs mt-0.5">
                            <span class="material-symbols-outlined text-base">event_available</span>
                        </div>
                        <div class="flex-1 min-w-0 text-xs space-y-0.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-black text-slate-900 dark:text-slate-100 uppercase text-[10px] tracking-wider">Vigencia Fiscal Oficial</span>
                                <span class="text-[10px] font-bold text-tertiary px-1.5 py-0.5 rounded bg-white dark:bg-slate-900 border border-teal-300 dark:border-teal-800 shrink-0">
                                    <?php echo $totales['cantidad_liq']; ?> turnos
                                </span>
                            </div>
                            <p class="font-bold text-slate-800 dark:text-slate-200 text-xs leading-snug break-words">
                                <?php echo $textoVigenciaFiscalLarga; ?>
                            </p>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 font-medium">
                                Declaración de Renta • Año Gravable <?php echo $selectedAnio; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- 2. ESPECIALISTA MÉDICO - CUSTOM DROPDOWN COMBOBOX CON BUSCADOR -->
                <div class="lg:col-span-4 relative" id="medicoComboboxContainer" style="z-index: 30;">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] font-black text-slate-700 dark:text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-tertiary">stethoscope</span>
                            Especialista Médico
                        </label>
                        <span class="text-[10px] text-slate-400 font-mono"><?php echo count($listaMedicos); ?> médicos</span>
                    </div>

                    <?php if ($isMedico): ?>
                        <!-- Titular Fijo para Rol Médico -->
                        <div class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-200 text-xs font-bold rounded-2xl p-3 flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2.5 overflow-hidden">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-primary to-tertiary text-white font-extrabold text-xs flex items-center justify-center shrink-0 shadow-xs">
                                    <?php echo strtoupper(substr($userName, 0, 2)); ?>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="truncate text-xs font-black text-primary dark:text-tertiary"><?php echo htmlspecialchars($userName); ?></p>
                                    <p class="text-[10px] text-slate-400 font-mono mt-0.5">CC: <?php echo htmlspecialchars($userCedula); ?></p>
                                </div>
                            </div>
                            <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-lg bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-400 border border-teal-300 dark:border-teal-800">
                                Titular
                            </span>
                        </div>
                    <?php else: ?>
                        <!-- Trigger Botón Personalizado para Dropdown -->
                        <button type="button" id="medicoComboboxBtn"
                            class="w-full bg-slate-50 dark:bg-slate-800/80 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-2xl p-2.5 text-left flex items-center justify-between gap-2 shadow-xs transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-tertiary/40 cursor-pointer">
                            
                            <div class="flex items-center gap-2.5 overflow-hidden">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-primary to-tertiary text-white font-extrabold text-xs flex items-center justify-center shrink-0 shadow-xs">
                                    <?php 
                                        $initialsM = 'MD';
                                        if (!empty($medicoDatos['nombre'])) {
                                            $partsM = explode(' ', trim($medicoDatos['nombre']));
                                            $initialsM = strtoupper(substr($partsM[0] ?? '', 0, 1) . substr($partsM[1] ?? '', 0, 1));
                                        } elseif (empty($listaMedicos)) {
                                            $initialsM = '--';
                                        }
                                        echo $initialsM;
                                    ?>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="text-xs font-black text-slate-900 dark:text-white truncate">
                                        <?php echo htmlspecialchars($medicoDatos['nombre'] ?? (empty($listaMedicos) ? 'Sin especialistas asociados' : 'Seleccionar Especialista')); ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400 font-mono mt-0.5 flex items-center gap-1">
                                        <?php if (!empty($selectedCedula)): ?>
                                            <span>CC:</span>
                                            <span class="font-bold text-tertiary"><?php echo htmlspecialchars($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C')); ?></span>
                                        <?php else: ?>
                                            <span class="text-amber-500 font-medium">0 médicos vinculados a esta entidad</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <span class="material-symbols-outlined text-slate-400 text-xl transition-transform duration-200" id="medicoChevron">expand_more</span>
                        </button>

                        <!-- Popover Desplegable con Buscador en Vivo (Alto Z-Index y fondo sólido) -->
                        <div id="medicoComboboxMenu" 
                            class="custom-popover absolute left-0 right-0 top-full mt-2 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 p-3 z-[100] origin-top">
                            
                            <!-- Input de Búsqueda Instantánea -->
                            <div class="relative mb-2.5">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
                                <input type="text" id="medicoSearchInput" placeholder="Buscar especialista o cédula..." 
                                    class="w-full pl-9 pr-3 py-2 bg-slate-100 dark:bg-slate-800 border-none rounded-xl text-xs font-semibold text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-tertiary/40 outline-none transition-all" />
                            </div>

                            <!-- Lista Desplazable de Médicos -->
                            <div class="max-h-60 overflow-y-auto custom-scroll space-y-1" id="medicoOptionsList">
                                <?php if (empty($listaMedicos)): ?>
                                    <div class="py-6 px-3 text-center">
                                        <span class="material-symbols-outlined text-slate-400 text-2xl mb-1">person_off</span>
                                        <p class="text-xs font-bold text-slate-600 dark:text-slate-300">Sin especialistas vinculados</p>
                                        <p class="text-[10px] text-slate-400 mt-0.5">Esta entidad no registra médicos en el sistema.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($listaMedicos as $m): 
                                        $isSelMed = (ltrim($selectedCedula, 'C') === ltrim($m['medico_cedula'], 'C'));
                                        $medParts = explode(' ', trim($m['medico_nombre']));
                                        $mInit = strtoupper(substr($medParts[0] ?? '', 0, 1) . substr($medParts[1] ?? '', 0, 1));
                                    ?>
                                        <button type="button" 
                                            data-cedula="<?php echo htmlspecialchars($m['medico_cedula']); ?>"
                                            data-nombre="<?php echo htmlspecialchars(mb_strtolower($m['medico_nombre'], 'UTF-8')); ?>"
                                            onclick="selectDoctor('<?php echo htmlspecialchars($m['medico_cedula']); ?>')"
                                            class="medico-option-item w-full flex items-center justify-between gap-2.5 p-2 rounded-xl text-left transition-all duration-150 cursor-pointer <?php echo $isSelMed ? 'bg-teal-50 dark:bg-slate-800 text-tertiary font-bold border border-tertiary/30' : 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200'; ?>">
                                            
                                            <div class="flex items-center gap-2.5 overflow-hidden">
                                                <div class="w-7 h-7 rounded-lg <?php echo $isSelMed ? 'bg-tertiary text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'; ?> font-bold text-[10px] flex items-center justify-center shrink-0">
                                                    <?php echo $mInit; ?>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <p class="text-xs font-bold truncate <?php echo $isSelMed ? 'text-primary dark:text-tertiary' : ''; ?>">
                                                        <?php echo htmlspecialchars($m['medico_nombre']); ?>
                                                    </p>
                                                    <p class="text-[10px] text-slate-400 font-mono">CC: <?php echo htmlspecialchars($m['medico_cedula']); ?></p>
                                                </div>
                                            </div>

                                            <?php if ($isSelMed): ?>
                                                <span class="material-symbols-outlined text-tertiary text-lg shrink-0">check_circle</span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. ENTIDAD RETENEDORA - SELECTOR CORREGIDO SIN DESBORDAMIENTOS -->
                <div class="lg:col-span-3 relative" id="entidadDropdownContainer" style="z-index: 25;">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] font-black text-slate-700 dark:text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-tertiary">domain</span>
                            Entidad Retenedora
                        </label>
                        <span class="text-[10px] text-slate-400 font-mono">Agente</span>
                    </div>

                    <?php if ($isMedico || empty($listaEntidades)): ?>
                        <!-- Fijo Matriz -->
                        <div class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-200 text-xs font-bold rounded-2xl p-3 flex items-center justify-between shadow-xs">
                            <div class="flex items-center gap-2.5 overflow-hidden">
                                <span class="material-symbols-outlined text-tertiary text-lg shrink-0">apartment</span>
                                <div class="overflow-hidden">
                                    <p class="truncate text-xs font-black"><?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?></p>
                                    <p class="text-[10px] text-slate-400 font-mono">NIT: <?php echo htmlspecialchars($entidadInfo['nit'] ?? '800149695'); ?></p>
                                </div>
                            </div>
                            <span class="text-[9px] font-extrabold uppercase px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 shrink-0">
                                Matriz
                            </span>
                        </div>
                    <?php else: ?>
                        <!-- Trigger Botón Entidad -->
                        <button type="button" id="entidadDropdownBtn"
                            class="w-full bg-slate-50 dark:bg-slate-800/80 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-2xl p-2.5 text-left flex items-center justify-between gap-2 shadow-xs transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-tertiary/40 cursor-pointer">
                            
                            <div class="flex items-center gap-2.5 overflow-hidden">
                                <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 border border-indigo-200/60 dark:border-indigo-800/40">
                                    <span class="material-symbols-outlined text-lg">domain</span>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="text-xs font-black text-slate-900 dark:text-white truncate">
                                        <?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>
                                    </p>
                                    <p class="text-[10px] text-slate-400 font-mono">NIT: <?php echo htmlspecialchars($entidadInfo['nit'] ?? '800149695'); ?><?php echo !empty($entidadInfo['dv']) ? '-' . htmlspecialchars($entidadInfo['dv']) : '-1'; ?></p>
                                </div>
                            </div>

                            <span class="material-symbols-outlined text-slate-400 text-xl transition-transform duration-200" id="entidadChevron">expand_more</span>
                        </button>

                        <!-- Popover Desplegable Entidades (CORREGIDO: Fondo sólido, ancho completo y alto z-index) -->
                        <div id="entidadDropdownMenu" 
                            class="custom-popover absolute left-0 right-0 top-full mt-2 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 p-2.5 z-[100] origin-top">
                            <div class="max-h-56 overflow-y-auto custom-scroll space-y-1">
                                <?php foreach ($listaEntidades as $ent): 
                                    $isSelEnt = ($selectedEntidadId == $ent['id'] || ($selectedEntidadId == 0 && !empty($ent['is_matriz'])));
                                ?>
                                    <button type="button" 
                                        onclick="selectEntity(<?php echo $ent['id']; ?>)"
                                        class="w-full flex items-center justify-between gap-3 p-2.5 rounded-xl text-left transition-all duration-150 cursor-pointer <?php echo $isSelEnt ? 'bg-teal-50/80 dark:bg-slate-800 text-tertiary font-bold border border-tertiary/40' : 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-200'; ?>">
                                        <div class="flex items-center gap-2.5 overflow-hidden">
                                            <div class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary flex items-center justify-center shrink-0 border border-slate-200 dark:border-slate-700">
                                                <span class="material-symbols-outlined text-base">apartment</span>
                                            </div>
                                            <div class="overflow-hidden">
                                                <p class="text-xs font-black truncate text-slate-900 dark:text-white"><?php echo htmlspecialchars($ent['nombre']); ?></p>
                                                <p class="text-[10px] text-slate-400 font-mono">NIT: <?php echo htmlspecialchars($ent['nit']); ?><?php echo !empty($ent['dv']) ? '-' . htmlspecialchars($ent['dv']) : ''; ?></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1.5 shrink-0">
                                            <?php if (!empty($ent['is_matriz'])): ?>
                                                <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-800">Matriz</span>
                                            <?php endif; ?>
                                            <?php if ($isSelEnt): ?>
                                                <span class="material-symbols-outlined text-tertiary text-lg">check_circle</span>
                                            <?php endif; ?>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- TARJETAS KPI CON DISEÑO PREMIUM, GLASSMORPHISM Y ANIMACIONES -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 no-print relative z-10">
            
            <!-- Tarjeta 1: Total Ingresos Brutos -->
            <div class="animate-card-entry bg-white/95 dark:bg-slate-900/90 backdrop-blur-md rounded-2xl border border-slate-200/90 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:-translate-y-1.5 hover:shadow-xl hover:border-blue-500/40 transition-all duration-300">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-blue-500/10 rounded-full blur-2xl group-hover:bg-blue-500/20 transition-colors pointer-events-none"></div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Ingresos Brutos</span>
                    <span class="p-2.5 rounded-xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 shadow-xs transition-transform duration-300 group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                    </span>
                </div>
                <div class="mt-3">
                    <p class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-mono tracking-tight counter-number" data-target="<?php echo $totales['bruto']; ?>">
                        $<?php echo number_format($totales['bruto'], 0, ',', '.'); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                        Honorarios médicos del año <?php echo $selectedAnio; ?>
                    </p>
                </div>
            </div>

            <!-- Tarjeta 2: Total Retención en la Fuente -->
            <div class="animate-card-entry bg-white/95 dark:bg-slate-900/90 backdrop-blur-md rounded-2xl border border-slate-200/90 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:-translate-y-1.5 hover:shadow-xl hover:border-amber-500/40 transition-all duration-300" style="animation-delay: 0.1s;">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-amber-500/10 rounded-full blur-2xl group-hover:bg-amber-500/20 transition-colors pointer-events-none"></div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Retención en Fuente</span>
                    <span class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 shadow-xs transition-transform duration-300 group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">receipt</span>
                    </span>
                </div>
                <div class="mt-3">
                    <p class="text-2xl sm:text-3xl font-black text-amber-600 dark:text-amber-400 font-mono tracking-tight counter-number" data-target="<?php echo $totales['total_rete']; ?>">
                        $<?php echo number_format($totales['total_rete'], 0, ',', '.'); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>
                        Art. 383: $<?php echo number_format($totales['rete_383'], 0, ',', '.'); ?> • Trad: $<?php echo number_format($totales['rete_fuente'], 0, ',', '.'); ?>
                    </p>
                </div>
            </div>

            <!-- Tarjeta 3: Parafiscales y Seguridad Social -->
            <div class="animate-card-entry bg-white/95 dark:bg-slate-900/90 backdrop-blur-md rounded-2xl border border-slate-200/90 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:-translate-y-1.5 hover:shadow-xl hover:border-purple-500/40 transition-all duration-300" style="animation-delay: 0.2s;">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-purple-500/10 rounded-full blur-2xl group-hover:bg-purple-500/20 transition-colors pointer-events-none"></div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Aportes Seguridad Social</span>
                    <span class="p-2.5 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 shadow-xs transition-transform duration-300 group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">health_and_safety</span>
                    </span>
                </div>
                <div class="mt-3">
                    <?php $totSegSoc = $totales['salud'] + $totales['pension'] + $totales['arl']; ?>
                    <p class="text-2xl sm:text-3xl font-black text-purple-600 dark:text-purple-400 font-mono tracking-tight counter-number" data-target="<?php echo $totSegSoc; ?>">
                        $<?php echo number_format($totSegSoc, 0, ',', '.'); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-purple-500"></span>
                        Salud: $<?php echo number_format($totales['salud'], 0, ',', '.'); ?> • ARL: $<?php echo number_format($totales['arl'], 0, ',', '.'); ?>
                    </p>
                </div>
            </div>

            <!-- Tarjeta 4: Total Neto Pagado -->
            <div class="animate-card-entry bg-white/95 dark:bg-slate-900/90 backdrop-blur-md rounded-2xl border border-slate-200/90 dark:border-slate-800 p-5 shadow-xs relative overflow-hidden group hover:-translate-y-1.5 hover:shadow-xl hover:border-emerald-500/40 transition-all duration-300" style="animation-delay: 0.3s;">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition-colors pointer-events-none"></div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Neto Percibido</span>
                    <span class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 shadow-xs transition-transform duration-300 group-hover:scale-110">
                        <span class="material-symbols-outlined text-xl">payments</span>
                    </span>
                </div>
                <div class="mt-3">
                    <p class="text-2xl sm:text-3xl font-black text-emerald-600 dark:text-emerald-400 font-mono tracking-tight counter-number" data-target="<?php echo $totales['neto_pagado']; ?>">
                        $<?php echo number_format($totales['neto_pagado'], 0, ',', '.'); ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                        <?php echo $totales['cantidad_liq']; ?> turnos / periodos liquidados
                    </p>
                </div>
            </div>

        </div>

        <?php if (empty($selectedCedula) || empty($medicoDatos)): ?>
            <!-- Estado Vacío: Sin médico en la entidad -->
            <div class="animate-card-entry bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 p-12 text-center shadow-xs">
                <div class="w-16 h-16 mx-auto rounded-3xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center mb-4 border border-amber-200 dark:border-amber-800/50">
                    <span class="material-symbols-outlined text-3xl">domain_disabled</span>
                </div>
                <h3 class="text-lg font-black text-slate-800 dark:text-slate-100">Sin especialistas asociados a esta entidad</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto mt-1.5 leading-relaxed">
                    La entidad retenedora <strong><?php echo htmlspecialchars($entidadInfo['nombre'] ?? ''); ?></strong> no cuenta actualmente con especialistas médicos asignados ni liquidaciones registradas. Seleccione otra entidad en el filtro superior.
                </p>
            </div>
        <?php elseif (empty($liquidaciones)): ?>
            <!-- Estado Vacío: Médico sin liquidaciones en el año gravable -->
            <div class="animate-card-entry bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 p-12 text-center shadow-xs">
                <div class="w-16 h-16 mx-auto rounded-3xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mb-4">
                    <span class="material-symbols-outlined text-3xl">sentiment_dissatisfied</span>
                </div>
                <h3 class="text-lg font-black text-slate-800 dark:text-slate-100">No se encontraron liquidaciones para este periodo</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto mt-1.5 leading-relaxed">
                    No existen liquidaciones registradas o aprobadas para el especialista <strong><?php echo htmlspecialchars($medicoDatos['nombre'] ?? ''); ?></strong> (CC: <strong><?php echo htmlspecialchars($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C')); ?></strong>) en el año gravable <strong><?php echo $selectedAnio; ?></strong> para la entidad <strong><?php echo htmlspecialchars($entidadInfo['nombre'] ?? ''); ?></strong>.
                </p>
            </div>
        <?php else: ?>

            <!-- =========================================================================================
                 VISOR OFICIAL DE DOCUMENTOS LIHO (WYSIWYG - 2 PÁGINAS CERTIFICADAS)
                 ========================================================================================= -->
            <div class="animate-card-entry space-y-4" style="animation-delay: 0.35s;">

                <!-- Barra Superior de Herramientas del Visor (no-print) -->
                <div id="viewerToolbar" class="no-print w-full max-w-[1000px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-3 rounded-2xl bg-white/95 dark:bg-slate-900/95 backdrop-blur-md border border-slate-200/90 dark:border-slate-800 shadow-sm">
                    <div class="flex items-center gap-2.5 text-xs">
                        <span class="flex h-2.5 w-2.5 relative">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-teal-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-teal-500"></span>
                        </span>
                        <div>
                            <span class="font-black text-slate-900 dark:text-white">Visor de Documento Oficial</span>
                            <span class="text-slate-400 dark:text-slate-500 mx-1.5">•</span>
                            <span class="text-[11px] font-mono font-bold text-slate-500 dark:text-slate-400">2 Hojas (Formato Carta)</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2.5 flex-wrap justify-end">
                        <!-- Paginador de pestañas de hojas -->
                        <div class="inline-flex items-center p-1 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 text-xs font-bold">
                            <button type="button" onclick="switchSheetView('all')" id="btnViewAll" 
                                class="px-3 py-1.5 rounded-lg transition-all duration-150 bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-xs">
                                Todas (2)
                            </button>
                            <button type="button" onclick="switchSheetView('p1')" id="btnViewP1" 
                                class="px-3 py-1.5 rounded-lg transition-all duration-150 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                                Hoja 1: Certificado
                            </button>
                            <button type="button" onclick="switchSheetView('p2')" id="btnViewP2" 
                                class="px-3 py-1.5 rounded-lg transition-all duration-150 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                                Hoja 2: Anexo
                            </button>
                        </div>

                        <!-- Botón Enviar al Médico por Correo con Confirmación y Vista Previa -->
                        <button type="button" onclick="abrirModalEnviarCorreo()" id="btnEnviarCorreoMedico"
                            title="Enviar Certificado Tributario Oficial en PDF al correo del médico"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-black shadow-xs transition-all duration-150 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer">
                            <span class="material-symbols-outlined text-sm">outgoing_mail</span>
                            <span>Enviar al Médico</span>
                        </button>

                        <button type="button" onclick="prepareAndPrint()" 
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-primary hover:bg-primary/90 text-white text-xs font-black shadow-xs transition-all duration-150 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer">
                            <span class="material-symbols-outlined text-sm">print</span>
                            <span>Imprimir</span>
                        </button>
                    </div>
                </div>

                <!-- Canvas / Mesa de Trabajo para los Documentos de Papel -->
                <div class="paper-sheet p-3 sm:p-8 rounded-3xl border border-slate-200/90 dark:border-slate-800/90 flex flex-col items-center shadow-inner">

                    <!-- =========================================================================================
                         HOJA 1 OFICIAL: EL CERTIFICADO COMPLETO (HOJA DE PAPEL FÍSICA)
                         ========================================================================================= -->
                    <div id="sheetPage1" class="certificate-paper-sheet bg-white w-full max-w-[1000px] rounded-2xl shadow-xl border border-slate-300/80 p-6 sm:p-10 space-y-4 text-slate-900 relative">
                        
                        <div class="official-cert-page space-y-3.5">
                            
                            <!-- 1. Encabezado Institucional Corporativo -->
                            <div class="cert-header-navy rounded-2xl p-3 sm:p-4 flex flex-row items-center justify-between gap-4 shadow-sm" style="background-color: #0f172a !important;">
                                <div class="flex items-center gap-3.5 text-left">
                                    <div class="p-1 rounded-xl bg-slate-900/80 border border-slate-700/60 shadow-xs shrink-0">
                                        <img src="<?php echo htmlspecialchars($headerLogoPath); ?>" 
                                            alt="Logo Entidad" 
                                            class="h-10 sm:h-12 w-auto object-contain print-logo" 
                                            onerror="this.src='assets/img/Ho_Fondo_Osc.png'" />
                                    </div>
                                    <div>
                                        <h2 class="text-sm sm:text-base font-black tracking-wide text-white uppercase leading-tight" style="color: #ffffff !important;">
                                            <?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>
                                        </h2>
                                        <p class="cert-text-teal text-[11px] sm:text-xs font-bold tracking-wide uppercase mt-0.5" style="color: #00c1be !important;">
                                            LIHO - CERTIFICADO DE INGRESOS Y RETENCIONES
                                        </p>
                                        <p class="cert-text-slate text-[9.5px] font-medium mt-0.5" style="color: #94a3b8 !important;">
                                            NIT: <?php echo htmlspecialchars($entidadInfo['nit'] ?? '800149695'); ?><?php echo !empty($entidadInfo['dv']) ? '-' . htmlspecialchars($entidadInfo['dv']) : '-1'; ?> | CERTIFICACIÓN OFICIAL DIAN ART. 381 Y 383 E.T.
                                        </p>
                                    </div>
                                </div>

                                <!-- Badge Año y Estado Oficial -->
                                <div class="text-right shrink-0">
                                    <div class="cert-badge-teal inline-block px-3.5 py-1.5 rounded-xl text-center shadow-xs" style="background-color: #0d9488 !important;">
                                        <span class="block text-[8.5px] font-black uppercase tracking-wider text-teal-100" style="color: #ccfbf1 !important;">CERTIFICADO OFICIAL</span>
                                        <span class="text-base sm:text-lg font-black font-mono text-white leading-none" style="color: #ffffff !important;">AÑO GRAVABLE <?php echo $selectedAnio; ?></span>
                                    </div>
                                    <p class="text-[9px] font-bold tracking-widest uppercase mt-1 text-slate-300" style="color: #cbd5e1 !important;">ESTADO: CERTIFICADO / VIGENTE</p>
                                </div>
                            </div>

                            <!-- 2. Tarjeta de Datos del Médico y Periodo Fiscal -->
                            <div class="cert-card-meta rounded-xl p-3 border text-xs" style="background-color: #f8fafc !important; border: 1px solid #cbd5e1 !important;">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                                    <div class="space-y-1.5">
                                        <div class="flex items-baseline gap-2">
                                            <span class="meta-label text-[9.5px] font-bold text-slate-500 uppercase tracking-wider w-36 shrink-0">PROFESIONAL / MÉDICO:</span>
                                            <span class="meta-value text-xs font-black text-slate-900 uppercase truncate"><?php echo htmlspecialchars($medicoDatos['nombre'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="flex items-baseline gap-2">
                                            <span class="meta-label text-[9.5px] font-bold text-slate-500 uppercase tracking-wider w-36 shrink-0">PERIODO CERTIFICADO:</span>
                                            <span class="meta-value text-[11px] font-bold text-slate-800"><?php echo $textoVigenciaFiscalLarga; ?></span>
                                        </div>
                                        <div class="flex items-baseline gap-2">
                                            <span class="meta-label text-[9.5px] font-bold text-slate-500 uppercase tracking-wider w-36 shrink-0">ENTIDAD RETENEDORA:</span>
                                            <span class="meta-value text-[11px] font-bold text-slate-800 truncate"><?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?></span>
                                        </div>
                                    </div>

                                    <div class="space-y-1.5">
                                        <div class="flex items-baseline gap-2">
                                            <span class="meta-label text-[9.5px] font-bold text-slate-500 uppercase tracking-wider w-32 shrink-0">CÉDULA / ID:</span>
                                            <span class="meta-value text-xs font-mono font-black text-slate-900"><?php echo htmlspecialchars($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C')); ?></span>
                                        </div>
                                        <div class="flex items-baseline gap-2">
                                            <span class="meta-label text-[9.5px] font-bold text-slate-500 uppercase tracking-wider w-32 shrink-0">FECHA EMISIÓN:</span>
                                            <span class="meta-value text-[11px] font-mono text-slate-800"><?php echo date('d/m/Y h:i A'); ?></span>
                                        </div>
                                        <div class="flex items-baseline gap-2">
                                            <span class="meta-label text-[9.5px] font-bold text-slate-500 uppercase tracking-wider w-32 shrink-0">CERTIFICADO POR:</span>
                                            <span class="meta-teal text-[11px] font-black uppercase" style="color: #0d9488 !important;">Dirección Financiera / Contabilidad</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Cláusula Legal Normativa -->
                            <div class="text-center py-1.5 px-3 rounded-lg border border-slate-200 bg-slate-50/80 text-[9.5px] text-slate-600 leading-tight">
                                Expedido en cumplimiento del <strong>Artículo 381 del Estatuto Tributario Nacional</strong> y el <strong>Artículo 383</strong> para personas naturales que perciben rentas de trabajo u honorarios médicos independientes.
                            </div>

                            <!-- 4. Tabla Consolidada de Conceptos Fiscales (DIAN Standard) -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <h4 class="text-[10.5px] font-black uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm text-tertiary">receipt_long</span>
                                        1. CONSOLIDADO DE INGRESOS BRUTOS Y RETENCIONES PRACTICADAS
                                    </h4>
                                    <span class="text-[8.5px] text-slate-400 font-mono">Valores en Pesos Colombianos (COP)</span>
                                </div>

                                <div class="overflow-x-auto rounded-xl border border-slate-300 shadow-xs">
                                    <table class="cert-table-navy w-full text-xs text-left">
                                        <thead class="bg-[#0f172a] text-white font-bold border-b border-[#0f172a] uppercase text-[9px] tracking-wider" style="background-color: #0f172a !important; color: #ffffff !important;">
                                            <tr>
                                                <th class="px-3 py-2 text-white" style="color: #ffffff !important;">Concepto Fiscal</th>
                                                <th class="px-3 py-2 text-center text-white" style="color: #ffffff !important;">Referencia Legal</th>
                                                <th class="px-3 py-2 text-center text-white whitespace-nowrap" style="color: #ffffff !important;">% Tarifa</th>
                                                <th class="px-3 py-2 text-right text-white" style="color: #ffffff !important;">Valor Consolidado</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-200 font-medium">
                                            <?php
                                            $filasItemsFiscales = [
                                                [
                                                    'concepto' => 'Base de la retención',
                                                    'ref' => 'Art. 103 E.T.',
                                                    'pct' => '100%',
                                                    'valor' => $totales['bruto'],
                                                    'bold' => true
                                                ]
                                            ];
                                            if (($totales['rete_383'] ?? 0) > 0) {
                                                $filasItemsFiscales[] = [
                                                    'concepto' => 'Retención aplicada (Art. 383 E.T.)',
                                                    'ref' => 'Art. 383 E.T.',
                                                    'pct' => $pctRete383Col,
                                                    'valor' => $totales['rete_383'],
                                                    'bold' => false
                                                ];
                                            }
                                            if (($totales['rete_fuente'] ?? 0) > 0) {
                                                $filasItemsFiscales[] = [
                                                    'concepto' => 'Retención aplicada (Art. 392 E.T.)',
                                                    'ref' => 'Art. 392 E.T.',
                                                    'pct' => $pctReteFuenteCol,
                                                    'valor' => $totales['rete_fuente'],
                                                    'bold' => false
                                                ];
                                            }
                                            if (($totales['rete_383'] ?? 0) <= 0 && ($totales['rete_fuente'] ?? 0) <= 0) {
                                                $filasItemsFiscales[] = [
                                                    'concepto' => 'Retención aplicada (Sin retención practicada)',
                                                    'ref' => 'Art. 381 E.T.',
                                                    'pct' => '',
                                                    'valor' => 0,
                                                    'bold' => false
                                                ];
                                            }

                                            foreach ($filasItemsFiscales as $idx => $fila):
                                                $bgClass = ($idx % 2 === 0) ? 'bg-white' : 'bg-slate-50/50';
                                                $titleClass = !empty($fila['bold']) ? 'font-bold text-slate-900' : 'text-slate-700';
                                                $valClass = !empty($fila['bold']) ? 'font-mono font-black text-slate-900' : 'font-mono font-semibold text-slate-800';
                                            ?>
                                            <tr class="<?php echo $bgClass; ?> hover:bg-slate-50/80">
                                                <td class="px-3 py-1 <?php echo $titleClass; ?>">
                                                    <?php echo htmlspecialchars($fila['concepto']); ?>
                                                </td>
                                                <td class="px-3 py-1 text-center text-slate-500 font-mono text-[9.5px]">
                                                    <?php echo htmlspecialchars($fila['ref']); ?>
                                                </td>
                                                <td class="px-3 py-1 text-center text-slate-700 font-mono text-[9.5px] font-bold">
                                                    <?php echo htmlspecialchars($fila['pct']); ?>
                                                </td>
                                                <td class="px-3 py-1 text-right <?php echo $valClass; ?>">
                                                    $<?php echo number_format($fila['valor'], 2, ',', '.'); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <!-- FILA TOTAL RETENCIÓN PRACTICADA -->
                                            <tr class="cert-row-total-navy bg-[#0f172a] text-white font-black" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                <td class="px-3 py-2 uppercase tracking-wider font-bold" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                    Total Retención en la Fuente Practicada en el Año Gravable
                                                </td>
                                                <td class="px-3 py-2 text-center font-mono text-[9.5px] font-bold" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                    Art. 381 E.T.
                                                </td>
                                                <td class="px-3 py-2 text-center font-mono text-[9.5px] font-black text-amber-300" style="background-color: #0f172a !important; color: #fde047 !important; -webkit-text-fill-color: #fde047 !important;">
                                                    <?php echo $pctTotReteCol; ?>
                                                </td>
                                                <td class="px-3 py-2 text-right font-mono text-xs sm:text-sm font-black" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                    $<?php echo number_format($totales['total_rete'], 2, ',', '.'); ?>
                                                </td>
                                            </tr>

                                            <!-- FILA TOTAL NETO PAGADO -->
                                            <tr class="cert-row-total-teal bg-[#0d9488] text-white font-black" style="background-color: #0d9488 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                <td class="px-3 py-2.5 uppercase tracking-wider font-black" style="background-color: #0d9488 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                    Valor Neto Total Girado / Pagado al Especialista
                                                </td>
                                                <td class="px-3 py-2.5 text-center font-mono text-[9.5px] font-bold" style="background-color: #0d9488 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                    NETO PAGADO
                                                </td>
                                                <td class="px-3 py-2.5 text-center font-mono text-[9.5px] font-black text-teal-100" style="background-color: #0d9488 !important; color: #ccfbf1 !important; -webkit-text-fill-color: #ccfbf1 !important;">
                                                    <?php echo $pctNetoCol; ?>
                                                </td>
                                                <td class="px-3 py-2.5 text-right font-mono text-sm font-black" style="background-color: #0d9488 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                    $<?php echo number_format($totales['neto_pagado'], 2, ',', '.'); ?>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- 5. Firmas Autorizadas y Huella Digital Criptográfica SHA-256 -->
                            <div class="pt-2">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                                    <div class="space-y-1">
                                        <div class="border-b-2 border-slate-700 w-52 pb-1">
                                            <span class="font-mono text-[9.5px] text-slate-500 tracking-widest">[ FIRMA DIGITAL REGISTRADA ]</span>
                                        </div>
                                        <div>
                                            <p class="text-[10.5px] font-black uppercase tracking-wider text-slate-900">Dirección Financiera / Contador Público</p>
                                            <p class="text-[9.5px] text-slate-600"><?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?></p>
                                            <p class="text-[8.5px] text-slate-400 font-mono">T.P. No. 129482-T • NIT: <?php echo htmlspecialchars($entidadInfo['nit'] ?? '800149695'); ?>-1</p>
                                        </div>
                                    </div>

                                    <div class="cert-hash-box p-2.5 rounded-xl border text-[9px] space-y-1 shadow-xs" style="background-color: #f0fdfa !important; border: 1.5px solid #0d9488 !important;">
                                        <div class="flex items-center justify-between font-black uppercase tracking-wider" style="color: #0d9488 !important;">
                                            <span class="cert-hash-title flex items-center gap-1 font-bold">
                                                <span class="material-symbols-outlined text-xs">verified</span>
                                                HUELLA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256):
                                            </span>
                                            <button type="button" onclick="copyCertHash('<?php echo $certificadoHash; ?>')"
                                                title="Copiar Hash Criptográfico"
                                                class="no-print inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-white text-slate-600 border border-teal-200 hover:text-tertiary text-[9px] font-bold shadow-xs transition-all cursor-pointer">
                                                <span class="material-symbols-outlined text-[11px]" id="copyIcon">content_copy</span>
                                                <span id="copyLabel">Copiar</span>
                                            </button>
                                        </div>
                                        <div class="font-mono text-[8pt] font-black text-slate-900 break-all select-all pt-0.5" style="color: #0f172a !important;">
                                            <?php echo $certificadoHash; ?>
                                        </div>
                                        <p class="text-[8px] text-slate-500 leading-snug pt-0.5 border-t border-teal-200/60">
                                            Esta firma criptográfica certifica la inmutabilidad y autenticidad del presente certificado contable en la plataforma LIHO.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- 6. Cláusula Legal Exoneración de Firma Autógrafa -->
                            <div class="text-[8.5px] text-slate-500 text-center leading-tight">
                                De conformidad con el <strong>Artículo 10 del Decreto 836 de 1991</strong> (compilado en el Decreto Único Reglamentario 1625 de 2016), este certificado no requiere firma autógrafa para su plena validez jurídica y probatoria ante la Dirección de Impuestos y Aduanas Nacionales (DIAN).
                            </div>

                            <!-- Pie de Página Institucional - Hoja 1 -->
                            <div class="pt-1.5 border-t border-slate-300 flex items-center justify-between text-[8pt] text-slate-500">
                                <span>IPS Hernán Ocazionez y Cía S.A.S. - Certificado Tributario Oficial Art. 381 E.T.</span>
                                <span class="font-black text-slate-700">Página 1 de 2 • Certificado Oficial</span>
                            </div>

                        </div>
                    </div>

                    <!-- DIVISOR DE PÁGINA ELEGANTE EN PANTALLA -->
                    <div id="sheetDivider" class="no-print w-full max-w-[1000px] flex items-center justify-center gap-4 my-8">
                        <div class="h-px bg-slate-300 dark:bg-slate-700 flex-1 max-w-[220px]"></div>
                        <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-black uppercase tracking-wider shadow-sm border border-slate-200 dark:border-slate-700">
                            <span class="material-symbols-outlined text-sm text-tertiary">calendar_view_month</span>
                            Hoja 2 de 2 • Anexo Cronológico de Liquidaciones
                        </span>
                        <div class="h-px bg-slate-300 dark:bg-slate-700 flex-1 max-w-[220px]"></div>
                    </div>

                    <!-- =========================================================================================
                         HOJA 2: ANEXO DETALLADO CRONOLÓGICO DE LIQUIDACIONES (HOJA DE PAPEL FÍSICA)
                         ========================================================================================= -->
                    <div id="sheetPage2" class="certificate-paper-sheet bg-white w-full max-w-[1000px] rounded-2xl shadow-xl border border-slate-300/80 p-6 sm:p-10 space-y-4 text-slate-900 relative">
                        
                        <div class="official-annex-page">
                            
                            <!-- Encabezado del Anexo con Barra Navy Corporativa -->
                            <div class="cert-header-navy rounded-xl p-3 mb-3 flex items-center justify-between text-white shadow-xs" style="background-color: #0f172a !important;">
                                <div class="flex items-center gap-3">
                                    <div class="p-1 rounded-xl bg-slate-900/80 border border-slate-700/60 shadow-xs shrink-0">
                                        <img src="<?php echo htmlspecialchars($headerLogoPath); ?>" 
                                            alt="Logo Entidad" 
                                            class="h-9 w-auto object-contain print-logo" 
                                            onerror="this.src='assets/img/Ho_Fondo_Osc.png'" />
                                    </div>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-black uppercase tracking-wider text-white flex items-center gap-1.5" style="color: #ffffff !important;">
                                            <span class="material-symbols-outlined text-base text-[#00c1be]" style="color: #00c1be !important;">calendar_view_month</span>
                                            2. ANEXO: RELACIÓN CRONOLÓGICA DE TURNOS Y LIQUIDACIONES
                                        </h4>
                                        <p class="text-[10px] text-slate-300 mt-0.5" style="color: #cbd5e1 !important;">
                                            Especialista: <strong class="text-white" style="color: #ffffff !important;"><?php echo htmlspecialchars($medicoDatos['nombre'] ?? ''); ?></strong> (CC: <?php echo htmlspecialchars($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C')); ?>) • Año Gravable <?php echo $selectedAnio; ?>
                                        </p>
                                    </div>
                                </div>
                                <span class="cert-badge-teal px-2.5 py-1 rounded-lg text-[9.5px] font-mono font-black text-white shrink-0" style="background-color: #0d9488 !important; color: #ffffff !important;">
                                    <?php echo count($liquidaciones); ?> liquidaciones
                                </span>
                            </div>

                            <div class="overflow-x-auto rounded-xl border border-slate-300 shadow-xs">
                                <table class="cert-table-navy w-full text-[9.5px] text-left">
                                    <thead class="bg-[#0f172a] text-white font-bold border-b border-[#0f172a] uppercase tracking-wider" style="background-color: #0f172a !important; color: #ffffff !important;">
                                        <tr>
                                            <th class="px-2.5 py-2 text-white" style="color: #ffffff !important;">ID / Periodo</th>
                                            <th class="px-2.5 py-2 text-right text-white" style="color: #ffffff !important;">Honorarios Brutos</th>
                                            <th class="px-2.5 py-2 text-right text-white" style="color: #ffffff !important;">Rete 383</th>
                                            <th class="px-2.5 py-2 text-right text-white" style="color: #ffffff !important;">Rete Trad.</th>
                                            <th class="px-2.5 py-2 text-right text-white" style="color: #ffffff !important;">Seg. Social</th>
                                            <th class="px-2.5 py-2 text-right text-white" style="color: #ffffff !important;">Neto Pagado</th>
                                            <th class="px-2.5 py-2 text-center text-white" style="color: #ffffff !important;">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 font-mono font-medium">
                                        <?php 
                                        $fillRow = false;
                                        foreach ($liquidaciones as $liq): 
                                            $segSocRow = (float)$liq['ded_salud'] + (float)$liq['ded_pension'] + (float)$liq['ded_arl'];
                                            $rowBg = $fillRow ? 'bg-slate-50/60' : 'bg-white';
                                            $fillRow = !$fillRow;
                                        ?>
                                        <tr class="<?php echo $rowBg; ?> hover:bg-slate-100/60">
                                            <td class="px-2.5 py-1.5 text-slate-700 font-sans">
                                                <span class="font-bold font-mono text-slate-900">#<?php echo $liq['id']; ?></span>
                                                <span class="text-[8.5px] text-slate-500 block"><?php echo htmlspecialchars($liq['periodo_desde']); ?> al <?php echo htmlspecialchars($liq['periodo_hasta']); ?></span>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right font-bold text-slate-900">
                                                $<?php echo number_format((float)$liq['total_factura'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right text-slate-700">
                                                $<?php echo number_format((float)$liq['ded_rete_383'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right text-slate-700">
                                                $<?php echo number_format((float)$liq['ded_retencion'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right text-slate-700">
                                                $<?php echo number_format($segSocRow, 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right font-black text-teal-800">
                                                $<?php echo number_format((float)$liq['total_a_pagar'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-1.5 text-center font-sans">
                                                <span class="cert-status-badge inline-block px-2 py-0.5 rounded-full text-[8.5px] font-extrabold uppercase <?php echo ($liq['estado'] === 'APROBADA') ? 'bg-emerald-50 text-emerald-800 border border-emerald-300' : 'bg-amber-50 text-amber-800 border border-amber-300'; ?>">
                                                    <?php echo htmlspecialchars($liq['estado']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="cert-row-total-navy bg-[#0f172a] text-white font-bold border-t-2 border-[#0f172a]" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                        <tr>
                                            <td class="px-2.5 py-2 text-white uppercase text-[9px] font-sans" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                Totales del Periodo
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-mono font-bold text-white" style="background-color: #0f172a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                $<?php echo number_format($totales['bruto'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-mono text-slate-200" style="background-color: #0f172a !important; color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important;">
                                                $<?php echo number_format($totales['rete_383'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-mono text-slate-200" style="background-color: #0f172a !important; color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important;">
                                                $<?php echo number_format($totales['rete_fuente'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-mono text-slate-200" style="background-color: #0f172a !important; color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important;">
                                                $<?php echo number_format($totales['salud'] + $totales['pension'] + $totales['arl'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-mono font-black text-white" style="background-color: #0d9488 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important;">
                                                $<?php echo number_format($totales['neto_pagado'], 0, ',', '.'); ?>
                                            </td>
                                            <td class="px-2.5 py-2 text-center font-mono text-[9px] text-teal-100" style="background-color: #0f172a !important; color: #ccfbf1 !important; -webkit-text-fill-color: #ccfbf1 !important;">
                                                <?php echo $totales['cantidad_liq']; ?> turnos
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Pie de Página Institucional - Hoja 2 -->
                            <div class="mt-4 pt-2 border-t border-slate-300 flex items-center justify-between text-[8pt] text-slate-500">
                                <span>Sistema LIHO • Verificación: <strong class="font-mono text-slate-700"><?php echo substr($certificadoHash, 0, 16); ?>...</strong></span>
                                <span class="font-black text-slate-700">Página 2 de 2 • Anexo Detallado</span>
                            </div>

                        </div>
                    </div>

                </div>

            </div>

        <?php endif; ?>

    </main>

    <!-- Toast Flotante de Notificación -->
    <div id="toastNotification" class="no-print fixed bottom-6 right-6 z-50 transform translate-y-16 opacity-0 pointer-events-none transition-all duration-300 flex items-center gap-2.5 px-4 py-3 rounded-2xl bg-slate-900/95 dark:bg-white/95 text-white dark:text-slate-900 shadow-2xl border border-slate-700 dark:border-slate-200 backdrop-blur-md">
        <span class="material-symbols-outlined text-emerald-500 text-lg" id="toastIcon">check_circle</span>
        <span class="text-xs font-bold" id="toastMessage">Acción completada con éxito</span>
    </div>

    <!-- =========================================================================================
         MODAL INTERACTIVO: CONFIRMACIÓN Y VISTA PREVIA DEL CORREO OFICIAL AL MÉDICO
         ========================================================================================= -->
    <div id="modalEnviarCorreoCertificado" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-950/80 backdrop-blur-sm p-3 sm:p-6 flex items-center justify-center animate-fade-in" role="dialog" aria-modal="true">
        <div class="relative w-full max-w-4xl bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden my-auto">
            
            <!-- Header del Modal -->
            <div class="px-6 py-4 bg-gradient-to-r from-slate-900 via-slate-800 to-teal-950 text-white flex items-center justify-between border-b border-slate-700/60">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-teal-500/20 border border-teal-400/40 flex items-center justify-center text-teal-300 shrink-0">
                        <span class="material-symbols-outlined text-xl">outgoing_mail</span>
                    </div>
                    <div>
                        <h3 class="text-sm sm:text-base font-black tracking-wide flex items-center gap-2">
                            <span>Enviar Certificado Tributario al Especialista</span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-teal-500/20 text-teal-300 border border-teal-400/30">Año <?php echo $selectedAnio; ?></span>
                        </h3>
                        <p class="text-xs text-slate-300">Confirme los datos del destinatario y revise la vista previa del mensaje oficial antes del envío.</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEnviarCorreo()" class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 text-slate-300 hover:text-white flex items-center justify-center transition-all cursor-pointer">
                    <span class="material-symbols-outlined text-base">close</span>
                </button>
            </div>

            <!-- Cuerpo del Modal -->
            <div class="p-6 space-y-5 max-h-[calc(85vh-130px)] overflow-y-auto custom-scrollbar">

                <!-- 1. ALERTA DE CONFIRMACIÓN EXPLÍCITA -->
                <div class="p-4 rounded-2xl bg-teal-50/80 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800/60 flex items-start gap-3.5">
                    <div class="w-9 h-9 rounded-xl bg-teal-500/10 dark:bg-teal-500/20 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-xl">help</span>
                    </div>
                    <div class="flex-1 text-xs">
                        <h4 class="font-black text-slate-900 dark:text-white text-sm">¿Está seguro de enviar este Certificado Tributario Oficial?</h4>
                        <p class="text-slate-600 dark:text-slate-300 mt-1 leading-relaxed">
                            El sistema generará el archivo PDF oficial con firma digital contable y lo remitirá a la bandeja de entrada del especialista <strong><?php echo htmlspecialchars($medicoDatos['nombre'] ?? 'Médico Especialista'); ?></strong> (CC: <strong><?php echo htmlspecialchars($medicoDatos['cedula'] ?? ltrim($selectedCedula, 'C')); ?></strong>). A continuación puede revisar la vista previa exacta del correo oficial antes de realizar la entrega.
                        </p>
                    </div>
                </div>

                <!-- Valores Inmutables de Control de Envío (No editables en el modal) -->
                <input type="hidden" id="modalInputCorreoDestino" value="<?php echo htmlspecialchars($medicoDatos['email'] ?? ''); ?>">
                <input type="hidden" id="modalInputAsunto" value="[LIHO] Certificado de Ingresos y Retenciones <?php echo $selectedAnio; ?> - <?php echo htmlspecialchars($medicoDatos['nombre'] ?? 'Médico Especialista'); ?>">

                <!-- 2. FICHA DE ARCHIVO PDF ADJUNTO -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/70 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-red-500/10 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800/40 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-2xl">picture_as_pdf</span>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-black text-slate-900 dark:text-white font-mono">Certificado_Tributario_<?php echo $selectedAnio; ?>_<?php echo preg_replace('/[^A-Za-z0-9]/', '', $selectedCedula); ?>.pdf</span>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-teal-100 text-teal-800 dark:bg-teal-900/60 dark:text-teal-300">2 Hojas Carta</span>
                            </div>
                            <p class="text-[10.5px] text-slate-500 dark:text-slate-400 mt-0.5">
                                Hoja 1: Certificado Oficial DIAN Art. 381/383 • Hoja 2: Anexo Detallado con <?php echo count($liquidaciones); ?> liquidaciones
                            </p>
                            <div class="inline-flex items-center gap-1.5 mt-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/50 text-[10.5px] font-bold">
                                <span class="material-symbols-outlined text-xs text-emerald-600">lock</span>
                                <span>PDF protegido con contraseña: <strong>Número de documento de identidad del especialista</strong> (sin puntos ni espacios)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Botón para ver/descargar PDF directo -->
                    <a href="?anio=<?php echo $selectedAnio; ?>&medico_cedula=<?php echo urlencode($selectedCedula); ?>&entidad_id=<?php echo $selectedEntidadId; ?>&export=pdf" target="_blank"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-600 hover:bg-slate-100 dark:hover:bg-slate-600 text-xs font-bold transition-all shrink-0 cursor-pointer shadow-xs">
                        <span class="material-symbols-outlined text-sm text-red-500">visibility</span>
                        <span>Ver PDF Oficial</span>
                    </a>
                </div>

                <!-- 3. CONTENEDOR VISTA PREVIA DEL CORREO ELECTRÓNICO (SIMULADOR DE EMAIL CLIENT) -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="text-[11px] font-black uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm text-tertiary">preview</span>
                            Vista Previa Fidedigna del Correo Electrónico
                        </label>
                        <span class="text-[10px] text-slate-400 font-mono">Bandeja de Entrada del Especialista</span>
                    </div>

                    <?php 
                    require_once __DIR__ . '/includes/config_helper.php';
                    if (estanCorreosMedicosBloqueados()): 
                        $cfgCert = obtenerConfiguracionSistema();
                    ?>
                    <div class="p-3.5 mb-2.5 rounded-2xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300 dark:border-amber-700/70 text-xs text-amber-900 dark:text-amber-200 flex items-start gap-2.5">
                        <span class="material-symbols-outlined text-base text-amber-600 dark:text-amber-400 shrink-0 mt-0.5">shield</span>
                        <div>
                            <strong>MODO DESARROLLO ACTIVO:</strong> El envío a médicos reales está bloqueado preventivamente. Este certificado no saldrá a la bandeja del médico, sino que se redirigirá a <code><?php echo htmlspecialchars($cfgCert['email_test_redireccion'] ?? 'soporte'); ?></code> con el prefijo [TEST DESARROLLO].
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Cliente de Correo Simulado -->
                    <div class="rounded-2xl border border-slate-300 dark:border-slate-700 shadow-md overflow-hidden bg-white text-slate-800">
                        
                        <!-- Barra de Cabecera del Cliente de Correo -->
                        <div class="bg-slate-100 dark:bg-slate-800/90 px-4 py-3 border-b border-slate-200 dark:border-slate-700 text-xs space-y-1.5">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase w-12 shrink-0">De:</span>
                                    <span class="font-bold text-slate-800 dark:text-slate-200">Hernán Ocazionez - LIHO &lt;rihoticketsho@gmail.com&gt;</span>
                                </div>
                                <span class="text-[10px] text-slate-400 font-mono"><?php echo date('d/m/Y h:i A'); ?></span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase w-12 shrink-0">Para:</span>
                                    <span id="previewDestinatarioText" class="font-bold font-mono text-teal-700 dark:text-teal-400">
                                        <?php echo htmlspecialchars($medicoDatos['email'] ?: '(Sin correo registrado en la base de datos)'); ?>
                                    </span>
                                </div>
                                <?php if (!empty($medicoDatos['email'])): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 shrink-0">
                                        Registrado en BD
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 shrink-0">
                                        Sin correo en BD
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-bold text-slate-400 uppercase w-12 shrink-0">Asunto:</span>
                                <span id="previewAsuntoText" class="font-bold text-slate-900 dark:text-white">
                                    [LIHO] Certificado de Ingresos y Retenciones <?php echo $selectedAnio; ?> - <?php echo htmlspecialchars($medicoDatos['nombre'] ?? 'Médico Especialista'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Cuerpo Renderizado del Correo (HTML Oficial) -->
                        <div class="p-4 sm:p-6 bg-[#f8fafc] text-slate-900 overflow-x-auto text-xs">
                            <div class="max-w-[600px] mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden font-sans">
                                
                                <!-- Header Correo -->
                                <div style="background-color: #0f172a; padding: 20px; text-align: center; color: #ffffff;">
                                    <img src="<?php echo htmlspecialchars($headerLogoPath); ?>" alt="Logo" style="max-height: 40px; margin: 0 auto 8px auto; display: block;" onerror="this.style.display='none'" />
                                    <h3 style="margin: 0; font-size: 14px; font-weight: 900; letter-spacing: 0.5px; color: #ffffff; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>
                                    </h3>
                                    <p style="margin: 4px 0 0 0; font-size: 10.5px; font-weight: 800; color: #00c1be; text-transform: uppercase; letter-spacing: 0.8px;">
                                        LIHO - SISTEMA DE GESTIÓN TRIBUTARIA
                                    </p>
                                </div>

                                <!-- Contenido Correo -->
                                <div style="padding: 22px 18px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                                        <span style="font-size: 12.5px; font-weight: 900; color: #0f172a;">Certificado Tributario Oficial</span>
                                        <span style="background-color: #0d9488; color: #ffffff; padding: 3px 10px; border-radius: 999px; font-size: 9.5px; font-weight: 900;">
                                            AÑO GRAVABLE <?php echo $selectedAnio; ?>
                                        </span>
                                    </div>

                                    <p style="font-size: 12.5px; margin: 0 0 10px 0; color: #0f172a; line-height: 1.4;">
                                        Estimado(a) <strong>Dr(a). <?php echo htmlspecialchars($medicoDatos['nombre'] ?? 'Médico Especialista'); ?></strong>,
                                    </p>
                                    <p style="font-size: 11.5px; color: #475569; line-height: 1.5; margin: 0 0 14px 0;">
                                        La Dirección Financiera y Contabilidad de <strong><?php echo htmlspecialchars($entidadInfo['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?></strong> hace entrega formal de su <strong>Certificado Oficial de Ingresos y Retenciones</strong> para el año gravable <strong><?php echo $selectedAnio; ?></strong>, expedido en cumplimiento del <strong>Artículo 381 y 383 del Estatuto Tributario Nacional</strong>.
                                    </p>

                                    <!-- Tarjetas de Resumen Financiero -->
                                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; margin: 14px 0;">
                                        <table style="width: 100%; font-size: 11px; border-collapse: collapse;">
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 5px 0; color: #64748b; font-weight: 600;">Ingresos Brutos por Honorarios:</td>
                                                <td style="padding: 5px 0; text-align: right; font-weight: 900; font-family: monospace; color: #0f172a;">$<?php echo number_format($totales['bruto'], 2, ',', '.'); ?></td>
                                            </tr>
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 5px 0; color: #64748b; font-weight: 600;">Total Retención en la Fuente Practicada <?php echo $pctTotReteStr; ?>:</td>
                                                <td style="padding: 5px 0; text-align: right; font-weight: 900; font-family: monospace; color: #b91c1c;">$<?php echo number_format($totales['total_rete'], 2, ',', '.'); ?></td>
                                            </tr>
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 5px 0; color: #64748b; font-weight: 600;">Aportes a Seguridad Social:</td>
                                                <td style="padding: 5px 0; text-align: right; font-weight: 700; font-family: monospace; color: #475569;">$<?php echo number_format($totales['salud'] + $totales['pension'] + $totales['arl'], 2, ',', '.'); ?></td>
                                            </tr>
                                            <tr style="background-color: #f0fdfa;">
                                                <td style="padding: 7px 6px; color: #0f766e; font-weight: 900;">Total Neto Girado / Pagado <?php echo $pctNetoStr; ?>:</td>
                                                <td style="padding: 7px 6px; text-align: right; font-weight: 900; font-family: monospace; font-size: 12px; color: #0d9488;">$<?php echo number_format($totales['neto_pagado'], 2, ',', '.'); ?></td>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- Clave de Apertura de Seguridad y Adjunto -->
                                    <div style="background-color: #f0fdf4; border: 1.5px solid #22c55e; border-radius: 12px; padding: 12px 14px; margin: 14px 0;">
                                        <span style="font-size: 10.5px; font-weight: 800; color: #15803d; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px;">
                                            <span class="material-symbols-outlined text-sm text-emerald-700">lock</span>
                                            <span>DOCUMENTO PROTEGIDO CON CONTRASEÑA:</span>
                                        </span>
                                        <p style="margin: 4px 0 0 0; font-size: 11px; color: #166534; line-height: 1.4;">
                                            Por su estricta seguridad y confidencialidad fiscal, el archivo PDF oficial adjunto se encuentra cifrado. <strong>Su contraseña de apertura es su número de documento de identidad</strong> (sin puntos, guiones ni espacios).
                                        </p>
                                    </div>

                                    <!-- Cuadro Documento Adjunto -->
                                    <div style="background-color: #ffffff; border: 1.5px dashed #0d9488; border-radius: 12px; padding: 10px 12px; margin: 14px 0;">
                                        <span style="font-size: 10px; font-weight: 800; color: #0d9488; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px;">
                                            <span class="material-symbols-outlined text-sm text-teal-700">attach_file</span>
                                            <span>DOCUMENTO OFICIAL ADJUNTO:</span>
                                        </span>
                                        <p style="margin: 2px 0 0 0; font-size: 11px; font-weight: 700; color: #0f172a; font-family: monospace;">
                                            Certificado_Tributario_<?php echo $selectedAnio; ?>_<?php echo preg_replace('/[^A-Za-z0-9]/', '', $selectedCedula); ?>.pdf
                                        </p>
                                        <span style="font-size: 9.5px; color: #64748b;">Incluye Certificado Oficial DIAN y Anexo Cronológico Detallado (2 Hojas)</span>
                                    </div>

                                    <!-- Firma Digital Criptográfica -->
                                    <div style="background-color: #0f172a; border-radius: 10px; padding: 9px 11px; margin: 14px 0; color: #ffffff;">
                                        <span style="font-size: 8.5px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">HUELLA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256):</span>
                                        <div style="font-family: monospace; font-size: 8.5px; color: #38bdf8; word-break: break-all; margin-top: 3px; line-height: 1.3;">
                                            <?php echo $certificadoHash; ?>
                                        </div>
                                    </div>

                                    <p style="font-size: 9.5px; color: #64748b; line-height: 1.4; margin: 12px 0 0 0;">
                                        * De conformidad con el Artículo 10 del Decreto 836 de 1991, este certificado no requiere firma autógrafa para su plena validez probatoria.
                                    </p>
                                </div>

                                <!-- Footer Correo -->
                                <div style="background-color: #f1f5f9; padding: 12px 18px; text-align: center; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; line-height: 1.4;">
                                    © <?php echo date('Y'); ?> IPS Hernán Ocazionez y Cía S.A.S. • Portal Operativo LIHO
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MENSAJE DE ERROR DINÁMICO EN EL MODAL -->
                <div id="modalEnvioErrorBox" class="hidden p-3 rounded-xl bg-red-50 dark:bg-red-950/50 border border-red-200 dark:border-red-800/60 text-xs text-red-700 dark:text-red-300 flex items-center gap-2">
                    <span class="material-symbols-outlined text-base shrink-0">error</span>
                    <span id="modalEnvioErrorMsg">Error al procesar el envío.</span>
                </div>

            </div>

            <!-- Footer del Modal con Botones -->
            <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/60 border-t border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                    <span class="material-symbols-outlined text-sm text-emerald-500">lock</span>
                    <span>Envío seguro vía SMTP cifrado TLS • Con registro en auditoría</span>
                </div>

                <div class="flex items-center gap-2.5 w-full sm:w-auto justify-end">
                    <button type="button" onclick="cerrarModalEnviarCorreo()" id="btnCancelarEnvioCorreo"
                        class="w-full sm:w-auto px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-all cursor-pointer">
                        Cancelar
                    </button>
                    <button type="button" onclick="ejecutarEnvioCorreoCertificado()" id="btnConfirmarEnvioCorreo"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 via-teal-600 to-primary hover:opacity-95 text-white text-xs font-black shadow-md shadow-teal-500/20 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer">
                        <span class="material-symbols-outlined text-base" id="iconConfirmarEnvio">send</span>
                        <span id="labelConfirmarEnvio">Confirmar y Enviar Correo</span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <div class="no-print">
        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>

    <script>
        // 1. Selector de Año
        function selectYear(anio) {
            document.getElementById('hiddenInputAnio').value = anio;
            document.getElementById('filterMasterForm').submit();
        }

        // 2. Selector de Médico
        function selectDoctor(cedula) {
            document.getElementById('hiddenInputMedico').value = cedula;
            document.getElementById('filterMasterForm').submit();
        }

        // 3. Selector de Entidad
        function selectEntity(entidadId) {
            document.getElementById('hiddenInputEntidad').value = entidadId;
            document.getElementById('hiddenInputMedico').value = '';
            document.getElementById('filterMasterForm').submit();
        }

        // 4. Manejo Interactivo de Popovers / Dropdowns Personalizados
        document.addEventListener('DOMContentLoaded', function() {
            const medicoBtn = document.getElementById('medicoComboboxBtn');
            const medicoMenu = document.getElementById('medicoComboboxMenu');
            const medicoChevron = document.getElementById('medicoChevron');
            const medicoSearch = document.getElementById('medicoSearchInput');

            const entidadBtn = document.getElementById('entidadDropdownBtn');
            const entidadMenu = document.getElementById('entidadDropdownMenu');
            const entidadChevron = document.getElementById('entidadChevron');

            // Toggle Médico Combobox
            if (medicoBtn && medicoMenu) {
                medicoBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = medicoMenu.classList.contains('is-open');
                    closeAllPopovers();
                    if (!isOpen) {
                        medicoMenu.classList.add('is-open');
                        if (medicoChevron) medicoChevron.style.transform = 'rotate(180deg)';
                        if (medicoSearch) {
                            setTimeout(() => medicoSearch.focus(), 80);
                        }
                    }
                });

                // Buscador en vivo de médicos
                if (medicoSearch) {
                    medicoSearch.addEventListener('input', function() {
                        const term = this.value.toLowerCase().trim();
                        const items = document.querySelectorAll('.medico-option-item');
                        items.forEach(item => {
                            const name = item.getAttribute('data-nombre') || '';
                            const cedula = item.getAttribute('data-cedula') || '';
                            if (name.includes(term) || cedula.includes(term)) {
                                item.style.display = 'flex';
                            } else {
                                item.style.display = 'none';
                            }
                        });
                    });
                }
            }

            // Toggle Entidad Dropdown
            if (entidadBtn && entidadMenu) {
                entidadBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = entidadMenu.classList.contains('is-open');
                    closeAllPopovers();
                    if (!isOpen) {
                        entidadMenu.classList.add('is-open');
                        if (entidadChevron) entidadChevron.style.transform = 'rotate(180deg)';
                    }
                });
            }

            function closeAllPopovers() {
                if (medicoMenu) medicoMenu.classList.remove('is-open');
                if (medicoChevron) medicoChevron.style.transform = 'rotate(0deg)';
                if (entidadMenu) entidadMenu.classList.remove('is-open');
                if (entidadChevron) entidadChevron.style.transform = 'rotate(0deg)';
                // Cerrar también cualquier menú abierto del navbar central o usuario
                document.querySelectorAll('.nav-dropdown-menu.is-open').forEach(m => m.classList.remove('is-open'));
                const userDropdown = document.getElementById('userMenuDropdown');
                if (userDropdown) userDropdown.classList.remove('user-dropdown-active');
            }

            // Cerrar al hacer click fuera
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#medicoComboboxContainer') && !e.target.closest('#entidadDropdownContainer')) {
                    closeAllPopovers();
                }
            });

            // Cerrar con tecla Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllPopovers();
                }
            });

            // 5. Animación fluida de conteo numérico en tarjetas KPI
            const counters = document.querySelectorAll('.counter-number');
            counters.forEach(counter => {
                const target = parseFloat(counter.getAttribute('data-target')) || 0;
                if (target === 0) return;
                
                let current = 0;
                const duration = 800; // ms
                const steps = 30;
                const increment = target / steps;
                const stepTime = duration / steps;
                
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    counter.textContent = '$' + Math.round(current).toLocaleString('es-CO');
                }, stepTime);
            });
        });

        // 6. Copiar Hash Criptográfico al portapapeles con Toast
        function copyCertHash(hash) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(hash).then(() => {
                    showToast('Hash SHA-256 copiado al portapapeles');
                });
            } else {
                const tempInput = document.createElement('input');
                tempInput.value = hash;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                showToast('Hash SHA-256 copiado al portapapeles');
            }
        }

        function showToast(msg) {
            const toast = document.getElementById('toastNotification');
            const msgEl = document.getElementById('toastMessage');
            if (toast && msgEl) {
                msgEl.textContent = msg;
                toast.classList.remove('translate-y-16', 'opacity-0', 'pointer-events-none');
                toast.classList.add('translate-y-0', 'opacity-100');
                
                setTimeout(() => {
                    toast.classList.remove('translate-y-0', 'opacity-100');
                    toast.classList.add('translate-y-16', 'opacity-0', 'pointer-events-none');
                }, 2800);
            }
        }

        // 7. Visor Interactivo de Hojas Oficiales
        function switchSheetView(mode) {
            const s1 = document.getElementById('sheetPage1');
            const s2 = document.getElementById('sheetPage2');
            const div = document.getElementById('sheetDivider');
            const bAll = document.getElementById('btnViewAll');
            const bP1 = document.getElementById('btnViewP1');
            const bP2 = document.getElementById('btnViewP2');

            if (!s1 || !s2) return;

            [bAll, bP1, bP2].forEach(b => {
                if (!b) return;
                b.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-xs');
                b.classList.add('text-slate-600', 'dark:text-slate-400');
            });

            if (mode === 'p1') {
                s1.style.display = 'block';
                s2.style.display = 'none';
                if (div) div.style.display = 'none';
                if (bP1) {
                    bP1.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-xs');
                    bP1.classList.remove('text-slate-600', 'dark:text-slate-400');
                }
            } else if (mode === 'p2') {
                s1.style.display = 'none';
                s2.style.display = 'block';
                if (div) div.style.display = 'none';
                if (bP2) {
                    bP2.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-xs');
                    bP2.classList.remove('text-slate-600', 'dark:text-slate-400');
                }
            } else {
                s1.style.display = 'block';
                s2.style.display = 'block';
                if (div) div.style.display = 'flex';
                if (bAll) {
                    bAll.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-xs');
                    bAll.classList.remove('text-slate-600', 'dark:text-slate-400');
                }
            }
        }

        // 8. Preparación Automática de Impresión (Neutralización de Modo Oscuro y Ambas Hojas en PDF)
        function prepareAndPrint() {
            const s1 = document.getElementById('sheetPage1');
            const s2 = document.getElementById('sheetPage2');
            if (s1) s1.style.display = 'block';
            if (s2) s2.style.display = 'block';

            const wasDark = document.documentElement.classList.contains('dark');
            if (wasDark) {
                document.documentElement.classList.remove('dark');
            }
            setTimeout(() => {
                window.print();
                if (wasDark) {
                    document.documentElement.classList.add('dark');
                }
            }, 60);
        }

        window.addEventListener('beforeprint', function() {
            const s1 = document.getElementById('sheetPage1');
            const s2 = document.getElementById('sheetPage2');
            if (s1) s1.style.display = 'block';
            if (s2) s2.style.display = 'block';

            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-was-dark', 'true');
            }
        });

        window.addEventListener('afterprint', function() {
            if (document.documentElement.getAttribute('data-was-dark') === 'true') {
                document.documentElement.classList.add('dark');
                document.documentElement.removeAttribute('data-was-dark');
            }
        });

        // 9. Manejo del Modal de Envío de Certificado por Correo al Médico
        function abrirModalEnviarCorreo() {
            const modal = document.getElementById('modalEnviarCorreoCertificado');
            if (!modal) return;
            modal.classList.remove('hidden');

            // Limpiar errores previos
            const errBox = document.getElementById('modalEnvioErrorBox');
            if (errBox) errBox.classList.add('hidden');

            const btnConfirm = document.getElementById('btnConfirmarEnvioCorreo');
            const inpMail = document.getElementById('modalInputCorreoDestino');
            const emailVal = inpMail ? inpMail.value.trim() : '';

            // Si el médico no tiene correo en BD, deshabilitar botón y alertar
            if (!emailVal) {
                if (btnConfirm) {
                    btnConfirm.disabled = true;
                    btnConfirm.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (errBox) {
                    const errText = document.getElementById('modalEnvioErrorMsg');
                    if (errText) errText.textContent = 'El especialista no cuenta con un correo electrónico registrado en la base de datos. No es posible realizar el envío.';
                    errBox.classList.remove('hidden');
                }
            } else {
                if (btnConfirm) {
                    btnConfirm.disabled = false;
                    btnConfirm.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }
        }

        function cerrarModalEnviarCorreo() {
            const modal = document.getElementById('modalEnviarCorreoCertificado');
            if (modal) modal.classList.add('hidden');
        }

        async function ejecutarEnvioCorreoCertificado() {
            const inpMail = document.getElementById('modalInputCorreoDestino');
            const inpSubj = document.getElementById('modalInputAsunto');
            const btnConfirm = document.getElementById('btnConfirmarEnvioCorreo');
            const btnCancel = document.getElementById('btnCancelarEnvioCorreo');
            const iconConfirm = document.getElementById('iconConfirmarEnvio');
            const labelConfirm = document.getElementById('labelConfirmarEnvio');
            const errBox = document.getElementById('modalEnvioErrorBox');
            const errText = document.getElementById('modalEnvioErrorMsg');

            if (errBox) errBox.classList.add('hidden');

            const email = inpMail ? inpMail.value.trim() : '';
            const asunto = inpSubj ? inpSubj.value.trim() : '';

            // Validación de correo registrado
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email || !emailRegex.test(email)) {
                if (errBox && errText) {
                    errText.textContent = 'El especialista no cuenta con una dirección de correo válida registrada en la base de datos de LIHO.';
                    errBox.classList.remove('hidden');
                }
                return;
            }

            // Estado de carga y bloqueo de botones anti-doble clic
            if (btnConfirm) btnConfirm.disabled = true;
            if (btnCancel) btnCancel.disabled = true;
            if (iconConfirm) {
                iconConfirm.textContent = 'progress_activity';
                iconConfirm.classList.add('animate-spin');
            }
            if (labelConfirm) {
                labelConfirm.textContent = 'Generando PDF y enviando...';
            }

            try {
                const formData = new FormData();
                formData.append('action', 'enviar_correo');
                formData.append('correo_destino', email);
                formData.append('asunto', asunto);

                const currentUrl = new URL(window.location.href);
                const anio = currentUrl.searchParams.get('anio') || '<?php echo $selectedAnio; ?>';
                const medico = currentUrl.searchParams.get('medico_cedula') || '<?php echo $selectedCedula; ?>';
                const entidad = currentUrl.searchParams.get('entidad_id') || '<?php echo $selectedEntidadId; ?>';

                const postUrl = `certificados_tributarios.php?anio=${encodeURIComponent(anio)}&medico_cedula=${encodeURIComponent(medico)}&entidad_id=${encodeURIComponent(entidad)}`;

                const response = await fetch(postUrl, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data && data.success) {
                    cerrarModalEnviarCorreo();
                    showToast(data.message || 'Certificado Oficial enviado exitosamente');
                } else {
                    if (errBox && errText) {
                        errText.textContent = data.message || 'Ocurrió un error inesperado al enviar el correo.';
                        errBox.classList.remove('hidden');
                    }
                }
            } catch (err) {
                if (errBox && errText) {
                    errText.textContent = 'Error de comunicación con el servidor: ' + err.message;
                    errBox.classList.remove('hidden');
                }
            } finally {
                if (btnConfirm) btnConfirm.disabled = false;
                if (btnCancel) btnCancel.disabled = false;
                if (iconConfirm) {
                    iconConfirm.textContent = 'send';
                    iconConfirm.classList.remove('animate-spin');
                }
                if (labelConfirm) {
                    labelConfirm.textContent = 'Confirmar y Enviar Correo';
                }
            }
        }
    </script>

</body>
</html>
