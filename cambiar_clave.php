<?php
session_start();
date_default_timezone_set('America/Bogota');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['user_name'] ?? 'Usuario';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'MÉDICO';
$errorMsg = '';
$successMsg = '';

$claveInicialInfo = (strtoupper($userRole) === 'ADMINISTRADOR') ? 'desarrollo' : ('medico' . $userId . '*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $claveActual    = $_POST['clave_actual'] ?? '';
    $nuevaClave     = $_POST['nueva_clave'] ?? '';
    $confirmarClave = $_POST['confirmar_clave'] ?? '';

    if (empty($claveActual) || empty($nuevaClave) || empty($confirmarClave)) {
        $errorMsg = "Por favor diligencie todos los campos obligatorios.";
    } elseif (strlen($nuevaClave) < 6) {
        $errorMsg = "La nueva contraseña debe tener al menos 6 caracteres.";
    } elseif ($nuevaClave !== $confirmarClave) {
        $errorMsg = "La confirmación de la nueva contraseña no coincide.";
    } elseif ($nuevaClave === $claveActual) {
        $errorMsg = "La nueva contraseña debe ser diferente a su contraseña actual.";
    } elseif (preg_match('/^medico\d+\*$/i', $nuevaClave) || strtolower($nuevaClave) === 'desarrollo') {
        $errorMsg = "Debe elegir una contraseña diferente a la clave genérica inicial.";
    } else {
        // Encriptar y actualizar contraseña en SQL Server previa verificación de la clave actual
        require_once(__DIR__ . '/config/conexion.php');
        if (isset($con) && $con !== false) {
            // Verificar Contraseña Actual en la BD
            $stmtClave = sqlsrv_query($con, "SELECT TOP 1 clave, email FROM usuarios WHERE id = ?", array($userId));
            $claveCorrecta = false;
            $correoDestino = $userEmail;

            if ($stmtClave !== false && $rowC = sqlsrv_fetch_array($stmtClave, SQLSRV_FETCH_ASSOC)) {
                $dbHash = $rowC['clave'] ?? '';
                if (!empty($rowC['email'])) {
                    $correoDestino = $rowC['email'];
                }

                if (password_verify($claveActual, $dbHash) || $dbHash === $claveActual) {
                    $claveCorrecta = true;
                }
            }

            if (!$claveCorrecta) {
                $errorMsg = "La contraseña actual ingresada es incorrecta. Verifique e intente de nuevo.";
            } else {
                $hashedPassword = password_hash($nuevaClave, PASSWORD_DEFAULT);
                $sqlUpdate = "UPDATE usuarios SET clave = ? WHERE id = ?";
                $stmtUpdate = sqlsrv_query($con, $sqlUpdate, array($hashedPassword, $userId));

                if ($stmtUpdate !== false) {
                    $_SESSION['must_change_password'] = false;

                    // Enviar correo de notificación de seguridad y registrar auditoría en logs_correos
                    require_once(__DIR__ . '/includes/email_logger.php');
                    notificarActualizacionUsuario($userId, $correoDestino, $userName, array(
                        'Contraseña de Seguridad' => 'La contraseña de su cuenta ha sido actualizada exitosamente.',
                        'Fecha y Hora del Cambio' => date('d/m/Y h:i A')
                    ));

                    @sqlsrv_close($con);
                    header("Location: dashboard.php?msg=clave_actualizada");
                    exit;
                } else {
                    $errorMsg = "Error al actualizar la contraseña en la base de datos.";
                }
            }
            @sqlsrv_close($con);
        } else {
            $errorMsg = "Error de conexión a la base de datos.";
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Actualizar Contraseña Obligatoria | LIHO</title>
    
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
                        background: "#f9f9f9",
                        "on-surface": "#1a1c1c"
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
        body { background-color: #f9f9f9; }

        .animate-card-entry {
            animation: cardEntry 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardEntry {
            0% { opacity: 0; transform: translateY(30px) scale(0.97); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .login-card {
            background: rgba(255, 255, 255, 0.75) !important;
            backdrop-filter: blur(24px) saturate(200%);
            -webkit-backdrop-filter: blur(24px) saturate(200%);
            border: 1px solid rgba(255, 255, 255, 0.85) !important;
            box-shadow: 0 30px 60px -12px rgba(20, 53, 78, 0.18), 0 18px 36px -18px rgba(0, 0, 0, 0.12), inset 0 1px 1px rgba(255, 255, 255, 0.9);
        }

        .bg-overlay {
            background: linear-gradient(135deg, rgba(20, 53, 78, 0.42) 0%, rgba(249, 249, 249, 0.45) 100%);
        }

        @keyframes pulseGlow {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.75; transform: scale(1.1); }
        }

        .ambient-glow { animation: pulseGlow 6s ease-in-out infinite; }
    </style>
</head>

<body class="bg-background text-on-background min-h-screen flex flex-col selection:bg-tertiary selection:text-white">
    <!-- Header -->
    <header class="bg-white/85 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50">
        <div class="flex justify-between items-center w-full px-6 py-3 max-w-[1280px] mx-auto h-16">
            <div class="flex items-center gap-3">
                <img src="assets/img/Logo original.png" alt="Hernán Ocazionez Logo" class="h-10 md:h-12 w-auto object-contain" />
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-amber-700 bg-amber-50 px-3 py-1.5 rounded-full border border-amber-200">
                <span class="material-symbols-outlined text-sm text-amber-600">lock_reset</span>
                <span>Cambio Obligatorio de Contraseña</span>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow flex items-center justify-center relative overflow-hidden px-4 py-10">
        <div class="absolute inset-0 z-0">
            <div class="w-full h-full bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?q=80&w=2070&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-overlay backdrop-blur-[3px]"></div>
        </div>

        <div class="absolute z-0 w-96 h-96 bg-amber-500/20 rounded-full blur-3xl ambient-glow pointer-events-none"></div>

        <!-- Password Change Card -->
        <div class="relative z-10 w-full max-w-[460px] animate-card-entry">
            <div class="login-card rounded-3xl p-8 md:p-9 shadow-2xl">
                
                <div class="mb-6 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-600 mb-3 shadow-sm border border-amber-500/20">
                        <span class="material-symbols-outlined text-2xl">published_with_changes</span>
                    </div>
                    <h2 class="text-xl md:text-2xl text-primary mb-1.5 font-bold tracking-tight">Cambio de Contraseña</h2>
                    <p class="text-xs text-slate-600 font-medium leading-relaxed max-w-sm mx-auto">
                        Estimado(a) <strong class="text-primary font-bold"><?php echo htmlspecialchars(preg_replace('/^Dr\.\s*/i', '', $userName)); ?></strong>, ha iniciado sesión por primera vez con su clave inicial asignada (<strong><?php echo htmlspecialchars($claveInicialInfo); ?></strong>). Por seguridad institucional, debe definir su contraseña personal.
                    </p>
                </div>

                <?php if (!empty($errorMsg)): ?>
                    <div class="mb-5 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg text-rose-500">error</span>
                        <span><?php echo htmlspecialchars($errorMsg); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="cambiar_clave.php" class="space-y-4" id="passwordForm">
                    
                    <!-- Contraseña Actual -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-primary uppercase tracking-wider" for="clave_actual">
                            Contraseña Actual *
                        </label>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-tertiary transition-colors text-xl pointer-events-none">
                                lock
                            </span>
                            <input type="password" id="clave_actual" name="clave_actual" required placeholder="Ingrese su contraseña actual"
                                class="w-full bg-white/80 backdrop-blur-md h-[50px] pl-11 pr-12 rounded-xl border border-slate-300 text-primary text-sm font-medium focus:bg-white focus:ring-4 focus:ring-tertiary/20 focus:border-tertiary shadow-sm transition-all outline-none" />
                            <button type="button" onclick="togglePass('clave_actual', 'eye0')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 cursor-pointer">
                                <span class="material-symbols-outlined text-xl" id="eye0">visibility</span>
                            </button>
                        </div>
                    </div>

                    <!-- Nueva Contraseña -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-primary uppercase tracking-wider" for="nueva_clave">
                            Nueva Contraseña Personal *
                        </label>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-tertiary transition-colors text-xl pointer-events-none">
                                key
                            </span>
                            <input type="password" id="nueva_clave" name="nueva_clave" required placeholder="Mínimo 6 caracteres"
                                class="w-full bg-white/80 backdrop-blur-md h-[50px] pl-11 pr-12 rounded-xl border border-slate-300 text-primary text-sm font-medium focus:bg-white focus:ring-4 focus:ring-tertiary/20 focus:border-tertiary shadow-sm transition-all outline-none" />
                            <button type="button" onclick="togglePass('nueva_clave', 'eye1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 cursor-pointer">
                                <span class="material-symbols-outlined text-xl" id="eye1">visibility</span>
                            </button>
                        </div>
                    </div>

                    <!-- Confirmar Contraseña -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-primary uppercase tracking-wider">
                            Confirmar Nueva Contraseña
                        </label>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-tertiary transition-colors text-xl pointer-events-none">
                                check_circle
                            </span>
                            <input type="password" id="confirmar_clave" name="confirmar_clave" required placeholder="Repita la nueva contraseña"
                                class="w-full bg-white/80 backdrop-blur-md h-[50px] pl-11 pr-12 rounded-xl border border-slate-300 text-primary text-sm font-medium focus:bg-white focus:ring-4 focus:ring-tertiary/20 focus:border-tertiary shadow-sm transition-all outline-none" />
                            <button type="button" onclick="togglePass('confirmar_clave', 'eye2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1">
                                <span class="material-symbols-outlined text-xl" id="eye2">visibility</span>
                            </button>
                        </div>
                    </div>

                    <!-- Indicador de Fuerza de Contraseña -->
                    <div class="pt-1">
                        <div class="h-1.5 w-full bg-slate-200 rounded-full overflow-hidden">
                            <div id="strengthBar" class="h-full w-0 bg-rose-500 transition-all duration-300"></div>
                        </div>
                        <p id="strengthText" class="text-[11px] text-slate-500 font-semibold mt-1 text-right">Contraseña muy corta</p>
                    </div>

                    <button type="submit" class="w-full bg-gradient-to-r from-tertiary via-[#00b2af] to-[#009b98] text-white font-bold h-[52px] rounded-xl shadow-lg shadow-tertiary/30 hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 flex items-center justify-center gap-2 group cursor-pointer text-sm mt-4">
                        <span>Guardar Contraseña y Continuar</span>
                        <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">arrow_forward</span>
                    </button>
                </form>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200">
        <div class="flex flex-col md:flex-row justify-between items-center w-full px-6 py-4 max-w-[1280px] mx-auto space-y-2 md:space-y-0 h-auto md:h-16 text-xs text-slate-500">
            <div class="flex items-center gap-2">
                <span class="font-bold text-primary">Hernán Ocazionez y Cía S.A.S.</span>
                <span class="text-slate-300">|</span>
                <p>© 2026 Plataforma LIHO <span class="font-extrabold text-tertiary ml-0.5">V 1.0.0</span></p>
            </div>
            <div class="flex items-center gap-1.5 font-semibold">
                <span class="material-symbols-outlined text-sm text-tertiary">shield</span>
                <span>Seguridad de Datos IPS</span>
            </div>
        </div>
    </footer>

    <script>
        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                icon.textContent = 'visibility';
            }
        }

        const passInput = document.getElementById('nueva_clave');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');

        passInput.addEventListener('input', () => {
            const val = passInput.value;
            let score = 0;
            if (val.length >= 6) score += 30;
            if (val.length >= 8) score += 20;
            if (/[0-9]/.test(val)) score += 25;
            if (/[^A-Za-z0-9]/.test(val)) score += 25;

            strengthBar.style.width = score + '%';

            if (score === 0) {
                strengthBar.className = 'h-full w-0 bg-slate-300 transition-all duration-300';
                strengthText.textContent = 'Ingrese una contraseña';
                strengthText.className = 'text-[11px] text-slate-500 font-semibold mt-1 text-right';
            } else if (score < 50) {
                strengthBar.className = 'h-full bg-rose-500 transition-all duration-300';
                strengthText.textContent = 'Contraseña débil';
                strengthText.className = 'text-[11px] text-rose-600 font-semibold mt-1 text-right';
            } else if (score < 80) {
                strengthBar.className = 'h-full bg-amber-500 transition-all duration-300';
                strengthText.textContent = 'Contraseña media';
                strengthText.className = 'text-[11px] text-amber-600 font-semibold mt-1 text-right';
            } else {
                strengthBar.className = 'h-full bg-emerald-500 transition-all duration-300';
                strengthText.textContent = 'Contraseña fuerte';
                strengthText.className = 'text-[11px] text-emerald-600 font-semibold mt-1 text-right';
            }
        });
    </script>
</body>

</html>
