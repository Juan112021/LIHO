<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>LIHO | Hernán Ocazionez y Cía S.A.S.</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />

    <!-- Logo Favicon -->
    <link rel="shortcut icon" href="assets/img/hologo.png">

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#14354e",
                        secondary: "#006a68",
                        tertiary: "#00c1be",
                        background: "#f9f9f9",
                        surface: "#ffffff",
                        "on-surface": "#1a1c1c",
                        "outline-variant": "#c3c7cd"
                    },
                    fontFamily: {
                        sans: ["Montserrat", "sans-serif"]
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-dark: #051622;
            --cyan-glow: #00f2ff;
            --medical-blue: #14354E;
        }

        /* --- PRELOADER Y BARRA DE CARGA --- */
        #preloader {
            position: fixed;
            inset: 0;
            background: var(--bg-dark);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 100;
        }

        .name-text-pre {
            font-weight: 200;
            font-size: 1rem;
            letter-spacing: 0.8em;
            fill: none;
            stroke: var(--cyan-glow);
            stroke-width: 0.6px;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .loader-bar-container {
            width: 260px;
            height: 2px;
            background: rgba(0, 242, 255, 0.12);
            position: relative;
            overflow: hidden;
            border-radius: 2px;
        }

        .loader-bar-fill {
            position: absolute;
            height: 100%;
            width: 100%;
            background: var(--cyan-glow);
            left: -100%;
            box-shadow: 0 0 10px var(--cyan-glow);
        }

        * {
            font-family: 'Montserrat', sans-serif;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f9f9f9;
        }

        .animate-card-entry {
            animation: cardEntry 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardEntry {
            0% {
                opacity: 0;
                transform: translateY(30px) scale(0.97);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-card {
            background: rgba(255, 255, 255, 0.72) !important;
            backdrop-filter: blur(24px) saturate(200%);
            -webkit-backdrop-filter: blur(24px) saturate(200%);
            border: 1px solid rgba(255, 255, 255, 0.85) !important;
            box-shadow: 0 30px 60px -12px rgba(20, 53, 78, 0.18), 0 18px 36px -18px rgba(0, 0, 0, 0.12), inset 0 1px 1px rgba(255, 255, 255, 0.9);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease;
        }

        .login-card:hover {
            box-shadow: 0 35px 70px -12px rgba(20, 53, 78, 0.22), 0 20px 40px -18px rgba(0, 0, 0, 0.15), inset 0 1px 1px rgba(255, 255, 255, 0.95);
        }

        .bg-overlay {
            background: linear-gradient(135deg, rgba(20, 53, 78, 0.42) 0%, rgba(249, 249, 249, 0.45) 100%);
        }

        @keyframes pulseGlow {
            0%, 100% {
                opacity: 0.4;
                transform: scale(1);
            }
            50% {
                opacity: 0.75;
                transform: scale(1.1);
            }
        }

        .ambient-glow {
            animation: pulseGlow 6s ease-in-out infinite;
        }
    </style>
</head>

<body class="bg-background text-on-background min-h-screen flex flex-col selection:bg-tertiary selection:text-white">
    
    <!-- Preloader de Bienvenida con Animación SVG -->
    <div id="preloader">
        <svg viewBox="0 0 900 60" class="w-full max-w-2xl px-4">
            <text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle" class="name-text-pre animate-stroke">
                LIHO | HERNÁN OCAZIONEZ Y CÍA S.A.S.
            </text>
        </svg>
        <div class="loader-bar-container mt-4">
            <div class="loader-bar-fill" id="fill"></div>
        </div>
        <p class="text-[11px] text-cyan-400/70 tracking-widest uppercase mt-4 font-light">Cargando Plataforma de Liquidación Médica...</p>
    </div>

    <!-- Header Institucional -->
    <header class="bg-white/85 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50 transition-all">
        <div class="flex justify-between items-center w-full px-6 md:px-12 py-3 max-w-[1280px] mx-auto h-16">
            <div class="flex items-center gap-3">
                <img src="assets/img/Logo original.png" alt="Hernán Ocazionez Logo"
                    class="h-10 md:h-12 w-auto object-contain transition-transform duration-300 hover:scale-105" />
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-primary bg-primary/5 px-3 py-1.5 rounded-full border border-primary/10">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Portal de Médicos</span>
            </div>
        </div>
    </header>

    <!-- Contenido Principal: Tarjeta Central de Autenticación -->
    <main class="flex-grow flex items-center justify-center relative overflow-hidden px-4 py-10">
        
        <!-- Imagen de Fondo Institucional -->
        <div class="absolute inset-0 z-0">
            <div class="w-full h-full bg-cover bg-center"
                style="background-image: url('https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?q=80&w=2070&auto=format&fit=crop');">
            </div>
            <div class="absolute inset-0 bg-overlay backdrop-blur-[3px]"></div>
        </div>

        <!-- Ambient Glow Detrás de la Tarjeta -->
        <div class="absolute z-0 w-96 h-96 bg-tertiary/25 rounded-full blur-3xl ambient-glow pointer-events-none"></div>

        <!-- Tarjeta de Login -->
        <div class="relative z-10 w-full max-w-[450px] animate-card-entry">
            <div class="login-card rounded-3xl overflow-hidden shadow-2xl">
                
                <!-- Encabezado de Marca -->
                <div class="px-8 pt-8 pb-6 text-center border-b border-slate-200/60">
                    <div class="flex justify-center mb-4">
                        <img src="assets/img/Logo original.png" alt="Hernán Ocazionez Logo"
                            class="h-12 w-auto object-contain drop-shadow-sm" />
                    </div>
                    <!-- Nombre de la Plataforma LIHO -->
                    <h1 class="text-4xl font-extrabold tracking-widest uppercase" style="background: linear-gradient(135deg, #14354e 0%, #00c1be 100%);
                               -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                               background-clip: text; letter-spacing: .18em;">
                        LIHO
                    </h1>
                    <p class="text-xs font-semibold text-slate-500 tracking-wider uppercase mt-1">Liquidación Médica IPS</p>
                    <div class="mt-2.5">
                        <span class="inline-flex items-center gap-1 px-3 py-0.5 rounded-full text-[10px] font-extrabold bg-tertiary/10 border border-tertiary/20 text-tertiary tracking-widest shadow-2xs">
                            <span class="material-symbols-outlined text-xs">verified</span> V 1.0.0
                        </span>
                    </div>
                </div>

                <!-- Formulario de Acceso -->
                <div class="px-8 md:px-9 py-7">
                    <div class="mb-6 text-center">
                        <div class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-primary/10 text-primary mb-3 shadow-sm border border-primary/15">
                            <span class="material-symbols-outlined text-2xl">lock</span>
                        </div>
                        <h2 class="text-xl md:text-2xl text-primary mb-1.5 font-bold tracking-tight">Iniciar Sesión</h2>
                        <p class="text-xs text-slate-500 font-medium max-w-xs mx-auto leading-relaxed">
                            Ingrese su <strong>Usuario Proteo</strong> (médicos) o <strong>Correo Electrónico</strong> (administradores) para recibir su código de acceso.
                        </p>
                    </div>

                    <form class="space-y-5" id="loginForm">
                        <!-- Campo de Credencial de Acceso -->
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-primary uppercase tracking-wider" for="email">
                                Usuario Proteo / Correo electrónico
                            </label>
                            <div class="relative group">
                                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-tertiary transition-colors duration-200 text-xl pointer-events-none">
                                    badge
                                </span>
                                <input
                                    class="w-full bg-white/80 backdrop-blur-md h-[52px] pl-11 pr-4 rounded-xl border border-slate-300/80 text-primary text-sm font-medium placeholder:text-slate-400 focus:bg-white focus:ring-4 focus:ring-tertiary/20 focus:border-tertiary shadow-sm hover:border-slate-400 transition-all duration-300 outline-none"
                                    id="email" name="email" placeholder="usuario proteo o correo institucional" required="true"
                                    type="text" autocomplete="username" />
                            </div>
                            <p class="text-rose-600 font-medium text-xs mt-1 hidden flex items-center gap-1" id="error-email">
                                <span class="material-symbols-outlined text-sm">error</span>
                                <span id="error-text">Por favor ingrese su usuario o correo registrado</span>
                            </p>
                        </div>

                        <!-- Botón de Envío -->
                        <button
                            class="w-full bg-gradient-to-r from-tertiary via-[#00b2af] to-[#009b98] text-white font-bold text-sm h-[52px] rounded-xl shadow-lg shadow-tertiary/30 hover:shadow-xl hover:shadow-tertiary/40 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 flex items-center justify-center gap-2 group cursor-pointer"
                            type="submit" id="submitBtn">
                            <span>Continuar</span>
                            <span class="material-symbols-outlined text-xl group-hover:translate-x-1.5 transition-transform duration-200">arrow_forward</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer Institucional -->
    <footer class="bg-white border-t border-slate-200">
        <div class="flex flex-col md:flex-row justify-between items-center w-full px-6 py-4 max-w-[1280px] mx-auto space-y-2 md:space-y-0 h-auto md:h-16 text-xs text-slate-500">
            <div class="flex items-center gap-2">
                <span class="font-bold text-primary">Hernán Ocazionez y Cía S.A.S.</span>
                <span class="text-slate-300">|</span>
                <p>© 2026 Plataforma LIHO <span class="font-extrabold text-tertiary ml-0.5">V 1.0.0</span></p>
            </div>
            <div class="flex items-center gap-1.5 font-semibold">
                <span class="material-symbols-outlined text-sm text-tertiary">shield_lock</span>
                <span>Acceso Seguro IPS</span>
            </div>
        </div>
    </footer>

    <!-- Anime.js para animaciones suavizadas -->
    <script src="https://cdn.jsdelivr.net/npm/animejs@3.2.1/lib/anime.min.js"></script>
    <script>
        // Animación suave del Preloader
        const tl = anime.timeline({ easing: 'easeOutExpo' });

        tl.add({
            targets: '.animate-stroke',
            strokeDashoffset: [anime.setDashoffset, 0],
            opacity: [0, 1],
            duration: 1800,
            delay: 200
        })
        .add({
            targets: '.loader-bar-fill',
            left: ['-100%', '0%'],
            duration: 1300,
            easing: 'easeInOutQuad'
        }, '-=900')
        .add({
            targets: '#preloader',
            opacity: 0,
            duration: 600,
            complete: () => {
                document.getElementById('preloader').style.display = 'none';
            }
        });

        // Manejo del envio de correo AJAX
        document.getElementById('loginForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const emailInput = document.getElementById('email');
            const errorEmail = document.getElementById('error-email');
            const errorText = document.getElementById('error-text');
            const btn = document.getElementById('submitBtn');
            const originalContent = btn.innerHTML;

            errorEmail.classList.add('hidden');
            btn.innerHTML = '<span class="animate-spin material-symbols-outlined text-lg">progress_activity</span> Validando...';
            btn.disabled = true;

            const formData = new FormData(this);
            if (!formData.has('email')) {
                formData.append('email', emailInput.value);
            }

            fetch('validar_email.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    btn.innerHTML = '<span class="material-symbols-outlined text-lg">check_circle</span> Código Enviado';
                    setTimeout(() => {
                        window.location.href = data.redirect || 'validar_token.php';
                    }, 400);
                } else {
                    errorText.textContent = data.message || 'El usuario o correo electrónico no se encuentra registrado.';
                    errorEmail.classList.remove('hidden');
                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorText.textContent = 'Ocurrió un error al procesar la solicitud. Intente de nuevo.';
                errorEmail.classList.remove('hidden');
                btn.innerHTML = originalContent;
                btn.disabled = false;
            });
        });

        const emailInput = document.getElementById('email');
        const errorEmail = document.getElementById('error-email');

        emailInput.addEventListener('input', () => {
            if (emailInput.value.trim() !== '') {
                errorEmail.classList.add('hidden');
            }
        });
    </script>
</body>

</html>
