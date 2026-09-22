<?php
/**
 * Módulo de Seguridad y Encriptación AES-256 - LIHO (Hernán Ocazionez y Cía S.A.S.)
 * Protege las cadenas de conexión a base de datos.
 */

if (!defined('LIHO_CRYPTO_KEY')) {
    define('LIHO_CRYPTO_KEY', hash('sha256', 'LIHO_SECURE_KEY_2026_HERNAN_OCAZIONEZ_SEC_#99314'));
}

if (!defined('LIHO_CRYPTO_IV')) {
    define('LIHO_CRYPTO_IV', substr(hash('sha256', 'LIHO_IV_SALT_SECURE_77218'), 0, 16));
}

function desencriptar_cadena($base64Data) {
    if (empty($base64Data)) return '';
    $cipher = "AES-256-CBC";
    $key = LIHO_CRYPTO_KEY;
    $iv  = LIHO_CRYPTO_IV;
    $decrypted = openssl_decrypt(base64_decode($base64Data), $cipher, $key, 0, $iv);
    return $decrypted !== false ? $decrypted : '';
}

function encriptar_cadena($plainText) {
    if (empty($plainText)) return '';
    $cipher = "AES-256-CBC";
    $key = LIHO_CRYPTO_KEY;
    $iv  = LIHO_CRYPTO_IV;
    $encrypted = openssl_encrypt($plainText, $cipher, $key, 0, $iv);
    return base64_encode($encrypted);
}
?>
