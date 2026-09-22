<?php
/**
 * ============================================================================
 * MÓDULO DE ALERTA MÉDICOS - PACIENTES EN CURSO NO FINALIZADOS
 * ============================================================================
 * IPS Hernán Ocazionez y Cía S.A.S. - Sistema LIHO
 * 
 * Monitoreo en tiempo real de pacientes en estado 'En Curso' (PROTEO - SQL Server),
 * resumen agrupado por médicos, exportación a Excel nativo (.xls), envío de
 * alertas y recordatorios vía SMTP (con destinatario de prueba juane6462@gmail.com)
 * y soporte para horarios múltiples y ejecución programada en Windows Task Scheduler.
 */

date_default_timezone_set('America/Bogota');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Control de Autenticación (Solo en peticiones web)
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: index.php");
        exit;
    }

    require_once __DIR__ . '/includes/permisos_helper.php';
    $userId     = $_SESSION['user_id'] ?? 0;
    $userRole   = $_SESSION['user_role'] ?? 'MÉDICO';
    $userRoleId = $_SESSION['user_role_id'] ?? 3;
    $userName   = $_SESSION['user_name'] ?? 'Usuario';

    // Verificar permiso del módulo
    if (!tienePermisoModulo($userId, $userRoleId, 'alerta_medicos')) {
        header("Location: dashboard.php?error=no_permission");
        exit;
    }
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/conexion_external.php';
require_once __DIR__ . '/includes/smtp_mailer.php';
require_once __DIR__ . '/includes/config_helper.php';

// ============================================================================
// FUNCIONES AUXILIARES Y GESTIÓN DE CONFIGURACIÓN / LOGS
// ============================================================================

function obtenerConfiguracionAlerta() {
    $configFile = __DIR__ . '/config/alerta_medicos_config.json';
    $default = [
        'fecha_modo' => 'rango_fijo',
        'fecha_inicio' => '2026-05-01',
        'fecha_fin' => '2026-08-31',
        'tipo_destinatario' => 'directora_medica',
        'tipos_destinatarios' => ['directora_medica'],
        'destinatarios' => ['dirmedica@hernanocazionez.com'],
        'asunto_personalizado' => 'ALERTA: Pacientes En Curso No Finalizados',
        'programacion' => [
            'frecuencia' => 'varias_veces',
            'hora_unica' => '08:00',
            'horas' => ['08:00', '13:00', '18:00'],
            'intervalo_horas' => 4,
            'dias_activos' => ['1', '2', '3', '4', '5'],
            'validar_horario' => false
        ],
        'smtp' => [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'rihoticketsho@gmail.com',
            'password' => 'vjmp vlpv zxip kblx',
            'from_name' => 'Sistema de Gestión LIHO - Alertas Clínicas'
        ]
    ];

    if (file_exists($configFile)) {
        $json = json_decode(file_get_contents($configFile), true);
        if (is_array($json)) {
            $res = array_merge($default, $json);
            if (isset($json['programacion']) && is_array($json['programacion'])) {
                $res['programacion'] = array_merge($default['programacion'], $json['programacion']);
                if (isset($json['programacion']['horas']) && is_array($json['programacion']['horas'])) {
                    $res['programacion']['horas'] = array_values($json['programacion']['horas']);
                }
                if (isset($json['programacion']['dias_activos']) && is_array($json['programacion']['dias_activos'])) {
                    $res['programacion']['dias_activos'] = array_values($json['programacion']['dias_activos']);
                }
            }
            if (isset($json['tipos_destinatarios']) && is_array($json['tipos_destinatarios'])) {
                $res['tipos_destinatarios'] = array_values($json['tipos_destinatarios']);
            } elseif (isset($res['tipo_destinatario'])) {
                $res['tipos_destinatarios'] = [$res['tipo_destinatario']];
            } else {
                $res['tipos_destinatarios'] = ['directora_medica'];
            }
            if (isset($json['destinatarios']) && is_array($json['destinatarios'])) {
                $res['destinatarios'] = array_values($json['destinatarios']);
            }
            if (isset($json['smtp']) && is_array($json['smtp'])) {
                $res['smtp'] = array_merge($default['smtp'], $json['smtp']);
            }
            return $res;
        }
    }
    return $default;
}

function obtenerCorreosDestinatariosPredefinidos() {
    $conn = obtenerConexionLIHO();
    $rolesMap = [];
    $directora = 'dirmedica@hernanocazionez.com';

    if ($conn) {
        $sqlRoles = "SELECT id, nombre FROM dbo.roles WITH (NOLOCK) WHERE estado = 1 AND id <> 3 ORDER BY id ASC";
        $stmtRoles = sqlsrv_query($conn, $sqlRoles);
        if ($stmtRoles) {
            while ($r = sqlsrv_fetch_array($stmtRoles, SQLSRV_FETCH_ASSOC)) {
                $rId = intval($r['id']);
                $rolesMap[$rId] = [
                    'id' => $rId,
                    'nombre' => trim($r['nombre']),
                    'emails' => []
                ];
            }
        }

        // Consultar usuarios activos por rol
        $sqlU = "SELECT u.rol_id, LOWER(LTRIM(RTRIM(u.email))) AS email 
                 FROM dbo.usuarios u WITH (NOLOCK) 
                 WHERE u.estado = 1 AND u.email IS NOT NULL AND u.email <> ''";
        $stmtU = sqlsrv_query($conn, $sqlU);
        if ($stmtU) {
            while ($u = sqlsrv_fetch_array($stmtU, SQLSRV_FETCH_ASSOC)) {
                $rId = intval($u['rol_id']);
                $em = trim((string)$u['email']);
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    if (isset($rolesMap[$rId])) {
                        if (!in_array($em, $rolesMap[$rId]['emails'])) {
                            $rolesMap[$rId]['emails'][] = $em;
                        }
                    }
                }
            }
        }
    }

    if (empty($rolesMap)) {
        $rolesMap[1] = ['id' => 1, 'nombre' => 'Admin', 'emails' => ['coordinacionsistemas@hernanocazionez.com', 'dirasistencial@hernanocazionez.com', 'juane6462@gmail.com']];
        $rolesMap[2] = ['id' => 2, 'nombre' => 'Financiero', 'emails' => []];
    }

    $adminEmails = $rolesMap[1]['emails'] ?? ['coordinacionsistemas@hernanocazionez.com', 'dirasistencial@hernanocazionez.com', 'juane6462@gmail.com'];
    $financieroEmails = $rolesMap[2]['emails'] ?? [];
    $directoraYAdmins = array_values(array_unique(array_merge([$directora], $adminEmails)));

    return [
        'directora_medica'   => [$directora],
        'admin_emails'       => $adminEmails,
        'financiero_emails'  => $financieroEmails,
        'roles'              => $rolesMap,
        'directora_y_admins' => $directoraYAdmins
    ];
}

function guardarConfiguracionAlerta($nuevaConfig) {
    $configFile = __DIR__ . '/config/alerta_medicos_config.json';
    $actual = obtenerConfiguracionAlerta();

    $merged = $actual;
    foreach ($nuevaConfig as $key => $val) {
        if ($key === 'programacion' && is_array($val)) {
            $merged['programacion'] = array_merge($actual['programacion'] ?? [], $val);
            if (isset($val['horas']) && is_array($val['horas'])) {
                $merged['programacion']['horas'] = array_values(array_unique($val['horas']));
            }
            if (isset($val['dias_activos']) && is_array($val['dias_activos'])) {
                $merged['programacion']['dias_activos'] = array_values(array_unique($val['dias_activos']));
            }
        } elseif ($key === 'tipos_destinatarios' && is_array($val)) {
            $merged['tipos_destinatarios'] = array_values(array_unique($val));
        } elseif ($key === 'destinatarios' && is_array($val)) {
            $merged['destinatarios'] = array_values(array_unique($val));
        } else {
            $merged[$key] = $val;
        }
    }

    return file_put_contents($configFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function registrarLogAlerta($mensaje) {
    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $fecha = date('d/m/Y h:i:s A');
    $linea = "[$fecha] $mensaje\n";
    $archivoLog = $logDir . '/alerta_medicos_' . date('Y-m') . '.log';
    @file_put_contents($archivoLog, $linea, FILE_APPEND);
}

// Catálogo de Médicos de la Base de Datos LIHO (Cruce de correo oficial)
function obtenerCatalogoMedicosLIHO() {
    $connLIHO = obtenerConexionLIHO();
    $catalogo = [
        'por_usuario' => [],
        'por_cedula'  => [],
        'por_nombre'  => []
    ];
    if (!$connLIHO) return $catalogo;

    $sql = "SELECT m.usuario_proteo, m.cedula, u.nombre_completo, u.email 
            FROM dbo.medicos m WITH (NOLOCK)
            LEFT JOIN dbo.usuarios u WITH (NOLOCK) ON m.usuario_id = u.id";
    $stmt = sqlsrv_query($connLIHO, $sql);
    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $uProt = strtoupper(trim((string)($r['usuario_proteo'] ?? '')));
            $ced   = trim((string)($r['cedula'] ?? ''));
            $nom   = strtoupper(trim((string)($r['nombre_completo'] ?? '')));
            $email = strtolower(trim((string)($r['email'] ?? '')));

            if (!empty($email)) {
                if (!empty($uProt)) $catalogo['por_usuario'][$uProt] = $email;
                if (!empty($ced))   $catalogo['por_cedula'][$ced] = $email;
                if (!empty($nom))   $catalogo['por_nombre'][$nom] = $email;
            }
        }
    }
    return $catalogo;
}

// Consulta de Pacientes en Curso en SQL Server (PROTEO) con cruce a BD LIHO
function consultarPacientesEnCurso($fecha_inicio = '2026-05-01', $fecha_fin = '2026-08-31') {
    $con = obtenerConexionProteo();
    if ($con === false) {
        $errores = sqlsrv_errors();
        $msg = '';
        if ($errores) {
            foreach ($errores as $err) {
                $msg .= " [{$err['message']}]";
            }
        }
        registrarLogAlerta("ERROR DE CONEXIÓN SQL SERVER: $msg");
        return ['error' => true, 'mensaje' => 'Error de conexión con SQL Server (PROTEO): ' . $msg, 'pacientes' => [], 'resumen_medicos' => [], 'sedes' => [], 'modalidades' => []];
    }

    $catalogoLIHO = obtenerCatalogoMedicosLIHO();

    $fecha_inicio_sql = trim($fecha_inicio) . " 00:00:00";
    $fecha_fin_sql    = trim($fecha_fin) . " 23:59:59";

    $query = "SELECT 
        AE.Id,
        DOC.Value AS Documento,
        ENT.name AS Nombre,
        AE.LastModificationTime AS Fecha_Creacion,
        VIS.Value AS Ingreso,
        CUP.Value AS CUPS,
        MO.Value AS Modalidad,
        AE.EventStatusName AS Estado_Actual,
        SED.Value AS Sede,
        CONCAT(U.Name, ' ', U.Surname) AS Medico,
        U.UserName AS Usuario_Medico,
        U.EmailAddress AS Correo_Medico
    FROM dbo.AppEvents AE WITH (NOLOCK)
    LEFT JOIN dbo.AppEntities ENT WITH (NOLOCK) ON AE.EntityId = ENT.Id
    INNER JOIN dbo.AbpUsers U WITH (NOLOCK) ON AE.LastModifierUserId = U.Id
     
    -- Documento
    OUTER APPLY (
        SELECT TOP 1 Value
        FROM dbo.AppEntityDetails WITH (NOLOCK)
        WHERE EntityId = AE.EntityId 
          AND Name = 'Número identificación'
    ) DOC
     
    -- Ingreso
    OUTER APPLY (
        SELECT TOP 1 Value 
        FROM dbo.AppEventDynamicDetails WITH (NOLOCK)
        WHERE EventId = AE.Id AND [Key] = 'VisitId'
        ORDER BY LastModificationTime DESC
    ) VIS
     
    -- CUPS
    OUTER APPLY (
        SELECT TOP 1 Value
        FROM dbo.AppEventDynamicDetails WITH (NOLOCK)
        WHERE EventId = AE.Id AND [Key] = 'CUPS'
        ORDER BY LastModificationTime DESC
    ) CUP

    -- Última Modalidad
    OUTER APPLY (
        SELECT TOP 1 Value 
        FROM dbo.AppEventDynamicDetails WITH (NOLOCK)
        WHERE EventId = AE.Id AND [Key] = 'App.Proteo.WL.ModalityCUPS'
        ORDER BY LastModificationTime DESC
    ) MO
     
    -- Sede
    OUTER APPLY (
        SELECT TOP 1 Value
        FROM dbo.AppReports WITH (NOLOCK)
        WHERE EventId = AE.Id 
          AND [Key] = 'Sede'
        ORDER BY LastModificationTime DESC
    ) SED
     
    WHERE CHARINDEX('En Curso', AE.EventStatusName) > 0
    AND NOT EXISTS (
            SELECT 1
            FROM dbo.AppEvents AE2 WITH (NOLOCK)
            WHERE AE2.EntityId = AE.EntityId
              AND AE2.LastModificationTime > AE.LastModificationTime
              AND AE2.IsDeleted = 0
        )
      AND AE.LastModificationTime >= ?
      AND AE.LastModificationTime <= ?
    ORDER BY CONCAT(U.Name, ' ', U.Surname) ASC, AE.LastModificationTime DESC";

    $params = array($fecha_inicio_sql, $fecha_fin_sql);
    $stmt = sqlsrv_query($con, $query, $params);

    if ($stmt === false) {
        $errores = sqlsrv_errors();
        $msg = '';
        if ($errores) {
            foreach ($errores as $err) {
                $msg .= " [{$err['message']}]";
            }
        }
        registrarLogAlerta("ERROR EN CONSULTA SQL: $msg");
        return ['error' => true, 'mensaje' => 'Error al consultar pacientes: ' . $msg, 'pacientes' => [], 'resumen_medicos' => [], 'sedes' => [], 'modalidades' => []];
    }

    $pacientes = [];
    $sedesMap = [];
    $modalidadesMap = [];
    $medicosMap = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fechaStr = 'N/A';
        if ($row['Fecha_Creacion'] instanceof DateTime) {
            $fechaStr = $row['Fecha_Creacion']->format('Y-m-d H:i');
        } elseif (!empty($row['Fecha_Creacion'])) {
            $fechaStr = (string)$row['Fecha_Creacion'];
        }

        $doc = trim((string)($row['Documento'] ?? ''));
        $nom = trim((string)($row['Nombre'] ?? ''));
        $ing = trim((string)($row['Ingreso'] ?? ''));
        $cup = trim((string)($row['CUPS'] ?? ''));
        $mod = trim((string)($row['Modalidad'] ?? ''));
        $est = trim((string)($row['Estado_Actual'] ?? 'En Curso'));
        $sed = trim((string)($row['Sede'] ?? ''));
        $med = trim((string)($row['Medico'] ?? ''));
        if (empty($med)) $med = 'Médico No Asignado';
        $uMed = trim((string)($row['Usuario_Medico'] ?? ''));
        $cMedProteo = trim((string)($row['Correo_Medico'] ?? ''));

        // Cruce inteligente con la Base de Datos LIHO
        $uMedUpper = strtoupper($uMed);
        $nomUpper  = strtoupper($med);
        $cedLimpia = preg_replace('/[^0-9]/', '', $uMedUpper);

        $cMedLIHO = '';
        if (!empty($uMedUpper) && isset($catalogoLIHO['por_usuario'][$uMedUpper])) {
            $cMedLIHO = $catalogoLIHO['por_usuario'][$uMedUpper];
        } elseif (!empty($nomUpper) && isset($catalogoLIHO['por_nombre'][$nomUpper])) {
            $cMedLIHO = $catalogoLIHO['por_nombre'][$nomUpper];
        } elseif (!empty($cedLimpia) && isset($catalogoLIHO['por_cedula'][$cedLimpia])) {
            $cMedLIHO = $catalogoLIHO['por_cedula'][$cedLimpia];
        }

        $cMedFinal = !empty($cMedLIHO) ? $cMedLIHO : $cMedProteo;
        $fuenteCorreo = !empty($cMedLIHO) ? 'LIHO' : (!empty($cMedProteo) ? 'PROTEO' : '');

        if (!empty($sed)) $sedesMap[$sed] = true;
        if (!empty($mod)) $modalidadesMap[$mod] = true;

        $pItem = [
            'id'             => (string)$row['Id'],
            'documento'      => $doc,
            'nombre'         => $nom,
            'fecha'          => $fechaStr,
            'ingreso'        => $ing,
            'cups'           => $cup,
            'modalidad'      => $mod,
            'estado'         => $est,
            'sede'           => $sed,
            'medico'         => $med,
            'usuario_medico' => $uMed,
            'correo_medico'  => $cMedFinal,
            'correo_liho'    => $cMedLIHO,
            'correo_proteo'  => $cMedProteo,
            'fuente_correo'  => $fuenteCorreo
        ];

        $pacientes[] = $pItem;

        // Agrupación por Médico
        if (!isset($medicosMap[$med])) {
            $medicosMap[$med] = [
                'medico'         => $med,
                'usuario_medico' => $uMed,
                'correo_medico'  => $cMedFinal,
                'correo_liho'    => $cMedLIHO,
                'correo_proteo'  => $cMedProteo,
                'fuente_correo'  => $fuenteCorreo,
                'total'          => 0,
                'sedes'          => [],
                'modalidades'    => [],
                'pacientes'      => []
            ];
        }
        $medicosMap[$med]['total']++;
        if (!empty($cMedFinal) && (empty($medicosMap[$med]['correo_medico']) || $medicosMap[$med]['fuente_correo'] !== 'LIHO')) {
            $medicosMap[$med]['correo_medico'] = $cMedFinal;
            $medicosMap[$med]['fuente_correo'] = $fuenteCorreo;
        }
        if (!empty($sed) && !in_array($sed, $medicosMap[$med]['sedes'])) {
            $medicosMap[$med]['sedes'][] = $sed;
        }
        if (!empty($mod) && !in_array($mod, $medicosMap[$med]['modalidades'])) {
            $medicosMap[$med]['modalidades'][] = $mod;
        }
        $medicosMap[$med]['pacientes'][] = $pItem;
    }

    // Ordenar médicos por cantidad de pacientes descendente
    $resumenMedicos = array_values($medicosMap);
    usort($resumenMedicos, function($a, $b) {
        if ($b['total'] === $a['total']) {
            return strcmp($a['medico'], $b['medico']);
        }
        return $b['total'] <=> $a['total'];
    });

    $sedesLista = array_keys($sedesMap);
    sort($sedesLista);

    $modalidadesLista = array_keys($modalidadesMap);
    sort($modalidadesLista);

    return [
        'error'           => false,
        'mensaje'         => 'OK',
        'total'           => count($pacientes),
        'pacientes'       => $pacientes,
        'resumen_medicos' => $resumenMedicos,
        'sedes'           => $sedesLista,
        'modalidades'     => $modalidadesLista
    ];
}

