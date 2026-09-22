<?php
date_default_timezone_set('America/Bogota');
/**
 * Conexión Encriptada AES-256 - Base de Datos SQL Server - LIHO
 * IPS Hernán Ocazionez y Cía S.A.S.
 */
require_once __DIR__ . '/security_crypto.php';

$enc_host = "Z0Z6VnhMaHlCNDhyRjV5TDF1WlVldz09"; // 192.168.4.7
$enc_db   = "K00xa3c3czFZYVdXbHRqT3Uxb2QzUT09"; // LIHO
$enc_uid  = "N3NRbmxWN1NaaFdjckg0aTVqZDNxQT09"; // sa
$enc_pwd  = "NU1hSXhocHJFeGhlSmd5d1dLcEpxQT09";

$servername = desencriptar_cadena($enc_host);
$dbname     = desencriptar_cadena($enc_db);
$uid        = desencriptar_cadena($enc_uid);
$pwd        = desencriptar_cadena($enc_pwd);

$connectionInfo = array(
    "Database"               => !empty($dbname) ? $dbname : "LIHO",
    "UID"                    => $uid,
    "PWD"                    => $pwd,
    "CharacterSet"           => "UTF-8",
    "Encrypt"                => "no",
    "TrustServerCertificate" => 1
);

// Intento de conexión con SQL Server
$con = false;
if (function_exists('sqlsrv_connect')) {
    $con = sqlsrv_connect($servername, $connectionInfo);
    if ($con === false) {
        error_log("Error de conexión a SQL Server LIHO: " . print_r(sqlsrv_errors(), true));
    }
}

// Función helper para comprobar la conexión activa
function obtenerConexionLIHO() {
    global $con;
    return $con;
}

if (!function_exists('obtenerConexionLIMED')) {
    function obtenerConexionLIMED() {
        return obtenerConexionLIHO();
    }
}
?>
