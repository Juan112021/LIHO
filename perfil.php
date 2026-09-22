<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');

$userId    = $_SESSION['user_id'] ?? null;
$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');

$msgSuccess = $_SESSION['flash_msg_success'] ?? '';
$msgError   = $_SESSION['flash_msg_error'] ?? '';
unset($_SESSION['flash_msg_success'], $_SESSION['flash_msg_error']);

// Consultar datos actualizados del usuario y médico desde SQL Server
$usuarioInfo = array();
if ($userId && isset($con) && $con !== false) {
    $sqlU = "SELECT u.id, u.email, u.rol_id, u.estado, u.fecha_creacion, u.ultima_sesion, u.fecha_nacimiento, u.foto_perfil, u.nombre_completo, u.cedula AS u_cedula,
                    m.cedula AS m_cedula, m.usuario_proteo, m.pnom, m.snom, m.pape, m.sape
             FROM usuarios u
             LEFT JOIN medicos m ON u.id = m.usuario_id
             WHERE u.id = ?";
    $stmtU = sqlsrv_query($con, $sqlU, array($userId));
    if ($stmtU !== false && $rowU = sqlsrv_fetch_array($stmtU, SQLSRV_FETCH_ASSOC)) {
        $usuarioInfo = $rowU;
        $userEmail   = $rowU['email'] ?? $userEmail;
    }
}

$fechaNacimiento = $usuarioInfo['fecha_nacimiento'] ?? '';
$fotoPerfil      = $usuarioInfo['foto_perfil'] ?? '';
$cedulaDisplay   = !empty($usuarioInfo['m_cedula']) ? $usuarioInfo['m_cedula'] : (!empty($usuarioInfo['u_cedula']) ? $usuarioInfo['u_cedula'] : 'No registrada');

$userNombreDisplay = $usuarioInfo['nombre_completo'] ?? '';
if (empty($userNombreDisplay)) {
    $userNombreDisplay = trim(($usuarioInfo['pnom'] ?? '') . ' ' . ($usuarioInfo['snom'] ?? '') . ' ' . ($usuarioInfo['pape'] ?? '') . ' ' . ($usuarioInfo['sape'] ?? ''));
}
if (empty($userNombreDisplay)) {
    $userNombreDisplay = $userName;
}

