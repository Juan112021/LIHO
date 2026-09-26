<?php
date_default_timezone_set('America/Bogota');
/**
 * Módulo de Gestión Médicos Procedimientos (PROTEO vs SERVINTE) - LIHO
 * IPS Hernán Ocazionez y Cía S.A.S.
 * - Cruce Bidireccional de Procedimientos con estado 'Enviado a Enfermeria'.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/conexion_external.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/liquidaciones_helper.php';

$userId     = $_SESSION['user_id'] ?? 0;
$userRole   = $_SESSION['user_role'] ?? 'MÉDICO';
$userRoleId = $_SESSION['user_role_id'] ?? 3;

$canGenerateLiquidation = ($userRoleId == 1 || $userRoleId == 2 || in_array(strtoupper($userRole), ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']));

$entidadActivaId     = $_SESSION['entidad_liquidacion_id'] ?? 'PROPIO';
$entidadActivaNombre = $_SESSION['entidad_liquidacion_nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.';
$entidadActivaNit    = $_SESSION['entidad_liquidacion_nit'] ?? '';
$entidadActivaLogo   = $_SESSION['entidad_liquidacion_logo'] ?? 'assets/img/hologo.png';

// Verificar permiso del módulo
if (!tienePermisoModulo($userId, $userRoleId, 'gestion_medicos_procedimientos') && !tienePermisoModulo($userId, $userRoleId, 'examenes_medicos')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// --------------------------------------------------------------------------
// PROCESAMIENTO AJAX / EXPORTACIÓN
// --------------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'obtener_detalle_evento') {
    header('Content-Type: application/json');
    $eventoId = trim((string)($_GET['evento_id'] ?? ($_POST['evento_id'] ?? '')));
    if (empty($eventoId)) {
        echo json_encode(['success' => false, 'detail' => '']);
        exit;
    }
    $connProteo = obtenerConexionProteo();
    if (!$connProteo) {
        echo json_encode(['success' => false, 'detail' => '']);
        exit;
    }
    $stmtD = sqlsrv_query($connProteo, "SELECT TOP 1 Detail FROM dbo.AppEventDetails WITH (NOLOCK) WHERE EventId = ? AND IsDeleted = 0 ORDER BY LastModificationTime DESC", [$eventoId]);
    $detailVal = '';
    if ($stmtD && $rowD = sqlsrv_fetch_array($stmtD, SQLSRV_FETCH_ASSOC)) {
        $detailVal = (string)($rowD['Detail'] ?? '');
    }
    echo json_encode(['success' => true, 'detail' => $detailVal]);
    exit;
}

if ($action === 'guardar_liquidacion') {
    header('Content-Type: application/json');
    if (!$canGenerateLiquidation) {
        echo json_encode(array('success' => false, 'error' => 'No tienes permiso para registrar liquidaciones.'));
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);

    if (!$inputData) {
        $inputData = $_POST;
    }

    $userNameVal = $_SESSION['user_name'] ?? 'Usuario';
    $res = guardarLiquidacionBD($inputData, $userId, $userNameVal, $userRole);

    echo json_encode($res);
    exit;
}

if ($action === 'obtener_novedades_entidad') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $entId = $_GET['entidad_id'] ?? ($entidadActivaId ?? 'PROPIO');
    $novedades = obtenerNovedadesEntidadBD($entId, true);
    echo json_encode(array('success' => true, 'data' => $novedades));
    exit;
}

if ($action === 'registrar_log_toggle_novedades') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $refId  = $_GET['referencia_id'] ?? 'PRE-LIQ';
    $entId  = $_GET['entidad_id'] ?? ($entidadActivaId ?? 'PROPIO');
    $medico = $_GET['medico'] ?? 'MÉDICO';
    $activo = isset($_GET['activo']) ? ($_GET['activo'] === '1' || $_GET['activo'] === 'true') : true;

    $userNameVal = $_SESSION['user_name'] ?? 'Usuario';
    registrarLogToggleNovedades('LIQUIDACION', $refId, $entId, $medico, $userId, $userNameVal, $userRole, $activo);
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'fetch_data' || $action === 'export_excel') {
    // Parámetros de filtro
    $fechaDesde   = $_GET['fecha_desde'] ?? ($_POST['fecha_desde'] ?? date('Y-m-d 00:00:00'));
    $fechaHasta   = $_GET['fecha_hasta'] ?? ($_POST['fecha_hasta'] ?? date('Y-m-d 23:59:59'));
    $medicoFiltro = trim($_GET['medico'] ?? ($_POST['medico'] ?? ''));
    $estadoFiltro = trim($_GET['estado'] ?? ($_POST['estado'] ?? ''));
    $cruceFiltro  = trim($_GET['cruce'] ?? ($_POST['cruce'] ?? ''));
    $searchKey    = trim($_GET['search'] ?? ($_POST['search'] ?? ''));

    // Formatear fechas para SQL Server (YYYY-MM-DD HH:MM:SS)
    if (strlen($fechaDesde) === 10) $fechaDesde .= ' 00:00:00';
    if (strlen($fechaHasta) === 10) $fechaHasta .= ' 23:59:59';

    // 1. Obtener conexión PROTEO, SERVINTE y LIHO
    $connProteo   = obtenerConexionProteo();
    $connServinte = obtenerConexionServinte();
    $connLIHO     = obtenerConexionLIHO();

    $records  = [];
    $errorMsg = '';

    if ($connProteo === false || $connServinte === false) {
        $errorMsg = "No se pudo establecer conexión con una de las bases de datos (PROTEO o SERVINTE). Verifique la conectividad de red.";
    } else {
        // Consultar médicos con modalidad Bloqueos_HO en LIHO
        $medicosBloqueoHOUsernames = [];
        $medicosBloqueOHONombres   = [];
        if ($connLIHO !== false) {
            $sqlBMed = "SELECT m.usuario_proteo, m.cedula, u.nombre_completo,
                               ISNULL(m.modalidades_adicionales, ISNULL(u.modalidades_adicionales, '')) AS modalidades_adicionales
                        FROM dbo.medicos m 
                        LEFT JOIN dbo.usuarios u ON m.usuario_id = u.id";
            $stmtBMed = sqlsrv_query($connLIHO, $sqlBMed);
            if ($stmtBMed !== false) {
                while ($rB = sqlsrv_fetch_array($stmtBMed, SQLSRV_FETCH_ASSOC)) {
                    $mStr = strtoupper(trim((string)($rB['modalidades_adicionales'] ?? '')));
                    $mArr = !empty($mStr) ? array_filter(array_map('trim', explode(',', $mStr))) : [];
                    if (in_array('BLOQUEOS_HO', $mArr) || in_array('BLOQUEO_HO', $mArr)) {
                        $uProt = strtoupper(trim((string)($rB['usuario_proteo'] ?? '')));
                        $uNom  = strtoupper(trim((string)($rB['nombre_completo'] ?? '')));
                        if (!empty($uProt)) $medicosBloqueoHOUsernames[] = $uProt;
                        if (!empty($uNom))  $medicosBloqueOHONombres[]   = $uNom;
                    }
                }
            }
        }

        // --- A. CONSULTAR REGISTROS DE PROTEO PARA PROCEDIMIENTOS ('Enviado a Enfermeria') ---
        $paramsProteo = [$fechaDesde, $fechaHasta];
        $whereProteo  = "WHERE EXISTS (
                            SELECT TOP 1 CreationTime
                            FROM dbo.AppEventStatusesInEvents WITH (NOLOCK)
                            WHERE EventId = AE.Id 
                              AND EventStatusName = 'Enviado a Enfermeria'
                         )
                         AND DOLO.CreationTime >= ?
                         AND DOLO.CreationTime <= ?";

        if (!empty($medicoFiltro)) {
            $whereProteo .= " AND (MEDIC.UsuarioMedico = ? OR MEDIC.Usuario LIKE ?)";
            $paramsProteo[] = $medicoFiltro;
            $paramsProteo[] = '%' . $medicoFiltro . '%';
        } else {
            // Si no se especifica un médico en el filtro, restringir exclusivamente a los médicos con modalidad Bloqueos_HO
            if (!empty($medicosBloqueoHOUsernames)) {
                $phMed = implode(',', array_fill(0, count($medicosBloqueoHOUsernames), '?'));
                $whereProteo .= " AND (MEDIC.UsuarioMedico IN ($phMed)";
                foreach ($medicosBloqueoHOUsernames as $uProt) {
                    $paramsProteo[] = $uProt;
                }
                if (!empty($medicosBloqueOHONombres)) {
                    foreach ($medicosBloqueOHONombres as $uNom) {
                        $whereProteo .= " OR UPPER(MEDIC.Usuario) LIKE ?";
                        $paramsProteo[] = '%' . $uNom . '%';
                    }
                }
                $whereProteo .= ")";
            } else {
                $whereProteo .= " AND 1 = 0";
            }
        }

        if (!empty($estadoFiltro)) {
            $whereProteo .= " AND MEDDOL.EventStatusName = ?";
            $paramsProteo[] = $estadoFiltro;
        }

        $sqlProteo = "
        SELECT 
            AE.Id,
            AE.Id AS Evento_Id,
            DOC.Value AS Documento,
            DOC.Value AS Documento_Paciente,
            ENT.name AS Nombre,
            ENT.name AS Nombre_Paciente,
            DOLO.CreationTime AS Fecha_Finalizacion,
            DOLO.CreationTime AS Fecha_Creacion,
            FUEN.Value AS Fuente, 
            VIS.Value AS Ingreso,
            CUP.Value AS CUPS,
            MO.Value AS Modalidad,
            MEDDOL.EventStatusName AS Estado_Medicodol,
            MEDDOL.EventStatusName AS Estado_Actual,
            SED.Value AS Sede,
            MEDIC.Usuario,
            MEDIC.Usuario AS Medico_Usuario,
            MEDIC.UsuarioMedico,
            MEDIC.UsuarioMedico AS Usuario_Medico
        FROM dbo.AppEvents AE WITH (NOLOCK)
        LEFT JOIN dbo.AppEntities ENT WITH (NOLOCK) 
            ON AE.EntityId = ENT.Id

        -- Enviado a Enfermeria
        OUTER APPLY (
            SELECT TOP 1 CreationTime
            FROM dbo.AppEventStatusesInEvents WITH (NOLOCK)
            WHERE EventId = AE.Id 
              AND EventStatusName = 'Enviado a Enfermeria'
        ) DOLO

        OUTER APPLY (
            SELECT TOP 1 EventStatusName
            FROM dbo.AppEventStatusesInEvents WITH (NOLOCK)
            WHERE EventId = AE.Id 
              AND EventStatusName = 'Enviado a Enfermeria'
        ) MEDDOL

        OUTER APPLY (
            SELECT TOP 1 CONCAT(U.Name, ' ', U.Surname) AS Usuario, U.UserName AS UsuarioMedico
            FROM dbo.AppEventStatusesInEvents AEI /*WITH (NOLOCK)*/
            LEFT JOIN dbo.AbpUsers U ON AEI.CreatorUserId = U.Id
            WHERE AE.Id = AEI.EventId 
              AND AEI.EventStatusName = 'Enviado a Enfermeria'  
            ORDER BY AEI.CreationTime DESC
        ) MEDIC

        -- Documento
        OUTER APPLY (
            SELECT TOP 1 Value
            FROM dbo.AppEntityDetails WITH (NOLOCK)
            WHERE EntityId = AE.EntityId 
              AND Name = 'Número identificación'
        ) DOC

        ---Fuente
        OUTER APPLY (
            SELECT TOP 1 Value 
            FROM dbo.AppEventDynamicDetails WITH (NOLOCK) 
            WHERE EventId = AE.Id 
              AND [Key] = 'VisitSource' 
            ORDER BY CreationTime DESC
        ) FUEN

        -- Ingreso
        OUTER APPLY (
            SELECT TOP 1 Value 
            FROM dbo.AppEventDynamicDetails WITH (NOLOCK) 
            WHERE EventId = AE.Id 
              AND [Key] = 'VisitId'
            ORDER BY LastModificationTime DESC
        ) VIS

        -- CUPS
        OUTER APPLY (
            SELECT TOP 1 Value
            FROM dbo.AppEventDynamicDetails WITH (NOLOCK) 
            WHERE EventId = AE.Id 
              AND [Key] = 'CUPS'
            ORDER BY LastModificationTime DESC
        ) CUP

        -- Última Modalidad
        OUTER APPLY (
            SELECT TOP 1 Value 
            FROM dbo.AppEventDynamicDetails WITH (NOLOCK) 
            WHERE EventId = AE.Id 
              AND [Key] = 'App.Proteo.WL.ModalityCUPS'
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

        {$whereProteo}
        ORDER BY 
            MEDIC.Usuario, 
            DOLO.CreationTime DESC
        ";

        // Helpers para normalización y extracción estricta del CÓDIGO CUPS (antes del '-')
        $normalizarTexto = function($str) {
            if (empty($str)) return '';
            $str = (string)$str;
            $unwanted = [
                'Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C',
                'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
                'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a',
                'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i',
                'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u',
                'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y'
            ];
            return strtoupper(trim(strtr($str, $unwanted)));
        };

        $extractCupsCode = function($cupsStr) {
            if (empty($cupsStr)) return '';
            $cupsStr = trim((string)$cupsStr);
            if (strpos($cupsStr, '-') !== false) {
                $parts = explode('-', $cupsStr, 2);
                return strtoupper(trim($parts[0]));
            }
            $parts = preg_split('/\s+/', $cupsStr, 2);
            return strtoupper(trim($parts[0] ?? ''));
        };

        $extractCupsSecondaryCode = function($cupsStr) {
            if (empty($cupsStr)) return '';
            $cupsStr = trim((string)$cupsStr);
            if (strpos($cupsStr, '-') !== false) {
                $parts = explode('-', $cupsStr, 2);
                $secondPart = trim($parts[1] ?? '');
                $secondTokens = preg_split('/\s+/', $secondPart, 2);
                $sec = strtoupper(trim($secondTokens[0] ?? ''));
                $secClean = preg_replace('/[^A-Z0-9]/', '', $sec);
                // Un código CUPS secundario debe contener dígitos y tener longitud estándar (4 a 7 caracteres)
                if (preg_match('/^[A-Z]?\d{4,7}$/i', $secClean)) {
                    return $secClean;
                }
            }
            return '';
        };

        $stmtP = sqlsrv_query($connProteo, $sqlProteo, $paramsProteo);
        $proteoItems        = [];
        $pairsByFuenteProteo= [];
        $proteoKeysMap      = [];
        $proteoByFueIngMap  = [];
        $seenEventCupsMap   = []; // Deduplicación estricta por Evento + Código CUPS alfanumérico completo

        if ($stmtP !== false) {
            while ($row = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC)) {
                $fechaRaw = $row['Fecha_Finalizacion'] ?? ($row['Fecha_Creacion'] ?? null);
                $fechaStr = ($fechaRaw instanceof DateTime) ? $fechaRaw->format('Y-m-d H:i:s') : (string)$fechaRaw;
                $fuente   = trim((string)($row['Fuente'] ?? ''));
                $ingreso  = trim((string)($row['Ingreso'] ?? ''));
                $eventId  = trim((string)($row['Id'] ?? ($row['Evento_Id'] ?? '')));
                $cupsRaw  = trim((string)($row['CUPS'] ?? ''));

                // Extraer el código CUPS principal preservando letras iniciales alfanuméricas intactas
                $pCupsCode   = $extractCupsCode($cupsRaw);
                $cleanCode   = preg_replace('/[^A-Z0-9]/', '', $pCupsCode);

                if ($cleanCode === 'CPAC' || $cleanCode === 'CUMO' || $cleanCode === 'DESC') {
                    continue;
                }

                // Evitar duplicar registros para el mismo Evento cuando existen variaciones de tildes en AppEventDynamicDetails
                $dedupKey = $eventId . '_' . $cleanCode;
                if (!empty($eventId) && !empty($cleanCode) && isset($seenEventCupsMap[$dedupKey])) {
                    continue;
                }
                if (!empty($eventId) && !empty($cleanCode)) {
                    $seenEventCupsMap[$dedupKey] = true;
                }

                $item = [
                    'origen'        => 'PROTEO',
                    'id'            => $eventId,
                    'documento'     => $row['Documento'] ?? ($row['Documento_Paciente'] ?? ''),
                    'nombre'        => $row['Nombre'] ?? ($row['Nombre_Paciente'] ?? ''),
                    'fecha'         => $fechaStr,
                    'fuente'        => $fuente,
                    'ingreso'       => $ingreso,
                    'cups'          => $cupsRaw,
                    'modalidad'     => $row['Modalidad'] ?? '',
                    'estado_actual' => $row['Estado_Medicodol'] ?? ($row['Estado_Actual'] ?? ''),
                    'sede'          => $row['Sede'] ?? '',
                    'usuario'       => trim((string)($row['Usuario'] ?? ($row['Medico_Usuario'] ?? ''))),
                    'usuario_medico'=> trim((string)($row['UsuarioMedico'] ?? ($row['Usuario_Medico'] ?? ''))),
                    'servinte'      => null,
                    'cruce'         => 'SOLO_PROTEO',
                    'discrepancia'  => ''
                ];

                $proteoItems[] = $item;

                if (!empty($fuente) && !empty($ingreso)) {
                    $fueUpper = strtoupper($fuente);
                    $ingClean = ltrim($ingreso, '0');
                    if ($ingClean === '') $ingClean = '0';

                    $kExact = $fueUpper . '_' . $ingreso;
                    $kClean = $fueUpper . '_' . $ingClean;

                    $proteoKeysMap[$kExact] = true;
                    $pairsByFuenteProteo[$fueUpper][] = $ingreso;

                    if (!isset($proteoByFueIngMap[$kExact])) {
                        $proteoByFueIngMap[$kExact] = [];
                    }
                    $proteoByFueIngMap[$kExact][] = $item;

                    if ($kClean !== $kExact) {
                        if (!isset($proteoByFueIngMap[$kClean])) {
                            $proteoByFueIngMap[$kClean] = [];
                        }
                        $proteoByFueIngMap[$kClean][] = $item;
                    }
                }
            }
        }

        // --- B. CONSULTAR REGISTROS DE SERVINTE POR FUENTE E INGRESO (LLAVES DE PROTEO) ---
        $servinteItemsList = [];
        $servinteByFueIng   = [];

        if (!empty($pairsByFuenteProteo)) {
            foreach ($pairsByFuenteProteo as $fue => $ingArr) {
                $uniqueIngresos = array_unique(array_filter($ingArr));
                if (!empty($uniqueIngresos)) {
                    $allIngVariations = [];
                    foreach ($uniqueIngresos as $ing) {
                        $raw   = trim((string)$ing);
                        $clean = ltrim($raw, '0');
                        if ($clean === '') $clean = '0';

                        $baseArr = [$raw, $clean, '0' . $clean, '00' . $clean, '000' . $clean];
                        foreach ($baseArr as $v) {
                            $allIngVariations[] = $v;
                            $allIngVariations[] = str_pad($v, 6);
                            $allIngVariations[] = str_pad($v, 8);
                            $allIngVariations[] = str_pad($v, 10);
                            $allIngVariations[] = str_pad($v, 12);
                            $allIngVariations[] = str_pad($v, 15);
                        }
                    }
                    $allIngVariations = array_unique(array_filter($allIngVariations));

                    $chunks  = array_chunk($allIngVariations, 500);
                    $fueRaw  = trim((string)$fue);
                    $fuePad  = str_pad($fueRaw, 6);
                    $fuePad8 = str_pad($fueRaw, 8);

                    foreach ($chunks as $chunk) {
                        $inClause = "'" . implode("','", array_map('addslashes', $chunk)) . "'";
                        $sqlServinte = "
                        SELECT 
                            NVL(cia.cianom, 'Sin Sede') AS SEDE,
                            mov.movfue AS FUENTE,
                            mov.movdoc AS INGRESO,
                            mov.movtip AS TIPO_PACIENTE,
                            TO_CHAR(mov.movfec, 'YYYY-MM-DD HH24:MI:SS') AS FECHA,
                            TRIM(pac.pacnom || ' ' || pac.pacap1 || ' ' || pac.pacap2) AS PACIENTE,
                            pac.pactid AS TIPO_DOC,
                            pac.pacide AS IDENTIFICACION,
                            mov.movres AS ENTIDAD,
                            det.cardetcon AS CONCEPTO,
                            det.cardetcod AS CODIGO_EXAMEN,
                            NVL(exa.exanom, det.cardetcod) AS EXAMEN,
                            det.cardetcan AS CANTIDAD,
                            det.cardettot AS TOTAL
                        FROM aymov mov 
                          INNER JOIN aycardet det ON mov.movfue = det.cardetfue AND mov.movdoc = det.cardetdoc 
                          LEFT JOIN inexa exa ON exa.exacod = det.cardetcod 
                          LEFT JOIN sicia cia ON cia.ciacod = mov.movead 
                          LEFT JOIN abpac pac ON pac.pachis = mov.movhis
                        WHERE mov.movfue IN (:fuente, :fuentePad, :fuentePad8)
                          AND mov.movdoc IN ({$inClause})
                          AND (det.cardetcon IS NULL OR UPPER(TRIM(det.cardetcon)) NOT IN ('CPAC', 'CUMO', 'DESC'))
                        ";

                        $stS = oci_parse($connServinte, $sqlServinte);
                        if ($stS) {
                            oci_bind_by_name($stS, ":fuente", $fueRaw);
                            oci_bind_by_name($stS, ":fuentePad", $fuePad);
                            oci_bind_by_name($stS, ":fuentePad8", $fuePad8);
                            if (@oci_execute($stS)) {
                                while ($r = oci_fetch_array($stS, OCI_ASSOC + OCI_RETURN_NULLS)) {
                                    $conRaw = strtoupper(trim((string)($r['CONCEPTO'] ?? '')));
                                    if ($conRaw === 'CPAC' || $conRaw === 'CUMO' || $conRaw === 'DESC') {
                                        continue;
                                    }

                                    $fueStr    = strtoupper(trim($r['FUENTE'] ?? ''));
                                    $ingStr    = trim($r['INGRESO'] ?? '');
                                    $ingClean  = ltrim($ingStr, '0');
                                    if ($ingClean === '') $ingClean = '0';

                                    $codExa     = strtoupper(trim($r['CODIGO_EXAMEN'] ?? ''));
                                    $codClean   = preg_replace('/[^A-Z0-9]/', '', $codExa);
                                    $nomExa     = strtoupper(trim($r['EXAMEN'] ?? ''));
                                    $nomTokens  = preg_split('/\s+/', $nomExa, 2);
                                    $firstToken = preg_replace('/[^A-Z0-9]/', '', $nomTokens[0] ?? '');
                                    $exaSecCode = preg_match('/^[A-Z]?\d{4,7}$/i', $firstToken) ? $firstToken : '';

                                    $rawCant = (float)($r['CANTIDAD'] ?? 0);
                                    $rawTot  = (float)($r['TOTAL'] ?? 0);
                                    $anomaliaCant = false;

                                    // Corrección automática de error de digitación en Servinte:
                                    // El facturador en Servinte digitó por error el código CUPS en el campo de cantidad (ej. cardetcan = 881401)
                                    $codNum = preg_replace('/[^0-9]/', '', $codExa);
                                    if ($rawCant > 1) {
                                        if ((!empty($codNum) && strval((int)$rawCant) === $codNum) || ($rawCant > 20 && preg_match('/^\d{5,7}$/', strval((int)$rawCant)))) {
                                            $unitVal = ($rawCant > 0 && $rawTot > 0) ? ($rawTot / $rawCant) : $rawTot;
                                            $rawCant = 1;
                                            $rawTot  = $unitVal;
                                            $anomaliaCant = true;
                                        }
                                    }

                                    $sItemData = [
                                        'sede'          => $r['SEDE'] ?? '',
                                        'fuente'        => $fueStr,
                                        'ingreso'       => $ingStr,
                                        'tipo_paciente' => trim($r['TIPO_PACIENTE'] ?? ''),
                                        'fecha'         => $r['FECHA'] ?? '',
                                        'paciente'      => trim($r['PACIENTE'] ?? ''),
                                        'tipo_doc'      => $r['TIPO_DOC'] ?? '',
                                        'identificacion'=> $r['IDENTIFICACION'] ?? '',
                                        'entidad'       => $r['ENTIDAD'] ?? '',
                                        'concepto'      => $r['CONCEPTO'] ?? '',
                                        'codigo_examen' => $codExa,
                                        'cod_clean'     => $codClean,
                                        'examen_sec'    => $exaSecCode,
                                        'examen'        => $r['EXAMEN'] ?? '',
                                        'cantidad'      => $rawCant,
                                        'total'         => $rawTot,
                                        'anomalia_cant' => $anomaliaCant,
                                        'matched'       => false
                                    ];

                                    $idx = count($servinteItemsList);
                                    $servinteItemsList[$idx] = $sItemData;

                                    $keysToRegister = array_unique([
                                        $fueStr . '_' . $ingStr,
                                        $fueStr . '_' . $ingClean
                                    ]);

                                    foreach ($keysToRegister as $kFueIng) {
                                        if (!isset($servinteByFueIng[$kFueIng])) {
                                            $servinteByFueIng[$kFueIng] = [];
                                        }
                                        $servinteByFueIng[$kFueIng][] = $idx;
                                    }
                                }
                            }
                            oci_free_statement($stS);
                        }
                    }
                }
            }
        }

        // --- C.1 CARGAR MAPA DE TARIFARIO LIHO PARA CÁLCULO DE VALOR A PAGAR ---
        $tarifarioMap = [];
        $tarifarioEspecialMap = [];
        $tarifarioBloqueosMap = [];
        $medicosEspecialesMap = [];
        $medicosDeglucionesMap = [];
        $medicosParafiscalesMap = [];
        $medicosAfcMap = [];
        $medicosIbcMap = [];
        $medicosPensionadosMap = [];
        $medicosArlMap = [];
        $porcentajesPagoMap = [
            'TARIFAS_ESPECIALES' => 30.0,
            'DEGLUCIONES'        => 45.0
        ];
        $modalidadesConfigMap = [];
        $parafiscalesConfigMap = [
            'AFC'     => 40.0,
            'IBC'     => 40.0,
            'SALUD'   => 12.5,
            'PENSION' => 16.0,
            'ARL'     => 2.4360
        ];

        $connLIHO = obtenerConexionLIHO();
        if ($connLIHO !== false) {
            // 1. Porcentajes y Modalidades de Pago desde el Maestro (por Perfil de Entidad)
            $entActivaSesion = $_SESSION['entidad_liquidacion_id'] ?? 'PROPIO';
            $sqlPctBase = "SELECT tipo, porcentaje, ISNULL(tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(valor_fijo, 0) AS valor_fijo FROM dbo.maestro_porcentajes_pago WHERE ISNULL(estado, 1) = 1 AND (entidad_id IS NULL OR entidad_id = 0)";
            $stmtPctBase = sqlsrv_query($connLIHO, $sqlPctBase);
            if ($stmtPctBase !== false) {
                while ($rP = sqlsrv_fetch_array($stmtPctBase, SQLSRV_FETCH_ASSOC)) {
                    $tP = strtoupper(trim((string)($rP['tipo'] ?? '')));
                    if (!empty($tP)) {
                        $porcentajesPagoMap[$tP] = (float)($rP['porcentaje'] ?? 0);
                        $modalidadesConfigMap[$tP] = [
                            'porcentaje'   => (float)($rP['porcentaje'] ?? 0),
                            'tipo_calculo' => strtoupper(trim((string)($rP['tipo_calculo'] ?? 'PORCENTAJE'))),
                            'valor_fijo'   => (float)($rP['valor_fijo'] ?? 0)
                        ];
                    }
                }
            }

            if (!empty($entActivaSesion) && $entActivaSesion !== 'PROPIO') {
                $sqlPctEnt = "SELECT tipo, porcentaje, ISNULL(tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(valor_fijo, 0) AS valor_fijo FROM dbo.maestro_porcentajes_pago WHERE ISNULL(estado, 1) = 1 AND entidad_id = ?";
                $stmtPctEnt = sqlsrv_query($connLIHO, $sqlPctEnt, [(int)$entActivaSesion]);
                if ($stmtPctEnt !== false) {
                    while ($rP = sqlsrv_fetch_array($stmtPctEnt, SQLSRV_FETCH_ASSOC)) {
                        $tP = strtoupper(trim((string)($rP['tipo'] ?? '')));
                        if (!empty($tP)) {
                            $porcentajesPagoMap[$tP] = (float)($rP['porcentaje'] ?? 0);
                            $modalidadesConfigMap[$tP] = [
                                'porcentaje'   => (float)($rP['porcentaje'] ?? 0),
                                'tipo_calculo' => strtoupper(trim((string)($rP['tipo_calculo'] ?? 'PORCENTAJE'))),
                                'valor_fijo'   => (float)($rP['valor_fijo'] ?? 0)
                            ];
                        }
                    }
                }
            }

            // 1.1 Maestro de Parafiscales
            $sqlPara = "SELECT codigo, porcentaje FROM dbo.maestro_parafiscales WHERE ISNULL(estado, 1) = 1";
            $stmtPara = sqlsrv_query($connLIHO, $sqlPara);
            if ($stmtPara !== false) {
                while ($rPara = sqlsrv_fetch_array($stmtPara, SQLSRV_FETCH_ASSOC)) {
                    $cPara = strtoupper(trim((string)($rPara['codigo'] ?? '')));
                    if (!empty($cPara)) {
                        $parafiscalesConfigMap[$cPara] = (float)($rPara['porcentaje'] ?? 0);
                    }
                }
            }

            // 2. Tarifario General Estándar con Concepto de Facturación y Tipos (Prioriza entidad activa si existe, con fallback a Hernán Ocazionez)
            $cupsConceptoMap       = [];
            $cupsServicioMap       = [];
            $cupsCuentaContableMap = [];
            $cupsNombreCuentaMap   = [];
            $tacContrastadasMap    = [];
            $tacSimpleMap          = [];
            // Cargar base de Hernán Ocazionez (entidad_id IS NULL OR 0)
            $sqlT = "SELECT codigo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, ISNULL(columna1, valor_und) AS valor_und, ISNULL(concepto, '') AS concepto, ISNULL(servicio, '') AS servicio, ISNULL(tipo, '') AS tipo, ISNULL(cuenta_contable, '') AS cuenta_contable, ISNULL(nombre_cuenta, '') AS nombre_cuenta, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo 
                     FROM dbo.tarifario 
                     WHERE ISNULL(estado, 1) = 1 AND (entidad_id IS NULL OR entidad_id = 0)";
            $stmtT = sqlsrv_query($connLIHO, $sqlT);
            if ($stmtT !== false) {
                while ($rT = sqlsrv_fetch_array($stmtT, SQLSRV_FETCH_ASSOC)) {
                    $codRaw       = strtoupper(trim((string)($rT['codigo'] ?? '')));
                    $tipoPac      = strtoupper(trim((string)($rT['tipo_paciente'] ?? 'E')));
                    if ($tipoPac !== 'P') $tipoPac = 'E';
                    $valUnd       = (float)($rT['valor_und'] ?? 0);
                    $conVal       = strtoupper(trim((string)($rT['concepto'] ?? '')));
                    $serVal       = strtoupper(trim((string)($rT['servicio'] ?? '')));
                    $tipoVal      = strtoupper(trim((string)($rT['tipo'] ?? '')));
                    $cuentaVal    = strtoupper(trim((string)($rT['cuenta_contable'] ?? '')));
                    $nomCuentaVal = strtoupper(trim((string)($rT['nombre_cuenta'] ?? '')));
                    $pagarCant    = (int)($rT['pagar_por_cantidad'] ?? 1);
                    $baseCalc     = strtoupper(trim((string)($rT['base_calculo'] ?? 'VALOR_LIQUIDACION')));
                    if (!empty($codRaw)) {
                        $cleanCod = preg_replace('/[^A-Z0-9]/', '', $codRaw);
                        $keyWithTipo = $cleanCod . '_' . $tipoPac;
                        $tarifarioMap[$keyWithTipo] = [
                            'valor_und'          => $valUnd,
                            'pagar_por_cantidad' => $pagarCant,
                            'base_calculo'       => $baseCalc
                        ];
                        if (!empty($conVal)) {
                            $cupsConceptoMap[$cleanCod] = $conVal;
                        } elseif (!empty($serVal)) {
                            $cupsConceptoMap[$cleanCod] = $serVal;
                        }

                        $servicioFinal = !empty($serVal) ? $serVal : (!empty($tipoVal) ? $tipoVal : '');
                        if (empty($servicioFinal) && !empty($conVal)) {
                            if ($conVal === 'RXES') $servicioFinal = 'RX ESPECIALES';
                            elseif ($conVal === 'RXSI') $servicioFinal = 'RX SIMPLE';
                            elseif ($conVal === 'ECOG') $servicioFinal = 'ECOGRAFÍAS';
                            elseif ($conVal === 'TOHO') $servicioFinal = 'TOMOGRAFÍAS';
                            elseif ($conVal === 'MAMO') $servicioFinal = 'MAMOGRAFÍAS';
                            elseif ($conVal === 'DOPP') $servicioFinal = 'DOPPLER';
                            elseif ($conVal === 'BIOP') $servicioFinal = 'BIOPSIAS';
                        }
                        if (!empty($servicioFinal)) {
                            $cupsServicioMap[$cleanCod] = $servicioFinal;
                        }
                        if (!empty($cuentaVal)) {
                            $cupsCuentaContableMap[$cleanCod] = $cuentaVal;
                        }
                        if (!empty($nomCuentaVal)) {
                            $cupsNombreCuentaMap[$cleanCod] = $nomCuentaVal;
                        }

                        if ($tipoVal === 'TAC CONTRASTADA' || strpos($tipoVal, 'CONTRAST') !== false) {
                            $tacContrastadasMap[$cleanCod] = true;
                        } elseif ($tipoVal === 'TAC SIMPLE') {
                            $tacSimpleMap[$cleanCod] = true;
                        }
                    }
                }
            }

            // Si la entidad activa es externa (ej. IMADINSA SAS), sobreescribir/agregar con su tarifario específico
            $entidadActivaId = $entActivaSesion ?? 'PROPIO';
            if (!empty($entidadActivaId) && $entidadActivaId !== 'PROPIO' && (int)$entidadActivaId > 0) {
                $sqlTEnt = "SELECT codigo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, ISNULL(columna1, valor_und) AS valor_und, ISNULL(concepto, '') AS concepto, ISNULL(servicio, '') AS servicio, ISNULL(tipo, '') AS tipo, ISNULL(cuenta_contable, '') AS cuenta_contable, ISNULL(nombre_cuenta, '') AS nombre_cuenta, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo 
                            FROM dbo.tarifario 
                            WHERE ISNULL(estado, 1) = 1 AND entidad_id = ?";
                $stmtTEnt = sqlsrv_query($connLIHO, $sqlTEnt, [(int)$entidadActivaId]);
                if ($stmtTEnt !== false) {
                    while ($rT = sqlsrv_fetch_array($stmtTEnt, SQLSRV_FETCH_ASSOC)) {
                        $codRaw       = strtoupper(trim((string)($rT['codigo'] ?? '')));
                        $tipoPac      = strtoupper(trim((string)($rT['tipo_paciente'] ?? 'E')));
                        if ($tipoPac !== 'P') $tipoPac = 'E';
                        $valUnd       = (float)($rT['valor_und'] ?? 0);
                        $conVal       = strtoupper(trim((string)($rT['concepto'] ?? '')));
                        $serVal       = strtoupper(trim((string)($rT['servicio'] ?? '')));
                        $tipoVal      = strtoupper(trim((string)($rT['tipo'] ?? '')));
                        $cuentaVal    = strtoupper(trim((string)($rT['cuenta_contable'] ?? '')));
                        $nomCuentaVal = strtoupper(trim((string)($rT['nombre_cuenta'] ?? '')));
                        $pagarCant    = (int)($rT['pagar_por_cantidad'] ?? 1);
                        $baseCalc     = strtoupper(trim((string)($rT['base_calculo'] ?? 'VALOR_LIQUIDACION')));
                        if (!empty($codRaw)) {
                            $cleanCod = preg_replace('/[^A-Z0-9]/', '', $codRaw);
                            $keyWithTipo = $cleanCod . '_' . $tipoPac;
                            $tarifarioMap[$keyWithTipo] = [
                                'valor_und'          => $valUnd,
                                'pagar_por_cantidad' => $pagarCant,
                                'base_calculo'       => $baseCalc
                            ];
                            if (!empty($conVal)) {
                                $cupsConceptoMap[$cleanCod] = $conVal;
                            } elseif (!empty($serVal)) {
                                $cupsConceptoMap[$cleanCod] = $serVal;
                            }

                            $servicioFinal = !empty($serVal) ? $serVal : (!empty($tipoVal) ? $tipoVal : '');
                            if (empty($servicioFinal) && !empty($conVal)) {
                                if ($conVal === 'RXES') $servicioFinal = 'RX ESPECIALES';
                                elseif ($conVal === 'RXSI') $servicioFinal = 'RX SIMPLE';
                                elseif ($conVal === 'ECOG') $servicioFinal = 'ECOGRAFÍAS';
                                elseif ($conVal === 'TOHO') $servicioFinal = 'TOMOGRAFÍAS';
                                elseif ($conVal === 'MAMO') $servicioFinal = 'MAMOGRAFÍAS';
                                elseif ($conVal === 'DOPP') $servicioFinal = 'DOPPLER';
                                elseif ($conVal === 'BIOP') $servicioFinal = 'BIOPSIAS';
                            }
                            if (!empty($servicioFinal)) {
                                $cupsServicioMap[$cleanCod] = $servicioFinal;
                            }
                            if (!empty($cuentaVal)) {
                                $cupsCuentaContableMap[$cleanCod] = $cuentaVal;
                            }
                            if (!empty($nomCuentaVal)) {
                                $cupsNombreCuentaMap[$cleanCod] = $nomCuentaVal;
                            }

                            if ($tipoVal === 'TAC CONTRASTADA' || strpos($tipoVal, 'CONTRAST') !== false) {
                                $tacContrastadasMap[$cleanCod] = true;
                            } elseif ($tipoVal === 'TAC SIMPLE') {
                                $tacSimpleMap[$cleanCod] = true;
                            }
                        }
                    }
                }
            }

            // 3. Maestro de Tarifas Especiales / Degluciones
            $sqlTE = "SELECT codigo, tipo, estudio, tipo_pago,
                             ISNULL(es_tarifa_especial, 0) AS es_tarifa_especial,
                             ISNULL(es_deglucion, 0) AS es_deglucion
                      FROM dbo.tarifario_especial 
                      WHERE ISNULL(estado, 1) = 1";
            $stmtTE = sqlsrv_query($connLIHO, $sqlTE);
            if ($stmtTE !== false) {
                while ($rTE = sqlsrv_fetch_array($stmtTE, SQLSRV_FETCH_ASSOC)) {
                    $cRaw    = strtoupper(trim((string)($rTE['codigo'] ?? '')));
                    $cleanTE = preg_replace('/[^A-Z0-9]/', '', $cRaw);
                    $cfg = [
                        'es_tarifa_especial' => (int)($rTE['es_tarifa_especial'] ?? 0),
                        'es_deglucion'       => (int)($rTE['es_deglucion'] ?? 0),
                        'tipo_pago'          => strtoupper(trim((string)($rTE['tipo_pago'] ?? '')))
                    ];
                    if (!empty($cleanTE)) {
                        $tarifarioEspecialMap[$cleanTE] = $cfg;
                    }
                }
            }

            // 3.1 Maestro de Tarifario Bloqueos HO
            $sqlTB = "SELECT codigo, estudio, especialidad, valor_base, valor_cant_2, valor_cant_3, porcentaje_base, porcentaje_cant_2, porcentaje_cant_3, tipo_calculo, observaciones 
                      FROM dbo.tarifario_bloqueos 
                      WHERE ISNULL(estado, 1) = 1";
            $stmtTB = sqlsrv_query($connLIHO, $sqlTB);
            if ($stmtTB !== false) {
                while ($rTB = sqlsrv_fetch_array($stmtTB, SQLSRV_FETCH_ASSOC)) {
                    $cRawTB  = strtoupper(trim((string)($rTB['codigo'] ?? '')));
                    $cleanTB = preg_replace('/[^A-Z0-9]/', '', $cRawTB);
                    if (!empty($cleanTB)) {
                        $tarifarioBloqueosMap[$cleanTB] = [
                            'codigo'            => $cRawTB,
                            'estudio'           => $rTB['estudio'] ?? '',
                            'valor_base'        => (float)($rTB['valor_base'] ?? 0),
                            'valor_cant_2'      => (float)($rTB['valor_cant_2'] ?? 0),
                            'valor_cant_3'      => (float)($rTB['valor_cant_3'] ?? 0),
                            'porcentaje_base'   => (float)($rTB['porcentaje_base'] ?? 40.0),
                            'porcentaje_cant_2' => (float)($rTB['porcentaje_cant_2'] ?? 70.0),
                            'porcentaje_cant_3' => (float)($rTB['porcentaje_cant_3'] ?? 60.0),
                            'observaciones'     => $rTB['observaciones'] ?? ''
                        ];
                    }
                }
            }

            // 4. Médicos con Modalidades y Parafiscales Activos
            $medicosModalidadesMap = [];
            $medicosEntidadMap     = [];

            // Identificar IDs correspondientes a IMADINSA SAS en maestro_entidades
            $imadinsaEntidadIds = [1 => true];
            $sqlIm = "SELECT id FROM dbo.maestro_entidades WHERE UPPER(nombre) LIKE '%IMADINSA%'";
            $stmtIm = sqlsrv_query($connLIHO, $sqlIm);
            if ($stmtIm !== false) {
                while ($rIm = sqlsrv_fetch_array($stmtIm, SQLSRV_FETCH_ASSOC)) {
                    $imadinsaEntidadIds[(int)$rIm['id']] = true;
                }
            }

            $sqlMedEsp = "SELECT m.usuario_proteo, m.cedula, u.nombre_completo,
                                 ISNULL(m.entidad_id, ISNULL(u.entidad_id, 0)) AS medico_entidad_id,
                                 ISNULL(m.tarifas_especiales, ISNULL(u.tarifas_especiales, 0)) AS tarifas_especiales,
                                 ISNULL(m.degluciones, ISNULL(u.degluciones, 0)) AS degluciones,
                                 ISNULL(m.parafiscales, ISNULL(u.parafiscales, 0)) AS parafiscales,
                                 ISNULL(m.afc, ISNULL(u.afc, ISNULL(m.ibc, ISNULL(u.ibc, 0)))) AS afc,
                                 ISNULL(m.ibc, ISNULL(u.ibc, ISNULL(m.afc, ISNULL(u.afc, 0)))) AS ibc,
                                 ISNULL(m.pensionado, ISNULL(u.pensionado, 0)) AS pensionado,
                                 ISNULL(m.arl, ISNULL(u.arl, 0)) AS arl,
                                 ISNULL(m.modalidades_adicionales, ISNULL(u.modalidades_adicionales, '')) AS modalidades_adicionales
                          FROM dbo.medicos m 
                          LEFT JOIN dbo.usuarios u ON m.usuario_id = u.id";
            $stmtMedEsp = sqlsrv_query($connLIHO, $sqlMedEsp);
            if ($stmtMedEsp !== false) {
                while ($rME = sqlsrv_fetch_array($stmtMedEsp, SQLSRV_FETCH_ASSOC)) {
                    $pUser = strtoupper(trim((string)($rME['usuario_proteo'] ?? '')));
                    $ced   = trim((string)($rME['cedula'] ?? ''));
                    $nom   = strtoupper(trim((string)($rME['nombre_completo'] ?? '')));
                    
                    $isEsp  = ((int)($rME['tarifas_especiales'] ?? 0) === 1);
                    $isDeg  = ((int)($rME['degluciones'] ?? 0) === 1);
                    $isPara = ((int)($rME['parafiscales'] ?? 0) === 1);
                    $isAfc  = ((int)($rME['afc'] ?? 0) === 1 || (int)($rME['ibc'] ?? 0) === 1);
            $isIbc  = $isAfc;
                    $isPen  = ((int)($rME['pensionado'] ?? 0) === 1);
                    $isArl  = ((int)($rME['arl'] ?? 0) === 1);

                    $docModsStr = trim((string)($rME['modalidades_adicionales'] ?? ''));
                    $docMods = !empty($docModsStr) ? array_filter(array_map('trim', explode(',', $docModsStr))) : [];
                    if ($isEsp && !in_array('TARIFAS_ESPECIALES', $docMods)) $docMods[] = 'TARIFAS_ESPECIALES';
                    if ($isDeg && !in_array('DEGLUCIONES', $docMods)) $docMods[] = 'DEGLUCIONES';

                    foreach ($docMods as $mCode) {
                        $mCode = strtoupper(trim($mCode));
                        if (!empty($pUser)) $medicosModalidadesMap[$pUser][$mCode] = true;
                        if (!empty($ced))   $medicosModalidadesMap[$ced][$mCode] = true;
                        if (!empty($nom))   $medicosModalidadesMap[$nom][$mCode] = true;

                        // Normalización bidireccional de modalidad BLOQUEOS_HO / BLOQUEO_HO
                        if ($mCode === 'BLOQUEOS_HO' || $mCode === 'BLOQUEO_HO') {
                            foreach (['BLOQUEO_HO', 'BLOQUEOS_HO'] as $bCode) {
                                if (!empty($pUser)) $medicosModalidadesMap[$pUser][$bCode] = true;
                                if (!empty($ced))   $medicosModalidadesMap[$ced][$bCode] = true;
                                if (!empty($nom))   $medicosModalidadesMap[$nom][$bCode] = true;
                            }
                        }
                    }

                    if ($isEsp || in_array('TARIFAS_ESPECIALES', $docMods)) {
                        if (!empty($pUser)) $medicosEspecialesMap[$pUser] = true;
                        if (!empty($ced))   $medicosEspecialesMap[$ced] = true;
                        if (!empty($nom))   $medicosEspecialesMap[$nom] = true;
                    }
                    if ($isDeg || in_array('DEGLUCIONES', $docMods)) {
                        if (!empty($pUser)) $medicosDeglucionesMap[$pUser] = true;
                        if (!empty($ced))   $medicosDeglucionesMap[$ced] = true;
                        if (!empty($nom))   $medicosDeglucionesMap[$nom] = true;
                    }
                    if ($isPara) {
                        if (!empty($pUser)) $medicosParafiscalesMap[$pUser] = true;
                        if (!empty($ced))   $medicosParafiscalesMap[$ced] = true;
                        if (!empty($nom))   $medicosParafiscalesMap[$nom] = true;
                    }
                    if ($isAfc || $isIbc) {
                        if (!empty($pUser)) { $medicosAfcMap[$pUser] = true; $medicosIbcMap[$pUser] = true; }
                        if (!empty($ced))   { $medicosAfcMap[$ced] = true; $medicosIbcMap[$ced] = true; }
                        if (!empty($nom))   { $medicosAfcMap[$nom] = true; $medicosIbcMap[$nom] = true; }
                    }
                    if ($isPen) {
                        if (!empty($pUser)) $medicosPensionadosMap[$pUser] = true;
                        if (!empty($ced))   $medicosPensionadosMap[$ced] = true;
                        if (!empty($nom))   $medicosPensionadosMap[$nom] = true;
                    }
                    if ($isArl) {
                        if (!empty($pUser)) $medicosArlMap[$pUser] = true;
                        if (!empty($ced))   $medicosArlMap[$ced] = true;
                        if (!empty($nom))   $medicosArlMap[$nom] = true;
                    }

                    $mEntId = !empty($rME['medico_entidad_id']) ? (int)$rME['medico_entidad_id'] : 0;
                    if (!empty($pUser)) $medicosEntidadMap[$pUser] = $mEntId;
                    if (!empty($ced))   $medicosEntidadMap[$ced]   = $mEntId;
                    if (!empty($nom))   $medicosEntidadMap[$nom]   = $mEntId;
                    $cleanCed = preg_replace('/[^0-9]/', '', $ced ?: $pUser);
                    if (!empty($cleanCed)) $medicosEntidadMap[$cleanCed] = $mEntId;
                }
            }
        }

        $obtenerTarifa = function($cupsStr, $tipoPac = 'E', $codigoServinte = '', $medicoProteo = '', $medicoNom = '', $valorExamenUnitario = 0.0) use (&$tarifarioMap, &$tarifarioEspecialMap, &$medicosEspecialesMap, &$medicosDeglucionesMap, &$medicosModalidadesMap, &$medicosEntidadMap, &$porcentajesPagoMap, &$modalidadesConfigMap, &$entActivaSesion, $extractCupsCode, $extractCupsSecondaryCode) {
            $tipoPac = strtoupper(trim((string)$tipoPac));
            if ($tipoPac !== 'P') $tipoPac = 'E';

            $codeProteo    = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($cupsStr));
            $secCodeProteo = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($cupsStr));
            $sCod          = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$codigoServinte)));

            $pUserKey = strtoupper(trim((string)$medicoProteo));
            $pNomKey  = strtoupper(trim((string)$medicoNom));

            // 0. VERIFICAR MODALIDAD "PAGO_DINAMICO"
            // En las liquidaciones siempre se les pagará el valor establecido ahí y será el valor por cada examen
            // cuando el paciente/liquidación pertenece a esta IPS y el médico también es de esa IPS/entidad o tiene asignada la modalidad.
            $docTienePagoDinamico = (!empty($pUserKey) && isset($medicosModalidadesMap[$pUserKey]['PAGO_DINAMICO'])) || 
                                   (!empty($pNomKey) && isset($medicosModalidadesMap[$pNomKey]['PAGO_DINAMICO']));

            $docEntId = $medicosEntidadMap[$pUserKey] ?? ($medicosEntidadMap[$pNomKey] ?? null);
            $entidadActivaKey = $entActivaSesion ?? '';
            $esMismaEntidad = (!empty($entidadActivaKey) && $entidadActivaKey !== 'PROPIO' && (int)$docEntId === (int)$entidadActivaKey);

            if (($docTienePagoDinamico || $esMismaEntidad) && isset($modalidadesConfigMap['PAGO_DINAMICO'])) {
                $cfgPD = $modalidadesConfigMap['PAGO_DINAMICO'];
                if (($cfgPD['tipo_calculo'] ?? '') === 'VALOR_FIJO') {
                    return [
                        'valor_und'          => (float)$cfgPD['valor_fijo'],
                        'pagar_por_cantidad' => 1,
                        'base_calculo'       => 'VALOR_LIQUIDACION'
                    ];
                } else {
                    $pct = (float)($cfgPD['porcentaje'] ?? 0);
                    if ($pct > 0 && $valorExamenUnitario > 0) {
                        return [
                            'valor_und'          => round($valorExamenUnitario * ($pct / 100.0), 2),
                            'pagar_por_cantidad' => 1,
                            'base_calculo'       => 'VALOR_LIQUIDACION'
                        ];
                    }
                }
            }

            $isDoctorDeglucion = (!empty($pUserKey) && isset($medicosDeglucionesMap[$pUserKey])) || 
                                 (!empty($pNomKey) && isset($medicosDeglucionesMap[$pNomKey]));

            $isDoctorEspecial  = (!empty($pUserKey) && isset($medicosEspecialesMap[$pUserKey])) || 
                                 (!empty($pNomKey) && isset($medicosEspecialesMap[$pNomKey]));

            // Buscar configuración especial del examen (Prioridad: Código Servinte -> Código Proteo -> Código Secundario Proteo)
            $confEsp = null;
            if (!empty($sCod) && isset($tarifarioEspecialMap[$sCod])) {
                $confEsp = $tarifarioEspecialMap[$sCod];
            } elseif (!empty($codeProteo) && isset($tarifarioEspecialMap[$codeProteo])) {
                $confEsp = $tarifarioEspecialMap[$codeProteo];
            } elseif (!empty($secCodeProteo) && isset($tarifarioEspecialMap[$secCodeProteo])) {
                $confEsp = $tarifarioEspecialMap[$secCodeProteo];
            }

            // Función auxiliar para buscar tarifa en el tarifario general estándar (Prioridad Servinte -> Proteo -> Secundario Proteo)
            $obtenerTarifaGeneral = function() use ($codeProteo, $secCodeProteo, $sCod, $tipoPac, &$tarifarioMap) {
                $candidates = [];
                if (!empty($sCod)) $candidates[] = $sCod . '_' . $tipoPac;
                if (!empty($codeProteo)) $candidates[] = $codeProteo . '_' . $tipoPac;
                if (!empty($secCodeProteo)) $candidates[] = $secCodeProteo . '_' . $tipoPac;

                $altTipo = ($tipoPac === 'P') ? 'E' : 'P';
                if (!empty($sCod)) $candidates[] = $sCod . '_' . $altTipo;
                if (!empty($codeProteo)) $candidates[] = $codeProteo . '_' . $altTipo;
                if (!empty($secCodeProteo)) $candidates[] = $secCodeProteo . '_' . $altTipo;

                foreach ($candidates as $candKey) {
                    if (isset($tarifarioMap[$candKey])) {
                        $tarifaFound = $tarifarioMap[$candKey];
                        if (is_array($tarifaFound)) {
                            return $tarifaFound;
                        }
                        return [
                            'valor_und'          => (float)$tarifaFound,
                            'pagar_por_cantidad' => 1,
                            'base_calculo'       => 'VALOR_LIQUIDACION'
                        ];
                    }
                }

                return [
                    'valor_und'          => 0.0,
                    'pagar_por_cantidad' => 1,
                    'base_calculo'       => 'VALOR_LIQUIDACION'
                ];
            };

            // Identificar modalidad configurada para este examen
            $modExamen = '';
            if ($confEsp) {
                $modExamen = strtoupper(trim((string)($confEsp['tipo_pago'] ?? '')));
                if (empty($modExamen) || $modExamen === 'TARIFA_ESTANDAR') {
                    if (intval($confEsp['es_tarifa_especial'] ?? 0) === 1) $modExamen = 'TARIFAS_ESPECIALES';
                    elseif (intval($confEsp['es_deglucion'] ?? 0) === 1) $modExamen = 'DEGLUCIONES';
                }
            }
            if (empty($modExamen)) {
                if ($codeProteo === '874910' || $secCodeProteo === '874910' || $sCod === '874910') {
                    $modExamen = 'DEGLUCIONES';
                }
            }

            // Verificar si el médico tiene habilitada esta modalidad
            $doctorTieneModalidad = false;
            if (!empty($modExamen)) {
                if (!empty($pUserKey) && isset($medicosModalidadesMap[$pUserKey][$modExamen])) $doctorTieneModalidad = true;
                if (!empty($pNomKey) && isset($medicosModalidadesMap[$pNomKey][$modExamen])) $doctorTieneModalidad = true;
                if ($modExamen === 'TARIFAS_ESPECIALES' && $isDoctorEspecial) $doctorTieneModalidad = true;
                if ($modExamen === 'DEGLUCIONES' && $isDoctorDeglucion) $doctorTieneModalidad = true;
            }

            // CASO: Si el médico tiene asignada la modalidad del examen
            if ($doctorTieneModalidad && !empty($modExamen)) {
                $mCfg = $modalidadesConfigMap[$modExamen] ?? null;
                $mTipoCalc = $mCfg['tipo_calculo'] ?? 'PORCENTAJE';
                $mValorFijo = (float)($mCfg['valor_fijo'] ?? 0);

                if ($mTipoCalc === 'VALOR_FIJO') {
                    return [
                        'valor_und'          => $mValorFijo,
                        'pagar_por_cantidad' => 1,
                        'base_calculo'       => 'VALOR_LIQUIDACION'
                    ];
                }

                if ($modExamen === 'TARIFAS_ESPECIALES') {
                    // Tarifas Especiales únicamente para paciente PARTICULAR ('P')
                    if ($tipoPac === 'P') {
                        $pct = floatval($porcentajesPagoMap['TARIFAS_ESPECIALES'] ?? 30.0);
                        if ($valorExamenUnitario > 0) {
                            return [
                                'valor_und'          => round($valorExamenUnitario * ($pct / 100.0), 2),
                                'pagar_por_cantidad' => 1,
                                'base_calculo'       => 'VALOR_LIQUIDACION'
                            ];
                        }
                        return $obtenerTarifaGeneral();
                    } else {
                        return $obtenerTarifaGeneral();
                    }
                } else {
                    // Degluciones u otras modalidades dinámicas configuradas
                    $pct = floatval($porcentajesPagoMap[$modExamen] ?? ($modExamen === 'DEGLUCIONES' ? 45.0 : 0));
                    if ($pct > 0 && $valorExamenUnitario > 0) {
                        return [
                            'valor_und'          => round($valorExamenUnitario * ($pct / 100.0), 2),
                            'pagar_por_cantidad' => 1,
                            'base_calculo'       => 'VALOR_LIQUIDACION'
                        ];
                    }
                    return $obtenerTarifaGeneral();
                }
            }

            // Fallback General Estándar
            return $obtenerTarifaGeneral();
        };

        $conceptosNombresMap = [
            'BLOQ'  => 'Bloqueos',
            'RXSI'  => 'RX Simples',
            'RXES'  => 'RX Especiales',
            'ECOG'  => 'Ecografías',
            'DOPP'  => 'Doppler',
            'TOHO'  => 'Tomografías',
            'TOMO'  => 'Tomografías',
            'MAMO'  => 'Mamografías',
            'BIOP'  => 'Biopsias',
            'CONS'  => 'Consultas',
            'RGCP'  => 'RX Simples (RGCP)',
            'BONI_TOHO' => 'Bonificación Tomografías (Regla 50x$150.000)',
            'OTROS' => 'Otros Exámenes'
        ];

        // Función para clasificar si un examen es de tipo BLOQUEO
        $esEstudioBloqueo = function($cupsText, $conceptoCode = '', $cupsCodServinte = '', $cupsDescripcionServinte = '', $confEsp = null) use (&$tarifarioBloqueosMap) {
            $cleanP = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$cupsText)));
            $cleanS = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$cupsCodServinte)));
            if ((!empty($cleanP) && isset($tarifarioBloqueosMap[$cleanP])) || (!empty($cleanS) && isset($tarifarioBloqueosMap[$cleanS]))) {
                return true;
            }
            if ($confEsp) {
                $tPago = strtoupper(trim((string)($confEsp['tipo_pago'] ?? '')));
                if ($tPago === 'BLOQUEO_HO' || $tPago === 'BLOQUEOS_HO') return true;
                $tTipo = strtoupper(trim((string)($confEsp['tipo'] ?? '')));
                if (strpos($tTipo, 'BLOQUEO') !== false) return true;
                $tEst = strtoupper(trim((string)($confEsp['estudio'] ?? '')));
                if (strpos($tEst, 'BLOQUEO') !== false) return true;
            }
            $cCode = strtoupper(trim((string)$conceptoCode));
            if ($cCode === 'BLOQUEO' || $cCode === 'BLOQUEOS') {
                return true;
            }
            $haystack = mb_strtoupper(trim($cupsText . ' ' . $cupsDescripcionServinte . ' ' . $cupsCodServinte));
            if (strpos($haystack, 'BLOQUEO') !== false || strpos($haystack, 'BLOQUEOS') !== false) {
                return true;
            }
            return false;
        };

        // Función para clasificar si un examen es Tomografía Contrastada (aplica bonificación de $150.000 COP por cada 50)
        $esTomografiaContrastada = function($cupsText, $conceptoCode, $cupsCodServinte, $cupsDescripcionServinte) use (&$tacContrastadasMap, &$tacSimpleMap) {
            $pTokens = preg_split('/[\s\-]+/', trim((string)$cupsText), 3);
            $pToken0 = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($pTokens[0] ?? '')));
            $pToken1 = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($pTokens[1] ?? '')));
            $sCode   = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$cupsCodServinte)));

            // 1. Coincidencia directa con el Maestro de Tarifas (tipo = 'TAC CONTRASTADA')
            if (!empty($pToken0) && isset($tacContrastadasMap[$pToken0])) return true;
            if (!empty($pToken1) && isset($tacContrastadasMap[$pToken1])) return true;
            if (!empty($sCode) && isset($tacContrastadasMap[$sCode])) return true;

            // 2. Si el código está explícitamente en el mapa de TAC SIMPLE:
            if (!empty($pToken0) && isset($tacSimpleMap[$pToken0])) return false;
            if (!empty($pToken1) && isset($tacSimpleMap[$pToken1])) return false;
            if (!empty($sCode) && isset($tacSimpleMap[$sCode])) return false;

            // 3. Verificación de seguridad por texto:
            $haystack = mb_strtoupper(trim($cupsText . ' ' . $cupsDescripcionServinte));
            $cCode    = mb_strtoupper(trim((string)$conceptoCode));

            // Excluir expresamente radiografías simples o especiales, mamografías o ecografías
            if (strpos($haystack, 'RADIOGRAF') !== false || strpos($haystack, 'ECOGRAF') !== false || 
                strpos($haystack, 'ULTRASON') !== false  || strpos($haystack, 'MAMOGRAF') !== false) {
                return false;
            }
            if (preg_match('/^87[123]\d{3}/i', $sCode)) {
                return false;
            }

            // Debe pertenecer al concepto o descripción de Tomografía
            $isTomo = ($cCode === 'TOHO' || $cCode === 'TOMO' || 
                       strpos($haystack, 'TOMOGRAF') !== false || 
                       strpos($haystack, 'TAC') !== false || 
                       strpos($haystack, 'ANGIOTAC') !== false || 
                       strpos($haystack, 'UROTC') !== false || 
                       strpos($haystack, 'UROTOMOGRAF') !== false ||
                       preg_match('/^[A-Z]?79\d{3,4}/i', $sCode) || 
                       preg_match('/^879\d{3,4}/i', $sCode));

            if (!$isTomo) return false;

            // Si expresamente dice 'SIMPLE' y NO menciona 'CONTRAST', es simple -> NO aplica bono
            if (strpos($haystack, 'SIMPLE') !== false && strpos($haystack, 'CONTRAST') === false) {
                return false;
            }

            // Criterios de contraste positivo:
            $hasContraste = (strpos($haystack, 'CONTRAST') !== false || 
                             strpos($haystack, 'CON CONTRASTE') !== false || 
                             strpos($haystack, 'ANGIOTAC') !== false || 
                             strpos($haystack, 'ANGIOTOMOGRAF') !== false || 
                             strpos($haystack, 'UROTC') !== false || 
                             strpos($haystack, 'UROTOMOGRAF') !== false || 
                             strpos($haystack, 'TRIFASICO') !== false);

            return $hasContraste;
        };

        // --- C.2 PROCESAR EVENTOS DE PROTEO Y REALIZAR MATCH ---
        $itemCounter = 0;
        foreach ($proteoItems as $pItem) {
            $itemCounter++;
            $pItem['unique_id'] = 'PROT_' . ($pItem['id'] ?? '0') . '_' . $itemCounter;

            if (empty($pItem['fuente']) || empty($pItem['ingreso'])) {
                $pItem['cruce']        = 'INCOMPLETO_PROTEO';
                $pItem['discrepancia'] = 'Sin Fuente o Ingreso registrado en los parámetros del evento de Proteo';
            } else {
                $pFue      = strtoupper(trim((string)$pItem['fuente']));
                $pIng      = trim((string)$pItem['ingreso']);
                $pIngClean = ltrim($pIng, '0');
                if ($pIngClean === '') $pIngClean = '0';

                $keyExact = $pFue . '_' . $pIng;
                $keyClean = $pFue . '_' . $pIngClean;

                $candExact = $servinteByFueIng[$keyExact] ?? [];
                $candClean = $servinteByFueIng[$keyClean] ?? [];
                $candidateIndices = array_values(array_unique(array_merge($candExact, $candClean)));

                $pCupsCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($pItem['cups']));
                $pCupsSec  = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($pItem['cups']));

                if (!empty($candidateIndices)) {
                    $matchedIdx = null;

                    // FASE 1: Búsqueda de coincidencia EXACTA del código CUPS principal (Prioridad Máxima Absoluta)
                    foreach ($candidateIndices as $idx) {
                        $sCand = $servinteItemsList[$idx];
                        if (!$sCand['matched']) {
                            $sCodRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sCand['codigo_examen'] ?? ''))));
                            if (!empty($pCupsCode) && !empty($sCodRaw) && $pCupsCode === $sCodRaw) {
                                $matchedIdx = $idx;
                                break;
                            }
                        }
                    }

                    // FASE 2: Si no hubo coincidencia exacta en Fase 1, evaluar códigos secundarios CUPS válidos
                    if ($matchedIdx === null) {
                        foreach ($candidateIndices as $idx) {
                            $sCand = $servinteItemsList[$idx];
                            if (!$sCand['matched']) {
                                $sCodRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sCand['codigo_examen'] ?? ''))));
                                $sExaSec = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sCand['examen_sec'] ?? ''))));

                                $isMatch = false;

                                // 2.1 Coincidencia de código principal de Proteo con código secundario de Servinte
                                if (!empty($pCupsCode) && !empty($sExaSec) && $pCupsCode === $sExaSec) {
                                    $isMatch = true;
                                }
                                // 2.2 Coincidencia por código CUPS secundario de Proteo con código de Servinte
                                elseif (!empty($pCupsSec) && !empty($sCodRaw) && $pCupsSec === $sCodRaw) {
                                    $isMatch = true;
                                }
                                // 2.3 Coincidencia de código secundario de Proteo con código secundario de Servinte
                                elseif (!empty($pCupsSec) && !empty($sExaSec) && $pCupsSec === $sExaSec) {
                                    $isMatch = true;
                                }

                                if ($isMatch) {
                                    $matchedIdx = $idx;
                                    break;
                                }
                            }
                        }
                    }

                    if ($matchedIdx !== null) {
                        // MATCH EXITOSO POR FUENTE, INGRESO Y CUPS
                        $servinteItemsList[$matchedIdx]['matched'] = true;
                        $bestMatch = $servinteItemsList[$matchedIdx];

                        $pItem['cruce']        = 'CRUZADO';
                        $pItem['servinte']     = $bestMatch;
                        $pItem['discrepancia'] = 'Cruzado Exitosamente por Fuente (' . $pItem['fuente'] . '), Ingreso (' . $pItem['ingreso'] . ') y CUPS (' . ($bestMatch['codigo_examen'] ?? '') . ')';

                        $sRowsForIngreso = array_map(function($i) use ($servinteItemsList) { return $servinteItemsList[$i]; }, $candidateIndices);
                        $pItem['servinte_total_ingreso'] = array_sum(array_column($sRowsForIngreso, 'total'));
                        $pItem['servinte_items']         = $sRowsForIngreso;
                    } else {
                        $pItem['cruce']        = 'SOLO_PROTEO';
                        $pItem['discrepancia'] = 'No Cruzado: Existen facturas en Servinte para Fuente (' . $pItem['fuente'] . ') e Ingreso (' . $pItem['ingreso'] . '), pero el código CUPS no coincide (Proteo: ' . $pItem['cups'] . ')';
                        $pItem['servinte']     = null;

                        // Solo asociar servinte_unmatched si existe coincidencia de código secundario o afín, NUNCA si son estudios totalmente diferentes
                        $matchedUnmatched = null;
                        foreach ($candidateIndices as $cIdx) {
                            $sc = $servinteItemsList[$cIdx];
                            $scCod = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sc['codigo_examen'] ?? ''))));
                            $scSec = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sc['examen_sec'] ?? ''))));
                            if ((!empty($pCupsCode) && !empty($scSec) && $pCupsCode === $scSec) ||
                                (!empty($pCupsSec) && !empty($scCod) && $pCupsSec === $scCod) ||
                                (!empty($pCupsSec) && !empty($scSec) && $pCupsSec === $scSec)) {
                                $matchedUnmatched = $sc;
                                break;
                            }
                        }
                        $pItem['servinte_unmatched'] = $matchedUnmatched;
                    }
                } else {
                    $pItem['cruce']              = 'SOLO_PROTEO';
                    $pItem['discrepancia']       = 'No Cruzado: La combinación Fuente (' . $pItem['fuente'] . ') e Ingreso (' . $pItem['ingreso'] . ') no fue encontrada en Servinte';
                    $pItem['servinte']           = null;
                    $pItem['servinte_unmatched'] = null;
                }
            }

            // Calcular Valor a Pagar según Tarifario y Tipo de Paciente (E / P)
            $sInfo = $pItem['servinte'] ?? ($pItem['servinte_unmatched'] ?? null);
            $cupsCodeServinte   = $sInfo['codigo_examen'] ?? '';
            $tipoPacItem        = $sInfo['tipo_paciente'] ?? ($pItem['tipo_paciente'] ?? 'E');
            if (empty($tipoPacItem)) $tipoPacItem = 'E';
            $cantItem           = (float)($sInfo['cantidad'] ?? 1);
            if ($cantItem <= 0) $cantItem = 1;
            $valorTotalServinte = (float)($sInfo['total'] ?? 0);
            $valorUndServinte   = ($cantItem > 0 && $valorTotalServinte > 0) ? ($valorTotalServinte / $cantItem) : $valorTotalServinte;

            $tarifaInfo       = $obtenerTarifa($pItem['cups'], $tipoPacItem, $cupsCodeServinte, $pItem['usuario_medico'] ?? '', $pItem['usuario'] ?? '', $valorUndServinte);
            $valUndTarifa     = is_array($tarifaInfo) ? (float)($tarifaInfo['valor_und'] ?? 0) : (float)$tarifaInfo;
            $pagarPorCantidad = is_array($tarifaInfo) ? (int)($tarifaInfo['pagar_por_cantidad'] ?? 1) : 1;
            $baseCalculo      = is_array($tarifaInfo) ? strtoupper(trim((string)($tarifaInfo['base_calculo'] ?? 'VALOR_LIQUIDACION'))) : 'VALOR_LIQUIDACION';

            // Base de cálculo: VALOR_EXAMEN toma el valor unitario de Servinte ($valorUndServinte), VALOR_LIQUIDACION toma la tarifa de LIHO
            $valorBaseCalculo = ($baseCalculo === 'VALOR_EXAMEN') ? $valorUndServinte : $valUndTarifa;
            // Pagar por cantidad: si es 1 (Sí) multiplica por la cantidad de Servinte, si es 0 (No) paga valor unitario fijo sin multiplicar
            $valorAPagar      = ($pagarPorCantidad != 0) ? ($valorBaseCalculo * $cantItem) : $valorBaseCalculo;

            $pItem['tipo_paciente']       = $tipoPacItem;
            $pItem['valor_und_tarifario'] = ($baseCalculo === 'VALOR_EXAMEN') ? $valorUndServinte : $valUndTarifa;
            $pItem['tarifa_base_calculo'] = $baseCalculo;
            $pItem['pagar_por_cantidad']  = $pagarPorCantidad;
            $pItem['cantidad']            = $cantItem;
            $pItem['valor_a_pagar']       = $valorAPagar;

            // Verificación y Liquidación Especial para Médicos con Modalidad BLOQUEO_HO / BLOQUEOS_HO
            $pMedUser = strtoupper(trim((string)($pItem['usuario_medico'] ?? '')));
            $pMedNom  = strtoupper(trim((string)($pItem['usuario'] ?? '')));
            $docTieneBloqueoHO = (!empty($pMedUser) && (isset($medicosModalidadesMap[$pMedUser]['BLOQUEO_HO']) || isset($medicosModalidadesMap[$pMedUser]['BLOQUEOS_HO']))) ||
                                 (!empty($pMedNom)  && (isset($medicosModalidadesMap[$pMedNom]['BLOQUEO_HO'])  || isset($medicosModalidadesMap[$pMedNom]['BLOQUEOS_HO'])));

            $sExaNom = $sInfo['examen'] ?? '';
            $sExaCon = $sInfo['concepto'] ?? '';
            $cleanCUPSP = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($pItem['cups'] ?? ''))));
            $cleanCUPSS = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$cupsCodeServinte)));
            $cfgBloqueo = $tarifarioBloqueosMap[$cleanCUPSP] ?? ($tarifarioBloqueosMap[$cleanCUPSS] ?? null);

            $esBloqueo = ($cfgBloqueo !== null) || $esEstudioBloqueo($pItem['cups'] ?? '', $sExaCon, $cupsCodeServinte, $sExaNom);

            if ($docTieneBloqueoHO && $esBloqueo) {
                // Esquema de Porcentajes y Valores por Cantidad desde Tarifario Bloqueos HO:
                $pctBase  = $cfgBloqueo ? (float)$cfgBloqueo['porcentaje_base'] : floatval($porcentajesPagoMap['BLOQUEOS_HO'] ?? $porcentajesPagoMap['BLOQUEO_HO'] ?? 40.0);
                $pctCant2 = $cfgBloqueo ? (float)$cfgBloqueo['porcentaje_cant_2'] : 70.0;
                $pctCant3 = $cfgBloqueo ? (float)$cfgBloqueo['porcentaje_cant_3'] : 60.0;

                $valBase  = $cfgBloqueo ? (float)$cfgBloqueo['valor_base'] : 0.0;
                $valCant2 = $cfgBloqueo ? (float)$cfgBloqueo['valor_cant_2'] : 0.0;
                $valCant3 = $cfgBloqueo ? (float)$cfgBloqueo['valor_cant_3'] : 0.0;

                $cantInt = (int)round($cantItem);

                // Detección del nivel de cantidad:
                // Se determina por cantidad registrada O por coincidencia del valor facturado en Servinte con la tarifa del portafolio (ej: $5.542.680 -> Cant. 2 al 70%, $7.498.920 -> Cant. 3 al 60%)
                $esTier3 = ($cantInt >= 3) || ($valCant3 > 0 && abs($valorTotalServinte - $valCant3) < 100);
                $esTier2 = !$esTier3 && (($cantInt == 2) || ($valCant2 > 0 && abs($valorTotalServinte - $valCant2) < 100));

                if ($esTier3) {
                    $pctBloqueo       = $pctCant3;
                    $tierLabel        = 'Cant. 3+';
                    $valEstudioTier   = $valCant3;
                } elseif ($esTier2) {
                    $pctBloqueo       = $pctCant2;
                    $tierLabel        = 'Cant. 2';
                    $valEstudioTier   = $valCant2;
                } else {
                    $pctBloqueo       = $pctBase;
                    $tierLabel        = 'Cant. 1';
                    $valEstudioTier   = $valBase;
                }

                $baseTotalEstudio  = $valorTotalServinte > 0 ? $valorTotalServinte : ($valEstudioTier > 0 ? $valEstudioTier : ($valBase > 0 ? $valBase : $valorAPagar));
                $valorPagarBloqueo = round($baseTotalEstudio * ($pctBloqueo / 100.0), 2);
                $valorUndBloqueo   = $cantItem > 0 ? round($valorPagarBloqueo / $cantItem, 2) : $valorPagarBloqueo;

                $pItem['valor_und_tarifario']   = $valorUndBloqueo;
                $pItem['valor_a_pagar']         = $valorPagarBloqueo;
                $pItem['es_bloqueo_ho']         = true;
                $pItem['bloqueo_pct']           = $pctBloqueo;
                $pItem['bloqueo_tier_label']    = $tierLabel;
                $pItem['bloqueo_valor_estudio'] = $baseTotalEstudio;
                $pItem['bloqueo_nota']          = $cfgBloqueo && !empty($cfgBloqueo['observaciones']) ? $cfgBloqueo['observaciones'] : 'Estas condiciones están sujetas a lo contratado por cada entidad, por ende pueden variar';
                $pItem['tarifa_origen']         = 'TARIFARIO_BLOQUEOS';
            } else {
                $pItem['es_bloqueo_ho']         = false;
                $pItem['bloqueo_pct']           = 0;
                $pItem['bloqueo_tier_label']    = '';
                $pItem['bloqueo_valor_estudio'] = 0;
                $pItem['bloqueo_nota']          = '';
            }

            // Regla de Negocio: Médicos pertenecientes a IMADINSA SAS
            // Sólo se factura cuando en el registro de Servinte dice "IPS ALIVIO INTEGRAL DEL DOLOR SAS"
            $pMedUserUpper = strtoupper(trim((string)($pItem['usuario_medico'] ?? '')));
            $pMedNomUpper  = strtoupper(trim((string)($pItem['usuario'] ?? '')));
            $pMedCedRaw    = preg_replace('/[^0-9]/', '', $pMedUserUpper);

            $docEntId = $medicosEntidadMap[$pMedUserUpper] ?? ($medicosEntidadMap[$pMedNomUpper] ?? ($medicosEntidadMap[$pMedCedRaw] ?? null));
            $isDoctorImadinsa = (!empty($docEntId) && isset($imadinsaEntidadIds[(int)$docEntId])) || (!empty($entActivaSesion) && isset($imadinsaEntidadIds[(int)$entActivaSesion]));

            if ($isDoctorImadinsa) {
                $entidadServinte = strtoupper(trim((string)($sInfo['entidad'] ?? '')));
                $esAlivioIntegral = (stripos($entidadServinte, 'ALIVIO INTEGRAL') !== false);

                $pItem['es_medico_imadinsa'] = true;
                if (!$esAlivioIntegral) {
                    $pItem['valor_und_tarifario']    = 0.0;
                    $pItem['valor_a_pagar']          = 0.0;
                    $pItem['no_facturable_imadinsa'] = true;
                    $pItem['motivo_no_facturable']   = 'Médico perteneciente a IMADINSA SAS: sólo se factura cuando el registro es de IPS ALIVIO INTEGRAL DEL DOLOR SAS (Entidad del registro: ' . ($entidadServinte ?: 'Sin Entidad') . ')';
                } else {
                    $pItem['no_facturable_imadinsa'] = false;
                }
            } else {
                $pItem['es_medico_imadinsa']     = false;
                $pItem['no_facturable_imadinsa'] = false;
            }

            // Determinar Concepto y Servicio del Examen
            $conceptoRaw       = '';
            $servicioRaw       = '';
            $cuentaContableRaw = '';
            $nombreCuentaRaw   = '';

            // 1. Por código Servinte
            if (!empty($cupsCodeServinte)) {
                $cleanCodS = preg_replace('/[^A-Z0-9]/', '', $cupsCodeServinte);
                if (isset($cupsConceptoMap[$cleanCodS]))       $conceptoRaw       = $cupsConceptoMap[$cleanCodS];
                if (isset($cupsServicioMap[$cleanCodS]))       $servicioRaw       = $cupsServicioMap[$cleanCodS];
                if (isset($cupsCuentaContableMap[$cleanCodS])) $cuentaContableRaw = $cupsCuentaContableMap[$cleanCodS];
                if (isset($cupsNombreCuentaMap[$cleanCodS]))   $nombreCuentaRaw   = $cupsNombreCuentaMap[$cleanCodS];
            }
            // 2. Por código principal de Proteo
            if (empty($conceptoRaw) || empty($servicioRaw)) {
                $cleanPCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($pItem['cups'] ?? ''));
                if (empty($conceptoRaw) && isset($cupsConceptoMap[$cleanPCode]))       $conceptoRaw       = $cupsConceptoMap[$cleanPCode];
                if (empty($servicioRaw) && isset($cupsServicioMap[$cleanPCode]))       $servicioRaw       = $cupsServicioMap[$cleanPCode];
                if (empty($cuentaContableRaw) && isset($cupsCuentaContableMap[$cleanPCode])) $cuentaContableRaw = $cupsCuentaContableMap[$cleanPCode];
                if (empty($nombreCuentaRaw) && isset($cupsNombreCuentaMap[$cleanPCode]))   $nombreCuentaRaw   = $cupsNombreCuentaMap[$cleanPCode];
            }
            // 3. Por código secundario de Proteo
            if (empty($conceptoRaw) || empty($servicioRaw)) {
                $cleanPSec = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($pItem['cups'] ?? ''));
                if (empty($conceptoRaw) && isset($cupsConceptoMap[$cleanPSec]))       $conceptoRaw       = $cupsConceptoMap[$cleanPSec];
                if (empty($servicioRaw) && isset($cupsServicioMap[$cleanPSec]))       $servicioRaw       = $cupsServicioMap[$cleanPSec];
                if (empty($cuentaContableRaw) && isset($cupsCuentaContableMap[$cleanPSec])) $cuentaContableRaw = $cupsCuentaContableMap[$cleanPSec];
                if (empty($nombreCuentaRaw) && isset($cupsNombreCuentaMap[$cleanPSec]))   $nombreCuentaRaw   = $cupsNombreCuentaMap[$cleanPSec];
            }
            // 4. Si Servinte traía concepto
            if (empty($conceptoRaw) && !empty($sInfo['concepto'])) {
                $conceptoRaw = strtoupper(trim((string)$sInfo['concepto']));
            }
            // 5. Análisis textual en cups/examen
            if (empty($conceptoRaw)) {
                $nomCupsUpper = strtoupper(trim((string)($pItem['cups'] ?? '')));
                $nomExaUpper  = strtoupper(trim((string)($sInfo['examen'] ?? ($pItem['nombre_examen'] ?? ''))));
                $haystackNom  = $nomCupsUpper . ' ' . $nomExaUpper;
                $cleanPNum    = preg_replace('/[^0-9]/', '', $nomCupsUpper);
                if (strpos($haystackNom, 'ESPECIAL') !== false) {
                    $conceptoRaw = 'RXES';
                } elseif (strpos($haystackNom, 'DOPP') !== false || substr($cleanPNum, 0, 3) === '882') {
                    $conceptoRaw = 'DOPP';
                } elseif (strpos($haystackNom, 'ECO') !== false || strpos($haystackNom, 'ULTRASO') !== false || substr($cleanPNum, 0, 3) === '881') {
                    $conceptoRaw = 'ECOG';
                } elseif (strpos($haystackNom, 'TOMO') !== false || strpos($haystackNom, 'TAC') !== false || substr($cleanPNum, 0, 3) === '879') {
                    $conceptoRaw = 'TOHO';
                } elseif (strpos($haystackNom, 'MAMO') !== false || strpos($haystackNom, 'SENO') !== false || substr($cleanPNum, 0, 4) === '8768') {
                    $conceptoRaw = 'MAMO';
                } elseif (strpos($haystackNom, 'BIOP') !== false || strpos($haystackNom, 'BIO') !== false || strpos($haystackNom, 'BACAF') !== false) {
                    $conceptoRaw = 'BIOP';
                } elseif (strpos($haystackNom, 'BLOQUEO') !== false || strpos($haystackNom, 'BLOQUEOS') !== false) {
                    $conceptoRaw = 'BLOQ';
                } elseif (strpos($haystackNom, 'RESONAN') !== false || strpos($haystackNom, 'RMN') !== false || substr($cleanPNum, 0, 3) === '883') {
                    $conceptoRaw = 'RESO';
                } elseif (strpos($haystackNom, 'RAYOS X') !== false || strpos($haystackNom, 'RADIOGRAF') !== false || strpos($nomCupsUpper, 'RX') !== false) {
                    $conceptoRaw = 'RXSI';
                } else {
                    $conceptoRaw = 'OTROS';
                }
            }
            if ($esBloqueo && ($conceptoRaw === 'OTROS' || empty($conceptoRaw))) {
                $conceptoRaw = 'BLOQ';
            }

            if (empty($servicioRaw)) {
                if ($conceptoRaw === 'RXES' || $cuentaContableRaw === '61251002') $servicioRaw = 'RX ESPECIALES';
                elseif ($conceptoRaw === 'RXSI' || $cuentaContableRaw === '61251001') $servicioRaw = 'RX SIMPLE';
                elseif ($conceptoRaw === 'ECOG') $servicioRaw = 'ECOGRAFÍAS';
                elseif ($conceptoRaw === 'TOHO' || $cuentaContableRaw === '61251007') $servicioRaw = 'TOMOGRAFÍAS';
                elseif ($conceptoRaw === 'MAMO' || $cuentaContableRaw === '61251006') $servicioRaw = 'MAMOGRAFÍAS';
                elseif ($conceptoRaw === 'DOPP') $servicioRaw = 'DOPPLER';
                elseif ($conceptoRaw === 'BIOP') $servicioRaw = 'BIOPSIAS';
                elseif ($conceptoRaw === 'BLOQ') $servicioRaw = 'BLOQUEOS';
                else $servicioRaw = $conceptosNombresMap[$conceptoRaw] ?? $conceptoRaw;
            }

            $pItem['concepto']        = $conceptoRaw;
            $pItem['concepto_nombre'] = $conceptosNombresMap[$conceptoRaw] ?? $conceptoRaw;
            $pItem['servicio']        = $servicioRaw;
            $pItem['cuenta_contable'] = $cuentaContableRaw;
            $pItem['nombre_cuenta']   = $nombreCuentaRaw;
            $pItem['es_tomografia_contrastada'] = $esTomografiaContrastada($pItem['cups'] ?? '', $pItem['concepto'] ?? '', $cupsCodeServinte, $sInfo['examen'] ?? '');

            // Aplicar filtro de cruce si existe
            if (!empty($cruceFiltro)) {
                if ($cruceFiltro === 'TODOS_ERRORES' && $pItem['cruce'] === 'CRUZADO') continue;
                if ($cruceFiltro !== 'TODOS_ERRORES' && $pItem['cruce'] !== $cruceFiltro) continue;
            }

            // Aplicar filtro de búsqueda
            if (!empty($searchKey)) {
                $searchLower = mb_strtolower($searchKey);
                $haystack = mb_strtolower(
                    $pItem['usuario'] . ' ' .
                    $pItem['usuario_medico'] . ' ' .
                    $pItem['fuente'] . ' ' .
                    $pItem['ingreso'] . ' ' .
                    $pItem['cups'] . ' ' .
                    $pItem['modalidad'] . ' ' .
                    $pItem['sede'] . ' ' .
                    ($sInfo['paciente'] ?? '') . ' ' .
                    ($sInfo['identificacion'] ?? '') . ' ' .
                    ($sInfo['examen'] ?? '') . ' ' .
                    ($sInfo['entidad'] ?? '')
                );
                if (strpos($haystack, $searchLower) === false) continue;
            }

            $records[] = $pItem;
        }

        // --- D. PROCESAR REGISTROS QUE EXISTEN SOLO EN SERVINTE (SIN MATCH PREVIO EN PROTEO) ---
        // 1. Recolectar ingresos no cruzados de Servinte para verificación global en Proteo
        $unmatchedIngresosList = [];
        foreach ($servinteItemsList as $sCand) {
            if (!$sCand['matched'] && !empty($sCand['ingreso'])) {
                $unmatchedIngresosList[] = trim((string)$sCand['ingreso']);
            }
        }
        $unmatchedIngresosList = array_unique(array_filter($unmatchedIngresosList));

        // Consulta global en Proteo para verificar estado real de los ingresos (si pertenecen a otro médico o no están finalizados)
        $globalProteoEventsMap = [];
        if (!empty($unmatchedIngresosList)) {
            $chunksUnmatched = array_chunk($unmatchedIngresosList, 300);
            foreach ($chunksUnmatched as $uChunk) {
                $inClauseUnmatched = "'" . implode("','", array_map('addslashes', $uChunk)) . "'";
                $sqlGP = "
                SELECT 
                    AE.Id AS Evento_Id,
                    AE.EventStatusName,
                    U.UserName AS Usuario_Medico,
                    CONCAT(U.Name, ' ', U.Surname) AS Medico_Usuario,
                    VIS.Value AS Ingreso,
                    FUEN.Value AS Fuente,
                    CUP.Value AS CUPS
                FROM dbo.AppEvents AE WITH (NOLOCK)
                INNER JOIN dbo.AbpUsers U WITH (NOLOCK) ON AE.LastModifierUserId = U.Id
                OUTER APPLY (SELECT TOP 1 Value FROM dbo.AppEventDynamicDetails WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'VisitSource') FUEN
                OUTER APPLY (SELECT TOP 1 Value FROM dbo.AppEventDynamicDetails WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'VisitId') VIS
                OUTER APPLY (SELECT * FROM (SELECT DISTINCT Value FROM dbo.AppEventDynamicDetails WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'CUPS') C) CUP
                WHERE VIS.Value IN ($inClauseUnmatched)
                  AND AE.IsDeleted = 0
                ";
                $stGP = sqlsrv_query($connProteo, $sqlGP);
                if ($stGP) {
                    while ($rg = sqlsrv_fetch_array($stGP, SQLSRV_FETCH_ASSOC)) {
                        $gIng = trim((string)$rg['Ingreso']);
                        $gCupsRaw = trim((string)$rg['CUPS']);
                        $gCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($gCupsRaw));
                        $gSecCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($gCupsRaw));
                        $gMedUser = strtoupper(trim((string)$rg['Usuario_Medico']));
                        $gStatus = trim((string)$rg['EventStatusName']);
                        $eventData = [
                            'evento_id'      => $rg['Evento_Id'],
                            'status'         => $gStatus,
                            'usuario_medico' => $gMedUser,
                            'medico'         => $rg['Medico_Usuario'],
                            'cups'           => $gCupsRaw,
                            'is_finalizado'  => in_array($gStatus, ['Finalizado lectura WL', 'Finalizar ECO', 'Finalizado imagen rechazada', 'Enviado a Enfermeria'])
                        ];
                        if (!empty($gCode)) {
                            $globalProteoEventsMap[$gIng][$gCode][] = $eventData;
                        }
                        if (!empty($gSecCode) && $gSecCode !== $gCode) {
                            $globalProteoEventsMap[$gIng][$gSecCode][] = $eventData;
                        }
                    }
                }
            }
        }

        foreach ($servinteItemsList as $sItemData) {
            if (!$sItemData['matched']) {
                $sFue      = strtoupper(trim((string)$sItemData['fuente']));
                $sIng      = trim((string)$sItemData['ingreso']);
                $sIngClean = ltrim($sIng, '0');
                if ($sIngClean === '') $sIngClean = '0';

                $kExact = $sFue . '_' . $sIng;
                $kClean = $sFue . '_' . $sIngClean;

                $candExact = $proteoByFueIngMap[$kExact] ?? [];
                $candClean = $proteoByFueIngMap[$kClean] ?? [];
                $proteoCandidates = array_merge($candExact, $candClean);

                // 1. Verificación de Seguridad Estricta: ¿Existe en Proteo un registro con el MISMO código CUPS para este Ingreso?
                $sCodClean = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sItemData['codigo_examen'] ?? ''))));
                $sSecClean = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sItemData['examen_sec'] ?? ''))));

                $matchingProteoCand = null;
                $candidateDiferenteCups = null;

                foreach ($proteoCandidates as $pCand) {
                    $pCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($pCand['cups'] ?? ''));
                    $pSec  = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($pCand['cups'] ?? ''));

                    // Match exacto o secundario legítimo
                    if ((!empty($sCodClean) && !empty($pCode) && $sCodClean === $pCode) ||
                        (!empty($sSecClean) && !empty($pCode) && $sSecClean === $pCode) ||
                        (!empty($sCodClean) && !empty($pSec) && $sCodClean === $pSec) ||
                        (!empty($sSecClean) && !empty($pSec) && $sSecClean === $pSec)) {
                        $matchingProteoCand = $pCand;
                        break;
                    }

                    // Candidato de código secundario/homologable
                    if ($candidateDiferenteCups === null && (!empty($sSecClean) || !empty($pSec))) {
                        $candidateDiferenteCups = $pCand;
                    }
                }

                // SI COINCIDE EL CÓDIGO CUPS: Este examen está legítimamente cruzado con Proteo
                if ($matchingProteoCand !== null) {
                    // Si hay filtro médico y este registro no pertenece al médico filtrado, omitir
                    if (!empty($medicoFiltro)) {
                        $uCandMed = strtoupper(trim((string)($matchingProteoCand['usuario_medico'] ?? '')));
                        $uCandNom = strtoupper(trim((string)($matchingProteoCand['usuario'] ?? '')));
                        $mFiltroUpper = strtoupper($medicoFiltro);
                        if ($uCandMed !== $mFiltroUpper && strpos($uCandNom, $mFiltroUpper) === false) {
                            continue;
                        }
                    }

                    $itemCounter++;
                    $sItem = [
                        'origen'           => 'SERVINTE',
                        'id'               => 'CRUZ-' . $sItemData['fuente'] . '-' . $sItemData['ingreso'] . '-' . $sItemData['codigo_examen'],
                        'unique_id'        => 'CRUZ_' . $sItemData['fuente'] . '_' . $sItemData['ingreso'] . '_' . $itemCounter,
                        'documento'        => !empty($matchingProteoCand['documento']) ? $matchingProteoCand['documento'] : $sItemData['identificacion'],
                        'nombre'           => !empty($matchingProteoCand['nombre']) ? $matchingProteoCand['nombre'] : $sItemData['paciente'],
                        'fecha'            => !empty($matchingProteoCand['fecha']) ? $matchingProteoCand['fecha'] : $sItemData['fecha'],
                        'fuente'           => $sItemData['fuente'],
                        'ingreso'          => $sItemData['ingreso'],
                        'cups'             => $matchingProteoCand['cups'] ?? ($sItemData['codigo_examen'] . ' - ' . $sItemData['examen']),
                        'modalidad'        => $matchingProteoCand['modalidad'] ?? 'SERVINTE',
                        'estado_actual'    => $matchingProteoCand['estado_actual'] ?? 'Facturado y Leído',
                        'sede'             => !empty($matchingProteoCand['sede']) ? $matchingProteoCand['sede'] : $sItemData['sede'],
                        'usuario'          => $matchingProteoCand['usuario'],
                        'usuario_medico'   => $matchingProteoCand['usuario_medico'],
                        'tipo_paciente'    => $sItemData['tipo_paciente'] ?? 'E',
                        'servinte'         => $sItemData,
                        'servinte_total_ingreso' => $sItemData['total'],
                        'servinte_items'   => [$sItemData],
                        'proteo_unmatched' => null,
                        'cruce'            => 'CRUZADO',
                        'discrepancia'     => 'Cruzado Exitosamente por Fuente (' . $sItemData['fuente'] . '), Ingreso (' . $sItemData['ingreso'] . ') y CUPS (' . $sItemData['codigo_examen'] . ')'
                    ];

                    $cupsCodeServinte   = $sItemData['codigo_examen'] ?? '';
                    $tipoPacItem        = $sItemData['tipo_paciente'] ?? 'E';
                    $cantItem           = (float)($sItemData['cantidad'] ?? 1);
                    if ($cantItem <= 0) $cantItem = 1;
                    $valorTotalServinte = (float)($sItemData['total'] ?? 0);
                    $valorUndServinte   = ($cantItem > 0 && $valorTotalServinte > 0) ? ($valorTotalServinte / $cantItem) : $valorTotalServinte;

                    $tarifaInfo       = $obtenerTarifa($sItem['cups'], $tipoPacItem, $cupsCodeServinte, $sItem['usuario_medico'] ?? '', $sItem['usuario'] ?? '', $valorUndServinte);
                    $valUndTarifa     = is_array($tarifaInfo) ? (float)($tarifaInfo['valor_und'] ?? 0) : (float)$tarifaInfo;
                    $pagarPorCantidad = is_array($tarifaInfo) ? (int)($tarifaInfo['pagar_por_cantidad'] ?? 1) : 1;
                    $baseCalculo      = is_array($tarifaInfo) ? strtoupper(trim((string)($tarifaInfo['base_calculo'] ?? 'VALOR_LIQUIDACION'))) : 'VALOR_LIQUIDACION';

                    $valorBaseCalculo = ($baseCalculo === 'VALOR_EXAMEN') ? $valorUndServinte : $valUndTarifa;
                    $valorAPagar      = ($pagarPorCantidad != 0) ? ($valorBaseCalculo * $cantItem) : $valorBaseCalculo;

                    $sItem['valor_und_tarifario'] = ($baseCalculo === 'VALOR_EXAMEN') ? $valorUndServinte : $valUndTarifa;
                    $sItem['tarifa_base_calculo'] = $baseCalculo;
                    $sItem['pagar_por_cantidad']  = $pagarPorCantidad;
                    $sItem['cantidad']            = $cantItem;
                    $sItem['valor_a_pagar']       = $valorAPagar;

                    // Verificación y Liquidación Especial para Médicos con Modalidad BLOQUEO_HO / BLOQUEOS_HO
                    $sMedUser = strtoupper(trim((string)($sItem['usuario_medico'] ?? '')));
                    $sMedNom  = strtoupper(trim((string)($sItem['usuario'] ?? '')));
                    $docTieneBloqueoHO = (!empty($sMedUser) && (isset($medicosModalidadesMap[$sMedUser]['BLOQUEO_HO']) || isset($medicosModalidadesMap[$sMedUser]['BLOQUEOS_HO']))) ||
                                         (!empty($sMedNom)  && (isset($medicosModalidadesMap[$sMedNom]['BLOQUEO_HO'])  || isset($medicosModalidadesMap[$sMedNom]['BLOQUEOS_HO'])));
                    $cleanCUPSP = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sItem['cups'] ?? ''))));
                    $cleanCUPSS = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$cupsCodeServinte)));
                    $cfgBloqueo = $tarifarioBloqueosMap[$cleanCUPSP] ?? ($tarifarioBloqueosMap[$cleanCUPSS] ?? null);

                    $esBloqueo = ($cfgBloqueo !== null) || $esEstudioBloqueo($sItem['cups'] ?? '', $sItemData['concepto'] ?? '', $cupsCodeServinte, $sItemData['examen'] ?? '');

                    if ($docTieneBloqueoHO && $esBloqueo) {
                        $pctBase  = $cfgBloqueo ? (float)$cfgBloqueo['porcentaje_base'] : floatval($porcentajesPagoMap['BLOQUEOS_HO'] ?? $porcentajesPagoMap['BLOQUEO_HO'] ?? 40.0);
                        $pctCant2 = $cfgBloqueo ? (float)$cfgBloqueo['porcentaje_cant_2'] : 70.0;
                        $pctCant3 = $cfgBloqueo ? (float)$cfgBloqueo['porcentaje_cant_3'] : 60.0;

                        $valBase  = $cfgBloqueo ? (float)$cfgBloqueo['valor_base'] : 0.0;
                        $valCant2 = $cfgBloqueo ? (float)$cfgBloqueo['valor_cant_2'] : 0.0;
                        $valCant3 = $cfgBloqueo ? (float)$cfgBloqueo['valor_cant_3'] : 0.0;

                        $cantInt = (int)round($cantItem);

                        $esTier3 = ($cantInt >= 3) || ($valCant3 > 0 && abs($valorTotalServinte - $valCant3) < 100);
                        $esTier2 = !$esTier3 && (($cantInt == 2) || ($valCant2 > 0 && abs($valorTotalServinte - $valCant2) < 100));

                        if ($esTier3) {
                            $pctBloqueo       = $pctCant3;
                            $tierLabel        = 'Cant. 3+';
                            $valEstudioTier   = $valCant3;
                        } elseif ($esTier2) {
                            $pctBloqueo       = $pctCant2;
                            $tierLabel        = 'Cant. 2';
                            $valEstudioTier   = $valCant2;
                        } else {
                            $pctBloqueo       = $pctBase;
                            $tierLabel        = 'Cant. 1';
                            $valEstudioTier   = $valBase;
                        }

                        $baseTotalEstudio  = $valorTotalServinte > 0 ? $valorTotalServinte : ($valEstudioTier > 0 ? $valEstudioTier : ($valBase > 0 ? $valBase : $valorAPagar));
                        $valorPagarBloqueo = round($baseTotalEstudio * ($pctBloqueo / 100.0), 2);
                        $valorUndBloqueo   = $cantItem > 0 ? round($valorPagarBloqueo / $cantItem, 2) : $valorPagarBloqueo;

                        $sItem['valor_und_tarifario']   = $valorUndBloqueo;
                        $sItem['valor_a_pagar']         = $valorPagarBloqueo;
                        $sItem['es_bloqueo_ho']         = true;
                        $sItem['bloqueo_pct']           = $pctBloqueo;
                        $sItem['bloqueo_tier_label']    = $tierLabel;
                        $sItem['bloqueo_valor_estudio'] = $baseTotalEstudio;
                        $sItem['bloqueo_nota']          = $cfgBloqueo && !empty($cfgBloqueo['observaciones']) ? $cfgBloqueo['observaciones'] : 'Estas condiciones están sujetas a lo contratado por cada entidad, por ende pueden variar';
                        $sItem['tarifa_origen']         = 'TARIFARIO_BLOQUEOS';
                    } else {
                        $sItem['es_bloqueo_ho']         = false;
                        $sItem['bloqueo_pct']           = 0;
                        $sItem['bloqueo_tier_label']    = '';
                        $sItem['bloqueo_valor_estudio'] = 0;
                        $sItem['bloqueo_nota']          = '';
                    }
                } else {
                    // NO COINCIDE EL CÓDIGO CUPS con los procedimientos leídos en el lote de esta consulta.
                    // Verificación contra el estado global de Proteo:
                    $gpCandidates = $globalProteoEventsMap[$sIng][$sCodClean] ?? ($globalProteoEventsMap[$sIngClean][$sCodClean] ?? ($globalProteoEventsMap[$sIng][$sSecClean] ?? ($globalProteoEventsMap[$sIngClean][$sSecClean] ?? [])));

                    $gpInfo = null;
                    if (!empty($gpCandidates)) {
                        $gpInfo = $gpCandidates[0];
                        // CASO 1: En Proteo este estudio fue finalizado por un médico
                        if ($gpInfo['is_finalizado']) {
                            $docEnAlcance = false;
                            if (!empty($medicoFiltro)) {
                                $docEnAlcance = ($gpInfo['usuario_medico'] === strtoupper($medicoFiltro));
                            } elseif (!empty($medicosBloqueoHOUsernames)) {
                                $docEnAlcance = in_array($gpInfo['usuario_medico'], $medicosBloqueoHOUsernames);
                            } else {
                                $docEnAlcance = true;
                            }
                            if (!$docEnAlcance) {
                                // Pertenece a la liquidación de otro médico. Omitir estrictamente.
                                continue;
                            }
                        } else {
                            // CASO 2: El estudio existe en Proteo pero NO ESTÁ FINALIZADO
                            // No se puede liquidar ni pagar un estudio no finalizado. Omitir de liquidación.
                            continue;
                        }
                    } else {
                        // CASO 3: El estudio facturado en Servinte no tiene ninguna orden/evento en Proteo a nivel hospitalario
                        // Si se está liquidando un médico o bloqueos específicos, omitir porque no tienen lectura que liquidar.
                        if (!empty($medicoFiltro) || !empty($medicosBloqueoHOUsernames)) {
                            continue;
                        }
                    }

                    if (!empty($gpCandidates) && !empty($gpInfo['is_finalizado'])) {
                        // ¡EL ESTUDIO EXISTE Y ESTÁ FINALIZADO EN PROTEO POR UN MÉDICO EN ALCANCE!
                        $itemCounter++;
                        $sItem = [
                            'origen'           => 'SERVINTE',
                            'id'               => 'CRUZ-' . $sItemData['fuente'] . '-' . $sItemData['ingreso'] . '-' . $sItemData['codigo_examen'],
                            'unique_id'        => 'CRUZ_' . $sItemData['fuente'] . '_' . $sItemData['ingreso'] . '_' . $itemCounter,
                            'documento'        => $sItemData['identificacion'],
                            'nombre'           => $sItemData['paciente'],
                            'fecha'            => $sItemData['fecha'],
                            'fuente'           => $sItemData['fuente'],
                            'ingreso'          => $sItemData['ingreso'],
                            'cups'             => !empty($gpInfo['cups']) ? $gpInfo['cups'] : ($sItemData['codigo_examen'] . ' - ' . $sItemData['examen']),
                            'modalidad'        => 'SERVINTE',
                            'estado_actual'    => $gpInfo['status'] ?? 'Finalizado lectura WL',
                            'sede'             => $sItemData['sede'],
                            'usuario'          => $gpInfo['medico'],
                            'usuario_medico'   => $gpInfo['usuario_medico'],
                            'tipo_paciente'    => $sItemData['tipo_paciente'] ?? 'E',
                            'servinte'         => $sItemData,
                            'servinte_total_ingreso' => $sItemData['total'],
                            'servinte_items'   => [$sItemData],
                            'proteo_unmatched' => null,
                            'cruce'            => 'CRUZADO',
                            'discrepancia'     => 'Cruzado Exitosamente por Fuente (' . $sItemData['fuente'] . '), Ingreso (' . $sItemData['ingreso'] . ') y CUPS (' . $sItemData['codigo_examen'] . ')'
                        ];

                        $cupsCodeServinte   = $sItemData['codigo_examen'] ?? '';
                        $tipoPacItem        = $sItemData['tipo_paciente'] ?? 'E';
                        $cantItem           = (float)($sItemData['cantidad'] ?? 1);
                        if ($cantItem <= 0) $cantItem = 1;
                        $valorTotalServinte = (float)($sItemData['total'] ?? 0);
                        $valorUndServinte   = ($cantItem > 0 && $valorTotalServinte > 0) ? ($valorTotalServinte / $cantItem) : $valorTotalServinte;

                        $tarifaInfo       = $obtenerTarifa($sItem['cups'], $tipoPacItem, $cupsCodeServinte, $sItem['usuario_medico'] ?? '', $sItem['usuario'] ?? '', $valorUndServinte);
                        $valUndTarifa     = is_array($tarifaInfo) ? (float)($tarifaInfo['valor_und'] ?? 0) : (float)$tarifaInfo;
                        $pagarPorCantidad = is_array($tarifaInfo) ? (int)($tarifaInfo['pagar_por_cantidad'] ?? 1) : 1;
                        $baseCalculo      = is_array($tarifaInfo) ? strtoupper(trim((string)($tarifaInfo['base_calculo'] ?? 'VALOR_LIQUIDACION'))) : 'VALOR_LIQUIDACION';

                        $valorBaseCalculo = ($baseCalculo === 'VALOR_EXAMEN') ? $valorUndServinte : $valUndTarifa;
                        $valorAPagar      = ($pagarPorCantidad != 0) ? ($valorBaseCalculo * $cantItem) : $valorBaseCalculo;

                        $sItem['valor_und_tarifario'] = ($baseCalculo === 'VALOR_EXAMEN') ? $valorUndServinte : $valUndTarifa;
                        $sItem['tarifa_base_calculo'] = $baseCalculo;
                        $sItem['pagar_por_cantidad']  = $pagarPorCantidad;
                        $sItem['cantidad']            = $cantItem;
                        $sItem['valor_a_pagar']       = $valorAPagar;
                        $sItem['es_bloqueo_ho']       = false;
                        $sItem['bloqueo_pct']         = 0;
                        $sItem['bloqueo_tier_label']  = '';
                        $sItem['bloqueo_valor_estudio'] = 0;
                        $sItem['bloqueo_nota']        = '';
                    } else {
                        // Si llega aquí, es un verdadero registro Solo Servinte (auditoría general)
                        $itemCounter++;
                        $sItem = [
                            'origen'           => 'SERVINTE',
                            'id'               => 'SERV-' . $sItemData['fuente'] . '-' . $sItemData['ingreso'] . '-' . $sItemData['codigo_examen'],
                            'unique_id'        => 'SERV_' . $sItemData['fuente'] . '_' . $sItemData['ingreso'] . '_' . $itemCounter,
                            'documento'        => $sItemData['identificacion'],
                            'nombre'           => $sItemData['paciente'],
                            'fecha'            => $sItemData['fecha'],
                            'fuente'           => $sItemData['fuente'],
                            'ingreso'          => $sItemData['ingreso'],
                            'cups'             => $sItemData['codigo_examen'] . ' - ' . $sItemData['examen'],
                            'modalidad'        => 'SERVINTE',
                            'estado_actual'    => 'Facturado en Servinte',
                            'sede'             => $sItemData['sede'],
                            'usuario'          => 'Sin Lectura en Proteo',
                            'usuario_medico'   => 'N/A',
                            'tipo_paciente'    => $sItemData['tipo_paciente'] ?? 'E',
                            'servinte'         => $sItemData,
                            'servinte_total_ingreso' => $sItemData['total'],
                            'servinte_items'   => [$sItemData],
                            'proteo_unmatched' => null,
                            'cruce'            => 'SOLO_SERVINTE',
                            'discrepancia'     => 'Facturado en Servinte (Fuente: ' . $sItemData['fuente'] . ', Ingreso: ' . $sItemData['ingreso'] . ', CUPS: ' . $sItemData['codigo_examen'] . ') sin orden ni evento registrado en Proteo',
                            'valor_und_tarifario' => 0.0,
                            'tarifa_base_calculo' => 'VALOR_LIQUIDACION',
                            'pagar_por_cantidad'  => 0,
                            'cantidad'            => (float)($sItemData['cantidad'] ?? 1),
                            'valor_a_pagar'       => 0.0,
                            'es_bloqueo_ho'       => false,
                            'bloqueo_pct'         => 0,
                            'bloqueo_tier_label'  => '',
                            'bloqueo_valor_estudio' => 0,
                            'bloqueo_nota'        => ''
                        ];
                    }
                }

                // Regla de Negocio: Médicos pertenecientes a IMADINSA SAS en Solo Servinte
                $sMedUserUpper = strtoupper(trim((string)($sItem['usuario_medico'] ?? '')));
                $sMedNomUpper  = strtoupper(trim((string)($sItem['usuario'] ?? '')));
                $sMedCedRaw    = preg_replace('/[^0-9]/', '', $sMedUserUpper);

                $docEntIdS = $medicosEntidadMap[$sMedUserUpper] ?? ($medicosEntidadMap[$sMedNomUpper] ?? ($medicosEntidadMap[$sMedCedRaw] ?? null));
                $isDoctorImadinsaS = (!empty($docEntIdS) && isset($imadinsaEntidadIds[(int)$docEntIdS])) || (!empty($entActivaSesion) && isset($imadinsaEntidadIds[(int)$entActivaSesion]));

                if ($isDoctorImadinsaS) {
                    $entidadServinteS = strtoupper(trim((string)($sItemData['entidad'] ?? '')));
                    $esAlivioIntegralS = (stripos($entidadServinteS, 'ALIVIO INTEGRAL') !== false);

                    $sItem['es_medico_imadinsa'] = true;
                    if (!$esAlivioIntegralS) {
                        $sItem['valor_und_tarifario']    = 0.0;
                        $sItem['valor_a_pagar']          = 0.0;
                        $sItem['no_facturable_imadinsa'] = true;
                        $sItem['motivo_no_facturable']   = 'Médico perteneciente a IMADINSA SAS: sólo se factura cuando el registro es de IPS ALIVIO INTEGRAL DEL DOLOR SAS (Entidad del registro: ' . ($entidadServinteS ?: 'Sin Entidad') . ')';
                    } else {
                        $sItem['no_facturable_imadinsa'] = false;
                    }
                } else {
                    $sItem['es_medico_imadinsa']     = false;
                    $sItem['no_facturable_imadinsa'] = false;
                }

                $conceptoRawS       = !empty($sItemData['concepto']) ? strtoupper(trim((string)$sItemData['concepto'])) : '';
                $servicioRawS       = '';
                $cuentaContableRawS = '';
                $nombreCuentaRawS   = '';

                if (!empty($cupsCodeServinte)) {
                    $cleanCodS = preg_replace('/[^A-Z0-9]/', '', $cupsCodeServinte);
                    if (empty($conceptoRawS) && isset($cupsConceptoMap[$cleanCodS]))       $conceptoRawS       = $cupsConceptoMap[$cleanCodS];
                    if (isset($cupsServicioMap[$cleanCodS]))                               $servicioRawS       = $cupsServicioMap[$cleanCodS];
                    if (isset($cupsCuentaContableMap[$cleanCodS]))                         $cuentaContableRawS = $cupsCuentaContableMap[$cleanCodS];
                    if (isset($cupsNombreCuentaMap[$cleanCodS]))                           $nombreCuentaRawS   = $cupsNombreCuentaMap[$cleanCodS];
                }
                if ($esBloqueo && (empty($conceptoRawS) || $conceptoRawS === 'OTROS' || $conceptoRawS === 'RXSI')) {
                    $conceptoRawS = 'BLOQ';
                }
                if (empty($conceptoRawS)) {
                    $cleanCodSNum = preg_replace('/[^0-9]/', '', (string)$cupsCodeServinte);
                    $nomServUpper = strtoupper(trim((string)($sItemData['nombre_examen'] ?? ($sItemData['examen'] ?? ''))));
                    if (strpos($nomServUpper, 'ESPECIAL') !== false) {
                        $conceptoRawS = 'RXES';
                    } elseif (strpos($nomServUpper, 'DOPP') !== false || substr($cleanCodSNum, 0, 3) === '882') {
                        $conceptoRawS = 'DOPP';
                    } elseif (strpos($nomServUpper, 'ECO') !== false || strpos($nomServUpper, 'ULTRASO') !== false || substr($cleanCodSNum, 0, 3) === '881') {
                        $conceptoRawS = 'ECOG';
                    } elseif (strpos($nomServUpper, 'TOMO') !== false || strpos($nomServUpper, 'TAC') !== false || substr($cleanCodSNum, 0, 3) === '879') {
                        $conceptoRawS = 'TOHO';
                    } elseif (strpos($nomServUpper, 'MAMO') !== false || strpos($nomServUpper, 'SENO') !== false || substr($cleanCodSNum, 0, 4) === '8768') {
                        $conceptoRawS = 'MAMO';
                    } elseif (strpos($nomServUpper, 'RAYOS X') !== false || strpos($nomServUpper, 'RADIOGRAF') !== false || strpos($nomServUpper, 'RX') !== false) {
                        $conceptoRawS = 'RXSI';
                    } else {
                        $conceptoRawS = 'OTROS';
                    }
                }

                if (empty($servicioRawS)) {
                    if ($conceptoRawS === 'RXES' || $cuentaContableRawS === '61251002') $servicioRawS = 'RX ESPECIALES';
                    elseif ($conceptoRawS === 'RXSI' || $cuentaContableRawS === '61251001') $servicioRawS = 'RX SIMPLE';
                    elseif ($conceptoRawS === 'ECOG') $servicioRawS = 'ECOGRAFÍAS';
                    elseif ($conceptoRawS === 'TOHO' || $cuentaContableRawS === '61251007') $servicioRawS = 'TOMOGRAFÍAS';
                    elseif ($conceptoRawS === 'MAMO' || $cuentaContableRawS === '61251006') $servicioRawS = 'MAMOGRAFÍAS';
                    elseif ($conceptoRawS === 'DOPP') $servicioRawS = 'DOPPLER';
                    elseif ($conceptoRawS === 'BIOP') $servicioRawS = 'BIOPSIAS';
                    elseif ($conceptoRawS === 'BLOQ') $servicioRawS = 'BLOQUEOS';
                    else $servicioRawS = $conceptosNombresMap[$conceptoRawS] ?? $conceptoRawS;
                }

                $sItem['concepto']        = $conceptoRawS;
                $sItem['concepto_nombre'] = $conceptosNombresMap[$conceptoRawS] ?? $conceptoRawS;
                $sItem['servicio']        = $servicioRawS;
                $sItem['cuenta_contable'] = $cuentaContableRawS;
                $sItem['nombre_cuenta']   = $nombreCuentaRawS;
                $sItem['es_tomografia_contrastada'] = $esTomografiaContrastada($sItem['cups'] ?? '', $sItem['concepto'] ?? '', $cupsCodeServinte, $sItemData['examen'] ?? '');

                // Filtros para registros Solo Servinte
                if (!empty($cruceFiltro)) {
                    if ($cruceFiltro === 'CRUZADO' || $cruceFiltro === 'SOLO_PROTEO' || $cruceFiltro === 'INCOMPLETO_PROTEO') {
                        continue;
                    }
                }

                if (!empty($searchKey)) {
                    $searchLower = mb_strtolower($searchKey);
                    $haystack = mb_strtolower(
                        $sItem['fuente'] . ' ' .
                        $sItem['ingreso'] . ' ' .
                        $sItem['cups'] . ' ' .
                        $sItem['sede'] . ' ' .
                        $sItemData['paciente'] . ' ' .
                        $sItemData['identificacion'] . ' ' .
                        $sItemData['examen'] . ' ' .
                        $sItemData['entidad']
                    );
                    if (strpos($haystack, $searchLower) === false) continue;
                }

                $records[] = $sItem;
            }
        }
    }

    // --- CÁLCULO DE MÉTRICAS, CONTADORES Y TOTALES GLOBALES ---
    $totalExamenes     = count($records);
    $totalCruzados     = 0;
    $totalSoloProteo   = 0;
    $totalSoloServinte = 0;

    $cruzadosValorServinte   = 0;
    $cruzadosValorPagar      = 0;

    $soloProteoValorServinte = 0;
    $soloProteoValorPagar    = 0;

    $soloServinteValorServinte = 0;
    $soloServinteValorPagar    = 0;

    $medicosUnicos   = [];
    $conceptosKpiMap = [];
    $totalTomografiasContrastadas = 0;
    $totalCantidadEstudios        = 0;
    $totalBloqueosHODetectados    = 0;

    foreach ($records as $r) {
        $sRecord   = $r['servinte'] ?? ($r['servinte_unmatched'] ?? null);
        $vServinte = (float)($sRecord['total'] ?? 0);
        $vPagar    = (float)($r['valor_a_pagar'] ?? 0);

        if ($r['cruce'] === 'CRUZADO') {
            $totalCruzados++;
            $cruzadosValorServinte += $vServinte;
            $cruzadosValorPagar    += $vPagar;
        } elseif ($r['cruce'] === 'SOLO_PROTEO') {
            $totalSoloProteo++;
            $soloProteoValorServinte += $vServinte;
            $soloProteoValorPagar    += $vPagar;
        } elseif ($r['cruce'] === 'SOLO_SERVINTE') {
            $totalSoloServinte++;
            $soloServinteValorServinte += $vServinte;
            $soloServinteValorPagar    += $vPagar;
        }

        $cantActual = (int)($r['cantidad'] ?? 1);
        if ($cantActual <= 0) $cantActual = 1;
        $totalCantidadEstudios += $cantActual;

        // Agrupación de registros por Concepto
        $cKey = $r['concepto'] ?? 'OTROS';
        if (!isset($conceptosKpiMap[$cKey])) {
            $conceptosKpiMap[$cKey] = [
                'codigo'       => $cKey,
                'nombre'       => $r['concepto_nombre'] ?? $cKey,
                'cantidad'     => 0,
                'registros'    => 0,
                'valor_pagar'  => 0,
                'valor_examen' => 0
            ];
        }
        $conceptosKpiMap[$cKey]['registros']    += 1;
        $conceptosKpiMap[$cKey]['cantidad']     += $cantActual;
        $conceptosKpiMap[$cKey]['valor_pagar']  += (float)$vPagar;
        $conceptosKpiMap[$cKey]['valor_examen'] += (float)$vServinte;

        // Conteo de Tomografías Contrastadas para Bonificación ($150.000 COP por cada 50)
        if (!empty($r['es_tomografia_contrastada'])) {
            $totalTomografiasContrastadas += (int)($r['cantidad'] ?? 1);
        }

        if (!empty($r['es_bloqueo_ho'])) {
            $totalBloqueosHODetectados++;
        }

        if (!empty($r['usuario']) && $r['usuario'] !== 'Sin Lectura en Proteo') {
            $medicosUnicos[$r['usuario']] = true;
        }
    }

    // Calcular Bonificación por Tomografías Contrastadas (1 bono de $150.000 COP por cada 50 tomografías contrastadas)
    $bonificacionTomoCant      = (int)floor($totalTomografiasContrastadas / 50);
    $bonificacionTomoValor     = $bonificacionTomoCant * 150000;
    $bonificacionTomoRestantes = ($totalTomografiasContrastadas % 50 === 0 && $totalTomografiasContrastadas > 0) 
        ? 50 
        : (50 - ($totalTomografiasContrastadas % 50));

    // Si se generó bonificación, anexarla como concepto oficial en los KPIs
    if ($bonificacionTomoCant > 0) {
        $conceptosKpiMap['BONI_TOHO'] = [
            'codigo'          => 'BONI_TOHO',
            'nombre'          => 'Bonificación Tomografías Contrastadas (Regla 50x$150.000)',
            'cantidad'        => (int)$bonificacionTomoCant,
            'registros'       => 1,
            'valor_pagar'     => (float)$bonificacionTomoValor,
            'valor_examen'    => 0,
            'es_bonificacion' => true
        ];
    }

    // Ordenar conceptos por mayor cantidad de exámenes
    $conceptosKpiList = array_values($conceptosKpiMap);
    usort($conceptosKpiList, function($a, $b) {
        return $b['cantidad'] <=> $a['cantidad'];
    });

    $totalValorServinte = $cruzadosValorServinte + $soloProteoValorServinte + $soloServinteValorServinte;
    $totalValorPagar    = $cruzadosValorPagar + $soloProteoValorPagar + $soloServinteValorPagar + $bonificacionTomoValor;
    $porcentajePagoTotal = ($totalValorServinte > 0) ? round(($totalValorPagar / $totalValorServinte) * 100, 1) : 0;

    // Exportar a Excel
    if ($action === 'export_excel') {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=Reporte_Procedimientos_Medicos_" . date('Y-m-d_H-i') . ".xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8"/>';
        echo '<style>
            body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; color: #1e293b; }
            table { border-collapse: collapse; margin-bottom: 20px; width: 100%; }
            th { background-color: #0f172a; color: #ffffff; padding: 7px 10px; border: 1px solid #94a3b8; font-weight: bold; font-size: 10pt; text-align: left; }
            td { padding: 6px 9px; border: 1px solid #cbd5e1; font-size: 10pt; vertical-align: middle; }
            .th-sub { background-color: #1e293b; color: #ffffff; font-weight: bold; padding: 6px 10px; border: 1px solid #475569; font-size: 10pt; }
            .th-cant { background-color: #047857; color: #ffffff; font-weight: bold; text-align: center; }
            .title-main { font-size: 15pt; font-weight: bold; color: #0f172a; padding: 4px 0; }
            .title-sub { font-size: 10pt; color: #475569; }
            .section-header { font-size: 12pt; font-weight: bold; color: #0f172a; margin-top: 15px; margin-bottom: 6px; }
            .kpi-title { background-color: #0f172a; color: #f8fafc; font-weight: bold; text-align: center; font-size: 9pt; padding: 6px 4px; }
            .kpi-val { background-color: #f8fafc; font-weight: bold; font-size: 13pt; text-align: center; color: #0f172a; padding: 8px 4px; }
            .kpi-sub { background-color: #f1f5f9; font-size: 8pt; text-align: center; color: #64748b; padding: 4px; }
            .kpi-bono-val { background-color: #fefce8; color: #a16207; font-weight: bold; font-size: 12pt; text-align: center; padding: 8px 4px; }
            .total-row { background-color: #e2e8f0; font-weight: bold; border-top: 2px solid #0f172a; }
            .cruzado { background-color: #dcfce7; color: #166534; font-weight: bold; text-align: center; }
            .solo-proteo { background-color: #fee2e2; color: #991b1b; font-weight: bold; text-align: center; }
            .solo-servinte { background-color: #e0f2fe; color: #0369a1; font-weight: bold; text-align: center; }
            .boni-row { background-color: #fef9c3; font-weight: bold; }
            .num { text-align: right; mso-number-format: "\$#,##0"; }
            .cant { text-align: center; font-weight: bold; mso-number-format: "#,##0"; }
            .pct { text-align: right; mso-number-format: "0\.0%"; }
            .center { text-align: center; }
        </style></head>';
        echo '<body>';

        // 1. Encabezado principal
        echo '<table style="border: none; margin-bottom: 12px;">';
        echo '<tr><td colspan="18" class="title-main" style="border: none;">REPORTE DE PROCEDIMIENTOS MÉDICOS (PROTEO VS SERVINTE)</td></tr>';
        echo '<tr><td colspan="18" class="title-sub" style="border: none;"><strong>Entidad / IPS:</strong> ' . htmlspecialchars($entidadActivaNombre) . ' &nbsp;|&nbsp; <strong>Fecha de Generación:</strong> ' . date('d/m/Y H:i:s') . '</td></tr>';
        echo '<tr><td colspan="18" class="title-sub" style="border: none;"><strong>Rango de Fechas:</strong> ' . htmlspecialchars($fechaDesde) . ' al ' . htmlspecialchars($fechaHasta) . ' &nbsp;|&nbsp; <strong>Filtro Médico:</strong> ' . htmlspecialchars(!empty($medicoFiltro) ? $medicoFiltro : 'TODOS LOS MÉDICOS') . ' &nbsp;|&nbsp; <strong>Estado de Cruce:</strong> ' . htmlspecialchars(!empty($cruceFiltro) ? $cruceFiltro : 'TODAS LAS COINCIDENCIAS') . '</td></tr>';
        echo '</table>';

        // 2. Tarjetas / Bloque de Contadores Generales (KPIs)
        echo '<table style="border: 1px solid #cbd5e1; margin-bottom: 18px;">';
        echo '<tr>
                <th class="kpi-title" style="width: 16%;">TOTAL REGISTROS</th>
                <th class="kpi-title" style="width: 16%;">TOTAL CANTIDAD ESTUDIOS</th>
                <th class="kpi-title" style="width: 17%;">MONTO EXAMEN (SERVINTE)</th>
                <th class="kpi-title" style="width: 17%;">MONTO A PAGAR (TARIFARIO)</th>
                <th class="kpi-title" style="width: 14%;">% MONTO A PAGAR</th>
                <th class="kpi-title" style="width: 20%;">BONIFICACIÓN TAC CONTRASTADAS</th>
              </tr>';
        echo '<tr>
                <td class="kpi-val">' . number_format($totalExamenes, 0, ',', '.') . '</td>
                <td class="kpi-val cant" style="color: #047857; font-size: 13pt;">' . number_format($totalCantidadEstudios, 0, ',', '.') . '</td>
                <td class="kpi-val num" style="font-size: 13pt;">$' . number_format($totalValorServinte, 0, ',', '.') . '</td>
                <td class="kpi-val num" style="color: #047857; font-size: 13pt;">$' . number_format($totalValorPagar, 0, ',', '.') . '</td>
                <td class="kpi-val pct" style="font-size: 13pt;">' . $porcentajePagoTotal . '%</td>
                <td class="kpi-bono-val">' . 
                    ($bonificacionTomoCant > 0 
                        ? $bonificacionTomoCant . ' bono(s) (+$' . number_format($bonificacionTomoValor, 0, ',', '.') . ')<br/><span style="font-size: 8pt; font-weight: normal; color: #854d0e;">(' . $totalTomografiasContrastadas . ' Contrastadas acumuladas)</span>'
                        : '0 bonos ($0)<br/><span style="font-size: 8pt; font-weight: normal; color: #64748b;">(' . $totalTomografiasContrastadas . '/50 Contrastadas)</span>'
                    ) . 
                '</td>
              </tr>';
        echo '<tr>
                <td class="kpi-sub">Proteo + Servinte</td>
                <td class="kpi-sub">Unidades Totales Facturadas</td>
                <td class="kpi-sub">Facturado en Servinte</td>
                <td class="kpi-sub">Tarifario LIHO + Bonos</td>
                <td class="kpi-sub">Sobre Facturación Servinte</td>
                <td class="kpi-sub">Regla: Cada 50 TAC Contrastadas = $150.000 COP</td>
              </tr>';
        echo '</table>';

        // 3. Tabla de Resumen por Concepto / Especialidad (Contadores detallados)
        echo '<p class="section-header">RESUMEN POR CONCEPTO / MODALIDAD (CONTADORES Y MONTOS)</p>';
        echo '<table style="border: 1px solid #cbd5e1; margin-bottom: 18px;">';
        echo '<thead><tr>
                <th class="th-sub" style="width: 28%;">Concepto / Modalidad</th>
                <th class="th-sub center" style="width: 10%;">Código</th>
                <th class="th-sub center" style="width: 14%; background-color: #047857;">Cantidad de Estudios</th>
                <th class="th-sub center" style="width: 12%;">N° Registros</th>
                <th class="th-sub" style="width: 18%; text-align: right;">Monto Facturado Servinte</th>
                <th class="th-sub" style="width: 18%; text-align: right;">Monto a Pagar (Tarifario)</th>
              </tr></thead><tbody>';

        foreach ($conceptosKpiList as $cItem) {
            $cCant = (int)($cItem['cantidad'] ?? 0);
            $cRegs = (int)($cItem['registros'] ?? $cCant);
            $cValS = (float)($cItem['valor_examen'] ?? 0);
            $cValP = (float)($cItem['valor_pagar'] ?? 0);
            $isBoni = !empty($cItem['es_bonificacion']);

            echo '<tr class="' . ($isBoni ? 'boni-row' : '') . '">';
            echo '<td><strong>' . htmlspecialchars($cItem['nombre']) . '</strong></td>';
            echo '<td class="center">' . htmlspecialchars($cItem['codigo']) . '</td>';
            echo '<td class="cant">' . number_format($cCant, 0, ',', '.') . '</td>';
            echo '<td class="center">' . number_format($cRegs, 0, ',', '.') . '</td>';
            echo '<td class="num">$' . number_format($cValS, 0, ',', '.') . '</td>';
            echo '<td class="num">$' . number_format($cValP, 0, ',', '.') . '</td>';
            echo '</tr>';
        }

        echo '<tr class="total-row">';
        echo '<td colspan="2"><strong>TOTALES CONSOLIDADOS POR CONCEPTO</strong></td>';
        echo '<td class="cant" style="font-size: 11pt;">' . number_format($totalCantidadEstudios + $bonificacionTomoCant, 0, ',', '.') . '</td>';
        echo '<td class="center" style="font-size: 11pt;">' . number_format($totalExamenes + ($bonificacionTomoCant > 0 ? 1 : 0), 0, ',', '.') . '</td>';
        echo '<td class="num" style="font-size: 11pt;"><strong>$' . number_format($totalValorServinte, 0, ',', '.') . '</strong></td>';
        echo '<td class="num" style="font-size: 11pt; color: #047857;"><strong>$' . number_format($totalValorPagar, 0, ',', '.') . '</strong></td>';
        echo '</tr>';
        echo '</tbody></table>';

        // 4. Tabla de Resumen por Estado de Conciliación
        echo '<p class="section-header">RESUMEN POR ESTADO DE CONCILIACIÓN (CRUCE)</p>';
        echo '<table style="border: 1px solid #cbd5e1; margin-bottom: 22px;">';
        echo '<thead><tr>
                <th class="th-sub" style="width: 30%;">Estado de Cruce</th>
                <th class="th-sub center" style="width: 14%;">N° Registros</th>
                <th class="th-sub center" style="width: 14%;">% Registros</th>
                <th class="th-sub" style="width: 21%; text-align: right;">Monto Facturado Servinte</th>
                <th class="th-sub" style="width: 21%; text-align: right;">Monto a Pagar (Tarifario)</th>
              </tr></thead><tbody>';

        $pctCruz = ($totalExamenes > 0) ? round(($totalCruzados / $totalExamenes) * 100, 1) : 0;
        $pctProt = ($totalExamenes > 0) ? round(($totalSoloProteo / $totalExamenes) * 100, 1) : 0;
        $pctServ = ($totalExamenes > 0) ? round(($totalSoloServinte / $totalExamenes) * 100, 1) : 0;

        echo '<tr>
                <td><span class="cruzado" style="padding: 2px 8px; border-radius: 4px;">CRUZADO (Proteo + Servinte)</span></td>
                <td class="center"><strong>' . number_format($totalCruzados, 0, ',', '.') . '</strong></td>
                <td class="pct">' . $pctCruz . '%</td>
                <td class="num">$' . number_format($cruzadosValorServinte, 0, ',', '.') . '</td>
                <td class="num">$' . number_format($cruzadosValorPagar, 0, ',', '.') . '</td>
              </tr>';
        echo '<tr>
                <td><span class="solo-proteo" style="padding: 2px 8px; border-radius: 4px;">SOLO PROTEO (Sin Servinte)</span></td>
                <td class="center"><strong>' . number_format($totalSoloProteo, 0, ',', '.') . '</strong></td>
                <td class="pct">' . $pctProt . '%</td>
                <td class="num">$' . number_format($soloProteoValorServinte, 0, ',', '.') . '</td>
                <td class="num">$' . number_format($soloProteoValorPagar, 0, ',', '.') . '</td>
              </tr>';
        echo '<tr>
                <td><span class="solo-servinte" style="padding: 2px 8px; border-radius: 4px;">SOLO SERVINTE (Sin Proteo)</span></td>
                <td class="center"><strong>' . number_format($totalSoloServinte, 0, ',', '.') . '</strong></td>
                <td class="pct">' . $pctServ . '%</td>
                <td class="num">$' . number_format($soloServinteValorServinte, 0, ',', '.') . '</td>
                <td class="num">$' . number_format($soloServinteValorPagar, 0, ',', '.') . '</td>
              </tr>';
        echo '<tr class="total-row">
                <td><strong>TOTALES CONCILIACIÓN</strong></td>
                <td class="center"><strong>' . number_format($totalExamenes, 0, ',', '.') . '</strong></td>
                <td class="pct"><strong>100.0%</strong></td>
                <td class="num"><strong>$' . number_format($totalValorServinte, 0, ',', '.') . '</strong></td>
                <td class="num"><strong>$' . number_format($cruzadosValorPagar + $soloProteoValorPagar + $soloServinteValorPagar, 0, ',', '.') . '</strong></td>
              </tr>';
        echo '</tbody></table>';

        // 5. Tabla Detallada con Columna Cantidad
        echo '<p class="section-header">DETALLE COMPLETO DE PROCEDIMIENTOS Y EXÁMENES</p>';
        echo '<table>';
        echo '<thead><tr>
                <th>Origen</th>
                <th>ID Evento / Ref</th>
                <th>Fuente</th>
                <th>Ingreso</th>
                <th>Tipo Paciente</th>
                <th>Fecha</th>
                <th>Sede</th>
                <th>Médico (Proteo)</th>
                <th>CUPS / Examen</th>
                <th class="th-cant">Cantidad</th>
                <th>Modalidad</th>
                <th>Estado Proteo / Servinte</th>
                <th>Estado Cruce (Fuente + Ingreso)</th>
                <th>Paciente (Servinte)</th>
                <th>Identificación</th>
                <th>Entidad (EPS)</th>
                <th style="text-align: right;">Valor del Examen</th>
                <th style="text-align: right;">Valor a Pagar (Tarifario)</th>
              </tr></thead><tbody>';

        foreach ($records as $r) {
            $s = $r['servinte'] ?? ($r['servinte_unmatched'] ?? null);
            $cruceClass = ($r['cruce'] === 'CRUZADO') ? 'cruzado' : (($r['cruce'] === 'SOLO_SERVINTE') ? 'solo-servinte' : 'solo-proteo');
            $tipoPacRaw = strtoupper(trim((string)($r['tipo_paciente'] ?? ($s['tipo_paciente'] ?? 'E'))));
            $tipoPacDisp = ($tipoPacRaw === 'E' || $tipoPacRaw === 'EMPRESA') ? 'Empresa' : (($tipoPacRaw === 'P' || $tipoPacRaw === 'PARTICULAR') ? 'Particular' : $tipoPacRaw);
            $cantItem = (int)($r['cantidad'] ?? 1);
            if ($cantItem <= 0) $cantItem = 1;

            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['origen']) . '</td>';
            echo '<td>' . htmlspecialchars($r['id']) . '</td>';
            echo '<td>' . htmlspecialchars($r['fuente']) . '</td>';
            echo '<td>' . htmlspecialchars($r['ingreso']) . '</td>';
            echo '<td>' . htmlspecialchars($tipoPacDisp) . '</td>';
            echo '<td>' . htmlspecialchars($r['fecha']) . '</td>';
            echo '<td>' . htmlspecialchars($r['sede']) . '</td>';
            echo '<td>' . htmlspecialchars($r['usuario']) . '</td>';
            echo '<td>' . htmlspecialchars($r['cups']) . '</td>';
            echo '<td class="cant">' . $cantItem . '</td>';
            $modDisp = $r['modalidad'];
            if (!empty($r['es_bloqueo_ho'])) {
                $tierTxt = !empty($r['bloqueo_tier_label']) ? $r['bloqueo_tier_label'] . ' - ' : '';
                $modDisp = 'BLOQUEO HO (' . $tierTxt . ($r['bloqueo_pct'] ?? 40) . '%)';
            }
            echo '<td>' . htmlspecialchars($modDisp) . '</td>';
            echo '<td>' . htmlspecialchars($r['estado_actual']) . '</td>';
            echo '<td class="' . $cruceClass . '">' . htmlspecialchars($r['cruce']) . '</td>';
            echo '<td>' . htmlspecialchars($s['paciente'] ?? $r['nombre'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars(($s['tipo_doc'] ?? '') . ' ' . ($s['identificacion'] ?? $r['documento'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars($s['entidad'] ?? 'N/A') . '</td>';
            echo '<td class="num">$' . number_format($s['total'] ?? 0, 0, ',', '.') . '</td>';
            echo '<td class="num">$' . number_format($r['valor_a_pagar'] ?? 0, 0, ',', '.') . '</td>';
            echo '</tr>';
        }

        // Fila para Bonificación de Tomografías si aplica
        if ($bonificacionTomoCant > 0) {
            echo '<tr class="boni-row">';
            echo '<td>LIHO</td>';
            echo '<td>BONI_TOHO</td>';
            echo '<td>LIHO</td>';
            echo '<td>BONIFICACION</td>';
            echo '<td>Incentivo</td>';
            echo '<td>' . date('Y-m-d') . '</td>';
            echo '<td>SEDE PRINCIPAL</td>';
            echo '<td>' . htmlspecialchars($medicoFiltro ?: 'MÉDICO LIHO') . '</td>';
            echo '<td>BONIFICACIÓN TOMOGRAFÍAS CONTRASTADAS (' . $totalTomografiasContrastadas . ' CONTRASTADAS / ' . $bonificacionTomoCant . 'x$150.000 COP)</td>';
            echo '<td class="cant">' . $bonificacionTomoCant . '</td>';
            echo '<td>CT</td>';
            echo '<td>BONIFICACIÓN</td>';
            echo '<td class="cruzado">CRUZADO</td>';
            echo '<td>INCENTIVO POR PRODUCTIVIDAD</td>';
            echo '<td>N/A</td>';
            echo '<td>LIHO IPS</td>';
            echo '<td class="num">$0</td>';
            echo '<td class="num">$' . number_format($bonificacionTomoValor, 0, ',', '.') . '</td>';
            echo '</tr>';
        }

        // Fila final de Totales
        echo '<tr class="total-row">';
        echo '<td colspan="9" style="text-align: right; font-weight: bold; font-size: 11pt;">TOTALES (' . (count($records) + ($bonificacionTomoCant > 0 ? 1 : 0)) . ' REGISTROS):</td>';
        echo '<td class="cant" style="font-size: 11pt; color: #047857;">' . number_format($totalCantidadEstudios + $bonificacionTomoCant, 0, ',', '.') . '</td>';
        echo '<td colspan="6"></td>';
        echo '<td class="num" style="font-size: 11pt;"><strong>$' . number_format($totalValorServinte, 0, ',', '.') . '</strong></td>';
        echo '<td class="num" style="font-size: 11pt; color: #047857;"><strong>$' . number_format($totalValorPagar, 0, ',', '.') . '</strong></td>';
        echo '</tr>';

        echo '</tbody></table></body></html>';
        exit;
    }

    // Respuesta JSON
    header('Content-Type: application/json');

    echo json_encode([
        'success' => empty($errorMsg),
        'error'   => $errorMsg,
        'kpis'    => [
            'total_examenes'                 => $totalExamenes,
            'total_unidades'                 => $totalCantidadEstudios,
            'total_cruzados'                 => $totalCruzados,
            'total_solo_proteo'              => $totalSoloProteo,
            'total_solo_servinte'            => $totalSoloServinte,
            'total_bloqueos_ho'              => $totalBloqueosHODetectados,
            'porcentaje_cruzados'            => $totalExamenes > 0 ? round(($totalCruzados / $totalExamenes) * 100, 1) : 0,

            'total_valor'                    => $totalValorServinte,
            'total_valor_pagar'              => $totalValorPagar,
            'porcentaje_pago_examen'         => $totalValorServinte > 0 ? round(($totalValorPagar / $totalValorServinte) * 100, 1) : 0,

            'cruzados_pagar'                 => $cruzadosValorPagar,
            'solo_proteo_pagar'              => $soloProteoValorPagar,
            'solo_servinte_pagar'            => $soloServinteValorPagar,

            'cruzados_valor'                 => $cruzadosValorServinte,
            'solo_proteo_valor'              => $soloProteoValorServinte,
            'solo_servinte_valor'            => $soloServinteValorServinte,

            'total_tomografias_contrastadas' => $totalTomografiasContrastadas,
            'bonificacion_tomo_cant'         => $bonificacionTomoCant,
            'bonificacion_tomo_valor'        => $bonificacionTomoValor,
            'bonificacion_tomo_restantes'    => $bonificacionTomoRestantes,

            'conceptos'                      => $conceptosKpiList,
            'medicos_activos'                => count($medicosUnicos)
        ],
        'data'    => $records
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------------------------------------------------------
// CARGAR LISTA DE MÉDICOS Y CONFIGURACIÓN DE PARAFISCALES PARA FILTROS
// --------------------------------------------------------------------------
$listaMedicos = [];
$medicosParafiscalesMap = [];
$medicosAfcMap = [];
        $medicosIbcMap = [];
$medicosPensionadosMap = [];
$medicosRetencionesMap = [];
$medicosRetencion383Map = [];
$medicosArlMap = [];
$medicosEntidadMap = [];
$parafiscalesConfigMap = [
    'AFC'     => 40.0,
            'IBC'     => 40.0,
    'SALUD'   => 12.5,
    'PENSION' => 16.0,
    'ARL'     => 2.4360
];

$connLIHOInit = obtenerConexionLIHO();
if ($connLIHOInit !== false) {
    // Tasas del Maestro de Parafiscales
    $sqlParaInit = "SELECT codigo, porcentaje FROM dbo.maestro_parafiscales WHERE ISNULL(estado, 1) = 1";
    $stmtParaInit = sqlsrv_query($connLIHOInit, $sqlParaInit);
    if ($stmtParaInit !== false) {
        while ($rPara = sqlsrv_fetch_array($stmtParaInit, SQLSRV_FETCH_ASSOC)) {
            $cPara = strtoupper(trim((string)($rPara['codigo'] ?? '')));
            if (!empty($cPara)) {
                $parafiscalesConfigMap[$cPara] = (float)($rPara['porcentaje'] ?? 0);
            }
        }
    }

    // Médicos con Parafiscales, IBC, Pensionados, Retenciones, Rete 383, ARL, Modalidad Bloqueos_HO y Entidad Vinculada
    $sqlMedParaInit = "SELECT m.usuario_proteo, m.cedula, u.nombre_completo,
                              ISNULL(m.parafiscales, ISNULL(u.parafiscales, 0)) AS parafiscales,
                              ISNULL(m.afc, ISNULL(u.afc, ISNULL(m.ibc, ISNULL(u.ibc, 0)))) AS afc,
                              ISNULL(m.ibc, ISNULL(u.ibc, ISNULL(m.afc, ISNULL(u.afc, 0)))) AS ibc,
                              ISNULL(m.pensionado, ISNULL(u.pensionado, 0)) AS pensionado,
                              ISNULL(m.retenciones, ISNULL(u.retenciones, 0)) AS retenciones,
                              ISNULL(m.retencion_art_383, ISNULL(u.retencion_art_383, 0)) AS retencion_art_383,
                              ISNULL(m.arl, ISNULL(u.arl, 0)) AS arl,
                              ISNULL(m.modalidades_adicionales, ISNULL(u.modalidades_adicionales, '')) AS modalidades_adicionales,
                              ISNULL(m.entidad_id, ISNULL(u.entidad_id, 0)) AS entidad_id,
                              e.nombre AS entidad_nombre,
                              e.nit AS entidad_nit
                       FROM dbo.medicos m 
                       LEFT JOIN dbo.usuarios u ON m.usuario_id = u.id
                       LEFT JOIN dbo.maestro_entidades e ON ISNULL(m.entidad_id, ISNULL(u.entidad_id, 0)) = e.id";
    $stmtMedParaInit = sqlsrv_query($connLIHOInit, $sqlMedParaInit);
    $medicosBloqueoHOMap = [];
    $medicosBloqueoHOList = [];
    if ($stmtMedParaInit !== false) {
        while ($rME = sqlsrv_fetch_array($stmtMedParaInit, SQLSRV_FETCH_ASSOC)) {
            $pUser = strtoupper(trim((string)($rME['usuario_proteo'] ?? '')));
            $ced   = trim((string)($rME['cedula'] ?? ''));
            $nom   = strtoupper(trim((string)($rME['nombre_completo'] ?? '')));
            $isPara = ((int)($rME['parafiscales'] ?? 0) === 1);
            $isAfc  = ((int)($rME['afc'] ?? 0) === 1 || (int)($rME['ibc'] ?? 0) === 1);
            $isIbc  = $isAfc;
            $isPen  = ((int)($rME['pensionado'] ?? 0) === 1);
            $isRet  = ((int)($rME['retenciones'] ?? 0) === 1);
            $isR383 = ((int)($rME['retencion_art_383'] ?? 0) === 1);
            $isArl  = ((int)($rME['arl'] ?? 0) === 1);

            $docEntId  = (int)($rME['entidad_id'] ?? 0);
            $docEntNom = trim((string)($rME['entidad_nombre'] ?? ''));
            $docEntNit = trim((string)($rME['entidad_nit'] ?? ''));
            if ($docEntId > 0 && !empty($docEntNom)) {
                $docEntArr = ['id' => $docEntId, 'nombre' => $docEntNom, 'nit' => $docEntNit];
                if (!empty($pUser)) $medicosEntidadMap[$pUser] = $docEntArr;
                if (!empty($ced))   $medicosEntidadMap[$ced] = $docEntArr;
                if (!empty($nom))   $medicosEntidadMap[$nom] = $docEntArr;
            }

            $modsStr = strtoupper(trim((string)($rME['modalidades_adicionales'] ?? '')));
            $modsArr = !empty($modsStr) ? array_filter(array_map('trim', explode(',', $modsStr))) : [];
            $hasBloqueoHO = (in_array('BLOQUEOS_HO', $modsArr) || in_array('BLOQUEO_HO', $modsArr));

            if ($isPara) {
                if (!empty($pUser)) $medicosParafiscalesMap[$pUser] = true;
                if (!empty($ced))   $medicosParafiscalesMap[$ced] = true;
                if (!empty($nom))   $medicosParafiscalesMap[$nom] = true;
            }
            if ($isAfc || $isIbc) {
                if (!empty($pUser)) { $medicosAfcMap[$pUser] = true; $medicosIbcMap[$pUser] = true; }
                if (!empty($ced))   { $medicosAfcMap[$ced] = true; $medicosIbcMap[$ced] = true; }
                if (!empty($nom))   { $medicosAfcMap[$nom] = true; $medicosIbcMap[$nom] = true; }
            }
            if ($isPen) {
                if (!empty($pUser)) $medicosPensionadosMap[$pUser] = true;
                if (!empty($ced))   $medicosPensionadosMap[$ced] = true;
                if (!empty($nom))   $medicosPensionadosMap[$nom] = true;
            }
            if ($isRet) {
                if (!empty($pUser)) $medicosRetencionesMap[$pUser] = true;
                if (!empty($ced))   $medicosRetencionesMap[$ced] = true;
                if (!empty($nom))   $medicosRetencionesMap[$nom] = true;
            }
            if ($isR383) {
                if (!empty($pUser)) $medicosRetencion383Map[$pUser] = true;
                if (!empty($ced))   $medicosRetencion383Map[$ced] = true;
                if (!empty($nom))   $medicosRetencion383Map[$nom] = true;
            }
            if ($isArl) {
                if (!empty($pUser)) $medicosArlMap[$pUser] = true;
                if (!empty($ced))   $medicosArlMap[$ced] = true;
                if (!empty($nom))   $medicosArlMap[$nom] = true;
            }

            if ($hasBloqueoHO) {
                if (!empty($pUser)) $medicosBloqueoHOMap[$pUser] = true;
                if (!empty($ced))   $medicosBloqueoHOMap[$ced] = true;
                if (!empty($nom))   $medicosBloqueoHOMap[$nom] = true;

                if (!empty($pUser)) {
                    $medicosBloqueoHOList[$pUser] = [
                        'username' => $rME['usuario_proteo'],
                        'nombre'   => !empty($rME['nombre_completo']) ? trim($rME['nombre_completo']) : $rME['usuario_proteo']
                    ];
                }
            }
        }
    }
}

$connProteoInit = obtenerConexionProteo();
if ($connProteoInit !== false) {
    $sqlMedicos = "SELECT DISTINCT U.UserName, U.Name, U.Surname 
                   FROM dbo.AbpUsers U WITH (NOLOCK)
                   INNER JOIN dbo.AppEventStatusesInEvents AEI WITH (NOLOCK) ON AEI.CreatorUserId = U.Id
                   WHERE AEI.EventStatusName = 'Enviado a Enfermeria'
                   ORDER BY U.Name, U.Surname";
    $stmtMInit = sqlsrv_query($connProteoInit, $sqlMedicos);
    if ($stmtMInit !== false) {
        while ($rowM = sqlsrv_fetch_array($stmtMInit, SQLSRV_FETCH_ASSOC)) {
            $uName = strtoupper(trim((string)($rowM['UserName'] ?? '')));
            $nombreCompleto = trim(($rowM['Name'] ?? '') . ' ' . ($rowM['Surname'] ?? ''));
            $nUpper = strtoupper($nombreCompleto);

            // SOLO médicos que tengan habilitada la modalidad Bloqueos_HO
            if (empty($medicosBloqueoHOMap[$uName]) && empty($medicosBloqueoHOMap[$nUpper])) {
                continue;
            }

            if (!empty($nombreCompleto)) {
                $listaMedicos[$uName] = [
                    'username' => $rowM['UserName'],
                    'nombre'   => $nombreCompleto
                ];
            }
        }
    }
}

// Asegurar que cualquier médico con Bloqueos_HO en LIHO figure en la lista
foreach ($medicosBloqueoHOList as $uKey => $mLiho) {
    if (!isset($listaMedicos[$uKey])) {
        $listaMedicos[$uKey] = $mLiho;
    }
}
$listaMedicos = array_values($listaMedicos);
usort($listaMedicos, function($a, $b) {
    return strcmp($a['nombre'], $b['nombre']);
});
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión Médicos Procedimientos (Proteo vs Servinte) - LIHO IPS</title>

    <!-- Logo Favicon -->
    <link rel="shortcut icon" href="assets/img/hologo.png">
    <link rel="icon" type="image/png" href="assets/img/hologo.png">

    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#14354E',
                        secondary: '#1E4A6D',
                        tertiary: '#00C1BE',
                        accent: '#00A8A5',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- SheetJS (Excel) -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        /* Estilos personalizados para animaciones y scrollbars */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
        
        .tab-btn.active {
            color: #00C1BE;
            border-bottom: 2px solid #00C1BE;
        }
        
        /* Efectos de pulso y loader */
        @keyframes pulse-subtle {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .animate-pulse-subtle { animation: pulse-subtle 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        
        /* Custom Premium Checkbox */
        .custom-chk {
            appearance: none;
            -webkit-appearance: none;
            width: 1.3rem;
            height: 1.3rem;
            border: 2px solid #94a3b8;
            border-radius: 0.5rem;
            background-color: rgba(255, 255, 255, 0.05);
            display: inline-grid;
            place-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            vertical-align: middle;
        }

        .dark .custom-chk {
            border-color: #475569;
            background-color: rgba(15, 23, 42, 0.6);
        }

        .custom-chk:hover {
            border-color: #0d9488 !important;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.25);
            transform: scale(1.08);
        }

        .custom-chk:checked {
            background: linear-gradient(135deg, #0d9488 0%, #0284c7 100%) !important;
            border-color: transparent !important;
            box-shadow: 0 2px 8px rgba(13, 148, 136, 0.4);
            transform: scale(1.05);
        }

        .custom-chk:checked::before {
            content: "";
            width: 0.38rem;
            height: 0.72rem;
            border: solid white;
            border-width: 0 2.4px 2.4px 0;
            transform: rotate(45deg) translate(-1px, -1px);
        }

        .custom-chk:indeterminate {
            background: linear-gradient(135deg, #0d9488 0%, #0284c7 100%) !important;
            border-color: transparent !important;
            box-shadow: 0 2px 8px rgba(13, 148, 136, 0.4);
        }

        .custom-chk:indeterminate::before {
            content: "";
            width: 0.65rem;
            height: 2.4px;
            background-color: white;
            border-radius: 1px;
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans min-h-screen flex flex-col antialiased transition-colors duration-200">

    <!-- Navbar Global Unificado -->
    <?php include_once __DIR__ . '/includes/navbar.php'; ?>

    <!-- Main Container -->
    <main class="flex-1 w-full max-w-[1700px] mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- Header / Banner Section -->
        <div class="relative overflow-hidden bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
            <div class="absolute top-0 right-0 -mt-10 -mr-10 w-48 h-48 bg-gradient-to-br from-tertiary/10 to-accent/10 rounded-full blur-3xl pointer-events-none"></div>
            
            <div class="flex items-center gap-4 z-10">
                <div class="p-3.5 rounded-2xl bg-gradient-to-tr from-primary via-slate-800 to-tertiary text-white shadow-md shadow-tertiary/20 shrink-0">
                    <span class="material-symbols-outlined text-3xl">vital_signs</span>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-black font-outfit text-slate-900 dark:text-white tracking-tight">
                        Gestión Médicos Procedimientos
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Conciliación completa de procedimientos médicos (Enviado a Enfermería) entre <span class="font-bold text-sky-600 dark:text-sky-400">PROTEO (SQL Server)</span> y <span class="font-bold text-teal-600 dark:text-teal-400">SERVINTE (Oracle)</span>.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 z-10 self-start md:self-auto">
                <?php if ($canGenerateLiquidation): ?>
                    <button type="button" id="btnGenerarLiquidacion" onclick="notificarLiquidacionPorSeleccion()" disabled
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs border border-slate-200 dark:border-slate-700/60 shadow-none cursor-not-allowed opacity-50 transition-all duration-200"
                        title="Liquidación global inactiva: Utilice el botón 'Liquidar Selección' en la barra inferior tras revisar cada registro">
                        <span class="material-symbols-outlined text-lg">receipt_long</span>
                        <span>Generar liquidación</span>
                        <span class="text-[9px] bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 px-1.5 py-0.5 rounded font-bold uppercase tracking-wider">Inactivo</span>
                    </button>
                <?php endif; ?>
                <button type="button" id="btnExportExcel" onclick="exportarExcel()" disabled
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs shadow-none cursor-not-allowed opacity-60 transition-all duration-200"
                    title="Realice una consulta primero para exportar">
                    <span class="material-symbols-outlined text-lg">download_for_offline</span>
                    <span>Exportar Excel</span>
                </button>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-tertiary text-xl">tune</span>
                    <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider">Filtros de Búsqueda</h2>
                </div>
                <button type="button" onclick="limpiarFiltros()" class="text-xs font-semibold text-slate-400 hover:text-rose-500 transition-colors flex items-center gap-1 cursor-pointer">
                    <span class="material-symbols-outlined text-sm">restart_alt</span>
                    <span>Restablecer</span>
                </button>
            </div>

            <form id="filterForm" onsubmit="event.preventDefault(); aplicarFiltros(event); return false;" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                
                <!-- Fecha Desde -->
                <div class="space-y-1">
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Fecha Desde</label>
                    <input type="date" id="fecha_desde" name="fecha_desde" 
                        value="<?php echo date('Y-m-d'); ?>"
                        class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary/40 focus:outline-none font-medium">
                </div>

                <!-- Fecha Hasta -->
                <div class="space-y-1">
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Fecha Hasta</label>
                    <input type="date" id="fecha_hasta" name="fecha_hasta" 
                        value="<?php echo date('Y-m-d'); ?>"
                        class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary/40 focus:outline-none font-medium">
                </div>

                <!-- Selector de Médico con Buscador -->
                <div class="space-y-1 relative z-30" id="medicoSelectContainer">
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Médico</label>
                    <input type="hidden" id="medico" name="medico" value="" />
                    
                    <!-- Botón Trigger Selector -->
                    <button type="button" id="btnMedicoTrigger"
                        class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary/40 focus:outline-none font-medium flex items-center justify-between transition-all cursor-pointer hover:border-tertiary/60">
                        <span id="selectedMedicoLabel" class="truncate font-semibold text-slate-700 dark:text-slate-200">-- Todos los Médicos --</span>
                        <span id="chevronMedicoIcon" class="material-symbols-outlined text-base text-slate-400 shrink-0 ml-1 transition-transform duration-200">unfold_more</span>
                    </button>

                    <!-- Dropdown Desplegable con Buscador -->
                    <div id="dropdownMedicoMenu"
                        class="hidden absolute left-0 sm:-left-2 w-full sm:w-[380px] max-w-[420px] top-full mt-1.5 z-[9999] bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden p-2.5 space-y-2 ring-1 ring-black/10 dark:ring-white/10">
                        <!-- Campo de búsqueda -->
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none">search</span>
                            <input type="text" id="searchMedicoInput" placeholder="Escribe para buscar médico..." 
                                autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-gramm="false" data-enable-grammarly="false" data-lpignore="true"
                                class="w-full pl-8 pr-3 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 transition-all outline-none" />
                        </div>

                        <!-- Lista Opciones Scrollable -->
                        <ul id="listaMedicosOptions" class="max-h-60 overflow-y-auto space-y-1 text-xs text-slate-700 dark:text-slate-200 font-medium pr-1 custom-scrollbar">
                            <li>
                                <button type="button" data-value="" data-label="-- Todos los Médicos --"
                                    class="medico-option-btn w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors font-bold text-tertiary flex items-center justify-between bg-slate-100 dark:bg-slate-800">
                                    <span>-- Todos los Médicos --</span>
                                    <span class="material-symbols-outlined text-sm check-icon">check</span>
                                </button>
                            </li>
                            <?php foreach ($listaMedicos as $m): ?>
                                <?php 
                                    $uUpper  = strtoupper(trim((string)($m['username'] ?? '')));
                                    $nUpper  = strtoupper(trim((string)($m['nombre'] ?? '')));
                                    $hasPara = (!empty($medicosParafiscalesMap[$uUpper]) || !empty($medicosParafiscalesMap[$nUpper]));
                                    $hasAfc  = (!empty($medicosAfcMap[$uUpper]) || !empty($medicosAfcMap[$nUpper]) || !empty($medicosIbcMap[$uUpper]) || !empty($medicosIbcMap[$nUpper]));
                                    $hasIbc  = $hasAfc;
                                    $hasPen  = (!empty($medicosPensionadosMap[$uUpper]) || !empty($medicosPensionadosMap[$nUpper]));
                                    $hasRet  = (!empty($medicosRetencionesMap[$uUpper]) || !empty($medicosRetencionesMap[$nUpper]));
                                    $hasR383 = (!empty($medicosRetencion383Map[$uUpper]) || !empty($medicosRetencion383Map[$nUpper]));
                                    $hasArl  = (!empty($medicosArlMap[$uUpper]) || !empty($medicosArlMap[$nUpper]));
                                    $docEnt  = $medicosEntidadMap[$uUpper] ?? ($medicosEntidadMap[$nUpper] ?? null);
                                    $hasDocEnt = (!empty($docEnt['nombre']) && $docEnt['nombre'] !== 'HERNÁN OCAZIONEZ Y CÍA S.A.S.');
                                ?>
                                <li>
                                    <button type="button" data-value="<?php echo htmlspecialchars($m['username']); ?>" data-label="<?php echo htmlspecialchars($m['nombre']); ?>" data-parafiscales="<?php echo $hasPara ? '1' : '0'; ?>" data-afc="<?php echo $hasAfc ? '1' : '0'; ?>" data-ibc="<?php echo $hasAfc ? '1' : '0'; ?>" data-pensionado="<?php echo $hasPen ? '1' : '0'; ?>" data-retenciones="<?php echo $hasRet ? '1' : '0'; ?>" data-retencion-383="<?php echo $hasR383 ? '1' : '0'; ?>" data-arl="<?php echo $hasArl ? '1' : '0'; ?>" data-entidad-id="<?php echo htmlspecialchars($docEnt['id'] ?? ''); ?>" data-entidad-nombre="<?php echo htmlspecialchars($docEnt['nombre'] ?? ''); ?>" data-entidad-nit="<?php echo htmlspecialchars($docEnt['nit'] ?? ''); ?>"
                                        class="medico-option-btn w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors flex items-center justify-between group">
                                        <div class="flex flex-col min-w-0 pr-2">
                                            <span class="truncate font-semibold text-slate-800 dark:text-slate-100 group-hover:text-primary dark:group-hover:text-white"><?php echo htmlspecialchars($m['nombre']); ?></span>
                                            <span class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($m['username']); ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0 flex-wrap justify-end">
                                            <?php if ($hasDocEnt): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-teal-50 dark:bg-teal-950/80 text-teal-700 dark:text-teal-300 text-[9px] font-extrabold uppercase border border-teal-200 dark:border-teal-800" title="Entidad vinculada"><i class="fa-solid fa-hospital text-[8px] mr-1"></i><?php echo htmlspecialchars($docEnt['nombre']); ?></span>
                                            <?php endif; ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 text-[9px] font-extrabold uppercase">Bloqueos HO</span>
                                            <?php if ($hasPara): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-950/80 text-teal-800 dark:text-teal-300 text-[9px] font-extrabold uppercase">Parafiscales</span>
                                            <?php endif; ?>
                                            <?php if ($hasAfc || $hasIbc): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-950/80 text-teal-800 dark:text-teal-300 text-[9px] font-extrabold uppercase">AFC</span>
                                            <?php endif; ?>
                                            <?php if ($hasPen): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-indigo-100 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-300 text-[9px] font-extrabold uppercase">Pensionado</span>
                                            <?php endif; ?>
                                            <?php if ($hasArl): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 text-[9px] font-extrabold uppercase">ARL</span>
                                            <?php endif; ?>
                                            <?php if ($hasRet): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-rose-100 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300 text-[9px] font-extrabold uppercase">Retenciones</span>
                                            <?php endif; ?>
                                            <?php if ($hasR383): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-cyan-100 dark:bg-cyan-950/80 text-cyan-800 dark:text-cyan-300 text-[9px] font-extrabold uppercase">Rete 383</span>
                                            <?php endif; ?>
                                            <span class="material-symbols-outlined text-sm check-icon hidden text-tertiary">check</span>
                                        </div>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <div id="noMedicosFound" class="hidden text-center py-4 text-xs text-slate-400 font-medium">
                            No se encontraron médicos coincidentes.
                        </div>
                    </div>
                </div>

                <!-- Estado del Cruce -->
                <div class="space-y-1">
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Estado Cruce</label>
                    <select id="cruce" name="cruce" class="w-full px-3 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary/40 focus:outline-none font-medium">
                        <option value="">Todas las Coincidencias</option>
                        <option value="CRUZADO">Cruzados OK (En Proteo y Servinte)</option>
                        <option value="SOLO_PROTEO">Solo en Proteo (Sin Factura Servinte)</option>
                        <option value="SOLO_SERVINTE">Solo en Servinte (Sin Evento Proteo)</option>
                        <option value="INCOMPLETO_PROTEO">Sin Fuente o Ingreso en Proteo</option>
                    </select>
                </div>

                <!-- Botón Buscar -->
                <div class="flex items-end">
                    <button type="button" onclick="aplicarFiltros(event)" id="btnFiltrar" 
                        class="w-full py-2 px-4 rounded-xl bg-primary hover:bg-slate-800 dark:bg-tertiary dark:hover:bg-teal-700 text-white font-bold text-xs shadow-md transition-all duration-200 flex items-center justify-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-base">search</span>
                        <span>Consultar</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- KPI Cards Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-3.5" id="kpiCardsGrid">
            
            <!-- Card 1: Total Registros -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group">
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Registros</p>
                    <h3 id="kpiTotalExamenes" class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                    <p class="text-[10px] text-slate-400 font-medium" id="kpiSubTotalExamenes">Proteo + Servinte</p>
                </div>
                <div class="p-2.5 rounded-2xl bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 group-hover:scale-110 transition-transform duration-300">
                    <span class="material-symbols-outlined text-2xl">assignment_turned_in</span>
                </div>
            </div>

            <!-- Contenedor Dinámico de Tarjetas por Concepto (Reemplaza tarjetas de cruce) -->
            <div id="kpiConceptosContainer" class="contents">
                <!-- Tarjeta Inicial: RX Simples (RXSI) -->
                <div class="kpi-concepto-card bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-teal-500/50 transition-all cursor-pointer" onclick="filtrarPorConcepto('RXSI')">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase text-teal-600 dark:text-teal-400 tracking-wider">RX Simples (RXSI)</p>
                        <h3 id="kpiConcepto_RXSI" class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                        <p class="text-[10px] text-teal-600 dark:text-teal-400 font-semibold" id="kpiConceptoSub_RXSI">$0 a pagar</p>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-teal-50 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400 group-hover:scale-110 transition-transform duration-300">
                        <span class="material-symbols-outlined text-2xl">radiology</span>
                    </div>
                </div>

                <!-- Tarjeta Inicial: Ecografías (ECOG) -->
                <div class="kpi-concepto-card bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-emerald-500/50 transition-all cursor-pointer" onclick="filtrarPorConcepto('ECOG')">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase text-emerald-600 dark:text-emerald-400 tracking-wider">Ecografías (ECOG)</p>
                        <h3 id="kpiConcepto_ECOG" class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                        <p class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold" id="kpiConceptoSub_ECOG">$0 a pagar</p>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform duration-300">
                        <span class="material-symbols-outlined text-2xl">vital_signs</span>
                    </div>
                </div>

                <!-- Tarjeta Inicial: Doppler (DOPP) -->
                <div class="kpi-concepto-card bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-sky-500/50 transition-all cursor-pointer" onclick="filtrarPorConcepto('DOPP')">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase text-sky-600 dark:text-sky-400 tracking-wider">Doppler (DOPP)</p>
                        <h3 id="kpiConcepto_DOPP" class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                        <p class="text-[10px] text-sky-600 dark:text-sky-400 font-semibold" id="kpiConceptoSub_DOPP">$0 a pagar</p>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-sky-50 dark:bg-sky-900/30 text-sky-600 dark:text-sky-400 group-hover:scale-110 transition-transform duration-300">
                        <span class="material-symbols-outlined text-2xl">water_drop</span>
                    </div>
                </div>
            </div>

            <!-- Card 5: Valor Examen -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group">
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Monto Examen</p>
                    <h3 id="kpiValorTotal" class="text-xl font-black font-outfit text-slate-900 dark:text-white">$0</h3>
                    <p class="text-[10px] text-slate-400 font-medium">Facturado Servinte</p>
                </div>
                <div class="p-2.5 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 group-hover:scale-110 transition-transform duration-300">
                    <span class="material-symbols-outlined text-2xl">receipt_long</span>
                </div>
            </div>

            <!-- Card 6: Valor a Pagar (Tarifario) -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group">
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Monto a Pagar</p>
                    <h3 id="kpiValorPagar" class="text-xl font-black font-outfit text-emerald-600 dark:text-emerald-400">$0</h3>
                    <p class="text-[10px] text-slate-400 font-medium" id="kpiSubValorPagar">Tarifario LIHO</p>
                </div>
                <div class="p-2.5 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform duration-300">
                    <span class="material-symbols-outlined text-2xl">payments</span>
                </div>
            </div>

            <!-- Card 7: % Monto a Pagar sobre Monto Examen -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-3xl border border-purple-200/60 dark:border-purple-900/40 shadow-sm flex items-center justify-between relative overflow-hidden group">
                <div class="space-y-1">
                    <p class="text-[10px] font-bold uppercase text-purple-600 dark:text-purple-400 tracking-wider">% Monto a Pagar</p>
                    <h3 id="kpiPorcentajePagar" class="text-xl font-black font-outfit text-purple-700 dark:text-purple-300">0.0%</h3>
                    <p class="text-[10px] text-slate-400 font-medium">Del Monto Examen</p>
                </div>
                <div class="p-2.5 rounded-2xl bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 group-hover:scale-110 transition-transform duration-300">
                    <span class="material-symbols-outlined text-2xl">percent</span>
                </div>
            </div>

        </div>

        <!-- Banner Informativo de Condiciones de Liquidación BLOQUEOS_HO -->
        <div id="bannerCondicionesBloqueo" class="hidden mb-4 p-4 rounded-3xl bg-gradient-to-r from-amber-500/15 via-amber-500/10 to-teal-500/10 border border-amber-500/30 dark:border-amber-600/40 shadow-sm transition-all duration-300">
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-3 text-slate-800 dark:text-slate-100">
                <div class="flex items-start md:items-center gap-3">
                    <div class="p-3 rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400 shrink-0 shadow-inner">
                        <span class="material-symbols-outlined text-2xl">syringe</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-black text-xs uppercase tracking-wider text-amber-800 dark:text-amber-300 bg-amber-200/80 dark:bg-amber-950/90 px-2.5 py-0.5 rounded-lg border border-amber-300 dark:border-amber-700 shadow-xs">Modalidad Especial: BLOQUEO HO</span>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300">Esquema de porcentajes de pago por cantidad para estudios de Bloqueo:</span>
                        </div>
                        <div class="flex items-center gap-3 md:gap-5 mt-2 flex-wrap text-xs">
                            <span class="inline-flex items-center gap-1.5 font-bold">
                                <span class="px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 font-mono font-extrabold border border-emerald-300 dark:border-emerald-800">Cantidad 1</span>
                                <strong class="text-emerald-700 dark:text-emerald-400 font-black">40%</strong>
                                <span class="text-slate-500 dark:text-slate-400 text-[11px] font-normal">del valor total estudio</span>
                            </span>
                            <span class="text-slate-300 dark:text-slate-700 hidden md:inline">•</span>
                            <span class="inline-flex items-center gap-1.5 font-bold">
                                <span class="px-2 py-0.5 rounded-md bg-sky-100 dark:bg-sky-950 text-sky-800 dark:text-sky-300 font-mono font-extrabold border border-sky-300 dark:border-sky-800">Cantidad 2</span>
                                <strong class="text-sky-700 dark:text-sky-400 font-black">al 70%</strong>
                            </span>
                            <span class="text-slate-300 dark:text-slate-700 hidden md:inline">•</span>
                            <span class="inline-flex items-center gap-1.5 font-bold">
                                <span class="px-2 py-0.5 rounded-md bg-purple-100 dark:bg-purple-950 text-purple-800 dark:text-purple-300 font-mono font-extrabold border border-purple-300 dark:border-purple-800">Cantidad 3</span>
                                <strong class="text-purple-700 dark:text-purple-400 font-black">al 60%</strong>
                            </span>
                        </div>
                        <p class="text-[11px] text-amber-800 dark:text-amber-300 font-semibold italic mt-2 flex items-center gap-1.5 flex-wrap">
                            <span class="material-symbols-outlined text-xs shrink-0 text-amber-600 dark:text-amber-400">info</span>
                            <span>(Estas condiciones están sujetas a lo parametrizado en el Tarifario Bloqueos HO)</span>
                            <a href="tarifario_bloqueos.php" target="_blank" class="inline-flex items-center gap-1 font-bold text-amber-900 dark:text-amber-200 underline hover:text-amber-700 dark:hover:text-white transition-colors ml-1">
                                <span class="material-symbols-outlined text-xs">open_in_new</span>
                                <span>Ver Tarifario Bloqueos HO</span>
                            </a>
                        </p>
                    </div>
                </div>
                <div class="shrink-0 flex items-center gap-2 self-stretch md:self-auto justify-end">
                    <span id="badgeBloqueosDetectados" class="px-3.5 py-2 rounded-2xl bg-amber-500/20 text-amber-800 dark:text-amber-200 text-xs font-black border border-amber-500/30 flex items-center gap-1.5 shadow-xs">
                        <span class="material-symbols-outlined text-sm">checklist</span>
                        <span id="lblBloqueosDetectados">0 Bloqueos liquidados</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- View Tabs Bar -->
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-2">
            <button type="button" id="tabAll" onclick="filtrarPestaña('')" 
                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-primary text-white shadow-sm">
                <span class="material-symbols-outlined text-base">list_alt</span>
                <span>Todos (<span id="cntAll">0</span>)</span>
            </button>
            
            <button type="button" id="tabOK" onclick="filtrarPestaña('CRUZADO')" 
                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-base text-emerald-500">check_circle</span>
                <span>Cruzados OK (<span id="cntOK">0</span>)</span>
            </button>

            <button type="button" id="tabProteo" onclick="filtrarPestaña('SOLO_PROTEO')" 
                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-base text-rose-500">cancel</span>
                <span>Solo en Proteo (<span id="cntProteo">0</span>)</span>
            </button>

            <button type="button" id="tabServinte" onclick="filtrarPestaña('SOLO_SERVINTE')" 
                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-base text-sky-500">local_hospital</span>
                <span>Solo en Servinte (<span id="cntServinte">0</span>)</span>
            </button>

            <button type="button" id="tabExcluidos" onclick="filtrarPestaña('EXCLUIDOS')" 
                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-base text-amber-500">do_not_disturb_on</span>
                <span>Excluidos (<span id="cntExcluidos">0</span>)</span>
            </button>

            <button type="button" id="tabNoFacturables" onclick="filtrarPestaña('NO_FACTURABLES')" 
                class="hidden px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800">
                <span class="material-symbols-outlined text-base text-rose-500">block</span>
                <span>No Facturables (<span id="cntNoFacturables">0</span>)</span>
            </button>
        </div>

        <!-- Table Data Section -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col">
            
            <!-- Table Action Header -->
            <div class="p-4 sm:p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/50">
                <div class="flex items-center gap-3">
                    <div class="relative w-full sm:w-96">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-base pointer-events-none">search</span>
                        <input type="text" id="tableSearch" oninput="filtrarTablaEnMemoria()" 
                            autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                            placeholder="Buscar por paciente, cédula, examen, código, médico, sede..." 
                            class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:ring-2 focus:ring-tertiary/40 focus:outline-none font-medium">
                    </div>
                </div>
                
                <div class="flex items-center gap-2 self-end sm:self-auto text-xs text-slate-500 dark:text-slate-400 font-medium">
                    <div id="activeFilterBadgeContainer" class="hidden"></div>
                    <span>Mostrando <strong id="lblMostrandoCount" class="text-slate-900 dark:text-white">0</strong> registros</span>
                </div>
            </div>

            <!-- Table Container -->
            <div class="overflow-x-auto min-h-[350px] relative">
                
                <!-- Spinner Overlay -->
                <div id="tableLoading" class="hidden absolute inset-0 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xs flex flex-col items-center justify-center z-20 transition-opacity duration-300">
                    <div class="animate-spin rounded-full h-10 w-10 border-4 border-slate-200 border-t-tertiary mb-3"></div>
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300 animate-pulse">Realizando conciliación bidireccional entre PROTEO y SERVINTE...</p>
                </div>

                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100/70 dark:bg-slate-800/70 text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200/80 dark:border-slate-800">
                            <th class="py-3.5 px-3 text-center w-12">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAllPage(this.checked)" class="custom-chk" title="Seleccionar / Deseleccionar página actual" />
                            </th>
                            <th class="py-3 px-4">Fuente / Ingreso</th>
                            <th class="py-3 px-4 text-center">Tipo Pac.</th>
                            <th class="py-3 px-4">Fecha / Sede</th>
                            <th class="py-3 px-4">Médico (Proteo)</th>
                            <th class="py-3 px-4">Paciente</th>
                            <th class="py-3 px-4 min-w-[220px]">Examen / CUPS</th>
                            <th class="py-3 px-4 text-center">Cant.</th>
                            <th class="py-3 px-4 text-center">Estado Cruce</th>
                            <th class="py-3 px-4 text-right">Valor del Examen</th>
                            <th class="py-3 px-4 text-right">Valor a Pagar</th>
                            <th class="py-3 px-4 text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody" class="divide-y divide-slate-100 dark:divide-slate-800/80 text-xs">
                        <!-- Renderizado dinámico vía JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Table Footer Pagination -->
            <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-50/50 dark:bg-slate-900/50 text-xs">
                <div class="text-slate-500 dark:text-slate-400 font-medium">
                    Página <span id="lblPaginaActual" class="font-bold text-slate-800 dark:text-slate-200">1</span> de <span id="lblTotalPaginas" class="font-bold text-slate-800 dark:text-slate-200">1</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="btnPagPrev" onclick="cambiarPagina(-1)" class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                        Anterior
                    </button>
                    <button type="button" id="btnPagNext" onclick="cambiarPagina(1)" class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                        Siguiente
                    </button>
                </div>
            </div>

        </div>

    </main>

    <!-- Floating Selection Action Bar (Fixed at Bottom / Draggable) -->
    <div id="floatingSelectionBar" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 w-[95%] max-w-6xl bg-slate-900/95 dark:bg-slate-900/95 backdrop-blur-md text-white rounded-3xl p-4 sm:p-5 shadow-2xl border border-slate-700/80 transition-all duration-300 transform translate-y-32 opacity-0 pointer-events-none">
        
        <!-- Header para Arrastrar Barra / Drag Handle -->
        <div class="w-full flex items-center justify-between pb-2 mb-2 border-b border-slate-800/80 cursor-grab active:cursor-grabbing select-none text-[11px] text-slate-400 font-bold" id="dragHeaderFloatingBar">
            <span class="flex items-center gap-1.5 text-slate-400 hover:text-slate-200 transition-colors">
                <span class="material-symbols-outlined text-base">drag_indicator</span>
                <span>Mantén presionado para arrastrar este panel</span>
            </span>
            <button type="button" onclick="event.stopPropagation(); resetearPosicionFloatingBar()" 
                class="inline-flex items-center gap-1 text-[10px] text-slate-400 hover:text-white px-2 py-0.5 rounded-lg hover:bg-slate-800 border border-slate-800 hover:border-slate-700 transition-all cursor-pointer" 
                title="Volver a fijar en la parte inferior original">
                <span class="material-symbols-outlined text-xs">restart_alt</span>
                <span>Restablecer posición</span>
            </button>
        </div>

        <div class="flex flex-col xl:flex-row items-center justify-between gap-4">
            
            <!-- Left: Info + Live Metrics -->
            <div class="flex flex-wrap items-center gap-3 w-full xl:w-auto justify-between sm:justify-start">
                <div class="p-2.5 rounded-2xl bg-tertiary/20 text-tertiary border border-tertiary/30 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl">checklist</span>
                    <span class="font-black text-sm font-outfit text-white"><span id="floatSelectedCount">0</span> Seleccionados</span>
                </div>
                
                <div class="flex flex-wrap items-center gap-3 sm:gap-4 pl-1 text-xs">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Total a Pagar</span>
                        <span id="floatSelectedPagar" class="text-base font-black text-emerald-400 font-outfit">$0</span>
                    </div>
                    <div class="hidden sm:block text-slate-700">|</div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Monto Examen (Servinte)</span>
                        <span id="floatSelectedExamen" class="text-sm font-bold text-slate-200 font-outfit">$0</span>
                    </div>
                    <div class="hidden md:block text-slate-700">|</div>
                    <div class="flex items-center gap-2 text-[11px] text-slate-300 font-medium">
                        <span class="inline-flex items-center gap-1 text-emerald-400"><span class="material-symbols-outlined text-xs">check_circle</span> <span id="floatCruzadosOK">0</span> OK</span>
                        <span class="inline-flex items-center gap-1 text-rose-400"><span class="material-symbols-outlined text-xs">cancel</span> <span id="floatSoloProteo">0</span> Proteo</span>
                        <span class="inline-flex items-center gap-1 text-sky-400"><span class="material-symbols-outlined text-xs">local_hospital</span> <span id="floatSoloServinte">0</span> Servinte</span>
                        <span class="inline-flex items-center gap-1 text-amber-400"><span class="material-symbols-outlined text-xs">do_not_disturb_on</span> <span id="floatExcluidos">0</span> Excluidos</span>
                    </div>
                </div>
            </div>

            <!-- Right: Quick Select Buttons + Action Buttons -->
            <div class="flex flex-wrap items-center gap-2 w-full xl:w-auto justify-end">
                <button type="button" onclick="seleccionarTodosVisibles()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs border border-slate-700 transition-all cursor-pointer">
                    Seleccionar página actual
                </button>
                <button type="button" onclick="seleccionarTodosFiltrados()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs border border-slate-700 transition-all cursor-pointer">
                    Seleccionar los <span id="floatTotalFiltrados">0</span> filtrados
                </button>
                <button type="button" onclick="limpiarSeleccion()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-rose-950/70 hover:bg-rose-900 text-rose-300 hover:text-white font-bold text-xs border border-rose-800/70 transition-all cursor-pointer" title="Deseleccionar todos los registros">
                    <span class="material-symbols-outlined text-sm">close</span>
                    <span>Limpiar selección</span>
                </button>
                <button type="button" onclick="exportarSeleccionadosAExcel()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-lg shadow-emerald-900/30 transition-all cursor-pointer hover:scale-105 active:scale-95">
                    <span class="material-symbols-outlined text-base">file_download</span>
                    <span>Exportar Selección (.xls)</span>
                </button>
                <button type="button" onclick="abrirModalLiquidacion(true)" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-tertiary hover:bg-teal-600 text-white font-bold text-xs shadow-lg shadow-teal-900/30 transition-all cursor-pointer hover:scale-105 active:scale-95">
                    <span class="material-symbols-outlined text-base">receipt_long</span>
                    <span>Liquidar Selección</span>
                </button>
            </div>

        </div>
    </div>

    <!-- Modal Detalle del Examen & Conciliación -->
    <div id="modalDetalle" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden transition-opacity duration-300 opacity-0 pointer-events-none">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-3xl w-full overflow-hidden flex flex-col max-h-[90vh] transition-transform duration-300 scale-95">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-800/50">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-tertiary text-white">
                        <span class="material-symbols-outlined text-xl">swap_horizontal_circle</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white font-outfit">Detalle de Conciliación Bidireccional</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400" id="modalSubtitulo">Fuente: - | Ingreso: -</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-2xl">close</span>
                </button>
            </div>

            <!-- Modal Content -->
            <div class="p-6 overflow-y-auto space-y-6 flex-1 text-xs">
                
                <!-- Status Banner -->
                <div id="modalStatusBanner" class="p-4 rounded-2xl border flex items-center gap-3">
                    <!-- Dinámico -->
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Card PROTEO (SQL Server) -->
                    <div class="p-4 rounded-2xl bg-sky-50/50 dark:bg-sky-950/20 border border-sky-200/80 dark:border-sky-900/50 space-y-3">
                        <div class="flex items-center justify-between border-b border-sky-200/60 dark:border-sky-900/50 pb-2">
                            <span class="font-black text-sky-800 dark:text-sky-300 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base">database</span>
                                PROTEO (SQL SERVER)
                            </span>
                            <span id="modalProteoId" class="text-[10px] font-bold text-sky-600 dark:text-sky-400 bg-sky-100 dark:bg-sky-900/60 px-2 py-0.5 rounded-md">ID: -</span>
                        </div>

                        <div class="space-y-2 text-slate-700 dark:text-slate-300" id="modalProteoBody">
                            <!-- Dinámico -->
                        </div>
                    </div>

                    <!-- Card SERVINTE (Oracle) -->
                    <div class="p-4 rounded-2xl bg-teal-50/50 dark:bg-teal-950/20 border border-teal-200/80 dark:border-teal-900/50 space-y-3">
                        <div class="flex items-center justify-between border-b border-teal-200/60 dark:border-teal-900/50 pb-2">
                            <span class="font-black text-teal-800 dark:text-teal-300 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base">local_hospital</span>
                                SERVINTE (ORACLE)
                            </span>
                            <span id="modalServinteBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-md">-</span>
                        </div>

                        <div class="space-y-2 text-slate-700 dark:text-slate-300" id="modalServinteBody">
                            <!-- Dinámico -->
                        </div>
                    </div>

                </div>

            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 flex justify-end">
                <button type="button" onclick="cerrarModal()" class="px-4 py-2 rounded-xl bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors cursor-pointer">
                    Cerrar
                </button>
            </div>

        </div>
    </div>

    <!-- Modal Generar Liquidación de Turnos -->
    <div id="modalLiquidacion" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/70 backdrop-blur-md hidden transition-opacity duration-300 opacity-0 pointer-events-none">
        <div class="bg-slate-50 dark:bg-slate-950 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-6xl w-full overflow-hidden flex flex-col max-h-[92vh] transition-transform duration-300 scale-95">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-white dark:bg-slate-900 no-print">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-tertiary/10 dark:bg-tertiary/30 text-tertiary dark:text-emerald-400 border border-tertiary/20">
                        <span class="material-symbols-outlined text-2xl">receipt_long</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white font-outfit">Liquidación de Turnos / Honorarios Médicos</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Generación y cálculo de honorarios con deducciones estimadas editables</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($canGenerateLiquidation): ?>
                        <button type="button" id="btnGuardarLiquidacionDB" onclick="guardarLiquidacionBDFront()" 
                            class="px-4 py-2 rounded-xl bg-primary hover:bg-slate-800 dark:bg-tertiary dark:hover:bg-teal-700 text-white font-black text-xs flex items-center gap-1.5 shadow-md transition-all cursor-pointer hover:scale-105 active:scale-95">
                            <span class="material-symbols-outlined text-base">task_alt</span>
                            <span>GENERAR LIQUIDACIÓN</span>
                        </button>
                    <?php endif; ?>
                    <button type="button" onclick="imprimirLiquidacion()" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold text-xs flex items-center gap-1.5 transition-colors cursor-pointer">
                        <span class="material-symbols-outlined text-base">print</span>
                        <span>Imprimir / PDF</span>
                    </button>
                    <button type="button" onclick="cerrarModalLiquidacion()" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors cursor-pointer rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined text-2xl">close</span>
                    </button>
                </div>
            </div>

            <!-- Modal Content (Area imprimible) -->
            <div class="p-6 overflow-y-auto flex-1 space-y-6 text-xs bg-slate-100/50 dark:bg-slate-950/50" id="areaImpresionLiquidacion">
                
                <!-- Formal Corporate Print Header (Solo visible en Impresión / PDF) -->
                <div class="hidden print:block mb-4 pb-3 border-b-2 border-slate-900 avoid-page-break">
                    <div class="flex justify-between items-start">
                        <div>
                            <h1 class="text-base font-black text-slate-900 uppercase tracking-wide font-outfit" id="liqEmpresaNombrePrint"><?php echo htmlspecialchars($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?></h1>
                            <p class="text-[10px] font-semibold text-slate-700" id="liqEmpresaNitPrint">
                                <?php 
                                if ($entidadActivaId === 'PROPIO' || empty($entidadActivaId)) {
                                    echo 'SISTEMAS DIAGNÓSTICOS E IMÁGENES MÉDICAS | NIT: 890.980.123-4';
                                } elseif (!empty($entidadActivaNit)) {
                                    echo 'NIT: ' . htmlspecialchars($entidadActivaNit);
                                } else {
                                    echo 'ENTIDAD EXTERNA';
                                }
                                ?>
                            </p>
                            <p class="text-[9px] text-slate-500">LIHO - Sistema de Liquidación de Honorarios y Turnos Médicos</p>
                        </div>
                        <div class="text-right">
                            <div class="inline-block px-2.5 py-0.5 bg-slate-100 border border-slate-400 rounded text-[11px] font-black uppercase text-slate-900">
                                PRE-LIQUIDACIÓN DE HONORARIOS
                            </div>
                            <p class="text-[9px] font-mono text-slate-600 mt-0.5">FECHA EMISIÓN: <?php echo date('d/m/Y'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Header Information Card -->
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col md:flex-row justify-between items-start md:items-center gap-4 avoid-page-break">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h2 class="text-lg font-black text-slate-900 dark:text-white font-outfit tracking-wide" id="liqModalTituloCard">LIQUIDACIÓN DE HONORARIOS</h2>
                            <span id="liqTipoLiquidacionBadge" class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700">Consolidado Global</span>
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 flex flex-wrap items-center gap-x-6 gap-y-1 font-medium">
                            <p><strong class="text-slate-700 dark:text-slate-300">EMPRESA:</strong> <span id="liqEmpresaLabel" class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?></span> <span id="liqEmpresaNitLabel" class="text-slate-400 font-normal"><?php echo !empty($entidadActivaNit) ? ('• NIT: ' . htmlspecialchars($entidadActivaNit)) : ''; ?></span></p>
                            <p><strong class="text-slate-700 dark:text-slate-300">PERIODO:</strong> <span id="liqPeriodoLabel" class="font-semibold text-slate-800 dark:text-slate-200">-</span></p>
                        </div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-800/60 p-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700/80 text-right min-w-[210px]">
                        <p class="text-[10px] font-bold uppercase text-slate-400 tracking-wider" id="liqDoctorSubhead">ALCANCE / MODALIDAD</p>
                        <p class="text-sm font-black text-primary dark:text-tertiary" id="liqDoctorName">-</p>
                        <p class="text-[11px] font-mono text-slate-500 dark:text-slate-400" id="liqDoctorCedula">CÉDULA: -</p>
                    </div>
                </div>

                <!-- Main 2-Column Grid -->
                <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
                    
                    <!-- Left Column: Detailed Studies -->
                    <div class="xl:col-span-8 space-y-4">
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col">
                            <div class="bg-primary dark:bg-slate-800 text-white font-bold text-xs tracking-widest text-center uppercase py-2.5 px-4 font-outfit">
                                DETALLE DE LIQUIDACIÓN: ESTUDIOS REALIZADOS
                            </div>
                            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4" id="liqContainerSedes">
                                <!-- Dinámico -->
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Summaries & Deductions & Totals -->
                    <div class="xl:col-span-4 space-y-4 flex flex-col">
                        
                        <!-- Banner Informativo Bonificación Tomografías ($150.000 COP por cada 50) -->
                        <div id="liqBannerBonificacionTomo" class="hidden p-3.5 rounded-2xl bg-gradient-to-r from-[#fbf8f1] via-[#f7f3e8] to-[#fbf8f1] dark:from-[#2a1d0d] dark:via-[#20170a] dark:to-[#16120b] border border-[#dfd5c0] dark:border-amber-500/80 text-[#4e3b1f] dark:text-amber-100 text-xs shadow-xs">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-[#8a6021] dark:text-amber-400 text-2xl shrink-0 mt-0.5">military_tech</span>
                                <div class="space-y-1 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-black text-[#3a2c16] dark:text-white uppercase tracking-wider text-xs font-outfit">BONIFICACIÓN DE PRODUCTIVIDAD EN TOMOGRAFÍAS</p>
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-[#ebdfc6] text-[#523b16] dark:bg-amber-500/25 dark:text-amber-200 border border-[#cfbe9b] dark:border-amber-500/40">$150.000 x cada 50</span>
                                    </div>
                                    <p class="text-[11px] leading-relaxed text-[#5e451b] dark:text-amber-200/90">
                                        Aplica a <strong class="text-[#3a2c16] dark:text-white underline decoration-[#b88c38]">todos los médicos y entidades</strong>: +$ <strong class="font-mono text-xs font-black text-[#3a2c16] dark:text-white" id="liqValBonificacionTomoDisplay">0</strong> (<span id="liqCantBonificacionTomoDisplay" class="font-bold">0</span> bono(s) de $150.000 COP por <span id="liqCantContrastadasDisplay" class="font-bold">0</span> tomografías seleccionadas).
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Banner Informativo / Advertencia Registros No Cruzados -->
                        <div id="liqBannerAdvertenciaNoCruzados" class="hidden p-3.5 rounded-2xl bg-[#fbf9f4] dark:bg-amber-950/50 border border-[#dfd5c0] dark:border-amber-700/80 text-[#4e3b1f] dark:text-amber-200 text-xs shadow-xs">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-[#8a6021] dark:text-amber-400 text-xl shrink-0 mt-0.5">warning</span>
                                <div class="space-y-0.5">
                                    <p class="font-extrabold text-[#423219] dark:text-amber-300 uppercase tracking-wider text-[11px]">ADVERTENCIA: INCLUYE REGISTROS NO CRUZADOS</p>
                                    <p class="text-[11px] leading-snug opacity-95">
                                        La liquidación total incluye <strong><span id="liqCountNoCruzadosDisplay">0</span> registro(s) no cruzado(s)</strong> por valor de <strong>$ <span id="liqValNoCruzadosDisplay">0</span></strong>.
                                        Los ítems no cruzados han sido incluidos pero están señalizados con la insignia <span class="px-1.5 py-0.5 rounded bg-[#ece2cb] dark:bg-amber-900 text-[#4a3713] dark:text-amber-200 border border-[#d8c8a8] dark:border-amber-700 font-bold text-[10px]"><i class="fa-solid fa-triangle-exclamation mr-1"></i>No Cruzado</span> en cada sede.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Banner Informativo Exámenes Excluidos -->
                        <div id="liqBannerExclusiones" class="hidden p-3.5 rounded-2xl bg-[#fbf9f4] dark:bg-amber-950/60 border border-[#dfd5c0] dark:border-amber-700 text-[#4e3b1f] dark:text-amber-200 text-xs shadow-xs">
                            <div class="flex items-start justify-between gap-2.5">
                                <div class="flex items-start gap-2.5">
                                    <span class="material-symbols-outlined text-[#8a6021] dark:text-amber-400 text-xl shrink-0 mt-0.5">do_not_disturb_on</span>
                                    <div class="space-y-0.5">
                                        <p class="font-extrabold text-[#423219] dark:text-amber-300 uppercase tracking-wider text-[11px]">EXÁMENES EXCLUIDOS DE ESTA LIQUIDACIÓN</p>
                                        <p class="text-[11px] leading-snug opacity-95">
                                            Se han omitido <strong><span id="liqCountExcluidosDisplay">0</span> examen(es)</strong> con justificación obligatoria por valor de <strong>$ <span id="liqValExcluidosDisplay">0</span></strong>.
                                        </p>
                                    </div>
                                </div>
                                <button type="button" onclick="verExclusionesLiquidacionPreview()" class="px-2.5 py-1 rounded-xl bg-[#ece2cb] hover:bg-[#e2d5bd] dark:bg-amber-900 dark:hover:bg-amber-800 text-[#4a3713] dark:text-amber-100 font-extrabold text-[10px] shrink-0 border border-[#d8c8a8] dark:border-amber-700 transition-colors shadow-xs cursor-pointer">
                                    Ver listado
                                </button>
                            </div>
                        </div>

                        <!-- Resumen Administrativo por Sede -->
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                            <div class="bg-primary dark:bg-slate-800 text-white font-bold text-xs tracking-widest text-center uppercase py-2.5 px-4 font-outfit">
                                ESTUDIOS POR ESTRUCTURA ADMINISTRATIVA
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-100 dark:bg-slate-800/80 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800">
                                            <th class="py-2 px-3">SEDE</th>
                                            <th class="py-2 px-3 text-right">VALOR TOTAL</th>
                                        </tr>
                                    </thead>
                                    <tbody id="liqTbodyResumenSedes" class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-200">
                                        <!-- Dinámico -->
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold bg-slate-100/80 dark:bg-slate-800/80 text-slate-900 dark:text-white">
                                            <td class="py-2.5 px-3 text-right uppercase text-[11px] font-black">TOTAL FACTURA</td>
                                            <td class="py-2.5 px-3 text-right font-black text-sm text-primary dark:text-tertiary" id="liqTotalFacturaSum">$0</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Información Contable - Deducciones (EDITABLE) -->
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-rose-200 dark:border-rose-900/60 shadow-sm overflow-hidden border-l-4 border-l-rose-500 flex flex-col">
                            <div class="bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 py-2.5 px-4 font-bold text-xs tracking-widest text-center uppercase border-b border-rose-200 dark:border-rose-900/40 flex items-center justify-center gap-1.5 shrink-0">
                                <span class="material-symbols-outlined text-base">edit_note</span>
                                INFORMACIÓN CONTABLE - DEDUCCIONES (EDITABLE)
                            </div>

                            <!-- Mensaje cuando el médico no tiene deducciones activadas -->
                            <div id="liqNoDeduccionesBox" class="hidden py-6 px-4 bg-slate-50/80 dark:bg-slate-800/80 flex flex-col items-center justify-center text-center gap-2.5">
                                <div class="w-10 h-10 rounded-xl bg-slate-200/80 dark:bg-slate-700/80 flex items-center justify-center text-slate-500 dark:text-slate-400 shadow-inner">
                                    <span class="material-symbols-outlined text-xl">money_off</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider">Sin deducciones activas</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">El médico no tiene ninguna deducción activa configurada.</p>
                                </div>
                            </div>

                            <div id="liqDeduccionesFormBody">
                                <!-- Indicador Dinámico de Parafiscales -->
                                <div id="liqParafiscalesStatusBadge" class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-1 text-[10px]">
                                    <span class="font-bold flex items-center gap-1.5" id="liqParafiscalesStatusText">
                                        <span class="w-2 h-2 rounded-full bg-slate-400 shrink-0" id="liqParafiscalesDot"></span>
                                        <span id="liqParafiscalesLabel">Parafiscales Desactivados ($0)</span>
                                    </span>
                                    <span class="font-mono font-extrabold text-[9px] text-teal-600 dark:text-teal-400" id="liqParafiscalesRatesText">Valores en $0</span>
                                </div>

                                <div class="p-4 space-y-3">
                                    
                                    <!-- IBC Mes (Visible si aplica Parafiscales / Pensión / ARL) -->
                                    <div id="row_ded_ibc" class="flex items-center justify-between gap-2">
                                        <label class="text-[11px] font-bold text-slate-700 dark:text-slate-200">IBC MES (ESTIMADO)</label>
                                        <div class="w-36 text-right pr-2">
                                            <span id="ded_ibc_display" class="font-mono font-black text-xs text-slate-900 dark:text-white">$ 0</span>
                                            <input type="hidden" id="ded_ibc" value="0">
                                        </div>
                                    </div>

                                    <!-- Fila Deducción AFC Mes (Manual si el médico tiene AFC activo) -->
                                    <div id="row_ded_afc" class="hidden flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                        <div class="flex items-center gap-1.5">
                                            <label class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">AFC MES</label>
                                            <span class="text-[9px] font-bold text-teal-600 dark:text-teal-400 uppercase tracking-wider bg-teal-100 dark:bg-teal-950/60 px-1.5 py-0.5 rounded border border-teal-200 dark:border-teal-800/60">Manual</span>
                                        </div>
                                        <div class="relative w-36">
                                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 font-mono text-rose-600 dark:text-rose-400 text-xs font-bold">- $</span>
                                            <input type="text" inputmode="numeric" id="ded_afc" value="0" oninput="formatInputMiles(this); recalcularLiquidacion(false)" 
                                                class="w-full pl-8 pr-2 py-1 bg-rose-50/50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800 rounded-lg text-right font-mono font-bold text-rose-700 dark:text-rose-300 text-xs focus:ring-2 focus:ring-rose-500/30 outline-none">
                                        </div>
                                    </div>

                                    <div id="row_ded_salud" class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                        <label class="text-[11px] font-medium">MENOS APORTES SALUD MES</label>
                                        <div class="w-36 text-right pr-2">
                                            <span id="ded_salud_display" class="font-mono font-bold text-xs text-rose-600 dark:text-rose-400">- $ 0</span>
                                            <input type="hidden" id="ded_salud" value="0">
                                        </div>
                                    </div>

                                    <div id="row_ded_arl" class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                        <label class="text-[11px] font-medium">MENOS APORTES ARL MES</label>
                                        <div class="w-36 text-right pr-2">
                                            <span id="ded_arl_display" class="font-mono font-bold text-xs text-rose-600 dark:text-rose-400">- $ 0</span>
                                            <input type="hidden" id="ded_arl" value="0">
                                        </div>
                                    </div>

                                    <div id="row_ded_pension" class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                        <label class="text-[11px] font-medium">MENOS APORTES PENSIÓN MES</label>
                                        <div class="w-36 text-right pr-2">
                                            <span id="ded_pension_display" class="font-mono font-bold text-xs text-rose-600 dark:text-rose-400">- $ 0</span>
                                            <input type="hidden" id="ded_pension" value="0">
                                        </div>
                                    </div>

                                    <!-- Fondo de Solidaridad (Visible únicamente si el médico tiene Parafiscales activos) -->
                                    <div id="row_ded_solidaridad" class="hidden flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                        <div class="flex items-center gap-1.5">
                                            <label class="text-[11px] font-medium text-slate-700 dark:text-slate-200">Fondo Solidaridad</label>
                                            <span class="text-[9px] font-bold text-indigo-700 dark:text-indigo-400 uppercase tracking-wider bg-indigo-100 dark:bg-indigo-950/60 px-1.5 py-0.5 rounded border border-indigo-200 dark:border-indigo-800/60">Manual %</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <div class="relative w-20">
                                                <input type="number" id="ded_solidaridad_pct" value="0" min="0" max="100" step="0.01" placeholder="0" 
                                                    oninput="recalcularLiquidacion(false)" 
                                                    class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg pl-2 pr-5 py-1 text-slate-900 dark:text-slate-100 font-mono text-right text-xs transition outline-none"
                                                    title="Porcentaje para Fondo de Solidaridad sobre el IBC">
                                                <span class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 font-bold font-mono text-[10px] pointer-events-none">%</span>
                                            </div>
                                            <div class="w-28 text-right pr-2">
                                                <span id="ded_solidaridad_display" class="font-mono font-bold text-xs text-rose-600 dark:text-rose-400">- $ 0</span>
                                                <input type="hidden" id="ded_solidaridad" value="0">
                                            </div>
                                        </div>
                                    </div>

                                    <div id="row_ded_retenciones" class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-2">
                                        <!-- Bloque Retención Art. 383 (Informativo + Entrada Manual) -->
                                        <div id="container_ded_rete_383" class="space-y-2">
                                            <!-- Fila Informativa Art. 383 (Editable para registro, no afecta total deducciones) -->
                                            <div class="flex items-center justify-between gap-2 py-1 px-1.5 rounded-lg bg-slate-50/60 dark:bg-slate-800/40">
                                                <div class="flex items-center gap-1.5">
                                                    <label class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">RETE FUENTE ART 383</label>
                                                    <span class="text-[9px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider bg-slate-200/60 dark:bg-slate-700/60 px-1.5 py-0.5 rounded">Informativo</span>
                                                </div>
                                                <div class="relative w-36">
                                                    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 font-mono text-slate-500 dark:text-slate-400 text-xs font-bold">$</span>
                                                    <input type="text" inputmode="numeric" id="ded_rete_383_info" value="0" oninput="formatInputMiles(this);" 
                                                        class="w-full pl-6 pr-2 py-1 bg-slate-100/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-700 rounded-lg text-right font-mono font-bold text-slate-700 dark:text-slate-300 text-xs focus:ring-2 focus:ring-slate-400/30 outline-none">
                                                </div>
                                            </div>

                                            <!-- Fila Manual Art. 383 (Editable, sí afecta total deducciones) -->
                                            <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                                <div class="flex items-center gap-1.5">
                                                    <label class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">RETENCIÓN ART 383</label>
                                                    <span class="text-[9px] font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider bg-cyan-100 dark:bg-cyan-950/60 px-1.5 py-0.5 rounded">Manual</span>
                                                </div>
                                                <div class="relative w-36">
                                                    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 font-mono text-rose-600 dark:text-rose-400 text-xs font-bold">- $</span>
                                                    <input type="text" inputmode="numeric" id="ded_rete_383" value="0" oninput="formatInputMiles(this); recalcularLiquidacion(false)" 
                                                        class="w-full pl-8 pr-2 py-1 bg-rose-50/50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800 rounded-lg text-right font-mono font-bold text-rose-700 dark:text-rose-300 text-xs focus:ring-2 focus:ring-rose-500/30 outline-none">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Bloque Retención Porcentual Estándar -->
                                        <div id="container_ded_retencion_pct" class="space-y-2">
                                            <!-- Porcentaje de Retención (Editable Input) -->
                                            <div class="flex items-center justify-between gap-2">
                                                <label class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">PORCENTAJE DE RETENCIÓN</label>
                                                <div class="relative w-36">
                                                    <input type="number" id="ded_retencion_pct" value="0" min="0" max="100" step="0.01" oninput="recalcularLiquidacion(false)" 
                                                        class="w-full pl-3 pr-7 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-xs focus:ring-2 focus:ring-rose-500/30 outline-none">
                                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 font-mono font-bold text-xs">%</span>
                                                </div>
                                            </div>

                                            <!-- Valor Retención (Calculado sobre Total Factura - Solo Texto) -->
                                            <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                                <label class="text-[11px] font-medium">RETENCIÓN</label>
                                                <div class="w-36 text-right pr-2">
                                                    <span id="ded_retencion_display" class="font-mono font-bold text-xs text-rose-600 dark:text-rose-400">- $ 0</span>
                                                    <input type="hidden" id="ded_retencion" value="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-3 border-t-2 border-rose-200 dark:border-rose-800 flex items-center justify-between font-bold text-rose-700 dark:text-rose-400">
                                        <span class="text-[11px] uppercase tracking-wider font-black">TOTAL DEDUCCIONES</span>
                                        <span class="font-mono text-sm font-black" id="liqTotalDeduccionesDisplay">- $0</span>
                                    </div>

                                </div>
                            </div>

                        </div>

                        <!-- Card 2.5: Novedades de Liquidación -->
                        <div class="bg-white dark:bg-slate-900 border border-amber-300 dark:border-amber-800/70 rounded-2xl p-4 shadow-sm overflow-hidden relative">
                            <div class="flex items-center justify-between gap-2">
                                <label for="chkRegistraNovedadesLiq" class="text-xs font-bold text-amber-900 dark:text-amber-300 flex items-center gap-1.5 cursor-pointer select-none">
                                    <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-base">campaign</span>
                                    <span>¿Registra novedades?</span>
                                </label>
                                <input type="checkbox" id="chkRegistraNovedadesLiq" onchange="alCambiarToggleNovedadesLiq(this)" class="w-4 h-4 text-amber-600 bg-white dark:bg-slate-800 border-amber-400 rounded focus:ring-amber-500 cursor-pointer" />
                            </div>

                            <!-- Resumen dinámico si hay novedades seleccionadas -->
                            <div id="containerNovedadesLiqResumen" class="hidden mt-3 pt-3 border-t border-amber-200 dark:border-amber-800/60 space-y-2">
                                <div class="flex items-center justify-between text-[11px] font-bold text-slate-700 dark:text-slate-300">
                                    <span>Novedades Seleccionadas</span>
                                    <button type="button" onclick="abrirModalNovedadesLiq()" class="text-amber-600 dark:text-amber-400 hover:underline flex items-center gap-0.5 cursor-pointer font-bold text-[10px]">
                                        <span class="material-symbols-outlined text-xs">edit_note</span>
                                        <span>Modificar</span>
                                    </button>
                                </div>
                                <div id="listaNovedadesLiqBadges" class="flex flex-col gap-1.5"></div>
                                <div class="pt-2 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-xs font-bold font-mono">
                                    <span class="text-slate-500 dark:text-slate-400 font-sans text-[11px]">Neto Novedades:</span>
                                    <span id="liqTotalNovedadesNetoDisplay" class="text-emerald-600 dark:text-emerald-400">+ $ 0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Total Final Card (Dentro de la columna derecha) -->
                        <div class="bg-gradient-to-r from-emerald-600 to-teal-700 dark:from-teal-900 dark:to-slate-900 text-white p-5 rounded-2xl shadow-lg border border-teal-500/30 flex flex-col items-center justify-center relative overflow-hidden">
                            <p class="text-[11px] font-black uppercase tracking-widest text-emerald-200 mb-1 z-10">TOTAL A PAGAR ===&gt;&gt;&gt;</p>
                            <p class="text-3xl font-black font-outfit z-10 tracking-tight text-white" id="liqTotalPagarDisplay">$0</p>
                        </div>

                    </div>

                    <!-- Bloque Formal de Firmas Corporativas (Visible en Impresión / PDF) -->
                    <div class="hidden print:flex print-signatures-block">
                        <div class="print-signature-col">
                            <div class="print-signature-line" id="liqDoctorNamePrintSignature">
                                FIRMA DEL PROFESIONAL MÉDICO
                            </div>
                            <p class="text-[9px] text-slate-600 uppercase font-semibold">FIRMA DEL PROFESIONAL MÉDICO</p>
                            <p class="text-[8.5px] text-slate-500 font-mono" id="liqDoctorCedulaPrintSignature">C.C. / ID: -</p>
                        </div>
                        <div class="print-signature-col">
                            <div class="print-signature-line" id="liqEmpresaFirmaPrint">
                                <?php echo htmlspecialchars($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CIA S.A.S.'); ?>
                            </div>
                            <p class="text-[9px] text-slate-600 uppercase font-semibold">DIRECCIÓN FINANCIERA Y CONTABILIDAD</p>
                            <p class="text-[8.5px] text-slate-500 font-mono">Revisión y Liquidación Autorizada</p>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </div>
</div>

    <!-- ========================================================================= -->
    <!-- MODAL CATÁLOGO DE NOVEDADES POR ENTIDAD (LIQUIDACIÓN DE TURNOS) -->
    <!-- ========================================================================= -->
    <div id="modalNovedadesLiq" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-950/75 backdrop-blur-md">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-2xl w-full shadow-2xl border border-amber-300 dark:border-amber-700/60 flex flex-col max-h-[90vh] overflow-hidden animate__animated animate__zoomIn animate__faster">
            
            <div class="px-6 py-4 border-b border-amber-200 dark:border-amber-800/60 flex items-center justify-between bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-2xl">campaign</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-outfit text-slate-900 dark:text-white">Novedades de la Entidad</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400" id="modalNovLiqSubhead">Catálogo de novedades disponible para esta liquidación</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalNovedadesLiq()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-4 flex-1 custom-scrollbar">
                <div id="modalNovLiqLoading" class="py-8 text-center text-slate-400">
                    <span class="material-symbols-outlined text-3xl animate-spin text-amber-500 mb-2">sync</span>
                    <p class="text-xs font-semibold">Consultando catálogo de novedades de la entidad...</p>
                </div>

                <div id="modalNovLiqEmpty" class="hidden py-8 text-center text-slate-400">
                    <span class="material-symbols-outlined text-4xl text-slate-300 dark:text-slate-600 mb-2">info</span>
                    <p class="text-xs font-semibold">No hay novedades activas configuradas para esta entidad en el Maestro de Novedades.</p>
                    <a href="maestro_novedades.php" target="_blank" class="mt-2 inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 hover:underline font-bold">
                        <span class="material-symbols-outlined text-xs">open_in_new</span> Ir al Maestro de Novedades
                    </a>
                </div>

                <div id="modalNovLiqCards" class="hidden space-y-3">
                    <!-- Dinámico: cards con checkbox, código, nombre, tipo, input de valor e input de observación -->
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-950/50">
                <div class="text-xs text-slate-500 dark:text-slate-400">
                    <span id="modalNovLiqSeleccionadasCount" class="font-bold text-amber-600 dark:text-amber-400 font-mono">0</span> novedad(es) seleccionada(s)
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="cerrarModalNovedadesLiq()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="button" onclick="aplicarNovedadesALiquidacion()" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 shadow-md shadow-amber-600/20 active:scale-95 transition-all flex items-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                        <span>Aplicar a la Liquidación</span>
                    </button>
                </div>
            </div>

        </div>
    </div>

<style>
@media print {
    @page {
        size: letter portrait;
        margin: 10mm 12mm 12mm 12mm;
    }

    html, body {
        background: #ffffff !important;
        color: #0f172a !important;
        font-size: 10.5px !important;
        line-height: 1.25 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        height: auto !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body > *:not(#modalLiquidacion) {
        display: none !important;
    }

    #modalLiquidacion {
        position: static !important;
        display: block !important;
        background: transparent !important;
        padding: 0 !important;
        margin: 0 !important;
        opacity: 1 !important;
        pointer-events: auto !important;
        width: 100% !important;
        max-width: 100% !important;
        box-shadow: none !important;
        border: none !important;
        overflow: visible !important;
        backdrop-filter: none !important;
        transform: none !important;
    }

    #modalLiquidacion > div {
        max-width: 100% !important;
        max-height: none !important;
        box-shadow: none !important;
        border: none !important;
        background: #ffffff !important;
        border-radius: 0 !important;
        overflow: visible !important;
        display: block !important;
        transform: none !important;
    }

    .no-print,
    #modalLiquidacion .no-print,
    button,
    input,
    .cursor-pointer,
    .material-symbols-outlined,
    .fa-arrow-right {
        display: none !important;
    }

    #areaImpresionLiquidacion {
        padding: 0 !important;
        background: #ffffff !important;
        overflow: visible !important;
        max-height: none !important;
        display: block !important;
    }

    .avoid-page-break,
    .border,
    table,
    tr {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .bg-primary {
        background-color: #0f172a !important;
        color: #ffffff !important;
    }
    .bg-slate-900, .dark\:bg-slate-900, .bg-slate-50, .dark\:bg-slate-950 {
        background-color: #ffffff !important;
        color: #0f172a !important;
    }
    .bg-slate-100, .dark\:bg-slate-800, .bg-slate-800 {
        background-color: #f8fafc !important;
        color: #0f172a !important;
    }
    .text-slate-900, .dark\:text-white, .text-white {
        color: #0f172a !important;
    }
    .text-slate-500, .dark\:text-slate-400, .text-slate-400 {
        color: #475569 !important;
    }
    .border-slate-200, .border-slate-700, .dark\:border-slate-800, .border-slate-300 {
        border-color: #cbd5e1 !important;
    }

    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    th, td {
        padding: 3.5px 5px !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }
    th {
        background-color: #f1f5f9 !important;
        color: #1e293b !important;
        font-weight: 800 !important;
        font-size: 9px !important;
        text-transform: uppercase !important;
    }

    .xl\:grid-cols-12 {
        display: flex !important;
        flex-direction: row !important;
        gap: 14px !important;
    }
    .xl\:col-span-8 {
        flex: 0 0 62% !important;
        max-width: 62% !important;
    }
    .xl\:col-span-4 {
        flex: 0 0 38% !important;
        max-width: 38% !important;
    }
    .md\:grid-cols-2 {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
    }

    .bg-gradient-to-r {
        background-color: #0f766e !important;
        border: 2px solid #0f766e !important;
        border-radius: 8px !important;
        padding: 8px !important;
        text-align: center !important;
    }
    .bg-gradient-to-r * {
        color: #ffffff !important;
    }

    .print-signatures-block {
        display: flex !important;
        justify-content: space-between !important;
        margin-top: 28px !important;
        padding-top: 15px !important;
        border-top: 1px solid #cbd5e1 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    .print-signature-col {
        width: 45% !important;
        text-align: center !important;
    }
    .print-signature-line {
        border-top: 1px solid #0f172a !important;
        margin-bottom: 4px !important;
        padding-top: 4px !important;
        font-weight: bold !important;
        color: #0f172a !important;
        font-size: 10px !important;
    }
}
</style>

    <!-- Footer -->
    <?php include_once __DIR__ . '/includes/footer.php'; ?>

    <!-- JavaScript Logic -->
    <script>
        window.currentEntidadIdLiq = <?php echo json_encode($entidadActivaId ?: 'PROPIO'); ?>;
        window.currentEntidadNombreLiq = <?php echo json_encode($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>;
        window.currentEntidadNitLiq = <?php echo json_encode($entidadActivaNit ?: ''); ?>;

        let allData = [];
        let filteredData = [];
        let paginaActual = 1;
        const registrosPorPagina = 25;
        let filtroPestañaActual = '';
        let haConsultado = false;

        function initApp() {
            inicializarSearchableMedico();
            initDraggableFloatingBar();
            actualizarEstadoBotonesAccion();
            renderizarTabla();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initApp);
        } else {
            initApp();
        }

        function inicializarSearchableMedico() {
            const container = document.getElementById('medicoSelectContainer');
            const trigger = document.getElementById('btnMedicoTrigger');
            const menu = document.getElementById('dropdownMedicoMenu');
            const searchInput = document.getElementById('searchMedicoInput');
            const hiddenInput = document.getElementById('medico');
            const labelSpan = document.getElementById('selectedMedicoLabel');
            const chevronIcon = document.getElementById('chevronMedicoIcon');
            const options = document.querySelectorAll('.medico-option-btn');

            if (!trigger || !menu) return;

            function toggleMenu(forceOpen = null) {
                const shouldOpen = (forceOpen !== null) ? forceOpen : menu.classList.contains('hidden');
                if (shouldOpen) {
                    menu.classList.remove('hidden');
                    if (chevronIcon) chevronIcon.classList.add('rotate-180', 'text-tertiary');
                    if (searchInput) {
                        searchInput.value = '';
                        filtrarOpcionesMedico('');
                        setTimeout(() => searchInput.focus(), 60);
                    }
                } else {
                    menu.classList.add('hidden');
                    if (chevronIcon) chevronIcon.classList.remove('rotate-180', 'text-tertiary');
                }
            }

            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleMenu();
            });

            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    filtrarOpcionesMedico(e.target.value);
                });
                searchInput.addEventListener('click', (e) => e.stopPropagation());
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.stopPropagation();
                        // Seleccionar la primera opción visible
                        const firstOpt = Array.from(options).find(opt => {
                            const li = opt.parentElement;
                            return li && li.style.display !== 'none';
                        });
                        if (firstOpt) {
                            firstOpt.click();
                        }
                    } else if (e.key === 'Escape') {
                        toggleMenu(false);
                    }
                });
            }

            options.forEach(opt => {
                opt.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const val = opt.getAttribute('data-value') || '';
                    const label = opt.getAttribute('data-label') || '-- Todos los Médicos --';

                    hiddenInput.value = val;
                    labelSpan.textContent = label;

                    options.forEach(o => {
                        const check = o.querySelector('.check-icon');
                        if (o === opt) {
                            o.classList.add('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                            if (check) check.classList.remove('hidden');
                        } else {
                            o.classList.remove('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                            if (check) check.classList.add('hidden');
                        }
                    });

                    toggleMenu(false);
                });
            });

            document.addEventListener('click', (e) => {
                if (container && !container.contains(e.target)) {
                    toggleMenu(false);
                }
            });
        }

        function filtrarOpcionesMedico(text) {
            const query = text.toLowerCase().trim();
            const options = document.querySelectorAll('.medico-option-btn');
            const noFound = document.getElementById('noMedicosFound');
            let visibleCount = 0;

            options.forEach(opt => {
                const label = (opt.getAttribute('data-label') || '').toLowerCase();
                const val = (opt.getAttribute('data-value') || '').toLowerCase();
                const li = opt.parentElement;

                if (!query || label.includes(query) || val.includes(query)) {
                    if (li) li.style.display = '';
                    visibleCount++;
                } else {
                    if (li) li.style.display = 'none';
                }
            });

            if (noFound) {
                if (visibleCount === 0) noFound.classList.remove('hidden');
                else noFound.classList.add('hidden');
            }
        }

        function aplicarFiltros(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            cargarDatos();
            return false;
        }

        function limpiarFiltros() {
            document.getElementById('fecha_desde').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('fecha_hasta').value = '<?php echo date('Y-m-d'); ?>';
            
            const hiddenMedico = document.getElementById('medico');
            const labelMedico = document.getElementById('selectedMedicoLabel');
            if (hiddenMedico) hiddenMedico.value = '';
            if (labelMedico) labelMedico.textContent = '-- Todos los Médicos --';
            
            const options = document.querySelectorAll('.medico-option-btn');
            options.forEach((o, idx) => {
                const check = o.querySelector('.check-icon');
                if (idx === 0) {
                    o.classList.add('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                    if (check) check.classList.remove('hidden');
                } else {
                    o.classList.remove('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                    if (check) check.classList.add('hidden');
                }
            });

            document.getElementById('cruce').value = '';
            document.getElementById('tableSearch').value = '';
            filtroPestañaActual = '';
            filtroConceptoActual = '';
            actualizarEstilosPestañas();
            
            allData = [];
            filteredData = [];
            haConsultado = false;
            limpiarSeleccion();
            actualizarKPIs({});
            actualizarEstadoBotonesAccion();
            renderizarTabla();
        }

        function filtrarPestaña(tipo) {
            filtroPestañaActual = tipo;
            const elCruce = document.getElementById('cruce');
            if (elCruce) {
                if (tipo === 'NO_FACTURABLES' || tipo === 'EXCLUIDOS') {
                    elCruce.value = '';
                } else {
                    elCruce.value = tipo;
                }
            }
            actualizarEstilosPestañas();
            filtrarTablaEnMemoria();
        }

        function actualizarEstilosPestañas() {
            const btnAll      = document.getElementById('tabAll');
            const btnOK       = document.getElementById('tabOK');
            const btnProteo   = document.getElementById('tabProteo');
            const btnServinte = document.getElementById('tabServinte');
            const btnExcl     = document.getElementById('tabExcluidos');
            const btnNoFact   = document.getElementById('tabNoFacturables');

            const baseCls = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800";

            [btnAll, btnOK, btnProteo, btnServinte, btnExcl].forEach(b => {
                if (b) b.className = baseCls;
            });
            if (btnNoFact) {
                if (btnNoFact.dataset.visible === 'true') {
                    btnNoFact.className = baseCls;
                } else {
                    btnNoFact.className = "hidden " + baseCls;
                }
            }

            if (filtroPestañaActual === '') {
                if (btnAll) btnAll.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-primary text-white shadow-sm";
            } else if (filtroPestañaActual === 'CRUZADO') {
                if (btnOK) btnOK.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-emerald-600 text-white shadow-sm shadow-emerald-600/20";
            } else if (filtroPestañaActual === 'SOLO_PROTEO') {
                if (btnProteo) btnProteo.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-rose-600 text-white shadow-sm shadow-rose-600/20";
            } else if (filtroPestañaActual === 'SOLO_SERVINTE') {
                if (btnServinte) btnServinte.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-sky-600 text-white shadow-sm shadow-sky-600/20";
            } else if (filtroPestañaActual === 'EXCLUIDOS') {
                if (btnExcl) btnExcl.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-amber-600 text-white shadow-sm shadow-amber-600/20";
            } else if (filtroPestañaActual === 'NO_FACTURABLES') {
                if (btnNoFact) btnNoFact.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 bg-rose-600 text-white shadow-sm shadow-rose-600/20";
            }
        }

        const SwalCustom = Swal.mixin({
            customClass: {
                popup: 'bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl text-slate-100 font-sans p-6',
                title: 'text-lg font-black font-outfit text-white tracking-wide',
                htmlContainer: 'text-xs text-slate-300 font-medium leading-relaxed',
                confirmButton: 'px-5 py-2.5 rounded-xl bg-primary hover:bg-slate-800 dark:bg-tertiary dark:hover:bg-teal-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer mx-1 border border-white/10',
                cancelButton: 'px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-all cursor-pointer mx-1 border border-slate-700',
                input: 'bg-slate-800 border border-slate-700 text-white rounded-xl text-xs focus:ring-2 focus:ring-tertiary outline-none p-3'
            },
            buttonsStyling: false,
            showClass: {
                popup: 'animate__animated animate__fadeInDown animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutUp animate__faster'
            }
        });

        // --- CONFIGURACIÓN EN VIVO DE PARAFISCALES, PENSIONADOS Y RETENCIONES ---
        window.parafiscalesConfig = <?php echo json_encode($parafiscalesConfigMap); ?>;
        window.medicosParafiscalesMap = <?php echo json_encode($medicosParafiscalesMap); ?>;
        window.medicosAfcMap = <?php echo json_encode($medicosAfcMap ?? $medicosIbcMap); ?>;
        window.medicosIbcMap = <?php echo json_encode($medicosIbcMap); ?>;
        window.medicosPensionadosMap = <?php echo json_encode($medicosPensionadosMap); ?>;
        window.medicosRetencionesMap = <?php echo json_encode($medicosRetencionesMap); ?>;
        window.medicosRetencion383Map = <?php echo json_encode($medicosRetencion383Map); ?>;
        window.medicosArlMap = <?php echo json_encode($medicosArlMap); ?>;
        window.medicosEntidadMap = <?php echo json_encode($medicosEntidadMap); ?>;
        window.currentMedicoHasParafiscales = false;
        window.currentMedicoHasAfc = false;
        window.currentMedicoHasIbc = false;
        window.currentMedicoIsPensionado = false;
        window.currentMedicoHasRetenciones = false;
        window.currentMedicoHasRetencion383 = false;
        window.currentMedicoHasArl = false;

        // --- GESTIÓN DE SELECCIÓN Y EXCLUSIÓN JUSTIFICADA DE REGISTROS ---
        let selectedIds = new Set();
        let excludedRecordsMap = new Map(); // unique_id -> { motivo_exclusion, detalle_exclusion, fecha_exclusion }

        async function toggleSelectRow(uniqueId, isChecked) {
            const item = allData.find(x => (x.unique_id && x.unique_id === uniqueId) || x.id == uniqueId);
            if (!item) return;

            if (isChecked) {
                // Reincorporar registro a la liquidación
                selectedIds.add(uniqueId);
                excludedRecordsMap.delete(uniqueId);
                actualizarResumenSeleccion();
                actualizarEstadoFilasVisibles();
                renderizarTabla();
            } else {
                // Se intenta deschulear -> Solicitar justificación obligatoria
                const justificado = await solicitarJustificacionExclusion(item);
                if (justificado) {
                    selectedIds.delete(uniqueId);
                    actualizarResumenSeleccion();
                    actualizarEstadoFilasVisibles();
                    renderizarTabla();
                } else {
                    // Cancelado -> Revertir checkbox a marcado
                    selectedIds.add(uniqueId);
                    actualizarEstadoFilasVisibles();
                }
            }
        }

        async function solicitarJustificacionExclusion(item, isEditing = false) {
            const uId = item.unique_id || item.id;
            const prevExcl = excludedRecordsMap.get(uId) || {};
            const prevMotivo = prevExcl.motivo_exclusion || '';
            const prevDetalle = prevExcl.detalle_exclusion || '';

            const s = item.servinte || item.servinte_unmatched || null;
            const pacNombre = item.nombre || (s ? s.paciente : 'N/A');
            const pacDoc    = item.documento || (s ? `${s.tipo_doc || ''} ${s.identificacion || ''}`.trim() : 'N/A');
            const cupsExa   = item.cups || item.nombre_examen || (s ? s.examen : 'EXAMEN MÉDICO');
            const fueIng    = `Fuente ${item.fuente || '-'} / Ingreso ${item.ingreso || '-'}`;
            const valPagar  = `$${(item.valor_a_pagar || 0).toLocaleString('es-CO')}`;

            const { value: formValues } = await SwalCustom.fire({
                title: isEditing 
                    ? '<span class="flex items-center justify-center gap-2"><i class="fa-solid fa-pen-to-square text-amber-400"></i><span>Editar Justificación de Exclusión</span></span>' 
                    : '<span class="flex items-center justify-center gap-2"><i class="fa-solid fa-triangle-exclamation text-amber-400"></i><span>Justificación Obligatoria de Exclusión</span></span>',
                width: '640px',
                html: `
                    <div class="space-y-3.5 text-left my-2 font-sans">
                        <!-- Tarjeta Resumen del Examen -->
                        <div class="p-3.5 rounded-2xl bg-slate-800/90 border border-slate-700 text-xs space-y-2 shadow-inner">
                            <div class="flex justify-between items-start gap-2">
                                <div>
                                    <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Paciente</span>
                                    <span class="font-bold text-white text-xs">${htmlspecialchars(pacNombre)}</span>
                                    <span class="text-[10px] text-slate-400"> (Doc: ${htmlspecialchars(pacDoc)})</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Valor a Pagar</span>
                                    <span class="font-mono font-black text-emerald-400 text-sm">${valPagar}</span>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-700/70 text-[11px]">
                                <div>
                                    <span class="text-slate-400 font-medium">Examen / CUPS:</span>
                                    <span class="font-semibold text-slate-200 truncate block" title="${htmlspecialchars(cupsExa)}">${htmlspecialchars(cupsExa)}</span>
                                </div>
                                <div>
                                    <span class="text-slate-400 font-medium">Ubicación:</span>
                                    <span class="font-semibold text-slate-200 block">${htmlspecialchars(fueIng)}</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-2.5 rounded-xl bg-amber-950/40 border border-amber-800/60 text-amber-300 text-[11px] leading-relaxed flex items-center gap-2">
                            <span class="material-symbols-outlined text-base shrink-0 text-amber-400">info</span>
                            <span>Para omitir este registro de la liquidación, indique la causal correspondiente y detalle el motivo de auditoría.</span>
                        </div>

                        <!-- Causal de Exclusión -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                                Causal / Motivo de Exclusión <span class="text-rose-400">*</span>
                            </label>
                            <select id="swal_motivo_exclusion" class="w-full p-2.5 rounded-xl bg-slate-800 border border-slate-700 text-slate-100 text-xs font-semibold focus:border-tertiary focus:ring-1 focus:ring-tertiary outline-none">
                                <option value="">-- Seleccione una Causal --</option>
                                <option value="Examen no realizado / cancelado" ${prevMotivo === 'Examen no realizado / cancelado' ? 'selected' : ''}>Examen no realizado / cancelado</option>
                                <option value="Examen duplicado en sistema" ${prevMotivo === 'Examen duplicado en sistema' ? 'selected' : ''}>Examen duplicado en sistema</option>
                                <option value="Error de asignación de médico / no corresponde" ${prevMotivo === 'Error de asignación de médico / no corresponde' ? 'selected' : ''}>Error de asignación de médico / no corresponde</option>
                                <option value="Pendiente de refacturación / glosa" ${prevMotivo === 'Pendiente de refacturación / glosa' ? 'selected' : ''}>Pendiente de refacturación / glosa</option>
                                <option value="Tarifa o CUPS no convenido / en revisión" ${prevMotivo === 'Tarifa o CUPS no convenido / en revisión' ? 'selected' : ''}>Tarifa o CUPS no convenido / en revisión</option>
                                <option value="Lectura rechazada / sin informe válido" ${prevMotivo === 'Lectura rechazada / sin informe válido' ? 'selected' : ''}>Lectura rechazada / sin informe válido</option>
                                <option value="Exclusión manual por auditoría médica" ${prevMotivo === 'Exclusión manual por auditoría médica' ? 'selected' : ''}>Exclusión manual por auditoría médica</option>
                                <option value="Otro motivo" ${prevMotivo === 'Otro motivo' ? 'selected' : ''}>Otro motivo (especificar en detalle)</option>
                            </select>
                        </div>

                        <!-- Detalle Explicativo -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                                Detalle y Justificación de la Exclusión <span class="text-rose-400">*</span>
                            </label>
                            <textarea id="swal_detalle_exclusion" rows="3" placeholder="Explique detalladamente la razón de auditoría por la cual se excluye este examen de la liquidación..."
                                class="w-full p-3 bg-slate-800 border border-slate-700 rounded-xl text-xs font-medium text-slate-100 placeholder-slate-500 focus:border-tertiary focus:ring-1 focus:ring-tertiary outline-none resize-none leading-relaxed">${htmlspecialchars(prevDetalle)}</textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-ban mr-1.5"></i> ' + (isEditing ? 'Guardar Cambios' : 'Confirmar Exclusión'),
                cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cancelar (Mantener Incluido)',
                preConfirm: () => {
                    const motivo = document.getElementById('swal_motivo_exclusion').value.trim();
                    const detalle = document.getElementById('swal_detalle_exclusion').value.trim();

                    if (!motivo) {
                        Swal.showValidationMessage('Debe seleccionar una causal de exclusión obligatoria.');
                        return false;
                    }
                    if (detalle.length < 5) {
                        Swal.showValidationMessage('Debe ingresar un detalle explicativo de al menos 5 caracteres.');
                        return false;
                    }
                    return { motivo, detalle };
                }
            });

            if (formValues) {
                excludedRecordsMap.set(uId, {
                    motivo_exclusion: formValues.motivo,
                    detalle_exclusion: formValues.detalle,
                    fecha_exclusion: new Date().toISOString()
                });
                return true;
            }

            return false;
        }

        async function editarJustificacionExclusion(uniqueId) {
            const item = allData.find(x => (x.unique_id && x.unique_id === uniqueId) || x.id == uniqueId);
            if (!item) return;
            await solicitarJustificacionExclusion(item, true);
            actualizarResumenSeleccion();
            renderizarTabla();
        }

        async function toggleSelectAllPage(isChecked) {
            const total = filteredData.length;
            if (total === 0) return;
            const totalPaginas = Math.ceil(total / registrosPorPagina) || 1;
            if (paginaActual > totalPaginas) paginaActual = totalPaginas;

            const inicio = (paginaActual - 1) * registrosPorPagina;
            const fin = Math.min(inicio + registrosPorPagina, total);
            const paginaItems = filteredData.slice(inicio, fin);

            if (isChecked) {
                // Reincorporar registros de la página
                paginaItems.forEach(item => {
                    const uId = item.unique_id || item.id;
                    selectedIds.add(uId);
                    excludedRecordsMap.delete(uId);
                });
                actualizarResumenSeleccion();
                renderizarTabla();
            } else {
                // Desmarcar todos los de la página -> Justificación obligatoria
                const { value: formValues } = await SwalCustom.fire({
                    title: '<span class="flex items-center justify-center gap-2"><i class="fa-solid fa-triangle-exclamation text-amber-400"></i><span>Exclusión de Página Actual</span></span>',
                    width: '620px',
                    html: `
                        <div class="space-y-3 text-left my-2 font-sans">
                            <p class="text-xs text-amber-300 font-semibold leading-relaxed">
                                Se excluirán <strong>${paginaItems.length} registros</strong> de la página actual. Ingrese la justificación general que aplicará a todos ellos:
                            </p>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                                    Causal / Motivo General <span class="text-rose-400">*</span>
                                </label>
                                <select id="swal_motivo_masivo" class="w-full p-2.5 rounded-xl bg-slate-800 border border-slate-700 text-slate-100 text-xs font-semibold focus:border-tertiary focus:ring-1 focus:ring-tertiary outline-none">
                                    <option value="">-- Seleccione una Causal --</option>
                                    <option value="Exclusión manual por auditoría médica">Exclusión manual por auditoría médica</option>
                                    <option value="Examen no realizado / cancelado">Examen no realizado / cancelado</option>
                                    <option value="Error de asignación de médico / no corresponde">Error de asignación de médico / no corresponde</option>
                                    <option value="Pendiente de refacturación / glosa">Pendiente de refacturación / glosa</option>
                                    <option value="Tarifa o CUPS no convenido / en revisión">Tarifa o CUPS no convenido / en revisión</option>
                                    <option value="Otro motivo">Otro motivo (especificar en detalle)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                                    Detalle General de Exclusión <span class="text-rose-400">*</span>
                                </label>
                                <textarea id="swal_detalle_masivo" rows="3" placeholder="Explique la razón de la exclusión colectiva..."
                                    class="w-full p-3 bg-slate-800 border border-slate-700 rounded-xl text-xs font-medium text-slate-100 placeholder-slate-500 focus:border-tertiary focus:ring-1 focus:ring-tertiary outline-none resize-none leading-relaxed"></textarea>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-ban mr-1.5"></i> Excluir Registros',
                    cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cancelar (Mantener Incluidos)',
                    preConfirm: () => {
                        const motivo = document.getElementById('swal_motivo_masivo').value.trim();
                        const detalle = document.getElementById('swal_detalle_masivo').value.trim();
                        if (!motivo) {
                            Swal.showValidationMessage('Debe seleccionar una causal obligatoria.');
                            return false;
                        }
                        if (detalle.length < 5) {
                            Swal.showValidationMessage('Debe ingresar un detalle de al menos 5 caracteres.');
                            return false;
                        }
                        return { motivo, detalle };
                    }
                });

                if (formValues) {
                    paginaItems.forEach(item => {
                        const uId = item.unique_id || item.id;
                        selectedIds.delete(uId);
                        excludedRecordsMap.set(uId, {
                            motivo_exclusion: formValues.motivo,
                            detalle_exclusion: formValues.detalle,
                            fecha_exclusion: new Date().toISOString()
                        });
                    });
                    actualizarResumenSeleccion();
                    renderizarTabla();
                } else {
                    actualizarMasterCheckbox();
                }
            }
        }

        function seleccionarTodosVisibles() {
            toggleSelectAllPage(true);
        }

        function seleccionarTodosFiltrados() {
            filteredData.forEach(item => {
                const uId = item.unique_id || item.id;
                selectedIds.add(uId);
                excludedRecordsMap.delete(uId);
            });
            actualizarResumenSeleccion();
            renderizarTabla();
        }

        async function limpiarSeleccion() {
            if (allData.length === 0) {
                selectedIds.clear();
                excludedRecordsMap.clear();
                actualizarResumenSeleccion();
                return;
            }

            const { value: formValues } = await SwalCustom.fire({
                title: '<span class="flex items-center justify-center gap-2"><i class="fa-solid fa-triangle-exclamation text-rose-400"></i><span>Exclusión de Todos los Registros</span></span>',
                width: '620px',
                html: `
                    <div class="space-y-3 text-left my-2 font-sans">
                        <p class="text-xs text-rose-300 font-semibold leading-relaxed">
                            Está a punto de desmarcar y excluir la totalidad de los registros (${allData.length} exámenes). Ingrese la justificación general obligatoria:
                        </p>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                                Causal / Motivo General <span class="text-rose-400">*</span>
                            </label>
                            <select id="swal_motivo_total" class="w-full p-2.5 rounded-xl bg-slate-800 border border-slate-700 text-slate-100 text-xs font-semibold focus:border-tertiary focus:ring-1 focus:ring-tertiary outline-none">
                                <option value="">-- Seleccione una Causal --</option>
                                <option value="Exclusión manual por auditoría médica">Exclusión manual por auditoría médica</option>
                                <option value="Examen no realizado / cancelado">Examen no realizado / cancelado</option>
                                <option value="Error de asignación de médico / no corresponde">Error de asignación de médico / no corresponde</option>
                                <option value="Pendiente de refacturación / glosa">Pendiente de refacturación / glosa</option>
                                <option value="Otro motivo">Otro motivo (especificar en detalle)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-300 uppercase tracking-wider mb-1">
                                Detalle General de Exclusión <span class="text-rose-400">*</span>
                            </label>
                            <textarea id="swal_detalle_total" rows="3" placeholder="Explique detalladamente la razón de la exclusión masiva..."
                                class="w-full p-3 bg-slate-800 border border-slate-700 rounded-xl text-xs font-medium text-slate-100 placeholder-slate-500 focus:border-tertiary focus:ring-1 focus:ring-tertiary outline-none resize-none leading-relaxed"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-ban mr-1.5"></i> Excluir Todos',
                cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cancelar (Mantener Incluidos)',
                preConfirm: () => {
                    const motivo = document.getElementById('swal_motivo_total').value.trim();
                    const detalle = document.getElementById('swal_detalle_total').value.trim();
                    if (!motivo) {
                        Swal.showValidationMessage('Debe seleccionar una causal obligatoria.');
                        return false;
                    }
                    if (detalle.length < 5) {
                        Swal.showValidationMessage('Debe ingresar un detalle de al menos 5 caracteres.');
                        return false;
                    }
                    return { motivo, detalle };
                }
            });

            if (formValues) {
                selectedIds.clear();
                allData.forEach(item => {
                    const uId = item.unique_id || item.id;
                    excludedRecordsMap.set(uId, {
                        motivo_exclusion: formValues.motivo,
                        detalle_exclusion: formValues.detalle,
                        fecha_exclusion: new Date().toISOString()
                    });
                });
                const chkMaster = document.getElementById('selectAllCheckbox');
                if (chkMaster) {
                    chkMaster.checked = false;
                    chkMaster.indeterminate = false;
                }
                actualizarResumenSeleccion();
                renderizarTabla();
            } else {
                actualizarMasterCheckbox();
            }
        }

        function actualizarEstadoFilasVisibles() {
            const rows = document.querySelectorAll('#tableBody tr');
            rows.forEach(tr => {
                const chk = tr.querySelector('.row-checkbox');
                if (chk) {
                    const uId = chk.getAttribute('data-id');
                    const isSel = selectedIds.has(uId);
                    const isExcl = excludedRecordsMap.has(uId);
                    chk.checked = isSel;
                    if (isSel) {
                        tr.className = 'bg-slate-200/80 dark:bg-slate-800/90 ring-1 ring-tertiary/40 font-medium transition-colors group cursor-pointer';
                    } else if (isExcl) {
                        tr.className = 'bg-amber-50/50 dark:bg-amber-950/20 border-l-4 border-amber-500 opacity-90 transition-colors group cursor-pointer';
                    } else {
                        tr.className = 'hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors group cursor-pointer';
                    }
                }
            });
            actualizarMasterCheckbox();
        }

        function actualizarMasterCheckbox() {
            const chkMaster = document.getElementById('selectAllCheckbox');
            if (!chkMaster) return;

            const total = filteredData.length;
            if (total === 0) {
                chkMaster.checked = false;
                chkMaster.indeterminate = false;
                return;
            }

            const inicio = (paginaActual - 1) * registrosPorPagina;
            const fin = Math.min(inicio + registrosPorPagina, total);
            const paginaItems = filteredData.slice(inicio, fin);

            if (paginaItems.length === 0) {
                chkMaster.checked = false;
                chkMaster.indeterminate = false;
                return;
            }

            let countSelectedInPage = 0;
            paginaItems.forEach(item => {
                const uId = item.unique_id || item.id;
                if (selectedIds.has(uId)) countSelectedInPage++;
            });

            if (countSelectedInPage === 0) {
                chkMaster.checked = false;
                chkMaster.indeterminate = false;
            } else if (countSelectedInPage === paginaItems.length) {
                chkMaster.checked = true;
                chkMaster.indeterminate = false;
            } else {
                chkMaster.checked = false;
                chkMaster.indeterminate = true;
            }
        }

        function initDraggableFloatingBar() {
            const bar = document.getElementById('floatingSelectionBar');
            if (!bar) return;

            let isDragging = false;
            let startX = 0, startY = 0;
            let initialLeft = 0, initialTop = 0;

            bar.addEventListener('mousedown', startDrag);
            bar.addEventListener('touchstart', startDrag, { passive: false });

            function startDrag(e) {
                if (e.target.closest('button, a, input, select, textarea, label')) return;

                isDragging = true;
                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                const clientY = e.touches ? e.touches[0].clientY : e.clientY;
                startX = clientX;
                startY = clientY;

                const rect = bar.getBoundingClientRect();
                initialLeft = rect.left;
                initialTop = rect.top;

                bar.style.transition = 'none';
                bar.style.bottom = 'auto';
                bar.style.right = 'auto';
                bar.style.left = `${initialLeft}px`;
                bar.style.top = `${initialTop}px`;
                bar.style.transform = 'none';
                bar.classList.remove('-translate-x-1/2');
                bar.dataset.dragged = 'true';

                document.body.classList.add('select-none');
                document.addEventListener('mousemove', onDrag);
                document.addEventListener('touchmove', onDrag, { passive: false });
                document.addEventListener('mouseup', stopDrag);
                document.addEventListener('touchend', stopDrag);
            }

            function onDrag(e) {
                if (!isDragging) return;
                if (e.cancelable) e.preventDefault();

                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                const clientY = e.touches ? e.touches[0].clientY : e.clientY;
                const dx = clientX - startX;
                const dy = clientY - startY;

                let newX = initialLeft + dx;
                let newY = initialTop + dy;

                const rect = bar.getBoundingClientRect();
                newX = Math.max(10, Math.min(window.innerWidth - rect.width - 10, newX));
                newY = Math.max(10, Math.min(window.innerHeight - rect.height - 10, newY));

                bar.style.left = `${newX}px`;
                bar.style.top = `${newY}px`;
            }

            function stopDrag() {
                if (!isDragging) return;
                isDragging = false;
                bar.style.transition = '';
                document.body.classList.remove('select-none');
                document.removeEventListener('mousemove', onDrag);
                document.removeEventListener('touchmove', onDrag);
                document.removeEventListener('mouseup', stopDrag);
                document.removeEventListener('touchend', stopDrag);
            }
        }

        function resetearPosicionFloatingBar() {
            const bar = document.getElementById('floatingSelectionBar');
            if (!bar) return;
            bar.style.transition = '';
            bar.style.left = '';
            bar.style.top = '';
            bar.style.bottom = '';
            bar.style.right = '';
            bar.style.transform = '';
            bar.style.opacity = '';
            bar.style.pointerEvents = '';
            bar.classList.add('-translate-x-1/2');
            delete bar.dataset.dragged;
        }

        function actualizarResumenSeleccion() {
            const selCount = selectedIds.size;
            const exclCount = excludedRecordsMap.size;
            const floatBar = document.getElementById('floatingSelectionBar');

            if (selCount === 0 && exclCount === 0) {
                if (floatBar) {
                    if (floatBar.dataset.dragged === 'true') {
                        floatBar.style.opacity = '0';
                        floatBar.style.pointerEvents = 'none';
                    } else {
                        floatBar.classList.add('translate-y-32', 'opacity-0', 'pointer-events-none');
                    }
                }
                actualizarMasterCheckbox();
                return;
            }

            // Calcular métricas exclusivamente de los registros seleccionados
            let totalPagar = 0;
            let totalExamen = 0;
            let cruzadosOK = 0;
            let soloProteo = 0;
            let soloServinte = 0;

            allData.forEach(item => {
                const uId = item.unique_id || item.id;
                if (selectedIds.has(uId)) {
                    totalPagar += (parseFloat(item.valor_a_pagar) || 0);

                    const s = item.servinte || item.servinte_unmatched;
                    if (s && s.total !== undefined) {
                        totalExamen += (parseFloat(s.total) || 0);
                    }

                    if (item.cruce === 'CRUZADO') cruzadosOK++;
                    else if (item.cruce === 'SOLO_PROTEO') soloProteo++;
                    else if (item.cruce === 'SOLO_SERVINTE') soloServinte++;
                }
            });

            const totalPagarFmt = '$' + totalPagar.toLocaleString('es-CO');
            const totalExamenFmt = '$' + totalExamen.toLocaleString('es-CO');

            // Actualizar Floating Bar (Fijado abajo o arrastrado)
            if (floatBar) {
                if (floatBar.dataset.dragged === 'true') {
                    floatBar.style.opacity = '1';
                    floatBar.style.pointerEvents = 'auto';
                } else {
                    floatBar.classList.remove('translate-y-32', 'opacity-0', 'pointer-events-none');
                }
                const elCount = document.getElementById('floatSelectedCount');
                const elPagar = document.getElementById('floatSelectedPagar');
                const elExamen = document.getElementById('floatSelectedExamen');
                const elFiltrados = document.getElementById('floatTotalFiltrados');
                const elOK = document.getElementById('floatCruzadosOK');
                const elProt = document.getElementById('floatSoloProteo');
                const elServ = document.getElementById('floatSoloServinte');
                const elExcl = document.getElementById('floatExcluidos');

                if (elCount) elCount.textContent = selCount.toLocaleString();
                if (elPagar) elPagar.textContent = totalPagarFmt;
                if (elExamen) elExamen.textContent = totalExamenFmt;
                if (elFiltrados) elFiltrados.textContent = filteredData.length.toLocaleString();
                if (elOK) elOK.textContent = cruzadosOK.toLocaleString();
                if (elProt) elProt.textContent = soloProteo.toLocaleString();
                if (elServ) elServ.textContent = soloServinte.toLocaleString();
                if (elExcl) elExcl.textContent = exclCount.toLocaleString();
            }

            actualizarMasterCheckbox();
        }

        function exportarSeleccionadosAExcel() {
            if (selectedIds.size === 0) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Selección', text: 'Selecciona al menos un registro con el checkbox para exportar.' });
                return;
            }

            const itemsToExport = allData.filter(item => selectedIds.has(item.unique_id || item.id));
            if (itemsToExport.length === 0) return;

            // Métricas y contadores de los seleccionados
            let totalRegs = itemsToExport.length;
            let totalCantidad = 0;
            let totalValorServinte = 0;
            let totalValorPagar = 0;
            let totalCruzados = 0;
            let totalSoloProteo = 0;
            let totalSoloServinte = 0;
            let cruzadosValServ = 0, cruzadosValPag = 0;
            let soloProtValServ = 0, soloProtValPag = 0;
            let soloServValServ = 0, soloServValPag = 0;
            let totalTomosContrastadas = 0;
            const conceptosMap = {};

            itemsToExport.forEach(r => {
                const s = r.servinte || (r.servinte_unmatched || {});
                const cant = Math.max(1, parseInt(r.cantidad || 1, 10));
                const vServ = parseFloat(s.total || 0);
                const vPag = parseFloat(r.valor_a_pagar || 0);

                totalCantidad += cant;
                totalValorServinte += vServ;
                totalValorPagar += vPag;

                if (r.cruce === 'CRUZADO') {
                    totalCruzados++;
                    cruzadosValServ += vServ;
                    cruzadosValPag += vPag;
                } else if (r.cruce === 'SOLO_PROTEO') {
                    totalSoloProteo++;
                    soloProtValServ += vServ;
                    soloProtValPag += vPag;
                } else if (r.cruce === 'SOLO_SERVINTE') {
                    totalSoloServinte++;
                    soloServValServ += vServ;
                    soloServValPag += vPag;
                }

                if (r.es_tomografia_contrastada) {
                    totalTomosContrastadas += cant;
                }

                const cKey = r.concepto || 'OTROS';
                const cNom = r.concepto_nombre || cKey;
                if (!conceptosMap[cKey]) {
                    conceptosMap[cKey] = { codigo: cKey, nombre: cNom, cantidad: 0, registros: 0, valor_examen: 0, valor_pagar: 0 };
                }
                conceptosMap[cKey].cantidad += cant;
                conceptosMap[cKey].registros += 1;
                conceptosMap[cKey].valor_examen += vServ;
                conceptosMap[cKey].valor_pagar += vPag;
            });

            // Bonificación por Tomografías Contrastadas
            const boniTomoCant = Math.floor(totalTomosContrastadas / 50);
            const boniTomoValor = boniTomoCant * 150000;
            if (boniTomoCant > 0) {
                totalValorPagar += boniTomoValor;
                conceptosMap['BONI_TOHO'] = {
                    codigo: 'BONI_TOHO',
                    nombre: 'Bonificación Tomografías Contrastadas (Regla 50x$150.000)',
                    cantidad: boniTomoCant,
                    registros: 1,
                    valor_examen: 0,
                    valor_pagar: boniTomoValor,
                    es_bonificacion: true
                };
            }

            const conceptosList = Object.values(conceptosMap).sort((a, b) => b.cantidad - a.cantidad);
            const pctPagoTotal = totalValorServinte > 0 ? ((totalValorPagar / totalValorServinte) * 100).toFixed(1) : '0.0';
            const entidadNombre = <?php echo json_encode($entidadActivaNombre); ?>;

            let tableHtml = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head>
                    <meta charset="utf-8"/>
                    <style>
                        body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; color: #1e293b; }
                        table { border-collapse: collapse; margin-bottom: 20px; width: 100%; }
                        th { background-color: #0f172a; color: #ffffff; padding: 7px 10px; border: 1px solid #94a3b8; font-weight: bold; font-size: 10pt; text-align: left; }
                        td { padding: 6px 9px; border: 1px solid #cbd5e1; font-size: 10pt; vertical-align: middle; }
                        .th-sub { background-color: #1e293b; color: #ffffff; font-weight: bold; padding: 6px 10px; border: 1px solid #475569; font-size: 10pt; }
                        .th-cant { background-color: #047857; color: #ffffff; font-weight: bold; text-align: center; }
                        .title-main { font-size: 15pt; font-weight: bold; color: #0f172a; padding: 4px 0; }
                        .title-sub { font-size: 10pt; color: #475569; }
                        .section-header { font-size: 12pt; font-weight: bold; color: #0f172a; margin-top: 15px; margin-bottom: 6px; }
                        .kpi-title { background-color: #0f172a; color: #f8fafc; font-weight: bold; text-align: center; font-size: 9pt; padding: 6px 4px; }
                        .kpi-val { background-color: #f8fafc; font-weight: bold; font-size: 13pt; text-align: center; color: #0f172a; padding: 8px 4px; }
                        .kpi-sub { background-color: #f1f5f9; font-size: 8pt; text-align: center; color: #64748b; padding: 4px; }
                        .kpi-bono-val { background-color: #fefce8; color: #a16207; font-weight: bold; font-size: 12pt; text-align: center; padding: 8px 4px; }
                        .total-row { background-color: #e2e8f0; font-weight: bold; border-top: 2px solid #0f172a; }
                        .cruzado { background-color: #dcfce7; color: #166534; font-weight: bold; text-align: center; }
                        .solo-proteo { background-color: #fee2e2; color: #991b1b; font-weight: bold; text-align: center; }
                        .solo-servinte { background-color: #e0f2fe; color: #0369a1; font-weight: bold; text-align: center; }
                        .boni-row { background-color: #fef9c3; font-weight: bold; }
                        .num { text-align: right; mso-number-format: "\\$#,##0"; }
                        .cant { text-align: center; font-weight: bold; mso-number-format: "#,##0"; }
                        .pct { text-align: right; mso-number-format: "0\\.0%"; }
                        .center { text-align: center; }
                    </style>
                </head>
                <body>
                    <!-- 1. Encabezado principal -->
                    <table style="border: none; margin-bottom: 12px;">
                        <tr><td colspan="18" class="title-main" style="border: none;">REPORTE DE EXÁMENES SELECCIONADOS (PROTEO VS SERVINTE)</td></tr>
                        <tr><td colspan="18" class="title-sub" style="border: none;"><strong>Entidad / IPS:</strong> ${htmlspecialchars(entidadNombre)} &nbsp;|&nbsp; <strong>Fecha de Generación:</strong> ${new Date().toLocaleString('es-CO')}</td></tr>
                        <tr><td colspan="18" class="title-sub" style="border: none;"><strong>Total Registros Seleccionados:</strong> ${totalRegs}</td></tr>
                    </table>

                    <!-- 2. Tarjetas / Bloque de Contadores Generales (KPIs) -->
                    <table style="border: 1px solid #cbd5e1; margin-bottom: 18px;">
                        <tr>
                            <th class="kpi-title" style="width: 16%;">TOTAL REGISTROS</th>
                            <th class="kpi-title" style="width: 16%;">TOTAL CANTIDAD ESTUDIOS</th>
                            <th class="kpi-title" style="width: 17%;">MONTO EXAMEN (SERVINTE)</th>
                            <th class="kpi-title" style="width: 17%;">MONTO A PAGAR (TARIFARIO)</th>
                            <th class="kpi-title" style="width: 14%;">% MONTO A PAGAR</th>
                            <th class="kpi-title" style="width: 20%;">BONIFICACIÓN TAC CONTRASTADAS</th>
                        </tr>
                        <tr>
                            <td class="kpi-val">${totalRegs.toLocaleString('es-CO')}</td>
                            <td class="kpi-val cant" style="color: #047857; font-size: 13pt;">${totalCantidad.toLocaleString('es-CO')}</td>
                            <td class="kpi-val num" style="font-size: 13pt;">$${Math.round(totalValorServinte).toLocaleString('es-CO')}</td>
                            <td class="kpi-val num" style="color: #047857; font-size: 13pt;">$${Math.round(totalValorPagar).toLocaleString('es-CO')}</td>
                            <td class="kpi-val pct" style="font-size: 13pt;">${pctPagoTotal}%</td>
                            <td class="kpi-bono-val">
                                ${boniTomoCant > 0 
                                    ? `${boniTomoCant} bono(s) (+$${boniTomoValor.toLocaleString('es-CO')})<br/><span style="font-size: 8pt; font-weight: normal; color: #854d0e;">(${totalTomosContrastadas} Contrastadas acumuladas)</span>`
                                    : `0 bonos ($0)<br/><span style="font-size: 8pt; font-weight: normal; color: #64748b;">(${totalTomosContrastadas}/50 Contrastadas)</span>`}
                            </td>
                        </tr>
                        <tr>
                            <td class="kpi-sub">Registros Seleccionados</td>
                            <td class="kpi-sub">Unidades Totales Seleccionadas</td>
                            <td class="kpi-sub">Facturado en Servinte</td>
                            <td class="kpi-sub">Tarifario LIHO + Bonos</td>
                            <td class="kpi-sub">Sobre Facturación Servinte</td>
                            <td class="kpi-sub">Regla: Cada 50 TAC Contrastadas = $150.000 COP</td>
                        </tr>
                    </table>

                    <!-- 3. Resumen por Concepto / Modalidad -->
                    <p class="section-header">RESUMEN POR CONCEPTO / MODALIDAD (CONTADORES Y MONTOS)</p>
                    <table style="border: 1px solid #cbd5e1; margin-bottom: 18px;">
                        <thead>
                            <tr>
                                <th class="th-sub" style="width: 28%;">Concepto / Modalidad</th>
                                <th class="th-sub center" style="width: 10%;">Código</th>
                                <th class="th-sub center" style="width: 14%; background-color: #047857;">Cantidad de Estudios</th>
                                <th class="th-sub center" style="width: 12%;">N° Registros</th>
                                <th class="th-sub" style="width: 18%; text-align: right;">Monto Facturado Servinte</th>
                                <th class="th-sub" style="width: 18%; text-align: right;">Monto a Pagar (Tarifario)</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            conceptosList.forEach(c => {
                const isBoni = !!c.es_bonificacion;
                tableHtml += `
                    <tr class="${isBoni ? 'boni-row' : ''}">
                        <td><strong>${htmlspecialchars(c.nombre)}</strong></td>
                        <td class="center">${htmlspecialchars(c.codigo)}</td>
                        <td class="cant">${c.cantidad.toLocaleString('es-CO')}</td>
                        <td class="center">${c.registros.toLocaleString('es-CO')}</td>
                        <td class="num">$${Math.round(c.valor_examen).toLocaleString('es-CO')}</td>
                        <td class="num">$${Math.round(c.valor_pagar).toLocaleString('es-CO')}</td>
                    </tr>
                `;
            });

            tableHtml += `
                            <tr class="total-row">
                                <td colspan="2"><strong>TOTALES CONSOLIDADOS POR CONCEPTO</strong></td>
                                <td class="cant" style="font-size: 11pt;">${(totalCantidad + boniTomoCant).toLocaleString('es-CO')}</td>
                                <td class="center" style="font-size: 11pt;">${(totalRegs + (boniTomoCant > 0 ? 1 : 0)).toLocaleString('es-CO')}</td>
                                <td class="num" style="font-size: 11pt;"><strong>$${Math.round(totalValorServinte).toLocaleString('es-CO')}</strong></td>
                                <td class="num" style="font-size: 11pt; color: #047857;"><strong>$${Math.round(totalValorPagar).toLocaleString('es-CO')}</strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 4. Resumen por Estado de Conciliación -->
                    <p class="section-header">RESUMEN POR ESTADO DE CONCILIACIÓN (CRUCE)</p>
                    <table style="border: 1px solid #cbd5e1; margin-bottom: 22px;">
                        <thead>
                            <tr>
                                <th class="th-sub" style="width: 30%;">Estado de Cruce</th>
                                <th class="th-sub center" style="width: 14%;">N° Registros</th>
                                <th class="th-sub center" style="width: 14%;">% Registros</th>
                                <th class="th-sub" style="width: 21%; text-align: right;">Monto Facturado Servinte</th>
                                <th class="th-sub" style="width: 21%; text-align: right;">Monto a Pagar (Tarifario)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="cruzado" style="padding: 2px 8px; border-radius: 4px;">CRUZADO (Proteo + Servinte)</span></td>
                                <td class="center"><strong>${totalCruzados.toLocaleString('es-CO')}</strong></td>
                                <td class="pct">${totalRegs > 0 ? ((totalCruzados / totalRegs) * 100).toFixed(1) : '0.0'}%</td>
                                <td class="num">$${Math.round(cruzadosValServ).toLocaleString('es-CO')}</td>
                                <td class="num">$${Math.round(cruzadosValPag).toLocaleString('es-CO')}</td>
                            </tr>
                            <tr>
                                <td><span class="solo-proteo" style="padding: 2px 8px; border-radius: 4px;">SOLO PROTEO (Sin Servinte)</span></td>
                                <td class="center"><strong>${totalSoloProteo.toLocaleString('es-CO')}</strong></td>
                                <td class="pct">${totalRegs > 0 ? ((totalSoloProteo / totalRegs) * 100).toFixed(1) : '0.0'}%</td>
                                <td class="num">$${Math.round(soloProtValServ).toLocaleString('es-CO')}</td>
                                <td class="num">$${Math.round(soloProtValPag).toLocaleString('es-CO')}</td>
                            </tr>
                            <tr>
                                <td><span class="solo-servinte" style="padding: 2px 8px; border-radius: 4px;">SOLO SERVINTE (Sin Proteo)</span></td>
                                <td class="center"><strong>${totalSoloServinte.toLocaleString('es-CO')}</strong></td>
                                <td class="pct">${totalRegs > 0 ? ((totalSoloServinte / totalRegs) * 100).toFixed(1) : '0.0'}%</td>
                                <td class="num">$${Math.round(soloServValServ).toLocaleString('es-CO')}</td>
                                <td class="num">$${Math.round(soloServValPag).toLocaleString('es-CO')}</td>
                            </tr>
                            <tr class="total-row">
                                <td><strong>TOTALES CONCILIACIÓN</strong></td>
                                <td class="center"><strong>${totalRegs.toLocaleString('es-CO')}</strong></td>
                                <td class="pct"><strong>100.0%</strong></td>
                                <td class="num"><strong>$${Math.round(totalValorServinte).toLocaleString('es-CO')}</strong></td>
                                <td class="num"><strong>$${Math.round(cruzadosValPag + soloProtValPag + soloServValPag).toLocaleString('es-CO')}</strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 5. Tabla Detallada con Columna Cantidad -->
                    <p class="section-header">DETALLE COMPLETO DE PROCEDIMIENTOS Y EXÁMENES</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Origen</th>
                                <th>ID Evento / Ref</th>
                                <th>Fuente</th>
                                <th>Ingreso</th>
                                <th>Tipo Paciente</th>
                                <th>Fecha</th>
                                <th>Sede</th>
                                <th>Médico (Proteo)</th>
                                <th>CUPS / Examen</th>
                                <th class="th-cant">Cantidad</th>
                                <th>Modalidad</th>
                                <th>Estado Proteo / Servinte</th>
                                <th>Estado Cruce</th>
                                <th>Paciente</th>
                                <th>Identificación</th>
                                <th>Entidad (EPS)</th>
                                <th style="text-align: right;">Valor del Examen</th>
                                <th style="text-align: right;">Valor a Pagar (Tarifario)</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            itemsToExport.forEach(r => {
                const s = r.servinte || (r.servinte_unmatched || {});
                const cruceClass = (r.cruce === 'CRUZADO') ? 'cruzado' : ((r.cruce === 'SOLO_SERVINTE') ? 'solo-servinte' : 'solo-proteo');
                const tipoPacRaw = ((s && s.tipo_paciente) ? s.tipo_paciente : (r.tipo_paciente || 'E')).toString().trim().toUpperCase();
                const tipoPacDisp = (tipoPacRaw === 'P' || tipoPacRaw === 'PARTICULAR') ? 'Particular' : 'Empresa';
                const docVal = r.documento || (s.identificacion ? `${s.tipo_doc || ''} ${s.identificacion}` : '');
                const pacVal = r.nombre || (s.paciente || 'N/A');
                const valExamen = (s && s.total !== undefined) ? `$${Math.round(Number(s.total || 0)).toLocaleString('es-CO')}` : '$0';
                const valPagar = `$${Math.round(Number(r.valor_a_pagar || 0)).toLocaleString('es-CO')}`;
                const cantItem = Math.max(1, parseInt(r.cantidad || 1, 10));

                let modText = r.modalidad || '';
                if (r.es_bloqueo_ho) {
                    const tLbl = r.bloqueo_tier_label ? r.bloqueo_tier_label + ' - ' : '';
                    modText = `BLOQUEO HO (${tLbl}${r.bloqueo_pct || 40}%)`;
                }

                tableHtml += `
                    <tr>
                        <td>${htmlspecialchars(r.origen || '')}</td>
                        <td>${htmlspecialchars(r.id || '')}</td>
                        <td>${htmlspecialchars(r.fuente || '')}</td>
                        <td>${htmlspecialchars(r.ingreso || '')}</td>
                        <td>${htmlspecialchars(tipoPacDisp)}</td>
                        <td>${htmlspecialchars(r.fecha || '')}</td>
                        <td>${htmlspecialchars(r.sede || '')}</td>
                        <td>${htmlspecialchars(r.usuario || '')}</td>
                        <td>${htmlspecialchars(r.cups || '')}</td>
                        <td class="cant">${cantItem}</td>
                        <td>${htmlspecialchars(modText)}</td>
                        <td>${htmlspecialchars(r.estado_actual || '')}</td>
                        <td class="${cruceClass}">${htmlspecialchars(r.cruce || '')}</td>
                        <td>${htmlspecialchars(pacVal)}</td>
                        <td>${htmlspecialchars(docVal)}</td>
                        <td>${htmlspecialchars(s.entidad || '-')}</td>
                        <td class="num">${valExamen}</td>
                        <td class="num">${valPagar}</td>
                    </tr>
                `;
            });

            if (boniTomoCant > 0) {
                tableHtml += `
                    <tr class="boni-row">
                        <td>LIHO</td>
                        <td>BONI_TOHO</td>
                        <td>LIHO</td>
                        <td>BONIFICACION</td>
                        <td>Incentivo</td>
                        <td>${new Date().toISOString().substring(0, 10)}</td>
                        <td>SEDE PRINCIPAL</td>
                        <td>MÉDICO LIHO</td>
                        <td>BONIFICACIÓN TOMOGRAFÍAS CONTRASTADAS (${totalTomosContrastadas} CONTRASTADAS / ${boniTomoCant}x$150.000 COP)</td>
                        <td class="cant">${boniTomoCant}</td>
                        <td>CT</td>
                        <td>BONIFICACIÓN</td>
                        <td class="cruzado">CRUZADO</td>
                        <td>INCENTIVO POR PRODUCTIVIDAD</td>
                        <td>N/A</td>
                        <td>LIHO IPS</td>
                        <td class="num">$0</td>
                        <td class="num">$${boniTomoValor.toLocaleString('es-CO')}</td>
                    </tr>
                `;
            }

            tableHtml += `
                            <tr class="total-row">
                                <td colspan="9" style="text-align: right; font-weight: bold; font-size: 11pt;">TOTALES (${(totalRegs + (boniTomoCant > 0 ? 1 : 0))} REGISTROS):</td>
                                <td class="cant" style="font-size: 11pt; color: #047857;">${(totalCantidad + boniTomoCant).toLocaleString('es-CO')}</td>
                                <td colspan="6"></td>
                                <td class="num" style="font-size: 11pt;"><strong>$${Math.round(totalValorServinte).toLocaleString('es-CO')}</strong></td>
                                <td class="num" style="font-size: 11pt; color: #047857;"><strong>$${Math.round(totalValorPagar).toLocaleString('es-CO')}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </body>
                </html>
            `;

            const blob = new Blob([tableHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `Reporte_Examenes_Seleccionados_${new Date().toISOString().slice(0, 10)}.xls`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        let isFetchingExamenesData = false;
        async function cargarDatos() {
            if (isFetchingExamenesData) return;
            isFetchingExamenesData = true;

            const submitBtn = document.getElementById('btnFiltrar') || document.querySelector('#filterForm button');
            let origSubmitHtml = '';
            if (submitBtn) {
                origSubmitHtml = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-70', 'cursor-wait');
                submitBtn.innerHTML = `<span class="material-symbols-outlined text-base animate-spin">sync</span><span>Consultando...</span>`;
            }

            haConsultado = true;
            selectedIds.clear();
            excludedRecordsMap.clear();

            const spinner = document.getElementById('tableLoading');
            if (spinner) spinner.classList.remove('hidden');

            const fDesde = document.getElementById('fecha_desde').value;
            const fHasta = document.getElementById('fecha_hasta').value;
            const medico = document.getElementById('medico').value;
            const cruce  = document.getElementById('cruce').value;

            const url = `gestion_medicos_procedimientos.php?action=fetch_data&fecha_desde=${encodeURIComponent(fDesde)}&fecha_hasta=${encodeURIComponent(fHasta)}&medico=${encodeURIComponent(medico)}&cruce=${encodeURIComponent(cruce)}`;

            try {
                const response = await fetch(url);
                const result = await response.json();

                if (!result.success) {
                    SwalCustom.fire({ icon: 'error', title: 'Error de Consulta', text: result.error || 'Error desconocido' });
                    allData = [];
                } else {
                    allData = (result.data || []).map((item, idx) => {
                        item.unique_id = item.unique_id || `${item.origen || 'P'}_${item.id || ''}_${item.fuente || ''}_${item.ingreso || ''}_${idx}`;
                        return item;
                    });

                    // Chulear los registros válidos por defecto (excluyendo no facturables)
                    allData.forEach(item => {
                        if (!item.no_facturable_imadinsa) {
                            selectedIds.add(item.unique_id);
                        }
                    });

                    actualizarKPIs(result.kpis || {});
                }
            } catch (err) {
                console.error("Error en petición AJAX:", err);
                SwalCustom.fire({ icon: 'error', title: 'Error de Conexión', text: 'Ocurrió un error al comunicar con el servidor.' });
                allData = [];
            } finally {
                if (spinner) spinner.classList.add('hidden');
                actualizarEstadoBotonesAccion();
                filtrarTablaEnMemoria();
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-70', 'cursor-wait');
                    submitBtn.innerHTML = origSubmitHtml;
                }
                isFetchingExamenesData = false;
            }
        }

        let filtroConceptoActual = '';

        function renderizarTarjetasConceptos(conceptosList) {
            const container = document.getElementById('kpiConceptosContainer');
            if (!container) return;

            const colorMap = {
                'BLOQ':      { bg: 'bg-amber-50 dark:bg-amber-950/40', text: 'text-amber-600 dark:text-amber-400', icon: 'syringe' },
                'RXSI':      { bg: 'bg-teal-50 dark:bg-teal-950/40', text: 'text-teal-600 dark:text-teal-400', icon: 'radiology' },
                'RXES':      { bg: 'bg-indigo-50 dark:bg-indigo-950/40', text: 'text-indigo-600 dark:text-indigo-400', icon: 'perm_media' },
                'ECOG':      { bg: 'bg-emerald-50 dark:bg-emerald-950/40', text: 'text-emerald-600 dark:text-emerald-400', icon: 'vital_signs' },
                'DOPP':      { bg: 'bg-sky-50 dark:bg-sky-950/40', text: 'text-sky-600 dark:text-sky-400', icon: 'water_drop' },
                'TOHO':      { bg: 'bg-amber-50 dark:bg-amber-950/40', text: 'text-amber-600 dark:text-amber-400', icon: 'view_in_ar' },
                'TOMO':      { bg: 'bg-amber-50 dark:bg-amber-950/40', text: 'text-amber-600 dark:text-amber-400', icon: 'view_in_ar' },
                'MAMO':      { bg: 'bg-rose-50 dark:bg-rose-950/40', text: 'text-rose-600 dark:text-rose-400', icon: 'attribution' },
                'BIOP':      { bg: 'bg-violet-50 dark:bg-violet-950/40', text: 'text-violet-600 dark:text-violet-400', icon: 'biotech' },
                'CONS':      { bg: 'bg-cyan-50 dark:bg-cyan-950/40', text: 'text-cyan-600 dark:text-cyan-400', icon: 'clinical_notes' },
                'RGCP':      { bg: 'bg-teal-50 dark:bg-teal-950/40', text: 'text-teal-600 dark:text-teal-400', icon: 'radiology' },
                'BONI_TOHO': { bg: 'bg-amber-100 dark:bg-amber-950/80', text: 'text-amber-700 dark:text-amber-300', icon: 'military_tech' },
                'OTROS':     { bg: 'bg-slate-100 dark:bg-slate-800', text: 'text-slate-600 dark:text-slate-300', icon: 'medical_services' }
            };

            if (!conceptosList || conceptosList.length === 0) {
                container.innerHTML = `
                    <div class="kpi-concepto-card bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group">
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase text-teal-600 dark:text-teal-400 tracking-wider">RX Simples (RXSI)</p>
                            <h3 class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                            <p class="text-[10px] text-slate-400 font-medium">$0 a pagar</p>
                        </div>
                        <div class="p-2.5 rounded-2xl bg-teal-50 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400">
                            <span class="material-symbols-outlined text-2xl">radiology</span>
                        </div>
                    </div>
                    <div class="kpi-concepto-card bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group">
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase text-emerald-600 dark:text-emerald-400 tracking-wider">Ecografías (ECOG)</p>
                            <h3 class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                            <p class="text-[10px] text-slate-400 font-medium">$0 a pagar</p>
                        </div>
                        <div class="p-2.5 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400">
                            <span class="material-symbols-outlined text-2xl">vital_signs</span>
                        </div>
                    </div>
                    <div class="kpi-concepto-card bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group">
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase text-sky-600 dark:text-sky-400 tracking-wider">Doppler (DOPP)</p>
                            <h3 class="text-2xl font-black font-outfit text-slate-900 dark:text-white">0</h3>
                            <p class="text-[10px] text-slate-400 font-medium">$0 a pagar</p>
                        </div>
                        <div class="p-2.5 rounded-2xl bg-sky-50 dark:bg-sky-900/30 text-sky-600 dark:text-sky-400">
                            <span class="material-symbols-outlined text-2xl">water_drop</span>
                        </div>
                    </div>
                `;
                return;
            }

            let cardsHtml = '';
            conceptosList.forEach(c => {
                const cKey = (c.codigo || 'OTROS').toUpperCase();
                const style = colorMap[cKey] || colorMap['OTROS'];
                const isSelected = (filtroConceptoActual === cKey);
                const isBoni = (c.es_bonificacion === true || cKey === 'BONI_TOHO');
                const ringClass = isSelected ? 'ring-2 ring-tertiary shadow-lg' : (isBoni ? 'border-amber-400/80 dark:border-amber-600 shadow-amber-500/10' : '');

                const badgeLabel = isBoni ? `<span class="bg-amber-200 dark:bg-amber-900 text-amber-900 dark:text-amber-100 px-1 py-0.5 rounded text-[8px] font-black mr-1 uppercase">Bono</span>` : '';
                const pagarPrefix = isBoni ? '+$' : '$';

                let counterHtml = '';
                if (isBoni) {
                    counterHtml = `
                        <div class="flex items-baseline gap-1">
                            <h3 class="text-2xl font-black font-outfit text-amber-600 dark:text-amber-400">${c.cantidad.toLocaleString('es-CO')}</h3>
                            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">bono(s)</span>
                        </div>
                    `;
                } else {
                    const numRegs = (c.registros || c.cantidad || 0);
                    const tieneDobles = (c.registros && c.cantidad > c.registros);
                    const diffDobles = c.cantidad - c.registros;
                    counterHtml = `
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <h3 class="text-2xl font-black font-outfit text-slate-900 dark:text-white leading-none">${numRegs.toLocaleString('es-CO')}</h3>
                            <span class="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500 font-sans tracking-wide">reg.</span>
                            ${tieneDobles ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-teal-50 dark:bg-teal-950/70 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800" title="${c.cantidad.toLocaleString('es-CO')} estudios liquidados (+${diffDobles.toLocaleString('es-CO')} adicionales por exámenes dobles/bilaterales)">
                                    <i class="fa-solid fa-layer-group text-[8px]"></i> ${c.cantidad.toLocaleString('es-CO')} est.
                                </span>
                            ` : ''}
                        </div>
                    `;
                }

                cardsHtml += `
                    <div class="kpi-concepto-card ${ringClass} bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-tertiary/60 transition-all cursor-pointer hover:scale-[1.02] active:scale-98" 
                        data-concepto="${cKey}"
                        onclick="filtrarPorConcepto('${cKey}')" 
                        title="${isBoni ? 'Hacer clic para filtrar y ver las tomografías contrastadas que generaron esta bonificación' : (c.registros || c.cantidad) + ' registros (' + c.cantidad + ' estudios liquidados). Clic para filtrar tabla por concepto ' + c.nombre}">
                        <div class="space-y-1 min-w-0 pr-1">
                            <p class="text-[10px] font-bold uppercase tracking-wider truncate ${style.text}">
                                ${badgeLabel}${htmlspecialchars(c.nombre)} <span class="font-mono opacity-80 font-normal">(${htmlspecialchars(c.codigo)})</span>
                            </p>
                            ${counterHtml}
                            <p class="text-[10px] ${style.text} font-semibold truncate">${pagarPrefix}${(c.valor_pagar || 0).toLocaleString('es-CO')} a pagar</p>
                        </div>
                        <div class="p-2.5 rounded-2xl ${style.bg} ${style.text} group-hover:scale-110 transition-transform duration-300 shrink-0">
                            <span class="material-symbols-outlined text-2xl">${style.icon}</span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = cardsHtml;
        }

        function filtrarPorConcepto(codigo) {
            if (filtroConceptoActual === codigo) {
                filtroConceptoActual = '';
            } else {
                filtroConceptoActual = codigo;
            }

            document.querySelectorAll('.kpi-concepto-card').forEach(card => {
                if (card.getAttribute('data-concepto') === filtroConceptoActual) {
                    card.classList.add('ring-2', 'ring-tertiary', 'shadow-lg');
                } else {
                    card.classList.remove('ring-2', 'ring-tertiary', 'shadow-lg');
                }
            });

            filtrarTablaEnMemoria();
        }

        function actualizarKPIs(kpis) {
            const elTotal = document.getElementById('kpiTotalExamenes');
            if (elTotal) elTotal.textContent = (kpis.total_examenes || 0).toLocaleString();

            const elSubTotal = document.getElementById('kpiSubTotalExamenes');
            if (elSubTotal) {
                if (kpis.total_unidades && kpis.total_unidades > kpis.total_examenes) {
                    elSubTotal.innerHTML = `<span class="text-teal-600 dark:text-teal-400 font-bold">${kpis.total_unidades.toLocaleString()} estudios</span> • Proteo + Servinte`;
                } else {
                    elSubTotal.textContent = 'Proteo + Servinte';
                }
            }

            const totalPagarFmt       = (kpis.total_valor_pagar || 0).toLocaleString('es-CO');
            const totalExamenFmt      = (kpis.total_valor || 0).toLocaleString('es-CO');
            const cruzadosPagarFmt    = (kpis.cruzados_pagar || 0).toLocaleString('es-CO');
            const soloProteoPagarFmt  = (kpis.solo_proteo_pagar || 0).toLocaleString('es-CO');
            const soloServintePagarFmt= (kpis.solo_servinte_pagar || 0).toLocaleString('es-CO');

            const cntAll  = document.getElementById('cntAll');
            const cntOK   = document.getElementById('cntOK');
            const cntProt = document.getElementById('cntProteo');
            const cntServ = document.getElementById('cntServinte');
            if (cntAll)  cntAll.textContent  = `${(kpis.total_examenes || 0).toLocaleString()} | $${totalPagarFmt}`;
            if (cntOK)   cntOK.textContent   = `${(kpis.total_cruzados || 0).toLocaleString()} | $${cruzadosPagarFmt}`;
            if (cntProt) cntProt.textContent= `${(kpis.total_solo_proteo || 0).toLocaleString()} | $${soloProteoPagarFmt}`;
            if (cntServ) cntServ.textContent= `${(kpis.total_solo_servinte || 0).toLocaleString()} | $${soloServintePagarFmt}`;

            let totalExcluidosVal = 0;
            let totalNoFacturables = 0;
            allData.forEach(item => {
                const uId = item.unique_id || item.id;
                if (excludedRecordsMap.has(uId)) {
                    totalExcluidosVal += (parseFloat(item.valor_a_pagar) || 0);
                }
                if (item.no_facturable_imadinsa) {
                    totalNoFacturables++;
                }
            });
            const elCntExcl = document.getElementById('cntExcluidos');
            if (elCntExcl) {
                elCntExcl.textContent = `${excludedRecordsMap.size.toLocaleString()} | $${totalExcluidosVal.toLocaleString('es-CO')}`;
            }

            const tabNoFact = document.getElementById('tabNoFacturables');
            const cntNoFact = document.getElementById('cntNoFacturables');
            if (tabNoFact && cntNoFact) {
                if (totalNoFacturables > 0) {
                    tabNoFact.dataset.visible = 'true';
                    tabNoFact.classList.remove('hidden');
                    cntNoFact.textContent = `${totalNoFacturables.toLocaleString()} | $0`;
                } else {
                    tabNoFact.dataset.visible = 'false';
                    tabNoFact.classList.add('hidden');
                    if (filtroPestañaActual === 'NO_FACTURABLES') {
                        filtroPestañaActual = '';
                        actualizarEstilosPestañas();
                    }
                }
            }

            const elValTotal = document.getElementById('kpiValorTotal');
            if (elValTotal) elValTotal.textContent = `$${totalExamenFmt}`;

            const elValPagar = document.getElementById('kpiValorPagar');
            if (elValPagar) elValPagar.textContent = `$${totalPagarFmt}`;

            const elSubValPagar = document.getElementById('kpiSubValorPagar');
            if (elSubValPagar) elSubValPagar.textContent = 'Tarifario LIHO';

            const pctGlobal = (kpis.porcentaje_pago_examen !== undefined) 
                ? Number(kpis.porcentaje_pago_examen).toFixed(1) 
                : (kpis.total_valor > 0 ? ((kpis.total_valor_pagar / kpis.total_valor) * 100).toFixed(1) : '0.0');

            const elPctPagar = document.getElementById('kpiPorcentajePagar');
            if (elPctPagar) elPctPagar.textContent = `${pctGlobal}%`;

            renderizarTarjetasConceptos(kpis.conceptos || []);
            actualizarBannerBloqueosHO(allData);
        }

        function actualizarBannerBloqueosHO(dataList) {
            const banner = document.getElementById('bannerCondicionesBloqueo');
            const lblCount = document.getElementById('lblBloqueosDetectados');
            if (!banner) return;

            const bloqueosCount = (dataList || []).filter(x => x.es_bloqueo_ho === true).length;
            if (bloqueosCount > 0) {
                banner.classList.remove('hidden');
                if (lblCount) {
                    lblCount.textContent = `${bloqueosCount.toLocaleString()} Bloqueo(s) liquidado(s)`;
                }
            } else {
                banner.classList.add('hidden');
            }
        }

        function filtrarTablaEnMemoria() {
            const query = document.getElementById('tableSearch').value.toLowerCase().trim();
            
            filteredData = allData.filter(item => {
                const uId = item.unique_id || item.id;

                if (filtroPestañaActual === 'EXCLUIDOS') {
                    if (!excludedRecordsMap.has(uId)) return false;
                } else if (filtroPestañaActual === 'NO_FACTURABLES') {
                    if (!item.no_facturable_imadinsa) return false;
                } else if (filtroPestañaActual !== '') {
                    if (item.cruce !== filtroPestañaActual) return false;
                }

                // Filtro activo por Concepto (clic en tarjeta)
                if (filtroConceptoActual !== '') {
                    if (filtroConceptoActual === 'BONI_TOHO') {
                        // Filtrar exclusivamente las tomografías contrastadas
                        if (!item.es_tomografia_contrastada) return false;
                    } else {
                        const cItem = (item.concepto || '').toUpperCase();
                        if (cItem !== filtroConceptoActual) return false;
                    }
                }

                if (!query) return true;

                const s = item.servinte || {};
                const excl = excludedRecordsMap.get(uId) || {};
                const text = [
                    item.usuario,
                    item.usuario_medico,
                    item.fuente,
                    item.ingreso,
                    item.cups,
                    item.detail || '',
                    item.modalidad,
                    item.sede,
                    item.nombre || '',
                    item.documento || '',
                    item.concepto || '',
                    item.concepto_nombre || '',
                    s.paciente || '',
                    s.identificacion || '',
                    s.examen || '',
                    s.entidad || '',
                    excl.motivo_exclusion || '',
                    excl.detalle_exclusion || ''
                ].join(' ').toLowerCase();

                return text.includes(query);
            });

            // Recalcular dinámicamente las tarjetas de Monto Examen, Monto a Pagar y % Participación para los datos filtrados en pantalla
            const totalValorFiltrado = filteredData.reduce((sum, item) => sum + (item.servinte ? (item.servinte.total || 0) : 0), 0);
            const totalPagarFiltrado = filteredData.reduce((sum, item) => sum + (item.valor_a_pagar || 0), 0);
            const pctPagarFiltrado   = totalValorFiltrado > 0 ? ((totalPagarFiltrado / totalValorFiltrado) * 100).toFixed(1) : '0.0';

            document.getElementById('kpiValorTotal').textContent = `$${totalValorFiltrado.toLocaleString('es-CO')}`;
            document.getElementById('kpiValorPagar').textContent = `$${totalPagarFiltrado.toLocaleString('es-CO')}`;
            
            const elPctPagar = document.getElementById('kpiPorcentajePagar');
            if (elPctPagar) elPctPagar.textContent = `${pctPagarFiltrado}%`;

            const elSubValPagar = document.getElementById('kpiSubValorPagar');
            if (elSubValPagar) {
                if (allData.length > 0 && filteredData.length < allData.length) {
                    const totalGeneralPagar = allData.reduce((sum, item) => sum + (item.valor_a_pagar || 0), 0);
                    elSubValPagar.innerHTML = `<span class="text-amber-600 dark:text-amber-400 font-bold">Filtrado (${filteredData.length.toLocaleString()} reg.)</span> • Total: $${totalGeneralPagar.toLocaleString('es-CO')}`;
                } else {
                    elSubValPagar.textContent = 'Tarifario LIHO';
                }
            }

            const badgeContainer = document.getElementById('activeFilterBadgeContainer');
            if (badgeContainer) {
                if (filtroConceptoActual !== '') {
                    badgeContainer.classList.remove('hidden');
                    badgeContainer.innerHTML = `<button type="button" onclick="filtrarPorConcepto('${filtroConceptoActual}')" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800 text-[11px] font-bold hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 transition-colors cursor-pointer shadow-xs" title="Clic para quitar filtro de concepto"><span>Concepto: ${filtroConceptoActual}</span><span class="material-symbols-outlined text-xs">close</span></button>`;
                } else {
                    badgeContainer.classList.add('hidden');
                    badgeContainer.innerHTML = '';
                }
            }

            let totalExcluidosVal = 0;
            allData.forEach(item => {
                const uId = item.unique_id || item.id;
                if (excludedRecordsMap.has(uId)) {
                    totalExcluidosVal += (parseFloat(item.valor_a_pagar) || 0);
                }
            });
            const elCntExcl = document.getElementById('cntExcluidos');
            if (elCntExcl) {
                elCntExcl.textContent = `${excludedRecordsMap.size.toLocaleString()} | $${totalExcluidosVal.toLocaleString('es-CO')}`;
            }

            paginaActual = 1;
            renderizarTabla();
            actualizarResumenSeleccion();
        }

        function renderizarTabla() {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';

            if (!haConsultado) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="12" class="py-16 text-center text-slate-400 dark:text-slate-500">
                            <div class="w-16 h-16 rounded-2xl bg-teal-50 dark:bg-slate-800 text-tertiary flex items-center justify-center mx-auto mb-3 border border-teal-100 dark:border-slate-700 shadow-sm">
                                <span class="material-symbols-outlined text-3xl">pageview</span>
                            </div>
                            <h4 class="font-extrabold text-base text-slate-800 dark:text-slate-200 mb-1">Consulta de Exámenes Médicos</h4>
                            <p class="text-xs text-slate-400 font-medium max-w-md mx-auto">Seleccione las fechas y filtros deseados arriba y haga clic en el botón <strong class="text-primary dark:text-tertiary">Consultar</strong> para realizar el cruce de datos entre Proteo y Servinte.</p>
                        </td>
                    </tr>
                `;
                document.getElementById('lblMostrandoCount').textContent = '0';
                document.getElementById('lblPaginaActual').textContent = '1';
                document.getElementById('lblTotalPaginas').textContent = '1';
                document.getElementById('btnPagPrev').disabled = true;
                document.getElementById('btnPagNext').disabled = true;
                actualizarMasterCheckbox();
                return;
            }

            const total = filteredData.length;
            document.getElementById('lblMostrandoCount').textContent = total.toLocaleString();

            if (total === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="12" class="py-12 text-center text-slate-400 dark:text-slate-500">
                            <span class="material-symbols-outlined text-4xl block mb-2 opacity-50">search_off</span>
                            <p class="font-bold text-sm">No se encontraron registros para el criterio seleccionado.</p>
                            <p class="text-xs">Prueba ajustando el rango de fechas o los filtros de búsqueda.</p>
                        </td>
                    </tr>
                `;
                document.getElementById('lblPaginaActual').textContent = '1';
                document.getElementById('lblTotalPaginas').textContent = '1';
                document.getElementById('btnPagPrev').disabled = true;
                document.getElementById('btnPagNext').disabled = true;
                actualizarMasterCheckbox();
                return;
            }

            const totalPaginas = Math.ceil(total / registrosPorPagina);
            if (paginaActual > totalPaginas) paginaActual = totalPaginas;

            const inicio = (paginaActual - 1) * registrosPorPagina;
            const fin = Math.min(inicio + registrosPorPagina, total);
            const paginaItems = filteredData.slice(inicio, fin);

            paginaItems.forEach(item => {
                const uniqueId = item.unique_id || item.id;
                const isSelected = selectedIds.has(uniqueId);
                const isExcluded = excludedRecordsMap.has(uniqueId);
                const exclData = isExcluded ? excludedRecordsMap.get(uniqueId) : null;

                const tr = document.createElement('tr');
                if (isSelected) {
                    tr.className = 'bg-slate-200/80 dark:bg-slate-800/90 ring-1 ring-tertiary/40 font-medium transition-colors group cursor-pointer';
                } else if (isExcluded) {
                    tr.className = 'bg-amber-50/60 dark:bg-amber-950/25 border-l-4 border-amber-500 font-medium transition-colors group cursor-pointer';
                } else {
                    tr.className = 'hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors group cursor-pointer';
                }

                const s = item.servinte || item.servinte_unmatched;

                let cruceBadge = '';
                let origenBadge = '';

                if (item.origen === 'PROTEO') {
                    origenBadge = `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-sky-100 text-sky-800 dark:bg-sky-950/80 dark:text-sky-300">PROTEO</span>`;
                } else {
                    origenBadge = `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-teal-100 text-teal-800 dark:bg-teal-950/80 dark:text-teal-300">SERVINTE</span>`;
                }

                if (item.cruce === 'CRUZADO') {
                    cruceBadge = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <span class="material-symbols-outlined text-xs">check_circle</span> Cruzado OK
                    </span>`;
                } else if (item.cruce === 'SOLO_PROTEO') {
                    cruceBadge = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                        <span class="material-symbols-outlined text-xs">cancel</span> Solo Proteo
                    </span>`;
                } else if (item.cruce === 'SOLO_SERVINTE') {
                    cruceBadge = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-sky-100 text-sky-800 dark:bg-sky-950/80 dark:text-sky-300 border border-sky-200 dark:border-sky-800">
                        <span class="material-symbols-outlined text-xs">local_hospital</span> Solo Servinte
                    </span>`;
                } else {
                    cruceBadge = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                        <span class="material-symbols-outlined text-xs">warning</span> Incompleto
                    </span>`;
                }

                const valorExamenFmt = (s && s.total !== undefined) ? `$${(s.total || 0).toLocaleString('es-CO')}` : '-';
                const valorPagarFmt  = `$${(item.valor_a_pagar || 0).toLocaleString('es-CO')}`;
                
                const tipoPacRaw = ((s && s.tipo_paciente) ? s.tipo_paciente : (item.tipo_paciente || 'E')).toString().trim().toUpperCase();
                let tipoPacienteStr = 'Empresa';
                let tipoPacienteBadgeClass = 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800';

                if (tipoPacRaw === 'P' || tipoPacRaw === 'PARTICULAR') {
                    tipoPacienteStr = 'Particular';
                    tipoPacienteBadgeClass = 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800';
                } else if (tipoPacRaw === 'E' || tipoPacRaw === 'EMPRESA') {
                    tipoPacienteStr = 'Empresa';
                    tipoPacienteBadgeClass = 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800';
                } else if (tipoPacRaw !== '' && tipoPacRaw !== '-') {
                    tipoPacienteStr = tipoPacRaw;
                    tipoPacienteBadgeClass = 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700';
                }

                const cantidadStr = s ? (s.cantidad !== undefined ? s.cantidad : '-') : (item.cantidad || 1);

                let exclBadgeHtml = '';
                if (isExcluded && exclData) {
                    const safeMot = htmlspecialchars(exclData.motivo_exclusion || 'Excluido');
                    const safeDet = htmlspecialchars(exclData.detalle_exclusion || '');
                    exclBadgeHtml = `
                        <div class="mt-1.5 flex items-center gap-1.5 flex-wrap">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-amber-100 text-amber-900 dark:bg-amber-950/90 dark:text-amber-300 border border-amber-300 dark:border-amber-700 shadow-xs" title="${safeDet}">
                                <span class="material-symbols-outlined text-xs text-amber-600 dark:text-amber-400">do_not_disturb_on</span>
                                <span>Excluido: ${safeMot}</span>
                            </span>
                            <button type="button" onclick="event.stopPropagation(); editarJustificacionExclusion('${uniqueId}')" class="text-[10px] text-tertiary hover:underline font-bold inline-flex items-center gap-0.5" title="Editar justificación de exclusión">
                                <span class="material-symbols-outlined text-xs">edit_note</span>
                                <span>Editar</span>
                            </button>
                        </div>
                    `;
                }

                tr.innerHTML = `
                    <td class="py-3 px-3 text-center w-12" onclick="event.stopPropagation();">
                        <input type="checkbox" class="row-checkbox custom-chk" 
                            data-id="${uniqueId}" ${isSelected ? 'checked' : ''} onchange="toggleSelectRow('${uniqueId}', this.checked)" />
                    </td>
                    <td class="py-3 px-4 font-bold text-slate-900 dark:text-white whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        <span class="text-tertiary">${htmlspecialchars(item.fuente || '-')}</span> / <span class="text-slate-700 dark:text-slate-300">${htmlspecialchars(item.ingreso || '-')}</span>
                    </td>
                    <td class="py-3 px-4 text-center whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        <span class="inline-block px-2.5 py-1 rounded-lg text-[11px] font-bold ${tipoPacienteBadgeClass} border">${htmlspecialchars(tipoPacienteStr)}</span>
                    </td>
                    <td class="py-3 px-4 text-slate-500 dark:text-slate-400 whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        <div class="font-medium text-slate-700 dark:text-slate-300">${htmlspecialchars(item.fecha.substring(0, 10))}</div>
                        <div class="text-[10px] text-slate-400">${htmlspecialchars(item.sede || 'Sin Sede')}</div>
                    </td>
                    <td class="py-3 px-4 whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        <div class="font-bold text-slate-800 dark:text-slate-200">${htmlspecialchars(item.usuario)}</div>
                        <div class="text-[10px] text-slate-400">${htmlspecialchars(item.usuario_medico || '')}</div>
                    </td>
                    <td class="py-3 px-4" onclick="verDetalle('${uniqueId}')">
                        <div class="font-bold text-slate-900 dark:text-white">${htmlspecialchars(item.nombre || (s ? s.paciente : 'N/A'))}</div>
                        <div class="text-[10px] text-slate-400">
                            ${item.documento ? htmlspecialchars(item.documento) : (s ? `${s.tipo_doc} ${s.identificacion}` : '')}
                            ${s && s.entidad ? (
                                item.no_facturable_imadinsa ? 
                                `| <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-300 dark:border-rose-700 shadow-xs" title="Médico de IMADINSA SAS: sólo se factura cuando el registro es de IPS ALIVIO INTEGRAL DEL DOLOR SAS"><span class="material-symbols-outlined text-[11px]">block</span>${htmlspecialchars(s.entidad)} (No Facturable)</span>` :
                                (item.es_medico_imadinsa || s.entidad.toUpperCase().includes('ALIVIO INTEGRAL') ?
                                `| <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700 shadow-xs" title="IPS en convenio facturable para IMADINSA"><span class="material-symbols-outlined text-[11px]">verified</span>${htmlspecialchars(s.entidad)}</span>` :
                                `| <span class="text-teal-600 dark:text-teal-400 font-semibold">${htmlspecialchars(s.entidad)}</span>`)
                            ) : ''}
                        </div>
                    </td>
                    <td class="py-3 px-4 min-w-[220px] max-w-[380px]" onclick="verDetalle('${uniqueId}')">
                        ${formatExamenCupsCell(item)}
                        ${exclBadgeHtml}
                    </td>
                    <td class="py-3 px-4 text-center font-bold text-slate-800 dark:text-slate-200 whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        ${htmlspecialchars(cantidadStr)}
                    </td>
                    <td class="py-3 px-4 text-center whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        ${cruceBadge}
                    </td>
                    <td class="py-3 px-4 text-right font-bold text-slate-900 dark:text-white whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        ${valorExamenFmt}
                    </td>
                    <td class="py-3 px-4 text-right font-black text-emerald-600 dark:text-emerald-400 whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        ${item.no_facturable_imadinsa ? `
                            <div class="text-right leading-tight">
                                <span class="text-slate-400 dark:text-slate-500 font-bold text-sm">$0</span>
                                <span class="text-[9px] font-extrabold text-rose-500 dark:text-rose-400 block tracking-tight">No Facturable</span>
                            </div>
                        ` : valorPagarFmt}
                    </td>
                    <td class="py-3 px-4 text-center whitespace-nowrap">
                        <button type="button" onclick="verDetalle('${uniqueId}')" class="p-1.5 rounded-xl text-slate-400 hover:text-tertiary hover:bg-teal-50 dark:hover:bg-slate-800 transition-colors" title="Ver detalle completo">
                            <span class="material-symbols-outlined text-lg">visibility</span>
                        </button>
                    </td>
                `;

                tbody.appendChild(tr);
            });

            document.getElementById('lblPaginaActual').textContent = paginaActual;
            document.getElementById('lblTotalPaginas').textContent = totalPaginas;
            document.getElementById('btnPagPrev').disabled = (paginaActual <= 1);
            document.getElementById('btnPagNext').disabled = (paginaActual >= totalPaginas);
            actualizarMasterCheckbox();
        }

        function cambiarPagina(delta) {
            paginaActual += delta;
            renderizarTabla();
        }

        function verDetalle(targetId) {
            const item = allData.find(x => (x.unique_id && x.unique_id === targetId) || x.id == targetId);
            if (!item) return;

            document.getElementById('modalSubtitulo').textContent = `Fuente: ${item.fuente || '-'} | Ingreso: ${item.ingreso || '-'}`;

            // Banner Status
            const banner = document.getElementById('modalStatusBanner');
            if (item.cruce === 'CRUZADO') {
                const s = item.servinte;
                let docAlert = '';
                if (item.documento && s && s.identificacion) {
                    const docP = String(item.documento).trim();
                    const docS = String(s.identificacion).trim();
                    if (docP !== '' && docS !== '' && docP !== docS) {
                        docAlert = `<p class="text-[11px] font-bold text-amber-700 dark:text-amber-300 mt-1"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-1"></i>ATENCIÓN: El documento en Proteo (${htmlspecialchars(docP)}) difiere del documento en Servinte (${htmlspecialchars(docS)}).</p>`;
                    } else if (docP === docS) {
                        docAlert = `<p class="text-[11px] text-emerald-700 dark:text-emerald-300 font-semibold mt-1"><i class="fa-solid fa-check text-emerald-500 mr-1"></i>Documento de Identificación coincide (${htmlspecialchars(docP)}).</p>`;
                    }
                }
                banner.className = "p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 flex items-center gap-3";
                banner.innerHTML = `
                    <span class="material-symbols-outlined text-2xl text-emerald-600">check_circle</span>
                    <div>
                        <p class="font-bold text-xs">Examen Cruzado Exitosamente</p>
                        <p class="text-[11px] opacity-90">Existe registro de lectura en Proteo y factura en Servinte para Fuente ${item.fuente} e Ingreso ${item.ingreso}.</p>
                        ${docAlert}
                    </div>
                `;
            } else if (item.cruce === 'SOLO_PROTEO') {
                if (item.servinte_unmatched) {
                    const su = item.servinte_unmatched;
                    banner.className = "p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 flex items-center gap-3";
                    banner.innerHTML = `
                        <span class="material-symbols-outlined text-2xl text-amber-600">warning</span>
                        <div>
                            <p class="font-bold text-xs">Examen Registrado Solo en PROTEO (Diferencia de Código CUPS)</p>
                            <p class="text-[11px] opacity-90">Existe lectura en Proteo (CUPS: <strong>${htmlspecialchars(item.cups || '-')}</strong>) y factura en Servinte (Examen: <strong>${htmlspecialchars(su.codigo_examen || '')} - ${htmlspecialchars(su.examen || '')}</strong>) para Fuente ${htmlspecialchars(item.fuente || '-')} e Ingreso ${htmlspecialchars(item.ingreso || '-')}, pero no coincidieron en el cruce.</p>
                        </div>
                    `;
                } else {
                    banner.className = "p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200 flex items-center gap-3";
                    banner.innerHTML = `
                        <span class="material-symbols-outlined text-2xl text-rose-600">cancel</span>
                        <div>
                            <p class="font-bold text-xs">Examen Registrado Solo en PROTEO</p>
                            <p class="text-[11px] opacity-90">El examen fue leído en Proteo, pero NO posee factura/movimiento registrado en Servinte.</p>
                        </div>
                    `;
                }
            } else if (item.cruce === 'SOLO_SERVINTE') {
                if (item.proteo_unmatched) {
                    const pu = item.proteo_unmatched;
                    banner.className = "p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 flex items-center gap-3";
                    banner.innerHTML = `
                        <span class="material-symbols-outlined text-2xl text-amber-600">warning</span>
                        <div>
                            <p class="font-bold text-xs">Examen Registrado Solo en SERVINTE (Diferencia de Código CUPS)</p>
                            <p class="text-[11px] opacity-90">Existe factura en Servinte (Código: <strong>${htmlspecialchars(item.servinte ? item.servinte.codigo_examen : '')}</strong>) y lectura en Proteo (CUPS: <strong>${htmlspecialchars(pu.cups || '-')}</strong>) para Fuente ${htmlspecialchars(item.fuente || '-')} e Ingreso ${htmlspecialchars(item.ingreso || '-')}, pero no coincidieron en el cruce.</p>
                        </div>
                    `;
                } else {
                    banner.className = "p-4 rounded-2xl bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800 text-sky-800 dark:text-sky-200 flex items-center gap-3";
                    banner.innerHTML = `
                        <span class="material-symbols-outlined text-2xl text-sky-600">local_hospital</span>
                        <div>
                            <p class="font-bold text-xs">Examen Registrado Solo en SERVINTE</p>
                            <p class="text-[11px] opacity-90">El examen fue facturado en Servinte, pero NO posee registro de lectura en Proteo.</p>
                        </div>
                    `;
                }
            } else {
                banner.className = "p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 flex items-center gap-3";
                banner.innerHTML = `
                    <span class="material-symbols-outlined text-2xl text-amber-600">warning</span>
                    <div>
                        <p class="font-bold text-xs">Registro Incompleto en Proteo</p>
                        <p class="text-[11px] opacity-90">Falta la Fuente o el Ingreso en los parámetros dinámicos del evento.</p>
                    </div>
                `;
            }

            // Datos Proteo Body
            const pBody = document.getElementById('modalProteoBody');

            if (item.origen === 'PROTEO' || item.cruce === 'CRUZADO' || item.cruce === 'SOLO_PROTEO') {
                document.getElementById('modalProteoId').textContent = `ID: ${item.id}`;
                pBody.innerHTML = `
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Paciente (Proteo)</span>
                        <span class="font-bold text-slate-900 dark:text-white text-sm">${htmlspecialchars(item.nombre || 'N/A')}</span>
                        <div class="text-[11px] text-slate-500">${htmlspecialchars(item.documento || 'Sin Documento')}</div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Médico / Usuario</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">${htmlspecialchars(item.usuario)} (${htmlspecialchars(item.usuario_medico)})</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Fuente</span>
                            <span class="font-semibold">${htmlspecialchars(item.fuente || '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Ingreso</span>
                            <span class="font-semibold">${htmlspecialchars(item.ingreso || '-')}</span>
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">CUPS</span>
                        <div class="flex items-center gap-2 flex-wrap mt-0.5">
                            <span class="font-semibold text-slate-800 dark:text-slate-200">${htmlspecialchars(item.cups || '-')}</span>
                            ${item.cups && esTextoComparativo(item.cups) ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                    <span class="material-symbols-outlined text-[11px]">compare_arrows</span> COMPARATIVO
                                </span>
                            ` : ''}
                        </div>
                    </div>
                    ${item.id ? `
                        <div class="mt-2 pt-2 border-t border-sky-200/50 dark:border-sky-900/40" id="detailContainer_${item.id}">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Detalle de Evento (Detail JSON)</span>
                                <button type="button" onclick="cargarDetalleEvento('${item.id}', 'detailBox_${item.id}')" class="text-[10px] text-sky-600 dark:text-sky-400 hover:underline font-bold">Ver JSON</button>
                            </div>
                            <div id="detailBox_${item.id}" class="hidden mt-1 p-2 rounded-xl bg-slate-900/90 text-slate-200 font-mono text-[10px] max-h-28 overflow-y-auto break-all select-all"></div>
                        </div>
                    ` : ''}
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Modalidad</span>
                            <span class="font-semibold">${htmlspecialchars(item.modalidad || '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Sede</span>
                            <span class="font-semibold">${htmlspecialchars(item.sede || '-')}</span>
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Fecha Registro</span>
                        <span class="font-semibold">${htmlspecialchars(item.fecha || '-')}</span>
                    </div>
                `;
            } else if (item.cruce === 'SOLO_SERVINTE' && item.proteo_unmatched) {
                const pu = item.proteo_unmatched;
                document.getElementById('modalProteoId').textContent = `ID: ${pu.id || '-'}`;
                pBody.innerHTML = `
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Paciente (Proteo)</span>
                        <span class="font-bold text-slate-900 dark:text-white text-sm">${htmlspecialchars(pu.nombre || 'N/A')}</span>
                        <div class="text-[11px] text-slate-500">${htmlspecialchars(pu.documento || 'Sin Documento')}</div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Médico / Usuario</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">${htmlspecialchars(pu.usuario || '-')} (${htmlspecialchars(pu.usuario_medico || '-')})</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Fuente</span>
                            <span class="font-semibold">${htmlspecialchars(pu.fuente || '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Ingreso</span>
                            <span class="font-semibold">${htmlspecialchars(pu.ingreso || '-')}</span>
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">CUPS (Proteo)</span>
                        <div class="flex items-center gap-2 flex-wrap mt-0.5">
                            <span class="font-semibold text-slate-800 dark:text-slate-200">${htmlspecialchars(pu.cups || '-')}</span>
                            ${pu.cups && esTextoComparativo(pu.cups) ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                    <span class="material-symbols-outlined text-[11px]">compare_arrows</span> COMPARATIVO
                                </span>
                            ` : ''}
                        </div>
                    </div>
                    ${pu.id ? `
                        <div class="mt-2 pt-2 border-t border-amber-200/50 dark:border-amber-900/40" id="detailContainer_${pu.id}">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Detalle de Evento Proteo (Detail JSON)</span>
                                <button type="button" onclick="cargarDetalleEvento('${pu.id}', 'detailBox_${pu.id}')" class="text-[10px] text-amber-600 dark:text-amber-400 hover:underline font-bold">Ver JSON</button>
                            </div>
                            <div id="detailBox_${pu.id}" class="hidden mt-1 p-2 rounded-xl bg-slate-900/90 text-slate-200 font-mono text-[10px] max-h-28 overflow-y-auto break-all select-all"></div>
                        </div>
                    ` : ''}
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Modalidad</span>
                            <span class="font-semibold">${htmlspecialchars(pu.modalidad || '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Sede</span>
                            <span class="font-semibold">${htmlspecialchars(pu.sede || '-')}</span>
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Fecha Registro</span>
                        <span class="font-semibold">${htmlspecialchars(pu.fecha || '-')}</span>
                    </div>
                    <div class="mt-3 p-2.5 rounded-xl bg-amber-100/80 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-200">
                        <div class="font-extrabold text-[11px] flex items-center gap-1.5 text-amber-800 dark:text-amber-300">
                            <span class="material-symbols-outlined text-sm">info</span>
                            INFORMACIÓN DE PROTEO (SOLO INFORMATIVO)
                        </div>
                        <p class="text-[11px] mt-1 opacity-90 leading-tight">
                            Este ingreso se encuentra registrado en Proteo con CUPS: <strong>${htmlspecialchars(pu.cups || '-')}</strong>. Se muestra únicamente a fines de información para explicar la razón del no cruce (difiere del examen facturado en Servinte).
                        </p>
                    </div>
                `;
            } else {
                document.getElementById('modalProteoId').textContent = 'ID: -';
                pBody.innerHTML = `
                    <div class="py-8 text-center text-slate-400">
                        <span class="material-symbols-outlined text-3xl block mb-1 opacity-60">no_sim</span>
                        <p class="font-bold text-xs text-sky-600 dark:text-sky-400">Sin evento de lectura registrado en Proteo</p>
                        <p class="text-[11px] mt-1">Este examen fue facturado en Servinte pero no cuenta con un informe/evento grabado en Proteo.</p>
                    </div>
                `;
            }

            // Datos Servinte Body
            const sBody  = document.getElementById('modalServinteBody');
            const sBadge = document.getElementById('modalServinteBadge');

            if (item.servinte) {
                const s = item.servinte;
                sBadge.className = (item.cruce === 'CRUZADO') ? 
                    "text-[10px] font-bold text-teal-700 bg-teal-100 dark:bg-teal-900/60 px-2 py-0.5 rounded-md" :
                    "text-[10px] font-bold text-sky-700 bg-sky-100 dark:bg-sky-900/60 px-2 py-0.5 rounded-md";
                sBadge.textContent = (item.cruce === 'CRUZADO') ? "COINCIDENCIA OK" : "SOLO SERVINTE";

                sBody.innerHTML = `
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Paciente (Servinte)</span>
                        <span class="font-bold text-slate-900 dark:text-white text-sm">${htmlspecialchars(s.paciente)}</span>
                        <div class="text-[11px] text-slate-500">
                            ${htmlspecialchars(s.tipo_doc)} ${htmlspecialchars(s.identificacion)}
                            ${s.tipo_paciente ? `| <span class="font-bold text-slate-700 dark:text-slate-200">Tipo: <span class="text-primary dark:text-tertiary">${htmlspecialchars((String(s.tipo_paciente).trim().toUpperCase() === 'P' || String(s.tipo_paciente).trim().toUpperCase() === 'PARTICULAR') ? 'Particular' : 'Empresa')}</span></span>` : ''}
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Entidad / Convenio</span>
                        ${item.no_facturable_imadinsa ? 
                            `<span class="inline-flex items-center gap-1 font-bold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/50 px-2 py-0.5 rounded border border-rose-200 dark:border-rose-800 text-xs"><span class="material-symbols-outlined text-xs">block</span>${htmlspecialchars(s.entidad || 'Sin Entidad')} (No Facturable)</span>` :
                            `<span class="font-semibold text-teal-600 dark:text-teal-400">${htmlspecialchars(s.entidad)}</span>`
                        }
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Examen Facturado (Servinte)</span>
                        <div class="flex items-center gap-2 flex-wrap mt-0.5">
                            <span class="font-semibold text-slate-800 dark:text-slate-200">${htmlspecialchars(s.examen || '-')} (${htmlspecialchars(s.codigo_examen)})</span>
                            ${s.examen && esTextoComparativo(s.examen) ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                    <span class="material-symbols-outlined text-[11px]">compare_arrows</span> COMPARATIVO
                                </span>
                            ` : ''}
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Concepto</span>
                            <span class="font-semibold">${htmlspecialchars(s.concepto)}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Cantidad</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">${htmlspecialchars(s.cantidad !== undefined ? s.cantidad : '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Valor del Examen</span>
                            <span class="font-black text-slate-900 dark:text-white text-sm">$${(s.total || 0).toLocaleString('es-CO')}</span>
                        </div>
                    </div>

                    <!-- Bloque Cálculo Tarifario Valor a Pagar -->
                    <div class="p-3.5 rounded-2xl ${item.no_facturable_imadinsa ? 'bg-rose-50/80 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800/80' : 'bg-emerald-50/80 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/80'} space-y-2 mt-3">
                        <div class="flex items-center justify-between border-b ${item.no_facturable_imadinsa ? 'border-rose-200/80 dark:border-rose-800/80' : 'border-emerald-200/80 dark:border-emerald-800/80'} pb-1.5">
                            <span class="font-black ${item.no_facturable_imadinsa ? 'text-rose-800 dark:text-rose-300' : 'text-emerald-800 dark:text-emerald-300'} uppercase tracking-wider text-[10px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-sm">${item.no_facturable_imadinsa ? 'block' : 'payments'}</span>
                                ${item.no_facturable_imadinsa ? 'REGISTRO NO FACTURABLE (REGLA IMADINSA SAS)' : 'CÁLCULO VALOR A PAGAR (TARIFARIO LIHO)'}
                            </span>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Tarifa Unitario</span>
                                <span class="font-bold text-slate-900 dark:text-white text-xs">${item.no_facturable_imadinsa ? '$0' : (item.valor_und_tarifario ? `$${(item.valor_und_tarifario).toLocaleString('es-CO')}` : 'Sin Tarifa')}</span>
                                ${item.tarifa_base_calculo === 'VALOR_EXAMEN' ? '<span class="inline-block text-[9px] font-black uppercase px-1 py-0.2 rounded bg-cyan-100 dark:bg-cyan-900/60 text-cyan-800 dark:text-cyan-200 mt-0.5">Base Examen</span>' : ''}
                            </div>
                            <div>
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Cantidad</span>
                                <span class="font-bold text-slate-900 dark:text-white text-xs">${item.cantidad || 1}</span>
                                ${item.pagar_por_cantidad === 0 ? '<span class="inline-block text-[9px] font-black uppercase px-1 py-0.2 rounded bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-200 mt-0.5">Fijo (Sin Cant)</span>' : ''}
                            </div>
                            <div>
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Valor a Pagar</span>
                                <span class="font-black ${item.no_facturable_imadinsa ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'} text-sm">$${(item.valor_a_pagar || 0).toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                        ${item.no_facturable_imadinsa ? `
                            <div class="mt-2 text-[11px] text-rose-700 dark:text-rose-300 leading-tight">
                                <strong>Motivo:</strong> Médico perteneciente a <strong>IMADINSA SAS</strong>. Únicamente se factura cuando en el registro de Servinte dice <strong>IPS ALIVIO INTEGRAL DEL DOLOR SAS</strong> (Entidad actual: <em>${htmlspecialchars(s.entidad || 'Sin Entidad')}</em>).
                            </div>
                        ` : ''}
                        ${item.es_bloqueo_ho ? `
                            <div class="mt-2 pt-2 border-t border-amber-300/80 dark:border-amber-800/80 text-[11px] text-amber-900 dark:text-amber-200">
                                <div class="flex items-center justify-between font-bold flex-wrap gap-1">
                                    <span class="flex items-center gap-1"><span class="material-symbols-outlined text-xs text-amber-600 dark:text-amber-400">syringe</span> Modalidad Especial BLOQUEO HO (${htmlspecialchars(item.bloqueo_tier_label || ('Cant. ' + (item.cantidad || 1)))}):</span>
                                    <span class="px-2 py-0.5 rounded bg-amber-200 dark:bg-amber-900 text-amber-900 dark:text-amber-100 font-extrabold">${item.bloqueo_pct || 40}% del valor total estudio</span>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between text-[10px] text-slate-600 dark:text-slate-300 bg-amber-500/10 px-2.5 py-1 rounded-xl">
                                    <span>Base Liquidada: <strong class="text-slate-800 dark:text-slate-100">$${(item.bloqueo_valor_estudio || 0).toLocaleString('es-CO')}</strong></span>
                                    <span>Pago Médico (${item.bloqueo_pct || 40}%): <strong class="text-emerald-600 dark:text-emerald-400">$${(item.valor_a_pagar || 0).toLocaleString('es-CO')}</strong></span>
                                </div>
                                <p class="text-[10px] italic text-amber-700 dark:text-amber-400 mt-1 font-semibold">
                                    (Estas condiciones están sujetas a lo contratado por cada entidad, por ende pueden variar)
                                </p>
                            </div>
                        ` : ''}
                    </div>
                `;
            } else if (item.cruce === 'SOLO_PROTEO' && item.servinte_unmatched) {
                const s = item.servinte_unmatched;
                sBadge.className = "text-[10px] font-bold text-amber-700 bg-amber-100 dark:bg-amber-900/60 px-2 py-0.5 rounded-md";
                sBadge.textContent = "DIFERENCIA EN EXAMEN";

                sBody.innerHTML = `
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Paciente (Servinte)</span>
                        <span class="font-bold text-slate-900 dark:text-white text-sm">${htmlspecialchars(s.paciente || 'N/A')}</span>
                        <div class="text-[11px] text-slate-500">
                            ${htmlspecialchars(s.tipo_doc || '')} ${htmlspecialchars(s.identificacion || '')}
                            ${s.tipo_paciente ? `| <span class="font-bold text-slate-700 dark:text-slate-200">Tipo: <span class="text-primary dark:text-tertiary">${htmlspecialchars((String(s.tipo_paciente).trim().toUpperCase() === 'P' || String(s.tipo_paciente).trim().toUpperCase() === 'PARTICULAR') ? 'Particular' : 'Empresa')}</span></span>` : ''}
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Entidad / Convenio</span>
                        ${item.no_facturable_imadinsa ? 
                            `<span class="inline-flex items-center gap-1 font-bold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/50 px-2 py-0.5 rounded border border-rose-200 dark:border-rose-800 text-xs"><span class="material-symbols-outlined text-xs">block</span>${htmlspecialchars(s.entidad || 'Sin Entidad')} (No Facturable)</span>` :
                            `<span class="font-semibold text-teal-600 dark:text-teal-400">${htmlspecialchars(s.entidad || '-')}</span>`
                        }
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Examen Facturado (Servinte)</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">${htmlspecialchars(s.examen || '-')} (${htmlspecialchars(s.codigo_examen || '-')})</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Concepto</span>
                            <span class="font-semibold">${htmlspecialchars(s.concepto || '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Cantidad</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">${htmlspecialchars(s.cantidad !== undefined ? s.cantidad : '-')}</span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Valor del Examen</span>
                            <span class="font-black text-slate-900 dark:text-white text-sm">$${(s.total || 0).toLocaleString('es-CO')}</span>
                        </div>
                    </div>

                    <!-- Bloque Cálculo Tarifario Valor a Pagar -->
                    <div class="p-3.5 rounded-2xl ${item.no_facturable_imadinsa ? 'bg-rose-50/80 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-800/80' : 'bg-emerald-50/80 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/80'} space-y-2 mt-3">
                        <div class="flex items-center justify-between border-b ${item.no_facturable_imadinsa ? 'border-rose-200/80 dark:border-rose-800/80' : 'border-emerald-200/80 dark:border-emerald-800/80'} pb-1.5">
                            <span class="font-black ${item.no_facturable_imadinsa ? 'text-rose-800 dark:text-rose-300' : 'text-emerald-800 dark:text-emerald-300'} uppercase tracking-wider text-[10px] flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-sm">${item.no_facturable_imadinsa ? 'block' : 'payments'}</span>
                                ${item.no_facturable_imadinsa ? 'REGISTRO NO FACTURABLE (REGLA IMADINSA SAS)' : 'CÁLCULO VALOR A PAGAR (TARIFARIO CUPS ' + htmlspecialchars(s.codigo_examen || '') + ')'}
                            </span>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Tarifa Unitario</span>
                                <span class="font-bold text-slate-900 dark:text-white text-xs">${item.no_facturable_imadinsa ? '$0' : (item.valor_und_tarifario ? `$${(item.valor_und_tarifario).toLocaleString('es-CO')}` : 'Sin Tarifa')}</span>
                                ${item.tarifa_base_calculo === 'VALOR_EXAMEN' ? '<span class="inline-block text-[9px] font-black uppercase px-1 py-0.2 rounded bg-cyan-100 dark:bg-cyan-900/60 text-cyan-800 dark:text-cyan-200 mt-0.5">Base Examen</span>' : ''}
                            </div>
                            <div>
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Cantidad</span>
                                <span class="font-bold text-slate-900 dark:text-white text-xs">${item.cantidad || 1}</span>
                                ${item.pagar_por_cantidad === 0 ? '<span class="inline-block text-[9px] font-black uppercase px-1 py-0.2 rounded bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-200 mt-0.5">Fijo (Sin Cant)</span>' : ''}
                            </div>
                            <div>
                                <span class="text-[10px] uppercase font-bold text-slate-400 block">Valor a Pagar</span>
                                <span class="font-black ${item.no_facturable_imadinsa ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'} text-sm">$${(item.valor_a_pagar || 0).toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                        ${item.no_facturable_imadinsa ? `
                            <div class="mt-2 text-[11px] text-rose-700 dark:text-rose-300 leading-tight">
                                <strong>Motivo:</strong> Médico perteneciente a <strong>IMADINSA SAS</strong>. Únicamente se factura cuando en el registro de Servinte dice <strong>IPS ALIVIO INTEGRAL DEL DOLOR SAS</strong> (Entidad actual: <em>${htmlspecialchars(s.entidad || 'Sin Entidad')}</em>).
                            </div>
                        ` : ''}
                        ${item.es_bloqueo_ho ? `
                            <div class="mt-2 pt-2 border-t border-amber-300/80 dark:border-amber-800/80 text-[11px] text-amber-900 dark:text-amber-200">
                                <div class="flex items-center justify-between font-bold flex-wrap gap-1">
                                    <span class="flex items-center gap-1"><span class="material-symbols-outlined text-xs text-amber-600 dark:text-amber-400">syringe</span> Modalidad Especial BLOQUEO HO (${htmlspecialchars(item.bloqueo_tier_label || ('Cant. ' + (item.cantidad || 1)))}):</span>
                                    <span class="px-2 py-0.5 rounded bg-amber-200 dark:bg-amber-900 text-amber-900 dark:text-amber-100 font-extrabold">${item.bloqueo_pct || 40}% del valor total estudio</span>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between text-[10px] text-slate-600 dark:text-slate-300 bg-amber-500/10 px-2.5 py-1 rounded-xl">
                                    <span>Base Liquidada: <strong class="text-slate-800 dark:text-slate-100">$${(item.bloqueo_valor_estudio || 0).toLocaleString('es-CO')}</strong></span>
                                    <span>Pago Médico (${item.bloqueo_pct || 40}%): <strong class="text-emerald-600 dark:text-emerald-400">$${(item.valor_a_pagar || 0).toLocaleString('es-CO')}</strong></span>
                                </div>
                                <p class="text-[10px] italic text-amber-700 dark:text-amber-400 mt-1 font-semibold">
                                    (Estas condiciones están sujetas a lo contratado por cada entidad, por ende pueden variar)
                                </p>
                            </div>
                        ` : ''}
                    </div>

                    <div class="mt-3 p-2.5 rounded-xl bg-amber-100/80 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-200">
                        <div class="font-extrabold text-[11px] flex items-center gap-1.5 text-amber-800 dark:text-amber-300">
                            <span class="material-symbols-outlined text-sm">info</span>
                            INFORMACIÓN DE SERVINTE (DIFERENCIA CUPS)
                        </div>
                        <p class="text-[11px] mt-1 opacity-90 leading-tight">
                            Existe movimiento en Servinte con este mismo Ingreso (Examen: <strong>${htmlspecialchars(s.codigo_examen || '')} - ${htmlspecialchars(s.examen || '')}</strong>). La tarifa a pagar se calcula sobre el código CUPS facturado en Servinte (<strong>${htmlspecialchars(s.codigo_examen || '')}</strong>).
                        </p>
                    </div>
                `;
            } else {
                sBadge.className = "text-[10px] font-bold text-rose-700 bg-rose-100 dark:bg-rose-900/60 px-2 py-0.5 rounded-md";
                sBadge.textContent = "NO ENCONTRADO";

                sBody.innerHTML = `
                    <div class="py-8 text-center text-slate-400">
                        <span class="material-symbols-outlined text-3xl block mb-1 opacity-60">folder_off</span>
                        <p class="font-bold text-xs text-rose-600 dark:text-rose-400">Sin movimiento registrado en SERVINTE</p>
                        <p class="text-[11px] mt-1">La combinación Fuente (${htmlspecialchars(item.fuente || '-')}) e Ingreso (${htmlspecialchars(item.ingreso || '-')}) no fue encontrada en las tablas de Oracle.</p>
                    </div>
                `;
            }

            // Abrir Modal
            const modal = document.getElementById('modalDetalle');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0', 'pointer-events-none');
                modal.firstElementChild.classList.remove('scale-95');
                modal.firstElementChild.classList.add('scale-100');
            }, 10);
        }

        async function cargarDetalleEvento(eventoId, boxId) {
            const box = document.getElementById(boxId);
            if (!box) return;

            if (!box.classList.contains('hidden')) {
                box.classList.add('hidden');
                return;
            }

            box.classList.remove('hidden');
            box.innerHTML = '<span class="text-slate-400 italic">Cargando JSON del evento...</span>';

            try {
                const res = await fetch(`gestion_medicos_procedimientos.php?action=obtener_detalle_evento&evento_id=${encodeURIComponent(eventoId)}`);
                const data = await res.json();
                if (data.success && data.detail) {
                    try {
                        const parsed = JSON.parse(data.detail);
                        box.textContent = JSON.stringify(parsed, null, 2);
                    } catch (e) {
                        box.textContent = data.detail;
                    }
                } else {
                    box.innerHTML = '<span class="text-slate-400 italic">Sin detalle JSON registrado para este evento</span>';
                }
            } catch (err) {
                box.innerHTML = '<span class="text-rose-400 italic">Error al consultar el detalle</span>';
            }
        }

        function cerrarModal() {
            const modal = document.getElementById('modalDetalle');
            modal.firstElementChild.classList.remove('scale-100');
            modal.firstElementChild.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        function exportarExcel() {
            if (!haConsultado) {
                SwalCustom.fire({ icon: 'warning', title: 'Consulta Requerida', text: 'Debes realizar una consulta primero seleccionando los filtros y haciendo clic en Consultar.' });
                return;
            }
            if (allData.length === 0) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Datos', text: 'No existen datos para exportar en la consulta realizada.' });
                return;
            }

            const fDesde = document.getElementById('fecha_desde').value;
            const fHasta = document.getElementById('fecha_hasta').value;
            const medico = document.getElementById('medico').value;
            const cruce  = document.getElementById('cruce').value;

            const url = `gestion_medicos_procedimientos.php?action=export_excel&fecha_desde=${encodeURIComponent(fDesde)}&fecha_hasta=${encodeURIComponent(fHasta)}&medico=${encodeURIComponent(medico)}&cruce=${encodeURIComponent(cruce)}`;
            window.location.href = url;
        }

        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function esTextoComparativo(texto) {
            if (!texto) return false;
            if (/\bcomparativ[a-záéíóúñ\/]*/i.test(texto)) return true;
            const tieneProyeccion = /\b(a\s*\.?\s*p\.?|p\s*\.?\s*a\.?)\b/i.test(texto) || /a\.p/i.test(texto) || /p\.a/i.test(texto);
            const tieneLateral    = /\blateral(?:es)?\b/i.test(texto) || /\blat\b/i.test(texto);
            if (tieneProyeccion && tieneLateral) return true;
            return false;
        }

        function formatExamenCupsCell(item) {
            const rawCups = item.cups || 'Sin CUPS';
            const rawDetail = item.detail || '';
            const sUnmatched = item.servinte_unmatched;
            const rawServinteUnmatched = sUnmatched ? (sUnmatched.examen || '') : '';
            const rawServinte = (item.servinte && item.servinte.examen) ? item.servinte.examen : '';

            const isCompCups = esTextoComparativo(rawCups) || esTextoComparativo(rawDetail);
            const isCompUnmatched = esTextoComparativo(rawServinteUnmatched);
            const isCompServ = esTextoComparativo(rawServinte);
            const isComparativo = isCompCups || isCompUnmatched || isCompServ;

            const safeCups = htmlspecialchars(rawCups);

            let unmatchedHtml = '';
            if (!item.servinte && sUnmatched) {
                const safeServCod = htmlspecialchars(sUnmatched.codigo_examen || '');
                const safeServExa = htmlspecialchars(sUnmatched.examen || '');

                unmatchedHtml = `
                    <div class="mt-1.5 flex items-start gap-1 text-[10px] text-amber-700 dark:text-amber-300 font-bold bg-amber-50 dark:bg-amber-950/60 px-2 py-1 rounded-lg border border-amber-200 dark:border-amber-900/60 w-fit leading-snug">
                        <span class="material-symbols-outlined text-xs shrink-0 mt-0.5">sync_problem</span>
                        <span>En Servinte: ${safeServCod} - ${safeServExa}</span>
                    </div>
                `;
            }

            const cCode = item.concepto || '';
            const cNom  = item.concepto_nombre || cCode;
            const conceptBadge = cCode ? `
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 mr-1" title="Concepto: ${htmlspecialchars(cNom)}">
                    ${htmlspecialchars(cCode)}
                </span>
            ` : '';

            const contrastadaBadge = item.es_tomografia_contrastada ? `
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black tracking-wider uppercase bg-amber-100 text-amber-900 dark:bg-amber-950/80 dark:text-amber-200 border border-amber-300 dark:border-amber-700/80 shadow-xs mr-1" title="Tomografía Contrastada (Aplica bono de $150.000 por cada 50 tomografías contrastadas)">
                    <span class="material-symbols-outlined text-[10px] text-amber-600 dark:text-amber-400">military_tech</span>
                    Contrastada (Bono 50x$150K)
                </span>
            ` : '';

            const bloqueoBadge = item.es_bloqueo_ho ? `
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black tracking-wider uppercase bg-amber-100 text-amber-900 dark:bg-amber-950/90 dark:text-amber-200 border border-amber-300 dark:border-amber-700 shadow-xs mr-1" title="Liquidación Bloqueos HO: ${htmlspecialchars(item.bloqueo_tier_label || ('Cant. ' + (item.cantidad || 1)))} liquidada al ${item.bloqueo_pct || 40}% (Estudio: $${(item.bloqueo_valor_estudio || 0).toLocaleString('es-CO')} -> Pago Médico: $${(item.valor_a_pagar || 0).toLocaleString('es-CO')})">
                    <span class="material-symbols-outlined text-[10px] leading-none text-amber-600 dark:text-amber-400">syringe</span>
                    <span>BLOQUEO HO (${item.bloqueo_tier_label ? item.bloqueo_tier_label + ' - ' : ''}${item.bloqueo_pct || 40}%)</span>
                </span>
            ` : '';

            if (isComparativo) {
                return `
                    <div class="p-2 rounded-xl bg-indigo-50/60 dark:bg-indigo-950/30 border border-indigo-200/80 dark:border-indigo-800/60 shadow-xs transition-all duration-150 group-hover:border-indigo-300 dark:group-hover:border-indigo-700">
                        <div class="flex items-center gap-1 mb-1 flex-wrap">
                            ${conceptBadge}
                            ${contrastadaBadge}
                            ${bloqueoBadge}
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black tracking-wider uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/90 dark:text-indigo-200 border border-indigo-300/80 dark:border-indigo-700/80 shadow-xs">
                                <span class="material-symbols-outlined text-[11px] leading-none">compare_arrows</span>
                                <span>COMPARATIVO</span>
                            </span>
                        </div>
                        <div class="font-semibold text-slate-800 dark:text-slate-200 whitespace-normal break-words leading-tight" title="${safeCups}">
                            ${safeCups}
                        </div>
                        ${unmatchedHtml}
                    </div>
                `;
            }

            return `
                <div class="font-semibold text-slate-800 dark:text-slate-200 whitespace-normal break-words leading-tight flex items-center flex-wrap gap-1" title="${safeCups}">
                    ${conceptBadge}${contrastadaBadge}${bloqueoBadge}<span>${safeCups}</span>
                </div>
                ${unmatchedHtml}
            `;
        }

        // --- LÓGICA VISTA / MODAL DE LIQUIDACIÓN DE TURNOS ---
        let totalFacturaBaseLiquidador = 0;

        function actualizarEstadoBotonesAccion() {
            const btnLiq   = document.getElementById('btnGenerarLiquidacion');
            const btnExcel = document.getElementById('btnExportExcel');

            const hayDatos = (haConsultado && allData.length > 0);

            if (btnLiq) {
                // Inactivado permanentemente para obligar al usuario a usar "Liquidar Selección" y auditar cada registro
                btnLiq.disabled = true;
                btnLiq.className = "inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs border border-slate-200 dark:border-slate-700/60 shadow-none cursor-not-allowed opacity-50 transition-all duration-200";
                btnLiq.title = "Liquidación global inactiva: Utilice el botón 'Liquidar Selección' en la barra inferior tras revisar cada registro";
            }

            if (btnExcel) {
                if (hayDatos) {
                    btnExcel.disabled = false;
                    btnExcel.className = "inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md shadow-emerald-600/20 transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer opacity-100";
                    btnExcel.title = "Exportar datos a Excel";
                } else {
                    btnExcel.disabled = true;
                    btnExcel.className = "inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs shadow-none cursor-not-allowed opacity-60 transition-all duration-200";
                    btnExcel.title = "Realice una consulta primero para exportar";
                }
            }
        }

        function notificarLiquidacionPorSeleccion() {
            SwalCustom.fire({
                icon: 'info',
                title: 'Liquidación por Selección Obligatoria',
                html: '<div class="text-left space-y-2 text-xs text-slate-300"><p>Para garantizar el <strong>análisis y la auditoría registro a registro</strong>, la liquidación directa desde este botón ha sido inactivada.</p><p>Por favor, revisa cada examen en la tabla (o excluye los que no correspondan con su debida justificación) y haz clic en el botón <strong class="text-tertiary">"Liquidar Selección"</strong> en la barra flotante inferior.</p></div>',
                confirmButtonText: 'Entendido, ir a la selección'
            }).then(() => {
                const floatBar = document.getElementById('floatingSelectionBar');
                if (floatBar) {
                    floatBar.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
            });
        }

        function parseMontoInput(val) {
            if (typeof val === 'number') return val;
            if (!val) return 0;
            const clean = String(val).replace(/\D/g, '');
            return clean === '' ? 0 : parseInt(clean, 10);
        }

        function formatInputMiles(inputEl) {
            if (!inputEl) return 0;
            let cursorPosition = inputEl.selectionStart || 0;
            let originalLength = inputEl.value.length;

            let raw = inputEl.value.replace(/\D/g, '');
            if (raw === '') {
                inputEl.value = '0';
                return 0;
            }
            let num = parseInt(raw, 10);
            inputEl.value = num.toLocaleString('es-CO');

            let newLength = inputEl.value.length;
            cursorPosition = cursorPosition + (newLength - originalLength);
            if (cursorPosition < 0) cursorPosition = 0;
            if (inputEl.setSelectionRange) {
                inputEl.setSelectionRange(cursorPosition, cursorPosition);
            }
            return num;
        }

        function abrirModalLiquidacion(soloSels = true) {
            if (!haConsultado) {
                SwalCustom.fire({ icon: 'warning', title: 'Consulta Requerida', text: 'Debes realizar una consulta primero seleccionando los filtros y haciendo clic en el botón Consultar.' });
                return;
            }
            if (allData.length === 0) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Resultados', text: 'No se encontraron registros en la consulta realizada. Ajusta los filtros y consulta nuevamente.' });
                return;
            }

            if (selectedIds.size === 0) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Selección', text: 'No has seleccionado ningún registro con el checkbox para liquidar. Analiza y selecciona los registros a incluir.' });
                return;
            }

            const fDesde = document.getElementById('fecha_desde').value || '';
            const fHasta = document.getElementById('fecha_hasta').value || '';
            const medicoLabel = (document.getElementById('selectedMedicoLabel') ? document.getElementById('selectedMedicoLabel').textContent : '').trim() || '-- Todos los Médicos --';
            const medicoVal   = (document.getElementById('medico') ? document.getElementById('medico').value : '').trim();

            let periodoStr = (fDesde && fHasta) ? `${fDesde} AL ${fHasta}` : 'PERIODO CONSULTADO';
            periodoStr += ` (${selectedIds.size} REGISTROS AUDITADOS)`;
            document.getElementById('liqPeriodoLabel').textContent = periodoStr;

            // Datos de la Entidad Activa y Entidad del Médico
            const isGlobalMed = (!medicoVal || medicoLabel === '-- Todos los Médicos --');

            const entActivaNombre = <?php echo json_encode($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>;
            const entActivaNit    = <?php echo json_encode($entidadActivaNit ?: ''); ?>;
            const entActivaId     = <?php echo json_encode($entidadActivaId ?: 'PROPIO'); ?>;

            let entFinalNombre = entActivaNombre;
            let entFinalNit    = entActivaNit;
            let entFinalId     = entActivaId;

            if (!isGlobalMed) {
                // Verificar si el médico seleccionado está vinculado a una entidad específica
                const selBtn = document.querySelector(`.medico-option-btn[data-value="${medicoVal}"]`);
                const docEntId = selBtn ? selBtn.getAttribute('data-entidad-id') : null;
                const docEntNom = selBtn ? selBtn.getAttribute('data-entidad-nombre') : null;
                const docEntNit = selBtn ? selBtn.getAttribute('data-entidad-nit') : null;

                const cleanCed = medicoVal ? medicoVal.replace(/^[Cc]/, '').trim() : '';
                const mapEnt = window.medicosEntidadMap && (window.medicosEntidadMap[medicoVal] || window.medicosEntidadMap[cleanCed] || (medicoLabel ? window.medicosEntidadMap[medicoLabel.toUpperCase().trim()] : null));

                if (docEntNom && docEntNom.trim() !== '') {
                    entFinalNombre = docEntNom.trim();
                    entFinalId     = docEntId || 'PROPIO';
                    entFinalNit    = docEntNit || '';
                } else if (mapEnt && mapEnt.nombre && mapEnt.nombre.trim() !== '') {
                    entFinalNombre = mapEnt.nombre.trim();
                    entFinalId     = mapEnt.id || 'PROPIO';
                    entFinalNit    = mapEnt.nit || '';
                }
            }

            window.novedadesLiquidacionAplicadas = [];
            window.currentEntidadIdLiq = entFinalId || 'PROPIO';
            window.currentEntidadNombreLiq = entFinalNombre;
            window.currentEntidadNitLiq = entFinalNit;
            const chkNov = document.getElementById('chkRegistraNovedadesLiq');
            if (chkNov) chkNov.checked = false;
            actualizarResumenBadgesNovedadesLiq();

            const elEmpresaLabel = document.getElementById('liqEmpresaLabel');
            if (elEmpresaLabel) elEmpresaLabel.textContent = entFinalNombre;

            const elEmpresaNitLabel = document.getElementById('liqEmpresaNitLabel');
            if (elEmpresaNitLabel) {
                elEmpresaNitLabel.textContent = entFinalNit ? `• NIT: ${entFinalNit}` : (entFinalId === 'PROPIO' ? '• NIT: 890.980.123-4' : '');
            }

            const elEmpresaPrint = document.getElementById('liqEmpresaNombrePrint');
            if (elEmpresaPrint) elEmpresaPrint.textContent = entFinalNombre;

            const elEmpresaNitPrint = document.getElementById('liqEmpresaNitPrint');
            if (elEmpresaNitPrint) {
                elEmpresaNitPrint.textContent = entFinalNit ? `NIT: ${entFinalNit}` : (entFinalId === 'PROPIO' ? 'SISTEMAS DIAGNÓSTICOS E IMÁGENES MÉDICAS | NIT: 890.980.123-4' : 'ENTIDAD EXTERNA');
            }

            const elEmpresaFirmaPrint = document.getElementById('liqEmpresaFirmaPrint');
            if (elEmpresaFirmaPrint) elEmpresaFirmaPrint.textContent = entFinalNombre;

            const docName = !isGlobalMed ? medicoLabel : 'TODOS LOS MÉDICOS / GLOBAL';

            const elDoctorSubhead = document.getElementById('liqDoctorSubhead');
            const elDoctorName    = document.getElementById('liqDoctorName');
            const elDoctorCedula  = document.getElementById('liqDoctorCedula');
            const elTipoBadge     = document.getElementById('liqTipoLiquidacionBadge');

            if (isGlobalMed) {
                if (elDoctorSubhead) elDoctorSubhead.textContent = 'ALCANCE / MODALIDAD';
                if (elDoctorName) elDoctorName.textContent = 'TODOS LOS MÉDICOS / GLOBAL';
                if (elDoctorCedula) elDoctorCedula.textContent = `CONSOLIDADO (${entFinalNombre || entActivaNombre})`;
                if (elTipoBadge) {
                    elTipoBadge.textContent = 'Consolidado Global';
                    elTipoBadge.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700';
                }
            } else {
                if (elDoctorSubhead) elDoctorSubhead.textContent = 'DOCTOR / PROFESIONAL';
                if (elDoctorName) elDoctorName.textContent = docName;
                if (elDoctorCedula) elDoctorCedula.textContent = `CÉDULA / ID: ${medicoVal}`;
                if (elTipoBadge) {
                    elTipoBadge.textContent = 'Liquidación Individual';
                    elTipoBadge.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-300 border border-teal-200 dark:border-teal-700';
                }
            }

            const docSig = document.getElementById('liqDoctorNamePrintSignature');
            if (docSig) docSig.textContent = isGlobalMed ? 'CONSOLIDADO INSTITUCIONAL (VARIOS MÉDICOS)' : docName;
            const docCedSig = document.getElementById('liqDoctorCedulaPrintSignature');
            if (docCedSig) docCedSig.textContent = isGlobalMed ? `ENTIDAD: ${entFinalNombre || entActivaNombre}` : (medicoVal ? `C.C. / ID: ${medicoVal}` : 'C.C. / ID: -');

            let totalCruzadosOK = 0;
            let totalNoCruzados = 0;
            let countNoCruzados  = 0;

            let totalTomosContrastadasSels = 0;
            const sedesTomoCount = {};

            const sedesMap = {};

            // Para la liquidación procesamos SIEMPRE exclusivamente los registros seleccionados y auditados
            const dataToProcess = allData.filter(item => selectedIds.has(item.unique_id || item.id));

            dataToProcess.forEach(item => {
                const sedeNombre = (item.sede && item.sede.trim() !== '') ? item.sede.trim().toUpperCase() : 'SEDE SIN ESPECIFICAR';
                const esCruzado  = (item.cruce === 'CRUZADO');
                const cruceTipo  = item.cruce || 'SOLO_PROTEO';

                if (!sedesMap[sedeNombre]) {
                    sedesMap[sedeNombre] = {
                        nombre: sedeNombre,
                        conceptos: {},
                        totalCant: 0,
                        totalValor: 0,
                        cruzadoValor: 0,
                        noCruzadoValor: 0,
                        cruzadoCount: 0,
                        noCruzadoCount: 0
                    };
                }

                // Clasificación Dinámica de Servicio / Concepto
                let conceptoKey = '';
                const itemNom = ((item.nombre_examen || '') + ' ' + (item.cups || '')).toUpperCase();
                const itemCupsNum = (item.cups || '').replace(/[^0-9]/g, '');

                if (item.servicio && item.servicio.trim() !== '') {
                    conceptoKey = item.servicio.trim().toUpperCase();
                } else if (item.concepto === 'RXES' || item.cuenta_contable === '61251002' || itemNom.includes('ESPECIAL')) {
                    conceptoKey = 'RX ESPECIALES';
                } else if (item.concepto === 'DOPP' || itemNom.includes('DOPP') || itemCupsNum.startsWith('882')) {
                    conceptoKey = 'DOPPLER';
                } else if (item.concepto === 'ECOG' || itemNom.includes('ECO') || itemNom.includes('ULTRASO') || itemCupsNum.startsWith('881')) {
                    conceptoKey = 'ECOGRAFÍAS';
                } else if (item.concepto === 'TOHO' || item.cuenta_contable === '61251007' || itemNom.includes('TOMO') || itemNom.includes('TAC') || itemCupsNum.startsWith('879')) {
                    conceptoKey = 'TOMOGRAFÍAS';
                } else if (item.concepto === 'MAMO' || item.cuenta_contable === '61251006' || itemNom.includes('MAMO') || itemNom.includes('SENO') || itemCupsNum.startsWith('8768')) {
                    conceptoKey = 'MAMOGRAFÍAS';
                } else if (item.concepto === 'BIOP' || itemNom.includes('BIOP') || itemNom.includes('BIO') || itemNom.includes('BACAF')) {
                    conceptoKey = 'BIOPSIAS';
                } else if (item.concepto === 'BLOQ' || itemNom.includes('BLOQUEO')) {
                    conceptoKey = 'BLOQUEOS';
                } else if (item.concepto === 'RESO' || itemNom.includes('RESONAN') || itemNom.includes('RMN') || itemCupsNum.startsWith('883')) {
                    conceptoKey = 'RESONANCIAS';
                } else if (item.concepto === 'RXSI' || item.cuenta_contable === '61251001' || itemNom.includes('RAYOS X') || itemNom.includes('RADIOGRAF') || itemNom.includes('RX')) {
                    conceptoKey = 'RX SIMPLE';
                } else if (item.concepto_nombre && item.concepto_nombre.trim() !== '') {
                    conceptoKey = item.concepto_nombre.trim().toUpperCase();
                } else if (item.concepto && item.concepto.trim() !== '') {
                    conceptoKey = item.concepto.trim().toUpperCase();
                } else if (item.servinte && item.servinte.concepto) {
                    conceptoKey = item.servinte.concepto.toUpperCase();
                } else if (item.cups) {
                    conceptoKey = item.cups.length > 35 ? item.cups.substring(0, 35) + '...' : item.cups;
                } else {
                    conceptoKey = 'OTROS PROCEDIMIENTOS';
                }

                const cant = 1; // Conteo estricto por número de registros
                const val  = (parseFloat(item.valor_a_pagar) || 0);

                // Conteo de Tomografías Contrastadas para Bonificación ($150.000 COP por cada 50)
                if (item.es_tomografia_contrastada) {
                    totalTomosContrastadasSels += cant;
                    sedesTomoCount[sedeNombre] = (sedesTomoCount[sedeNombre] || 0) + cant;
                }

                if (!sedesMap[sedeNombre].conceptos[conceptoKey]) {
                    sedesMap[sedeNombre].conceptos[conceptoKey] = {
                        nombre: conceptoKey,
                        cant: 0,
                        valor: 0,
                        cruzadoCant: 0,
                        cruzadoValor: 0,
                        noCruzadoCant: 0,
                        noCruzadoValor: 0,
                        cruceTipos: new Set()
                    };
                }

                sedesMap[sedeNombre].conceptos[conceptoKey].cant += cant;
                sedesMap[sedeNombre].conceptos[conceptoKey].valor += val;

                if (esCruzado) {
                    sedesMap[sedeNombre].conceptos[conceptoKey].cruzadoCant += cant;
                    sedesMap[sedeNombre].conceptos[conceptoKey].cruzadoValor += val;
                    sedesMap[sedeNombre].cruzadoValor += val;
                    sedesMap[sedeNombre].cruzadoCount += 1;
                    totalCruzadosOK += val;
                } else {
                    sedesMap[sedeNombre].conceptos[conceptoKey].noCruzadoCant += cant;
                    sedesMap[sedeNombre].conceptos[conceptoKey].noCruzadoValor += val;
                    sedesMap[sedeNombre].conceptos[conceptoKey].cruceTipos.add(cruceTipo);
                    sedesMap[sedeNombre].noCruzadoValor += val;
                    sedesMap[sedeNombre].noCruzadoCount += 1;
                    totalNoCruzados += val;
                    countNoCruzados += 1;
                }

                sedesMap[sedeNombre].totalCant += cant;
                sedesMap[sedeNombre].totalValor += val;
            });

            // Bonificación por Tomografías Contrastadas (1 bonificación de $150.000 COP por cada 50 contrastadas seleccionadas)
            const cantBonificaciones = Math.floor(totalTomosContrastadasSels / 50);
            const valorBonificacionTotal = cantBonificaciones * 150000;

            const sedesKeys = Object.keys(sedesMap).sort();

            if (cantBonificaciones > 0 && sedesKeys.length > 0) {
                // Anexar la bonificación a la sede con mayor volumen de tomografías contrastadas
                let sedeMayorTomo = sedesKeys[0];
                let maxTomo = -1;
                sedesKeys.forEach(sKey => {
                    const countSede = sedesTomoCount[sKey] || 0;
                    if (countSede > maxTomo) {
                        maxTomo = countSede;
                        sedeMayorTomo = sKey;
                    }
                });

                const boniKey = '61251007-BONIFICACION TOMOGRAFIAS';
                sedesMap[sedeMayorTomo].conceptos[boniKey] = {
                    nombre: `BONIFICACIÓN TOMOGRAFÍAS CONTRASTADAS (${totalTomosContrastadasSels} CONTRASTADAS / ${cantBonificaciones}x$150.000 COP)`,
                    cant: cantBonificaciones,
                    valor: valorBonificacionTotal,
                    cruzadoCant: cantBonificaciones,
                    cruzadoValor: valorBonificacionTotal,
                    noCruzadoCant: 0,
                    noCruzadoValor: 0,
                    cruceTipos: new Set(),
                    esBonificacion: true
                };
                sedesMap[sedeMayorTomo].totalCant += cantBonificaciones;
                sedesMap[sedeMayorTomo].totalValor += valorBonificacionTotal;
                sedesMap[sedeMayorTomo].cruzadoValor += valorBonificacionTotal;
                totalCruzadosOK += valorBonificacionTotal;
            }

            const containerSedes = document.getElementById('liqContainerSedes');
            containerSedes.innerHTML = '';

            totalFacturaBaseLiquidador = 0;

            if (sedesKeys.length === 0) {
                containerSedes.innerHTML = `
                    <div class="col-span-2 py-8 text-center text-slate-400">
                        <span class="material-symbols-outlined text-3xl block mb-1">info</span>
                        <p class="font-bold text-xs">No hay datos en pantalla para generar la liquidación.</p>
                        <p class="text-[11px]">Realiza una consulta primero para cargar los registros.</p>
                    </div>
                `;
            } else {
                sedesKeys.forEach(sKey => {
                    const sObj = sedesMap[sKey];
                    totalFacturaBaseLiquidador += sObj.totalValor;

                    let warningBadgeHeader = '';
                    if (sObj.noCruzadoCount > 0) {
                        warningBadgeHeader = `
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#f9f5ec] text-[#664b22] dark:bg-amber-950 dark:text-amber-300 border border-[#e5d9c2] dark:border-amber-700 flex items-center gap-1 shrink-0" title="${sObj.noCruzadoCount} registro(s) no cruzado(s) incluidos en esta sede">
                                <span class="material-symbols-outlined text-xs text-[#8a6021] dark:text-amber-400">warning</span>
                                ${sObj.noCruzadoCount} no cruzado(s) ($${sObj.noCruzadoValor.toLocaleString('es-CO')})
                            </span>
                        `;
                    }

                    let conceptosRowsHtml = '';
                    Object.values(sObj.conceptos).forEach(cObj => {
                        if (cObj.esBonificacion) {
                            conceptosRowsHtml += `
                                <tr class="border-b border-[#e9dfcc] dark:border-amber-900/60 bg-[#faf6ee] dark:bg-amber-950/30">
                                    <td class="py-2 px-2 text-[11px] font-bold text-[#4a3713] dark:text-amber-200">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-black uppercase bg-[#ece2cb] text-[#4a3713] dark:bg-amber-900/90 dark:text-amber-100 border border-[#d8c8a8] dark:border-amber-700 shadow-xs">
                                                <span class="material-symbols-outlined text-xs">military_tech</span> BONIFICACIÓN
                                            </span>
                                            <span class="truncate max-w-[200px]" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                        </div>
                                    </td>
                                    <td class="py-2 px-2 text-right font-mono font-bold text-[#5c4217] dark:text-amber-300 text-[11px]">${cObj.cant} bono(s)</td>
                                    <td class="py-2 px-2 text-right font-mono font-black text-[#8c5717] dark:text-amber-300 text-[11px]">+$ ${cObj.valor.toLocaleString('es-CO')}</td>
                                </tr>
                            `;
                            return;
                        }

                        let advertenciaConcepto = '';
                        if (cObj.noCruzadoCant > 0) {
                            const tiposArr = Array.from(cObj.cruceTipos).map(t => {
                                if (t === 'SOLO_PROTEO') return 'Solo Proteo';
                                if (t === 'SOLO_SERVINTE') return 'Solo Servinte';
                                return 'No Cruzado';
                            });
                            const tiposStr = tiposArr.join(', ');

                            if (cObj.cruzadoCant === 0) {
                                advertenciaConcepto = `<span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-200 dark:border-rose-800 ml-1.5 shrink-0" title="Registro no cruzado en la conciliación (${tiposStr})"><span class="material-symbols-outlined text-[10px]">cancel</span> No Cruzado (${tiposStr})</span>`;
                            } else {
                                advertenciaConcepto = `<span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800 ml-1.5 shrink-0" title="Incluye ${cObj.noCruzadoCant} registro(s) no cruzado(s) ($${cObj.noCruzadoValor.toLocaleString('es-CO')})"><span class="material-symbols-outlined text-[10px]">warning</span> ${cObj.noCruzadoCant} No Cruzado(s)</span>`;
                            }
                        }

                        conceptosRowsHtml += `
                            <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="py-1.5 px-2 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                                    <div class="flex items-center flex-wrap gap-1">
                                        <span class="truncate max-w-[180px]" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                        ${advertenciaConcepto}
                                    </div>
                                </td>
                                <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-[11px]">${cObj.cant.toLocaleString('es-CO')}</td>
                                <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-900 dark:text-white text-[11px]">$ ${cObj.valor.toLocaleString('es-CO')}</td>
                            </tr>
                        `;
                    });

                    const cardSedeHtml = `
                        <div class="border border-slate-200 dark:border-slate-700/80 rounded-xl overflow-hidden flex flex-col bg-white dark:bg-slate-900 shadow-xs">
                            <div class="bg-primary hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700/80 cursor-pointer transition-colors py-2 px-3.5 border-b border-primary/20 dark:border-slate-700 font-bold text-xs text-white font-outfit uppercase flex items-center justify-between gap-2 group" onclick="mostrarInformeExamenesSedePreview('${htmlspecialchars(sObj.nombre)}')">
                                <span class="truncate flex items-center gap-1.5 text-white">
                                    <i class="fa-solid fa-building text-tertiary"></i>
                                    <span>${htmlspecialchars(sObj.nombre)}</span>
                                </span>
                                <div class="flex items-center gap-2">
                                    ${warningBadgeHeader}
                                    <span class="text-[10px] text-tertiary group-hover:text-white group-hover:underline font-extrabold flex items-center gap-1">
                                        <span>VER INFORME</span>
                                        <i class="fa-solid fa-arrow-right text-[9px]"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="overflow-x-auto flex-1 p-1">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 dark:bg-slate-800/50 text-[10px] font-bold text-slate-400 uppercase border-b border-slate-200 dark:border-slate-700">
                                            <th class="py-1.5 px-2">SERVICIO / CONCEPTO</th>
                                            <th class="py-1.5 px-2 text-right">CANT</th>
                                            <th class="py-1.5 px-2 text-right">VALOR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${conceptosRowsHtml}
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 pt-2 border-t-2 border-slate-200 dark:border-slate-700 flex justify-between items-center text-xs font-bold text-slate-900 dark:text-white px-2 pb-1">
                                <span>TOTAL SEDE:</span>
                                <span class="font-mono text-sm font-extrabold text-primary dark:text-tertiary">$ ${sObj.totalValor.toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    `;
                    containerSedes.insertAdjacentHTML('beforeend', cardSedeHtml);
                });
            }

            // Renderizar Resumen Administrativo Lateral
            const tbodyResumen = document.getElementById('liqTbodyResumenSedes');
            tbodyResumen.innerHTML = '';

            sedesKeys.forEach(sKey => {
                const sObj = sedesMap[sKey];
                let noCruzadoSedeTag = '';
                if (sObj.noCruzadoCount > 0) {
                    noCruzadoSedeTag = `<span class="inline-flex items-center gap-0.5 text-amber-600 dark:text-amber-400 font-extrabold text-[10px] ml-1" title="${sObj.noCruzadoCount} registro(s) no cruzado(s) por valor de $${sObj.noCruzadoValor.toLocaleString('es-CO')}"><span class="material-symbols-outlined text-xs">warning</span> (${sObj.noCruzadoCount} no cruzado)</span>`;
                }

                tbodyResumen.insertAdjacentHTML('beforeend', `
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <td class="py-1.5 px-3 text-[11px] font-medium text-slate-700 dark:text-slate-300">${htmlspecialchars(sObj.nombre)} ${noCruzadoSedeTag}</td>
                        <td class="py-1.5 px-3 text-right font-mono font-bold text-slate-900 dark:text-white">$ ${sObj.totalValor.toLocaleString('es-CO')}</td>
                    </tr>
                `);
            });

            document.getElementById('liqTotalFacturaSum').textContent = `$ ${totalFacturaBaseLiquidador.toLocaleString('es-CO')}`;

            // Banner Bonificación Tomografías Contrastadas
            const bannerBoniTomo = document.getElementById('liqBannerBonificacionTomo');
            if (bannerBoniTomo) {
                if (cantBonificaciones > 0) {
                    bannerBoniTomo.classList.remove('hidden');
                    const elVal = document.getElementById('liqValBonificacionTomoDisplay');
                    const elCant = document.getElementById('liqCantBonificacionTomoDisplay');
                    const elContr = document.getElementById('liqCantContrastadasDisplay');
                    if (elVal) elVal.textContent = valorBonificacionTotal.toLocaleString('es-CO');
                    if (elCant) elCant.textContent = cantBonificaciones.toLocaleString('es-CO');
                    if (elContr) elContr.textContent = totalTomosContrastadasSels.toLocaleString('es-CO');
                } else {
                    bannerBoniTomo.classList.add('hidden');
                }
            }

            const bannerNoCruzados = document.getElementById('liqBannerAdvertenciaNoCruzados');
            if (bannerNoCruzados) {
                if (countNoCruzados > 0) {
                    bannerNoCruzados.classList.remove('hidden');
                    document.getElementById('liqCountNoCruzadosDisplay').textContent = countNoCruzados.toLocaleString('es-CO');
                    document.getElementById('liqValNoCruzadosDisplay').textContent = totalNoCruzados.toLocaleString('es-CO');
                } else {
                    bannerNoCruzados.classList.add('hidden');
                }
            }

            // Banner de Exclusiones
            const bannerExclusiones = document.getElementById('liqBannerExclusiones');
            if (bannerExclusiones) {
                let countExcluidos = excludedRecordsMap.size;
                let totalExcluidos = 0;
                allData.forEach(item => {
                    const uId = item.unique_id || item.id;
                    if (excludedRecordsMap.has(uId)) {
                        totalExcluidos += (parseFloat(item.valor_a_pagar) || 0);
                    }
                });

                if (countExcluidos > 0) {
                    bannerExclusiones.classList.remove('hidden');
                    const elCnt = document.getElementById('liqCountExcluidosDisplay');
                    const elVal = document.getElementById('liqValExcluidosDisplay');
                    if (elCnt) elCnt.textContent = countExcluidos.toLocaleString('es-CO');
                    if (elVal) elVal.textContent = totalExcluidos.toLocaleString('es-CO');
                } else {
                    bannerExclusiones.classList.add('hidden');
                }
            }

            // Determinar si el médico liquidado tiene Parafiscales activos, si tiene IBC activo, si es Pensionado, Retenciones, Retención Art 383 o ARL
            let hasParafiscales  = false;
            let hasAfc           = false;
            let hasIbc           = false;
            let isPensionado     = false;
            let hasRetenciones   = false;
            let hasRetencion383  = false;
            let hasArl           = false;

            if (medicoVal) {
                const uVal = String(medicoVal).trim().toUpperCase();
                const uNom = String(medicoLabel).trim().toUpperCase();
                if (window.medicosParafiscalesMap && (window.medicosParafiscalesMap[uVal] || window.medicosParafiscalesMap[uNom])) {
                    hasParafiscales = true;
                }
                if ((window.medicosAfcMap && (window.medicosAfcMap[uVal] || window.medicosAfcMap[uNom])) || (window.medicosIbcMap && (window.medicosIbcMap[uVal] || window.medicosIbcMap[uNom]))) {
                    hasAfc = true;
                    hasIbc = true;
                }
                if (window.medicosPensionadosMap && (window.medicosPensionadosMap[uVal] || window.medicosPensionadosMap[uNom])) {
                    isPensionado = true;
                }
                if (window.medicosRetencionesMap && (window.medicosRetencionesMap[uVal] || window.medicosRetencionesMap[uNom])) {
                    hasRetenciones = true;
                }
                if (window.medicosRetencion383Map && (window.medicosRetencion383Map[uVal] || window.medicosRetencion383Map[uNom])) {
                    hasRetencion383 = true;
                }
                if (window.medicosArlMap && (window.medicosArlMap[uVal] || window.medicosArlMap[uNom])) {
                    hasArl = true;
                }
            }
            const selectedBtn = document.querySelector(`.medico-option-btn[data-value="${medicoVal}"]`);
            if (selectedBtn) {
                if (selectedBtn.getAttribute('data-parafiscales') === '1') hasParafiscales = true;
                if (selectedBtn.getAttribute('data-afc') === '1' || selectedBtn.getAttribute('data-ibc') === '1') {
                    hasAfc = true;
                    hasIbc = true;
                }
                if (selectedBtn.getAttribute('data-pensionado') === '1') isPensionado = true;
                if (selectedBtn.getAttribute('data-retenciones') === '1') hasRetenciones = true;
                if (selectedBtn.getAttribute('data-retencion-383') === '1') hasRetencion383 = true;
                if (selectedBtn.getAttribute('data-arl') === '1') hasArl = true;
            }
            if (dataToProcess && dataToProcess.length > 0) {
                const primerMedico = (dataToProcess[0].usuario_medico || dataToProcess[0].usuario || '').trim().toUpperCase();
                const primerNom    = (dataToProcess[0].nombre || '').trim().toUpperCase();
                if (!hasParafiscales && window.medicosParafiscalesMap && (window.medicosParafiscalesMap[primerMedico] || window.medicosParafiscalesMap[primerNom])) {
                    hasParafiscales = true;
                }
                if (!hasAfc && ((window.medicosAfcMap && (window.medicosAfcMap[primerMedico] || window.medicosAfcMap[primerNom])) || (window.medicosIbcMap && (window.medicosIbcMap[primerMedico] || window.medicosIbcMap[primerNom])))) {
                    hasAfc = true;
                    hasIbc = true;
                }
                if (!isPensionado && window.medicosPensionadosMap && (window.medicosPensionadosMap[primerMedico] || window.medicosPensionadosMap[primerNom])) {
                    isPensionado = true;
                }
                if (!hasRetenciones && window.medicosRetencionesMap && (window.medicosRetencionesMap[primerMedico] || window.medicosRetencionesMap[primerNom])) {
                    hasRetenciones = true;
                }
                if (!hasRetencion383 && window.medicosRetencion383Map && (window.medicosRetencion383Map[primerMedico] || window.medicosRetencion383Map[primerNom])) {
                    hasRetencion383 = true;
                }
                if (!hasArl && window.medicosArlMap && (window.medicosArlMap[primerMedico] || window.medicosArlMap[primerNom])) {
                    hasArl = true;
                }
            }

            // Exclusividad: solo una de las dos modalidades o ninguna
            if (hasRetenciones && hasRetencion383) {
                hasRetencion383 = false;
            }

            const hasAnyDeductionActive = (hasParafiscales || hasAfc || hasIbc || isPensionado || hasRetenciones || hasRetencion383 || hasArl);

            window.currentMedicoHasParafiscales  = hasParafiscales;
            window.currentMedicoHasAfc          = hasAfc;
            window.currentMedicoHasIbc          = hasIbc;
            window.currentMedicoIsPensionado     = isPensionado;
            window.currentMedicoHasRetenciones   = hasRetenciones;
            window.currentMedicoHasRetencion383  = hasRetencion383;
            window.currentMedicoHasArl           = hasArl;
            window.currentMedicoHasAnyDeduction  = hasAnyDeductionActive;

            const ibcPct     = (window.parafiscalesConfig && (window.parafiscalesConfig.IBC !== undefined ? window.parafiscalesConfig.IBC : window.parafiscalesConfig.AFC) !== undefined) ? Number(window.parafiscalesConfig.IBC ?? window.parafiscalesConfig.AFC) : 40.0;
            const afcPct     = ibcPct;
            const saludPct   = (window.parafiscalesConfig && window.parafiscalesConfig.SALUD !== undefined) ? Number(window.parafiscalesConfig.SALUD) : 12.5;
            const pensionPct = (window.parafiscalesConfig && window.parafiscalesConfig.PENSION !== undefined) ? Number(window.parafiscalesConfig.PENSION) : 16.0;
            const arlPct     = (window.parafiscalesConfig && window.parafiscalesConfig.ARL !== undefined) ? Number(window.parafiscalesConfig.ARL) : 2.436;

            const aplicaSeguridadSocial = (hasParafiscales || isPensionado || hasArl);
            const hasAportesSS = hasParafiscales;

            // Actualizar distintivo visual en la tarjeta de deducciones
            const badgeDot       = document.getElementById('liqParafiscalesDot');
            const badgeLbl       = document.getElementById('liqParafiscalesLabel');
            const badgeRates     = document.getElementById('liqParafiscalesRatesText');
            const badgeContainer = document.getElementById('liqParafiscalesStatusBadge');

            if (badgeContainer) {
                if (isGlobalMed || !hasAnyDeductionActive) {
                    badgeContainer.classList.add('hidden');
                } else {
                    badgeContainer.classList.remove('hidden');
                    if (badgeDot && badgeLbl) {
                        if (isPensionado) {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-indigo-500 animate-pulse';
                            badgeLbl.textContent = hasArl ? 'Médico Pensionado (Salud y ARL)' : 'Médico Pensionado (Solo Salud)';
                            badgeLbl.className = 'text-indigo-700 dark:text-indigo-300 font-extrabold';
                            if (badgeRates) {
                                badgeRates.textContent = hasArl ? `Salud: ${saludPct}% | ARL: ${arlPct}% | Pensión: $0 (Exento)` : `Salud: ${saludPct}% | Pensión: $0 (Exento)`;
                            }
                        } else if ((hasAfc || hasIbc) && !hasParafiscales) {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-teal-500 animate-pulse';
                            badgeLbl.textContent = hasArl ? 'AFC y ARL Activos' : 'AFC Activo para este Médico';
                            badgeLbl.className = 'text-teal-700 dark:text-teal-300 font-extrabold';
                            if (badgeRates) {
                                badgeRates.textContent = hasArl ? `ARL: ${arlPct}% | Deducción Manual` : 'Deducción Manual';
                            }
                        } else if (hasParafiscales && (hasAfc || hasIbc)) {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-teal-500 animate-pulse';
                            badgeLbl.textContent = hasArl ? 'Parafiscales, AFC y ARL Activos' : 'Parafiscales y AFC Activos';
                            badgeLbl.className = 'text-teal-700 dark:text-teal-300 font-extrabold';
                            if (badgeRates) badgeRates.textContent = hasArl ? `Salud: ${saludPct}% | Pensión: ${pensionPct}% | ARL: ${arlPct}% | AFC: Manual` : `Salud: ${saludPct}% | Pensión: ${pensionPct}% | AFC: Manual`;
                        } else if (hasParafiscales) {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-teal-500 animate-pulse';
                            badgeLbl.textContent = hasArl ? 'Parafiscales Activos (Salud, Pensión y ARL)' : 'Parafiscales Activos para este Médico';
                            badgeLbl.className = 'text-teal-700 dark:text-teal-300 font-extrabold';
                            if (badgeRates) badgeRates.textContent = hasArl ? `Salud: ${saludPct}% | Pensión: ${pensionPct}% | ARL: ${arlPct}%` : `Salud: ${saludPct}% | Pensión: ${pensionPct}%`;
                        } else if (hasArl) {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-amber-500 animate-pulse';
                            badgeLbl.textContent = 'Aportes ARL Activos para este Médico';
                            badgeLbl.className = 'text-amber-700 dark:text-amber-300 font-extrabold';
                            if (badgeRates) badgeRates.textContent = `ARL: ${arlPct}%`;
                        } else {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-slate-400';
                            badgeLbl.textContent = 'Parafiscales Desactivados ($0)';
                            badgeLbl.className = 'text-slate-500 dark:text-slate-400 font-medium';
                            if (badgeRates) badgeRates.textContent = 'Valores en $0';
                        }
                    }
                }
            }

            let ibcEstimado     = 0;
            let saludEstimado   = 0;
            let pensionEstimado = 0;
            let arlEstimado     = 0;

            if (aplicaSeguridadSocial && totalFacturaBaseLiquidador > 0) {
                ibcEstimado     = Math.round(totalFacturaBaseLiquidador * (ibcPct / 100));
                saludEstimado   = (hasParafiscales || isPensionado) ? Math.round(ibcEstimado * (saludPct / 100)) : 0;
                pensionEstimado = (hasParafiscales && !isPensionado) ? Math.round(ibcEstimado * (pensionPct / 100)) : 0;
                arlEstimado     = hasArl ? Math.round(ibcEstimado * (arlPct / 100)) : 0;
            }

            // Visibilidad de Deducciones según el perfil del Médico o si es Consolidado Global
            const rowIbc         = document.getElementById('row_ded_ibc');
            const rowAfc         = document.getElementById('row_ded_afc');
            const rowSalud       = document.getElementById('row_ded_salud');
            const rowArl         = document.getElementById('row_ded_arl');
            const rowPension     = document.getElementById('row_ded_pension');
            const rowSolidaridad = document.getElementById('row_ded_solidaridad');
            const rowRetenciones = document.getElementById('row_ded_retenciones');
            const contRete383    = document.getElementById('container_ded_rete_383');
            const contRetencion  = document.getElementById('container_ded_retencion_pct');
            const formBody       = document.getElementById('liqDeduccionesFormBody');
            const noDeducBox     = document.getElementById('liqNoDeduccionesBox');

            window.isCurrentLiquidacionGlobal = isGlobalMed;

            if (isGlobalMed) {
                if (noDeducBox) noDeducBox.classList.add('hidden');
                if (formBody) formBody.classList.remove('hidden');

                // Liquidación Global: Se ocultan parafiscales, aportes, afc y rete 383
                // Únicamente se muestra: PORCENTAJE DE RETENCIÓN, RETENCIÓN y TOTAL DEDUCCIONES
                if (rowIbc)         rowIbc.classList.add('hidden');
                if (rowAfc)         rowAfc.classList.add('hidden');
                if (rowSalud)       rowSalud.classList.add('hidden');
                if (rowArl)         rowArl.classList.add('hidden');
                if (rowPension)     rowPension.classList.add('hidden');
                if (rowSolidaridad) rowSolidaridad.classList.add('hidden');

                if (rowRetenciones) {
                    rowRetenciones.classList.remove('hidden');
                    rowRetenciones.classList.remove('border-t', 'border-slate-100', 'dark:border-slate-800', 'pt-2');
                }
                if (contRete383)    contRete383.classList.add('hidden');
                if (contRetencion)  contRetencion.classList.remove('hidden');

                document.getElementById('ded_afc').value = '0';
                if (document.getElementById('ded_solidaridad_pct')) document.getElementById('ded_solidaridad_pct').value = 0;
                document.getElementById('ded_solidaridad').value = '0';
                const dispSolidaridad = document.getElementById('ded_solidaridad_display');
                if (dispSolidaridad) dispSolidaridad.textContent = '- $ 0';
                document.getElementById('ded_ibc').value = '0';
                document.getElementById('ded_salud').value = '0';
                document.getElementById('ded_pension').value = '0';
                document.getElementById('ded_arl').value = '0';
                
                const rete383InfoEl = document.getElementById('ded_rete_383_info');
                if (rete383InfoEl) rete383InfoEl.value = '0';

                document.getElementById('ded_rete_383').value = '0';
                document.getElementById('ded_retencion_pct').value = 0;
                document.getElementById('ded_retencion').value = 0;
            } else if (!hasAnyDeductionActive) {
                // El médico NO tiene ninguna deducción activa (parafiscales, pensionado, retenciones, rete 383)
                if (formBody) formBody.classList.add('hidden');
                if (noDeducBox) noDeducBox.classList.remove('hidden');

                if (rowIbc)         rowIbc.classList.add('hidden');
                if (rowAfc)         rowAfc.classList.add('hidden');
                if (rowSalud)       rowSalud.classList.add('hidden');
                if (rowArl)         rowArl.classList.add('hidden');
                if (rowPension)     rowPension.classList.add('hidden');
                if (rowSolidaridad) rowSolidaridad.classList.add('hidden');
                document.getElementById('ded_afc').value = '0';
                if (document.getElementById('ded_solidaridad_pct')) document.getElementById('ded_solidaridad_pct').value = 0;
                document.getElementById('ded_solidaridad').value = '0';
                const dispSolidaridad = document.getElementById('ded_solidaridad_display');
                if (dispSolidaridad) dispSolidaridad.textContent = '- $ 0';
                document.getElementById('ded_ibc').value = '0';
                document.getElementById('ded_salud').value = '0';
                document.getElementById('ded_pension').value = '0';
                document.getElementById('ded_arl').value = '0';
                
                const rete383InfoEl = document.getElementById('ded_rete_383_info');
                if (rete383InfoEl) rete383InfoEl.value = '0';

                document.getElementById('ded_rete_383').value = '0';
                document.getElementById('ded_retencion_pct').value = 0;
                document.getElementById('ded_retencion').value = 0;
            } else {
                if (noDeducBox) noDeducBox.classList.add('hidden');
                if (formBody) formBody.classList.remove('hidden');

                // Si tiene AFC activo se muestra la fila editable AFC MES; la fila base informativa IBC permanece oculta para no duplicar
                if (hasAfc) {
                    if (rowAfc) rowAfc.classList.remove('hidden');
                    if (rowIbc) rowIbc.classList.add('hidden');
                } else if (hasParafiscales || isPensionado || hasArl) {
                    if (rowAfc) rowAfc.classList.add('hidden');
                    if (rowIbc) rowIbc.classList.remove('hidden');
                } else {
                    if (rowAfc) rowAfc.classList.add('hidden');
                    if (rowIbc) rowIbc.classList.add('hidden');
                }

                // Salud (Si tiene parafiscales o es pensionado)
                if (hasParafiscales || isPensionado) {
                    if (rowSalud) rowSalud.classList.remove('hidden');
                } else {
                    if (rowSalud) rowSalud.classList.add('hidden');
                }

                // ARL
                if (hasArl) {
                    if (rowArl)   rowArl.classList.remove('hidden');
                } else {
                    if (rowArl)   rowArl.classList.add('hidden');
                }

                if (rowRetenciones) {
                    rowRetenciones.classList.add('border-t', 'border-slate-100', 'dark:border-slate-800', 'pt-2');
                }

                // Pensión (Se muestra solo si tiene parafiscales y no es pensionado)
                if (hasParafiscales && !isPensionado) {
                    if (rowPension) rowPension.classList.remove('hidden');
                } else {
                    if (rowPension) rowPension.classList.add('hidden');
                }

                // Fondo de Solidaridad (Visible únicamente si el médico tiene Parafiscales activos y no es pensionado)
                if (hasParafiscales && !isPensionado) {
                    if (rowSolidaridad) rowSolidaridad.classList.remove('hidden');
                } else {
                    if (rowSolidaridad) rowSolidaridad.classList.add('hidden');
                    const elSolPctOff = document.getElementById('ded_solidaridad_pct');
                    if (elSolPctOff) elSolPctOff.value = 0;
                    document.getElementById('ded_solidaridad').value = '0';
                }

                // Retenciones: Se muestran según si tiene Retención Art 383 o Retención estándar, SIN importar si es pensionado
                if (hasRetencion383) {
                    if (rowRetenciones) rowRetenciones.classList.remove('hidden');
                    if (contRete383)    contRete383.classList.remove('hidden');
                    if (contRetencion)  contRetencion.classList.add('hidden');
                } else if (hasRetenciones) {
                    if (rowRetenciones) rowRetenciones.classList.remove('hidden');
                    if (contRete383)    contRete383.classList.add('hidden');
                    if (contRetencion)  contRetencion.classList.remove('hidden');
                } else {
                    if (rowRetenciones) rowRetenciones.classList.add('hidden');
                    if (contRete383)    contRete383.classList.add('hidden');
                    if (contRetencion)  contRetencion.classList.add('hidden');
                }

                document.getElementById('ded_afc').value = '0';
                if (document.getElementById('ded_solidaridad_pct')) document.getElementById('ded_solidaridad_pct').value = 0;
                document.getElementById('ded_solidaridad').value = '0';
                const dispSolidaridad = document.getElementById('ded_solidaridad_display');
                if (dispSolidaridad) dispSolidaridad.textContent = '- $ 0';
                document.getElementById('ded_ibc').value = ibcEstimado;
                document.getElementById('ded_salud').value = saludEstimado;
                document.getElementById('ded_pension').value = pensionEstimado;
                document.getElementById('ded_arl').value = arlEstimado;
                
                const rete383InfoEl = document.getElementById('ded_rete_383_info');
                if (rete383InfoEl) rete383InfoEl.value = '0';

                document.getElementById('ded_rete_383').value = '0';
                document.getElementById('ded_retencion_pct').value = 0;
                document.getElementById('ded_retencion').value = 0;
            }

            recalcularLiquidacion(false);

            const modal = document.getElementById('modalLiquidacion');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0', 'pointer-events-none');
                modal.firstElementChild.classList.remove('scale-95');
                modal.firstElementChild.classList.add('scale-100');
            }, 10);
        }

        window.novedadesLiquidacionAplicadas = [];
        window.catalogoNovedadesLiqCache = {};

        async function alCambiarToggleNovedadesLiq(chk) {
            const isChecked = chk.checked;
            const entId = window.currentEntidadIdLiq || <?php echo json_encode($entidadActivaId ?: 'PROPIO'); ?>;
            const medicoLabel = (document.getElementById('selectedMedicoLabel') ? document.getElementById('selectedMedicoLabel').textContent : '').trim() || '-- Todos los Médicos --';

            if (isChecked) {
                // Registrar log de auditoría
                fetch(`gestion_medicos_procedimientos.php?action=registrar_log_toggle_novedades&referencia_id=PRE-LIQ&entidad_id=${encodeURIComponent(entId)}&medico=${encodeURIComponent(medicoLabel)}&activo=1`);
                abrirModalNovedadesLiq();
            } else {
                if (window.novedadesLiquidacionAplicadas && window.novedadesLiquidacionAplicadas.length > 0) {
                    const confirmDeselect = await SwalCustom.fire({
                        title: '¿Remover novedades?',
                        text: 'Al desmarcar esta opción se removerán todas las novedades que había aplicado a esta liquidación.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, remover novedades',
                        cancelButtonText: 'Mantener novedades'
                    });

                    if (!confirmDeselect.isConfirmed) {
                        chk.checked = true;
                        return;
                    }

                    window.novedadesLiquidacionAplicadas = [];
                    actualizarResumenBadgesNovedadesLiq();
                    recalcularLiquidacion(true);
                }

                fetch(`gestion_medicos_procedimientos.php?action=registrar_log_toggle_novedades&referencia_id=PRE-LIQ&entidad_id=${encodeURIComponent(entId)}&medico=${encodeURIComponent(medicoLabel)}&activo=0`);
            }
        }

        async function abrirModalNovedadesLiq() {
            const entId = window.currentEntidadIdLiq || <?php echo json_encode($entidadActivaId ?: 'PROPIO'); ?>;
            const entNombre = window.currentEntidadNombreLiq || <?php echo json_encode($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>;
            const modal = document.getElementById('modalNovedadesLiq');
            const subhead = document.getElementById('modalNovLiqSubhead');
            const loading = document.getElementById('modalNovLiqLoading');
            const empty = document.getElementById('modalNovLiqEmpty');
            const cardsContainer = document.getElementById('modalNovLiqCards');

            subhead.textContent = `Entidad: ${entNombre} (${entId})`;
            modal.classList.remove('hidden');

            loading.classList.remove('hidden');
            empty.classList.add('hidden');
            cardsContainer.classList.add('hidden');
            cardsContainer.innerHTML = '';

            try {
                let novedades = window.catalogoNovedadesLiqCache[entId];
                if (!novedades) {
                    const res = await fetch(`gestion_medicos_procedimientos.php?action=obtener_novedades_entidad&entidad_id=${encodeURIComponent(entId)}`);
                    const json = await res.json();
                    if (json.success && Array.isArray(json.data)) {
                        novedades = json.data;
                        window.catalogoNovedadesLiqCache[entId] = novedades;
                    } else {
                        novedades = [];
                    }
                }

                loading.classList.add('hidden');

                if (novedades.length === 0) {
                    empty.classList.remove('hidden');
                    return;
                }

                cardsContainer.classList.remove('hidden');

                // Renderizar cards
                novedades.forEach((nov, idx) => {
                    const yaAplicada = (window.novedadesLiquidacionAplicadas || []).find(n => n.codigo === nov.codigo);
                    const isChecked = !!yaAplicada;
                    const valorActual = yaAplicada ? yaAplicada.valor : (parseFloat(nov.valor_predeterminado) || 0);
                    const obsActual = yaAplicada ? (yaAplicada.observacion || '') : '';
                    const esAdicion = (nov.tipo === 'ADICION');

                    const card = document.createElement('div');
                    card.className = `p-4 rounded-2xl border transition-all ${isChecked ? 'bg-amber-500/10 border-amber-500 dark:border-amber-400' : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700/80 hover:border-amber-400'}`;
                    card.setAttribute('data-nov-codigo', nov.codigo);

                    card.innerHTML = `
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="chkNovLiq_${idx}" ${isChecked ? 'checked' : ''} onchange="alCambiarCheckCardNovedadLiq(this)" class="nov-liq-card-check mt-1 w-4 h-4 text-amber-600 bg-white dark:bg-slate-800 border-amber-400 rounded focus:ring-amber-500 cursor-pointer" />
                            <div class="flex-1 space-y-2">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <label for="chkNovLiq_${idx}" class="font-bold text-xs text-slate-800 dark:text-slate-100 cursor-pointer flex items-center gap-1.5">
                                        <span class="font-mono text-[11px] px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-black">${nov.codigo}</span>
                                        <span>${htmlspecialchars(nov.nombre)}</span>
                                    </label>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${esAdicion ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-300 dark:border-rose-700'}">
                                        ${esAdicion ? '+ ADICIÓN' : '- DEDUCCIÓN'}
                                    </span>
                                </div>
                                ${nov.descripcion ? `<p class="text-[11px] text-slate-500 dark:text-slate-400">${htmlspecialchars(nov.descripcion)}</p>` : ''}
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-600 dark:text-slate-400 uppercase">Valor ($ COP)</label>
                                        <input type="number" step="0.01" min="0" value="${valorActual}" placeholder="0" class="nov-liq-card-valor w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs font-mono font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-amber-500" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-600 dark:text-slate-400 uppercase">Observación / Detalle</label>
                                        <input type="text" value="${htmlspecialchars(obsActual)}" placeholder="Opcional..." class="nov-liq-card-obs w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-amber-500" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    card._novData = nov;
                    cardsContainer.appendChild(card);
                });

                actualizarConteoSeleccionadasNovedadesLiq();

            } catch(e) {
                loading.classList.add('hidden');
                empty.classList.remove('hidden');
                console.error("Error al cargar novedades de la entidad:", e);
            }
        }

        function alCambiarCheckCardNovedadLiq(inputEl) {
            const card = inputEl.closest('[data-nov-codigo]');
            if (inputEl.checked) {
                card.classList.add('bg-amber-500/10', 'border-amber-500', 'dark:border-amber-400');
                card.classList.remove('bg-slate-50', 'dark:bg-slate-800/60', 'border-slate-200', 'dark:border-slate-700/80');
            } else {
                card.classList.remove('bg-amber-500/10', 'border-amber-500', 'dark:border-amber-400');
                card.classList.add('bg-slate-50', 'dark:bg-slate-800/60', 'border-slate-200', 'dark:border-slate-700/80');
            }
            actualizarConteoSeleccionadasNovedadesLiq();
        }

        function actualizarConteoSeleccionadasNovedadesLiq() {
            const count = document.querySelectorAll('#modalNovLiqCards .nov-liq-card-check:checked').length;
            const el = document.getElementById('modalNovLiqSeleccionadasCount');
            if (el) el.textContent = count;
        }

        function cerrarModalNovedadesLiq() {
            document.getElementById('modalNovedadesLiq').classList.add('hidden');
            if (!window.novedadesLiquidacionAplicadas || window.novedadesLiquidacionAplicadas.length === 0) {
                const chk = document.getElementById('chkRegistraNovedadesLiq');
                if (chk) chk.checked = false;
            }
        }

        function aplicarNovedadesALiquidacion() {
            const cardEls = document.querySelectorAll('#modalNovLiqCards [data-nov-codigo]');
            const seleccionadas = [];

            cardEls.forEach(card => {
                const chk = card.querySelector('.nov-liq-card-check');
                if (chk && chk.checked) {
                    const nov = card._novData;
                    const val = parseFloat(card.querySelector('.nov-liq-card-valor')?.value) || 0;
                    const obs = (card.querySelector('.nov-liq-card-obs')?.value || '').trim();

                    seleccionadas.push({
                        id: nov.id,
                        codigo: nov.codigo,
                        nombre: nov.nombre,
                        tipo: nov.tipo,
                        valor: val,
                        observacion: obs
                    });
                }
            });

            window.novedadesLiquidacionAplicadas = seleccionadas;
            actualizarResumenBadgesNovedadesLiq();

            const chkMain = document.getElementById('chkRegistraNovedadesLiq');
            if (chkMain) chkMain.checked = (seleccionadas.length > 0);

            cerrarModalNovedadesLiq();
            recalcularLiquidacion(true);
        }

        function actualizarResumenBadgesNovedadesLiq() {
            const container = document.getElementById('containerNovedadesLiqResumen');
            const listaBadges = document.getElementById('listaNovedadesLiqBadges');
            if (!container || !listaBadges) return;

            const items = window.novedadesLiquidacionAplicadas || [];
            if (items.length === 0) {
                container.classList.add('hidden');
                listaBadges.innerHTML = '';
                return;
            }

            container.classList.remove('hidden');
            let badgesHtml = '';
            items.forEach(nov => {
                const esAdicion = (nov.tipo === 'ADICION');
                badgesHtml += `
                    <div class="flex items-center justify-between text-xs py-1 px-2 rounded-lg ${esAdicion ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800'}">
                        <span class="truncate pr-1"><strong>${esAdicion ? '+ ' : '- '}${nov.codigo}</strong>: ${htmlspecialchars(nov.nombre)}</span>
                        <strong class="font-mono shrink-0">$ ${(parseFloat(nov.valor) || 0).toLocaleString('es-CO')}</strong>
                    </div>
                `;
            });
            listaBadges.innerHTML = badgesHtml;
        }

        function recalcularLiquidacion(autoCalcularSeguridadSocial = true) {
            // Novedades de la entidad aplicadas
            let totalNovAdicion = 0;
            let totalNovDeduccion = 0;
            if (window.novedadesLiquidacionAplicadas && window.novedadesLiquidacionAplicadas.length > 0) {
                window.novedadesLiquidacionAplicadas.forEach(n => {
                    const v = parseFloat(n.valor) || 0;
                    if (n.tipo === 'DEDUCCION') {
                        totalNovDeduccion += v;
                    } else {
                        totalNovAdicion += v;
                    }
                });
            }
            const totalNovNeto = totalNovAdicion - totalNovDeduccion;

            // Las novedades afectan directamente al TOTAL FACTURA (base bruta de facturación para deducciones)
            const totalFactura = Math.max(0, totalFacturaBaseLiquidador + totalNovNeto);

            const elTotalFactura = document.getElementById('liqTotalFacturaSum');
            if (elTotalFactura) {
                elTotalFactura.textContent = `$ ${totalFactura.toLocaleString('es-CO')}`;
            }

            const elNetoNov = document.getElementById('liqTotalNovedadesNetoDisplay');
            if (elNetoNov) {
                elNetoNov.textContent = (totalNovNeto >= 0 ? '+ ' : '- ') + '$ ' + Math.abs(totalNovNeto).toLocaleString('es-CO');
                elNetoNov.className = (totalNovNeto >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400') + ' font-mono font-bold text-xs';
            }

            if (window.isCurrentLiquidacionGlobal) {
                // En liquidación global, solo aplica retención porcentual estándar
                document.getElementById('ded_afc').value = '0';
                if (document.getElementById('ded_solidaridad_pct')) document.getElementById('ded_solidaridad_pct').value = 0;
                document.getElementById('ded_solidaridad').value = '0';
                const dispSolidaridad = document.getElementById('ded_solidaridad_display');
                if (dispSolidaridad) dispSolidaridad.textContent = `- $ 0`;
                document.getElementById('ded_ibc').value = 0;
                document.getElementById('ded_salud').value = 0;
                document.getElementById('ded_pension').value = 0;
                document.getElementById('ded_arl').value = 0;
                document.getElementById('ded_rete_383').value = 0;

                const retencionPct = parseFloat(document.getElementById('ded_retencion_pct').value) || 0;
                const retencion = (retencionPct > 0 && totalFactura > 0) ? Math.round(totalFactura * (retencionPct / 100)) : 0;
                document.getElementById('ded_retencion').value = retencion;
                const dispRetencion = document.getElementById('ded_retencion_display');
                if (dispRetencion) dispRetencion.textContent = `- $ ${retencion.toLocaleString('es-CO')}`;

                const totalDeducciones = retencion;
                const totalPagar = Math.max(0, totalFactura - totalDeducciones);

                document.getElementById('liqTotalDeduccionesDisplay').textContent = `- $ ${totalDeducciones.toLocaleString('es-CO')}`;
                document.getElementById('liqTotalPagarDisplay').textContent = `$ ${totalPagar.toLocaleString('es-CO')}`;
                return;
            }

            if (!window.isCurrentLiquidacionGlobal && !window.currentMedicoHasAnyDeduction) {
                document.getElementById('ded_afc').value = '0';
                if (document.getElementById('ded_solidaridad_pct')) document.getElementById('ded_solidaridad_pct').value = 0;
                document.getElementById('ded_solidaridad').value = '0';
                const dispSolidaridad = document.getElementById('ded_solidaridad_display');
                if (dispSolidaridad) dispSolidaridad.textContent = `- $ 0`;
                document.getElementById('ded_ibc').value = 0;
                document.getElementById('ded_salud').value = 0;
                document.getElementById('ded_pension').value = 0;
                document.getElementById('ded_arl').value = 0;
                document.getElementById('ded_rete_383').value = 0;
                document.getElementById('ded_retencion').value = 0;
                const dispRetencion = document.getElementById('ded_retencion_display');
                if (dispRetencion) dispRetencion.textContent = `- $ 0`;
                document.getElementById('liqTotalDeduccionesDisplay').textContent = `- $ 0`;
                const totalPagar = totalFactura;
                document.getElementById('liqTotalPagarDisplay').textContent = `$ ${totalPagar.toLocaleString('es-CO')}`;
                return;
            }

            const ibcPct     = (window.parafiscalesConfig && (window.parafiscalesConfig.IBC !== undefined ? window.parafiscalesConfig.IBC : window.parafiscalesConfig.AFC) !== undefined) ? Number(window.parafiscalesConfig.IBC ?? window.parafiscalesConfig.AFC) : 40.0;
            const afcPct     = ibcPct;
            const saludPct   = (window.parafiscalesConfig && window.parafiscalesConfig.SALUD !== undefined) ? Number(window.parafiscalesConfig.SALUD) : 12.5;
            const pensionPct = (window.parafiscalesConfig && window.parafiscalesConfig.PENSION !== undefined) ? Number(window.parafiscalesConfig.PENSION) : 16.0;
            const arlPct     = (window.parafiscalesConfig && window.parafiscalesConfig.ARL !== undefined) ? Number(window.parafiscalesConfig.ARL) : 2.436;

            const afc         = parseMontoInput(document.getElementById('ded_afc').value);

            let ibc = parseFloat(document.getElementById('ded_ibc').value) || 0;
            const aplicaSeguridadSocial = (window.currentMedicoHasParafiscales || window.currentMedicoIsPensionado || window.currentMedicoHasArl);
            const hasAportesSS = window.currentMedicoHasParafiscales;
            
            if (autoCalcularSeguridadSocial && totalFactura > 0) {
                if (aplicaSeguridadSocial) {
                    ibc = Math.round(totalFactura * (ibcPct / 100));
                } else {
                    ibc = 0;
                }
                document.getElementById('ded_ibc').value = ibc;
            }
            const dispIbc = document.getElementById('ded_ibc_display');
            if (dispIbc) dispIbc.textContent = `$ ${ibc.toLocaleString('es-CO')}`;

            // Fondo de Solidaridad: Solo aplica si el médico tiene Parafiscales activos y se calcula sobre el IBC
            const solidaridadPct = (hasAportesSS && document.getElementById('ded_solidaridad_pct')) ? (parseFloat(document.getElementById('ded_solidaridad_pct').value) || 0) : 0;
            let solidaridad = 0;
            if (hasAportesSS && solidaridadPct > 0 && ibc > 0) {
                solidaridad = Math.round(ibc * (solidaridadPct / 100));
            }
            document.getElementById('ded_solidaridad').value = solidaridad;
            const dispSolidaridad = document.getElementById('ded_solidaridad_display');
            if (dispSolidaridad) dispSolidaridad.textContent = `- $ ${solidaridad.toLocaleString('es-CO')}`;

            let salud   = parseFloat(document.getElementById('ded_salud').value) || 0;
            let pension = parseFloat(document.getElementById('ded_pension').value) || 0;
            let arl     = parseFloat(document.getElementById('ded_arl').value) || 0;

            if (autoCalcularSeguridadSocial) {
                if (aplicaSeguridadSocial && ibc > 0) {
                    salud   = (hasAportesSS || window.currentMedicoIsPensionado) ? Math.round(ibc * (saludPct / 100)) : 0;
                    pension = (hasAportesSS && !window.currentMedicoIsPensionado) ? Math.round(ibc * (pensionPct / 100)) : 0;
                    arl     = window.currentMedicoHasArl ? Math.round(ibc * (arlPct / 100)) : 0;
                } else {
                    salud   = 0;
                    pension = 0;
                    arl     = 0;
                }
                document.getElementById('ded_salud').value = salud;
                document.getElementById('ded_pension').value = pension;
                document.getElementById('ded_arl').value = arl;
            }

            const dispSalud = document.getElementById('ded_salud_display');
            if (dispSalud) dispSalud.textContent = `- $ ${salud.toLocaleString('es-CO')}`;

            const dispPension = document.getElementById('ded_pension_display');
            if (dispPension) dispPension.textContent = `- $ ${pension.toLocaleString('es-CO')}`;

            const dispArl = document.getElementById('ded_arl_display');
            if (dispArl) dispArl.textContent = `- $ ${arl.toLocaleString('es-CO')}`;

            // Actualizar etiqueta de tasas si aplica seguridad social o AFC
            const badgeRates = document.getElementById('liqParafiscalesRatesText');
            if (badgeRates) {
                if (aplicaSeguridadSocial && window.currentMedicoHasAfc) {
                    let ratesText = `Salud: ${saludPct}%`;
                    if (!window.currentMedicoIsPensionado) ratesText += ` | Pensión: ${pensionPct}%`;
                    if (solidaridadPct > 0 && hasAportesSS) ratesText += ` | F. Sol.: ${solidaridadPct}%`;
                    if (window.currentMedicoHasArl) ratesText += ` | ARL: ${arlPct}%`;
                    ratesText += ` | AFC: Manual`;
                    badgeRates.textContent = ratesText;
                } else if (aplicaSeguridadSocial) {
                    let ratesText = `Salud: ${saludPct}%`;
                    if (!window.currentMedicoIsPensionado) ratesText += ` | Pensión: ${pensionPct}%`;
                    if (solidaridadPct > 0 && hasAportesSS) ratesText += ` | F. Sol.: ${solidaridadPct}%`;
                    if (window.currentMedicoHasArl) ratesText += ` | ARL: ${arlPct}%`;
                    badgeRates.textContent = ratesText;
                } else if (window.currentMedicoHasAfc) {
                    badgeRates.textContent = window.currentMedicoHasArl ? `ARL: ${arlPct}% | Deducción Manual` : 'Deducción Manual';
                }
            }

            let rete383   = 0;
            let retencion = 0;

            if (window.currentMedicoHasRetencion383) {
                // Entrada Manual: Sí suma a deducciones
                rete383 = parseMontoInput(document.getElementById('ded_rete_383').value);
                document.getElementById('ded_retencion').value = 0;
            } else if (window.currentMedicoHasRetenciones) {
                // Calcular Retención a partir del Porcentaje de Retención sobre Total Factura
                const retencionPct = parseFloat(document.getElementById('ded_retencion_pct').value) || 0;
                retencion = (retencionPct > 0 && totalFactura > 0) ? Math.round(totalFactura * (retencionPct / 100)) : 0;
                document.getElementById('ded_retencion').value = retencion;
                const dispRetencion = document.getElementById('ded_retencion_display');
                if (dispRetencion) dispRetencion.textContent = `- $ ${retencion.toLocaleString('es-CO')}`;
                document.getElementById('ded_rete_383').value = 0;
            } else {
                document.getElementById('ded_rete_383').value = 0;
                document.getElementById('ded_retencion').value = 0;
            }

            const totalDeducciones = afc + solidaridad + salud + pension + arl + rete383 + retencion;
            const totalPagar = Math.max(0, totalFactura - totalDeducciones);

            document.getElementById('liqTotalDeduccionesDisplay').textContent = `- $ ${totalDeducciones.toLocaleString('es-CO')}`;
            document.getElementById('liqTotalPagarDisplay').textContent = `$ ${totalPagar.toLocaleString('es-CO')}`;
        }

        function cerrarModalLiquidacion() {
            const modal = document.getElementById('modalLiquidacion');
            modal.firstElementChild.classList.remove('scale-100');
            modal.firstElementChild.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        function imprimirLiquidacion() {
            window.print();
        }

        let isSavingLiquidation = false;
        async function guardarLiquidacionBDFront() {
            if (isSavingLiquidation) return;

            const fDesde = document.getElementById('fecha_desde').value || '';
            const fHasta = document.getElementById('fecha_hasta').value || '';
            const medicoLabel = document.getElementById('selectedMedicoLabel').textContent || '-- Todos los Médicos --';
            const medicoVal   = document.getElementById('medico').value || '';
            const medicoNombre = (medicoVal && medicoLabel !== '-- Todos los Médicos --') ? medicoLabel : 'TODOS LOS MÉDICOS / GLOBAL';
            const medicoCedula = medicoVal || 'GLOBAL';

            const afc          = parseMontoInput(document.getElementById('ded_afc').value);
            const solidaridadPct = (window.currentMedicoHasParafiscales && document.getElementById('ded_solidaridad_pct')) ? (parseFloat(document.getElementById('ded_solidaridad_pct').value) || 0) : 0;
            const solidaridad    = parseMontoInput(document.getElementById('ded_solidaridad').value);
            const ibc          = parseMontoInput(document.getElementById('ded_ibc').value);
            const salud        = parseMontoInput(document.getElementById('ded_salud').value);
            const pension      = parseMontoInput(document.getElementById('ded_pension').value);
            const arl          = parseMontoInput(document.getElementById('ded_arl').value);
            const rete383Info  = parseMontoInput(document.getElementById('ded_rete_383_info') ? document.getElementById('ded_rete_383_info').value : 0);
            const rete383      = parseMontoInput(document.getElementById('ded_rete_383').value);
            const retencionPct = parseFloat(document.getElementById('ded_retencion_pct').value) || 0;
            const retencion    = parseMontoInput(document.getElementById('ded_retencion').value);

            let totalNovAdicion = 0;
            let totalNovDeduccion = 0;
            if (window.novedadesLiquidacionAplicadas && window.novedadesLiquidacionAplicadas.length > 0) {
                window.novedadesLiquidacionAplicadas.forEach(n => {
                    const v = parseFloat(n.valor) || 0;
                    if (n.tipo === 'DEDUCCION') {
                        totalNovDeduccion += v;
                    } else {
                        totalNovAdicion += v;
                    }
                });
            }
            const totalNovNeto = totalNovAdicion - totalNovDeduccion;
            const totalFactura = Math.max(0, totalFacturaBaseLiquidador + totalNovNeto);
            const totalDeducciones = afc + solidaridad + salud + pension + arl + rete383 + retencion;
            const totalAPagar  = Math.max(0, totalFactura - totalDeducciones);

            const countExcluidos = excludedRecordsMap.size;
            let msgExclusionesHtml = '';
            if (countExcluidos > 0) {
                let totalExcluidosMonto = 0;
                allData.forEach(item => {
                    const uId = item.unique_id || item.id;
                    if (excludedRecordsMap.has(uId)) {
                        totalExcluidosMonto += (parseFloat(item.valor_a_pagar) || 0);
                    }
                });

                msgExclusionesHtml = `
                    <div class="p-2.5 rounded-xl bg-amber-950/60 border border-amber-800 text-amber-300 text-xs flex items-center justify-between">
                        <span><i class="fa-solid fa-ban text-amber-400 mr-1.5"></i> <strong>${countExcluidos} examen(es)</strong> excluidos:</span>
                        <span class="font-mono font-bold">$ ${totalExcluidosMonto.toLocaleString('es-CO')}</span>
                    </div>
                `;
            }

            // Popup de confirmación estilizado
            const confirmModal = await SwalCustom.fire({
                title: '¿Confirmar Registro de Liquidación?',
                icon: 'question',
                html: `
                    <div class="space-y-2.5 text-left my-3 p-4 rounded-2xl bg-slate-800/90 border border-slate-700/80 shadow-inner">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400 font-medium">Médico / Profesional:</span>
                            <span class="font-bold text-white">${htmlspecialchars(medicoNombre)}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400 font-medium">Periodo:</span>
                            <span class="font-bold text-teal-400 font-mono">${fDesde} AL ${fHasta}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs border-t border-slate-700/80 pt-2">
                            <span class="text-slate-400 font-medium">Total Factura:</span>
                            <span class="font-mono font-bold text-white">$ ${totalFactura.toLocaleString('es-CO')}</span>
                        </div>
                        ${(window.novedadesLiquidacionAplicadas && window.novedadesLiquidacionAplicadas.length > 0) ? `
                        <div class="flex justify-between items-center text-xs text-amber-400">
                            <span class="font-medium">Novedades Entidad (${window.novedadesLiquidacionAplicadas.length}):</span>
                            <span class="font-mono font-bold">${totalNovNeto >= 0 ? '+ ' : '- '}$ ${Math.abs(totalNovNeto).toLocaleString('es-CO')} (Incluidas en Factura)</span>
                        </div>` : ''}
                        ${afc > 0 ? `
                        <div class="flex justify-between items-center text-xs text-rose-400">
                            <span class="font-medium">Aporte AFC:</span>
                            <span class="font-mono font-bold">- $ ${afc.toLocaleString('es-CO')}</span>
                        </div>
                        ` : ''}
                        ${solidaridad > 0 ? `
                        <div class="flex justify-between items-center text-xs text-rose-400">
                            <span class="font-medium">Fondo Solidaridad (${solidaridadPct}%):</span>
                            <span class="font-mono font-bold">- $ ${solidaridad.toLocaleString('es-CO')}</span>
                        </div>
                        ` : ''}
                        <div class="flex justify-between items-center text-xs text-rose-400">
                            <span class="font-medium">Total Deducciones:</span>
                            <span class="font-mono font-bold">- $ ${totalDeducciones.toLocaleString('es-CO')}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm border-t border-slate-700/80 pt-2 font-black text-emerald-400">
                            <span>TOTAL A PAGAR:</span>
                            <span class="font-mono text-base font-extrabold">$ ${totalAPagar.toLocaleString('es-CO')}</span>
                        </div>
                        ${msgExclusionesHtml}
                    </div>
                    <p class="text-[11px] text-slate-400 text-center">Al confirmar, la liquidación quedará en estado <strong>PENDIENTE DE APROBACIÓN</strong> para el área Financiera y las exclusiones se registrarán para auditoría.</p>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-paper-plane mr-1.5"></i> Sí, Generar Liquidación',
                cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cancelar'
            });

            if (!confirmModal.isConfirmed) return;

            isSavingLiquidation = true;
            const btn = document.getElementById('btnGuardarLiquidacionDB');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
            }

            SwalCustom.fire({
                title: 'Registrando Liquidación...',
                text: 'Guardando registro en la base de datos y calculando huella digital...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const dataToProcess = allData.filter(item => selectedIds.has(item.unique_id || item.id));
            const sedesResumen = {};
            dataToProcess.forEach(item => {
                const s = (item.sede && item.sede.trim() !== '') ? item.sede.trim().toUpperCase() : 'SEDE SIN ESPECIFICAR';
                if (!sedesResumen[s]) {
                    sedesResumen[s] = {
                        total: 0,
                        examenes: []
                    };
                }
                const valPagar = (parseFloat(item.valor_a_pagar) || 0);
                sedesResumen[s].total += valPagar;

                const sDataServ = item.servinte || item.servinte_unmatched || null;
                const tipoPacRaw = ((sDataServ && sDataServ.tipo_paciente) ? sDataServ.tipo_paciente : (item.tipo_paciente || 'E')).toString().trim().toUpperCase();
                const tipoPacStr = (tipoPacRaw === 'P' || tipoPacRaw === 'PARTICULAR') ? 'Particular' : 'Empresa';
                const cantVal = (sDataServ && sDataServ.cantidad !== undefined) ? parseInt(sDataServ.cantidad) : (parseInt(item.cantidad) || 1);

                sedesResumen[s].examenes.push({
                    origen: item.origen || 'PROTEO',
                    id_ref: item.id || '',
                    fuente: item.fuente || '',
                    ingreso: item.ingreso || item.fuente_id || '',
                    tipo_paciente: tipoPacStr,
                    fecha: item.fecha ? item.fecha.substring(0, 10) : '',
                    sede: s,
                    medico_nombre: item.usuario || medicoNombre,
                    medico_cedula: item.usuario_medico || medicoCedula,
                    paciente: item.nombre || (sDataServ ? sDataServ.paciente : (item.paciente || 'PACIENTE UNIFICADO')),
                    documento: item.documento ? item.documento : (sDataServ ? `${sDataServ.tipo_doc || ''} ${sDataServ.identificacion || ''}`.trim() : ''),
                    entidad: sDataServ ? (sDataServ.entidad || '') : (item.entidad || ''),
                    cups: item.cups || '',
                    examen: item.nombre_examen || item.cups || 'EXAMEN MÉDICO',
                    servicio: item.servicio || (item.concepto === 'ECOG' ? 'ECOGRAFIA' : (item.concepto === 'DOPP' ? 'DOPPLER' : (item.concepto === 'TOHO' ? 'TOMOGRAFIA' : (item.concepto === 'MAMO' ? 'MAMOGRAFIA' : (item.concepto === 'BIOP' ? 'BIOPSIAS' : (item.concepto === 'RXES' ? 'RX ESPECIALES' : (item.concepto === 'RXSI' ? 'RX SIMPLE' : (item.es_bloqueo_ho ? 'BLOQUEOS' : '')))))))),
                    concepto: item.concepto || (item.es_bloqueo_ho ? 'BLOQ' : ''),
                    cuenta_contable: item.cuenta_contable || '',
                    cantidad: cantVal,
                    valor_examen: parseFloat(item.valor_servinte || (sDataServ ? sDataServ.total : 0) || 0),
                    valor_a_pagar: valPagar,
                    cruce: item.estado_cruce || item.cruce || 'CRUZADO',
                    es_bloqueo_ho: item.es_bloqueo_ho ? 1 : 0,
                    bloqueo_pct: item.bloqueo_pct || 0,
                    bloqueo_nota: item.bloqueo_nota || ''
                });
            });

            // Si se generó bonificación por tomografías contrastadas, anexarla a la sede mayoritaria en el guardado
            let totalTomosContrastadasGuardado = 0;
            const sedesTomoCountGuardado = {};
            dataToProcess.forEach(item => {
                if (item.es_tomografia_contrastada) {
                    const cant = 1;
                    totalTomosContrastadasGuardado += cant;
                    const s = (item.sede && item.sede.trim() !== '') ? item.sede.trim().toUpperCase() : 'SEDE SIN ESPECIFICAR';
                    sedesTomoCountGuardado[s] = (sedesTomoCountGuardado[s] || 0) + cant;
                }
            });

            const cantBonisGuardado = Math.floor(totalTomosContrastadasGuardado / 50);
            if (cantBonisGuardado > 0) {
                let sedeMayoritaria = 'SEDE SIN ESPECIFICAR';
                let maxCant = -1;
                Object.keys(sedesTomoCountGuardado).forEach(s => {
                    if (sedesTomoCountGuardado[s] > maxCant) {
                        maxCant = sedesTomoCountGuardado[s];
                        sedeMayoritaria = s;
                    }
                });

                if (!sedesResumen[sedeMayoritaria]) {
                    sedesResumen[sedeMayoritaria] = { total: 0, examenes: [] };
                }

                const valorBonisGuardado = cantBonisGuardado * 150000;
                sedesResumen[sedeMayoritaria].total += valorBonisGuardado;
                sedesResumen[sedeMayoritaria].examenes.push({
                    origen: 'BONIFICACION',
                    id_ref: 'BONI_TOMOS',
                    fuente: 'REGLA_LIHO',
                    ingreso: 'N/A',
                    tipo_paciente: 'Institucional',
                    fecha: fHasta || fDesde || '',
                    sede: sedeMayoritaria,
                    medico_nombre: medicoNombre,
                    medico_cedula: medicoCedula,
                    paciente: 'BONIFICACIÓN CUMPLIMIENTO TOMOGRAFÍAS',
                    documento: 'N/A',
                    entidad: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.',
                    cups: 'BONI50',
                    examen: `BONIFICACIÓN TOMOGRAFÍAS CONTRASTADAS (${totalTomosContrastadasGuardado} CONTRASTADAS / ${cantBonisGuardado}x$150.000 COP)`,
                    cantidad: cantBonisGuardado,
                    valor_examen: 0,
                    valor_a_pagar: valorBonisGuardado,
                    cruce: 'CRUZADO',
                    es_bonificacion: true
                });
            }

            // Preparar array de exclusiones justificadas
            const exclusionesArr = [];
            excludedRecordsMap.forEach((excl, uId) => {
                const item = allData.find(x => (x.unique_id && x.unique_id === uId) || x.id == uId) || {};
                const sDataServ = item.servinte || item.servinte_unmatched || null;
                const tipoPacRaw = ((sDataServ && sDataServ.tipo_paciente) ? sDataServ.tipo_paciente : (item.tipo_paciente || 'E')).toString().trim().toUpperCase();
                const tipoPacStr = (tipoPacRaw === 'P' || tipoPacRaw === 'PARTICULAR') ? 'Particular' : 'Empresa';
                
                exclusionesArr.push({
                    unique_id: uId,
                    origen: item.origen || 'PROTEO',
                    evento_id: item.id || '',
                    fuente: item.fuente || '',
                    ingreso: item.ingreso || item.fuente_id || '',
                    tipo_paciente: tipoPacStr,
                    fecha: item.fecha ? item.fecha.substring(0, 10) : '',
                    sede: (item.sede && item.sede.trim() !== '') ? item.sede.trim().toUpperCase() : 'SEDE SIN ESPECIFICAR',
                    medico_nombre: item.usuario || medicoNombre,
                    medico_cedula: item.usuario_medico || medicoCedula,
                    paciente: item.nombre || (sDataServ ? sDataServ.paciente : 'PACIENTE'),
                    documento_paciente: item.documento ? item.documento : (sDataServ ? `${sDataServ.tipo_doc || ''} ${sDataServ.identificacion || ''}`.trim() : ''),
                    entidad: sDataServ ? (sDataServ.entidad || '') : (item.entidad || ''),
                    cups: item.cups || '',
                    examen_nombre: item.nombre_examen || item.cups || (sDataServ ? sDataServ.examen : 'EXAMEN MÉDICO'),
                    valor_examen: parseFloat(item.valor_servinte || (sDataServ ? sDataServ.total : 0) || 0),
                    valor_a_pagar: parseFloat(item.valor_a_pagar || 0),
                    estado_cruce: item.cruce || 'SOLO_PROTEO',
                    motivo_exclusion: excl.motivo_exclusion || 'Exclusión manual',
                    detalle_exclusion: excl.detalle_exclusion || ''
                });
            });

            const payload = {
                periodo_desde: fDesde,
                periodo_hasta: fHasta,
                medico_cedula: medicoCedula,
                medico_nombre: medicoNombre,
                entidad_id: window.currentEntidadIdLiq || (typeof entActivaId !== 'undefined' ? entActivaId : 'PROPIO'),
                entidad_nombre: window.currentEntidadNombreLiq || (typeof entActivaNombre !== 'undefined' ? entActivaNombre : 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'),
                total_factura: totalFactura,
                ded_afc: afc,
                ded_solidaridad_pct: solidaridadPct,
                ded_solidaridad: solidaridad,
                ded_ibc: ibc,
                ded_salud: salud,
                ded_pension: pension,
                ded_arl: arl,
                ded_rete_383_info: rete383Info,
                ded_rete_383: rete383,
                ded_retencion_pct: retencionPct,
                ded_retencion: retencion,
                total_deducciones: totalDeducciones,
                total_a_pagar: totalAPagar,
                novedades_json: (window.novedadesLiquidacionAplicadas && window.novedadesLiquidacionAplicadas.length > 0) ? window.novedadesLiquidacionAplicadas : null,
                total_novedades_adicion: totalNovAdicion,
                total_novedades_deduccion: totalNovDeduccion,
                total_novedades_neto: totalNovNeto,
                detalles_json: JSON.stringify(sedesResumen),
                exclusiones_json: JSON.stringify(exclusionesArr)
            };

            try {
                const resp = await fetch('gestion_medicos_procedimientos.php?action=guardar_liquidacion', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const res = await resp.json();

                if (res.success) {
                    window.novedadesLiquidacionAplicadas = [];
                    cerrarModalLiquidacion();
                    SwalCustom.fire({
                        icon: 'success',
                        title: 'Liquidación Generada Exitosamente',
                        html: `
                            <div class="p-4 rounded-2xl bg-teal-950/40 border border-teal-800/80 text-teal-200 text-xs my-2 space-y-1">
                                <p class="font-bold text-sm text-teal-300">Liquidación N° ${res.id}</p>
                                <p>Ha quedado registrada en estado <strong>PENDIENTE DE APROBACIÓN</strong>.</p>
                                <p class="text-[11px] opacity-80 mt-1">El equipo Financiero / Administración la revisará en el módulo correspondiente.</p>
                            </div>
                        `,
                        confirmButtonText: '<i class="fa-solid fa-check mr-1.5"></i> Entendido'
                    }).then(() => {
                        limpiarFiltros();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                } else {
                    SwalCustom.fire({
                        icon: 'error',
                        title: 'Error al Registrar',
                        text: res.error || 'Error desconocido'
                    });
                }
            } catch(err) {
                console.error(err);
                SwalCustom.fire({
                    icon: 'error',
                    title: 'Error de Conexión',
                    text: 'Ocurrió un error de conexión al servidor al guardar la liquidación.'
                });
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
                isSavingLiquidation = false;
            }
        }

        function verExclusionesLiquidacionPreview() {
            if (excludedRecordsMap.size === 0) {
                SwalCustom.fire({ icon: 'info', title: 'Sin Exclusiones', text: 'No hay exámenes excluidos en esta liquidación.' });
                return;
            }

            let rowsHtml = '';
            let totalExclVal = 0;

            excludedRecordsMap.forEach((excl, uId) => {
                const item = allData.find(x => (x.unique_id && x.unique_id === uId) || x.id == uId) || {};
                const s = item.servinte || item.servinte_unmatched || {};
                const pac = item.nombre || s.paciente || 'PACIENTE';
                const doc = item.documento || (s.identificacion ? `${s.tipo_doc || ''} ${s.identificacion}` : '-');
                const cupsExa = item.cups || item.nombre_examen || s.examen || 'EXAMEN';
                const valPagar = parseFloat(item.valor_a_pagar || 0);
                totalExclVal += valPagar;

                rowsHtml += `
                    <tr class="hover:bg-slate-800/60 border-b border-slate-800">
                        <td class="py-2.5 px-3">
                            <div class="font-bold text-white">${htmlspecialchars(pac)}</div>
                            <div class="text-[10px] text-slate-400">Doc: ${htmlspecialchars(doc)}</div>
                        </td>
                        <td class="py-2.5 px-3">
                            <div class="font-medium text-slate-200">${htmlspecialchars(cupsExa)}</div>
                            <div class="text-[10px] text-slate-400">Fuente ${htmlspecialchars(item.fuente || '-')} / Ingreso ${htmlspecialchars(item.ingreso || '-')}</div>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-amber-950/80 text-amber-300 border border-amber-800">
                                ${htmlspecialchars(excl.motivo_exclusion || 'Excluido')}
                            </span>
                            <div class="text-[10px] text-slate-300 mt-1 max-w-xs break-words italic">
                                "${htmlspecialchars(excl.detalle_exclusion || 'Sin detalle')}"
                            </div>
                        </td>
                        <td class="py-2.5 px-3 text-right font-mono font-bold text-emerald-400 whitespace-nowrap">
                            $ ${valPagar.toLocaleString('es-CO')}
                        </td>
                    </tr>
                `;
            });

            SwalCustom.fire({
                title: `<span class="flex items-center justify-center gap-2"><i class="fa-solid fa-ban text-amber-400"></i><span>Exámenes Excluidos (${excludedRecordsMap.size})</span></span>`,
                width: '850px',
                html: `
                    <div class="space-y-3 text-left my-2 font-sans">
                        <div class="flex justify-between items-center p-3 rounded-xl bg-slate-800/90 border border-slate-700/80 text-xs">
                            <span class="text-slate-300 font-medium">Total Exámenes Excluidos: <strong class="text-white font-mono text-sm">${excludedRecordsMap.size}</strong></span>
                            <span class="text-slate-300 font-medium">Monto Excluido No Pagado: <strong class="text-amber-400 font-mono text-sm">$ ${totalExclVal.toLocaleString('es-CO')}</strong></span>
                        </div>
                        <div class="max-h-[380px] overflow-y-auto rounded-xl border border-slate-800 bg-slate-950">
                            <table class="w-full text-left border-collapse">
                                <thead class="sticky top-0 bg-slate-900 text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                    <tr>
                                        <th class="py-2.5 px-3">Paciente / Cédula</th>
                                        <th class="py-2.5 px-3">Examen / CUPS</th>
                                        <th class="py-2.5 px-3">Causal y Justificación</th>
                                        <th class="py-2.5 px-3 text-right">Valor A Pagar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rowsHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `,
                confirmButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cerrar Listado'
            });
        }

        function mostrarInformeExamenesSedePreview(sedeKey) {
            const dataToProcess = (filteredData && filteredData.length > 0) ? filteredData : allData;
            const itemsSede = dataToProcess.filter(item => {
                const s = (item.sede && item.sede.trim() !== '') ? item.sede.trim().toUpperCase() : 'SEDE SIN ESPECIFICAR';
                return s === sedeKey;
            });

            let totalVal = 0;
            let rowsHtml = '';

            itemsSede.forEach(ex => {
                const valPagar = (parseFloat(ex.valor_a_pagar) || 0);
                totalVal += valPagar;

                const esCruzado = (ex.cruce === 'CRUZADO');
                const badgeCruce = esCruzado 
                    ? `<span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 inline-flex items-center gap-1"><i class="fa-solid fa-circle-check"></i> Cruzado OK</span>`
                    : `<span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-200 dark:border-rose-800 inline-flex items-center gap-1"><i class="fa-solid fa-circle-xmark"></i> No Cruzado (Solo Proteo)</span>`;

                rowsHtml += `
                    <tr class="border-b border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors text-left text-xs">
                        <td class="py-2.5 px-3">
                            <div class="font-bold text-slate-900 dark:text-slate-100">${htmlspecialchars(ex.paciente || 'PACIENTE')}</div>
                            <div class="text-[10px] font-mono text-slate-500 dark:text-slate-400">ID/CC: ${htmlspecialchars(ex.documento || 'N/A')}</div>
                        </td>
                        <td class="py-2.5 px-3">
                            <div class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-1.5 flex-wrap">
                                <span>${htmlspecialchars(ex.nombre_examen || ex.cups || 'EXAMEN')}</span>
                                ${(esTextoComparativo(ex.nombre_examen || ex.cups || '') || esTextoComparativo(ex.detail || '')) ? `
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[8px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/90 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                        <i class="fa-solid fa-code-compare text-[8px]"></i> COMPARATIVO
                                    </span>
                                ` : ''}
                            </div>
                            ${ex.cups ? `<div class="text-[10px] text-teal-600 dark:text-teal-400 font-mono">CUPS: ${htmlspecialchars(ex.cups)}</div>` : ''}
                        </td>
                        <td class="py-2.5 px-3 font-mono text-[11px] text-slate-600 dark:text-slate-300">${htmlspecialchars(ex.ingreso || ex.fuente_id || 'N/A')}</td>
                        <td class="py-2.5 px-3 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">$ ${valPagar.toLocaleString('es-CO')}</td>
                        <td class="py-2.5 px-3 text-center">${badgeCruce}</td>
                    </tr>
                `;
            });

            const totalRegistrosSede = itemsSede.length;

            SwalCustom.fire({
                title: `<i class="fa-solid fa-building text-tertiary mr-1.5"></i> Informe de Exámenes - Sede ${htmlspecialchars(sedeKey)}`,
                width: '800px',
                html: `
                    <div class="space-y-3 text-left my-2">
                        <div class="flex justify-between items-center p-3 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 text-xs">
                            <span class="text-slate-700 dark:text-slate-300 font-medium">Total Exámenes Sede: <strong class="text-slate-900 dark:text-white font-mono text-sm">${totalRegistrosSede.toLocaleString('es-CO')}</strong></span>
                            <span class="text-slate-700 dark:text-slate-300 font-medium">Total Facturado: <strong class="text-teal-600 dark:text-tertiary font-mono text-sm">$ ${totalVal.toLocaleString('es-CO')}</strong></span>
                        </div>
                        <div class="max-h-[350px] overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950">
                            <table class="w-full text-left border-collapse">
                                <thead class="sticky top-0 bg-slate-100 dark:bg-slate-900 text-[10px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="py-2 px-3">Paciente / Cédula</th>
                                        <th class="py-2 px-3">Examen / CUPS</th>
                                        <th class="py-2 px-3">Ingreso</th>
                                        <th class="py-2 px-3 text-right">Valor A Pagar</th>
                                        <th class="py-2 px-3 text-center">Estado Cruce</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rowsHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `,
                confirmButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cerrar Informe'
            });
        }
    </script>

</body>
</html>
