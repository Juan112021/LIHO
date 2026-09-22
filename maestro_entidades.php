<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');

$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId    = (int)($_SESSION['user_id'] ?? 0);
$userEmail = $_SESSION['user_email'] ?? '';
$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Control estricto de acceso a Maestro de Entidades / IPS
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && !tienePermisoModulo($userId, $userRoleIdVal, 'maestro_entidades')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Permisos para editar (Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditEntidad = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// Helper para asignar color aleatorio sin repetir entre entidades
function obtenerSiguienteColorEntidad($con, $excluirId = 0) {
    $paleta = ['sky', 'teal', 'indigo', 'purple', 'rose', 'pink', 'amber', 'emerald', 'orange', 'slate'];
    $usos = array_fill_keys($paleta, 0);

    if ($con) {
        $sql = "SELECT id, color_tema FROM maestro_entidades WHERE (estado = 1 OR estado = 0)";
        $params = [];
        if ($excluirId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excluirId;
        }
        $stmt = sqlsrv_query($con, $sql, $params);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $col = strtolower(trim($row['color_tema'] ?? ''));
                if (isset($usos[$col])) {
                    $usos[$col]++;
                }
            }
        }
    }

    // Buscar colores no usados
    $noUsados = [];
    foreach ($usos as $color => $cant) {
        if ($cant === 0) {
            $noUsados[] = $color;
        }
    }

    // Si hay colores disponibles sin usar, elegir uno aleatoriamente
    if (!empty($noUsados)) {
        return $noUsados[array_rand($noUsados)];
    }

    // Si ya todos se usaron al menos una vez, elegir aleatoriamente entre los que tengan la menor cantidad de usos
    $minUso = min($usos);
    $menosUsados = [];
    foreach ($usos as $color => $cant) {
        if ($cant === $minUso) {
            $menosUsados[] = $color;
        }
    }
    return $menosUsados[array_rand($menosUsados)];
}

// --------------------------------------------------------------------------
// PROCESAMIENTO AJAX
// --------------------------------------------------------------------------

// 1. AJAX: CREAR NUEVA ENTIDAD / IPS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_entidad') {
    header('Content-Type: application/json');

    if (!$canEditEntidad) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para registrar entidades o IPS.']);
        exit;
    }

    $nombre    = trim($_POST['nombre'] ?? '');
    $nit       = trim($_POST['nit'] ?? '');
    $dv        = !empty($_POST['dv']) ? trim($_POST['dv']) : null;
    $email     = !empty($_POST['email']) ? trim($_POST['email']) : null;
    $telefono  = !empty($_POST['telefono']) ? trim($_POST['telefono']) : null;
    $direccion = !empty($_POST['direccion']) ? trim($_POST['direccion']) : null;
    $ciudad    = !empty($_POST['ciudad']) ? trim($_POST['ciudad']) : null;
    $contacto  = !empty($_POST['contacto_nombre']) ? trim($_POST['contacto_nombre']) : null;
    $codReps   = !empty($_POST['codigo_habilitacion']) ? trim($_POST['codigo_habilitacion']) : null;
    $observ    = !empty($_POST['observaciones']) ? trim($_POST['observaciones']) : null;
    $colorTema = !empty($_POST['color_tema']) ? strtolower(trim($_POST['color_tema'])) : obtenerSiguienteColorEntidad($con);

    if (empty($nombre) || empty($nit)) {
        echo json_encode(['success' => false, 'message' => 'El Nombre de la IPS / Razón Social y el NIT son obligatorios.']);
        exit;
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'El formato del correo electrónico no es válido.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos SQL Server.']);
        exit;
    }

    // Verificar duplicidad de NIT
    $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_entidades WHERE nit = ?", array($nit));
    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "Ya existe una entidad registrada con el NIT '$nit'."]);
        exit;
    }

    // Manejo de subida de Logo
    $logoPath = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['logo']['tmp_name'];
        $fileName      = $_FILES['logo']['name'];
        $fileSize      = $_FILES['logo']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = array('jpg', 'jpeg', 'png', 'webp', 'svg');
        if (!in_array($fileExtension, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'Formato de logo no válido. Formatos permitidos: JPG, PNG, WEBP, SVG.']);
            exit;
        }
        if ($fileSize > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'El archivo de logo supera el límite de 5 MB.']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/entidades/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $cleanNit = preg_replace('/[^a-zA-Z0-9]/', '', $nit);
        $newFileName = 'logo_' . ($cleanNit ?: 'ent') . '_' . time() . '.' . $fileExtension;
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $logoPath = 'uploads/entidades/' . $newFileName;
        }
    }

    $sqlIns = "INSERT INTO maestro_entidades 
               (nombre, nit, dv, email, telefono, direccion, ciudad, contacto_nombre, codigo_habilitacion, observaciones, estado, fecha_creacion, fecha_actualizacion, usuario_creacion_id, logo, color_tema) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?, ?, ?); 
               SELECT SCOPE_IDENTITY() AS new_id;";
    
    $params = array($nombre, $nit, $dv, $email, $telefono, $direccion, $ciudad, $contacto, $codReps, $observ, $userId, $logoPath, $colorTema);
    $stmtIns = sqlsrv_query($con, $sqlIns, $params);

    if ($stmtIns === false) {
        echo json_encode(['success' => false, 'message' => 'Error al registrar la entidad: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    sqlsrv_next_result($stmtIns);
    $rowId = sqlsrv_fetch_array($stmtIns, SQLSRV_FETCH_ASSOC);
    $newId = $rowId['new_id'] ?? 0;

    echo json_encode([
        'success' => true,
        'message' => 'Entidad / IPS registrada exitosamente.',
        'data' => [
            'id' => $newId,
            'nombre' => $nombre,
            'nit' => $nit,
            'email' => $email ?? '',
            'logo' => $logoPath
        ]
    ]);
    exit;
}

// 2. AJAX: OBTENER DETALLES DE UNA ENTIDAD
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'obtener_entidad') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0 || !isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'ID de entidad inválido.']);
        exit;
    }

    $stmt = sqlsrv_query($con, "SELECT * FROM maestro_entidades WHERE id = ?", array($id));
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Formatear fechas para JSON
        if ($row['fecha_creacion'] instanceof DateTime) {
            $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
        }
        if ($row['fecha_actualizacion'] instanceof DateTime) {
            $row['fecha_actualizacion'] = $row['fecha_actualizacion']->format('Y-m-d H:i:s');
        }
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Entidad no encontrada.']);
    }
    exit;
}

// 3. AJAX: ACTUALIZAR ENTIDAD
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar_entidad') {
    header('Content-Type: application/json');

    if (!$canEditEntidad) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar entidades.']);
        exit;
    }

    $id        = (int)($_POST['id'] ?? 0);
    $nombre    = trim($_POST['nombre'] ?? '');
    $nit       = trim($_POST['nit'] ?? '');
    $dv        = !empty($_POST['dv']) ? trim($_POST['dv']) : null;
    $email     = !empty($_POST['email']) ? trim($_POST['email']) : null;
    $telefono  = !empty($_POST['telefono']) ? trim($_POST['telefono']) : null;
    $direccion = !empty($_POST['direccion']) ? trim($_POST['direccion']) : null;
    $ciudad    = !empty($_POST['ciudad']) ? trim($_POST['ciudad']) : null;
    $contacto  = !empty($_POST['contacto_nombre']) ? trim($_POST['contacto_nombre']) : null;
    $codReps   = !empty($_POST['codigo_habilitacion']) ? trim($_POST['codigo_habilitacion']) : null;
    $observ    = !empty($_POST['observaciones']) ? trim($_POST['observaciones']) : null;
    $estado    = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    if ($id <= 0 || empty($nombre) || empty($nit)) {
        echo json_encode(['success' => false, 'message' => 'El Nombre de la IPS / Razón Social y el NIT son obligatorios.']);
        exit;
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'El formato del correo electrónico no es válido.']);
        exit;
    }

    // Verificar duplicidad de NIT excluyendo la entidad actual
    $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_entidades WHERE nit = ? AND id <> ?", array($nit, $id));
    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "El NIT '$nit' ya pertenece a otra entidad registrada."]);
        exit;
    }

    // Obtener logo y color actual
    $stmtCur = sqlsrv_query($con, "SELECT logo, color_tema FROM maestro_entidades WHERE id = ?", array($id));
    $currentLogo = null;
    $currentColor = null;
    if ($stmtCur && $rCur = sqlsrv_fetch_array($stmtCur, SQLSRV_FETCH_ASSOC)) {
        $currentLogo = $rCur['logo'] ?? null;
        $currentColor = $rCur['color_tema'] ?? null;
    }
    // Priorizar color seleccionado por el usuario; de lo contrario conservar el actual o generar uno
    if (!empty($_POST['color_tema'])) {
        $colorTema = strtolower(trim($_POST['color_tema']));
    } else {
        $colorTema = (!empty($currentColor)) ? strtolower(trim($currentColor)) : obtenerSiguienteColorEntidad($con, $id);
    }

    $eliminarLogo = isset($_POST['eliminar_logo']) && $_POST['eliminar_logo'] == '1';
    $logoPath = $currentLogo;

    if ($eliminarLogo && !empty($currentLogo)) {
        if (file_exists(__DIR__ . '/' . $currentLogo) && strpos($currentLogo, 'uploads/entidades/') !== false) {
            @unlink(__DIR__ . '/' . $currentLogo);
        }
        $logoPath = null;
    }

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['logo']['tmp_name'];
        $fileName      = $_FILES['logo']['name'];
        $fileSize      = $_FILES['logo']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = array('jpg', 'jpeg', 'png', 'webp', 'svg');
        if (!in_array($fileExtension, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'Formato de logo no válido. Formatos permitidos: JPG, PNG, WEBP, SVG.']);
            exit;
        }
        if ($fileSize > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'El archivo de logo supera el límite de 5 MB.']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/entidades/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $cleanNit = preg_replace('/[^a-zA-Z0-9]/', '', $nit);
        $newFileName = 'logo_' . ($cleanNit ?: 'ent') . '_' . time() . '.' . $fileExtension;
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            if (!empty($currentLogo) && file_exists(__DIR__ . '/' . $currentLogo) && strpos($currentLogo, 'uploads/entidades/') !== false) {
                @unlink(__DIR__ . '/' . $currentLogo);
            }
            $logoPath = 'uploads/entidades/' . $newFileName;
        }
    }

    $sqlUpd = "UPDATE maestro_entidades SET 
                nombre = ?, nit = ?, dv = ?, email = ?, telefono = ?, direccion = ?, ciudad = ?, 
                contacto_nombre = ?, codigo_habilitacion = ?, observaciones = ?, estado = ?, logo = ?, color_tema = ?,
                fecha_actualizacion = GETDATE() 
               WHERE id = ?";
    $params = array($nombre, $nit, $dv, $email, $telefono, $direccion, $ciudad, $contacto, $codReps, $observ, $estado, $logoPath, $colorTema, $id);
    $stmtUpd = sqlsrv_query($con, $sqlUpd, $params);

    if ($stmtUpd === false) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar la entidad: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Entidad actualizada correctamente.',
        'data' => [
            'id' => $id,
            'nombre' => $nombre,
            'nit' => $nit . (!empty($dv) ? "-$dv" : ''),
            'email' => $email,
            'estado' => $estado
        ]
    ]);
    exit;
}

