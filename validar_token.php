<?php
session_start();
date_default_timezone_set('America/Bogota');

if (!isset($_SESSION['auth_user'])) {
    header("Location: index.php");
    exit;
}

$userEmail = $_SESSION['auth_user']['correo'] ?? '';
$userName = $_SESSION['auth_user']['nombre'] ?? 'Médico';
$errorMsg = '';
$successMsg = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $rawToken = $_POST['token'] ?? '';
    if (is_array($rawToken)) {
        $tokenIngresado = implode('', $rawToken);
    } else {
        $tokenIngresado = (string)$rawToken;
    }

    $tokenClean = strtoupper(trim($tokenIngresado));
    $tokenSession = strtoupper(trim($_SESSION['auth_token'] ?? ''));

    if (empty($tokenClean)) {
        $errorMsg = "Por favor ingrese el código de 6 caracteres.";
    } elseif (!isset($_SESSION['auth_token']) || time() > ($_SESSION['token_expires'] ?? 0)) {
        $errorMsg = "El código de acceso ha expirado (límite de 15 minutos). Solicite uno nuevo.";
    } elseif ($tokenClean === $tokenSession) {
        // Token correcto - Iniciar sesión oficial en la plataforma LIHO
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $_SESSION['auth_user']['id'];
        $_SESSION['user_role'] = strtoupper($_SESSION['auth_user']['rol'] ?? 'SIN ROL');
        $_SESSION['user_role_id'] = $_SESSION['auth_user']['rol_id'] ?? 0;
        $userNameClean = $_SESSION['auth_user']['nombre'] ?? 'Usuario';
        if ($_SESSION['user_role'] !== 'MÉDICO') {
            $userNameClean = preg_replace('/^Dr\.\s*/i', '', $userNameClean);
        }
        $_SESSION['user_name'] = $userNameClean;
        $_SESSION['user_email'] = $_SESSION['auth_user']['correo'];
        $_SESSION['user_permisos'] = $_SESSION['auth_user']['permisos'] ?? '';

        $mustChangePass = false;
        require_once(__DIR__ . '/config/conexion.php');
        require_once(__DIR__ . '/includes/logger_helper.php');
        if (isset($con) && $con !== false) {
            $userId = $_SESSION['auth_user']['id'] ?? null;
            $userEmailClean = $_SESSION['auth_user']['correo'] ?? '';

            registrar_log_acceso($con, $userEmailClean, 'LOGIN_EXITOSO', $userId, 'Inicio de sesión completado con OTP.');

            if ($userId !== null) {
                @sqlsrv_query($con, "UPDATE usuarios SET ultima_sesion = GETDATE() WHERE id = ?", array($userId));
                
                $stmtClave = sqlsrv_query($con, "SELECT TOP 1 clave FROM usuarios WHERE id = ?", array($userId));
                if ($stmtClave !== false && $rowClave = sqlsrv_fetch_array($stmtClave, SQLSRV_FETCH_ASSOC)) {
                    $dbPass = $rowClave['clave'] ?? '';
                    $genericDoctor = "medico" . $userId . "*";
                    $genericAdmin  = "desarrollo";

                    if (password_verify($genericDoctor, $dbPass) || $dbPass === $genericDoctor ||
                        password_verify($genericAdmin, $dbPass)  || $dbPass === $genericAdmin) {
                        $mustChangePass = true;
                    }
                }
            }
            @sqlsrv_close($con);
        }

        if ($mustChangePass) {
            $_SESSION['must_change_password'] = true;
            header("Location: cambiar_clave.php");
            exit;
        } else {
            $_SESSION['must_change_password'] = false;
            header("Location: dashboard.php");
            exit;
        }
    } else {
        $errorMsg = "El código ingresado es incorrecto. Verifique e intente nuevamente.";
        require_once(__DIR__ . '/config/conexion.php');
        require_once(__DIR__ . '/includes/logger_helper.php');
        if (isset($con) && $con !== false) {
            $userId = $_SESSION['auth_user']['id'] ?? null;
            $userEmailClean = $_SESSION['auth_user']['correo'] ?? $userEmail;
            registrar_log_acceso($con, $userEmailClean, 'OTP_FALLIDO', $userId, 'Código OTP ingresado incorrecto.');
            @sqlsrv_close($con);
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Código de Verificación | LIHO</title>
    
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
            background: rgba(255, 255, 255, 0.72) !important;
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

        .pin-input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 24px;
            font-weight: 800;
            color: #14354e;
            border-radius: 14px;
            border: 1.5px solid rgba(148, 163, 184, 0.5);
            background: rgba(255, 255, 255, 0.9);
            transition: all 0.25s ease;
        }
        .pin-input:focus {
            border-color: #00c1be;
            box-shadow: 0 0 0 4px rgba(0, 193, 190, 0.18);
            outline: none;
            background: #ffffff;
        }
    </style>
</head>

<body class="bg-background text-on-background min-h-screen flex flex-col selection:bg-tertiary selection:text-white">
    <!-- Header -->
    <header class="bg-white/85 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50">
        <div class="flex justify-between items-center w-full px-6 py-3 max-w-[1280px] mx-auto h-16">
            <div class="flex items-center gap-3">
                <img src="assets/img/Logo original.png" alt="Hernán Ocazionez Logo" class="h-10 md:h-12 w-auto object-contain" />
            </div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-primary transition-colors flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">arrow_back</span>
                <span>Cambiar Correo</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow flex items-center justify-center relative overflow-hidden px-4 py-10">
        <div class="absolute inset-0 z-0">
            <div class="w-full h-full bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?q=80&w=2070&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-overlay backdrop-blur-[3px]"></div>
        </div>

        <div class="absolute z-0 w-96 h-96 bg-tertiary/25 rounded-full blur-3xl ambient-glow pointer-events-none"></div>

        <!-- Token Form Card -->
        <div class="relative z-10 w-full max-w-[460px] animate-card-entry">
            <div class="login-card rounded-3xl p-8 md:p-10 shadow-2xl">
                <div class="mb-6 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-tertiary/10 text-tertiary mb-3 shadow-sm border border-tertiary/20">
                        <span class="material-symbols-outlined text-2xl">mark_email_read</span>
                    </div>
                    <h2 class="text-2xl md:text-3xl text-primary mb-2 font-bold tracking-tight">Código de Acceso</h2>
                    <p class="text-xs text-slate-600 font-medium leading-relaxed">
                        Hemos enviado un código alfanumérico de 6 caracteres al correo:<br>
                        <strong class="text-primary font-semibold text-sm"><?php echo htmlspecialchars($userEmail); ?></strong>
                    </p>
                </div>

                <?php if (!empty($errorMsg)): ?>
                    <div class="mb-5 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl text-rose-500">error</span>
                        <span><?php echo htmlspecialchars($errorMsg); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($successMsg)): ?>
                    <div class="mb-5 p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-medium flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl text-emerald-500">check_circle</span>
                        <span><?php echo htmlspecialchars($successMsg); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="validar_token.php" id="tokenForm" class="space-y-6">
                    <div>
                        <label class="block text-center text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3">
                            Ingrese el código de 6 caracteres
                        </label>
                        <div class="flex justify-between items-center gap-2" id="pinContainer">
                            <input type="text" maxlength="1" class="pin-input uppercase" autofocus data-index="0" name="token[]" pattern="[A-Za-z0-9]*" autocomplete="off" />
                            <input type="text" maxlength="1" class="pin-input uppercase" data-index="1" name="token[]" pattern="[A-Za-z0-9]*" autocomplete="off" />
                            <input type="text" maxlength="1" class="pin-input uppercase" data-index="2" name="token[]" pattern="[A-Za-z0-9]*" autocomplete="off" />
                            <input type="text" maxlength="1" class="pin-input uppercase" data-index="3" name="token[]" pattern="[A-Za-z0-9]*" autocomplete="off" />
                            <input type="text" maxlength="1" class="pin-input uppercase" data-index="4" name="token[]" pattern="[A-Za-z0-9]*" autocomplete="off" />
                            <input type="text" maxlength="1" class="pin-input uppercase" data-index="5" name="token[]" pattern="[A-Za-z0-9]*" autocomplete="off" />
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-gradient-to-r from-tertiary via-[#00b2af] to-[#009b98] text-white font-bold h-[52px] rounded-xl shadow-lg shadow-tertiary/30 hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 flex items-center justify-center gap-2 group cursor-pointer text-sm">
                        <span>Verificar e Ingresar</span>
                        <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">verified</span>
                    </button>

                    <div class="text-center pt-2">
                        <button type="button" id="resendBtn" class="text-xs font-semibold text-primary hover:text-tertiary transition-colors inline-flex items-center gap-1 cursor-pointer">
                            <span class="material-symbols-outlined text-base">refresh</span> Reenviar código a mi correo
                        </button>
                        <span id="resendMsg" class="block text-[11px] font-medium text-slate-500 mt-1 hidden"></span>
                    </div>
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
                <span class="material-symbols-outlined text-sm text-tertiary">lock</span>
                <span>Acceso Seguro IPS</span>
            </div>
        </div>
    </footer>

    <script>
        // Auto-avanzar campos de entrada para el PIN de 6 caracteres
        const pinInputs = document.querySelectorAll('.pin-input');
        pinInputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                input.value = input.value.toUpperCase();
                if (input.value.length === 1 && index < pinInputs.length - 1) {
                    pinInputs[index + 1].focus();
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && input.value === '' && index > 0) {
                    pinInputs[index - 1].focus();
                }
            });

            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
                if (/^[A-Z0-9]{6}$/.test(pasteData)) {
                    pasteData.split('').forEach((char, i) => {
                        if (pinInputs[i]) pinInputs[i].value = char;
                    });
                    pinInputs[5].focus();
                }
            });
        });

        // Manejo de Reenviar Código por AJAX
        const resendBtn = document.getElementById('resendBtn');
        const resendMsg = document.getElementById('resendMsg');

        resendBtn.addEventListener('click', () => {
            resendBtn.disabled = true;
            resendBtn.classList.add('opacity-50');
            resendMsg.textContent = 'Enviando nuevo código...';
            resendMsg.classList.remove('hidden');

            const formData = new FormData();
            formData.append('email', '<?php echo addslashes($userEmail); ?>');

            fetch('validar_email.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    resendMsg.textContent = 'Nuevo código enviado a su correo.';
                    resendMsg.className = 'block text-[11px] font-semibold text-emerald-600 mt-1';
                } else {
                    resendMsg.textContent = 'Error: ' + (data.message || 'No se pudo reenviar');
                    resendMsg.className = 'block text-[11px] font-semibold text-rose-600 mt-1';
                }
                setTimeout(() => {
                    resendBtn.disabled = false;
                    resendBtn.classList.remove('opacity-50');
                }, 5000);
            })
            .catch(err => {
                resendMsg.textContent = 'Error de red al reenviar el código.';
                resendMsg.className = 'block text-[11px] font-semibold text-rose-600 mt-1';
                resendBtn.disabled = false;
                resendBtn.classList.remove('opacity-50');
            });
        });
    </script>
</body>

</html>
