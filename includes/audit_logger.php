<?php
/**
 * Módulo de Auditoría de Cambios de Tarifario
 * LIHO - Liquidación Hospitalaria
 */

if (!function_exists('obtenerIpCliente')) {
    function obtenerIpCliente() {
        $ip = '0.0.0.0';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return substr($ip, 0, 45);
    }
}

if (!function_exists('obtenerUserAgent')) {
    function obtenerUserAgent() {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido', 0, 255);
    }
}

/**
 * Registra una modificación o acción en el historial de auditoría del tarifario
 */
if (!function_exists('registrarAuditoriaTarifario')) {
    function registrarAuditoriaTarifario(
        $con,
        $tarifarioId,
        $codigoExamen,
        $examenNombre,
        $usuarioId,
        $usuarioNombre,
        $usuarioEmail,
        $usuarioRol,
        $campoModificado,
        $valorAnterior,
        $valorNuevo,
        $motivoCambio = null,
        $tipoAccion = 'EDICION',
        $origen = 'TARIFARIO_GENERAL'
    ) {
        if (!$con) return false;

        $ipOrigen = obtenerIpCliente();
        $userAgent = obtenerUserAgent();

        // 1. Insertar en tabla de historial
        $sqlInsert = "INSERT INTO historial_cambios_tarifario 
            (tarifario_id, codigo_examen, examen_nombre, usuario_id, usuario_nombre, usuario_email, usuario_rol, campo_modificado, valor_anterior, valor_nuevo, motivo_cambio, ip_origen, user_agent, tipo_accion, fecha_cambio) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

        $params = array(
            $tarifarioId,
            $codigoExamen,
            $examenNombre,
            $usuarioId,
            $usuarioNombre,
            $usuarioEmail,
            $usuarioRol,
            $campoModificado,
            (string)$valorAnterior,
            (string)$valorNuevo,
            $motivoCambio,
            $ipOrigen,
            $userAgent,
            $tipoAccion
        );

        $stmtIns = sqlsrv_query($con, $sqlInsert, $params);

        // 2. Registrar en la tabla central unificada dbo.logs_sistema
        require_once __DIR__ . '/logger_helper.php';
        if (function_exists('registrar_log_sistema')) {
            $entidad = "CUPS: {$codigoExamen} - {$examenNombre}";
            $det = "Campo: {$campoModificado}" . (!empty($motivoCambio) ? " | Motivo: {$motivoCambio}" : "");
            $nivel = in_array(strtoupper($tipoAccion), ['INACTIVACION', 'ELIMINACION', 'DESACTIVACION']) ? 'WARNING' : 'INFO';
            
            registrar_log_sistema('TARIFARIO', "CAMBIO_{$tipoAccion}", $tipoAccion, $det, [
                'usuario_id' => $usuarioId,
                'usuario_nombre' => $usuarioNombre,
                'usuario_email' => $usuarioEmail,
                'usuario_rol' => $usuarioRol,
                'entidad_afectada' => $entidad,
                'valor_anterior' => $valorAnterior,
                'valor_nuevo' => $valorNuevo,
                'ip_origen' => $ipOrigen,
                'user_agent' => $userAgent,
                'nivel' => $nivel
            ]);
        }

        // 3. Actualizar metadatos rápidos en tabla principal según origen
        if ($origen === 'TARIFARIO_ESPECIAL') {
            $sqlUpdate = "UPDATE tarifario_especial SET fecha_actualizacion = GETDATE(), usuario_id = ? WHERE id = ?";
            sqlsrv_query($con, $sqlUpdate, array($usuarioId, $tarifarioId));
        } else {
            $sqlUpdate = "UPDATE tarifario SET fecha_ultima_modificacion = GETDATE(), usuario_ultimo_cambio_id = ? WHERE id = ?";
            sqlsrv_query($con, $sqlUpdate, array($usuarioId, $tarifarioId));
        }

        return ($stmtIns !== false);
    }
}
?>
