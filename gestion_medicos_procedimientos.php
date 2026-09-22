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
                return preg_replace('/[^A-Z0-9]/', '', $sec);
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
                    $pairsByFuenteProteo[$fuente][] = $ingreso;

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
                                    $exaSecCode = preg_replace('/[^A-Z0-9]/', '', $nomTokens[0] ?? '');

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
                                        'cantidad'      => (float)($r['CANTIDAD'] ?? 0),
                                        'total'         => (float)($r['TOTAL'] ?? 0),
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
        $medicosPensionadosMap = [];
        $porcentajesPagoMap = [
            'TARIFAS_ESPECIALES' => 30.0,
            'DEGLUCIONES'        => 45.0
        ];
        $modalidadesConfigMap = [];
        $parafiscalesConfigMap = [
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

            // 2. Tarifario General Estándar con Concepto de Facturación (Prioriza entidad activa si existe, con fallback a Hernán Ocazionez)
            $cupsConceptoMap = [];
            // Cargar base de Hernán Ocazionez (entidad_id IS NULL OR 0)
            $sqlT = "SELECT codigo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, ISNULL(columna1, valor_und) AS valor_und, ISNULL(concepto, '') AS concepto, ISNULL(servicio, '') AS servicio, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo 
                     FROM dbo.tarifario 
                     WHERE ISNULL(estado, 1) = 1 AND (entidad_id IS NULL OR entidad_id = 0)";
            $stmtT = sqlsrv_query($connLIHO, $sqlT);
            if ($stmtT !== false) {
                while ($rT = sqlsrv_fetch_array($stmtT, SQLSRV_FETCH_ASSOC)) {
                    $codRaw  = strtoupper(trim((string)($rT['codigo'] ?? '')));
                    $tipoPac = strtoupper(trim((string)($rT['tipo_paciente'] ?? 'E')));
                    if ($tipoPac !== 'P') $tipoPac = 'E';
                    $valUnd  = (float)($rT['valor_und'] ?? 0);
                    $conVal  = strtoupper(trim((string)($rT['concepto'] ?? '')));
                    $serVal  = strtoupper(trim((string)($rT['servicio'] ?? '')));
                    $pagarCant = (int)($rT['pagar_por_cantidad'] ?? 1);
                    $baseCalc  = strtoupper(trim((string)($rT['base_calculo'] ?? 'VALOR_LIQUIDACION')));
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
                    }
                }
            }

            // Si la entidad activa es externa (ej. IMADINSA SAS), sobreescribir/agregar con su tarifario específico
            $entidadActivaId = $entActivaSesion ?? 'PROPIO';
            if (!empty($entidadActivaId) && $entidadActivaId !== 'PROPIO' && (int)$entidadActivaId > 0) {
                $sqlTEnt = "SELECT codigo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, ISNULL(columna1, valor_und) AS valor_und, ISNULL(concepto, '') AS concepto, ISNULL(servicio, '') AS servicio, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo 
                            FROM dbo.tarifario 
                            WHERE ISNULL(estado, 1) = 1 AND entidad_id = ?";
                $stmtTEnt = sqlsrv_query($connLIHO, $sqlTEnt, [(int)$entidadActivaId]);
                if ($stmtTEnt !== false) {
                    while ($rT = sqlsrv_fetch_array($stmtTEnt, SQLSRV_FETCH_ASSOC)) {
                        $codRaw  = strtoupper(trim((string)($rT['codigo'] ?? '')));
                        $tipoPac = strtoupper(trim((string)($rT['tipo_paciente'] ?? 'E')));
                        if ($tipoPac !== 'P') $tipoPac = 'E';
                        $valUnd  = (float)($rT['valor_und'] ?? 0);
                        $conVal  = strtoupper(trim((string)($rT['concepto'] ?? '')));
                        $serVal  = strtoupper(trim((string)($rT['servicio'] ?? '')));
                        $pagarCant = (int)($rT['pagar_por_cantidad'] ?? 1);
                        $baseCalc  = strtoupper(trim((string)($rT['base_calculo'] ?? 'VALOR_LIQUIDACION')));
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
                                 ISNULL(m.pensionado, ISNULL(u.pensionado, 0)) AS pensionado,
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
                    $isPen  = ((int)($rME['pensionado'] ?? 0) === 1);

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
                    if ($isPen) {
                        if (!empty($pUser)) $medicosPensionadosMap[$pUser] = true;
                        if (!empty($ced))   $medicosPensionadosMap[$ced] = true;
                        if (!empty($nom))   $medicosPensionadosMap[$nom] = true;
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
        $esTomografiaContrastada = function($cupsText, $conceptoCode, $cupsCodServinte, $cupsDescripcionServinte) {
            $haystack = mb_strtoupper(trim($cupsText . ' ' . $cupsDescripcionServinte));
            $cCode    = mb_strtoupper(trim($conceptoCode));
            $sCode    = mb_strtoupper(trim($cupsCodServinte));

            // Excluir expresamente radiografías simples o especiales, mamografías o ecografías
            if (strpos($haystack, 'RADIOGRAF') !== false || strpos($haystack, 'ECOGRAF') !== false || strpos($haystack, 'ULTRASON') !== false || strpos($haystack, 'MAMOGRAF') !== false) {
                return false;
            }
            if (preg_match('/^87[123]\d{3}/i', $sCode)) {
                return false;
            }

            // Debe ser tomografía (concepto TOHO/TOMO, código CUPS 879/x79 o texto característico)
            $isTomo = ($cCode === 'TOHO' || $cCode === 'TOMO' || 
                       strpos($haystack, 'TOMOGRAF') !== false || 
                       strpos($haystack, 'TAC') !== false || 
                       strpos($haystack, 'ANGIOTAC') !== false || 
                       strpos($haystack, 'UROTC') !== false || 
                       strpos($haystack, 'UROTOMOGRAF') !== false);

            if (!$isTomo) {
                if (preg_match('/^[A-Z]?79\d{3,4}/i', $sCode) || preg_match('/^879\d{3,4}/i', $sCode)) {
                    $isTomo = true;
                }
            }
            if (!$isTomo) return false;

            // Aplica a todas las tomografías (simples o contrastadas) para todos los médicos de todas las entidades ($150.000 COP por cada 50)
            return true;
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

                $candidateIndices = [];
                if (isset($servinteByFueIng[$keyExact]) && !empty($servinteByFueIng[$keyExact])) {
                    $candidateIndices = $servinteByFueIng[$keyExact];
                } elseif (isset($servinteByFueIng[$keyClean]) && !empty($servinteByFueIng[$keyClean])) {
                    $candidateIndices = $servinteByFueIng[$keyClean];
                }

                $pCupsCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($pItem['cups']));
                $pCupsSec  = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($pItem['cups']));

                if (!empty($candidateIndices)) {
                    $matchedIdx = null;

                    // Coincidencia estricta del CÓDIGO CUPS completo de Proteo con el código de Servinte
                    foreach ($candidateIndices as $idx) {
                        $sCand = $servinteItemsList[$idx];
                        if (!$sCand['matched']) {
                            $sCodRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sCand['codigo_examen'] ?? ''))));
                            $sExaSec = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sCand['examen_sec'] ?? ''))));

                            $isMatch = false;

                            // 1. Coincidencia alfanumérica exacta (ej: A73210 === A73210, C73210 === C73210)
                            if (!empty($pCupsCode) && !empty($sCodRaw) && $pCupsCode === $sCodRaw) {
                                $isMatch = true;
                            }
                            // 2. Coincidencia por código CUPS secundario (ej: 8732100 === 8732100)
                            elseif (!empty($pCupsSec) && !empty($sCodRaw) && $pCupsSec === $sCodRaw) {
                                $isMatch = true;
                            }
                            // 3. Coincidencia de código secundario de Proteo con el código de descripción de Servinte
                            elseif (!empty($pCupsSec) && !empty($sExaSec) && $pCupsSec === $sExaSec) {
                                $isMatch = true;
                            }
                            // 4. Coincidencia de código principal de Proteo con el código de descripción de Servinte
                            elseif (!empty($pCupsCode) && !empty($sExaSec) && $pCupsCode === $sExaSec) {
                                $isMatch = true;
                            }

                            if ($isMatch) {
                                $matchedIdx = $idx;
                                break;
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
                        $pItem['cruce']              = 'SOLO_PROTEO';
                        $pItem['discrepancia']       = 'No Cruzado: Existen facturas en Servinte para Fuente (' . $pItem['fuente'] . ') e Ingreso (' . $pItem['ingreso'] . '), pero el código CUPS no coincide (Proteo: ' . $pItem['cups'] . ')';
                        $pItem['servinte']           = null;
                        $pItem['servinte_unmatched'] = $servinteItemsList[$candidateIndices[0]];
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
            $valorAPagar      = ($pagarPorCantidad === 1) ? ($valorBaseCalculo * $cantItem) : $valorBaseCalculo;

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

            // Determinar Concepto del Examen
            $conceptoRaw = '';
            if (!empty($sInfo['concepto'])) {
                $conceptoRaw = strtoupper(trim((string)$sInfo['concepto']));
            }
            if (empty($conceptoRaw) && !empty($cupsCodeServinte)) {
                $cleanCodS = preg_replace('/[^A-Z0-9]/', '', $cupsCodeServinte);
                if (isset($cupsConceptoMap[$cleanCodS])) $conceptoRaw = $cupsConceptoMap[$cleanCodS];
            }
            if (empty($conceptoRaw)) {
                $cleanPCode = preg_replace('/[^A-Z0-9]/', '', $extractCupsCode($pItem['cups'] ?? ''));
                if (isset($cupsConceptoMap[$cleanPCode])) $conceptoRaw = $cupsConceptoMap[$cleanPCode];
            }
            if (empty($conceptoRaw)) {
                $cleanPSec = preg_replace('/[^A-Z0-9]/', '', $extractCupsSecondaryCode($pItem['cups'] ?? ''));
                if (isset($cupsConceptoMap[$cleanPSec])) $conceptoRaw = $cupsConceptoMap[$cleanPSec];
            }
            if (empty($conceptoRaw)) {
                $nomCupsUpper = strtoupper(trim((string)($pItem['cups'] ?? '')));
                if (strpos($nomCupsUpper, 'ECO') !== false || strpos($nomCupsUpper, 'ULTRASONIDO') !== false) {
                    $conceptoRaw = 'ECOG';
                } elseif (strpos($nomCupsUpper, 'DOPP') !== false) {
                    $conceptoRaw = 'DOPP';
                } elseif (strpos($nomCupsUpper, 'TOMO') !== false || strpos($nomCupsUpper, 'TAC') !== false) {
                    $conceptoRaw = 'TOHO';
                } elseif (strpos($nomCupsUpper, 'MAMO') !== false) {
                    $conceptoRaw = 'MAMO';
                } elseif (strpos($nomCupsUpper, 'BIO') !== false) {
                    $conceptoRaw = 'BIOP';
                } elseif (strpos($nomCupsUpper, 'RX') !== false || strpos($nomCupsUpper, 'RADIOGRAF') !== false) {
                    $conceptoRaw = 'RXSI';
                } elseif (strpos($nomCupsUpper, 'BLOQUEO') !== false || strpos($nomCupsUpper, 'BLOQUEOS') !== false) {
                    $conceptoRaw = 'BLOQ';
                } else {
                    $conceptoRaw = 'OTROS';
                }
            }
            if ($esBloqueo && ($conceptoRaw === 'OTROS' || empty($conceptoRaw))) {
                $conceptoRaw = 'BLOQ';
            }
            $pItem['concepto']        = $conceptoRaw;
            $pItem['concepto_nombre'] = $conceptosNombresMap[$conceptoRaw] ?? $conceptoRaw;
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

        // --- D. PROCESAR REGISTROS QUE EXISTEN SOLO EN SERVINTE (SIN MATCH DE EVENTO Y CUPS EN PROTEO) ---
        foreach ($servinteItemsList as $sItemData) {
            if (!$sItemData['matched']) {
                // Si hay filtro por médico activo, omitir registros de Solo Servinte (sin lectura en Proteo)
                if (!empty($medicoFiltro)) {
                    continue;
                }

                $sFue      = strtoupper(trim((string)$sItemData['fuente']));
                $sIng      = trim((string)$sItemData['ingreso']);
                $sIngClean = ltrim($sIng, '0');
                if ($sIngClean === '') $sIngClean = '0';

                $kExact = $sFue . '_' . $sIng;
                $kClean = $sFue . '_' . $sIngClean;

                $proteoCandidates = [];
                if (isset($proteoByFueIngMap[$kExact]) && !empty($proteoByFueIngMap[$kExact])) {
                    $proteoCandidates = $proteoByFueIngMap[$kExact];
                } elseif (isset($proteoByFueIngMap[$kClean]) && !empty($proteoByFueIngMap[$kClean])) {
                    $proteoCandidates = $proteoByFueIngMap[$kClean];
                }

                $proteoUnmatched = !empty($proteoCandidates) ? $proteoCandidates[0] : null;

                $itemCounter++;
                $discrepanciaTxt = !empty($proteoUnmatched)
                    ? 'Facturado en Servinte (Fuente: ' . $sItemData['fuente'] . ', Ingreso: ' . $sItemData['ingreso'] . ', CUPS: ' . $sItemData['codigo_examen'] . '). Existe registro en Proteo con CUPS diferente (Proteo: ' . ($proteoUnmatched['cups'] ?? '') . ')'
                    : 'Facturado en Servinte (Fuente: ' . $sItemData['fuente'] . ', Ingreso: ' . $sItemData['ingreso'] . ', CUPS: ' . $sItemData['codigo_examen'] . ') sin lectura coincidente en Proteo';

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
                    'usuario'          => !empty($proteoUnmatched) ? $proteoUnmatched['usuario'] : 'Sin Lectura en Proteo',
                    'usuario_medico'   => !empty($proteoUnmatched) ? $proteoUnmatched['usuario_medico'] : 'N/A',
                    'tipo_paciente'    => $sItemData['tipo_paciente'] ?? 'E',
                    'servinte'         => $sItemData,
                    'servinte_total_ingreso' => $sItemData['total'],
                    'servinte_items'    => [$sItemData],
                    'proteo_unmatched' => $proteoUnmatched,
                    'cruce'            => 'SOLO_SERVINTE',
                    'discrepancia'     => $discrepanciaTxt
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
                $valorAPagar      = ($pagarPorCantidad === 1) ? ($valorBaseCalculo * $cantItem) : $valorBaseCalculo;

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

                $conceptoRawS = !empty($sItemData['concepto']) ? strtoupper(trim((string)$sItemData['concepto'])) : '';
                if (empty($conceptoRawS) && !empty($cupsCodeServinte)) {
                    $cleanCodS = preg_replace('/[^A-Z0-9]/', '', $cupsCodeServinte);
                    if (isset($cupsConceptoMap[$cleanCodS])) $conceptoRawS = $cupsConceptoMap[$cleanCodS];
                }
                if ($esBloqueo && (empty($conceptoRawS) || $conceptoRawS === 'OTROS' || $conceptoRawS === 'RXSI')) {
                    $conceptoRawS = 'BLOQ';
                }
                if (empty($conceptoRawS)) $conceptoRawS = 'RXSI';
                $sItem['concepto']        = $conceptoRawS;
                $sItem['concepto_nombre'] = $conceptosNombresMap[$conceptoRawS] ?? $conceptoRawS;
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

    // Exportar a Excel
    if ($action === 'export_excel') {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=Reporte_Procedimientos_Medicos_" . date('Y-m-d_H-i') . ".xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8"/><style>table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; } th { background: #0f172a; color: white; padding: 8px; border: 1px solid #cbd5e1; } td { padding: 6px; border: 1px solid #cbd5e1; } .num { text-align: right; } .cruzado { background: #dcfce7; color: #166534; font-weight: bold; } .solo-proteo { background: #fee2e2; color: #991b1b; font-weight: bold; } .solo-servinte { background: #e0f2fe; color: #0369a1; font-weight: bold; }</style></head>';
        echo '<body>';
        echo '<h2>Reporte de Procedimientos Médicos (Proteo vs Servinte) - LIHO IPS</h2>';
        echo '<p><strong>Rango de fechas:</strong> ' . htmlspecialchars($fechaDesde) . ' al ' . htmlspecialchars($fechaHasta) . '</p>';
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
                <th>Modalidad</th>
                <th>Estado Proteo / Servinte</th>
                <th>Estado Cruce (Fuente + Ingreso)</th>
                <th>Paciente (Servinte)</th>
                <th>Identificación</th>
                <th>Entidad (EPS)</th>
                <th>Valor del Examen</th>
                <th>Valor a Pagar (Tarifario)</th>
              </tr></thead><tbody>';

        foreach ($records as $r) {
            $s = $r['servinte'] ?? ($r['servinte_unmatched'] ?? null);
            $cruceClass = ($r['cruce'] === 'CRUZADO') ? 'cruzado' : (($r['cruce'] === 'SOLO_SERVINTE') ? 'solo-servinte' : 'solo-proteo');
            $tipoPacRaw = strtoupper(trim((string)($r['tipo_paciente'] ?? ($s['tipo_paciente'] ?? 'E'))));
            $tipoPacDisp = ($tipoPacRaw === 'E' || $tipoPacRaw === 'EMPRESA') ? 'Empresa' : (($tipoPacRaw === 'P' || $tipoPacRaw === 'PARTICULAR') ? 'Particular' : $tipoPacRaw);
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
        echo '</tbody></table></body></html>';
        exit;
    }

    // Respuesta JSON
    header('Content-Type: application/json');

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

    // Calcular Bonificación por Tomografías (1 bono de $150.000 COP por cada 50 tomografías para todos los médicos y entidades)
    $bonificacionTomoCant      = (int)floor($totalTomografiasContrastadas / 50);
    $bonificacionTomoValor     = $bonificacionTomoCant * 150000;
    $bonificacionTomoRestantes = ($totalTomografiasContrastadas % 50 === 0 && $totalTomografiasContrastadas > 0) 
        ? 50 
        : (50 - ($totalTomografiasContrastadas % 50));

    // Si se generó bonificación, anexarla como concepto oficial en los KPIs
    if ($bonificacionTomoCant > 0) {
        $conceptosKpiMap['BONI_TOHO'] = [
            'codigo'          => 'BONI_TOHO',
            'nombre'          => 'Bonificación Tomografías (Regla 50x$150.000)',
            'cantidad'        => (int)$bonificacionTomoCant,
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
$medicosPensionadosMap = [];
$medicosRetencionesMap = [];
$medicosRetencion383Map = [];
$parafiscalesConfigMap = [
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

    // Médicos con Parafiscales, Pensionados, Retenciones, Rete 383 y Modalidad Bloqueos_HO Activos
    $sqlMedParaInit = "SELECT m.usuario_proteo, m.cedula, u.nombre_completo,
                              ISNULL(m.parafiscales, ISNULL(u.parafiscales, 0)) AS parafiscales,
                              ISNULL(m.pensionado, ISNULL(u.pensionado, 0)) AS pensionado,
                              ISNULL(m.retenciones, ISNULL(u.retenciones, 0)) AS retenciones,
                              ISNULL(m.retencion_art_383, ISNULL(u.retencion_art_383, 0)) AS retencion_art_383,
                              ISNULL(m.modalidades_adicionales, ISNULL(u.modalidades_adicionales, '')) AS modalidades_adicionales
                       FROM dbo.medicos m 
                       LEFT JOIN dbo.usuarios u ON m.usuario_id = u.id";
    $stmtMedParaInit = sqlsrv_query($connLIHOInit, $sqlMedParaInit);
    $medicosBloqueoHOMap = [];
    $medicosBloqueoHOList = [];
    if ($stmtMedParaInit !== false) {
        while ($rME = sqlsrv_fetch_array($stmtMedParaInit, SQLSRV_FETCH_ASSOC)) {
            $pUser = strtoupper(trim((string)($rME['usuario_proteo'] ?? '')));
            $ced   = trim((string)($rME['cedula'] ?? ''));
            $nom   = strtoupper(trim((string)($rME['nombre_completo'] ?? '')));
            $isPara = ((int)($rME['parafiscales'] ?? 0) === 1);
            $isPen  = ((int)($rME['pensionado'] ?? 0) === 1);
            $isRet  = ((int)($rME['retenciones'] ?? 0) === 1);
            $isR383 = ((int)($rME['retencion_art_383'] ?? 0) === 1);

            $modsStr = strtoupper(trim((string)($rME['modalidades_adicionales'] ?? '')));
            $modsArr = !empty($modsStr) ? array_filter(array_map('trim', explode(',', $modsStr))) : [];
            $hasBloqueoHO = (in_array('BLOQUEOS_HO', $modsArr) || in_array('BLOQUEO_HO', $modsArr));

            if ($isPara) {
                if (!empty($pUser)) $medicosParafiscalesMap[$pUser] = true;
                if (!empty($ced))   $medicosParafiscalesMap[$ced] = true;
                if (!empty($nom))   $medicosParafiscalesMap[$nom] = true;
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
                                    $hasPen  = (!empty($medicosPensionadosMap[$uUpper]) || !empty($medicosPensionadosMap[$nUpper]));
                                    $hasRet  = (!empty($medicosRetencionesMap[$uUpper]) || !empty($medicosRetencionesMap[$nUpper]));
                                    $hasR383 = (!empty($medicosRetencion383Map[$uUpper]) || !empty($medicosRetencion383Map[$nUpper]));
                                ?>
                                <li>
                                    <button type="button" data-value="<?php echo htmlspecialchars($m['username']); ?>" data-label="<?php echo htmlspecialchars($m['nombre']); ?>" data-parafiscales="<?php echo $hasPara ? '1' : '0'; ?>" data-pensionado="<?php echo $hasPen ? '1' : '0'; ?>" data-retenciones="<?php echo $hasRet ? '1' : '0'; ?>" data-retencion-383="<?php echo $hasR383 ? '1' : '0'; ?>"
                                        class="medico-option-btn w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors flex items-center justify-between group">
                                        <div class="flex flex-col min-w-0 pr-2">
                                            <span class="truncate font-semibold text-slate-800 dark:text-slate-100 group-hover:text-primary dark:group-hover:text-white"><?php echo htmlspecialchars($m['nombre']); ?></span>
                                            <span class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($m['username']); ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 text-[9px] font-extrabold uppercase">Bloqueos HO</span>
                                            <?php if ($hasPara): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-950/80 text-teal-800 dark:text-teal-300 text-[9px] font-extrabold uppercase">Parafiscales</span>
                                            <?php endif; ?>
                                            <?php if ($hasPen): ?>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-indigo-100 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-300 text-[9px] font-extrabold uppercase">Pensionado</span>
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
                    <p class="text-[10px] text-slate-400 font-medium">Tarifario LIHO</p>
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
                            <th class="py-3 px-4">Origen / Ref</th>
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
                        <div id="liqBannerBonificacionTomo" class="hidden p-3.5 rounded-2xl bg-gradient-to-r from-amber-500/15 via-amber-400/10 to-transparent dark:from-[#2a1d0d] dark:via-[#20170a] dark:to-[#16120b] border-2 border-amber-400/80 dark:border-amber-500/80 text-amber-950 dark:text-amber-100 text-xs shadow-md">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-2xl shrink-0 mt-0.5">military_tech</span>
                                <div class="space-y-1 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-black text-amber-950 dark:text-white uppercase tracking-wider text-xs font-outfit">BONIFICACIÓN DE PRODUCTIVIDAD EN TOMOGRAFÍAS</p>
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-amber-200 text-amber-950 dark:bg-amber-500/25 dark:text-amber-200 border border-amber-300 dark:border-amber-500/40">$150.000 x cada 50</span>
                                    </div>
                                    <p class="text-[11px] leading-relaxed text-amber-900 dark:text-amber-200/90">
                                        Aplica a <strong class="text-amber-950 dark:text-white underline decoration-amber-400">todos los médicos y entidades</strong>: +$ <strong class="font-mono text-xs font-black text-amber-950 dark:text-white" id="liqValBonificacionTomoDisplay">0</strong> (<span id="liqCantBonificacionTomoDisplay" class="font-bold">0</span> bono(s) de $150.000 COP por <span id="liqCantContrastadasDisplay" class="font-bold">0</span> tomografías seleccionadas).
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Banner Informativo / Advertencia Registros No Cruzados -->
                        <div id="liqBannerAdvertenciaNoCruzados" class="hidden p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300 dark:border-amber-700/80 text-amber-900 dark:text-amber-200 text-xs shadow-xs">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-xl shrink-0 mt-0.5">warning</span>
                                <div class="space-y-0.5">
                                    <p class="font-extrabold text-amber-900 dark:text-amber-300 uppercase tracking-wider text-[11px]">ADVERTENCIA: INCLUYE REGISTROS NO CRUZADOS</p>
                                    <p class="text-[11px] leading-snug opacity-95">
                                        La liquidación total incluye <strong><span id="liqCountNoCruzadosDisplay">0</span> registro(s) no cruzado(s)</strong> por valor de <strong>$ <span id="liqValNoCruzadosDisplay">0</span></strong>.
                                        Los ítems no cruzados han sido incluidos pero están señalizados con la insignia <span class="px-1.5 py-0.5 rounded bg-amber-200 dark:bg-amber-900 font-bold text-[10px]"><i class="fa-solid fa-triangle-exclamation mr-1"></i>No Cruzado</span> en cada sede.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Banner Informativo Exámenes Excluidos -->
                        <div id="liqBannerExclusiones" class="hidden p-3.5 rounded-2xl bg-amber-50/90 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-200 text-xs shadow-xs">
                            <div class="flex items-start justify-between gap-2.5">
                                <div class="flex items-start gap-2.5">
                                    <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-xl shrink-0 mt-0.5">do_not_disturb_on</span>
                                    <div class="space-y-0.5">
                                        <p class="font-extrabold text-amber-900 dark:text-amber-300 uppercase tracking-wider text-[11px]">EXÁMENES EXCLUIDOS DE ESTA LIQUIDACIÓN</p>
                                        <p class="text-[11px] leading-snug opacity-95">
                                            Se han omitido <strong><span id="liqCountExcluidosDisplay">0</span> examen(es)</strong> con justificación obligatoria por valor de <strong>$ <span id="liqValExcluidosDisplay">0</span></strong>.
                                        </p>
                                    </div>
                                </div>
                                <button type="button" onclick="verExclusionesLiquidacionPreview()" class="px-2.5 py-1 rounded-xl bg-amber-200 hover:bg-amber-300 dark:bg-amber-900 dark:hover:bg-amber-800 text-amber-900 dark:text-amber-100 font-extrabold text-[10px] shrink-0 transition-colors shadow-xs cursor-pointer">
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
                                    
                                    <div id="row_ded_afc" class="flex items-center justify-between gap-2">
                                        <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES AFC</label>
                                        <div class="relative w-36">
                                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 font-mono text-xs">$</span>
                                            <input type="text" inputmode="numeric" id="ded_afc" value="0" oninput="formatInputMiles(this); recalcularLiquidacion()" 
                                                class="w-full pl-6 pr-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-xs focus:ring-2 focus:ring-rose-500/30 outline-none">
                                        </div>
                                    </div>

                                    <div id="row_ded_solidaridad" class="flex items-center justify-between gap-2">
                                        <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES FONDO SOLIDARIDAD</label>
                                        <div class="relative w-36">
                                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 font-mono text-xs">$</span>
                                            <input type="text" inputmode="numeric" id="ded_solidaridad" value="0" oninput="formatInputMiles(this); recalcularLiquidacion()" 
                                                class="w-full pl-6 pr-2 py-1 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-xs focus:ring-2 focus:ring-rose-500/30 outline-none">
                                        </div>
                                    </div>

                                    <div id="row_ded_ibc" class="flex items-center justify-between gap-2 pt-1 border-t border-slate-100 dark:border-slate-800">
                                        <label class="text-[11px] font-bold text-slate-700 dark:text-slate-200">IBC MES (ESTIMADO)</label>
                                        <div class="w-36 text-right pr-2">
                                            <span id="ded_ibc_display" class="font-mono font-black text-xs text-slate-900 dark:text-white">$ 0</span>
                                            <input type="hidden" id="ded_ibc" value="0">
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
        window.medicosPensionadosMap = <?php echo json_encode($medicosPensionadosMap); ?>;
        window.medicosRetencionesMap = <?php echo json_encode($medicosRetencionesMap); ?>;
        window.medicosRetencion383Map = <?php echo json_encode($medicosRetencion383Map); ?>;
        window.currentMedicoHasParafiscales = false;
        window.currentMedicoIsPensionado = false;
        window.currentMedicoHasRetenciones = false;
        window.currentMedicoHasRetencion383 = false;

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

            let tableHtml = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head>
                    <meta charset="utf-8"/>
                    <style>
                        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                        th { background: #0f172a; color: white; padding: 8px; border: 1px solid #cbd5e1; font-weight: bold; }
                        td { padding: 6px; border: 1px solid #cbd5e1; }
                        .num { text-align: right; }
                        .cruzado { background: #dcfce7; color: #166534; font-weight: bold; }
                        .solo-proteo { background: #fee2e2; color: #991b1b; font-weight: bold; }
                        .solo-servinte { background: #e0f2fe; color: #0369a1; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <h2>Reporte de Exámenes Seleccionados (${itemsToExport.length} registros) - LIHO IPS</h2>
                    <p><strong>Fecha de Generación:</strong> ${new Date().toLocaleString()}</p>
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
                                <th>Modalidad</th>
                                <th>Estado Proteo / Servinte</th>
                                <th>Estado Cruce</th>
                                <th>Paciente</th>
                                <th>Identificación</th>
                                <th>Entidad (EPS)</th>
                                <th>Valor del Examen</th>
                                <th>Valor a Pagar (Tarifario)</th>
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
                const valExamen = (s && s.total !== undefined) ? `$${Number(s.total || 0).toLocaleString('es-CO')}` : '-';
                const valPagar = `$${Number(r.valor_a_pagar || 0).toLocaleString('es-CO')}`;

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
                        <td>${htmlspecialchars(r.es_bloqueo_ho ? `BLOQUEO HO (${r.bloqueo_tier_label ? r.bloqueo_tier_label + ' - ' : ''}${r.bloqueo_pct || 40}%)` : (r.modalidad || ''))}</td>
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

            tableHtml += `
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
                const cantLabel = isBoni ? `${c.cantidad.toLocaleString()} bono(s)` : c.cantidad.toLocaleString();
                const pagarPrefix = isBoni ? '+$' : '$';
                const detalleDobles = (!isBoni && c.registros && c.cantidad > c.registros) 
                    ? `<span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 font-sans tracking-normal ml-1" title="${c.registros} registros atendidos (${c.cantidad - c.registros} estudio(s) doble(s)/bilateral(es))">(${c.registros} reg. • ${c.cantidad - c.registros} dobles)</span>` 
                    : '';

                cardsHtml += `
                    <div class="kpi-concepto-card ${ringClass} bg-white dark:bg-slate-900 p-4 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-tertiary/60 transition-all cursor-pointer hover:scale-[1.02] active:scale-98" 
                        data-concepto="${cKey}"
                        onclick="filtrarPorConcepto('${cKey}')" 
                        title="${isBoni ? 'Hacer clic para filtrar y ver las tomografías contrastadas que generaron esta bonificación' : c.cantidad + ' estudios liquidados en ' + (c.registros || c.cantidad) + ' registros. Hacer clic para filtrar tabla por concepto ' + c.nombre}">
                        <div class="space-y-1 min-w-0 pr-1">
                            <p class="text-[10px] font-bold uppercase tracking-wider truncate ${style.text}">
                                ${badgeLabel}${htmlspecialchars(c.nombre)} <span class="font-mono opacity-80 font-normal">(${htmlspecialchars(c.codigo)})</span>
                            </p>
                            <h3 class="text-2xl font-black font-outfit ${isBoni ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white'}">${cantLabel}${detalleDobles}</h3>
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
                        <td colspan="13" class="py-16 text-center text-slate-400 dark:text-slate-500">
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
                        <td colspan="13" class="py-12 text-center text-slate-400 dark:text-slate-500">
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
                    <td class="py-3 px-4 whitespace-nowrap" onclick="verDetalle('${uniqueId}')">
                        ${origenBadge}
                        <div class="text-[10px] text-slate-400 mt-0.5">Ref: ${htmlspecialchars(item.id)}</div>
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
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black tracking-wider uppercase bg-amber-100 text-amber-900 dark:bg-amber-950/80 dark:text-amber-200 border border-amber-300 dark:border-amber-700/80 shadow-xs mr-1" title="Tomografía (Aplica bono de $150.000 por cada 50 tomografías para todos los médicos y entidades)">
                    <span class="material-symbols-outlined text-[10px] leading-none text-amber-600 dark:text-amber-400">military_tech</span>
                    <span>TOMOGRAFÍA (BONO 50)</span>
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

            // Datos de la Entidad Activa
            const entActivaNombre = <?php echo json_encode($entidadActivaNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'); ?>;
            const entActivaNit    = <?php echo json_encode($entidadActivaNit ?: ''); ?>;
            const entActivaId     = <?php echo json_encode($entidadActivaId ?: 'PROPIO'); ?>;

            const elEmpresaLabel = document.getElementById('liqEmpresaLabel');
            if (elEmpresaLabel) elEmpresaLabel.textContent = entActivaNombre;

            const elEmpresaNitLabel = document.getElementById('liqEmpresaNitLabel');
            if (elEmpresaNitLabel) {
                elEmpresaNitLabel.textContent = entActivaNit ? `• NIT: ${entActivaNit}` : (entActivaId === 'PROPIO' ? '• NIT: 890.980.123-4' : '');
            }

            const elEmpresaPrint = document.getElementById('liqEmpresaNombrePrint');
            if (elEmpresaPrint) elEmpresaPrint.textContent = entActivaNombre;

            const elEmpresaNitPrint = document.getElementById('liqEmpresaNitPrint');
            if (elEmpresaNitPrint) {
                elEmpresaNitPrint.textContent = entActivaNit ? `NIT: ${entActivaNit}` : (entActivaId === 'PROPIO' ? 'SISTEMAS DIAGNÓSTICOS E IMÁGENES MÉDICAS | NIT: 890.980.123-4' : 'ENTIDAD EXTERNA');
            }

            const elEmpresaFirmaPrint = document.getElementById('liqEmpresaFirmaPrint');
            if (elEmpresaFirmaPrint) elEmpresaFirmaPrint.textContent = entActivaNombre;

            const isGlobalMed = (!medicoVal || medicoLabel === '-- Todos los Médicos --');
            const docName = !isGlobalMed ? medicoLabel : 'TODOS LOS MÉDICOS / GLOBAL';

            const elDoctorSubhead = document.getElementById('liqDoctorSubhead');
            const elDoctorName    = document.getElementById('liqDoctorName');
            const elDoctorCedula  = document.getElementById('liqDoctorCedula');
            const elTipoBadge     = document.getElementById('liqTipoLiquidacionBadge');

            if (isGlobalMed) {
                if (elDoctorSubhead) elDoctorSubhead.textContent = 'ALCANCE / MODALIDAD';
                if (elDoctorName) elDoctorName.textContent = 'TODOS LOS MÉDICOS / GLOBAL';
                if (elDoctorCedula) elDoctorCedula.textContent = `CONSOLIDADO (${entActivaNombre})`;
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
            if (docCedSig) docCedSig.textContent = isGlobalMed ? `ENTIDAD: ${entActivaNombre}` : (medicoVal ? `C.C. / ID: ${medicoVal}` : 'C.C. / ID: -');

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

                let conceptoKey = '61251001-HONORARIOS MED RX SIMPLES';
                if (item.cups && (item.cups.toLowerCase().includes('eco') || item.cups.toLowerCase().includes('ultrasonido'))) {
                    conceptoKey = '61251002-HONORARIOS MED ECOGRAFIAS';
                } else if (item.servinte && item.servinte.concepto) {
                    conceptoKey = item.servinte.concepto;
                } else if (item.cups) {
                    conceptoKey = item.cups.length > 35 ? item.cups.substring(0, 35) + '...' : item.cups;
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
                    nombre: `BONIFICACIÓN TOMOGRAFÍAS (${totalTomosContrastadasSels} TOMOGRAFÍAS / ${cantBonificaciones}x$150.000 COP)`,
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
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300 border border-amber-300 dark:border-amber-700 flex items-center gap-1 shrink-0" title="${sObj.noCruzadoCount} registro(s) no cruzado(s) incluidos en esta sede">
                                <span class="material-symbols-outlined text-xs text-amber-600 dark:text-amber-400">warning</span>
                                ${sObj.noCruzadoCount} no cruzado(s) ($${sObj.noCruzadoValor.toLocaleString('es-CO')})
                            </span>
                        `;
                    }

                    let conceptosRowsHtml = '';
                    Object.values(sObj.conceptos).forEach(cObj => {
                        if (cObj.esBonificacion) {
                            conceptosRowsHtml += `
                                <tr class="border-b border-amber-200 dark:border-amber-900/60 bg-amber-50/70 dark:bg-amber-950/30">
                                    <td class="py-2 px-2 text-[11px] font-bold text-amber-900 dark:text-amber-200">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-black uppercase bg-amber-200 text-amber-900 dark:bg-amber-900/90 dark:text-amber-100 shadow-xs">
                                                <span class="material-symbols-outlined text-xs">military_tech</span> BONIFICACIÓN
                                            </span>
                                            <span class="truncate max-w-[200px]" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                        </div>
                                    </td>
                                    <td class="py-2 px-2 text-right font-mono font-bold text-amber-800 dark:text-amber-300 text-[11px]">${cObj.cant} bono(s)</td>
                                    <td class="py-2 px-2 text-right font-mono font-black text-amber-700 dark:text-amber-300 text-[11px]">+$ ${cObj.valor.toLocaleString('es-CO')}</td>
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
                            <div class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700/80 cursor-pointer transition-colors py-1.5 px-3 border-b border-slate-200 dark:border-slate-700 font-bold text-xs text-slate-800 dark:text-slate-200 font-outfit uppercase flex items-center justify-between gap-2 group" onclick="mostrarInformeExamenesSedePreview('${htmlspecialchars(sObj.nombre)}')">
                                <span class="truncate flex items-center gap-1.5">
                                    <i class="fa-solid fa-building text-tertiary"></i>
                                    ${htmlspecialchars(sObj.nombre)}
                                </span>
                                <div class="flex items-center gap-2">
                                    ${warningBadgeHeader}
                                    <span class="text-[10px] text-tertiary group-hover:underline font-extrabold flex items-center gap-1">
                                        <span>Ver informe</span>
                                        <i class="fa-solid fa-arrow-right text-[9px]"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="overflow-x-auto flex-1 p-1">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 dark:bg-slate-800/50 text-[10px] font-bold text-slate-400 uppercase border-b border-slate-200 dark:border-slate-700">
                                            <th class="py-1.5 px-2">CENTRO DE COSTO</th>
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

            // Determinar si el médico liquidado tiene Parafiscales activos, si es Pensionado, Retenciones o Retención Art 383
            let hasParafiscales  = false;
            let isPensionado     = false;
            let hasRetenciones   = false;
            let hasRetencion383  = false;

            if (medicoVal) {
                const uVal = String(medicoVal).trim().toUpperCase();
                const uNom = String(medicoLabel).trim().toUpperCase();
                if (window.medicosParafiscalesMap && (window.medicosParafiscalesMap[uVal] || window.medicosParafiscalesMap[uNom])) {
                    hasParafiscales = true;
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
            }
            const selectedBtn = document.querySelector(`.medico-option-btn[data-value="${medicoVal}"]`);
            if (selectedBtn) {
                if (selectedBtn.getAttribute('data-parafiscales') === '1') hasParafiscales = true;
                if (selectedBtn.getAttribute('data-pensionado') === '1') isPensionado = true;
                if (selectedBtn.getAttribute('data-retenciones') === '1') hasRetenciones = true;
                if (selectedBtn.getAttribute('data-retencion-383') === '1') hasRetencion383 = true;
            }
            if (dataToProcess && dataToProcess.length > 0) {
                const primerMedico = (dataToProcess[0].usuario_medico || dataToProcess[0].usuario || '').trim().toUpperCase();
                const primerNom    = (dataToProcess[0].nombre || '').trim().toUpperCase();
                if (!hasParafiscales && window.medicosParafiscalesMap && (window.medicosParafiscalesMap[primerMedico] || window.medicosParafiscalesMap[primerNom])) {
                    hasParafiscales = true;
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
            }

            // Exclusividad: solo una de las dos modalidades o ninguna
            // Exclusividad: solo una de las dos modalidades o ninguna
            if (hasRetenciones && hasRetencion383) {
                hasRetencion383 = false;
            }

            const hasAnyDeductionActive = (hasParafiscales || isPensionado || hasRetenciones || hasRetencion383);

            window.currentMedicoHasParafiscales  = hasParafiscales;
            window.currentMedicoIsPensionado     = isPensionado;
            window.currentMedicoHasRetenciones   = hasRetenciones;
            window.currentMedicoHasRetencion383  = hasRetencion383;
            window.currentMedicoHasAnyDeduction  = hasAnyDeductionActive;

            const ibcPct     = (window.parafiscalesConfig && window.parafiscalesConfig.IBC !== undefined) ? Number(window.parafiscalesConfig.IBC) : 40.0;
            const saludPct   = (window.parafiscalesConfig && window.parafiscalesConfig.SALUD !== undefined) ? Number(window.parafiscalesConfig.SALUD) : 12.5;
            const pensionPct = (window.parafiscalesConfig && window.parafiscalesConfig.PENSION !== undefined) ? Number(window.parafiscalesConfig.PENSION) : 16.0;
            const arlPct     = (window.parafiscalesConfig && window.parafiscalesConfig.ARL !== undefined) ? Number(window.parafiscalesConfig.ARL) : 2.436;

            const aplicaSeguridadSocial = (hasParafiscales || isPensionado);

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
                            badgeLbl.textContent = 'Médico Pensionado (Salud y ARL)';
                            badgeLbl.className = 'text-indigo-700 dark:text-indigo-300 font-extrabold';
                            if (badgeRates) badgeRates.textContent = `IBC: ${ibcPct}% | Salud: ${saludPct}% | ARL: ${arlPct}% | Pensión: $0 (Exento)`;
                        } else if (hasParafiscales) {
                            badgeDot.className = 'w-2 h-2 rounded-full bg-teal-500 animate-pulse';
                            badgeLbl.textContent = 'Parafiscales Activos para este Médico';
                            badgeLbl.className = 'text-teal-700 dark:text-teal-300 font-extrabold';
                            if (badgeRates) badgeRates.textContent = `IBC: ${ibcPct}% | Salud: ${saludPct}% | Pensión: ${pensionPct}% | ARL: ${arlPct}%`;
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
                saludEstimado   = Math.round(ibcEstimado * (saludPct / 100));
                pensionEstimado = isPensionado ? 0 : Math.round(ibcEstimado * (pensionPct / 100));
                arlEstimado     = Math.round(ibcEstimado * (arlPct / 100));
            }

            // Visibilidad de Deducciones según el perfil del Médico o si es Consolidado Global
            const rowAfc         = document.getElementById('row_ded_afc');
            const rowSolidaridad = document.getElementById('row_ded_solidaridad');
            const rowIbc         = document.getElementById('row_ded_ibc');
            const rowSalud       = document.getElementById('row_ded_salud');
            const rowArl         = document.getElementById('row_ded_arl');
            const rowPension     = document.getElementById('row_ded_pension');
            const rowRetenciones = document.getElementById('row_ded_retenciones');
            const contRete383    = document.getElementById('container_ded_rete_383');
            const contRetencion  = document.getElementById('container_ded_retencion_pct');
            const formBody       = document.getElementById('liqDeduccionesFormBody');
            const noDeducBox     = document.getElementById('liqNoDeduccionesBox');

            window.isCurrentLiquidacionGlobal = isGlobalMed;

            if (isGlobalMed) {
                if (noDeducBox) noDeducBox.classList.add('hidden');
                if (formBody) formBody.classList.remove('hidden');

                // Liquidación Global: Se ocultan parafiscales, aportes y rete 383
                // Únicamente se muestra: PORCENTAJE DE RETENCIÓN, RETENCIÓN y TOTAL DEDUCCIONES
                if (rowAfc)         rowAfc.classList.add('hidden');
                if (rowSolidaridad) rowSolidaridad.classList.add('hidden');
                if (rowIbc)         rowIbc.classList.add('hidden');
                if (rowSalud)       rowSalud.classList.add('hidden');
                if (rowArl)         rowArl.classList.add('hidden');
                if (rowPension)     rowPension.classList.add('hidden');

                if (rowRetenciones) {
                    rowRetenciones.classList.remove('hidden');
                    rowRetenciones.classList.remove('border-t', 'border-slate-100', 'dark:border-slate-800', 'pt-2');
                }
                if (contRete383)    contRete383.classList.add('hidden');
                if (contRetencion)  contRetencion.classList.remove('hidden');

                document.getElementById('ded_afc').value = '0';
                document.getElementById('ded_solidaridad').value = '0';
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

                document.getElementById('ded_afc').value = '0';
                document.getElementById('ded_solidaridad').value = '0';
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

                if (rowIbc)   rowIbc.classList.remove('hidden');
                if (rowSalud) rowSalud.classList.remove('hidden');
                if (rowArl)   rowArl.classList.remove('hidden');

                if (rowRetenciones) {
                    rowRetenciones.classList.add('border-t', 'border-slate-100', 'dark:border-slate-800', 'pt-2');
                }

                // AFC, Solidaridad y Pensión (Se ocultan para pensionados)
                if (isPensionado) {
                    if (rowAfc)         rowAfc.classList.add('hidden');
                    if (rowSolidaridad) rowSolidaridad.classList.add('hidden');
                    if (rowPension)     rowPension.classList.add('hidden');
                } else {
                    if (rowAfc)         rowAfc.classList.remove('hidden');
                    if (rowSolidaridad) rowSolidaridad.classList.remove('hidden');
                    if (rowPension)     rowPension.classList.remove('hidden');
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
                document.getElementById('ded_solidaridad').value = '0';
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

        function recalcularLiquidacion(autoCalcularSeguridadSocial = true) {
            const totalFactura = totalFacturaBaseLiquidador;

            if (window.isCurrentLiquidacionGlobal) {
                // En liquidación global, solo aplica retención porcentual estándar
                document.getElementById('ded_afc').value = '0';
                document.getElementById('ded_solidaridad').value = '0';
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
                const totalPagar = totalFactura - totalDeducciones;

                document.getElementById('liqTotalDeduccionesDisplay').textContent = `- $ ${totalDeducciones.toLocaleString('es-CO')}`;
                document.getElementById('liqTotalPagarDisplay').textContent = `$ ${totalPagar.toLocaleString('es-CO')}`;
                return;
            }

            if (!window.isCurrentLiquidacionGlobal && !window.currentMedicoHasAnyDeduction) {
                document.getElementById('ded_afc').value = '0';
                document.getElementById('ded_solidaridad').value = '0';
                document.getElementById('ded_ibc').value = 0;
                document.getElementById('ded_salud').value = 0;
                document.getElementById('ded_pension').value = 0;
                document.getElementById('ded_arl').value = 0;
                document.getElementById('ded_rete_383').value = 0;
                document.getElementById('ded_retencion').value = 0;
                const dispRetencion = document.getElementById('ded_retencion_display');
                if (dispRetencion) dispRetencion.textContent = `- $ 0`;
                document.getElementById('liqTotalDeduccionesDisplay').textContent = `- $ 0`;
                document.getElementById('liqTotalPagarDisplay').textContent = `$ ${totalFactura.toLocaleString('es-CO')}`;
                return;
            }

            const ibcPct     = (window.parafiscalesConfig && window.parafiscalesConfig.IBC !== undefined) ? Number(window.parafiscalesConfig.IBC) : 40.0;
            const saludPct   = (window.parafiscalesConfig && window.parafiscalesConfig.SALUD !== undefined) ? Number(window.parafiscalesConfig.SALUD) : 12.5;
            const pensionPct = (window.parafiscalesConfig && window.parafiscalesConfig.PENSION !== undefined) ? Number(window.parafiscalesConfig.PENSION) : 16.0;
            const arlPct     = (window.parafiscalesConfig && window.parafiscalesConfig.ARL !== undefined) ? Number(window.parafiscalesConfig.ARL) : 2.436;

            const afc         = parseMontoInput(document.getElementById('ded_afc').value);
            const solidaridad = parseMontoInput(document.getElementById('ded_solidaridad').value);

            let ibc = parseFloat(document.getElementById('ded_ibc').value) || 0;
            const aplicaSeguridadSocial = (window.currentMedicoHasParafiscales || window.currentMedicoIsPensionado);
            
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

            let salud   = parseFloat(document.getElementById('ded_salud').value) || 0;
            let pension = parseFloat(document.getElementById('ded_pension').value) || 0;
            let arl     = parseFloat(document.getElementById('ded_arl').value) || 0;

            if (autoCalcularSeguridadSocial) {
                if (aplicaSeguridadSocial && ibc > 0) {
                    salud   = Math.round(ibc * (saludPct / 100));
                    pension = window.currentMedicoIsPensionado ? 0 : Math.round(ibc * (pensionPct / 100));
                    arl     = Math.round(ibc * (arlPct / 100));
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
            const totalPagar = totalFactura - totalDeducciones;

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
            const solidaridad  = parseMontoInput(document.getElementById('ded_solidaridad').value);
            const ibc          = parseMontoInput(document.getElementById('ded_ibc').value);
            const salud        = parseMontoInput(document.getElementById('ded_salud').value);
            const pension      = parseMontoInput(document.getElementById('ded_pension').value);
            const arl          = parseMontoInput(document.getElementById('ded_arl').value);
            const rete383Info  = parseMontoInput(document.getElementById('ded_rete_383_info') ? document.getElementById('ded_rete_383_info').value : 0);
            const rete383      = parseMontoInput(document.getElementById('ded_rete_383').value);
            const retencionPct = parseFloat(document.getElementById('ded_retencion_pct').value) || 0;
            const retencion    = parseMontoInput(document.getElementById('ded_retencion').value);

            const totalFactura     = totalFacturaBaseLiquidador;
            const totalDeducciones = afc + solidaridad + salud + pension + arl + rete383 + retencion;
            const totalAPagar      = totalFactura - totalDeducciones;

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
            const valorBonisGuardado = cantBonisGuardado * 150000;
            const sedesResumenKeys = Object.keys(sedesResumen);

            if (cantBonisGuardado > 0 && sedesResumenKeys.length > 0) {
                let sedeMayorTomo = sedesResumenKeys[0];
                let maxTomo = -1;
                sedesResumenKeys.forEach(sKey => {
                    const countS = sedesTomoCountGuardado[sKey] || 0;
                    if (countS > maxTomo) {
                        maxTomo = countS;
                        sedeMayorTomo = sKey;
                    }
                });

                sedesResumen[sedeMayorTomo].total += valorBonisGuardado;
                sedesResumen[sedeMayorTomo].examenes.push({
                    origen: 'SISTEMA',
                    id_ref: 'BONI_TOHO',
                    fuente: 'LIHO',
                    ingreso: 'BONIFICACION',
                    tipo_paciente: 'Incentivo',
                    fecha: (new Date()).toISOString().substring(0, 10),
                    sede: sedeMayorTomo,
                    medico_nombre: medicoNombre,
                    medico_cedula: medicoCedula,
                    paciente: 'INCENTIVO POR PRODUCTIVIDAD',
                    documento: 'N/A',
                    entidad: 'LIHO IPS',
                    cups: 'BONI_TOHO',
                    examen: `BONIFICACIÓN TOMOGRAFÍAS (${totalTomosContrastadasGuardado} TOMOGRAFÍAS / ${cantBonisGuardado}x$150.000 COP)`,
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
                entidad_id: typeof entActivaId !== 'undefined' ? entActivaId : 'PROPIO',
                entidad_nombre: typeof entActivaNombre !== 'undefined' ? entActivaNombre : 'HERNÁN OCAZIONEZ Y CÍA S.A.S.',
                total_factura: totalFactura,
                ded_afc: afc,
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
                    });
                    cerrarModalLiquidacion();
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
                    : `<span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-rose-950 text-rose-300 border border-rose-800 inline-flex items-center gap-1"><i class="fa-solid fa-circle-xmark"></i> No Cruzado (Solo Proteo)</span>`;

                rowsHtml += `
                    <tr class="border-b border-slate-800 hover:bg-slate-800/50 transition-colors text-left text-xs">
                        <td class="py-2.5 px-3">
                            <div class="font-bold text-slate-100">${htmlspecialchars(ex.paciente || 'PACIENTE')}</div>
                            <div class="text-[10px] font-mono text-slate-400">ID/CC: ${htmlspecialchars(ex.documento || 'N/A')}</div>
                        </td>
                        <td class="py-2.5 px-3">
                            <div class="font-semibold text-slate-200 flex items-center gap-1.5 flex-wrap">
                                <span>${htmlspecialchars(ex.nombre_examen || ex.cups || 'EXAMEN')}</span>
                                ${(esTextoComparativo(ex.nombre_examen || ex.cups || '') || esTextoComparativo(ex.detail || '')) ? `
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[8px] font-black uppercase bg-indigo-900/90 text-indigo-200 border border-indigo-700 shadow-xs">
                                        <i class="fa-solid fa-code-compare text-[8px]"></i> COMPARATIVO
                                    </span>
                                ` : ''}
                            </div>
                            ${ex.cups ? `<div class="text-[10px] text-teal-400 font-mono">CUPS: ${htmlspecialchars(ex.cups)}</div>` : ''}
                        </td>
                        <td class="py-2.5 px-3 font-mono text-[11px] text-slate-300">${htmlspecialchars(ex.ingreso || ex.fuente_id || 'N/A')}</td>
                        <td class="py-2.5 px-3 text-right font-mono font-bold text-emerald-400">$ ${valPagar.toLocaleString('es-CO')}</td>
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
                        <div class="flex justify-between items-center p-3 rounded-xl bg-slate-800/80 border border-slate-700/80 text-xs">
                            <span class="text-slate-300 font-medium">Total Exámenes Sede: <strong class="text-white font-mono text-sm">${totalRegistrosSede.toLocaleString('es-CO')}</strong></span>
                            <span class="text-slate-300 font-medium">Total Facturado: <strong class="text-tertiary font-mono text-sm">$ ${totalVal.toLocaleString('es-CO')}</strong></span>
                        </div>
                        <div class="max-h-[350px] overflow-y-auto rounded-xl border border-slate-800 bg-slate-950">
                            <table class="w-full text-left border-collapse">
                                <thead class="sticky top-0 bg-slate-900 text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-800">
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
