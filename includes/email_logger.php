<?php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/smtp_mailer.php';

/**
 * Registra en la base de datos SQL Server en la tabla unificada sistema_auditoria_logs el historial de correos
 */
function registrarLogCorreo($usuarioId, $correoDestino, $asunto, $motivo, $detalles, $estado = 'EXITOSO') {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) {
            $con = obtenerConexionLIHO();
        } elseif (function_exists('obtenerConexionLIMED')) {
            $con = obtenerConexionLIMED();
        }
    }
    
    if ($con !== false) {
        // 1. Registro en tabla unificada sistema_auditoria_logs
        $sqlUni = "
        IF EXISTS (SELECT * FROM sys.tables WHERE name = 'sistema_auditoria_logs')
        BEGIN
            INSERT INTO sistema_auditoria_logs (
                modulo, registro_id, referencia, accion, estado_anterior, estado_nuevo,
                usuario_id, usuario_nombre, usuario_rol, observaciones, detalles_json,
                estado_operacion, ip_address, fecha_registro
            ) VALUES (
                'CORREO', ?, 'CORREO-SISTEMA', 'ENVIO_CORREO', NULL, ?,
                ?, 'Sistema Notificaciones', 'SISTEMA', ?, ?,
                ?, ?, GETDATE()
            )
        END";
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detallesJson = json_encode(array('correo_destino' => $correoDestino, 'asunto' => $asunto, 'motivo' => $motivo, 'detalles' => $detalles), JSON_UNESCAPED_UNICODE);
        $obs = "Envío de correo a " . $correoDestino . " | Motivo: " . $motivo . " (" . $estado . ")";
        $paramsUni = array($usuarioId, $estado, $usuarioId, $obs, $detallesJson, $estado, $ip);
        @sqlsrv_query($con, $sqlUni, $paramsUni);

        // 2. Registro de compatibilidad en logs_correos si existe
        $sql = "
        IF EXISTS (SELECT * FROM sys.tables WHERE name = 'logs_correos')
        BEGIN
            INSERT INTO logs_correos (usuario_id, correo_destino, asunto, motivo_envio, detalles, fecha_hora, estado_envio) 
            VALUES (?, ?, ?, ?, ?, GETDATE(), ?)
        END";
        $params = array($usuarioId, $correoDestino, $asunto, $motivo, $detalles, $estado);
        @sqlsrv_query($con, $sql, $params);
    }
}

/**
 * Envía un correo contextual cuando se actualizan datos de usuario y registra la auditoría
 */
function notificarActualizacionUsuario($usuarioId, $correoDestino, $nombreUsuario, array $cambiosRealizados) {
    if (empty($cambiosRealizados)) {
        return false;
    }

    $asunto = "Notificación de Actualización de Datos - LIHO";
    $motivo = "Actualización de Datos de Cuenta";

    // Cargar Logo optimizado para correo
    $logoPath = __DIR__ . '/../assets/img/logo_email_optimized.png';
    if (!file_exists($logoPath)) {
        $logoPath = __DIR__ . '/../assets/img/hologo.png';
    }
    if (!file_exists($logoPath)) {
        $logoPath = __DIR__ . '/../assets/img/Logo original.png';
    }
    $embeddedImages = array('logo_liho' => $logoPath);

    $fechaHora = date("d/m/Y h:i A");
    $nombreUsuarioHtml = htmlspecialchars($nombreUsuario);

    // Lista HTML de cambios
    $itemsHtml = '';
    $detallesTexto = "Cambios realizados (" . count($cambiosRealizados) . "): ";

    foreach ($cambiosRealizados as $campo => $detalle) {
        $campoNombre = htmlspecialchars($campo);
        $detalleValor = htmlspecialchars($detalle);
        $itemsHtml .= '<li style="margin-bottom: 8px; color: #14354e;"><strong>' . $campoNombre . ':</strong> ' . $detalleValor . '</li>';
        $detallesTexto .= "[$campoNombre: $detalleValor] ";
    }

    $cuerpo = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
            .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
            .header { text-align: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px; }
            .subtitle { color: #00c1be; font-size: 13px; font-weight: 700; margin: 8px 0 0 0; letter-spacing: 1px; }
            .changes-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; margin: 20px 0; }
            .notice { font-size: 12px; color: #64748b; line-height: 1.5; text-align: center; margin-top: 20px; }
            .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #94a3b8; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="header">
                <img src="cid:logo_liho" alt="Hernán Ocazionez Logo" style="max-height: 55px; width: auto; margin-bottom: 8px; display: inline-block;" /><br>
                <p class="subtitle">LIHO &bull; Notificación de Seguridad de la Cuenta</p>
            </div>
            <p style="color: #14354e; font-size: 16px; margin-bottom: 12px; font-weight: 600;">Estimado(a) ' . $nombreUsuarioHtml . ',</p>
            <p style="color: #475569; font-size: 14px; line-height: 1.6; margin-top: 0;">Le informamos que el día <strong>' . $fechaHora . '</strong> se han modificado los siguientes datos asociados a su cuenta en la plataforma de <strong>Liquidación Hospitalaria (LIHO)</strong>:</p>
            
            <div class="changes-box">
                <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #334155;">
                    ' . $itemsHtml . '
                </ul>
            </div>

            <p class="notice"><strong>Nota de Seguridad:</strong> Si usted no realizó o no autorizó estos cambios en su cuenta, por favor póngase en contacto inmediatamente con la administración del sistema.</p>

            <div class="footer">
                © ' . date("Y") . ' Hernán Ocazionez y Cía S.A.S. | LIHO V 1.0.0
            </div>
        </div>
    </body>
    </html>';

    $enviado = enviarCorreoSMTP($correoDestino, $asunto, $cuerpo, null, $embeddedImages, '', array('coordinacionsistemas@hernanocazionez.com.co', 'juane6462@gmail.com', 'contabilidad2@hernanocazionez.com'));
    $estadoStr = $enviado ? 'EXITOSO' : 'FALLIDO';

    registrarLogCorreo($usuarioId, $correoDestino, $asunto, $motivo, $detallesTexto, $estadoStr);

    return $enviado;
}
?>