// 4. AJAX: ACTIVAR / DESACTIVAR ENTIDAD (POLÍTICA: SOLO DESACTIVAR, NUNCA ELIMINAR)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado') {
    header('Content-Type: application/json');

    if (!$canEditEntidad) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar el estado de entidades.']);
        exit;
    }

    $id          = (int)($_POST['id'] ?? 0);
    $nuevoEstado = (int)($_POST['estado'] ?? 1);

    if ($id <= 0 || !isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'ID no válido.']);
        exit;
    }

    $stmt = sqlsrv_query($con, "UPDATE maestro_entidades SET estado = ?, fecha_actualizacion = GETDATE() WHERE id = ?", array($nuevoEstado, $id));
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Error al cambiar estado en base de datos.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => ($nuevoEstado === 1) ? 'Entidad activada correctamente.' : 'Entidad desactivada correctamente.',
        'nuevo_estado' => $nuevoEstado
    ]);
    exit;
}

// 5. AJAX: OBTENER MÉDICOS VINCULADOS A LA ENTIDAD
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'obtener_medicos_entidad') {
    header('Content-Type: application/json');
    $entidadId = (int)($_GET['id'] ?? 0);
    if ($entidadId <= 0 || !isset($con) || $con === false) {
        echo json_encode(['success' => false, 'medicos' => []]);
        exit;
    }

    $sqlM = "SELECT u.id AS usuario_id, u.nombre_completo, u.email, u.cedula, u.estado,
                    m.usuario_proteo, m.pnom, m.pape
             FROM usuarios u
             LEFT JOIN medicos m ON u.id = m.usuario_id
             WHERE (u.entidad_id = ? OR m.entidad_id = ?)
             ORDER BY u.nombre_completo ASC";
    $stmtM = sqlsrv_query($con, $sqlM, array($entidadId, $entidadId));
    $medicosArr = [];
    if ($stmtM !== false) {
        while ($rM = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC)) {
            $nom = trim($rM['nombre_completo'] ?? '');
            if (empty($nom)) {
                $nom = trim(($rM['pnom'] ?? '') . ' ' . ($rM['pape'] ?? ''));
            }
            $medicosArr[] = [
                'usuario_id' => $rM['usuario_id'],
                'nombre' => $nom,
                'email' => $rM['email'] ?? '',
                'cedula' => $rM['cedula'] ?? '',
                'proteo' => $rM['usuario_proteo'] ?? '',
                'activo' => ((int)($rM['estado'] ?? 1) === 1)
            ];
        }
    }

    echo json_encode(['success' => true, 'medicos' => $medicosArr]);
    exit;
}

// --------------------------------------------------------------------------
// CARGA DE DATOS PARA LA VISTA
// --------------------------------------------------------------------------
$entidadesList = [];
$totalEntidades = 0;
$totalActivas   = 0;
$totalInactivas = 0;
$totalMedicosExternos = 0;

if (isset($con) && $con !== false) {
    // 1. Total médicos asignados a entidades externas (excluyendo la IPS Matriz)
    $sqlTotMed = "SELECT COUNT(DISTINCT u.id) AS total 
                  FROM usuarios u 
                  INNER JOIN maestro_entidades e ON u.entidad_id = e.id 
                  WHERE ISNULL(e.is_matriz, 0) = 0 AND u.entidad_id > 0";
    $stmtTotMed = sqlsrv_query($con, $sqlTotMed);
    if ($stmtTotMed && $rTM = sqlsrv_fetch_array($stmtTotMed, SQLSRV_FETCH_ASSOC)) {
        $totalMedicosExternos = (int)($rTM['total'] ?? 0);
    }

    // 2. Consulta de entidades con conteo de médicos vinculados y tarifas propias
    $sqlEnt = "
        SELECT e.id, e.nombre, e.nit, e.dv, e.email, e.telefono, e.direccion, e.ciudad, 
               e.contacto_nombre, e.codigo_habilitacion, e.observaciones, ISNULL(e.estado, 1) AS estado,
               e.fecha_creacion, e.fecha_actualizacion, e.logo, e.color_tema, ISNULL(e.is_matriz, 0) AS is_matriz,
               ISNULL(medCount.total_medicos, 0) AS total_medicos,
               ISNULL(tarCount.total_tarifas, 0) AS total_tarifas
        FROM maestro_entidades e
        LEFT JOIN (
            SELECT entidad_id, COUNT(DISTINCT id) AS total_medicos
            FROM usuarios
            WHERE entidad_id IS NOT NULL
            GROUP BY entidad_id
        ) medCount ON e.id = medCount.entidad_id
        LEFT JOIN (
            SELECT entidad_id, COUNT(*) AS total_tarifas
            FROM tarifario
            WHERE entidad_id IS NOT NULL
            GROUP BY entidad_id
        ) tarCount ON e.id = tarCount.entidad_id
        ORDER BY CASE WHEN ISNULL(e.is_matriz, 0) = 1 THEN 0 ELSE 1 END, e.nombre ASC";

    $stmtEnt = sqlsrv_query($con, $sqlEnt);
    if ($stmtEnt !== false) {
        while ($row = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
            $row['id'] = (int)$row['id'];
            $row['estado'] = (int)$row['estado'];
            $row['is_matriz'] = (int)($row['is_matriz'] ?? 0);
            $row['total_medicos'] = (int)$row['total_medicos'];
            $row['total_tarifas'] = (int)$row['total_tarifas'];
            $entidadesList[] = $row;

            $totalEntidades++;
            if ($row['estado'] === 1) {
                $totalActivas++;
            } else {
                $totalInactivas++;
            }
        }
    }

    // Garantizar que ninguna entidad registrada comparta el mismo color temático
    $coloresBase = ['indigo', 'sky', 'purple', 'rose', 'amber'];
    $coloresAsignados = [];
    foreach ($entidadesList as &$entItem) {
        if (!empty($entItem['is_matriz'])) {
            $entItem['color_tema'] = 'teal';
            continue;
        }
        $c = strtolower(trim($entItem['color_tema'] ?? ''));
        if (empty($c) || in_array($c, $coloresAsignados)) {
            $libres = array_values(array_diff($coloresBase, $coloresAsignados));
            if (!empty($libres)) {
                $c = $libres[array_rand($libres)];
            } else {
                $c = $coloresBase[array_rand($coloresBase)];
            }
            $entItem['color_tema'] = $c;
            sqlsrv_query($con, "UPDATE maestro_entidades SET color_tema = ? WHERE id = ?", array($c, $entItem['id']));
        }
        $coloresAsignados[] = $c;
    }
    unset($entItem);
}