// Generación de Excel nativo .xls con formato corporativo
function generarExcelPacientes($pacientes, $fecha_inicio = '', $fecha_fin = '', $titulo = 'Pacientes En Curso') {
    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    $html .= '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>' . htmlspecialchars($titulo) . '</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    $html .= '<style>
        body { font-family: Montserrat, Calibri, Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #14354E; color: #ffffff; font-weight: bold; padding: 10px; border: 1px solid #cbd5e1; text-align: left; font-size: 11pt; }
        td { padding: 8px 10px; border: 1px solid #e2e8f0; font-size: 10.5pt; }
        .num { mso-number-format: "\@"; }
        .badge { background-color: #fef3c7; color: #b45309; font-weight: bold; text-align: center; }
    </style></head><body>';
    
    $html .= '<table>';
    $html .= '<thead><tr>
        <th>#</th>
        <th>ID Evento</th>
        <th>Documento</th>
        <th>Nombre del Paciente</th>
        <th>Fecha Creación / Modif.</th>
        <th>Ingreso (VisitId)</th>
        <th>CUPS</th>
        <th>Modalidad</th>
        <th>Estado Actual</th>
        <th>Sede</th>
        <th>Médico Asignado</th>
        <th>Usuario Médico</th>
        <th>Correo Médico</th>
    </tr></thead><tbody>';

    $i = 1;
    foreach ($pacientes as $p) {
        $bg = ($i % 2 === 0) ? '#ffffff' : '#f8fafc';
        $html .= "<tr style='background-color: {$bg};'>";
        $html .= "<td style='text-align: center;'>{$i}</td>";
        $html .= "<td class='num'>" . htmlspecialchars($p['id'] ?? '') . "</td>";
        $html .= "<td class='num' style='font-weight: bold;'>" . htmlspecialchars($p['documento'] ?? '') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['nombre'] ?? '') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['fecha'] ?? '') . "</td>";
        $html .= "<td class='num'>" . htmlspecialchars($p['ingreso'] ?? '') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['cups'] ?? '') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['modalidad'] ?? '') . "</td>";
        $html .= "<td class='badge'>" . htmlspecialchars($p['estado'] ?? 'En Curso') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['sede'] ?? '') . "</td>";
        $html .= "<td style='font-weight: bold;'>" . htmlspecialchars($p['medico'] ?? '') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['usuario_medico'] ?? '') . "</td>";
        $html .= "<td>" . htmlspecialchars($p['correo_medico'] ?? '') . "</td>";
        $html .= "</tr>";
        $i++;
    }

    $html .= '</tbody></table></body></html>';
    return $html;
}

// Obtener Logo Corporativo Incrustado para Correos
function obtenerLogoEmailIncrustado() {
    $rutas = [
        __DIR__ . '/assets/img/logo_fondo_osc_hd.png',
        __DIR__ . '/assets/img/Logo fondo oscuro.png',
        __DIR__ . '/assets/img/Ho_Fondo_Osc.png',
        __DIR__ . '/assets/img/Logo original.png'
    ];
    foreach ($rutas as $r) {
        if (file_exists($r)) {
            return ['logo_liho' => $r];
        }
    }
    return [];
}

// Plantilla HTML para Correo General (Responsivo para Móviles)
function generarHtmlCorreoGeneral($pacientes, $fecha_inicio, $fecha_fin, $nombreArchivoExcel, $nota = '') {
    $total = count($pacientes);
    $fechaGeneracion = date('d/m/Y h:i A');

    $medicosUnicos = [];
    foreach ($pacientes as $p) {
        $m = trim($p['medico'] ?? '');
        if (!empty($m)) $medicosUnicos[$m] = true;
    }
    $totalMedicos = count($medicosUnicos);

    $bloqueNota = '';
    if (!empty($nota)) {
        $bloqueNota = "
        <div style='background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 14px; border-radius: 8px; margin: 16px 0;'>
            <strong style='color: #1e40af; font-size: 13px;'>Nota adicional:</strong>
            <p style='margin: 4px 0 0 0; color: #1e3a8a; font-size: 13px; line-height: 1.5; word-break: break-word;'>" . nl2br(htmlspecialchars($nota)) . "</p>
        </div>";
    }

    $cuerpoHTML = "<!DOCTYPE html>
    <html lang='es' xmlns='http://www.w3.org/1999/xhtml' xmlns:v='urn:schemas-microsoft-com:vml' xmlns:o='urn:schemas-microsoft-com:office:office'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta name='x-apple-disable-message-reformatting'>
        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
        <title>Pacientes En Curso No Finalizados</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 12px 6px; background-color: #f1f5f9; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; color: #1e293b; }
            table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
            img { -ms-interpolation-mode: bicubic; max-width: 100%; height: auto; border: 0; }
            
            .email-container { width: 100%; max-width: 640px; margin: 0 auto; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
            .header { background: linear-gradient(135deg, #14354E 0%, #1e4867 100%); color: #ffffff; padding: 26px 22px; text-align: center; }
            .content { padding: 24px 22px; }
            .metric-table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 18px 0; }
            .metric-col { width: 33.33%; padding: 0 4px; vertical-align: top; }
            .metric-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 8px; text-align: center; }
            
            /* Reglas específicas para pantallas de móviles (< 540px) */
            @media only screen and (max-width: 540px) {
                body { padding: 8px 4px !important; }
                .email-container { border-radius: 10px !important; width: 100% !important; }
                .header { padding: 20px 14px !important; }
                .content { padding: 18px 14px !important; }
                .header-badge { font-size: 10px !important; padding: 4px 10px !important; }
                .header-title { font-size: 19px !important; line-height: 1.3 !important; }
                .header-sub { font-size: 12px !important; }
                .text-intro { font-size: 14px !important; }
                .text-desc { font-size: 13.5px !important; }
                .metric-table { margin: 14px 0 !important; }
                .metric-col { display: block !important; width: 100% !important; padding: 0 0 8px 0 !important; }
                .metric-box { padding: 12px 14px !important; }
                .metric-value { font-size: 22px !important; }
                .attach-box { padding: 12px 14px !important; font-size: 13px !important; }
                .footer { padding: 14px 12px !important; font-size: 11px !important; }
            }
        </style>
    </head>
    <body>
        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
            <tr>
                <td align='center' style='padding: 0;'>
                    <div class='email-container'>
                        <div class='header'>
                            <div style='text-align: center; margin-bottom: 14px;'>
                                <img src='cid:logo_liho' alt='IPS Hernán Ocazionez' style='max-height: 48px; width: auto; display: inline-block; vertical-align: middle;' />
                            </div>
                            <div class='header-badge' style='display: inline-block; padding: 5px 12px; background: rgba(0,193,190,0.2); border: 1px solid #00c1be; border-radius: 20px; color: #00c1be; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px;'>
                                REPORTE PARA DIRECCIÓN MÉDICA
                            </div>
                            <h1 class='header-title' style='margin: 0; font-size: 21px; font-weight: 800; color: #ffffff;'>Pacientes En Curso No Finalizados</h1>
                            <p class='header-sub' style='margin: 5px 0 0 0; font-size: 12.5px; color: #cbd5e1;'>IPS Hernán Ocazionez y Cía S.A.S. • Sistema LIHO</p>
                        </div>

                        <div class='content'>
                            <p class='text-intro' style='font-size: 14.5px; line-height: 1.55; color: #334155; margin-top: 0;'>
                                Apreciada <strong>Directora Médica</strong> y equipo de coordinación,
                            </p>
                            <p class='text-desc' style='font-size: 14px; line-height: 1.55; color: #475569;'>
                                Para su conocimiento, supervisión y seguimiento, desde el sistema <strong>LIHO</strong> se ha generado automáticamente el reporte consolidado de <strong>{$total} paciente(s)</strong> con procedimientos en estado <span style='background-color: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: bold;'>En Curso</span> que aún no han sido finalizados por los especialistas en el sistema <strong>PROTEO</strong>.
                            </p>

                            {$bloqueNota}

                            <!-- Cuadrícula de Métricas Responsiva -->
                            <table class='metric-table' role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
                                <tr>
                                    <td class='metric-col'>
                                        <div class='metric-box'>
                                            <span style='font-size: 10.5px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;'>Total Pacientes</span>
                                            <div class='metric-value' style='font-size: 23px; font-weight: 800; color: #dc2626; margin-top: 3px;'>{$total}</div>
                                        </div>
                                    </td>
                                    <td class='metric-col'>
                                        <div class='metric-box'>
                                            <span style='font-size: 10.5px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;'>Médicos Involucrados</span>
                                            <div class='metric-value' style='font-size: 23px; font-weight: 800; color: #14354E; margin-top: 3px;'>{$totalMedicos}</div>
                                        </div>
                                    </td>
                                    <td class='metric-col'>
                                        <div class='metric-box'>
                                            <span style='font-size: 10.5px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;'>Rango Fechas</span>
                                            <div style='font-size: 11.5px; font-weight: 700; color: #00c1be; margin-top: 5px; line-height: 1.3;'>{$fecha_inicio}<br>al {$fecha_fin}</div>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Banner de Archivo Adjunto -->
                            <div class='attach-box' style='background-color: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 10px; padding: 14px 16px; margin: 18px 0;'>
                                <div style='font-size: 13.5px; color: #065f46; line-height: 1.5;'>
                                    <strong style='font-size: 13.5px;'>Archivo Excel Adjunto:</strong><br>
                                    <span style='word-break: break-word;'>Se adjunta el reporte detallado <strong>" . htmlspecialchars($nombreArchivoExcel) . "</strong> con los {$total} registros para auditoría, validación y gestión directa con los especialistas.</span>
                                </div>
                            </div>

                            <p style='font-size: 13.5px; color: #334155; line-height: 1.55;'>
                                Este informe tiene como propósito facilitar la gestión oportuna con el equipo de especialistas y coordinar el cierre de las atenciones pendientes.
                            </p>

                            <p style='font-size: 12.5px; color: #64748b; margin-bottom: 0;'>
                                Fecha y hora de generación: <strong>{$fechaGeneracion}</strong>
                            </p>
                        </div>

                        <div class='footer' style='background-color: #f8fafc; color: #64748b; padding: 14px 20px; text-align: center; font-size: 11.5px; border-top: 1px solid #e2e8f0; line-height: 1.4;'>
                            <p style='margin: 0;'>Mensaje automático generado por el <strong>Sistema LIHO - Alerta Médicos</strong>.</p>
                            <p style='margin: 3px 0 0 0;'>IPS Hernán Ocazionez y Cía S.A.S.</p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </body>
    </html>";

    return $cuerpoHTML;
}

// Plantilla HTML para Recordatorio Individual al Médico (Responsivo para Móviles)
function generarHtmlCorreoMedico($medicoNombre, $pacientesMedico, $fecha_inicio, $fecha_fin, $nombreArchivoExcel, $nota = '') {
    $total = count($pacientesMedico);
    $fechaGeneracion = date('d/m/Y h:i A');

    $bloqueNota = '';
    if (!empty($nota)) {
        $bloqueNota = "
        <div style='background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 14px; border-radius: 8px; margin: 16px 0;'>
            <strong style='color: #1e40af; font-size: 13px;'>Nota de coordinación médica:</strong>
            <p style='margin: 4px 0 0 0; color: #1e3a8a; font-size: 13px; line-height: 1.5; word-break: break-word;'>" . nl2br(htmlspecialchars($nota)) . "</p>
        </div>";
    }

    $cuerpoHTML = "<!DOCTYPE html>
    <html lang='es' xmlns='http://www.w3.org/1999/xhtml' xmlns:v='urn:schemas-microsoft-com:vml' xmlns:o='urn:schemas-microsoft-com:office:office'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta name='x-apple-disable-message-reformatting'>
        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
        <title>Recordatorio Pacientes En Curso - Dr(a). " . htmlspecialchars($medicoNombre) . "</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 12px 6px; background-color: #f1f5f9; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; color: #1e293b; }
            table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
            img { -ms-interpolation-mode: bicubic; max-width: 100%; height: auto; border: 0; }
            
            .email-container { width: 100%; max-width: 640px; margin: 0 auto; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
            .header { background: linear-gradient(135deg, #14354E 0%, #1e4867 100%); color: #ffffff; padding: 26px 22px; text-align: center; }
            .content { padding: 24px 22px; }
            .metric-table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 18px 0; }
            .metric-col { width: 50%; padding: 0 4px; vertical-align: top; }
            .metric-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 8px; text-align: center; }
            
            /* Reglas específicas para pantallas de móviles (< 540px) */
            @media only screen and (max-width: 540px) {
                body { padding: 8px 4px !important; }
                .email-container { border-radius: 10px !important; width: 100% !important; }
                .header { padding: 20px 14px !important; }
                .content { padding: 18px 14px !important; }
                .header-badge { font-size: 10px !important; padding: 4px 10px !important; }
                .header-title { font-size: 18px !important; line-height: 1.3 !important; }
                .header-sub { font-size: 12px !important; }
                .text-intro { font-size: 14px !important; }
                .text-desc { font-size: 13.5px !important; }
                .metric-table { margin: 14px 0 !important; }
                .metric-col { display: block !important; width: 100% !important; padding: 0 0 8px 0 !important; }
                .metric-box { padding: 12px 14px !important; }
                .metric-value { font-size: 22px !important; }
                .attach-box { padding: 12px 14px !important; font-size: 13px !important; }
                .footer { padding: 14px 12px !important; font-size: 11px !important; }
            }
        </style>
    </head>
    <body>
        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
            <tr>
                <td align='center' style='padding: 0;'>
                    <div class='email-container'>
                        <div class='header'>
                            <div style='text-align: center; margin-bottom: 14px;'>
                                <img src='cid:logo_liho' alt='IPS Hernán Ocazionez' style='max-height: 48px; width: auto; display: inline-block; vertical-align: middle;' />
                            </div>
                            <div class='header-badge' style='display: inline-block; padding: 5px 12px; background: rgba(0,193,190,0.2); border: 1px solid #00c1be; border-radius: 20px; color: #00c1be; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px;'>
                                RECORDATORIO DE GESTIÓN CLÍNICA
                            </div>
                            <h1 class='header-title' style='margin: 0; font-size: 21px; font-weight: 800; color: #ffffff;'>Dr(a). " . htmlspecialchars($medicoNombre) . "</h1>
                            <p class='header-sub' style='margin: 5px 0 0 0; font-size: 12.5px; color: #cbd5e1;'>IPS Hernán Ocazionez y Cía S.A.S. • Sistema LIHO</p>
                        </div>

                        <div class='content'>
                            <p class='text-intro' style='font-size: 14.5px; line-height: 1.55; color: #334155; margin-top: 0;'>
                                Apreciado(a) <strong>Dr(a). " . htmlspecialchars($medicoNombre) . "</strong>,
                            </p>
                            <p class='text-desc' style='font-size: 14px; line-height: 1.55; color: #475569;'>
                                Le informamos cordialmente que en el sistema de gestión clínica <strong>PROTEO</strong> registra actualmente <strong>{$total} paciente(s)</strong> asignados en estado <span style='background-color: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: bold;'>En Curso</span> pendientes por su debida finalización.
                            </p>

                            {$bloqueNota}

                            <!-- Cuadrícula de Métricas Responsiva para Médico -->
                            <table class='metric-table' role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
                                <tr>
                                    <td class='metric-col'>
                                        <div class='metric-box'>
                                            <span style='font-size: 10.5px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;'>Pacientes Asignados</span>
                                            <div class='metric-value' style='font-size: 23px; font-weight: 800; color: #dc2626; margin-top: 3px;'>{$total}</div>
                                        </div>
                                    </td>
                                    <td class='metric-col'>
                                        <div class='metric-box'>
                                            <span style='font-size: 10.5px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;'>Periodo de Consulta</span>
                                            <div style='font-size: 11.5px; font-weight: 700; color: #00c1be; margin-top: 5px; line-height: 1.3;'>{$fecha_inicio}<br>al {$fecha_fin}</div>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Banner de Archivo Adjunto -->
                            <div class='attach-box' style='background-color: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 10px; padding: 14px 16px; margin: 18px 0;'>
                                <div style='font-size: 13.5px; color: #065f46; line-height: 1.5;'>
                                    <strong style='font-size: 13.5px;'>Archivo Excel Adjunto:</strong><br>
                                    <span style='word-break: break-word;'>Se adjunta el archivo <strong>" . htmlspecialchars($nombreArchivoExcel) . "</strong> con el listado detallado de sus <strong>{$total} paciente(s)</strong> (Documento, Nombre, Fecha, Ingreso, CUPS, Modalidad y Sede).</span>
                                </div>
                            </div>

                            <p style='font-size: 13.5px; color: #334155; line-height: 1.55;'>
                                Agradecemos su valiosa colaboración revisando y finalizando estos procedimientos en el sistema a la mayor brevedad posible.
                            </p>

                            <p style='font-size: 12.5px; color: #64748b; margin-top: 18px; margin-bottom: 0;'>
                                Fecha y hora de generación: <strong>{$fechaGeneracion}</strong>
                            </p>
                        </div>

                        <div class='footer' style='background-color: #f8fafc; color: #64748b; padding: 14px 20px; text-align: center; font-size: 11.5px; border-top: 1px solid #e2e8f0; line-height: 1.4;'>
                            <p style='margin: 0;'>Mensaje automático generado por el <strong>Sistema LIHO - Alerta Médicos</strong>.</p>
                            <p style='margin: 3px 0 0 0;'>IPS Hernán Ocazionez y Cía S.A.S.</p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </body>
    </html>";

    return $cuerpoHTML;
}

// Envío de Alerta General por Correo
function enviarAlertaGeneral($pacientes, $destinatarios, $fecha_inicio, $fecha_fin, $asunto = null, $nota = '') {
    if (empty($destinatarios)) {
        return ['success' => false, 'mensaje' => 'No se especificaron destinatarios de correo.'];
    }

    $total = count($pacientes);
    if ($total === 0) {
        return ['success' => false, 'mensaje' => 'No hay pacientes en estado "En Curso" para enviar.'];
    }

    $nombreArchivoExcel = "Reporte_Pacientes_En_Curso_{$fecha_inicio}_al_{$fecha_fin}.xls";
    $contenidoExcel = generarExcelPacientes($pacientes, $fecha_inicio, $fecha_fin, "Pacientes_En_Curso");

    $subject = !empty($asunto) ? $asunto : "ALERTA: {$total} Paciente(s) En Curso No Finalizados ({$fecha_inicio} al {$fecha_fin})";
    $bodyHTML = generarHtmlCorreoGeneral($pacientes, $fecha_inicio, $fecha_fin, $nombreArchivoExcel, $nota);

    $attachments = array(
        array(
            'name' => $nombreArchivoExcel,
            'type' => 'application/vnd.ms-excel; charset=UTF-8',
            'data' => $contenidoExcel
        )
    );

    $embeddedImages = obtenerLogoEmailIncrustado();

    // Enviar utilizando el helper robusto de LIHO
    $enviado = enviarCorreoSMTP($destinatarios, $subject, $bodyHTML, null, $embeddedImages, '', array(), $attachments);

    if ($enviado) {
        $destStr = is_array($destinatarios) ? implode(', ', $destinatarios) : (string)$destinatarios;
        registrarLogAlerta("Alerta general enviada exitosamente a: $destStr (Total: $total pacientes, Rango: $fecha_inicio al $fecha_fin)");
        
        require_once __DIR__ . '/includes/logger_helper.php';
        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema('ALERTA_MEDICOS', 'REPORTE_DIRECTORA_MEDICA', 'ENVIO_CORREO', "Reporte consolidado de {$total} pacientes en curso enviado a: {$destStr} (Periodo: {$fecha_inicio} al {$fecha_fin})", [
                'entidad_afectada' => 'Dirección Médica (' . $destStr . ')',
                'nivel' => 'SUCCESS'
            ]);
        }

        return [
            'success' => true,
            'mensaje' => "¡Alerta general con archivo Excel adjunto enviada exitosamente a {$destStr}!",
            'total' => $total,
            'archivo' => $nombreArchivoExcel
        ];
    } else {
        $errorMsg = "Error al despachar el correo SMTP. Verifique la configuración de correo.";
        registrarLogAlerta("ERROR AL ENVIAR CORREO GENERAL: $errorMsg");
        return ['success' => false, 'mensaje' => $errorMsg];
    }
}

// Envío de Recordatorio Individual a Médico
function enviarRecordatorioMedico($medicoNombre, $pacientesMedico, $destinatarios, $fecha_inicio, $fecha_fin, $asunto = null, $nota = '') {
    if (empty($destinatarios)) {
        return ['success' => false, 'mensaje' => 'No se especificaron destinatarios de correo.'];
    }

    $total = count($pacientesMedico);
    if ($total === 0) {
        return ['success' => false, 'mensaje' => "El Dr(a). {$medicoNombre} no tiene pacientes en curso para alertar."];
    }

    $medicoSlug = preg_replace('/[^A-Za-z0-9_]/', '_', $medicoNombre);
    $nombreArchivoExcel = "Pacientes_En_Curso_{$medicoSlug}.xls";
    $contenidoExcel = generarExcelPacientes($pacientesMedico, $fecha_inicio, $fecha_fin, "Pacientes_" . substr($medicoSlug, 0, 20));

    $subject = !empty($asunto) ? $asunto : "RECORDATORIO: {$total} Paciente(s) En Curso pendientes - Dr(a). {$medicoNombre}";
    $bodyHTML = generarHtmlCorreoMedico($medicoNombre, $pacientesMedico, $fecha_inicio, $fecha_fin, $nombreArchivoExcel, $nota);

    $attachments = array(
        array(
            'name' => $nombreArchivoExcel,
            'type' => 'application/vnd.ms-excel; charset=UTF-8',
            'data' => $contenidoExcel
        )
    );

    $embeddedImages = obtenerLogoEmailIncrustado();

    $enviado = enviarCorreoSMTP($destinatarios, $subject, $bodyHTML, null, $embeddedImages, '', array(), $attachments);

    if ($enviado) {
        $destStr = is_array($destinatarios) ? implode(', ', $destinatarios) : (string)$destinatarios;
        registrarLogAlerta("Recordatorio para Dr(a). {$medicoNombre} enviado exitosamente a: $destStr ({$total} pacientes)");
        
        require_once __DIR__ . '/includes/logger_helper.php';
        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema('ALERTA_MEDICOS', 'RECORDATORIO_MEDICO_INDIVIDUAL', 'ENVIO_CORREO', "Recordatorio de {$total} pacientes en curso enviado al Dr(a). {$medicoNombre} al correo {$destStr}", [
                'entidad_afectada' => "Dr(a). {$medicoNombre} ({$destStr})",
                'nivel' => 'SUCCESS'
            ]);
        }

        return [
            'success' => true,
            'mensaje' => "¡Recordatorio enviado exitosamente a {$destStr} con los {$total} pacientes del Dr(a). {$medicoNombre}!",
            'total' => $total,
            'archivo' => $nombreArchivoExcel
        ];
    } else {
        $errorMsg = "Error al despachar el recordatorio SMTP.";
        registrarLogAlerta("ERROR AL ENVIAR RECORDATORIO: $errorMsg");
        return ['success' => false, 'mensaje' => $errorMsg];
    }
}

