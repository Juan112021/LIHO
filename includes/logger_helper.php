<?php
/**
 * Helper Universal de Registro de Logs y Auditoría Centralizada en LIHO
 * Monitoriza movimientos en Seguridad, Tarifario, Correos, Alertas Médicas, Usuarios y Liquidaciones.
 */

if (!function_exists('obtener_ip_cliente_log')) {
    function obtener_ip_cliente_log() {
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

if (!function_exists('obtener_user_agent_log')) {
    function obtener_user_agent_log() {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido / CLI', 0, 290);
    }
}

if (!function_exists('registrar_log_sistema')) {
    function registrar_log_sistema($modulo, $evento, $tipoAccion, $detalles = '', $opciones = []) {
        require_once __DIR__ . '/../config/conexion.php';
        $con = obtenerConexionLIHO();
        if (!$con) return false;

        $ip = $opciones['ip_origen'] ?? obtener_ip_cliente_log();
        $userAgent = $opciones['user_agent'] ?? obtener_user_agent_log();
        
        $usuarioId = $opciones['usuario_id'] ?? ($_SESSION['user_id'] ?? null);
        $usuarioNombre = $opciones['usuario_nombre'] ?? ($_SESSION['user_name'] ?? (php_sapi_name() === 'cli' ? 'Sistema Automático (CLI)' : 'Sistema'));
        $usuarioEmail = $opciones['usuario_email'] ?? ($_SESSION['user_email'] ?? (php_sapi_name() === 'cli' ? 'cron@hernanocazionez.com' : ''));
        $usuarioRol = $opciones['usuario_rol'] ?? ($_SESSION['user_role'] ?? 'SISTEMA');
        
        $nivel = strtoupper($opciones['nivel'] ?? 'INFO');
        $entidad = substr($opciones['entidad_afectada'] ?? '', 0, 240);
        $valorAnterior = isset($opciones['valor_anterior']) ? (string)$opciones['valor_anterior'] : null;
        $valorNuevo = isset($opciones['valor_nuevo']) ? (string)$opciones['valor_nuevo'] : null;

        $sql = "INSERT INTO dbo.logs_sistema 
                (modulo, evento, tipo_accion, nivel, usuario_id, usuario_nombre, usuario_email, usuario_rol, ip_origen, user_agent, entidad_afectada, valor_anterior, valor_nuevo, detalles, fecha_registro) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

        $params = [
            strtoupper(trim($modulo)),
            strtoupper(trim($evento)),
            strtoupper(trim($tipoAccion)),
            $nivel,
            $usuarioId,
            $usuarioNombre,
            $usuarioEmail,
            $usuarioRol,
            $ip,
            $userAgent,
            $entidad,
            $valorAnterior,
            $valorNuevo,
            (string)$detalles
        ];

        $stmt = sqlsrv_query($con, $sql, $params);
        return ($stmt !== false);
    }
}

// Mantener retrocompatibilidad total con registrar_log_acceso
if (!function_exists('registrar_log_acceso')) {
    function registrar_log_acceso($con, $correo, $evento, $usuarioId = null, $detalles = '') {
        $ip = obtener_ip_cliente_log();
        $userAgent = obtener_user_agent_log();

        // 1. Registro en tabla anterior
        if ($con) {
            $sql = "INSERT INTO dbo.logs_acceso (usuario_id, correo, evento, ip_origen, user_agent, detalles, fecha_registro) 
                    VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
            $params = [$usuarioId, $correo, $evento, $ip, $userAgent, $detalles];
            @sqlsrv_query($con, $sql, $params);
        }

        // 2. Registro en tabla central unificada
        $tipoAccion = ($evento === 'LOGIN_EXITOSO' || $evento === 'LOGOUT') ? 'ACCESO' : 'FALLO';
        $nivel = ($evento === 'LOGIN_EXITOSO') ? 'SUCCESS' : (($evento === 'LOGOUT') ? 'INFO' : 'WARNING');
        
        return registrar_log_sistema('SEGURIDAD', $evento, $tipoAccion, $detalles, [
            'usuario_id' => $usuarioId,
            'usuario_email' => $correo,
            'entidad_afectada' => 'Usuario: ' . $correo,
            'ip_origen' => $ip,
            'user_agent' => $userAgent,
            'nivel' => $nivel
        ]);
    }
}
