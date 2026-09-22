<?php
/**
 * ============================================================================
 * SCRIPT DE EJECUCIÓN PROGRAMADA (CLI / TASK SCHEDULER) - ALERTA MÉDICOS LIHO
 * ============================================================================
 * IPS Hernán Ocazionez y Cía S.A.S. - Sistema LIHO
 * 
 * Diseñado para ejecutarse de forma independiente en segundo plano mediante
 * Windows Task Scheduler o línea de comandos:
 * 
 *    C:\xampp\php\php.exe C:\xampp\htdocs\LIHO\cron_alerta_medicos.php
 *    C:\xampp\php\php.exe C:\xampp\htdocs\LIHO\cron_alerta_medicos.php --force
 */

date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/conexion_external.php';
require_once __DIR__ . '/includes/smtp_mailer.php';

// Función para registrar mensajes de log
function registrar_log_cron($mensaje) {
    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $fecha = date('d/m/Y h:i:s A');
    $linea = "[$fecha] [CRON_CLI] $mensaje\n";
    echo $linea;
    $archivoLog = $logDir . '/alerta_medicos_' . date('Y-m') . '.log';
    @file_put_contents($archivoLog, $linea, FILE_APPEND);
}

// Cargar configuración guardada
function obtener_config_cron() {
    $configFile = __DIR__ . '/config/alerta_medicos_config.json';
    $default = [
        'fecha_modo' => 'rango_fijo',
        'fecha_inicio' => '2026-05-01',
        'fecha_fin' => '2026-08-31',
        'destinatarios' => ['juane6462@gmail.com'],
        'asunto_personalizado' => 'ALERTA: Pacientes En Curso No Finalizados',
        'programacion' => [
            'frecuencia' => 'varias_veces',
            'hora_unica' => '08:00',
            'horas' => ['08:00', '13:00', '18:00'],
            'intervalo_horas' => 4,
            'dias_activos' => ['1', '2', '3', '4', '5'],
            'validar_horario' => false
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
            }
            if (isset($json['destinatarios']) && is_array($json['destinatarios'])) {
                $res['destinatarios'] = array_values($json['destinatarios']);
            }
            return $res;
        }
    }
    return $default;
}