// ============================================================================
// CONTROLADOR DE ACCIONES AJAX / EXPORTACIONES
// ============================================================================

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (!empty($action)) {
    switch ($action) {
        case 'fetch_data':
            header('Content-Type: application/json; charset=utf-8');
            $fecha_inicio = $_POST['fecha_inicio'] ?? ($_GET['fecha_inicio'] ?? '2026-05-01');
            $fecha_fin    = $_POST['fecha_fin'] ?? ($_GET['fecha_fin'] ?? '2026-08-31');

            $res = consultarPacientesEnCurso($fecha_inicio, $fecha_fin);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;

        case 'send_general_alert':
            header('Content-Type: application/json; charset=utf-8');
            $fecha_inicio  = $_POST['fecha_inicio'] ?? '2026-05-01';
            $fecha_fin     = $_POST['fecha_fin'] ?? '2026-08-31';
            $destinatarios = $_POST['destinatarios'] ?? ['juane6462@gmail.com'];
            $asunto        = trim($_POST['asunto'] ?? '');
            $nota          = trim($_POST['nota'] ?? '');

            if (is_string($destinatarios)) {
                $destinatarios = array_filter(array_map('trim', explode(',', $destinatarios)));
            }

            $resQuery = consultarPacientesEnCurso($fecha_inicio, $fecha_fin);
            if ($resQuery['error']) {
                echo json_encode(['success' => false, 'mensaje' => $resQuery['mensaje']], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resEnvio = enviarAlertaGeneral($resQuery['pacientes'], $destinatarios, $fecha_inicio, $fecha_fin, $asunto, $nota);
            echo json_encode($resEnvio, JSON_UNESCAPED_UNICODE);
            exit;

        case 'send_medico_reminder':
            header('Content-Type: application/json; charset=utf-8');
            $medicoNombre  = trim($_POST['medico_nombre'] ?? '');
            $fecha_inicio  = $_POST['fecha_inicio'] ?? '2026-05-01';
            $fecha_fin     = $_POST['fecha_fin'] ?? '2026-08-31';
            $destinatarios = $_POST['destinatarios'] ?? ['juane6462@gmail.com'];
            $asunto        = trim($_POST['asunto'] ?? '');
            $nota          = trim($_POST['nota'] ?? '');

            if (empty($medicoNombre)) {
                echo json_encode(['success' => false, 'mensaje' => 'No se especificó el nombre del médico.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (is_string($destinatarios)) {
                $destinatarios = array_filter(array_map('trim', explode(',', $destinatarios)));
            }

            $resQuery = consultarPacientesEnCurso($fecha_inicio, $fecha_fin);
            if ($resQuery['error']) {
                echo json_encode(['success' => false, 'mensaje' => $resQuery['mensaje']], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pacientesMedico = [];
            foreach ($resQuery['pacientes'] as $p) {
                if ($p['medico'] === $medicoNombre) {
                    $pacientesMedico[] = $p;
                }
            }

            if (empty($pacientesMedico)) {
                echo json_encode(['success' => false, 'mensaje' => "El Dr(a). {$medicoNombre} no tiene pacientes en curso en este rango de fechas."], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resEnvio = enviarRecordatorioMedico($medicoNombre, $pacientesMedico, $destinatarios, $fecha_inicio, $fecha_fin, $asunto, $nota);
            echo json_encode($resEnvio, JSON_UNESCAPED_UNICODE);
            exit;

        case 'export_excel':
            $fecha_inicio = $_GET['fecha_inicio'] ?? '2026-05-01';
            $fecha_fin    = $_GET['fecha_fin'] ?? '2026-08-31';
            $medicoNombre = trim($_GET['medico_nombre'] ?? '');

            $resQuery = consultarPacientesEnCurso($fecha_inicio, $fecha_fin);
            $pacientes = $resQuery['pacientes'] ?? [];

            if (!empty($medicoNombre)) {
                $pacientes = array_values(array_filter($pacientes, function($p) use ($medicoNombre) {
                    return $p['medico'] === $medicoNombre;
                }));
                $nombreArchivo = "Pacientes_En_Curso_" . preg_replace('/[^A-Za-z0-9_]/', '_', $medicoNombre) . ".xls";
                $titulo = "Pacientes_" . substr(preg_replace('/[^A-Za-z0-9_]/', '_', $medicoNombre), 0, 20);
            } else {
                $nombreArchivo = "Reporte_Pacientes_En_Curso_{$fecha_inicio}_al_{$fecha_fin}.xls";
                $titulo = "Pacientes_En_Curso";
            }

            $contenidoExcel = generarExcelPacientes($pacientes, $fecha_inicio, $fecha_fin, $titulo);

            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
            header('Cache-Control: max-age=0');
            echo $contenidoExcel;
            exit;

        case 'save_config':
            header('Content-Type: application/json; charset=utf-8');
            $fecha_modo              = $_POST['fecha_modo'] ?? 'rango_fijo';
            $fecha_inicio            = $_POST['fecha_inicio'] ?? '2026-05-01';
            $fecha_fin               = $_POST['fecha_fin'] ?? '2026-08-31';
            $tipos_destinatarios_raw = $_POST['tipos_destinatarios'] ?? '["directora_medica"]';
            $destinatarios           = $_POST['destinatarios'] ?? ['coordinacionsistemas@hernanocazionez.com'];
            $asunto                  = $_POST['asunto_personalizado'] ?? 'ALERTA: Pacientes En Curso No Finalizados';

            $tiposArr = is_array($tipos_destinatarios_raw) ? $tipos_destinatarios_raw : json_decode($tipos_destinatarios_raw, true);
            if (!is_array($tiposArr) || empty($tiposArr)) {
                $tiposArr = array_filter(array_map('trim', explode(',', (string)$tipos_destinatarios_raw)));
            }
            if (empty($tiposArr)) {
                $tiposArr = ['directora_medica'];
            }

            // Parámetros de Programación
            $prog_frecuencia = $_POST['prog_frecuencia'] ?? 'varias_veces';
            $prog_hora_unica = $_POST['prog_hora_unica'] ?? '08:00';
            $prog_horas_raw  = $_POST['prog_horas'] ?? '["08:00", "13:00", "18:00"]';
            $prog_intervalo  = intval($_POST['prog_intervalo_horas'] ?? 4);
            $prog_dias_raw   = $_POST['prog_dias_activos'] ?? '["1","2","3","4","5"]';
            $prog_validar    = ($_POST['prog_validar_horario'] === '1' || $_POST['prog_validar_horario'] === 'true' || $_POST['prog_validar_horario'] === true);

            $horasArr = is_array($prog_horas_raw) ? $prog_horas_raw : json_decode($prog_horas_raw, true);
            if (!is_array($horasArr) || empty($horasArr)) {
                $horasArr = array_filter(array_map('trim', explode(',', (string)$prog_horas_raw)));
            }
            if (empty($horasArr)) $horasArr = ['08:00', '13:00', '18:00'];
            sort($horasArr);

            $diasArr = is_array($prog_dias_raw) ? $prog_dias_raw : json_decode($prog_dias_raw, true);
            if (!is_array($diasArr)) {
                $diasArr = ['1', '2', '3', '4', '5'];
            }

            if (is_string($destinatarios)) {
                $destinatarios = array_filter(array_map('trim', explode(',', $destinatarios)));
            }

            // Guardar configuración de seguridad de correos a médicos en modo desarrollo
            if (isset($_POST['bloquear_correos_medicos'])) {
                $bloqMed = ($_POST['bloquear_correos_medicos'] === '1' || $_POST['bloquear_correos_medicos'] === 'true' || $_POST['bloquear_correos_medicos'] === true);
                $emailRedir = trim($_POST['email_test_redireccion'] ?? '');
                guardarConfiguracionSistema([
                    'bloquear_correos_medicos' => $bloqMed,
                    'modo_desarrollo'          => $bloqMed,
                    'email_test_redireccion'   => $emailRedir
                ], $_SESSION['user_name'] ?? 'Admin Alertas');
            }

            $guardado = guardarConfiguracionAlerta([
                'fecha_modo' => $fecha_modo,
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin,
                'tipo_destinatario' => $tiposArr[0] ?? 'directora_medica',
                'tipos_destinatarios' => array_values(array_unique($tiposArr)),
                'destinatarios' => $destinatarios,
                'asunto_personalizado' => $asunto,
                'programacion' => [
                    'frecuencia' => $prog_frecuencia,
                    'hora_unica' => $prog_hora_unica,
                    'horas' => array_values(array_unique($horasArr)),
                    'intervalo_horas' => $prog_intervalo,
                    'dias_activos' => array_values(array_unique($diasArr)),
                    'validar_horario' => $prog_validar
                ]
            ]);

            if ($guardado) {
                echo json_encode(['success' => true, 'mensaje' => 'Configuración de horario y tarea programada guardada exitosamente.'], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'mensaje' => 'Error al guardar la configuración.'], JSON_UNESCAPED_UNICODE);
            }
            exit;

        case 'get_logs':
            header('Content-Type: application/json; charset=utf-8');
            $archivoLog = __DIR__ . '/logs/alerta_medicos_' . date('Y-m') . '.log';
            if (file_exists($archivoLog)) {
                $lineas = file($archivoLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $ultimasLineas = array_slice($lineas, -60);
                echo json_encode(['success' => true, 'logs' => array_reverse($ultimasLineas)], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => true, 'logs' => ['No hay registros en la bitácora de este mes.']], JSON_UNESCAPED_UNICODE);
            }
            exit;
    }
}

// Cargar configuración inicial y listas predefinidas
$configActual = obtenerConfiguracionAlerta();
$progActual = $configActual['programacion'] ?? [
    'frecuencia' => 'varias_veces',
    'hora_unica' => '08:00',
    'horas' => ['08:00', '13:00', '18:00'],
    'intervalo_horas' => 4,
    'dias_activos' => ['1', '2', '3', '4', '5'],
    'validar_horario' => false
];
$predefs = obtenerCorreosDestinatariosPredefinidos();
$directoraEmails = $predefs['directora_medica'];
$adminEmails = $predefs['admin_emails'];
$financieroEmails = $predefs['financiero_emails'];
$rolesLIHO = $predefs['roles'];
$directoraAdminsEmails = $predefs['directora_y_admins'];
$tiposDestActuales = $configActual['tipos_destinatarios'] ?? (isset($configActual['tipo_destinatario']) ? [$configActual['tipo_destinatario']] : ['directora_medica']);
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Alerta Médicos - Pacientes en Curso | LIHO</title>
    
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
                        sans: ["Montserrat", "sans-serif"]
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
        }
        .freq-opt-btn.active {
            background-color: #14354e;
            color: #ffffff;
            border-color: #14354e;
        }
        .dark .freq-opt-btn.active {
            background-color: #00c1be;
            color: #0f172a;
            border-color: #00c1be;
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header / Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Content Container -->
    <main class="flex-grow max-w-[1520px] w-full mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-6">

        <!-- 1. Header Banner con Identidad Corporativa -->
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-primary via-[#1c486a] to-[#006a68] p-6 sm:p-8 text-white shadow-xl border border-white/10">
            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur-md border border-white/20 text-tertiary text-xs font-bold uppercase tracking-wider mb-2">
                        <span class="material-symbols-outlined text-sm">notifications_active</span>
                        <span>Monitoreo Clínico en Vivo • PROTEO</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight text-white flex items-center gap-3">
                        <span class="material-symbols-outlined text-3xl sm:text-4xl text-tertiary">notifications_active</span>
                        Alerta Médicos: Pacientes en Curso
                    </h1>
                    <p class="text-slate-200 text-xs sm:text-sm mt-1 max-w-2xl font-medium">
                        Control de procedimientos pendientes por finalizar en el sistema, gestión de recordatorios por especialista y despachos automáticos con Excel adjunto.
                    </p>
                </div>

                <!-- Botones Superiores de Acción Rápida -->
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" id="btnOpenConfigModal" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/20 text-white text-xs font-bold transition-all duration-200 shadow-sm hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg text-tertiary">schedule</span>
                        <span>Programar Cron</span>
                    </button>

                    <a href="logs.php?modulo=ALERTA_MEDICOS" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/20 text-white text-xs font-bold transition-all duration-200 shadow-sm hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg text-tertiary">manage_history</span>
                        <span>Ver Auditoría de Alertas</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Barra de Filtros y Rango de Fechas -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 sm:p-6 shadow-sm border border-slate-200/80 dark:border-slate-800 space-y-4">
            
            <!-- Barra Superior de Filtros: Presets y Acciones Principales -->
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                
                <!-- Presets Rápidos de Rango -->
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider mr-1">Rango:</span>
                    <button type="button" class="date-preset-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 hover:border-tertiary text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-800 transition-all cursor-pointer" data-preset="rango_fijo">
                        Mayo - Agosto 2026
                    </button>
                    <button type="button" class="date-preset-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 hover:border-tertiary text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-800 transition-all cursor-pointer" data-preset="mes_actual">
                        Mes Actual
                    </button>
                    <button type="button" class="date-preset-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 hover:border-tertiary text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-800 transition-all cursor-pointer" data-preset="ultimos_30">
                        Últimos 30 días
                    </button>
                    <button type="button" class="date-preset-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 hover:border-tertiary text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-800 transition-all cursor-pointer" data-preset="hoy">
                        Hoy
                    </button>
                </div>

                <!-- Botones Principales de Acción -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <button type="button" id="btnConsultar" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary hover:bg-[#193e5a] text-white text-xs font-bold shadow-md shadow-primary/20 transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg text-tertiary">refresh</span>
                        <span>Consultar Datos</span>
                    </button>

                    <button type="button" id="btnOpenGeneralEmailModal" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-rose-600 to-red-600 hover:from-rose-700 hover:to-red-700 text-white text-xs font-bold shadow-md shadow-rose-600/20 transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg">mail</span>
                        <span>Enviar Alerta General</span>
                    </button>

                    <button type="button" id="btnExportGlobalExcel" 
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg">table_view</span>
                        <span>Exportar Excel Global</span>
                    </button>
                </div>
            </div>

            <!-- Formulario de Rango y Filtros -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3.5 items-end">
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Fecha Inicial</label>
                    <input type="date" id="filtroFechaInicio" value="<?php echo htmlspecialchars($configActual['fecha_inicio'] ?? '2026-05-01'); ?>"
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none" />
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Fecha Final</label>
                    <input type="date" id="filtroFechaFin" value="<?php echo htmlspecialchars($configActual['fecha_fin'] ?? '2026-08-31'); ?>"
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none" />
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Filtrar por Sede</label>
                    <select id="filtroSede" class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none cursor-pointer">
                        <option value="">Todas las Sedes</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Filtrar Modalidad</label>
                    <select id="filtroModalidad" class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none cursor-pointer">
                        <option value="">Todas las Modalidades</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Búsqueda Rápida</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
                        <input type="text" id="filtroBusqueda" placeholder="Buscar paciente, médico..." 
                            class="w-full pl-9 pr-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none" />
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Tarjetas KPI de Resumen Métrico -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- KPI 1: Total Pacientes -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Pacientes En Curso</p>
                    <h3 id="kpiTotalPacientes" class="text-3xl font-black text-rose-600 dark:text-rose-400 mt-1">--</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Pendientes por finalizar</p>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">pending_actions</span>
                </div>
            </div>

            <!-- KPI 2: Médicos Afectados -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Médicos Asignados</p>
                    <h3 id="kpiTotalMedicos" class="text-3xl font-black text-primary dark:text-tertiary mt-1">--</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Especialistas con casos</p>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-primary/10 dark:bg-tertiary/10 text-primary dark:text-tertiary flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">medical_services</span>
                </div>
            </div>

            <!-- KPI 3: Horarios de Envío Automático -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div class="overflow-hidden">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Horarios Programados</p>
                    <h3 id="kpiHorariosTexto" class="text-xs sm:text-sm font-black text-primary dark:text-tertiary truncate mt-1">
                        <?php
                        $freq = $progActual['frecuencia'] ?? 'varias_veces';
                        if ($freq === 'una_vez') {
                            echo htmlspecialchars($progActual['hora_unica'] ?? '08:00') . " (1 vez/día)";
                        } elseif ($freq === 'varias_veces') {
                            $hList = $progActual['horas'] ?? ['08:00', '13:00', '18:00'];
                            echo htmlspecialchars(implode(', ', $hList)) . " (" . count($hList) . " veces/día)";
                        } else {
                            echo "Cada " . intval($progActual['intervalo_horas'] ?? 4) . " horas";
                        }
                        ?>
                    </h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Task Scheduler</p>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">alarm_on</span>
                </div>
            </div>

            <!-- KPI 4: Destinatarios Configurados -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between">
                <div class="overflow-hidden">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Destinatarios Automáticos</p>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100 truncate mt-1" id="kpiDestinatariosTexto">
                        <?php 
                        $labels = [];
                        if (in_array('directora_admins', $tiposDestActuales, true)) {
                            $labels[] = 'Directora + Admins';
                        } elseif (in_array('directora_medica', $tiposDestActuales, true)) {
                            $labels[] = 'Directora Médica';
                        }
                        if (in_array('rol_admin', $tiposDestActuales, true) || in_array('admin', $tiposDestActuales, true)) {
                            $labels[] = 'Admin';
                        }
                        if (in_array('rol_financiero', $tiposDestActuales, true) || in_array('financiero', $tiposDestActuales, true)) {
                            $labels[] = 'Financiero';
                        }
                        if (in_array('todos_medicos', $tiposDestActuales, true)) {
                            $labels[] = 'Médicos';
                        }
                        if (in_array('manual', $tiposDestActuales, true)) {
                            $labels[] = 'Personalizado';
                        }
                        if (empty($labels)) $labels[] = 'Directora Médica';
                        echo htmlspecialchars(implode(' • ', $labels));
                        ?>
                    </h3>
                    <p class="text-[11px] text-teal-600 dark:text-teal-400 font-bold mt-0.5 flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-teal-500 animate-pulse"></span> Envíos Reales en Producción
                    </p>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-teal-50 dark:bg-teal-950/50 text-teal-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">mark_email_read</span>
                </div>
            </div>

        </div>

        <!-- 4. Contenedor de Pestañas (Tabs) y Vistas -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-sm border border-slate-200/80 dark:border-slate-800 overflow-hidden">
            
            <!-- Barra de Pestañas -->
            <div class="p-4 sm:p-5 border-b border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/50">
                <div class="flex items-center gap-2 p-1.5 bg-slate-200/70 dark:bg-slate-800 rounded-2xl">
                    <button type="button" class="tab-btn active px-4 py-2 rounded-xl text-xs font-bold transition-all duration-200 cursor-pointer flex items-center gap-2" data-tab="tabMedicos">
                        <span class="material-symbols-outlined text-base">groups</span>
                        <span>Resumen por Médico</span>
                        <span id="badgeMedicosCount" class="px-1.5 py-0.5 rounded-full text-[10px] bg-white/20 text-inherit font-black">0</span>
                    </button>
                    <button type="button" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition-all duration-200 cursor-pointer flex items-center gap-2 text-slate-600 dark:text-slate-300" data-tab="tabPacientes">
                        <span class="material-symbols-outlined text-base">list_alt</span>
                        <span>Listado Detallado</span>
                        <span id="badgePacientesCount" class="px-1.5 py-0.5 rounded-full text-[10px] bg-slate-300 dark:bg-slate-700 text-inherit font-black">0</span>
                    </button>
                </div>

                <div class="text-xs text-slate-500 dark:text-slate-400 font-semibold" id="estadoCargaTexto">
                    Cargando datos...
                </div>
            </div>

            <!-- TAB 1: VISTA POR MÉDICOS -->
            <div id="tabMedicos" class="tab-content p-5 sm:p-6 space-y-4">
                
                <div class="overflow-x-auto rounded-2xl border border-slate-200/80 dark:border-slate-800">
                    <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                        <thead class="bg-slate-100/80 dark:bg-slate-800/80 uppercase text-[10px] font-black text-slate-500 dark:text-slate-400 tracking-wider">
                            <tr>
                                <th class="p-3.5 text-center w-12">#</th>
                                <th class="p-3.5">Médico Asignado</th>
                                <th class="p-3.5">Usuario / Correo Registrado</th>
                                <th class="p-3.5 text-center">Total Pacientes</th>
                                <th class="p-3.5">Sedes Involucradas</th>
                                <th class="p-3.5">Modalidades</th>
                                <th class="p-3.5 text-right">Acciones Rápidas</th>
                            </tr>
                        </thead>
                        <tbody id="tablaMedicosBody" class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-400 font-medium">
                                    <span class="material-symbols-outlined text-3xl animate-spin mb-2 text-tertiary">sync</span>
                                    <p>Consultando pacientes en curso en SQL Server PROTEO...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- TAB 2: VISTA DETALLADA DE PACIENTES -->
            <div id="tabPacientes" class="tab-content p-5 sm:p-6 space-y-4 hidden">
                
                <div class="overflow-x-auto rounded-2xl border border-slate-200/80 dark:border-slate-800 max-h-[650px]">
                    <table class="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                        <thead class="bg-slate-100/90 dark:bg-slate-800/90 uppercase text-[10px] font-black text-slate-500 dark:text-slate-400 tracking-wider sticky top-0 z-10 shadow-xs">
                            <tr>
                                <th class="p-3 text-center w-10">#</th>
                                <th class="p-3">ID Evento</th>
                                <th class="p-3">Documento</th>
                                <th class="p-3">Nombre del Paciente</th>
                                <th class="p-3">Fecha Creación / Modif.</th>
                                <th class="p-3">Ingreso (VisitId)</th>
                                <th class="p-3">CUPS</th>
                                <th class="p-3">Modalidad</th>
                                <th class="p-3 text-center">Estado</th>
                                <th class="p-3">Sede</th>
                                <th class="p-3">Médico</th>
                            </tr>
                        </thead>
                        <tbody id="tablaPacientesBody" class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr>
                                <td colspan="11" class="p-8 text-center text-slate-400 font-medium">
                                    Cargando registros...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>

    </main>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <!-- ===================================================================== -->
    <!-- MODALES INTERACTIVOS                                                  -->
    <!-- ===================================================================== -->

    <!-- MODAL 1: Enviar Alerta General -->
    <div id="modalGeneralEmail" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 transform scale-95 transition-transform duration-200" id="modalGeneralEmailBox">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600">
                        <span class="material-symbols-outlined text-2xl">mail</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-primary dark:text-tertiary">Enviar Alerta General</h3>
                        <p class="text-xs text-slate-400">Despacho con Excel consolidado adjunto</p>
                    </div>
                </div>
                <button type="button" class="modal-close-btn p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form id="formGeneralEmail" class="space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300">Destinatario(s)</label>
                        <span class="text-[10.5px] text-slate-400 font-medium">Selección rápida por Rol:</span>
                    </div>

                    <!-- Presets rápidos para Modal General -->
                    <div class="flex flex-wrap gap-1.5 mb-2">
                        <button type="button" class="btn-gen-preset px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-slate-100 dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors flex items-center gap-1 cursor-pointer" data-emails="<?php echo htmlspecialchars(implode(', ', $directoraEmails)); ?>">
                            <span class="material-symbols-outlined text-sm text-teal-600">medical_services</span>
                            <span>Directora Médica</span>
                        </button>
                        <button type="button" class="btn-gen-preset px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-slate-100 dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors flex items-center gap-1 cursor-pointer" data-emails="<?php echo htmlspecialchars(implode(', ', $adminEmails)); ?>">
                            <span class="material-symbols-outlined text-sm text-blue-600">admin_panel_settings</span>
                            <span>Rol: Admin</span>
                        </button>
                        <button type="button" class="btn-gen-preset px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-slate-100 dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors flex items-center gap-1 cursor-pointer" data-emails="<?php echo htmlspecialchars(implode(', ', $financieroEmails)); ?>">
                            <span class="material-symbols-outlined text-sm text-emerald-600">payments</span>
                            <span>Rol: Financiero</span>
                        </button>
                        <button type="button" class="btn-gen-preset px-2.5 py-1 rounded-lg text-[10.5px] font-bold bg-slate-100 dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors flex items-center gap-1 cursor-pointer" data-emails="<?php echo htmlspecialchars(implode(', ', $directoraAdminsEmails)); ?>">
                            <span class="material-symbols-outlined text-sm text-indigo-600">shield_person</span>
                            <span>Directora + Admins</span>
                        </button>
                    </div>

                    <input type="text" id="emailGenDestinatarios" value="<?php echo htmlspecialchars(implode(', ', $directoraEmails)); ?>" required
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none font-mono" />
                    <p class="text-[11px] text-slate-400 mt-1">Separar múltiples correos con comas (,)</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Asunto del Correo</label>
                    <input type="text" id="emailGenAsunto" value="ALERTA: Pacientes En Curso No Finalizados" required
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Nota Adicional (Opcional)</label>
                    <textarea id="emailGenNota" rows="2" placeholder="Instrucciones específicas para el equipo..."
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none"></textarea>
                </div>

                <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200/60 dark:border-emerald-800/60 text-xs text-emerald-800 dark:text-emerald-300 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-lg text-emerald-600 shrink-0">attach_file</span>
                    <div>
                        <strong>Archivo adjunto automático:</strong><br>
                        Se generará y adjuntará un archivo Excel con los pacientes filtrados.
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" class="modal-close-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitGeneralEmail"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md shadow-rose-600/20 transition-all cursor-pointer flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">send</span>
                        <span>Enviar Alerta</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: Enviar Recordatorio a Médico Individual -->
    <div id="modalMedicoEmail" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 transform scale-95 transition-transform duration-200" id="modalMedicoEmailBox">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-tertiary/10 text-tertiary">
                        <span class="material-symbols-outlined text-2xl">forward_to_inbox</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-primary dark:text-tertiary">Recordatorio a Especialista</h3>
                        <p class="text-xs text-slate-400" id="modalMedicoSubtitulo">Dr(a). Nombre Médico</p>
                    </div>
                </div>
                <button type="button" class="modal-close-btn p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form id="formMedicoEmail" class="space-y-4">
                <input type="hidden" id="emailMedNombreMedico" value="" />

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300">Correo Electrónico del Médico</label>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1">
                            <span class="material-symbols-outlined text-[13px]">lock</span>
                            <span>Solo Lectura (BD LIHO)</span>
                        </span>
                    </div>
                    <div class="relative">
                        <input type="text" id="emailMedDestinatarios" value="" readonly required placeholder="Sin correo registrado"
                            class="w-full pl-9 pr-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 outline-none font-mono cursor-not-allowed select-all" />
                        <span class="material-symbols-outlined absolute left-2.5 top-2 text-slate-400 text-base">mail_lock</span>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-medium mt-1.5 flex items-center gap-1.5" id="emailMedOriginalNotice">
                        <span class="material-symbols-outlined text-sm text-tertiary">verified</span>
                        <span>Correo oficial en BD LIHO. Para editarlo dirígete al módulo de <a href="medicos.php" target="_blank" class="underline font-bold text-tertiary hover:text-teal-400">Médicos</a>.</span>
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Asunto</label>
                    <input type="text" id="emailMedAsunto" value="" required
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Nota Personalizada (Opcional)</label>
                    <textarea id="emailMedNota" rows="2" placeholder="Mensaje específico para el médico..."
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none"></textarea>
                </div>

                <div class="p-3 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200/60 dark:border-teal-800/60 text-xs text-teal-800 dark:text-teal-300 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-lg text-teal-600 shrink-0">table_chart</span>
                    <div>
                        <strong>Excel personalizado:</strong><br>
                        Se adjuntará un archivo Excel con únicamente los pacientes asignados a este especialista.
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" class="modal-close-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitMedicoEmail"
                        class="px-5 py-2.5 rounded-xl bg-primary hover:bg-[#193e5a] text-white text-xs font-bold shadow-md shadow-primary/20 transition-all cursor-pointer flex items-center gap-2">
                        <span class="material-symbols-outlined text-base text-tertiary">send</span>
                        <span>Enviar Recordatorio</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: Configurar Tarea Automática (Cron / Task Scheduler) -->
    <div id="modalConfig" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 transform scale-95 transition-transform duration-200 max-h-[92vh] overflow-y-auto" id="modalConfigBox">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-primary/10 text-primary dark:text-tertiary">
                        <span class="material-symbols-outlined text-2xl">alarm_on</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-primary dark:text-tertiary">Programación y Horarios de Envío</h3>
                        <p class="text-xs text-slate-400">Windows Task Scheduler & Automatización CLI</p>
                    </div>
                </div>
                <button type="button" class="modal-close-btn p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form id="formConfig" class="space-y-4">
                
                <!-- SECCIÓN 1: RANGO DE FECHAS -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-black text-primary dark:text-tertiary uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">calendar_month</span>
                            <span>1. Rango de Fechas para la Consulta</span>
                        </label>
                    </div>

                    <div>
                        <select id="cfgFechaModo" class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none cursor-pointer">
                            <option value="rango_fijo" <?php echo (($configActual['fecha_modo'] ?? '') === 'rango_fijo') ? 'selected' : ''; ?>>Rango Fijo Específico</option>
                            <option value="mes_actual" <?php echo (($configActual['fecha_modo'] ?? '') === 'mes_actual') ? 'selected' : ''; ?>>Mes Actual Dinámico (01 al fin de mes)</option>
                            <option value="ultimos_30_dias" <?php echo (($configActual['fecha_modo'] ?? '') === 'ultimos_30_dias') ? 'selected' : ''; ?>>Últimos 30 Días Dinámico</option>
                            <option value="hoy" <?php echo (($configActual['fecha_modo'] ?? '') === 'hoy') ? 'selected' : ''; ?>>Solo el Día de Hoy</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3" id="cfgRangoFijoBox">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 mb-1">Fecha Inicio Fija</label>
                            <input type="date" id="cfgFechaInicio" value="<?php echo htmlspecialchars($configActual['fecha_inicio'] ?? '2026-05-01'); ?>"
                                class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 mb-1">Fecha Fin Fija</label>
                            <input type="date" id="cfgFechaFin" value="<?php echo htmlspecialchars($configActual['fecha_fin'] ?? '2026-08-31'); ?>"
                                class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN 2: FRECUENCIA Y HORARIOS DE ENVÍO -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 space-y-3.5">
                    <label class="block text-xs font-black text-primary dark:text-tertiary uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">schedule</span>
                        <span>2. Frecuencia y Horas de Envío al Día</span>
                    </label>

                    <!-- Selector de Frecuencia (3 opciones) -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <button type="button" class="freq-opt-btn px-3 py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 transition-all cursor-pointer" data-freq="una_vez">
                            <span class="material-symbols-outlined text-base">timer</span>
                            <span>1 vez al día</span>
                        </button>
                        <button type="button" class="freq-opt-btn active px-3 py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 transition-all cursor-pointer" data-freq="varias_veces">
                            <span class="material-symbols-outlined text-base">alarm_on</span>
                            <span>Varias veces al día</span>
                        </button>
                        <button type="button" class="freq-opt-btn px-3 py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 transition-all cursor-pointer" data-freq="intervalo">
                            <span class="material-symbols-outlined text-base">update</span>
                            <span>Por intervalo</span>
                        </button>
                    </div>
                    <input type="hidden" id="cfgProgFrecuencia" value="<?php echo htmlspecialchars($progActual['frecuencia'] ?? 'varias_veces'); ?>" />

                    <!-- PANEL: 1 vez al día -->
                    <div id="panelFreqUnaVez" class="space-y-2 hidden">
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300">Hora exacta de despacho:</label>
                        <input type="time" id="cfgProgHoraUnica" value="<?php echo htmlspecialchars($progActual['hora_unica'] ?? '08:00'); ?>"
                            class="w-full sm:w-48 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                    </div>

                    <!-- PANEL: Varias veces al día (Múltiples Horas) -->
                    <div id="panelFreqVariasVeces" class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold text-slate-600 dark:text-slate-300">Horarios configurados:</span>
                            <span class="text-[10px] text-slate-400">Puedes agregar o quitar horas</span>
                        </div>

                        <!-- Presets rápidos para 1-clic -->
                        <div class="flex flex-wrap gap-1.5">
                            <span class="text-[10px] text-slate-400 font-bold self-center mr-1">Presets:</span>
                            <button type="button" class="hora-preset-btn px-2.5 py-1 rounded-lg text-[10px] font-bold bg-white dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors" data-horas='["08:00","14:00"]'>
                                2 veces (08:00 & 14:00)
                            </button>
                            <button type="button" class="hora-preset-btn px-2.5 py-1 rounded-lg text-[10px] font-bold bg-white dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors" data-horas='["08:00","13:00","18:00"]'>
                                3 veces (08:00, 13:00 & 18:00)
                            </button>
                            <button type="button" class="hora-preset-btn px-2.5 py-1 rounded-lg text-[10px] font-bold bg-white dark:bg-slate-800 hover:bg-tertiary/10 hover:text-tertiary border border-slate-200 dark:border-slate-700 transition-colors" data-horas='["08:00","12:00","16:00","20:00"]'>
                                4 veces (08:00, 12:00, 16:00 & 20:00)
                            </button>
                        </div>

                        <!-- Lista visual de horas seleccionadas (Tags) -->
                        <div id="horasSeleccionadasContainer" class="flex flex-wrap gap-2 p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 min-h-[48px] items-center">
                            <!-- Inyectado dinámicamente -->
                        </div>

                        <!-- Control para agregar otra hora personalizada -->
                        <div class="flex items-center gap-2">
                            <input type="time" id="inputNuevaHora" value="12:00"
                                class="px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold focus:ring-2 focus:ring-tertiary/40 outline-none" />
                            <button type="button" id="btnAgregarHora" 
                                class="px-3.5 py-2 rounded-xl bg-primary hover:bg-[#193e5a] text-white text-xs font-bold shadow-xs transition-all flex items-center gap-1.5 cursor-pointer">
                                <span class="material-symbols-outlined text-base text-tertiary">add_circle</span>
                                <span>Agregar Hora</span>
                            </button>
                        </div>
                    </div>

                    <!-- PANEL: Por Intervalo -->
                    <div id="panelFreqIntervalo" class="space-y-2 hidden">
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300">Ejecutar cada:</label>
                        <select id="cfgProgIntervalo" class="w-full sm:w-60 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold focus:ring-2 focus:ring-tertiary/40 outline-none cursor-pointer">
                            <option value="1" <?php echo (($progActual['intervalo_horas'] ?? 4) == 1) ? 'selected' : ''; ?>>Cada 1 hora</option>
                            <option value="2" <?php echo (($progActual['intervalo_horas'] ?? 4) == 2) ? 'selected' : ''; ?>>Cada 2 horas</option>
                            <option value="3" <?php echo (($progActual['intervalo_horas'] ?? 4) == 3) ? 'selected' : ''; ?>>Cada 3 horas</option>
                            <option value="4" <?php echo (($progActual['intervalo_horas'] ?? 4) == 4) ? 'selected' : ''; ?>>Cada 4 horas (Recomendado)</option>
                            <option value="6" <?php echo (($progActual['intervalo_horas'] ?? 4) == 6) ? 'selected' : ''; ?>>Cada 6 horas</option>
                            <option value="8" <?php echo (($progActual['intervalo_horas'] ?? 4) == 8) ? 'selected' : ''; ?>>Cada 8 horas</option>
                            <option value="12" <?php echo (($progActual['intervalo_horas'] ?? 4) == 12) ? 'selected' : ''; ?>>Cada 12 horas</option>
                        </select>
                    </div>

                    <!-- DÍAS ACTIVOS DE LA SEMANA -->
                    <div class="pt-2 border-t border-slate-200/60 dark:border-slate-700/60">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-[11px] font-bold text-slate-600 dark:text-slate-300">Días de la semana activos:</label>
                            <div class="flex gap-2">
                                <button type="button" id="btnDiasLunVie" class="text-[10px] text-tertiary font-bold hover:underline">Lunes a Viernes</button>
                                <span class="text-[10px] text-slate-400">•</span>
                                <button type="button" id="btnDiasTodos" class="text-[10px] text-tertiary font-bold hover:underline">Todos</button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-1.5" id="diasSemanaContainer">
                            <?php
                            $diasNombres = [
                                '1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles',
                                '4' => 'Jueves', '5' => 'Viernes', '6' => 'Sábado', '7' => 'Domingo'
                            ];
                            $activosActuales = $progActual['dias_activos'] ?? ['1','2','3','4','5'];
                            foreach ($diasNombres as $dNum => $dNom):
                                $checked = in_array((string)$dNum, $activosActuales, true);
                            ?>
                            <label class="dia-pill-label inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-xs font-bold cursor-pointer transition-all <?php echo $checked ? 'bg-primary text-white border-primary dark:bg-tertiary dark:text-slate-900 dark:border-tertiary' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700'; ?>">
                                <input type="checkbox" name="cfgDiasActivos" value="<?php echo $dNum; ?>" class="hidden" <?php echo $checked ? 'checked' : ''; ?>>
                                <span><?php echo $dNom; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN 3: DESTINATARIOS Y ASUNTO (SELECCIÓN POR ROLES Y GRUPOS) -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 space-y-3.5">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-black text-primary dark:text-tertiary uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">forward_to_inbox</span>
                            <span>3. Destinatarios de la Alerta Automática</span>
                        </label>
                        <span class="text-[10px] text-tertiary font-bold bg-tertiary/10 px-2 py-0.5 rounded-md flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs">checklist</span>
                            <span>Selección Múltiple por Rol</span>
                        </span>
                    </div>

                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Selecciona los roles y destinatarios que recibirán las alertas automáticas. Puedes marcar varios a la vez:
                    </p>

                    <!-- Tarjetas Multi-Selección de Destinatarios por Roles -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5" id="grupoTipoDestinatario">
                        
                        <!-- Opción 1: Directora Médica -->
                        <?php $chkDirectora = in_array('directora_medica', $tiposDestActuales, true); ?>
                        <label class="tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none <?php echo $chkDirectora ? 'active bg-teal-50/70 dark:bg-slate-800 border-tertiary ring-2 ring-tertiary/20' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300'; ?>" data-tipo="directora_medica">
                            <input type="checkbox" name="cfgTiposDestinatarios[]" value="directora_medica" class="hidden" <?php echo $chkDirectora ? 'checked' : ''; ?>>
                            <div class="w-8 h-8 rounded-xl bg-teal-50 dark:bg-teal-950/40 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-lg">medical_services</span>
                            </div>
                            <div class="overflow-hidden flex-grow">
                                <div class="flex items-center justify-between">
                                    <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Directora Médica</span>
                                    <span class="chk-icon material-symbols-outlined text-base <?php echo $chkDirectora ? 'text-tertiary font-bold' : 'text-slate-300 dark:text-slate-600'; ?>">
                                        <?php echo $chkDirectora ? 'check_box' : 'check_box_outline_blank'; ?>
                                    </span>
                                </div>
                                <span class="block text-[10.5px] text-slate-400 truncate mt-0.5" title="dirmedica@hernanocazionez.com">dirmedica@hernanocazionez.com</span>
                            </div>
                        </label>

                        <!-- Opción 2: Rol Admin -->
                        <?php $chkAdmin = (in_array('rol_admin', $tiposDestActuales, true) || in_array('admin', $tiposDestActuales, true) || in_array('directora_admins', $tiposDestActuales, true)); ?>
                        <label class="tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none <?php echo $chkAdmin ? 'active bg-teal-50/70 dark:bg-slate-800 border-tertiary ring-2 ring-tertiary/20' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300'; ?>" data-tipo="rol_admin">
                            <input type="checkbox" name="cfgTiposDestinatarios[]" value="rol_admin" class="hidden" <?php echo $chkAdmin ? 'checked' : ''; ?>>
                            <div class="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-lg">admin_panel_settings</span>
                            </div>
                            <div class="overflow-hidden flex-grow">
                                <div class="flex items-center justify-between">
                                    <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Rol: Admin</span>
                                    <span class="chk-icon material-symbols-outlined text-base <?php echo $chkAdmin ? 'text-tertiary font-bold' : 'text-slate-300 dark:text-slate-600'; ?>">
                                        <?php echo $chkAdmin ? 'check_box' : 'check_box_outline_blank'; ?>
                                    </span>
                                </div>
                                <span class="block text-[10.5px] text-slate-400 truncate mt-0.5" title="<?php echo htmlspecialchars(implode(', ', $adminEmails)); ?>">
                                    <?php echo count($adminEmails) ? count($adminEmails) . ' usuario(s) registrados' : 'Administradores LIHO'; ?>
                                </span>
                            </div>
                        </label>

                        <!-- Opción 3: Rol Financiero -->
                        <?php $chkFinanciero = (in_array('rol_financiero', $tiposDestActuales, true) || in_array('financiero', $tiposDestActuales, true)); ?>
                        <label class="tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none <?php echo $chkFinanciero ? 'active bg-teal-50/70 dark:bg-slate-800 border-tertiary ring-2 ring-tertiary/20' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300'; ?>" data-tipo="rol_financiero">
                            <input type="checkbox" name="cfgTiposDestinatarios[]" value="rol_financiero" class="hidden" <?php echo $chkFinanciero ? 'checked' : ''; ?>>
                            <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-lg">payments</span>
                            </div>
                            <div class="overflow-hidden flex-grow">
                                <div class="flex items-center justify-between">
                                    <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Rol: Financiero</span>
                                    <span class="chk-icon material-symbols-outlined text-base <?php echo $chkFinanciero ? 'text-tertiary font-bold' : 'text-slate-300 dark:text-slate-600'; ?>">
                                        <?php echo $chkFinanciero ? 'check_box' : 'check_box_outline_blank'; ?>
                                    </span>
                                </div>
                                <span class="block text-[10.5px] text-slate-400 truncate mt-0.5" title="<?php echo htmlspecialchars(implode(', ', $financieroEmails)); ?>">
                                    <?php echo count($financieroEmails) ? count($financieroEmails) . ' usuario(s) registrados' : 'Personal área financiera'; ?>
                                </span>
                            </div>
                        </label>

                        <!-- Opción 4: Todos los Médicos -->
                        <?php $chkTodosMedicos = in_array('todos_medicos', $tiposDestActuales, true); ?>
                        <label class="tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none <?php echo $chkTodosMedicos ? 'active bg-teal-50/70 dark:bg-slate-800 border-tertiary ring-2 ring-tertiary/20' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300'; ?>" data-tipo="todos_medicos">
                            <input type="checkbox" name="cfgTiposDestinatarios[]" value="todos_medicos" class="hidden" <?php echo $chkTodosMedicos ? 'checked' : ''; ?>>
                            <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-lg">groups</span>
                            </div>
                            <div class="overflow-hidden flex-grow">
                                <div class="flex items-center justify-between">
                                    <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Médicos (Individual)</span>
                                    <span class="chk-icon material-symbols-outlined text-base <?php echo $chkTodosMedicos ? 'text-tertiary font-bold' : 'text-slate-300 dark:text-slate-600'; ?>">
                                        <?php echo $chkTodosMedicos ? 'check_box' : 'check_box_outline_blank'; ?>
                                    </span>
                                </div>
                                <span class="block text-[10.5px] text-slate-400 truncate mt-0.5">Envío individual a cada especialista</span>
                            </div>
                        </label>

                        <!-- Opción 5: Personalizado / Manual -->
                        <?php $chkManual = in_array('manual', $tiposDestActuales, true); ?>
                        <label class="tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none <?php echo $chkManual ? 'active bg-teal-50/70 dark:bg-slate-800 border-tertiary ring-2 ring-tertiary/20' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300'; ?>" data-tipo="manual">
                            <input type="checkbox" name="cfgTiposDestinatarios[]" value="manual" class="hidden" <?php echo $chkManual ? 'checked' : ''; ?>>
                            <div class="w-8 h-8 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-lg">edit_note</span>
                            </div>
                            <div class="overflow-hidden flex-grow">
                                <div class="flex items-center justify-between">
                                    <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Personalizado / Manual</span>
                                    <span class="chk-icon material-symbols-outlined text-base <?php echo $chkManual ? 'text-tertiary font-bold' : 'text-slate-300 dark:text-slate-600'; ?>">
                                        <?php echo $chkManual ? 'check_box' : 'check_box_outline_blank'; ?>
                                    </span>
                                </div>
                                <span class="block text-[10.5px] text-slate-400 truncate mt-0.5">Correos adicionales manuales</span>
                            </div>
                        </label>

                    </div>

                    <!-- Aviso de Envío Individual a Todos los Médicos -->
                    <div id="cfgTodosMedicosNotice" class="p-3.5 rounded-2xl bg-purple-50 dark:bg-purple-950/40 border border-purple-200/60 dark:border-purple-800/60 text-xs text-purple-800 dark:text-purple-300 flex items-start gap-2.5 <?php echo $chkTodosMedicos ? '' : 'hidden'; ?>">
                        <span class="material-symbols-outlined text-lg text-purple-600 shrink-0">info</span>
                        <div>
                            <strong>Envío Masivo Inteligente por Especialista:</strong><br>
                            El sistema agrupará los pacientes pendientes por cada especialista y enviará un correo personalizado con su respectivo archivo Excel adjunto a su correo oficial en la BD de LIHO.
                        </div>
                    </div>

                    <!-- Campo de Correos Dinámico para Modo Manual -->
                    <div id="cfgDestinatariosBox" class="<?php echo $chkManual ? '' : 'hidden'; ?>">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Correos Personalizados Adicionales</label>
                        <input type="text" id="cfgDestinatarios" value="<?php echo htmlspecialchars(implode(', ', $configActual['destinatarios'] ?? ['dirmedica@hernanocazionez.com'])); ?>"
                            placeholder="correo1@ejemplo.com, correo2@ejemplo.com"
                            class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none font-mono" />
                        <p class="text-[11px] text-slate-400 mt-1">Separar múltiples correos con comas (,)</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-1">Asunto Predeterminado</label>
                        <input type="text" id="cfgAsunto" value="<?php echo htmlspecialchars($configActual['asunto_personalizado'] ?? 'ALERTA: Pacientes En Curso No Finalizados'); ?>" required
                            class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none" />
                    </div>
                </div>

                <!-- SECCIÓN 4: SEGURIDAD DE ENVÍOS EN DESARROLLO -->
                <?php 
                $cfgSisAlerta = obtenerConfiguracionSistema();
                $bloqSisAlerta = estanCorreosMedicosBloqueados();
                ?>
                <div class="p-4 rounded-2xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-black text-amber-900 dark:text-amber-300 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-amber-600 dark:text-amber-400">shield</span>
                            <span>4. Entorno y Seguridad de Correos a Médicos</span>
                        </label>
                        <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-md flex items-center gap-1 <?php echo $bloqSisAlerta ? 'bg-amber-200 text-amber-900 dark:bg-amber-900/90 dark:text-amber-100' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'; ?>">
                            <span class="material-symbols-outlined text-xs"><?php echo $bloqSisAlerta ? 'shield' : 'check_circle'; ?></span>
                            <span><?php echo $bloqSisAlerta ? 'Correos Médicos Bloqueados' : 'Envíos a Médicos Activos'; ?></span>
                        </span>
                    </div>

                    <p class="text-[11px] text-amber-900/80 dark:text-amber-200/80 leading-relaxed">
                        Protección preventiva de desarrollo: evita que los médicos reales reciban correos durante pruebas de homologación y configuración.
                    </p>

                    <div class="space-y-2.5 pt-1">
                        <label class="flex items-start gap-3 p-3 rounded-xl bg-white dark:bg-slate-900 border border-amber-200/80 dark:border-slate-700 cursor-pointer select-none">
                            <input type="checkbox" id="cfgBloquearCorreosMedicos" name="bloquear_correos_medicos" value="1" <?php echo $bloqSisAlerta ? 'checked' : ''; ?> class="w-4 h-4 mt-0.5 rounded text-amber-600 focus:ring-amber-500 cursor-pointer">
                            <div>
                                <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Bloquear envíos de correos a Médicos (Modo Desarrollo Seguro)</span>
                                <span class="block text-[11px] text-slate-500 dark:text-slate-400">Si está activo, los mensajes destinados a médicos se interceptarán sin llegar a sus bandejas reales.</span>
                            </div>
                        </label>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">Buzón de pruebas para redirección:</label>
                            <input type="email" id="cfgEmailTestRedireccion" name="email_test_redireccion" value="<?php echo htmlspecialchars($cfgSisAlerta['email_test_redireccion'] ?? 'juane6462@gmail.com'); ?>"
                                placeholder="tu-correo@ejemplo.com"
                                class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none font-mono" />
                            <span class="text-[10px] text-slate-400 mt-0.5 block">Los correos a médicos se entregarán aquí marcados con [TEST DESARROLLO].</span>
                        </div>
                    </div>
                </div>

                <!-- GUÍA Y COMANDO PARA TASK SCHEDULER -->
                <div class="p-3.5 rounded-2xl bg-slate-100 dark:bg-slate-800 text-xs text-slate-600 dark:text-slate-300 space-y-2 border border-slate-200 dark:border-slate-700">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-primary dark:text-tertiary flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">terminal</span>
                            <span>Comando para Windows Task Scheduler:</span>
                        </span>
                        <span class="text-[10px] text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-950/60 px-2 py-0.5 rounded-md">Listo</span>
                    </div>
                    <code class="block font-mono text-[11px] bg-white dark:bg-slate-950 p-2.5 rounded-xl select-all border border-slate-200 dark:border-slate-700 text-tertiary">
                        C:\xampp\php\php.exe C:\xampp\htdocs\LIHO\cron_alerta_medicos.php
                    </code>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-start gap-1.5" id="schedulerGuiaTexto">
                        <span class="material-symbols-outlined text-sm text-tertiary shrink-0 mt-0.5">info</span>
                        <span><strong>Recomendación:</strong> En Task Scheduler programa el desencadenador (Trigger) a las horas elegidas o repitiendo cada 1 hora.</span>
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" class="modal-close-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitConfig"
                        class="px-5 py-2.5 rounded-xl bg-primary hover:bg-[#193e5a] text-white text-xs font-bold shadow-md shadow-primary/20 transition-all cursor-pointer flex items-center gap-2">
                        <span class="material-symbols-outlined text-base text-tertiary">save</span>
                        <span>Guardar Programación</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: Visor de Logs de Ejecución -->
    <div id="modalLogs" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 opacity-0 pointer-events-none transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 transform scale-95 transition-transform duration-200 flex flex-col max-h-[85vh]" id="modalLogsBox">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-3 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600">
                        <span class="material-symbols-outlined text-2xl">receipt_long</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-primary dark:text-tertiary">Bitácora de Ejecución (Logs)</h3>
                        <p class="text-xs text-slate-400">Historial reciente de auditoría y despachos</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="btnRecargarLogs" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer" title="Recargar logs">
                        <span class="material-symbols-outlined text-lg">sync</span>
                    </button>
                    <button type="button" class="modal-close-btn p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            </div>

            <div class="flex-grow overflow-y-auto bg-slate-950 p-4 rounded-2xl border border-slate-800 font-mono text-[11px] text-emerald-400 leading-relaxed whitespace-pre-wrap select-all" id="logsContainer">
                Cargando registros de auditoría...
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- LOGICA JAVASCRIPT / AJAX DEL FRONTEND                                 -->
    <!-- ===================================================================== -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // Estado Global del Módulo
        let rawPacientes = [];
        let rawMedicos = [];
        let listaSedes = [];
        let listaModalidades = [];

        // Estado de Programación de Horarios
        let horasConfiguradas = <?php echo json_encode($progActual['horas'] ?? ['08:00', '13:00', '18:00']); ?>;

        // Elementos DOM
        const filtroFechaInicio = document.getElementById('filtroFechaInicio');
        const filtroFechaFin    = document.getElementById('filtroFechaFin');
        const filtroSede        = document.getElementById('filtroSede');
        const filtroModalidad   = document.getElementById('filtroModalidad');
        const filtroBusqueda    = document.getElementById('filtroBusqueda');
        const btnConsultar      = document.getElementById('btnConsultar');
        const estadoCargaTexto  = document.getElementById('estadoCargaTexto');

        // KPIs
        const kpiTotalPacientes = document.getElementById('kpiTotalPacientes');
        const kpiTotalMedicos   = document.getElementById('kpiTotalMedicos');
        const kpiHorariosTexto  = document.getElementById('kpiHorariosTexto');
        const badgeMedicosCount = document.getElementById('badgeMedicosCount');
        const badgePacientesCount = document.getElementById('badgePacientesCount');

        // Tablas
        const tablaMedicosBody   = document.getElementById('tablaMedicosBody');
        const tablaPacientesBody = document.getElementById('tablaPacientesBody');

        // Control de Pestañas
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                tabBtns.forEach(b => {
                    b.classList.remove('active');
                    b.classList.add('text-slate-600', 'dark:text-slate-300');
                });
                tabContents.forEach(c => c.classList.add('hidden'));

                this.classList.add('active');
                this.classList.remove('text-slate-600', 'dark:text-slate-300');
                const targetTab = document.getElementById(this.dataset.tab);
                if (targetTab) targetTab.classList.remove('hidden');
            });
        });

        // Presets Rápidos de Fechas
        document.querySelectorAll('.date-preset-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const preset = this.dataset.preset;
                const now = new Date();
                
                if (preset === 'rango_fijo') {
                    filtroFechaInicio.value = '2026-05-01';
                    filtroFechaFin.value = '2026-08-31';
                } else if (preset === 'mes_actual') {
                    const y = now.getFullYear();
                    const m = String(now.getMonth() + 1).padStart(2, '0');
                    const lastDay = new Date(y, now.getMonth() + 1, 0).getDate();
                    filtroFechaInicio.value = `${y}-${m}-01`;
                    filtroFechaFin.value = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
                } else if (preset === 'ultimos_30') {
                    const past = new Date();
                    past.setDate(now.getDate() - 30);
                    filtroFechaInicio.value = past.toISOString().split('T')[0];
                    filtroFechaFin.value = now.toISOString().split('T')[0];
                } else if (preset === 'hoy') {
                    const todayStr = now.toISOString().split('T')[0];
                    filtroFechaInicio.value = todayStr;
                    filtroFechaFin.value = todayStr;
                }
                cargarDatos();
            });
        });

        // 1. Función para Cargar Datos AJAX
        function cargarDatos() {
            const fIni = filtroFechaInicio.value;
            const fFin = filtroFechaFin.value;

            estadoCargaTexto.innerHTML = '<span class="inline-flex items-center gap-1.5 text-tertiary"><span class="material-symbols-outlined text-sm animate-spin">sync</span> Consultando PROTEO...</span>';
            btnConsultar.disabled = true;

            fetch(`alerta_medicos.php?action=fetch_data&fecha_inicio=${encodeURIComponent(fIni)}&fecha_fin=${encodeURIComponent(fFin)}`)
                .then(r => r.json())
                .then(data => {
                    btnConsultar.disabled = false;
                    if (data.error) {
                        estadoCargaTexto.innerHTML = '<span class="text-rose-500 font-bold">Error en consulta</span>';
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de Conexión',
                            text: data.mensaje || 'No se pudo consultar SQL Server PROTEO.'
                        });
                        return;
                    }

                    rawPacientes = data.pacientes || [];
                    rawMedicos = data.resumen_medicos || [];
                    listaSedes = data.sedes || [];
                    listaModalidades = data.modalidades || [];

                    // Actualizar Selects de Filtros si cambiaron
                    actualizarSelectsFiltros();

                    // Renderizar
                    renderizarTodo();

                    estadoCargaTexto.innerHTML = `<span class="text-emerald-600 font-bold inline-flex items-center gap-1"><span class="material-symbols-outlined text-xs">check</span> Actualizado (${rawPacientes.length} pacientes)</span>`;
                })
                .catch(err => {
                    btnConsultar.disabled = false;
                    estadoCargaTexto.innerHTML = '<span class="text-rose-500 font-bold">Error de red</span>';
                    console.error("Error al cargar pacientes:", err);
                });
        }

        function actualizarSelectsFiltros() {
            const sedeActual = filtroSede.value;
            const modActual  = filtroModalidad.value;

            // Sedes
            let optsSede = '<option value="">Todas las Sedes</option>';
            listaSedes.forEach(s => {
                const sel = (s === sedeActual) ? 'selected' : '';
                optsSede += `<option value="${escapeHtml(s)}" ${sel}>${escapeHtml(s)}</option>`;
            });
            filtroSede.innerHTML = optsSede;

            // Modalidades
            let optsMod = '<option value="">Todas las Modalidades</option>';
            listaModalidades.forEach(m => {
                const sel = (m === modActual) ? 'selected' : '';
                optsMod += `<option value="${escapeHtml(m)}" ${sel}>${escapeHtml(m)}</option>`;
            });
            filtroModalidad.innerHTML = optsMod;
        }

        // 2. Filtrado y Renderizado
        function obtenerPacientesFiltrados() {
            const sVal = filtroSede.value.trim().toLowerCase();
            const mVal = filtroModalidad.value.trim().toLowerCase();
            const qVal = filtroBusqueda.value.trim().toLowerCase();

            return rawPacientes.filter(p => {
                if (sVal && (p.sede || '').toLowerCase() !== sVal) return false;
                if (mVal && (p.modalidad || '').toLowerCase() !== mVal) return false;
                if (qVal) {
                    const matchDoc   = (p.documento || '').toLowerCase().includes(qVal);
                    const matchNom   = (p.nombre || '').toLowerCase().includes(qVal);
                    const matchMed   = (p.medico || '').toLowerCase().includes(qVal);
                    const matchUser  = (p.usuario_medico || '').toLowerCase().includes(qVal);
                    const matchEmail = (p.correo_medico || '').toLowerCase().includes(qVal);
                    const matchCups  = (p.cups || '').toLowerCase().includes(qVal);
                    const matchIng   = (p.ingreso || '').toLowerCase().includes(qVal);
                    if (!matchDoc && !matchNom && !matchMed && !matchUser && !matchEmail && !matchCups && !matchIng) return false;
                }
                return true;
            });
        }

        function renderizarTodo() {
            const filtrados = obtenerPacientesFiltrados();

            // Reagrupar médicos con los datos filtrados
            const medicosMap = {};

            filtrados.forEach(p => {
                const m = p.medico || 'Médico No Asignado';
                if (!medicosMap[m]) {
                    medicosMap[m] = {
                        medico: m,
                        usuario_medico: p.usuario_medico || '',
                        correo_medico: p.correo_medico || '',
                        fuente_correo: p.fuente_correo || '',
                        total: 0,
                        sedes: [],
                        modalidades: [],
                        pacientes: []
                    };
                }
                medicosMap[m].total++;
                if (p.correo_medico && (!medicosMap[m].correo_medico || medicosMap[m].fuente_correo !== 'LIHO')) {
                    medicosMap[m].correo_medico = p.correo_medico;
                    medicosMap[m].fuente_correo = p.fuente_correo;
                }
                if (p.sede && !medicosMap[m].sedes.includes(p.sede)) medicosMap[m].sedes.push(p.sede);
                if (p.modalidad && !medicosMap[m].modalidades.includes(p.modalidad)) medicosMap[m].modalidades.push(p.modalidad);
                medicosMap[m].pacientes.push(p);
            });

            const medicosList = Object.values(medicosMap);
            medicosList.sort((a, b) => b.total - a.total || a.medico.localeCompare(b.medico));

            // Actualizar KPIs
            kpiTotalPacientes.innerText = filtrados.length.toLocaleString('es-CO');
            kpiTotalMedicos.innerText = medicosList.length.toLocaleString('es-CO');
            badgeMedicosCount.innerText = medicosList.length;
            badgePacientesCount.innerText = filtrados.length;

            // Render Tab Médicos
            renderTablaMedicos(medicosList);

            // Render Tab Pacientes
            renderTablaPacientes(filtrados);
        }

        function renderTablaMedicos(lista) {
            if (lista.length === 0) {
                tablaMedicosBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="p-8 text-center text-slate-400 font-medium">
                            No se encontraron médicos con pacientes en curso según los filtros aplicados.
                        </td>
                    </tr>`;
                return;
            }

            let html = '';
            lista.forEach((m, idx) => {
                const sedesBadges = m.sedes.map(s => `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">${escapeHtml(s)}</span>`).join(' ');
                const modBadges = m.modalidades.map(mod => `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200/50">${escapeHtml(mod)}</span>`).join(' ');

                const badgeOrigen = m.fuente_correo === 'LIHO' 
                    ? '<span class="px-1.5 py-0.2 rounded-md text-[9px] font-black bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 border border-teal-200/50">BD LIHO</span>'
                    : (m.correo_medico ? '<span class="px-1.5 py-0.2 rounded-md text-[9px] font-black bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">PROTEO</span>' : '');

                html += `
                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors group">
                    <td class="p-3.5 text-center font-bold text-slate-400">${idx + 1}</td>
                    <td class="p-3.5 font-bold text-primary dark:text-tertiary">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg text-tertiary">person</span>
                            <span class="text-xs sm:text-sm font-extrabold">${escapeHtml(m.medico)}</span>
                        </div>
                    </td>
                    <td class="p-3.5">
                        <div class="text-xs text-slate-700 dark:text-slate-300 font-bold flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm text-slate-400">badge</span>
                            <span>${escapeHtml(m.usuario_medico || 'Sin usuario')}</span>
                        </div>
                        <div class="text-[11px] text-tertiary font-mono font-medium flex items-center gap-1 mt-0.5" title="Correo oficial del médico (${m.fuente_correo === 'LIHO' ? 'Base de Datos LIHO' : 'PROTEO'})">
                            <span class="material-symbols-outlined text-[13px]">mail</span>
                            <span class="truncate max-w-[210px]">${escapeHtml(m.correo_medico || 'Sin correo en sistema')}</span>
                            ${badgeOrigen}
                        </div>
                    </td>
                    <td class="p-3.5 text-center">
                        <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-black bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 border border-rose-200/60 dark:border-rose-800/60">
                            ${m.total} paciente(s)
                        </span>
                    </td>
                    <td class="p-3.5">
                        <div class="flex flex-wrap gap-1">${sedesBadges || '<span class="text-slate-400">N/A</span>'}</div>
                    </td>
                    <td class="p-3.5">
                        <div class="flex flex-wrap gap-1">${modBadges || '<span class="text-slate-400">N/A</span>'}</div>
                    </td>
                    <td class="p-3.5 text-right">
                        <div class="inline-flex items-center gap-1.5">
                            <button type="button" class="btn-medico-reminder p-2 rounded-xl bg-primary/10 hover:bg-primary text-primary hover:text-white dark:bg-tertiary/10 dark:hover:bg-tertiary dark:text-tertiary dark:hover:text-slate-900 transition-all duration-150 cursor-pointer" 
                                title="Enviar recordatorio por correo con Excel adjunto"
                                data-medico="${escapeHtml(m.medico)}"
                                data-correo="${escapeHtml(m.correo_medico || '')}"
                                data-total="${m.total}">
                                <span class="material-symbols-outlined text-base">forward_to_inbox</span>
                            </button>

                            <button type="button" class="btn-medico-excel p-2 rounded-xl bg-emerald-50 hover:bg-emerald-600 text-emerald-600 hover:text-white dark:bg-emerald-950/50 dark:text-emerald-400 dark:hover:bg-emerald-600 transition-all duration-150 cursor-pointer" 
                                title="Descargar Excel de este médico"
                                data-medico="${escapeHtml(m.medico)}">
                                <span class="material-symbols-outlined text-base">download</span>
                            </button>
                        </div>
                    </td>
                </tr>`;
            });

            tablaMedicosBody.innerHTML = html;

            // Bind actions en botones
            document.querySelectorAll('.btn-medico-reminder').forEach(btn => {
                btn.addEventListener('click', function() {
                    abrirModalRecordatorioMedico(this.dataset.medico, this.dataset.correo, this.dataset.total);
                });
            });

            document.querySelectorAll('.btn-medico-excel').forEach(btn => {
                btn.addEventListener('click', function() {
                    const fIni = filtroFechaInicio.value;
                    const fFin = filtroFechaFin.value;
                    const med = this.dataset.medico;
                    window.location.href = `alerta_medicos.php?action=export_excel&fecha_inicio=${encodeURIComponent(fIni)}&fecha_fin=${encodeURIComponent(fFin)}&medico_nombre=${encodeURIComponent(med)}`;
                });
            });
        }

        function renderTablaPacientes(lista) {
            if (lista.length === 0) {
                tablaPacientesBody.innerHTML = `
                    <tr>
                        <td colspan="11" class="p-8 text-center text-slate-400 font-medium">
                            No se encontraron pacientes según los filtros seleccionados.
                        </td>
                    </tr>`;
                return;
            }

            let html = '';
            lista.forEach((p, idx) => {
                const badgeOrigenP = p.fuente_correo === 'LIHO' 
                    ? '<span class="px-1 py-0.2 rounded text-[9px] font-black bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 border border-teal-200/50">LIHO</span>'
                    : '';

                html += `
                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors">
                    <td class="p-3 text-center text-slate-400 font-bold">${idx + 1}</td>
                    <td class="p-3 font-mono text-[11px] text-slate-500">${escapeHtml(p.id)}</td>
                    <td class="p-3 font-bold text-slate-900 dark:text-slate-100">${escapeHtml(p.documento || 'N/A')}</td>
                    <td class="p-3 font-semibold text-primary dark:text-slate-100">${escapeHtml(p.nombre || 'N/A')}</td>
                    <td class="p-3 text-[11px] text-slate-500 whitespace-nowrap">${escapeHtml(p.fecha || 'N/A')}</td>
                    <td class="p-3 font-mono text-[11px]">${escapeHtml(p.ingreso || 'N/A')}</td>
                    <td class="p-3 text-[11px] font-mono">${escapeHtml(p.cups || 'N/A')}</td>
                    <td class="p-3"><span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200/50">${escapeHtml(p.modalidad || 'N/A')}</span></td>
                    <td class="p-3 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 border border-amber-200/60">${escapeHtml(p.estado || 'En Curso')}</span></td>
                    <td class="p-3 text-xs font-medium">${escapeHtml(p.sede || 'N/A')}</td>
                    <td class="p-3">
                        <div class="font-bold text-slate-800 dark:text-slate-100">${escapeHtml(p.medico || 'N/A')}</div>
                        <div class="text-[11px] font-mono text-tertiary flex items-center gap-1 mt-0.5" title="Correo del médico (${p.fuente_correo === 'LIHO' ? 'BD LIHO' : 'PROTEO'})">
                            <span class="material-symbols-outlined text-[13px] text-tertiary/80">mail</span>
                            <span class="truncate max-w-[190px] font-medium">${escapeHtml(p.correo_medico || 'Sin correo registrado')}</span>
                            ${badgeOrigenP}
                        </div>
                    </td>
                </tr>`;
            });

            tablaPacientesBody.innerHTML = html;
        }

        // Helper escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        // Filtros en Vivo
        btnConsultar.addEventListener('click', cargarDatos);
        filtroFechaInicio.addEventListener('change', cargarDatos);
        filtroFechaFin.addEventListener('change', cargarDatos);
        filtroSede.addEventListener('change', renderizarTodo);
        filtroModalidad.addEventListener('change', renderizarTodo);
        filtroBusqueda.addEventListener('input', renderizarTodo);

        // 3. Exportación Global a Excel
        document.getElementById('btnExportGlobalExcel').addEventListener('click', function() {
            const fIni = filtroFechaInicio.value;
            const fFin = filtroFechaFin.value;
            window.location.href = `alerta_medicos.php?action=export_excel&fecha_inicio=${encodeURIComponent(fIni)}&fecha_fin=${encodeURIComponent(fFin)}`;
        });

        // =====================================================================
        // MANEJO DE MODALES
        // =====================================================================

        function abrirModal(modalId) {
            const m = document.getElementById(modalId);
            if (!m) return;
            m.classList.remove('opacity-0', 'pointer-events-none');
            const box = m.querySelector('div[id$="Box"]');
            if (box) box.classList.remove('scale-95');
        }

        function cerrarModal(modalId) {
            const m = document.getElementById(modalId);
            if (!m) return;
            m.classList.add('opacity-0', 'pointer-events-none');
            const box = m.querySelector('div[id$="Box"]');
            if (box) box.classList.add('scale-95');
        }

        document.querySelectorAll('.modal-close-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.fixed');
                if (modal) cerrarModal(modal.id);
            });
        });

        // Modal Alerta General
        document.getElementById('btnOpenGeneralEmailModal').addEventListener('click', function() {
            abrirModal('modalGeneralEmail');
        });

        document.getElementById('formGeneralEmail').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitGeneralEmail');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">sync</span> Despachando...';

            const fIni = filtroFechaInicio.value;
            const fFin = filtroFechaFin.value;
            const dest = document.getElementById('emailGenDestinatarios').value;
            const asunto = document.getElementById('emailGenAsunto').value;
            const nota = document.getElementById('emailGenNota').value;

            const formData = new FormData();
            formData.append('fecha_inicio', fIni);
            formData.append('fecha_fin', fFin);
            formData.append('destinatarios', dest);
            formData.append('asunto', asunto);
            formData.append('nota', nota);

            fetch('alerta_medicos.php?action=send_general_alert', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined text-base">send</span> <span>Enviar Alerta</span>';
                if (res.success) {
                    cerrarModal('modalGeneralEmail');
                    Swal.fire({
                        icon: 'success',
                        title: '¡Correo Despachado!',
                        text: res.mensaje || 'La alerta general se envió exitosamente con el Excel adjunto.'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de Envío',
                        text: res.mensaje || 'No se pudo enviar la alerta por correo.'
                    });
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined text-base">send</span> <span>Enviar Alerta</span>';
                Swal.fire({ icon: 'error', title: 'Error de Red', text: 'Ocurrió un fallo en la comunicación con el servidor.' });
            });
        });

        // Modal Recordatorio Médico
        function abrirModalRecordatorioMedico(medicoNombre, correoOriginal, totalPacientes) {
            document.getElementById('emailMedNombreMedico').value = medicoNombre;
            document.getElementById('modalMedicoSubtitulo').innerText = `Dr(a). ${medicoNombre} (${totalPacientes} paciente${totalPacientes != 1 ? 's' : ''})`;
            document.getElementById('emailMedAsunto').value = `RECORDATORIO: ${totalPacientes} Paciente(s) En Curso pendientes - Dr(a). ${medicoNombre}`;
            document.getElementById('emailMedDestinatarios').value = correoOriginal || '';
            
            const noticeEl = document.getElementById('emailMedOriginalNotice');
            const submitBtn = document.getElementById('btnSubmitMedicoEmail');

            if (correoOriginal && correoOriginal.trim()) {
                noticeEl.innerHTML = `<span class="material-symbols-outlined text-sm text-tertiary">verified</span> <span>Correo oficial en BD: <strong class="text-primary dark:text-tertiary">${correoOriginal}</strong>. Para editarlo ve a <a href="medicos.php" target="_blank" class="underline font-bold text-tertiary hover:text-teal-400">Médicos</a>.</span>`;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            } else {
                noticeEl.innerHTML = `<span class="material-symbols-outlined text-sm text-rose-500">warning</span> <span class="text-rose-600 dark:text-rose-400 font-bold">Sin correo en BD. Asigna su correo en el módulo de <a href="medicos.php" target="_blank" class="underline font-bold text-tertiary hover:text-teal-400">Médicos</a> para habilitar el envío.</span>`;
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            }
            abrirModal('modalMedicoEmail');
        }

        document.getElementById('formMedicoEmail').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitMedicoEmail');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">sync</span> Despachando...';

            const fIni = filtroFechaInicio.value;
            const fFin = filtroFechaFin.value;
            const medicoNombre = document.getElementById('emailMedNombreMedico').value;
            const dest = document.getElementById('emailMedDestinatarios').value;
            const asunto = document.getElementById('emailMedAsunto').value;
            const nota = document.getElementById('emailMedNota').value;

            const formData = new FormData();
            formData.append('medico_nombre', medicoNombre);
            formData.append('fecha_inicio', fIni);
            formData.append('fecha_fin', fFin);
            formData.append('destinatarios', dest);
            formData.append('asunto', asunto);
            formData.append('nota', nota);

            fetch('alerta_medicos.php?action=send_medico_reminder', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined text-base text-tertiary">send</span> <span>Enviar Recordatorio</span>';
                if (res.success) {
                    cerrarModal('modalMedicoEmail');
                    Swal.fire({
                        icon: 'success',
                        title: '¡Recordatorio Despachado!',
                        text: res.mensaje || 'Se envió el recordatorio con los pacientes asignados.'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de Envío',
                        text: res.mensaje || 'No se pudo enviar el recordatorio.'
                    });
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined text-base text-tertiary">send</span> <span>Enviar Recordatorio</span>';
                Swal.fire({ icon: 'error', title: 'Error de Red', text: 'Fallo al procesar el recordatorio.' });
            });
        });

        // =====================================================================
        // MANEJO DE MODAL DE CONFIGURACIÓN & HORARIOS MÚLTIPLES
        // =====================================================================

        const panelFreqUnaVez      = document.getElementById('panelFreqUnaVez');
        const panelFreqVariasVeces = document.getElementById('panelFreqVariasVeces');
        const panelFreqIntervalo   = document.getElementById('panelFreqIntervalo');
        const cfgProgFrecuencia    = document.getElementById('cfgProgFrecuencia');
        const horasContainer       = document.getElementById('horasSeleccionadasContainer');
        const inputNuevaHora       = document.getElementById('inputNuevaHora');
        const btnAgregarHora       = document.getElementById('btnAgregarHora');
        const schedulerGuiaTexto   = document.getElementById('schedulerGuiaTexto');

        function renderHorasTags() {
            if (!horasContainer) return;
            if (horasConfiguradas.length === 0) {
                horasContainer.innerHTML = '<span class="text-xs text-slate-400 italic">No hay horas seleccionadas. Agrega al menos una.</span>';
                return;
            }

            horasConfiguradas.sort();
            let html = '';
            horasConfiguradas.forEach((h, idx) => {
                // Formato 12 horas bonito
                const parts = h.split(':');
                let hNum = parseInt(parts[0], 10);
                const ampm = hNum >= 12 ? 'PM' : 'AM';
                let h12 = hNum % 12;
                if (h12 === 0) h12 = 12;
                const h12Str = `${h12}:${parts[1]} ${ampm}`;

                html += `
                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary/10 dark:bg-tertiary/10 text-primary dark:text-tertiary border border-primary/20 dark:border-tertiary/20 text-xs font-bold shadow-2xs">
                    <span class="material-symbols-outlined text-sm">schedule</span>
                    <span>${h} <span class="text-[10px] font-normal text-slate-500 dark:text-slate-400">(${h12Str})</span></span>
                    <button type="button" class="btn-eliminar-hora text-slate-400 hover:text-rose-600 transition-colors ml-1 p-0.5 rounded-full" data-index="${idx}" title="Eliminar hora">
                        <span class="material-symbols-outlined text-sm">close</span>
                    </button>
                </div>`;
            });

            horasContainer.innerHTML = html;

            document.querySelectorAll('.btn-eliminar-hora').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const idx = parseInt(this.dataset.index, 10);
                    horasConfiguradas.splice(idx, 1);
                    renderHorasTags();
                    actualizarGuiaScheduler();
                });
            });
        }

        function agregarHora(hVal) {
            if (!hVal) return;
            if (!horasConfiguradas.includes(hVal)) {
                horasConfiguradas.push(hVal);
                horasConfiguradas.sort();
                renderHorasTags();
                actualizarGuiaScheduler();
            }
        }

        if (btnAgregarHora && inputNuevaHora) {
            btnAgregarHora.addEventListener('click', function(e) {
                e.preventDefault();
                agregarHora(inputNuevaHora.value);
            });
        }

        // Presets Rápidos de Horas
        document.querySelectorAll('.hora-preset-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                try {
                    const presetArr = JSON.parse(this.dataset.horas);
                    if (Array.isArray(presetArr)) {
                        horasConfiguradas = [...presetArr];
                        renderHorasTags();
                        actualizarGuiaScheduler();
                    }
                } catch(err) {
                    console.error("Error al cargar preset:", err);
                }
            });
        });

        // Cambio de Frecuencia (Pills)
        function cambiarFrecuencia(freq) {
            cfgProgFrecuencia.value = freq;
            document.querySelectorAll('.freq-opt-btn').forEach(b => {
                if (b.dataset.freq === freq) {
                    b.classList.add('active');
                } else {
                    b.classList.remove('active');
                }
            });

            if (panelFreqUnaVez) panelFreqUnaVez.classList.add('hidden');
            if (panelFreqVariasVeces) panelFreqVariasVeces.classList.add('hidden');
            if (panelFreqIntervalo) panelFreqIntervalo.classList.add('hidden');

            if (freq === 'una_vez' && panelFreqUnaVez) {
                panelFreqUnaVez.classList.remove('hidden');
            } else if (freq === 'varias_veces' && panelFreqVariasVeces) {
                panelFreqVariasVeces.classList.remove('hidden');
                renderHorasTags();
            } else if (freq === 'intervalo' && panelFreqIntervalo) {
                panelFreqIntervalo.classList.remove('hidden');
            }

            actualizarGuiaScheduler();
        }

        document.querySelectorAll('.freq-opt-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                cambiarFrecuencia(this.dataset.freq);
            });
        });

        function actualizarGuiaScheduler() {
            if (!schedulerGuiaTexto) return;
            const freq = cfgProgFrecuencia.value;
            if (freq === 'una_vez') {
                const h = document.getElementById('cfgProgHoraUnica')?.value || '08:00';
                schedulerGuiaTexto.innerHTML = `<span class="material-symbols-outlined text-sm text-tertiary shrink-0 mt-0.5">info</span> <span><strong>Recomendación Task Scheduler:</strong> Crea un desencadenador diario a la hora <strong>${h}</strong>.</span>`;
            } else if (freq === 'varias_veces') {
                const count = horasConfiguradas.length;
                const list = horasConfiguradas.join(', ');
                schedulerGuiaTexto.innerHTML = `<span class="material-symbols-outlined text-sm text-tertiary shrink-0 mt-0.5">info</span> <span><strong>Recomendación Task Scheduler:</strong> Se enviará en <strong>${count} horarios</strong> [${list}]. En el programador de tareas crea un trigger para esas horas o configúralo para repetir cada 1 hora.</span>`;
            } else if (freq === 'intervalo') {
                const inter = document.getElementById('cfgProgIntervalo')?.value || '4';
                schedulerGuiaTexto.innerHTML = `<span class="material-symbols-outlined text-sm text-tertiary shrink-0 mt-0.5">info</span> <span><strong>Recomendación Task Scheduler:</strong> En el programador de tareas activa la opción <em>"Repetir la tarea cada"</em> <strong>${inter} horas</strong>.</span>`;
            }
        }

        // Selección de Días de la Semana
        document.querySelectorAll('.dia-pill-label input[type="checkbox"]').forEach(chk => {
            chk.addEventListener('change', function() {
                const parent = this.closest('.dia-pill-label');
                if (this.checked) {
                    parent.className = 'dia-pill-label inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-xs font-bold cursor-pointer transition-all bg-primary text-white border-primary dark:bg-tertiary dark:text-slate-900 dark:border-tertiary';
                } else {
                    parent.className = 'dia-pill-label inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-xs font-bold cursor-pointer transition-all bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700';
                }
            });
        });

        document.getElementById('btnDiasLunVie')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.dia-pill-label input[type="checkbox"]').forEach(chk => {
                const val = parseInt(chk.value, 10);
                chk.checked = (val >= 1 && val <= 5);
                chk.dispatchEvent(new Event('change'));
            });
        });

        document.getElementById('btnDiasTodos')?.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.dia-pill-label input[type="checkbox"]').forEach(chk => {
                chk.checked = true;
                chk.dispatchEvent(new Event('change'));
            });
        });

        // Presets rápidos para Modal General
        document.querySelectorAll('.btn-gen-preset').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const em = this.dataset.emails || '';
                const inputGen = document.getElementById('emailGenDestinatarios');
                if (inputGen) inputGen.value = em;
            });
        });

        // Selección de Lista / Tipo de Destinatarios en Configuración (Multi-Selección)
        const cfgDestinatariosBox = document.getElementById('cfgDestinatariosBox');
        const cfgTodosMedicosNotice = document.getElementById('cfgTodosMedicosNotice');

        function actualizarEstadoDestinatariosUI() {
            const chkTodosMedicos = document.querySelector('.tipo-dest-card[data-tipo="todos_medicos"] input[type="checkbox"]');
            const chkManual = document.querySelector('.tipo-dest-card[data-tipo="manual"] input[type="checkbox"]');

            document.querySelectorAll('.tipo-dest-card').forEach(card => {
                const chk = card.querySelector('input[type="checkbox"]');
                const icon = card.querySelector('.chk-icon');
                if (chk && chk.checked) {
                    card.className = 'tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none active bg-teal-50/70 dark:bg-slate-800 border-tertiary ring-2 ring-tertiary/20';
                    if (icon) {
                        icon.innerText = 'check_box';
                        icon.className = 'chk-icon material-symbols-outlined text-base text-tertiary font-bold';
                    }
                } else {
                    card.className = 'tipo-dest-card p-3 rounded-2xl border transition-all cursor-pointer flex items-start gap-2.5 select-none bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 hover:border-slate-300';
                    if (icon) {
                        icon.innerText = 'check_box_outline_blank';
                        icon.className = 'chk-icon material-symbols-outlined text-base text-slate-300 dark:text-slate-600';
                    }
                }
            });

            if (cfgTodosMedicosNotice) {
                if (chkTodosMedicos && chkTodosMedicos.checked) {
                    cfgTodosMedicosNotice.classList.remove('hidden');
                } else {
                    cfgTodosMedicosNotice.classList.add('hidden');
                }
            }

            if (cfgDestinatariosBox) {
                if (chkManual && chkManual.checked) {
                    cfgDestinatariosBox.classList.remove('hidden');
                } else {
                    cfgDestinatariosBox.classList.add('hidden');
                }
            }
        }

        // Manejar cambio en los checkboxes de los tipos de destinatarios
        document.querySelectorAll('.tipo-dest-card input[type="checkbox"]').forEach(chk => {
            chk.addEventListener('change', function() {
                actualizarEstadoDestinatariosUI();
            });
        });

        // Soporte de accesibilidad por teclado (Espacio / Enter) en las tarjetas de destinatarios
        document.querySelectorAll('.tipo-dest-card').forEach(card => {
            card.setAttribute('tabindex', '0');
            card.addEventListener('keydown', function(e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    const chk = this.querySelector('input[type="checkbox"]');
                    if (chk) {
                        chk.checked = !chk.checked;
                        chk.dispatchEvent(new Event('change'));
                    }
                }
            });
        });

        // Abrir Modal Configuración
        document.getElementById('btnOpenConfigModal').addEventListener('click', function() {
            abrirModal('modalConfig');
            cambiarFrecuencia(cfgProgFrecuencia.value);
            renderHorasTags();
            actualizarEstadoDestinatariosUI();
        });

        // Guardar Formulario de Configuración
        document.getElementById('formConfig').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitConfig');

            const diasSeleccionados = [];
            document.querySelectorAll('input[name="cfgDiasActivos"]:checked').forEach(c => diasSeleccionados.push(c.value));

            const tiposSeleccionados = [];
            document.querySelectorAll('input[name="cfgTiposDestinatarios[]"]:checked').forEach(c => tiposSeleccionados.push(c.value));

            if (tiposSeleccionados.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Destinatario Requerido',
                    text: 'Debes seleccionar al menos una opción de destinatario (Directora Médica, Rol Admin, Rol Financiero, Médicos o Personalizado).'
                });
                return;
            }

            btn.disabled = true;

            const formData = new FormData();
            formData.append('fecha_modo', document.getElementById('cfgFechaModo').value);
            formData.append('fecha_inicio', document.getElementById('cfgFechaInicio').value);
            formData.append('fecha_fin', document.getElementById('cfgFechaFin').value);
            formData.append('tipos_destinatarios', JSON.stringify(tiposSeleccionados));
            formData.append('destinatarios', document.getElementById('cfgDestinatarios')?.value || '');
            formData.append('asunto_personalizado', document.getElementById('cfgAsunto').value);

            // Programación
            const freq = cfgProgFrecuencia.value;
            formData.append('prog_frecuencia', freq);
            formData.append('prog_hora_unica', document.getElementById('cfgProgHoraUnica')?.value || '08:00');
            formData.append('prog_horas', JSON.stringify(horasConfiguradas));
            formData.append('prog_intervalo_horas', document.getElementById('cfgProgIntervalo')?.value || '4');
            formData.append('prog_dias_activos', JSON.stringify(diasSeleccionados));
            formData.append('prog_validar_horario', '0');

            // Seguridad en Desarrollo
            const chkBloqMed = document.getElementById('cfgBloquearCorreosMedicos');
            if (chkBloqMed) {
                formData.append('bloquear_correos_medicos', chkBloqMed.checked ? '1' : '0');
            }
            const inpRedir = document.getElementById('cfgEmailTestRedireccion');
            if (inpRedir) {
                formData.append('email_test_redireccion', inpRedir.value.trim());
            }

            fetch('alerta_medicos.php?action=save_config', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                if (res.success) {
                    cerrarModal('modalConfig');

                    // Actualizar KPI Card de Horarios
                    if (kpiHorariosTexto) {
                        if (freq === 'una_vez') {
                            const h = document.getElementById('cfgProgHoraUnica')?.value || '08:00';
                            kpiHorariosTexto.innerText = `${h} (1 vez/día)`;
                        } else if (freq === 'varias_veces') {
                            kpiHorariosTexto.innerText = `${horasConfiguradas.join(', ')} (${horasConfiguradas.length} veces/día)`;
                        } else {
                            const inter = document.getElementById('cfgProgIntervalo')?.value || '4';
                            kpiHorariosTexto.innerText = `Cada ${inter} horas`;
                        }
                    }

                    // Actualizar KPI Card de Destinatarios
                    const kpiDest = document.getElementById('kpiDestinatariosTexto');
                    if (kpiDest) {
                        const labels = [];
                        if (tiposSeleccionados.includes('directora_medica')) {
                            labels.push('Directora Médica');
                        }
                        if (tiposSeleccionados.includes('rol_admin') || tiposSeleccionados.includes('admin') || tiposSeleccionados.includes('directora_admins')) {
                            labels.push('Admin');
                        }
                        if (tiposSeleccionados.includes('rol_financiero') || tiposSeleccionados.includes('financiero')) {
                            labels.push('Financiero');
                        }
                        if (tiposSeleccionados.includes('todos_medicos')) {
                            labels.push('Médicos');
                        }
                        if (tiposSeleccionados.includes('manual')) {
                            labels.push('Personalizado');
                        }
                        kpiDest.innerText = labels.length ? labels.join(' • ') : 'Directora Médica';
                    }

                    Swal.fire({
                        icon: 'success',
                        title: '¡Programación Guardada!',
                        text: res.mensaje || 'Se actualizaron los horarios y destinatarios de envío automático.'
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.mensaje });
                }
            })
            .catch(err => {
                btn.disabled = false;
                Swal.fire({ icon: 'error', title: 'Error de Red', text: 'No se pudo guardar la configuración.' });
            });
        });

        // Inicializar
        if (typeof cfgProgFrecuencia !== 'undefined' && cfgProgFrecuencia) {
            cambiarFrecuencia(cfgProgFrecuencia.value);
        }
        renderHorasTags();
        actualizarEstadoDestinatariosUI();
        cargarDatos();

    });
    </script>
</body>
</html>