// Mapeo temático de colores para ambientación de cards y badges
$paletaEntidades = [
    'teal'    => [
        'border_glow' => 'hover:border-teal-500/50',
        'badge_bg'    => 'bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 border-teal-200 dark:border-teal-800',
        'accent_bar'  => 'from-teal-400 via-teal-500 to-cyan-500',
        'icon_bg'     => 'bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-300',
        'ring'        => 'ring-teal-400/30'
    ],
    'sky'     => [
        'border_glow' => 'hover:border-sky-500/50',
        'badge_bg'    => 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-800',
        'accent_bar'  => 'from-sky-400 via-sky-500 to-blue-500',
        'icon_bg'     => 'bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-300',
        'ring'        => 'ring-sky-400/30'
    ],
    'indigo'  => [
        'border_glow' => 'hover:border-indigo-500/50',
        'badge_bg'    => 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800',
        'accent_bar'  => 'from-indigo-400 via-indigo-500 to-purple-500',
        'icon_bg'     => 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-300',
        'ring'        => 'ring-indigo-400/30'
    ],
    'purple'  => [
        'border_glow' => 'hover:border-purple-500/50',
        'badge_bg'    => 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
        'accent_bar'  => 'from-purple-400 via-purple-500 to-pink-500',
        'icon_bg'     => 'bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-300',
        'ring'        => 'ring-purple-400/30'
    ],
    'rose'    => [
        'border_glow' => 'hover:border-rose-500/50',
        'badge_bg'    => 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800',
        'accent_bar'  => 'from-rose-400 via-rose-500 to-pink-500',
        'icon_bg'     => 'bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-300',
        'ring'        => 'ring-rose-400/30'
    ],
    'pink'    => [
        'border_glow' => 'hover:border-pink-500/50',
        'badge_bg'    => 'bg-pink-50 dark:bg-pink-950/60 text-pink-700 dark:text-pink-300 border-pink-200 dark:border-pink-800',
        'accent_bar'  => 'from-pink-400 via-pink-500 to-rose-400',
        'icon_bg'     => 'bg-pink-50 dark:bg-pink-950/60 text-pink-600 dark:text-pink-300',
        'ring'        => 'ring-pink-400/30'
    ],
    'amber'   => [
        'border_glow' => 'hover:border-amber-500/50',
        'badge_bg'    => 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800',
        'accent_bar'  => 'from-amber-400 via-amber-500 to-orange-400',
        'icon_bg'     => 'bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-300',
        'ring'        => 'ring-amber-400/30'
    ],
    'emerald' => [
        'border_glow' => 'hover:border-emerald-500/50',
        'badge_bg'    => 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
        'accent_bar'  => 'from-emerald-400 via-emerald-500 to-teal-500',
        'icon_bg'     => 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-300',
        'ring'        => 'ring-emerald-400/30'
    ],
    'orange'  => [
        'border_glow' => 'hover:border-orange-500/50',
        'badge_bg'    => 'bg-orange-50 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800',
        'accent_bar'  => 'from-orange-400 via-orange-500 to-amber-500',
        'icon_bg'     => 'bg-orange-50 dark:bg-orange-950/60 text-orange-600 dark:text-orange-300',
        'ring'        => 'ring-orange-400/30'
    ],
    'slate'   => [
        'border_glow' => 'hover:border-slate-500/50',
        'badge_bg'    => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700',
        'accent_bar'  => 'from-slate-400 via-slate-500 to-slate-600',
        'icon_bg'     => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300',
        'ring'        => 'ring-slate-400/30'
    ],
];
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Maestro de Entidades e IPS | LIHO</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <!-- Google Fonts: Montserrat & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="shortcut icon" href="assets/img/hologo.png">

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#14354e",
                        tertiary: "#00c1be",
                        background: "#f8fafc"
                    },
                    fontFamily: {
                        sans: ["Montserrat", "sans-serif"],
                        outfit: ["Outfit", "sans-serif"]
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Montserrat', sans-serif; }
        .font-outfit { font-family: 'Outfit', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }

        /* Animaciones Suaves para Cards */
        @keyframes cardCascade {
            0% {
                opacity: 0;
                transform: translateY(18px) scale(0.98);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .entidad-card-animated {
            animation: cardCascade 0.42s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .entidad-card {
            transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.32s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.32s ease;
        }
        .entidad-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.08), 0 10px 15px -5px rgba(0, 0, 0, 0.04);
        }
        .dark .entidad-card:hover {
            box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.5), 0 0 25px -5px rgba(0, 193, 190, 0.12);
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header Navigation -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Container -->
    <main class="flex-grow max-w-[1300px] w-full mx-auto px-4 sm:px-6 py-8">

        <!-- Encabezado y Acciones -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-500/10 dark:bg-cyan-950/60 text-cyan-700 dark:text-cyan-300 text-[11px] font-extrabold uppercase tracking-wider mb-2 border border-cyan-200/60 dark:border-cyan-800">
                    <span class="material-symbols-outlined text-sm">domain</span>
                    <span>Directorio Institucional & Empresas Aliadas</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-primary dark:text-white tracking-tight font-outfit">
                    Maestro de Entidades / IPS
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-1">
                    Administración de clínicas, empresas e instituciones externas a las que pertenecen los médicos para su correspondiente liquidación.
                </p>
            </div>

            <div class="flex items-center gap-3 w-full sm:w-auto">
                <a href="medicos.php" class="px-4 py-2 text-xs font-bold rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition-all flex items-center gap-2 shadow-sm">
                    <span class="material-symbols-outlined text-base">medical_services</span>
                    <span>Gestión de Médicos</span>
                </a>
                <?php if ($canEditEntidad): ?>
                <button type="button" onclick="abrirModalCrearEntidad()"
                    class="px-4 py-2 text-xs font-bold rounded-xl bg-gradient-to-r from-tertiary to-cyan-500 hover:from-teal-600 hover:to-cyan-600 text-white shadow-md shadow-tertiary/20 hover:shadow-lg hover:shadow-tertiary/30 transition-all flex items-center gap-2 cursor-pointer">
                    <span class="material-symbols-outlined text-base">domain_add</span>
                    <span>Nueva Entidad</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pestañas de Navegación Rápida -->
        <div class="flex items-center gap-2 overflow-x-auto pb-2 mb-6 custom-scrollbar">
            <a href="medicos.php" class="px-3.5 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-sm text-slate-400">medical_services</span>
                <span>Médicos IPS</span>
            </a>
            <a href="maestro_entidades.php" class="px-3.5 py-1.5 rounded-xl text-xs font-bold bg-primary text-white shadow-sm flex items-center gap-1.5">
                <span class="material-symbols-outlined text-sm text-tertiary">domain</span>
                <span>Entidades / IPS</span>
            </a>
            <a href="maestro_porcentajes.php" class="px-3.5 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-sm text-slate-400">percent</span>
                <span>Modalidades y Porcentajes</span>
            </a>
            <a href="tarifario.php" class="px-3.5 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 transition-colors flex items-center gap-1.5">
                <span class="material-symbols-outlined text-sm text-slate-400">request_quote</span>
                <span>Tarifario General</span>
            </a>
        </div>

        <!-- Tarjetas KPI -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <!-- Total Entidades -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-cyan-50 dark:bg-cyan-950/60 text-cyan-600 dark:text-cyan-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">domain</span>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Total Entidades</p>
                    <p class="text-2xl font-black text-slate-800 dark:text-slate-100 font-outfit" id="kpiTotal"><?php echo $totalEntidades; ?></p>
                </div>
            </div>

            <!-- Entidades Activas -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">verified</span>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Activas</p>
                    <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-outfit" id="kpiActivas"><?php echo $totalActivas; ?></p>
                </div>
            </div>

            <!-- Entidades Inactivas -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">visibility_off</span>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Inactivas</p>
                    <p class="text-2xl font-black text-slate-500 dark:text-slate-400 font-outfit" id="kpiInactivas"><?php echo $totalInactivas; ?></p>
                </div>
            </div>

            <!-- Médicos Vinculados -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">groups</span>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Médicos Externos</p>
                    <p class="text-2xl font-black text-indigo-600 dark:text-indigo-400 font-outfit" id="kpiMedicos"><?php echo $totalMedicosExternos; ?></p>
                </div>
            </div>
        </div>

        <!-- Filtros y Búsqueda -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm mb-6 flex flex-col lg:flex-row items-center justify-between gap-4">
            <div class="relative w-full lg:w-96">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg pointer-events-none">search</span>
                <input type="text" id="searchInput" placeholder="Buscar por nombre IPS, NIT, correo o ciudad..."
                    onkeyup="filtrarTabla()"
                    class="w-full pl-9 pr-4 py-2 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all" />
            </div>

            <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto justify-between lg:justify-end">
                <!-- Selector de Vista: Tarjetas o Tabla -->
                <div class="flex items-center p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700 shrink-0">
                    <button type="button" id="btnVistaCards" onclick="cambiarVista('cards')"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-white dark:bg-slate-700 text-primary dark:text-white shadow-xs cursor-pointer">
                        <span class="material-symbols-outlined text-base">grid_view</span>
                        <span>Tarjetas</span>
                    </button>
                    <button type="button" id="btnVistaTabla" onclick="cambiarVista('tabla')"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white cursor-pointer">
                        <span class="material-symbols-outlined text-base">view_list</span>
                        <span>Tabla</span>
                    </button>
                </div>

                <!-- Filtro por Estado -->
                <div class="flex items-center gap-2">
                    <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider hidden sm:inline">Estado:</label>
                    <select id="filtroEstado" onchange="filtrarTabla()"
                        class="px-3 py-2 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none cursor-pointer">
                        <option value="">Todas las Entidades</option>
                        <option value="1">Solo Activas</option>
                        <option value="0">Solo Inactivas</option>
                    </select>
                </div>

                <button type="button" onclick="limpiarFiltros()"
                    class="px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-500 hover:text-slate-800 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    Restablecer
                </button>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- VISTA 1: CARDS DINÁMICAS (PREDETERMINADA)                      -->
        <!-- ============================================================== -->
        <div id="contenedorCards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
            <?php if (empty($entidadesList)): ?>
                <div class="col-span-full py-16 text-center text-slate-400 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-5xl text-slate-300 dark:text-slate-600">domain_disabled</span>
                        <p class="font-bold text-base text-slate-700 dark:text-slate-200">No hay entidades registradas en el sistema</p>
                        <p class="text-xs">Haga clic en "+ Nueva Entidad" para registrar la primera IPS o empresa externa.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($entidadesList as $index => $ent): ?>
                    <?php
                        $cKey = strtolower(trim($ent['color_tema'] ?? 'sky'));
                        $theme = $paletaEntidades[$cKey] ?? $paletaEntidades['sky'];
                        if (!empty($ent['is_matriz'])) {
                            $theme = $paletaEntidades['teal'];
                        }
                        $nitCompleto = htmlspecialchars($ent['nit'] ?? '');
                        $esActiva = ($ent['estado'] === 1);
                        $delay = min($index * 0.04, 0.45);
                    ?>
                    <div class="entidad-card entidad-card-animated bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-sm relative group flex flex-col justify-between overflow-hidden <?php echo $theme['border_glow']; ?>"
                        style="animation-delay: <?php echo $delay; ?>s;"
                        data-nombre="<?php echo strtolower(htmlspecialchars($ent['nombre'])); ?>"
                        data-nit="<?php echo strtolower(htmlspecialchars($nitCompleto)); ?>"
                        data-email="<?php echo strtolower(htmlspecialchars($ent['email'] ?? '')); ?>"
                        data-ciudad="<?php echo strtolower(htmlspecialchars($ent['ciudad'] ?? '')); ?>"
                        data-estado="<?php echo $ent['estado']; ?>">
                        
                        <!-- Barra de acento superior con degradado dinámico -->
                        <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r <?php echo $theme['accent_bar']; ?> opacity-80 group-hover:opacity-100 transition-opacity"></div>

                        <!-- Parte Superior: Header de la Card -->
                        <div>
                            <div class="flex items-start justify-between gap-3 mb-3 pt-1">
                                <!-- Logo o Monograma Institucional -->
                                <div class="flex items-center gap-3 min-w-0">
                                    <?php if (!empty($ent['logo']) && file_exists(__DIR__ . '/' . $ent['logo'])): ?>
                                        <div class="w-12 h-12 rounded-2xl bg-white p-1.5 flex items-center justify-center shrink-0 border border-slate-200/80 dark:border-slate-700 shadow-xs overflow-hidden group-hover:scale-105 transition-transform duration-300">
                                            <img src="<?php echo htmlspecialchars($ent['logo']); ?>" alt="Logo" class="w-full h-full object-contain" />
                                        </div>
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-2xl <?php echo $theme['icon_bg']; ?> border border-slate-200/60 dark:border-slate-700 shadow-xs flex items-center justify-center shrink-0 font-black text-sm group-hover:scale-105 transition-transform duration-300">
                                            <span class="material-symbols-outlined text-2xl">domain</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Identificador y Badges -->
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <?php if (!empty($ent['is_matriz'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-teal-500/15 text-teal-700 dark:text-teal-300 border border-teal-500/30 shadow-xs">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-teal-500 animate-pulse"></span>
                                                    <span>IPS MATRIZ</span>
                                                </span>
                                            <?php endif; ?>

                                            <!-- Badge de Estado -->
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider <?php echo $esActiva ? 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700'; ?>" id="cardBadgeEstado_<?php echo $ent['id']; ?>">
                                                <span class="w-1.5 h-1.5 rounded-full <?php echo $esActiva ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'; ?>"></span>
                                                <span><?php echo $esActiva ? 'Activo' : 'Inactivo'; ?></span>
                                            </span>
                                        </div>

                                        <p class="text-[11px] font-mono font-bold text-slate-400 dark:text-slate-500 mt-0.5">
                                            NIT: <?php echo $nitCompleto; ?><?php echo !empty($ent['dv']) ? '-' . htmlspecialchars($ent['dv']) : ''; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Nombre de la IPS / Razón Social -->
                            <h3 class="font-outfit font-extrabold text-base text-slate-900 dark:text-white leading-snug line-clamp-2 min-h-[2.5rem] flex items-center mb-1 group-hover:text-primary dark:group-hover:text-tertiary transition-colors" title="<?php echo htmlspecialchars($ent['nombre']); ?>">
                                <?php echo htmlspecialchars($ent['nombre']); ?>
                            </h3>

                            <?php if (!empty($ent['contacto_nombre'])): ?>
                                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium flex items-center gap-1.5 mb-3 truncate" title="Contacto: <?php echo htmlspecialchars($ent['contacto_nombre']); ?>">
                                    <span class="material-symbols-outlined text-sm text-slate-400 shrink-0">person</span>
                                    <span class="truncate"><?php echo htmlspecialchars($ent['contacto_nombre']); ?></span>
                                </p>
                            <?php else: ?>
                                <div class="mb-3"></div>
                            <?php endif; ?>

                            <!-- Metadatos de Contacto y Ubicación -->
                            <div class="space-y-1.5 py-3 border-y border-slate-100 dark:border-slate-800/80 text-xs text-slate-600 dark:text-slate-300">
                                <!-- Ubicación -->
                                <div class="flex items-start gap-2 min-w-0">
                                    <span class="material-symbols-outlined text-sm text-slate-400 shrink-0 mt-0.5">location_on</span>
                                    <div class="min-w-0 truncate">
                                        <span class="font-bold text-slate-800 dark:text-slate-200"><?php echo htmlspecialchars($ent['ciudad'] ?: 'No especificada'); ?></span>
                                        <?php if (!empty($ent['direccion'])): ?>
                                            <span class="text-slate-400 text-[11px] font-medium block truncate" title="<?php echo htmlspecialchars($ent['direccion']); ?>">
                                                <?php echo htmlspecialchars($ent['direccion']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Correo Electrónico -->
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="material-symbols-outlined text-sm text-slate-400 shrink-0">mail</span>
                                    <?php if (!empty($ent['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($ent['email']); ?>" class="text-tertiary hover:underline text-xs font-semibold truncate" title="<?php echo htmlspecialchars($ent['email']); ?>">
                                            <?php echo htmlspecialchars($ent['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic text-[11px]">Sin correo registrado</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Teléfono -->
                                <?php if (!empty($ent['telefono'])): ?>
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="material-symbols-outlined text-sm text-slate-400 shrink-0">call</span>
                                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $ent['telefono'])); ?>" class="text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-tertiary text-xs font-semibold truncate">
                                            <?php echo htmlspecialchars($ent['telefono']); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Métricas Rápidas Interactivas (Pills) -->
                            <div class="grid grid-cols-2 gap-2 my-3.5">
                                <!-- Pill Médicos -->
                                <?php if (!empty($ent['is_matriz'])): ?>
                                    <div class="px-2.5 py-2 rounded-xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200/60 dark:border-teal-800/60 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-base text-teal-600 dark:text-teal-400 shrink-0">apartment</span>
                                        <div class="min-w-0">
                                            <span class="block text-[9px] uppercase font-bold text-teal-600/80 dark:text-teal-400/80">Sede</span>
                                            <span class="block text-xs font-black text-teal-900 dark:text-teal-200 truncate">Principal (HO)</span>
                                        </div>
                                    </div>
                                <?php elseif ($ent['total_medicos'] > 0): ?>
                                    <button type="button" onclick="verMedicosEntidad(<?php echo $ent['id']; ?>, '<?php echo addslashes($ent['nombre']); ?>')"
                                        class="px-2.5 py-2 rounded-xl bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:hover:bg-indigo-900/60 border border-indigo-200/60 dark:border-indigo-800/60 transition-all flex items-center gap-2 text-left cursor-pointer group/pill">
                                        <span class="material-symbols-outlined text-base text-indigo-600 dark:text-indigo-400 group-hover/pill:scale-110 transition-transform shrink-0">groups</span>
                                        <div class="min-w-0">
                                            <span class="block text-[9px] uppercase font-bold text-indigo-600/80 dark:text-indigo-400/80">Médicos</span>
                                            <span class="block text-xs font-black text-indigo-900 dark:text-indigo-200 truncate"><?php echo $ent['total_medicos']; ?> vinculados</span>
                                        </div>
                                    </button>
                                <?php else: ?>
                                    <div class="px-2.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-base text-slate-400 shrink-0">person_off</span>
                                        <div class="min-w-0">
                                            <span class="block text-[9px] uppercase font-bold text-slate-400">Médicos</span>
                                            <span class="block text-xs font-bold text-slate-500 dark:text-slate-400 truncate">0 vinculados</span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Pill Tarifas -->
                                <a href="tarifario.php?entidad_id=<?php echo $ent['id']; ?>"
                                    class="px-2.5 py-2 rounded-xl bg-cyan-50 hover:bg-cyan-100 dark:bg-cyan-950/40 dark:hover:bg-cyan-900/60 border border-cyan-200/60 dark:border-cyan-800/60 transition-all flex items-center gap-2 text-left cursor-pointer group/pill">
                                    <span class="material-symbols-outlined text-base text-cyan-600 dark:text-cyan-400 group-hover/pill:scale-110 transition-transform shrink-0">request_quote</span>
                                    <div class="min-w-0">
                                        <span class="block text-[9px] uppercase font-bold text-cyan-600/80 dark:text-cyan-400/80">Tarifario</span>
                                        <span class="block text-xs font-black text-cyan-900 dark:text-cyan-200 truncate">
                                            <?php echo (int)($ent['total_tarifas'] ?? 0); ?> <?php echo ((int)($ent['total_tarifas'] ?? 0) === 1) ? 'tarifa' : 'tarifas'; ?>
                                        </span>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <!-- Barra Inferior de Acciones -->
                        <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2 mt-1">
                            <div class="flex items-center gap-1.5">
                                <!-- Botón Ver Médicos -->
                                <button type="button" onclick="verMedicosEntidad(<?php echo $ent['id']; ?>, '<?php echo addslashes($ent['nombre']); ?>')"
                                    title="Ver lista de médicos adscritos"
                                    class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/60 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-xs font-bold transition-all flex items-center gap-1 cursor-pointer">
                                    <span class="material-symbols-outlined text-sm">groups</span>
                                    <span>Médicos</span>
                                </button>

                                <!-- Botón Ver Tarifas -->
                                <a href="tarifario.php?entidad_id=<?php echo $ent['id']; ?>"
                                    title="Ver Tarifario de esta entidad"
                                    class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-cyan-50 dark:hover:bg-cyan-950/60 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 text-xs font-bold transition-all flex items-center gap-1 cursor-pointer">
                                    <span class="material-symbols-outlined text-sm">request_quote</span>
                                    <span>Tarifas</span>
                                </a>
                            </div>

                            <div class="flex items-center gap-1">
                                <?php if ($canEditEntidad): ?>
                                    <!-- Editar Entidad -->
                                    <button type="button" onclick="abrirModalEditarEntidad(<?php echo $ent['id']; ?>)"
                                        title="Editar datos de la entidad"
                                        class="w-8 h-8 rounded-xl text-slate-500 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-cyan-50 dark:hover:bg-cyan-950/60 transition-colors flex items-center justify-center cursor-pointer">
                                        <span class="material-symbols-outlined text-base">edit</span>
                                    </button>

                                    <!-- Activar / Desactivar -->
                                    <?php if (!empty($ent['is_matriz'])): ?>
                                        <button type="button" disabled
                                            title="IPS Matriz Principal (Activa permanente)"
                                            class="w-8 h-8 rounded-xl text-teal-600 dark:text-teal-400 bg-teal-50/70 dark:bg-teal-950/40 opacity-80 cursor-not-allowed flex items-center justify-center">
                                            <span class="material-symbols-outlined text-base">verified</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="toggleEstadoEntidad(<?php echo $ent['id']; ?>, <?php echo $esActiva ? 0 : 1; ?>, '<?php echo addslashes($ent['nombre']); ?>')"
                                            title="<?php echo $esActiva ? 'Desactivar entidad' : 'Activar entidad'; ?>"
                                            id="cardBtnToggle_<?php echo $ent['id']; ?>"
                                            class="w-8 h-8 rounded-xl <?php echo $esActiva ? 'text-amber-500 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/60' : 'text-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/60'; ?> transition-colors flex items-center justify-center cursor-pointer">
                                            <span class="material-symbols-outlined text-base">
                                                <?php echo $esActiva ? 'visibility_off' : 'visibility'; ?>
                                            </span>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================================== -->
        <!-- VISTA 2: TABLA DE ENTIDADES (ALTERNATIVA)                      -->
        <!-- ============================================================== -->
        <div id="contenedorTabla" class="hidden bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tablaEntidades">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 bg-slate-50/75 dark:bg-slate-800/50 text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">
                            <th class="py-3.5 px-4">Entidad / Razón Social</th>
                            <th class="py-3.5 px-4">NIT</th>
                            <th class="py-3.5 px-4">Contacto & Correo</th>
                            <th class="py-3.5 px-4">Ubicación</th>
                            <th class="py-3.5 px-4 text-center">Médicos Vinculados</th>
                            <th class="py-3.5 px-4 text-center">Estado</th>
                            <th class="py-3.5 px-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs text-slate-600 dark:text-slate-300 font-medium">
                        <?php if (empty($entidadesList)): ?>
                            <tr id="rowSinDatos">
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <span class="material-symbols-outlined text-4xl text-slate-300 dark:text-slate-600">domain_disabled</span>
                                        <p class="font-bold text-sm">No hay entidades registradas en el sistema</p>
                                        <p class="text-[11px]">Haga clic en "+ Nueva Entidad" para registrar la primera IPS o empresa externa.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($entidadesList as $ent): ?>
                                <?php
                                    $nitCompleto = htmlspecialchars($ent['nit'] ?? '');
                                    $esActiva = ($ent['estado'] === 1);
                                ?>
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors entidad-row"
                                    data-nombre="<?php echo strtolower(htmlspecialchars($ent['nombre'])); ?>"
                                    data-nit="<?php echo strtolower(htmlspecialchars($nitCompleto)); ?>"
                                    data-email="<?php echo strtolower(htmlspecialchars($ent['email'] ?? '')); ?>"
                                    data-ciudad="<?php echo strtolower(htmlspecialchars($ent['ciudad'] ?? '')); ?>"
                                    data-estado="<?php echo $ent['estado']; ?>">
                                    
                                    <!-- Entidad -->
                                    <td class="py-3.5 px-4">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($ent['logo']) && file_exists(__DIR__ . '/' . $ent['logo'])): ?>
                                                <div class="w-11 h-11 rounded-xl bg-white p-1.5 flex items-center justify-center shrink-0 border border-slate-200 shadow-xs overflow-hidden">
                                                    <img src="<?php echo htmlspecialchars($ent['logo']); ?>" alt="Logo" class="w-full h-full object-contain" />
                                                </div>
                                            <?php else: ?>
                                                <div class="w-11 h-11 rounded-xl bg-white text-cyan-700 font-bold flex items-center justify-center shrink-0 border border-slate-200 shadow-xs">
                                                    <span class="material-symbols-outlined text-xl text-cyan-600">domain</span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0">
                                                <div class="font-bold text-slate-900 dark:text-white truncate flex items-center gap-2 flex-wrap">
                                                    <span><?php echo htmlspecialchars($ent['nombre']); ?></span>
                                                    <?php if (!empty($ent['is_matriz'])): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-teal-500/15 text-teal-700 dark:text-teal-300 border border-teal-500/30 shrink-0 shadow-xs">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-teal-500 animate-pulse"></span>
                                                            IPS MATRIZ
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($ent['contacto_nombre'])): ?>
                                                    <div class="flex items-center gap-2 mt-0.5">
                                                        <span class="text-[11px] text-slate-400 truncate">
                                                            <span class="material-symbols-outlined text-xs align-middle">person</span> <?php echo htmlspecialchars($ent['contacto_nombre']); ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- NIT -->
                                    <td class="py-3.5 px-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700">
                                            <?php echo $nitCompleto; ?>
                                        </span>
                                    </td>

                                    <!-- Contacto & Correo -->
                                    <td class="py-3.5 px-4">
                                        <div class="space-y-0.5">
                                            <?php if (!empty($ent['email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($ent['email']); ?>" class="text-tertiary hover:underline text-xs font-semibold flex items-center gap-1 truncate">
                                                    <span class="material-symbols-outlined text-xs">mail</span>
                                                    <span><?php echo htmlspecialchars($ent['email']); ?></span>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-slate-400 italic text-[11px] flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-xs text-slate-300 dark:text-slate-600">mail</span>
                                                    Sin correo
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($ent['telefono'])): ?>
                                                <div class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-xs">call</span>
                                                    <span><?php echo htmlspecialchars($ent['telefono']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Ubicación -->
                                    <td class="py-3.5 px-4">
                                        <div>
                                            <span class="font-semibold text-slate-800 dark:text-slate-200">
                                                <?php echo htmlspecialchars($ent['ciudad'] ?: 'No especificada'); ?>
                                            </span>
                                            <?php if (!empty($ent['direccion'])): ?>
                                                <p class="text-[11px] text-slate-400 truncate max-w-[200px]" title="<?php echo htmlspecialchars($ent['direccion']); ?>">
                                                    <?php echo htmlspecialchars($ent['direccion']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Médicos Vinculados -->
                                    <td class="py-3.5 px-4 text-center">
                                        <?php if (!empty($ent['is_matriz'])): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800">
                                                <span class="material-symbols-outlined text-sm">apartment</span>
                                                <span>Sede Principal (HO)</span>
                                            </span>
                                        <?php elseif ($ent['total_medicos'] > 0): ?>
                                            <button type="button" onclick="verMedicosEntidad(<?php echo $ent['id']; ?>, '<?php echo addslashes($ent['nombre']); ?>')"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-100 transition-colors cursor-pointer">
                                                <span class="material-symbols-outlined text-sm">groups</span>
                                                <span><?php echo $ent['total_medicos']; ?> <?php echo ($ent['total_medicos'] === 1) ? 'médico' : 'médicos'; ?></span>
                                            </button>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold text-slate-400 bg-slate-100 dark:bg-slate-800">
                                                0 médicos
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Estado -->
                                    <td class="py-3.5 px-4 text-center" id="tdEstado_<?php echo $ent['id']; ?>">
                                        <?php if ($esActiva): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                                <span>Activo</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                <span>Inactivo</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-3.5 px-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <!-- Ver Tarifario Propio -->
                                            <a href="tarifario.php?entidad_id=<?php echo $ent['id']; ?>"
                                                title="Ver Catálogo de Tarifas de esta Entidad (<?php echo (int)($ent['total_tarifas'] ?? 0); ?> tarifas)"
                                                class="w-8 h-8 rounded-lg text-slate-500 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-950/60 transition-colors flex items-center justify-center cursor-pointer relative">
                                                <span class="material-symbols-outlined text-base">request_quote</span>
                                                <?php if ((int)($ent['total_tarifas'] ?? 0) > 0): ?>
                                                    <span class="absolute -top-1 -right-1 min-w-[14px] h-[14px] px-1 bg-tertiary text-white rounded-full text-[8px] font-black flex items-center justify-center">
                                                        <?php echo (int)$ent['total_tarifas']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </a>

                                            <!-- Ver Médicos -->
                                            <button type="button" onclick="verMedicosEntidad(<?php echo $ent['id']; ?>, '<?php echo addslashes($ent['nombre']); ?>')"
                                                title="Ver médicos adscritos a esta entidad"
                                                class="w-8 h-8 rounded-lg text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/60 transition-colors flex items-center justify-center cursor-pointer">
                                                <span class="material-symbols-outlined text-base">groups</span>
                                            </button>

                                            <?php if ($canEditEntidad): ?>
                                                <!-- Editar Entidad -->
                                                <button type="button" onclick="abrirModalEditarEntidad(<?php echo $ent['id']; ?>)"
                                                    title="Editar datos de la entidad"
                                                    class="w-8 h-8 rounded-lg text-slate-500 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-cyan-50 dark:hover:bg-cyan-950/60 transition-colors flex items-center justify-center cursor-pointer">
                                                    <span class="material-symbols-outlined text-base">edit</span>
                                                </button>

                                                <!-- Activar / Desactivar (Toggle) -->
                                                <?php if (!empty($ent['is_matriz'])): ?>
                                                    <button type="button" disabled
                                                        title="IPS Matriz Principal (Activa permanente)"
                                                        class="w-8 h-8 rounded-lg text-teal-600 dark:text-teal-400 bg-teal-50/70 dark:bg-teal-950/40 opacity-80 cursor-not-allowed flex items-center justify-center">
                                                        <span class="material-symbols-outlined text-base">verified</span>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" onclick="toggleEstadoEntidad(<?php echo $ent['id']; ?>, <?php echo $esActiva ? 0 : 1; ?>, '<?php echo addslashes($ent['nombre']); ?>')"
                                                        title="<?php echo $esActiva ? 'Desactivar entidad' : 'Activar entidad'; ?>"
                                                        id="btnToggle_<?php echo $ent['id']; ?>"
                                                        class="w-8 h-8 rounded-lg <?php echo $esActiva ? 'text-amber-500 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/60' : 'text-emerald-500 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/60'; ?> transition-colors flex items-center justify-center cursor-pointer">
                                                        <span class="material-symbols-outlined text-base">
                                                            <?php echo $esActiva ? 'visibility_off' : 'visibility'; ?>
                                                        </span>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mensaje cuando no hay coincidencias en ninguna vista -->
        <div id="noCoincidencias" class="hidden py-16 text-center text-slate-400 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm mb-8">
            <span class="material-symbols-outlined text-4xl mb-2 text-slate-300 dark:text-slate-600">search_off</span>
            <p class="font-bold text-sm text-slate-700 dark:text-slate-200">No se encontraron entidades coincidentes con la búsqueda</p>
            <p class="text-xs text-slate-400 mt-0.5">Intente con otro término o restablezca los filtros.</p>
        </div>

    </main>

    <!-- ---------------------------------------------------------------------- -->
    <!-- MODAL: CREAR / EDITAR ENTIDAD -->
    <!-- ---------------------------------------------------------------------- -->
    <div id="modalEntidad" class="fixed inset-0 z-50 hidden overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden transform transition-all">
            
            <!-- Encabezado Modal -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30">
                <div class="flex items-center gap-3">
                    <div id="modalIconContainer" class="w-10 h-10 rounded-xl bg-cyan-100 dark:bg-cyan-950/80 text-cyan-600 dark:text-cyan-400 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl" id="modalIcon">domain_add</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white font-outfit" id="modalTitulo">
                            Nueva Entidad / IPS
                        </h3>
                        <p class="text-xs text-slate-400 font-medium" id="modalSubtitulo">
                            Complete los datos de la institución médica o empresa externa
                        </p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEntidad()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white w-8 h-8 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <!-- Formulario -->
            <form id="formEntidad" onsubmit="guardarEntidad(event)" enctype="multipart/form-data" class="p-6 space-y-4">
                <input type="hidden" id="entidad_id" name="id" value="" />
                <input type="hidden" id="action_type" name="action" value="crear_entidad" />
                <input type="hidden" id="eliminar_logo" name="eliminar_logo" value="0" />

                <!-- Logo de la Entidad / IPS (Opcional) -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2">
                        Logo de la Entidad / IPS <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                    </label>
                    <input type="file" id="entidad_logo_file" name="logo" accept="image/png, image/jpeg, image/webp, image/svg+xml" class="hidden" onchange="previewLogoEntidad(this)" />

                    <div class="flex items-center gap-3.5">
                        <!-- Preview Box (Fondo blanco siempre para contraste óptimo) -->
                        <div id="logoPreviewContainer" class="w-16 h-16 rounded-xl border-2 border-dashed border-slate-300 bg-white flex items-center justify-center overflow-hidden shrink-0 shadow-xs transition-all">
                            <span id="logoPreviewIcon" class="material-symbols-outlined text-2xl text-slate-400">add_photo_alternate</span>
                            <img id="logoPreviewImg" src="" alt="Vista previa logo" class="w-full h-full object-contain hidden p-1" />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="document.getElementById('entidad_logo_file').click()"
                                    class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-900 dark:bg-slate-700 hover:bg-slate-800 text-white transition-all flex items-center gap-1.5 cursor-pointer shadow-xs">
                                    <span class="material-symbols-outlined text-sm">upload</span>
                                    <span>Subir Logo</span>
                                </button>
                                <button type="button" id="btnQuitarLogo" onclick="quitarLogoEntidad()"
                                    class="px-2.5 py-1.5 rounded-xl text-xs font-semibold text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors hidden cursor-pointer">
                                    Quitar
                                </button>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">Formatos: PNG, JPG, WEBP o SVG (máx. 5 MB).</p>
                        </div>
                    </div>
                </div>

                <!-- Color Distintivo de la Entidad -->
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                            Color Distintivo de la Entidad
                        </label>
                        <span id="lblColorSeleccionado" class="text-[11px] font-bold text-slate-700 dark:text-slate-300 capitalize">Azul</span>
                    </div>
                    <input type="hidden" id="entidad_color_tema" name="color_tema" value="blue" />
                    <div class="grid grid-cols-5 sm:grid-cols-10 gap-2 p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80" id="contenedorPaletaColores">
                        <!-- Swatches de colores generados dinámicamente -->
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1">Este color ambienta automáticamente los módulos, botones, filtros y reportes cuando se selecciona esta entidad.</p>
                </div>

                <!-- Nombre IPS / Razón Social -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                        Nombre IPS / Razón Social <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="entidad_nombre" name="nombre" required placeholder="Ej: Clínica Médica del Norte S.A.S."
                        class="w-full px-3.5 py-2.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                </div>

                <!-- NIT (Sin puntos ni guion) -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                        NIT (Sin puntos ni guion) <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="entidad_nit" name="nit" required placeholder="Ej: 900765973"
                        class="w-full px-3.5 py-2.5 text-xs font-mono font-bold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                </div>

                <!-- Fila: Correo y Teléfono -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                            Correo Electrónico (Facturación / Contacto) <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                        </label>
                        <input type="email" id="entidad_email" name="email" placeholder="facturacion@entidad.com (opcional)"
                            class="w-full px-3.5 py-2.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                            Teléfono / Celular <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                        </label>
                        <input type="text" id="entidad_telefono" name="telefono" placeholder="Ej: (604) 444-0000 / 300 1234567 (opcional)"
                            class="w-full px-3.5 py-2.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                    </div>
                </div>

                <!-- Fila: Ciudad, Dirección y Contacto -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                            Ciudad / Municipio <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                        </label>
                        <input type="text" id="entidad_ciudad" name="ciudad" placeholder="Ej: Medellín (opcional)"
                            class="w-full px-3.5 py-2.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                            Dirección <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                        </label>
                        <input type="text" id="entidad_direccion" name="direccion" placeholder="Ej: Cra 43A # 1-50 Torre Médica Of. 302 (opcional)"
                            class="w-full px-3.5 py-2.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                    </div>
                </div>

                <!-- Representante o Persona de Contacto -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                        Persona de Contacto / Representante Legal <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                    </label>
                    <input type="text" id="entidad_contacto" name="contacto_nombre" placeholder="Ej: Dr. Carlos Pérez - Director Médico (opcional)"
                        class="w-full px-3.5 py-2.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                </div>



                <!-- Observaciones -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                        Observaciones / Notas Contractuales <span class="text-[10px] font-semibold text-slate-400 lowercase">(opcional)</span>
                    </label>
                    <textarea id="entidad_observaciones" name="observaciones" rows="2" placeholder="Notas sobre el convenio, retenciones o particularidades... (opcional)"
                        class="w-full px-3.5 py-2 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all resize-none"></textarea>
                </div>

                <!-- Selector Estado (solo en edición) -->
                <div id="divEstadoEdicion" class="hidden pt-2 border-t border-slate-100 dark:border-slate-800">
                    <label class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                        Estado de la Entidad
                    </label>
                    <select id="entidad_estado" name="estado"
                        class="w-full px-3.5 py-2 text-xs font-bold rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none">
                        <option value="1">Activa (Habilitada para asociar a médicos)</option>
                        <option value="0">Inactiva (Suspendida temporalmente)</option>
                    </select>
                </div>

                <!-- Botones Acciones -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalEntidad()"
                        class="px-4 py-2 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarEntidad"
                        class="px-5 py-2.5 text-xs font-bold rounded-xl bg-tertiary hover:bg-teal-600 text-white shadow-md shadow-tertiary/20 hover:shadow-lg hover:shadow-tertiary/30 transition-all flex items-center gap-2 cursor-pointer">
                        <span class="material-symbols-outlined text-base">save</span>
                        <span id="btnGuardarText">Registrar Entidad</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ---------------------------------------------------------------------- -->
    <!-- MODAL: MÉDICOS VINCULADOS A LA ENTIDAD -->
    <!-- ---------------------------------------------------------------------- -->
    <div id="modalMedicos" class="fixed inset-0 z-50 hidden overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="relative w-full max-w-xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden transform transition-all">
            
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950/80 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl">groups</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white font-outfit" id="modalMedicosTitulo">
                            Médicos Vinculados
                        </h3>
                        <p class="text-xs text-slate-400 font-medium" id="modalMedicosSubtitulo">
                            Profesionales adscritos a esta entidad
                        </p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalMedicos()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white w-8 h-8 rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="p-6">
                <div id="medicosListContainer" class="space-y-2 max-h-80 overflow-y-auto custom-scrollbar">
                    <!-- Dinámico -->
                </div>
                
                <div class="mt-6 flex items-center justify-between pt-4 border-t border-slate-100 dark:border-slate-800">
                    <a href="medicos.php" class="text-xs font-bold text-tertiary hover:underline flex items-center gap-1">
                        <span>Ir al directorio general de médicos</span>
                        <span class="material-symbols-outlined text-xs">arrow_forward</span>
                    </a>
                    <button type="button" onclick="cerrarModalMedicos()" class="px-4 py-2 text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-xl hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ---------------------------------------------------------------------- -->
    <!-- JAVASCRIPT: LÓGICA DEL MÓDULO -->
    <!-- ---------------------------------------------------------------------- -->
    <script>
        function previewLogoEntidad(input) {
            const file = input.files && input.files[0];
            if (file) {
                if (file.size > 5 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Archivo muy pesado',
                        text: 'El logo no debe superar los 5 MB.',
                        confirmButtonColor: '#00c1be'
                    });
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById('logoPreviewImg');
                    const icon = document.getElementById('logoPreviewIcon');
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                    icon.classList.add('hidden');
                    document.getElementById('btnQuitarLogo').classList.remove('hidden');
                    document.getElementById('eliminar_logo').value = '0';
                };
                reader.readAsDataURL(file);
            }
        }

        function quitarLogoEntidad() {
            const input = document.getElementById('entidad_logo_file');
            if (input) input.value = '';
            const img = document.getElementById('logoPreviewImg');
            const icon = document.getElementById('logoPreviewIcon');
            img.src = '';
            img.classList.add('hidden');
            icon.classList.remove('hidden');
            document.getElementById('btnQuitarLogo').classList.add('hidden');
            document.getElementById('eliminar_logo').value = '1';
        }

        function resetearLogoPreview(logoUrl = null) {
            const input = document.getElementById('entidad_logo_file');
            if (input) input.value = '';
            document.getElementById('eliminar_logo').value = '0';
            const img = document.getElementById('logoPreviewImg');
            const icon = document.getElementById('logoPreviewIcon');
            const btnQuitar = document.getElementById('btnQuitarLogo');

            if (logoUrl) {
                img.src = logoUrl;
                img.classList.remove('hidden');
                icon.classList.add('hidden');
                btnQuitar.classList.remove('hidden');
            } else {
                img.src = '';
                img.classList.add('hidden');
                icon.classList.remove('hidden');
                btnQuitar.classList.add('hidden');
            }
        }

        const LISTA_COLORES_TEMA = [
            { key: 'sky',     nombre: 'Celeste Pastel',     hex: '#93c5fd', ring: 'ring-sky-400' },
            { key: 'teal',    nombre: 'Agua / Menta Suave',  hex: '#5eead4', ring: 'ring-teal-400' },
            { key: 'indigo',  nombre: 'Lavanda Suave',      hex: '#a5b4fc', ring: 'ring-indigo-400' },
            { key: 'purple',  nombre: 'Lila Pastel',        hex: '#c4b5fd', ring: 'ring-purple-400' },
            { key: 'rose',    nombre: 'Rosa Empolvado',     hex: '#f472b6', ring: 'ring-pink-400' },
            { key: 'pink',    nombre: 'Rosa Pastel',        hex: '#fbcfe8', ring: 'ring-rose-300' },
            { key: 'amber',   nombre: 'Vainilla Pastel',    hex: '#fde68a', ring: 'ring-amber-300' },
            { key: 'emerald', nombre: 'Salvia Pastel',      hex: '#a7f3d0', ring: 'ring-emerald-300' },
            { key: 'orange',  nombre: 'Melocotón Pastel',   hex: '#fed7aa', ring: 'ring-orange-300' },
            { key: 'slate',   nombre: 'Gris Perla Suave',   hex: '#cbd5e1', ring: 'ring-slate-400' }
        ];

        function renderizarSelectorColores(colorSeleccionado = 'sky') {
            const cont = document.getElementById('contenedorPaletaColores');
            if (!cont) return;
            const cleanColor = (colorSeleccionado || 'sky').toLowerCase().trim();
            document.getElementById('entidad_color_tema').value = cleanColor;
            
            const itemSel = LISTA_COLORES_TEMA.find(c => c.key === cleanColor) || LISTA_COLORES_TEMA[0];
            const lbl = document.getElementById('lblColorSeleccionado');
            if (lbl) lbl.textContent = itemSel.nombre;

            cont.innerHTML = '';
            LISTA_COLORES_TEMA.forEach(c => {
                const isSel = (c.key === cleanColor);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.onclick = (e) => { e.preventDefault(); seleccionarColorTema(c.key); };
                btn.title = c.nombre;
                btn.style.backgroundColor = c.hex;
                btn.className = `w-full h-8 rounded-xl transition-all duration-200 flex items-center justify-center cursor-pointer hover:scale-110 active:scale-95 shadow-xs border border-black/5 dark:border-white/10 ${isSel ? 'ring-2 ring-offset-2 ring-slate-800 dark:ring-offset-slate-900 ' + c.ring + ' scale-105 shadow-sm' : 'opacity-80 hover:opacity-100'}`;
                btn.innerHTML = isSel ? `<span class="material-symbols-outlined text-slate-800 text-sm font-black">check</span>` : '';
                cont.appendChild(btn);
            });
        }

        function seleccionarColorTema(colorKey) {
            renderizarSelectorColores(colorKey);
        }

        function abrirModalCrearEntidad() {
            document.getElementById('formEntidad').reset();
            resetearLogoPreview(null);
            renderizarSelectorColores('blue');
            document.getElementById('entidad_id').value = '';
            document.getElementById('action_type').value = 'crear_entidad';
            document.getElementById('modalTitulo').textContent = 'Nueva Entidad / IPS';
            document.getElementById('modalSubtitulo').textContent = 'Complete los datos de la institución médica o empresa externa';
            document.getElementById('modalIcon').textContent = 'domain_add';
            document.getElementById('btnGuardarText').textContent = 'Registrar Entidad';
            document.getElementById('divEstadoEdicion').classList.add('hidden');
            document.getElementById('modalEntidad').classList.remove('hidden');
        }

        function abrirModalEditarEntidad(id) {
            Swal.fire({
                title: 'Cargando datos...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            fetch(`maestro_entidades.php?action=obtener_entidad&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    Swal.close();
                    if (res.success && res.data) {
                        const d = res.data;
                        document.getElementById('entidad_id').value = d.id;
                        document.getElementById('action_type').value = 'editar_entidad';
                        document.getElementById('entidad_nombre').value = d.nombre || '';
                        document.getElementById('entidad_nit').value = d.nit || '';
                        document.getElementById('entidad_email').value = d.email || '';
                        document.getElementById('entidad_telefono').value = d.telefono || '';
                        document.getElementById('entidad_ciudad').value = d.ciudad || '';
                        document.getElementById('entidad_direccion').value = d.direccion || '';
                        document.getElementById('entidad_contacto').value = d.contacto_nombre || '';
                        document.getElementById('entidad_observaciones').value = d.observaciones || '';
                        document.getElementById('entidad_estado').value = d.estado !== undefined ? d.estado : '1';

                        resetearLogoPreview(d.logo || null);
                        renderizarSelectorColores(d.color_tema || 'blue');

                        document.getElementById('modalTitulo').textContent = 'Editar Entidad / IPS';
                        document.getElementById('modalSubtitulo').textContent = `Actualizando: ${d.nombre}`;
                        document.getElementById('modalIcon').textContent = 'edit_note';
                        document.getElementById('btnGuardarText').textContent = 'Guardar Cambios';
                        document.getElementById('divEstadoEdicion').classList.remove('hidden');

                        document.getElementById('modalEntidad').classList.remove('hidden');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: res.message || 'No se pudo cargar la entidad.',
                            confirmButtonColor: '#00c1be'
                        });
                    }
                })
                .catch(err => {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión',
                        text: 'No fue posible comunicarse con el servidor.',
                        confirmButtonColor: '#00c1be'
                    });
                });
        }

        function cerrarModalEntidad() {
            document.getElementById('modalEntidad').classList.add('hidden');
        }

        function guardarEntidad(e) {
            e.preventDefault();
            const form = document.getElementById('formEntidad');
            const formData = new FormData(form);

            const btn = document.getElementById('btnGuardarEntidad');
            btn.disabled = true;

            fetch('maestro_entidades.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                if (res.success) {
                    cerrarModalEntidad();
                    Swal.fire({
                        icon: 'success',
                        title: '¡Operación Exitosa!',
                        text: res.message,
                        confirmButtonColor: '#00c1be'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atención',
                        text: res.message || 'Ocurrió un error al procesar la solicitud.',
                        confirmButtonColor: '#00c1be'
                    });
                }
            })
            .catch(err => {
                btn.disabled = false;
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Error de comunicación con el servidor.',
                    confirmButtonColor: '#00c1be'
                });
            });
        }

        function toggleEstadoEntidad(id, nuevoEstado, nombre) {
            const accionTxt = (nuevoEstado === 1) ? 'ACTIVAR' : 'DESACTIVAR';
            const accionColor = (nuevoEstado === 1) ? '#10b981' : '#f59e0b';

            Swal.fire({
                title: `¿${accionTxt} Entidad?`,
                text: `¿Desea cambiar el estado de "${nombre}" a ${accionTxt.toLowerCase()}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: accionColor,
                cancelButtonColor: '#64748b',
                confirmButtonText: `Sí, ${accionTxt.toLowerCase()}`,
                cancelButtonText: 'Cancelar'
            }).then((res) => {
                if (res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'toggle_estado');
                    fd.append('id', id);
                    fd.append('estado', nuevoEstado);

                    fetch('maestro_entidades.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Estado Actualizado',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'No se pudo actualizar el estado.',
                                confirmButtonColor: '#00c1be'
                            });
                        }
                    })
                    .catch(() => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de red',
                            text: 'No se pudo conectar con el servidor.',
                            confirmButtonColor: '#00c1be'
                        });
                    });
                }
            });
        }

        function verMedicosEntidad(id, nombre) {
            document.getElementById('modalMedicosTitulo').textContent = `Médicos en ${nombre}`;
            document.getElementById('modalMedicosSubtitulo').textContent = 'Cargando profesionales adscritos...';
            const cont = document.getElementById('medicosListContainer');
            cont.innerHTML = '<div class="py-8 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin text-xl"></i></div>';
            document.getElementById('modalMedicos').classList.remove('hidden');

            fetch(`maestro_entidades.php?action=obtener_medicos_entidad&id=${id}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.medicos) {
                        document.getElementById('modalMedicosSubtitulo').textContent = `Total: ${res.medicos.length} médico(s) vinculado(s)`;
                        if (res.medicos.length === 0) {
                            cont.innerHTML = `
                                <div class="py-8 text-center text-slate-400">
                                    <span class="material-symbols-outlined text-3xl mb-1">person_off</span>
                                    <p class="text-xs font-bold">No hay médicos vinculados a esta entidad actualmente.</p>
                                    <p class="text-[11px]">Puede asociar médicos desde el módulo de Gestión de Médicos.</p>
                                </div>
                            `;
                            return;
                        }

                        let html = '';
                        res.medicos.forEach(m => {
                            html += `
                                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 flex items-center justify-center font-bold text-xs shrink-0">
                                            ${m.nombre.substring(0, 2).toUpperCase()}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-slate-900 dark:text-white text-xs truncate">${m.nombre}</p>
                                            <p class="text-[11px] text-slate-400 font-mono">CC: ${m.cedula || 'N/A'} ${m.proteo ? `| Proteo: ${m.proteo}` : ''}</p>
                                        </div>
                                    </div>
                                    <div class="shrink-0 flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-extrabold uppercase ${m.activo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300' : 'bg-slate-100 text-slate-500'}">
                                            ${m.activo ? 'Activo' : 'Inactivo'}
                                        </span>
                                    </div>
                                </div>
                            `;
                        });
                        cont.innerHTML = html;
                    } else {
                        cont.innerHTML = '<p class="text-xs text-rose-500 py-4 text-center">Error al consultar los médicos.</p>';
                    }
                })
                .catch(() => {
                    cont.innerHTML = '<p class="text-xs text-rose-500 py-4 text-center">Error de conexión al obtener médicos.</p>';
                });
        }

        function cerrarModalMedicos() {
            document.getElementById('modalMedicos').classList.add('hidden');
        }

        // Alternar entre Vista de Tarjetas y Vista de Tabla
        function cambiarVista(vista) {
            const contCards = document.getElementById('contenedorCards');
            const contTabla = document.getElementById('contenedorTabla');
            const btnCards = document.getElementById('btnVistaCards');
            const btnTabla = document.getElementById('btnVistaTabla');

            if (!contCards || !contTabla) return;

            if (vista === 'cards') {
                contCards.classList.remove('hidden');
                contTabla.classList.add('hidden');
                
                if (btnCards) {
                    btnCards.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-white dark:bg-slate-700 text-primary dark:text-white shadow-xs cursor-pointer';
                }
                if (btnTabla) {
                    btnTabla.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white cursor-pointer';
                }
                try { localStorage.setItem('vista_entidades_liho', 'cards'); } catch(e){}
            } else {
                contCards.classList.add('hidden');
                contTabla.classList.remove('hidden');

                if (btnTabla) {
                    btnTabla.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-white dark:bg-slate-700 text-primary dark:text-white shadow-xs cursor-pointer';
                }
                if (btnCards) {
                    btnCards.className = 'px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white cursor-pointer';
                }
                try { localStorage.setItem('vista_entidades_liho', 'tabla'); } catch(e){}
            }
        }

        // Inicializar vista recordada por el usuario (cards por defecto)
        document.addEventListener('DOMContentLoaded', function() {
            let saved = 'cards';
            try {
                saved = localStorage.getItem('vista_entidades_liho') || 'cards';
            } catch(e){}
            cambiarVista(saved);
        });

        function filtrarTabla() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const estFiltro = document.getElementById('filtroEstado').value;

            // 1. Filtrar filas de la tabla
            const rows = document.querySelectorAll('.entidad-row');
            let visiblesRows = 0;
            rows.forEach(r => {
                const nom = r.getAttribute('data-nombre') || '';
                const nit = r.getAttribute('data-nit') || '';
                const em  = r.getAttribute('data-email') || '';
                const cd  = r.getAttribute('data-ciudad') || '';
                const est = r.getAttribute('data-estado') || '';

                const matchesQuery = !query || nom.includes(query) || nit.includes(query) || em.includes(query) || cd.includes(query);
                const matchesEst   = !estFiltro || est === estFiltro;

                if (matchesQuery && matchesEst) {
                    r.style.display = '';
                    visiblesRows++;
                } else {
                    r.style.display = 'none';
                }
            });

            // 2. Filtrar cards dinámicas
            const cards = document.querySelectorAll('.entidad-card');
            let visiblesCards = 0;
            cards.forEach(c => {
                const nom = c.getAttribute('data-nombre') || '';
                const nit = c.getAttribute('data-nit') || '';
                const em  = c.getAttribute('data-email') || '';
                const cd  = c.getAttribute('data-ciudad') || '';
                const est = c.getAttribute('data-estado') || '';

                const matchesQuery = !query || nom.includes(query) || nit.includes(query) || em.includes(query) || cd.includes(query);
                const matchesEst   = !estFiltro || est === estFiltro;

                if (matchesQuery && matchesEst) {
                    c.style.display = '';
                    visiblesCards++;
                } else {
                    c.style.display = 'none';
                }
            });

            // 3. Controlar estado sin resultados
            const noCoinc = document.getElementById('noCoincidencias');
            if (noCoinc) {
                const totalItems = Math.max(rows.length, cards.length);
                const maxVisibles = Math.max(visiblesRows, visiblesCards);
                if (maxVisibles === 0 && totalItems > 0) {
                    noCoinc.classList.remove('hidden');
                } else {
                    noCoinc.classList.add('hidden');
                }
            }
        }

        function limpiarFiltros() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filtroEstado').value = '';
            filtrarTabla();
        }
    </script>
</body>
</html>