// Generación de Excel nativo .xls
function generar_excel_cron($pacientes, $fecha_inicio = '', $fecha_fin = '', $titulo = 'Pacientes En Curso') {
    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    $html .= '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>' . htmlspecialchars($titulo) . '</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    $html .= '<style>
        body { font-family: Calibri, Arial, sans-serif; }
        table { border-collapse: collapse; }
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

// Obtener Logo Corporativo Incrustado
function obtener_logo_incrustado() {
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

// Plantilla HTML de Alerta General
function generar_html_correo_cron($pacientes, $fecha_inicio, $fecha_fin, $nombreArchivoExcel) {
    $total = count($pacientes);
    $fechaGeneracion = date('d/m/Y h:i A');

    $medicosUnicos = [];
    foreach ($pacientes as $p) {
        $m = trim($p['medico'] ?? '');
        if (!empty($m)) $medicosUnicos[$m] = true;
    }
    $totalMedicos = count($medicosUnicos);

    return "<!DOCTYPE html>
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
                                    <span style='word-break: break-word;'>Se adjunta el reporte detallado <strong>" . htmlspecialchars($nombreArchivoExcel) . "</strong> con los {$total} registros para gestión y auditoría directa.</span>
                                </div>
                            </div>

                            <p style='font-size: 13.5px; color: #334155; line-height: 1.55;'>
                                Este informe tiene como propósito facilitar la gestión oportuna con el equipo de especialistas y coordinar el cierre de las atenciones pendientes.
                            </p>

                            <p style='font-size: 12.5px; color: #64748b; margin-bottom: 0;'>
                                Fecha y hora de ejecución automática: <strong>{$fechaGeneracion}</strong>
                            </p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

// Plantilla HTML de Recordatorio a Médico Individual (Cron)
function generar_html_recordatorio_medico_cron($medicoNombre, $pacientes, $fecha_inicio, $fecha_fin, $nombreArchivoExcel) {
    $total = count($pacientes);
    $fechaGeneracion = date('d/m/Y h:i A');

    $filasHTML = '';
    $limite = min($total, 25);
    for ($i = 0; $i < $limite; $i++) {
        $p = $pacientes[$i];
        $bg = ($i % 2 === 0) ? '#ffffff' : '#f8fafc';
        $filasHTML .= "<tr style='background-color: {$bg}; border-bottom: 1px solid #e2e8f0;'>
            <td style='padding: 8px; font-size: 11px; text-align: center; color: #64748b;'>" . ($i + 1) . "</td>
            <td style='padding: 8px; font-size: 11px; font-weight: bold; color: #1e293b;'>" . htmlspecialchars($p['documento']) . "</td>
            <td style='padding: 8px; font-size: 11px; color: #334155;'>" . htmlspecialchars($p['nombre']) . "</td>
            <td style='padding: 8px; font-size: 11px; color: #64748b;'>" . htmlspecialchars($p['cups']) . "</td>
            <td style='padding: 8px; font-size: 11px; color: #64748b;'>" . htmlspecialchars($p['modalidad']) . "</td>
            <td style='padding: 8px; font-size: 11px; color: #64748b;'>" . htmlspecialchars($p['sede']) . "</td>
            <td style='padding: 8px; font-size: 11px; text-align: center;'><span style='background-color: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 10px;'>En Curso</span></td>
        </tr>";
    }

    $masMsg = ($total > 25) ? "<p style='font-size: 12px; color: #64748b; font-style: italic; margin-top: 8px;'>Mostrando primeros 25 registros. En el archivo Excel adjunto encontrará los {$total} pacientes completos.</p>" : "";

    return "<!DOCTYPE html>
    <html lang='es' xmlns='http://www.w3.org/1999/xhtml'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Recordatorio Pacientes En Curso</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 12px 6px; background-color: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; color: #1e293b; }
            .email-container { width: 100%; max-width: 640px; margin: 0 auto; background-color: #ffffff; border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; }
            .header { background: linear-gradient(135deg, #14354E 0%, #1e4867 100%); color: #ffffff; padding: 26px 22px; text-align: center; }
            .content { padding: 24px 22px; }
            .badge { display: inline-block; padding: 5px 12px; background: rgba(0,193,190,0.2); border: 1px solid #00c1be; border-radius: 20px; color: #00c1be; font-size: 11px; font-weight: bold; text-transform: uppercase; margin-bottom: 8px; }
        </style>
    </head>
    <body>
        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
            <tr>
                <td align='center'>
                    <div class='email-container'>
                        <div class='header'>
                            <div style='text-align: center; margin-bottom: 14px;'>
                                <img src='cid:logo_liho' alt='IPS Hernán Ocazionez' style='max-height: 48px; width: auto; display: inline-block;' />
                            </div>
                            <div class='badge'>RECORDATORIO A ESPECIALISTA</div>
                            <h1 style='margin: 0; font-size: 21px; font-weight: 800; color: #ffffff;'>Dr(a). " . htmlspecialchars($medicoNombre) . "</h1>
                            <p style='margin: 5px 0 0 0; font-size: 12.5px; color: #cbd5e1;'>IPS Hernán Ocazionez y Cía S.A.S. • Sistema LIHO</p>
                        </div>
                        <div class='content'>
                            <p style='font-size: 14.5px; line-height: 1.55; color: #334155; margin-top: 0;'>
                                Estimado(a) <strong>Dr(a). " . htmlspecialchars($medicoNombre) . "</strong>,
                            </p>
                            <p style='font-size: 14px; line-height: 1.55; color: #475569;'>
                                Le informamos que a la fecha tiene <strong>{$total} paciente(s)</strong> registrados con procedimientos en estado <span style='background-color: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 4px; font-weight: bold;'>En Curso</span> pendientes por finalizar en el sistema de gestión clínica <strong>PROTEO</strong>.
                            </p>
                            <p style='font-size: 13.5px; line-height: 1.5; color: #475569;'>
                                Le solicitamos cordialmente revisar y culminar el registro de las atenciones pendientes a la mayor brevedad.
                            </p>

                            <!-- Tabla de Pacientes -->
                            <div style='overflow-x: auto; margin: 18px 0; border: 1px solid #e2e8f0; border-radius: 10px;'>
                                <table width='100%' cellpadding='0' cellspacing='0' border='0' style='border-collapse: collapse; font-size: 11px;'>
                                    <thead>
                                        <tr style='background-color: #14354E; color: #ffffff; text-align: left;'>
                                            <th style='padding: 8px;'>#</th>
                                            <th style='padding: 8px;'>Documento</th>
                                            <th style='padding: 8px;'>Paciente</th>
                                            <th style='padding: 8px;'>CUPS</th>
                                            <th style='padding: 8px;'>Modalidad</th>
                                            <th style='padding: 8px;'>Sede</th>
                                            <th style='padding: 8px; text-align: center;'>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {$filasHTML}
                                    </tbody>
                                </table>
                            </div>
                            {$masMsg}

                            <!-- Banner Adjunto -->
                            <div style='background-color: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 10px; padding: 14px 16px; margin: 18px 0;'>
                                <div style='font-size: 13.5px; color: #065f46;'>
                                    <strong>Archivo Excel Adjunto:</strong><br>
                                    Se adjunta el reporte detallado <strong>" . htmlspecialchars($nombreArchivoExcel) . "</strong> con los casos asignados.
                                </div>
                            </div>

                            <p style='font-size: 12px; color: #64748b; margin-bottom: 0;'>
                                Fecha de recordatorio automático: <strong>{$fechaGeneracion}</strong>
                            </p>
                        </div>
                        <div style='background-color: #f8fafc; color: #64748b; padding: 14px 20px; text-align: center; font-size: 11.5px; border-top: 1px solid #e2e8f0;'>
                            <p style='margin: 0;'>Mensaje automático generado por el <strong>Sistema LIHO - Alerta Médicos</strong>.</p>
                            <p style='margin: 3px 0 0 0;'>IPS Hernán Ocazionez y Cía S.A.S.</p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

// INICIO DEL PROCESO
registrar_log_cron("=== INICIANDO TAREA AUTOMÁTICA DE ALERTA MÉDICOS ===");

$config = obtener_config_cron();

// Evaluar argumentos CLI
$isForce = false;
foreach ($argv ?? [] as $arg) {
    if ($arg === '--force' || $arg === '-f') {
        $isForce = true;
        break;
    }
}

// Validar Horarios y Días de Programación
$prog = $config['programacion'] ?? [];
$frecuencia = $prog['frecuencia'] ?? 'varias_veces'; // 'una_vez', 'varias_veces', 'intervalo'
$horasProgramadas = $prog['horas'] ?? ['08:00', '13:00', '18:00'];
$horaUnica = $prog['hora_unica'] ?? '08:00';
$diasActivos = $prog['dias_activos'] ?? ['1', '2', '3', '4', '5'];
$validarHorario = !empty($prog['validar_horario']);

$diaSemanaHoy = (string)date('N'); // 1 = Lunes, 7 = Domingo
$horaActual = date('H:i');

if (!$isForce && !in_array($diaSemanaHoy, $diasActivos, true)) {
    registrar_log_cron("DÍA NO PROGRAMADO: Hoy es día $diaSemanaHoy de la semana (desactivado en programación). Tarea finalizada sin envío.");
    exit(0);
}

if (!$isForce && $validarHorario) {
    $debeEjecutar = false;
    $motivoCoincidencia = '';

    if ($frecuencia === 'una_vez') {
        $minDiff = abs(strtotime("today $horaActual") - strtotime("today $horaUnica")) / 60;
        if ($minDiff <= 35) {
            $debeEjecutar = true;
            $motivoCoincidencia = "Hora única programada ($horaUnica)";
        }
    } elseif ($frecuencia === 'varias_veces') {
        foreach ($horasProgramadas as $hProg) {
            $minDiff = abs(strtotime("today $horaActual") - strtotime("today $hProg")) / 60;
            if ($minDiff <= 35) {
                $debeEjecutar = true;
                $motivoCoincidencia = "Horario programado ($hProg)";
                break;
            }
        }
    } elseif ($frecuencia === 'intervalo') {
        $intervalo = intval($prog['intervalo_horas'] ?? 4);
        if ($intervalo > 0 && (intval(date('H')) % $intervalo === 0)) {
            $debeEjecutar = true;
            $motivoCoincidencia = "Intervalo cada $intervalo horas";
        }
    }

    if (!$debeEjecutar) {
        $horasStr = ($frecuencia === 'una_vez') ? $horaUnica : implode(', ', $horasProgramadas);
        registrar_log_cron("HORARIO NO COINCIDENTE: Hora actual ($horaActual) no coincide con los horarios configurados [$horasStr]. Omitiendo despacho (Use --force para forzar).");
        exit(0);
    } else {
        registrar_log_cron("HORARIO COINCIDENTE: $motivoCoincidencia. Procediendo con la consulta y despacho.");
    }
}

$fecha_modo   = $config['fecha_modo'] ?? 'rango_fijo';
$fecha_inicio = $config['fecha_inicio'] ?? '2026-05-01';
$fecha_fin    = $config['fecha_fin'] ?? '2026-08-31';

if ($fecha_modo === 'mes_actual') {
    $fecha_inicio = date('Y-m-01');
    $fecha_fin    = date('Y-m-t');
} elseif ($fecha_modo === 'ultimos_30_dias') {
    $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
    $fecha_fin    = date('Y-m-d');
} elseif ($fecha_modo === 'hoy') {
    $fecha_inicio = date('Y-m-d');
    $fecha_fin    = date('Y-m-d');
}

$tipos_destinatarios = $config['tipos_destinatarios'] ?? (isset($config['tipo_destinatario']) ? [$config['tipo_destinatario']] : ['directora_medica']);
if (!is_array($tipos_destinatarios)) {
    $tipos_destinatarios = [$tipos_destinatarios];
}

$asunto = $config['asunto_personalizado'] ?? 'ALERTA: Pacientes En Curso No Finalizados';

$horasInfo = ($frecuencia === 'una_vez') ? $horaUnica : implode(', ', $horasProgramadas);
registrar_log_cron("Parámetros: Rango [$fecha_inicio a $fecha_fin] (Modo: $fecha_modo) | Frecuencia: $frecuencia [$horasInfo] | Tipos Seleccionados: [" . implode(', ', $tipos_destinatarios) . "]");

// 1. Conexión a PROTEO (SQL Server)
$con = obtenerConexionProteo();
if ($con === false) {
    registrar_log_cron("FATAL: No se pudo conectar a SQL Server PROTEO.");
    exit(1);
}

// 2. Consulta SQL
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
    registrar_log_cron("ERROR EN CONSULTA SQL: $msg");
    exit(1);
}

// Catálogo de Médicos de la Base de Datos LIHO (Cruce de correo oficial)
function obtener_catalogo_medicos_liho_cron() {
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

$catalogoLIHO = obtener_catalogo_medicos_liho_cron();

$pacientes = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $fechaStr = 'N/A';
    if ($row['Fecha_Creacion'] instanceof DateTime) {
        $fechaStr = $row['Fecha_Creacion']->format('Y-m-d H:i');
    } elseif (!empty($row['Fecha_Creacion'])) {
        $fechaStr = (string)$row['Fecha_Creacion'];
    }

    $med = trim((string)($row['Medico'] ?? 'Médico No Asignado'));
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

    $pacientes[] = [
        'id'             => (string)$row['Id'],
        'documento'      => trim((string)($row['Documento'] ?? '')),
        'nombre'         => trim((string)($row['Nombre'] ?? '')),
        'fecha'          => $fechaStr,
        'ingreso'        => trim((string)($row['Ingreso'] ?? '')),
        'cups'           => trim((string)($row['CUPS'] ?? '')),
        'modalidad'      => trim((string)($row['Modalidad'] ?? '')),
        'estado'         => trim((string)($row['Estado_Actual'] ?? 'En Curso')),
        'sede'           => trim((string)($row['Sede'] ?? '')),
        'medico'         => $med,
        'usuario_medico' => $uMed,
        'correo_medico'  => $cMedFinal,
        'correo_liho'    => $cMedLIHO,
        'correo_proteo'  => $cMedProteo,
        'fuente_correo'  => $fuenteCorreo
    ];
}

$total = count($pacientes);
registrar_log_cron("Consulta finalizada: Se encontraron $total paciente(s) en curso.");

// 3. Envío de Correo si hay pacientes
if ($total > 0) {
    $embeddedImages = obtener_logo_incrustado();

    // 3.1. Si está activo "Todos los Médicos", despachar individualmente a cada especialista
    if (in_array('todos_medicos', $tipos_destinatarios, true)) {
        registrar_log_cron("=== INICIANDO DESPACHO INDIVIDUAL A MÉDICOS ESPECIALISTAS ===");
        require_once __DIR__ . '/includes/config_helper.php';
        if (estanCorreosMedicosBloqueados()) {
            registrar_log_cron("AVISO DE SEGURIDAD (MODO DESARROLLO): Los correos a médicos están bloqueados preventivamente. Se interceptarán y redirigirán al buzón de pruebas.");
        }
        $porMedico = [];
        foreach ($pacientes as $p) {
            $mNom = trim($p['medico'] ?? 'Sin Médico Asignado');
            $porMedico[$mNom][] = $p;
        }

        $despachadosMed = 0;
        $omitidosMed = 0;

        foreach ($porMedico as $medicoNombre => $pacientesMedico) {
            $totalMed = count($pacientesMedico);
            $correoMed = trim((string)($pacientesMedico[0]['correo_medico'] ?? ''));

            if (empty($correoMed) || !filter_var($correoMed, FILTER_VALIDATE_EMAIL)) {
                registrar_log_cron("OMITIDO: Dr(a). $medicoNombre ($totalMed pacientes) no tiene correo registrado en la BD de LIHO.");
                $omitidosMed++;
                continue;
            }

            $nombreArchivoExcel = "Pacientes_En_Curso_" . preg_replace('/[^A-Za-z0-9_]/', '_', $medicoNombre) . ".xls";
            $tituloExcel = "Pacientes_" . substr(preg_replace('/[^A-Za-z0-9_]/', '_', $medicoNombre), 0, 20);
            $contenidoExcel = generar_excel_cron($pacientesMedico, $fecha_inicio, $fecha_fin, $tituloExcel);

            $subjectMed = "RECORDATORIO: {$totalMed} Paciente(s) En Curso pendientes - Dr(a). {$medicoNombre}";
            $bodyHTMLMed = generar_html_recordatorio_medico_cron($medicoNombre, $pacientesMedico, $fecha_inicio, $fecha_fin, $nombreArchivoExcel);

            $attachmentsMed = array(
                array(
                    'name' => $nombreArchivoExcel,
                    'type' => 'application/vnd.ms-excel; charset=UTF-8',
                    'data' => $contenidoExcel
                )
            );

            $enviadoMed = enviarCorreoSMTP(array($correoMed), $subjectMed, $bodyHTMLMed, null, $embeddedImages, '', array(), $attachmentsMed);
            if ($enviadoMed) {
                $despachadosMed++;
                registrar_log_cron("ÉXITO: Recordatorio individual enviado a Dr(a). $medicoNombre ($correoMed) con $totalMed pacientes.");
                require_once __DIR__ . '/includes/logger_helper.php';
                if (function_exists('registrar_log_sistema')) {
                    registrar_log_sistema('ALERTA_MEDICOS', 'ALERTA_AUTOMATICA_CRON_MEDICO', 'ENVIO_CORREO', "Recordatorio individual despachado automáticamente vía Cron a: Dr(a). {$medicoNombre} ({$correoMed}) con {$totalMed} pacientes en curso", [
                        'entidad_afectada' => "Dr(a). {$medicoNombre} ({$correoMed})",
                        'usuario_nombre' => 'Sistema Automático CLI (Cron)',
                        'usuario_email' => 'cron@hernanocazionez.com',
                        'usuario_rol' => 'SISTEMA',
                        'nivel' => 'SUCCESS'
                    ]);
                }
            } else {
                registrar_log_cron("ERROR: Falló envío SMTP a Dr(a). $medicoNombre ($correoMed).");
            }
        }
        registrar_log_cron("RESUMEN DESPACHO INDIVIDUAL: $despachadosMed médicos notificados, $omitidosMed omitidos por falta de correo.");
    }

    // 3.2. Si está activo Directora Médica, Rol Admin, Rol Financiero, u otros roles o Personalizado, despachar Reporte Consolidado
    $destinatariosConsolidados = [];
    
    if (in_array('directora_medica', $tipos_destinatarios, true) || in_array('directora_admins', $tipos_destinatarios, true)) {
        $destinatariosConsolidados[] = 'dirmedica@hernanocazionez.com';
    }

    $rolesIdsAConsultar = [];
    if (in_array('rol_admin', $tipos_destinatarios, true) || in_array('admin', $tipos_destinatarios, true) || in_array('directora_admins', $tipos_destinatarios, true)) {
        $rolesIdsAConsultar[] = 1;
    }
    if (in_array('rol_financiero', $tipos_destinatarios, true) || in_array('financiero', $tipos_destinatarios, true)) {
        $rolesIdsAConsultar[] = 2;
    }
    foreach ($tipos_destinatarios as $t) {
        if (preg_match('/^rol_(\d+)$/', $t, $m)) {
            $rolesIdsAConsultar[] = intval($m[1]);
        }
    }
    $rolesIdsAConsultar = array_unique($rolesIdsAConsultar);

    if (!empty($rolesIdsAConsultar)) {
        $connLIHO = obtenerConexionLIHO();
        if ($connLIHO) {
            $rolesIn = implode(',', $rolesIdsAConsultar);
            $sqlA = "SELECT DISTINCT LOWER(u.email) AS email FROM dbo.usuarios u WITH (NOLOCK) WHERE u.estado = 1 AND u.rol_id IN ($rolesIn) AND u.email IS NOT NULL AND u.email <> ''";
            $stmtA = sqlsrv_query($connLIHO, $sqlA);
            if ($stmtA) {
                while ($rA = sqlsrv_fetch_array($stmtA, SQLSRV_FETCH_ASSOC)) {
                    $emA = trim((string)$rA['email']);
                    if (filter_var($emA, FILTER_VALIDATE_EMAIL)) $destinatariosConsolidados[] = $emA;
                }
            }
        }
    }

    if (in_array('manual', $tipos_destinatarios, true)) {
        $manualList = $config['destinatarios'] ?? [];
        if (is_string($manualList)) {
            $manualList = array_filter(array_map('trim', explode(',', $manualList)));
        }
        foreach ($manualList as $mEmail) {
            if (filter_var($mEmail, FILTER_VALIDATE_EMAIL)) $destinatariosConsolidados[] = strtolower($mEmail);
        }
    }

    $destinatariosConsolidados = array_values(array_unique(array_filter($destinatariosConsolidados)));

    if (!empty($destinatariosConsolidados)) {
        registrar_log_cron("=== INICIANDO DESPACHO CONSOLIDADO A DIRECCIÓN / ADMINS ===");
        $nombreArchivoExcel = "Reporte_Pacientes_En_Curso_{$fecha_inicio}_al_{$fecha_fin}.xls";
        $contenidoExcel = generar_excel_cron($pacientes, $fecha_inicio, $fecha_fin, "Pacientes_En_Curso");

        $subject = !empty($asunto) ? $asunto : "ALERTA: {$total} Paciente(s) En Curso No Finalizados ({$fecha_inicio} al {$fecha_fin})";
        $bodyHTML = generar_html_correo_cron($pacientes, $fecha_inicio, $fecha_fin, $nombreArchivoExcel);

        $attachments = array(
            array(
                'name' => $nombreArchivoExcel,
                'type' => 'application/vnd.ms-excel; charset=UTF-8',
                'data' => $contenidoExcel
            )
        );

        $enviadoConsolidado = enviarCorreoSMTP($destinatariosConsolidados, $subject, $bodyHTML, null, $embeddedImages, '', array(), $attachments);

        if ($enviadoConsolidado) {
            $destListStr = implode(', ', $destinatariosConsolidados);
            registrar_log_cron("FINALIZADO CON ÉXITO: Reporte consolidado y archivo Excel ($nombreArchivoExcel) enviados a " . $destListStr);
            
            require_once __DIR__ . '/includes/logger_helper.php';
            if (function_exists('registrar_log_sistema')) {
                registrar_log_sistema('ALERTA_MEDICOS', 'ALERTA_AUTOMATICA_CRON', 'ENVIO_CORREO', "Reporte consolidado de {$total} pacientes en curso despachado automáticamente vía Cron a: {$destListStr}", [
                    'entidad_afectada' => 'Destinatarios Consolidado (' . $destListStr . ')',
                    'usuario_nombre' => 'Sistema Automático CLI (Cron)',
                    'usuario_email' => 'cron@hernanocazionez.com',
                    'usuario_rol' => 'SISTEMA',
                    'nivel' => 'SUCCESS'
                ]);
            }
        } else {
            registrar_log_cron("ERROR: Falló el envío del correo SMTP consolidado a " . implode(', ', $destinatariosConsolidados));
        }
    }
} else {
    registrar_log_cron("Sin registros para alertar en este ciclo. No se requirió envío de correo.");
}

registrar_log_cron("=== TAREA AUTOMÁTICA FINALIZADA ===");
?>
