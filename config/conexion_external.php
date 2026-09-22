<?php
/**
 * Conexión Encriptada AES-256 a Bases de Datos Externas - LIHO
 * IPS Hernán Ocazionez y Cía S.A.S.
 * - SQL Server (PROTEO)
 * - Oracle SQL Developer (SERVINTE)
 */

require_once __DIR__ . '/security_crypto.php';

// Asegurar que PATH contenga la ruta a las DLLs de Oracle en Windows si no están en PATH
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $currentPath = getenv('PATH') ?: '';
    $oracleDllDir = 'C:\\xampp\\apache\\bin';
    if (file_exists($oracleDllDir . '\\oci.dll') && strpos($currentPath, $oracleDllDir) === false) {
        putenv("PATH={$oracleDllDir};" . $currentPath);
    }
}

/**
 * Obtiene una conexión activa a SQL Server (PROTEO)
 * @return resource|false
 */
function obtenerConexionProteo() {
    static $connProteo = null;
    if ($connProteo !== null && $connProteo !== false) {
        return $connProteo;
    }

    $enc_host = "MldQdDFFc0hnZ0xCZWV6WWNEbWo3dz09"; // 72.29.88.114
    $enc_db   = "NHZnakpOalFwN0tRZGtzdWxJNzFWZz09"; // PROTEO
    $enc_uid  = "d3k0M0FEazMyQmFGOFo4ZmM2cUVjQT09"; // reportes
    $enc_pwd  = "dFphdk84SDBsRGc5K3BvM2NVVDNqeHpwWXpXZGFPbFU1T2lrbE9iakpkRT0=";

    $host = desencriptar_cadena($enc_host);
    $db   = desencriptar_cadena($enc_db);
    $uid  = desencriptar_cadena($enc_uid);
    $pwd  = desencriptar_cadena($enc_pwd);

    $connectionInfo = array(
        "Database"               => !empty($db) ? $db : "PROTEO",
        "UID"                    => $uid,
        "PWD"                    => $pwd,
        "CharacterSet"           => "UTF-8",
        "Encrypt"                => "no",
        "TrustServerCertificate" => 1,
        "LoginTimeout"           => 10
    );

    if (function_exists('sqlsrv_connect')) {
        $connProteo = sqlsrv_connect($host, $connectionInfo);
        if ($connProteo === false) {
            error_log("Error de conexión a SQL Server PROTEO: " . print_r(sqlsrv_errors(), true));
        }
    } else {
        error_log("Driver sqlsrv_connect no está disponible en PHP.");
        $connProteo = false;
    }

    return $connProteo;
}

/**
 * Obtiene una conexión activa a Oracle SQL Developer (SERVINTE)
 * @return resource|false
 */
function obtenerConexionServinte() {
    static $connServinte = null;
    if ($connServinte !== null && $connServinte !== false) {
        return $connServinte;
    }

    $enc_uid = "dzFPL1NQRDBCdDllWXBTdWFUSEhNdz09"; // BASDAT
    $enc_pwd = "LzkrQm5MbEZKR2N0TGpiVHVUbSttUT09"; // Basd20*..
    $enc_db  = "L2JCNURVNkxxRngyMlJ3YTI4dXo4ZWJqcnU4Z2Y5b2Y0YXZMeUJsbFNLQT0="; // 192.168.4.5:1521/hodb

    $uid = desencriptar_cadena($enc_uid);
    $pwd = desencriptar_cadena($enc_pwd);
    $db  = desencriptar_cadena($enc_db);

    if (function_exists('oci_connect')) {
        $connServinte = @oci_connect($uid, $pwd, $db, 'AL32UTF8');
        if (!$connServinte) {
            $e = oci_error();
            error_log("Error de conexión a Oracle SERVINTE: " . ($e['message'] ?? 'Desconocido'));
            $connServinte = false;
        }
    } else {
        error_log("Extensión oci8 no está disponible en PHP.");
        $connServinte = false;
    }

    return $connServinte;
}
