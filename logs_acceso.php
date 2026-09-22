<?php
/**
 * Redirección Unificada a Centro de Auditoría y Logs - LIHO
 */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

header("Location: logs.php?modulo=SEGURIDAD");
exit;