// Procesar Actualización de Perfil (Nombre, Email, Fecha Nacimiento, Foto)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'actualizar_perfil') {
        $nuevoNombre   = trim($_POST['nombre_completo'] ?? '');
        $nuevoEmail    = trim($_POST['email'] ?? '');
        $nuevaFechaNac = trim($_POST['fecha_nacimiento'] ?? '');

        if (empty($nuevoNombre)) {
            $_SESSION['flash_msg_error'] = "Por favor ingrese su nombre completo.";
            header("Location: perfil.php");
            exit;
        } elseif (empty($nuevoEmail) || !filter_var($nuevoEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_msg_error'] = "Por favor ingrese un correo electrónico válido.";
            header("Location: perfil.php");
            exit;
        } else {
            if (isset($con) && $con !== false) {
                // Verificar que el correo no esté ocupado por otro usuario
                $sqlCheck = "SELECT TOP 1 id FROM usuarios WHERE LOWER(email) = LOWER(?) AND id <> ?";
                $stmtCheck = sqlsrv_query($con, $sqlCheck, array($nuevoEmail, $userId));

                if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                    $_SESSION['flash_msg_error'] = "El correo electrónico ingresado ya se encuentra registrado por otro usuario.";
                    header("Location: perfil.php");
                    exit;
                } else {
                    
                    // Manejo de la subida de foto de perfil
                    $nuevaFotoRuta = $fotoPerfil;
                    $uploadError = '';
                    if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
                        $fileTmpPath = $_FILES['foto_perfil']['tmp_name'];
                        $fileName = $_FILES['foto_perfil']['name'];
                        $fileSize = $_FILES['foto_perfil']['size'];
                        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                        $allowedExtensions = array('jpg', 'jpeg', 'png', 'webp');
                        if (!in_array($fileExtension, $allowedExtensions)) {
                            $uploadError = "Formato de imagen no válido. Formatos permitidos: JPG, JPEG, PNG, WEBP.";
                        } elseif ($fileSize > 5 * 1024 * 1024) { // Max 5MB
                            $uploadError = "La imagen supera el tamaño máximo permitido de 5 MB.";
                        } else {
                            $userFolderRel = 'uploads/usuarios/usuario_' . $userId . '/';
                            $uploadDir = __DIR__ . '/' . $userFolderRel;
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }

                            $newFileName = 'foto_perfil_' . time() . '.' . $fileExtension;
                            $destPath = $uploadDir . $newFileName;

                            if (move_uploaded_file($fileTmpPath, $destPath)) {
                                if (!empty($fotoPerfil) && file_exists(__DIR__ . '/' . $fotoPerfil) && strpos($fotoPerfil, $userFolderRel) !== false) {
                                    @unlink(__DIR__ . '/' . $fotoPerfil);
                                }
                                $nuevaFotoRuta = $userFolderRel . $newFileName;
                            } else {
                                $uploadError = "Error al guardar el archivo de foto en el directorio del usuario.";
                            }
                        }
                    }

                    if (!empty($uploadError)) {
                        $_SESSION['flash_msg_error'] = $uploadError;
                        header("Location: perfil.php");
                        exit;
                    }

                    $cambios = array();
                    $oldNombre = $userNombreDisplay;
                    $oldEmail = $usuarioInfo['email'] ?? '';
                    $oldFechaNac = $usuarioInfo['fecha_nacimiento'] ?? '';
                    $oldFoto = $usuarioInfo['foto_perfil'] ?? '';

                    if ($oldNombre !== $nuevoNombre) {
                        $cambios['Nombre Registrado'] = "Modificado de '$oldNombre' a '$nuevoNombre'";
                    }
                    if (strtolower($oldEmail) !== strtolower($nuevoEmail)) {
                        $cambios['Correo Electrónico'] = "Modificado de '" . ($oldEmail ?: 'No asignado') . "' a '$nuevoEmail'";
                    }
                    if ($oldFechaNac !== $nuevaFechaNac) {
                        $cambios['Fecha de Nacimiento'] = "Modificada a '" . (!empty($nuevaFechaNac) ? date('d/m/Y', strtotime($nuevaFechaNac)) : 'No especificada') . "'";
                    }
                    if ($nuevaFotoRuta !== $oldFoto) {
                        $cambios['Foto de Perfil'] = "Nueva foto de perfil cargada y guardada en el directorio del usuario.";
                    }

                    $sqlUpdate = "UPDATE usuarios SET email = ?, fecha_nacimiento = ?, foto_perfil = ?, nombre_completo = ? WHERE id = ?";
                    $stmtUp = sqlsrv_query($con, $sqlUpdate, array($nuevoEmail, $nuevaFechaNac, $nuevaFotoRuta, $nuevoNombre, $userId));
                    
                    // Actualizar en tabla medicos si existe
                    sqlsrv_query($con, "UPDATE medicos SET fecha_nacimiento = ?, pnom = ? WHERE usuario_id = ?", array($nuevaFechaNac, $nuevoNombre, $userId));

                    if ($stmtUp !== false) {
                        $_SESSION['user_name'] = $nuevoNombre;
                        $_SESSION['auth_user']['nombre'] = $nuevoNombre;
                        $_SESSION['user_email'] = $nuevoEmail;
                        $_SESSION['auth_user']['correo'] = $nuevoEmail;
                        $_SESSION['user_foto'] = $nuevaFotoRuta;

                        // Enviar correo de notificación contextual y registrar auditoría en logs_correos
                        if (!empty($cambios)) {
                            require_once(__DIR__ . '/includes/email_logger.php');
                            notificarActualizacionUsuario($userId, $nuevoEmail, $nuevoNombre, $cambios);

                            if (!empty($oldEmail) && strtolower($oldEmail) !== strtolower($nuevoEmail)) {
                                notificarActualizacionUsuario($userId, $oldEmail, $nuevoNombre, array(
                                    'Aviso de Cambio de Correo' => "El correo electrónico principal de su cuenta fue actualizado a: $nuevoEmail"
                                ));
                            }
                        }

                        $_SESSION['flash_msg_success'] = "Su información de perfil ha sido actualizada exitosamente.";
                        header("Location: perfil.php");
                        exit;
                    } else {
                        $_SESSION['flash_msg_error'] = "Ocurrió un error al actualizar los datos en la base de datos.";
                        header("Location: perfil.php");
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Mi Perfil | LIHO</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />

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
                        sans: ["Montserrat", "sans-serif"]
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Montserrat', sans-serif; }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Container -->
    <main class="flex-grow max-w-[1380px] w-full mx-auto px-4 sm:px-6 py-8">

        <!-- Alertas de Éxito o Error -->
        <?php if (!empty($msgSuccess)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-emerald-600 text-2xl">check_circle</span>
                    <span class="text-xs font-bold"><?php echo htmlspecialchars($msgSuccess); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($msgError)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-rose-600 text-2xl">error</span>
                    <span class="text-xs font-bold"><?php echo htmlspecialchars($msgError); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Encabezado del Perfil -->
        <div class="mb-8">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 text-primary text-[11px] font-extrabold uppercase tracking-wider mb-2">
                <span class="material-symbols-outlined text-sm">manage_accounts</span>
                <span>Configuración de Usuario</span>
            </div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-primary tracking-tight">Mi Perfil de Usuario</h1>
            <p class="text-xs text-slate-400 font-medium mt-1">Administre sus datos personales, foto de perfil y fecha de nacimiento en LIHO</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Columna Izquierda: Tarjeta de Resumen del Usuario con Foto -->
            <div class="space-y-6">
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm text-center relative overflow-hidden">
                    <!-- Banner de fondo decorativo -->
                    <div class="h-24 bg-gradient-to-r from-primary to-tertiary -mx-6 -mt-6 mb-12"></div>
                    
                    <!-- Avatar con botón selector de foto -->
                    <div class="relative inline-block -mt-20 mb-3 group">
                        <div class="w-24 h-24 rounded-full bg-primary text-white font-extrabold flex items-center justify-center text-2xl border-4 border-white shadow-xl mx-auto overflow-hidden bg-slate-100">
                            <?php if (!empty($fotoPerfil) && file_exists(__DIR__ . '/' . $fotoPerfil)): ?>
                                <img id="profileImagePreview" src="<?php echo htmlspecialchars($fotoPerfil); ?>" alt="Foto Perfil" class="w-full h-full object-cover" />
                            <?php else: ?>
                                <div id="profileImageFallback" class="w-full h-full bg-gradient-to-tr from-primary to-[#006a68] text-white font-extrabold flex items-center justify-center text-2xl">
                                    <?php 
                                        $words = explode(' ', trim($userName));
                                        echo strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr($words[1] ?? '', 0, 1));
                                    ?>
                                </div>
                                <img id="profileImagePreview" src="" alt="Foto Perfil" class="w-full h-full object-cover hidden" />
                            <?php endif; ?>
                        </div>

                        <!-- Botón Cámara para activar selección -->
                        <label for="foto_perfil_input" class="absolute bottom-1 right-1 w-8 h-8 bg-tertiary hover:bg-[#00b2af] text-white rounded-full flex items-center justify-center cursor-pointer shadow-md transition-transform hover:scale-110" title="Cambiar foto de perfil">
                            <span class="material-symbols-outlined text-base">photo_camera</span>
                        </label>
                    </div>

                    <h2 class="text-base font-extrabold text-primary leading-tight"><?php echo htmlspecialchars($userName); ?></h2>
                    <p class="text-xs text-tertiary font-extrabold uppercase tracking-wider mt-1"><?php echo htmlspecialchars($userRole); ?></p>
                    <p class="text-xs text-slate-400 font-medium mt-1"><?php echo htmlspecialchars($userEmail); ?></p>

                    <div class="mt-6 pt-6 border-t border-slate-100 space-y-3 text-left">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400 font-medium">ID Usuario SQL:</span>
                            <span class="font-extrabold text-primary font-mono">#<?php echo htmlspecialchars($userId ?? 'N/A'); ?></span>
                        </div>
                        <?php if (!empty($usuarioInfo['usuario_proteo'])): ?>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400 font-medium">Usuario Proteo:</span>
                                <span class="font-extrabold text-tertiary font-mono bg-teal-50 px-2 py-0.5 rounded-md border border-teal-200/60">
                                    <?php echo htmlspecialchars($usuarioInfo['usuario_proteo']); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($usuarioInfo['cedula'])): ?>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400 font-medium">Cédula:</span>
                                <span class="font-bold text-slate-700 font-mono"><?php echo htmlspecialchars($usuarioInfo['cedula']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($fechaNacimiento)): ?>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-slate-400 font-medium">Fecha Nacimiento:</span>
                                <span class="font-bold text-slate-700 font-mono"><?php echo date('d/m/Y', strtotime($fechaNacimiento)); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400 font-medium">Estado Cuenta:</span>
                            <span class="font-bold text-emerald-600 flex items-center gap-1">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Activo
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tarjeta de Acceso Rápido a Seguridad -->
                <div class="bg-gradient-to-br from-primary to-[#1c486a] rounded-3xl p-6 text-white shadow-lg relative overflow-hidden">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="p-2 bg-white/10 rounded-xl">
                            <span class="material-symbols-outlined text-tertiary text-xl">shield_lock</span>
                        </div>
                        <h3 class="font-bold text-sm">Seguridad y Credenciales</h3>
                    </div>
                    <p class="text-xs text-slate-200 leading-relaxed mb-4">
                        Mantenga su cuenta segura cambiando su contraseña periódicamente.
                    </p>
                    <a href="cambiar_clave.php" class="inline-flex items-center gap-2 bg-tertiary hover:bg-[#00b2af] text-white font-bold text-xs px-4 py-2.5 rounded-xl transition-all shadow-sm hover:shadow-md">
                        <span class="material-symbols-outlined text-base">key</span>
                        <span>Cambiar Mi Contraseña</span>
                    </a>
                </div>
            </div>

            <!-- Columna Derecha: Formulario de Actualización Completo -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Formulario de Datos Personales & Foto -->
                <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm">
                    <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                        <div class="p-2.5 rounded-2xl bg-tertiary/10 text-tertiary">
                            <span class="material-symbols-outlined text-2xl">badge</span>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-primary">Datos Institucionales y Personales</h2>
                            <p class="text-xs text-slate-400 font-medium">Actualice su correo, fecha de nacimiento y foto de perfil</p>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="action" value="actualizar_perfil" />
                        <input type="file" id="foto_perfil_input" name="foto_perfil" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewProfileImage(this);" />

                        <!-- Selector Foto de Perfil Directo -->
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="p-2.5 rounded-xl bg-primary/10 text-primary">
                                    <span class="material-symbols-outlined text-xl">account_box</span>
                                </div>
                                <div>
                                    <h3 class="text-xs font-bold text-primary">Foto de Perfil</h3>
                                    <p class="text-[11px] text-slate-400">Formatos: JPG, PNG o WEBP (Máx. 5MB)</p>
                                </div>
                            </div>
                            <label for="foto_perfil_input" class="px-4 py-2 rounded-xl bg-white hover:bg-slate-100 text-primary border border-slate-200 text-xs font-bold shadow-2xs cursor-pointer transition-all flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-sm">upload_file</span>
                                <span>Seleccionar Foto</span>
                            </label>
                        </div>

                        <!-- Grid Nombre Completo & Cédula -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Nombre Completo (Modificable) -->
                            <div>
                                <label class="block text-xs font-bold text-primary uppercase tracking-wider mb-2" for="nombre_completo">
                                    Nombre Completo *
                                </label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">person</span>
                                    <input type="text" id="nombre_completo" name="nombre_completo" required value="<?php echo htmlspecialchars($userNombreDisplay); ?>"
                                        class="w-full bg-slate-50 pl-10 pr-4 py-3 rounded-xl border border-slate-200 text-xs font-bold text-primary focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                                </div>
                            </div>

                            <!-- Cédula / Documento (No Modificable) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">
                                    Cédula / Documento (No Modificable)
                                </label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">badge</span>
                                    <input type="text" disabled value="<?php echo htmlspecialchars($cedulaDisplay); ?>"
                                        class="w-full bg-slate-100 pl-10 pr-4 py-3 rounded-xl border border-slate-200 text-xs font-bold text-slate-500 font-mono cursor-not-allowed" />
                                </div>
                            </div>
                        </div>

                        <!-- Fecha de Nacimiento & Correo Electrónico Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            
                            <!-- Fecha de Nacimiento -->
                            <div>
                                <label class="block text-xs font-bold text-primary uppercase tracking-wider mb-2" for="fecha_nacimiento">
                                    Fecha de Nacimiento
                                </label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">cake</span>
                                    <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?php echo htmlspecialchars($fechaNacimiento); ?>"
                                        class="w-full bg-slate-50 pl-10 pr-4 py-3 rounded-xl border border-slate-200 text-xs font-bold text-primary focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                                </div>
                            </div>

                            <!-- Correo Electrónico -->
                            <div>
                                <label class="block text-xs font-bold text-primary uppercase tracking-wider mb-2" for="email">
                                    Correo Electrónico
                                </label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">mail</span>
                                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($userEmail); ?>"
                                        class="w-full bg-slate-50 pl-10 pr-4 py-3 rounded-xl border border-slate-200 text-xs font-bold text-primary focus:bg-white focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
                                </div>
                            </div>

                        </div>

                        <!-- Botón Guardar -->
                        <div class="pt-4 border-t border-slate-100 flex justify-end">
                            <button type="submit" class="inline-flex items-center gap-2 bg-gradient-to-r from-tertiary to-[#009b98] hover:from-[#00b2af] hover:to-[#008986] text-white font-bold text-xs px-6 py-3 rounded-xl shadow-md transition-all cursor-pointer">
                                <span class="material-symbols-outlined text-base">save</span>
                                <span>Guardar Cambios</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Caja Informativa de Sesión Activa -->
                <div class="bg-slate-100/80 rounded-3xl p-6 border border-slate-200/80 text-slate-700">
                    <h3 class="text-xs font-extrabold text-primary uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-tertiary text-lg">info</span>
                        <span>Información de Sesión y Seguridad</span>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div>
                            <span class="text-slate-400 font-medium">Método de Autenticación:</span>
                            <p class="font-bold text-primary">OTP de 6 caracteres vía Email</p>
                        </div>
                        <div>
                            <span class="text-slate-400 font-medium">Zona Horaria:</span>
                            <p class="font-bold text-primary">America/Bogota (Hora Colombia)</p>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </main>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
    function previewProfileImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function (e) {
                const imgPreview = document.getElementById('profileImagePreview');
                const imgFallback = document.getElementById('profileImageFallback');

                if (imgPreview) {
                    imgPreview.src = e.target.result;
                    imgPreview.classList.remove('hidden');
                }
                if (imgFallback) {
                    imgFallback.classList.add('hidden');
                }
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>

</body>

</html>
