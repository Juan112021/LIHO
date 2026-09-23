<?php
/**
 * Helper de Configuración Global del Sistema - LIHO
 * Hernán Ocazionez y Cía S.A.S.
 * 
 * Gestiona el modo de desarrollo y las reglas de seguridad de correo,
 * impidiendo el envío accidental de correos a médicos en fase de pruebas.
 */

if (!defined('CONFIG_SISTEMA_FILE')) {
    define('CONFIG_SISTEMA_FILE', __DIR__ . '/../config/config_sistema.json');
}

if (!function_exists('obtenerConexionLIHO') && file_exists(__DIR__ . '/../config/conexion.php')) {
    require_once __DIR__ . '/../config/conexion.php';
}

/**
 * Obtiene la configuración actual del sistema
 * @return array
 */
function obtenerConfiguracionSistema() {
    $defaultConfig = [
        'modo_desarrollo'          => true,
        'bloquear_correos_medicos' => true,
        'email_test_redireccion'   => 'juane6462@gmail.com',
        'permitir_envio_soporte'   => true,
        'fecha_actualizacion'      => date('Y-m-d H:i:s'),
        'usuario_actualizacion'    => 'Sistema'
    ];

    if (!file_exists(CONFIG_SISTEMA_FILE)) {
        file_put_contents(CONFIG_SISTEMA_FILE, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $defaultConfig;
    }

    $raw = @file_get_contents(CONFIG_SISTEMA_FILE);
    if (!$raw) {
        return $defaultConfig;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaultConfig;
    }

    return array_merge($defaultConfig, $data);
}

/**
 * Guarda la configuración del sistema
 * @param array $nuevaConfig
 * @param string $usuario
 * @return bool
 */
function guardarConfiguracionSistema(array $nuevaConfig, $usuario = 'Sistema') {
    $actual = obtenerConfiguracionSistema();

    $merged = array_merge($actual, $nuevaConfig);
    $merged['fecha_actualizacion'] = date('Y-m-d H:i:s');
    $merged['usuario_actualizacion'] = $usuario;

    // Forzar tipos booleanos
    if (isset($merged['modo_desarrollo'])) {
        $merged['modo_desarrollo'] = filter_var($merged['modo_desarrollo'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($merged['bloquear_correos_medicos'])) {
        $merged['bloquear_correos_medicos'] = filter_var($merged['bloquear_correos_medicos'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($merged['permitir_envio_soporte'])) {
        $merged['permitir_envio_soporte'] = filter_var($merged['permitir_envio_soporte'], FILTER_VALIDATE_BOOLEAN);
    }

    $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $ok = @file_put_contents(CONFIG_SISTEMA_FILE, $json, LOCK_EX);

    if ($ok && function_exists('registrarLog')) {
        $estadoModo = $merged['modo_desarrollo'] ? 'ACTIVADO' : 'DESACTIVADO';
        $estadoBloq = $merged['bloquear_correos_medicos'] ? 'BLOQUEADOS' : 'PERMITIDOS';
        registrarLog('CONFIGURACION', "Modo Desarrollo: {$estadoModo} | Correos Médicos: {$estadoBloq}", "Actualizado por {$usuario}");
    }

    return ($ok !== false);
}

/**
 * Determina si el envío de correos a médicos está actualmente bloqueado
 * @return bool
 */
function estanCorreosMedicosBloqueados() {
    $cfg = obtenerConfiguracionSistema();
    return (!empty($cfg['bloquear_correos_medicos']) || !empty($cfg['modo_desarrollo']));
}

/**
 * Retorna la lista de correos de administradores y personal institucional (Staff)
 * @return array
 */
function obtenerCorreosAdminsYStaff() {
    static $correosAdminsCache = null;
    if ($correosAdminsCache !== null) {
        return $correosAdminsCache;
    }

    $admins = [
        'juane6462@gmail.com',
        'coordinacionsistemas@hernanocazionez.com.co',
        'coordinacionsistemas@hernanocazionez.com',
        'dirasistencial@hernanocazionez.com',
        'contabilidad2@hernanocazionez.com',
        'rihoticketsho@gmail.com',
        'soporte@hernando-ocazionez.com'
    ];

    $cfg = obtenerConfiguracionSistema();
    $emailRedir = strtolower(trim($cfg['email_test_redireccion'] ?? ''));
    if (!empty($emailRedir) && filter_var($emailRedir, FILTER_VALIDATE_EMAIL)) {
        $admins[] = $emailRedir;
    }

    // Cargar dinámicamente desde SQL Server todos los usuarios con roles de Admin, Financiero u Observador (rol_id <> 3)
    if (function_exists('obtenerConexionLIHO')) {
        try {
            $conLiho = obtenerConexionLIHO();
            if ($conLiho) {
                $sql = "SELECT LOWER(LTRIM(RTRIM(u.email))) as email
                        FROM usuarios u
                        LEFT JOIN roles r ON CAST(u.rol_id AS VARCHAR) = CAST(r.id AS VARCHAR)
                        WHERE (u.rol_id IN ('0', '1', '2') 
                           OR LOWER(r.nombre) IN ('admin', 'administrador', 'financiero', 'auxiliar', 'financiera', 'observador')
                           OR (u.rol_id <> '3' AND u.id NOT IN (SELECT usuario_id FROM medicos WHERE usuario_id IS NOT NULL)))
                          AND u.email IS NOT NULL";
                $stmt = @sqlsrv_query($conLiho, $sql);
                if ($stmt) {
                    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                        $em = strtolower(trim($r['email'] ?? ''));
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $admins[] = $em;
                        }
                    }
                    @sqlsrv_free_stmt($stmt);
                }
            }
        } catch (Exception $e) {}
    }

    $correosAdminsCache = array_values(array_unique(array_filter($admins)));
    return $correosAdminsCache;
}

/**
 * Verifica si una dirección de correo pertenece a un Administrador o personal interno (Staff)
 * @param string $email
 * @return bool
 */
function esCorreoDeAdminOStaff($email) {
    $emailLimpio = strtolower(trim($email));
    if (empty($emailLimpio) || !filter_var($emailLimpio, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $admins = obtenerCorreosAdminsYStaff();
    if (in_array($emailLimpio, $admins, true)) {
        return true;
    }

    // Correos institucionales de Hernán Ocazionez que no sean de médicos
    if (str_ends_with($emailLimpio, '@hernanocazionez.com.co') || str_ends_with($emailLimpio, '@hernanocazionez.com')) {
        return true;
    }

    return false;
}

/**
 * Retorna la lista de correos de médicos registrados en el sistema
 * @return array
 */
function obtenerCorreosMedicosRegistrados() {
    static $correosMedicosCache = null;
    if ($correosMedicosCache !== null) {
        return $correosMedicosCache;
    }

    $correos = [];

    // 1. Cargar desde medicos_data.json
    $jsonFile = __DIR__ . '/../medicos_data.json';
    if (file_exists($jsonFile)) {
        $jsonContent = @file_get_contents($jsonFile);
        if ($jsonContent !== false) {
            $jsonContent = preg_replace('/^\xEF\xBB\xBF/', '', $jsonContent);
            $medicosData = json_decode($jsonContent, true);
            if (is_array($medicosData)) {
                foreach ($medicosData as $row) {
                    if (!empty($row['Correo'])) {
                        $em = strtolower(trim($row['Correo']));
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $correos[$em] = true;
                        }
                    }
                }
            }
        }
    }

    // 2. Cargar desde BD SQL Server si hay conexión activa (únicamente médicos rol_id = 3 o tabla medicos)
    if (function_exists('obtenerConexionLIHO')) {
        try {
            $conLiho = obtenerConexionLIHO();
            if ($conLiho) {
                $sqlMed = "SELECT LOWER(LTRIM(RTRIM(u.email))) as email 
                           FROM usuarios u 
                           LEFT JOIN roles r ON CAST(u.rol_id AS VARCHAR) = CAST(r.id AS VARCHAR) 
                           WHERE (u.rol_id = '3' 
                              OR LOWER(r.nombre) IN ('medico', 'médico') 
                              OR u.id IN (SELECT usuario_id FROM medicos WHERE usuario_id IS NOT NULL))
                             AND u.email IS NOT NULL";
                $stmt = @sqlsrv_query($conLiho, $sqlMed);
                if ($stmt) {
                    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                        $em = strtolower(trim($r['email'] ?? ''));
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                            $correos[$em] = true;
                        }
                    }
                    @sqlsrv_free_stmt($stmt);
                }
            }
        } catch (Exception $e) {}
    }

    // Excluir de forma estricta cualquier correo de administradores o personal interno
    $admins = obtenerCorreosAdminsYStaff();
    foreach ($admins as $adminEmail) {
        unset($correos[$adminEmail]);
    }

    $correosMedicosCache = array_keys($correos);
    return $correosMedicosCache;
}

/**
 * Verifica si una dirección de correo electrónico pertenece a un médico
 * @param string $email
 * @return bool
 */
function esCorreoDeMedico($email) {
    $emailLimpio = strtolower(trim($email));
    if (empty($emailLimpio) || !filter_var($emailLimpio, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // 1. Si es Administrador o personal interno (Staff), NUNCA es médico
    if (esCorreoDeAdminOStaff($emailLimpio)) {
        return false;
    }

    // 2. Si está en la lista de médicos registrados
    $medicos = obtenerCorreosMedicosRegistrados();
    if (in_array($emailLimpio, $medicos, true)) {
        return true;
    }

    // 3. Consulta de seguridad directa en BD si hay conexión
    if (function_exists('obtenerConexionLIHO')) {
        try {
            $conLiho = obtenerConexionLIHO();
            if ($conLiho) {
                $sql = "SELECT TOP 1 u.id, u.rol_id, r.nombre as rol_nombre 
                        FROM usuarios u 
                        LEFT JOIN roles r ON CAST(u.rol_id AS VARCHAR) = CAST(r.id AS VARCHAR) 
                        WHERE LOWER(RTRIM(LTRIM(u.email))) = LOWER(?)";
                $stmt = @sqlsrv_query($conLiho, $sql, [$emailLimpio]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $rolId = (string)($row['rol_id'] ?? '');
                    $rolNom = strtolower(trim($row['rol_nombre'] ?? ''));
                    @sqlsrv_free_stmt($stmt);
                    if ($rolId === '1' || $rolId === '2' || $rolId === '0' || in_array($rolNom, ['admin', 'administrador', 'financiero', 'observador'], true)) {
                        return false;
                    }
                    if ($rolId === '3' || in_array($rolNom, ['medico', 'médico'], true)) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {}
    }

    return false;
}

/**
 * Filtra destinatarios seguros cuando está activo el bloqueo de correos a médicos
 * @param array|string $destinatarios
 * @param array &$bloqueados Referencia para almacenar los correos que fueron bloqueados
 * @return array
 */
function filtrarDestinatariosSeguros($destinatarios, &$bloqueados = []) {
    $bloqueados = [];
    $resultado = [];

    $lista = is_array($destinatarios) ? $destinatarios : explode(',', (string)$destinatarios);
    $bloqueoActivo = estanCorreosMedicosBloqueados();

    foreach ($lista as $item) {
        $em = strtolower(trim($item));
        if (empty($em) || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if ($bloqueoActivo && esCorreoDeMedico($em)) {
            $bloqueados[] = $em;
        } else {
            $resultado[] = $em;
        }
    }

    return array_values(array_unique($resultado));
}
