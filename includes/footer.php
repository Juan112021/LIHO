<?php require_once __DIR__ . '/../config/version.php'; ?>
<footer class="no-print bg-white dark:bg-slate-900 border-t border-slate-200/80 dark:border-slate-800 mt-auto py-5 transition-colors duration-300">
    <div class="flex flex-col md:flex-row justify-between items-center w-full px-6 max-w-[1440px] mx-auto text-xs text-slate-500 dark:text-slate-400 space-y-3 md:space-y-0">
        
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-primary dark:text-tertiary">Hernán Ocazionez y Cía S.A.S.</span>
            <span class="text-slate-300 dark:text-slate-700">|</span>
            <p>© <?php echo date('Y'); ?> Plataforma LIHO <span class="font-extrabold text-tertiary ml-0.5">v<?php echo defined('LIHO_VERSION') ? LIHO_VERSION : '1.1.0'; ?></span></p>
            <span class="text-slate-300 dark:text-slate-700">•</span>
            <a href="manual_usuario.php" target="_blank" rel="noopener noreferrer" class="font-bold text-teal-600 dark:text-tertiary hover:underline flex items-center gap-1 transition-colors">
                <span class="material-symbols-outlined text-sm">menu_book</span>
                <span>Manual de Uso</span>
                <span class="material-symbols-outlined text-[10px] text-slate-400">open_in_new</span>
            </a>
        </div>

        <!-- Botón Reportar Fallas / Soporte Técnico -->
        <div class="flex items-center gap-4">
            <button type="button" id="openSoporteBtn" 
                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs border border-slate-200 dark:border-slate-700 transition-all cursor-pointer shadow-2xs hover:scale-105 active:scale-95">
                <span class="material-symbols-outlined text-base text-rose-500 animate-pulse">bug_report</span>
                <span>Soporte Técnico / Reportar Falla</span>
            </button>

            <div class="hidden sm:flex items-center gap-2 font-semibold text-slate-600 dark:text-slate-300">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Sistema Operativo</span>
            </div>
        </div>
    </div>
</footer>

<!-- Modal Pop-up Soporte Técnico RIHO -->
<div id="modalSoporteRIHO" class="no-print fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden opacity-0 pointer-events-none transition-all duration-300">
    <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-md w-full shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden transform scale-95 transition-all duration-300" id="modalSoporteContent">
        
        <!-- Header Modal -->
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/80 dark:bg-slate-900/80">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 border border-rose-100 dark:border-rose-900/40">
                    <span class="material-symbols-outlined text-2xl">support_agent</span>
                </div>
                <div>
                    <h3 class="text-base font-black text-primary dark:text-white tracking-tight">Soporte Técnico e Incidencias</h3>
                    <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Plataforma Institucional de Reportes</p>
                </div>
            </div>
            <button type="button" id="closeSoporteBtn" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors">
                <span class="material-symbols-outlined text-xl">close</span>
            </button>
        </div>

        <!-- Body Modal con Mascota RIHO -->
        <div class="p-6 flex flex-col items-center text-center space-y-4">
            
            <!-- Imagen Mascota RIHO -->
            <div class="relative group">
                <div class="w-36 h-36 rounded-3xl overflow-hidden shadow-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-1 flex items-center justify-center transition-transform duration-300 group-hover:scale-105">
                    <img src="assets/img/Mascota_sis.jpeg" alt="Mascota RIHO Sistemas" class="w-full h-full object-cover rounded-2xl" />
                </div>
            </div>

            <div>
                <h4 class="text-sm font-extrabold text-primary dark:text-tertiary tracking-tight mb-2">
                    ¿Desea reportar alguna falla o inconsistencia?
                </h4>
                <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed font-medium">
                    Para registrar cualquier requerimiento de soporte, falla del sistema o reporte de errores, por favor realice su solicitud directamente a través de la plataforma <strong class="text-primary dark:text-white underline decoration-tertiary">RIHO</strong>.
                </p>
            </div>

        </div>

        <!-- Footer Modal con Enlace a http://app.riho/ -->
        <div class="p-4 bg-slate-50 dark:bg-slate-900/90 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-3">
            <button type="button" id="closeSoporteBtn2" class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-bold transition-all cursor-pointer">
                Cancelar
            </button>
            <a href="http://app.riho/" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-tertiary to-[#009b98] hover:from-[#00b2af] hover:to-[#008986] text-white text-xs font-bold shadow-md shadow-tertiary/20 transition-all hover:scale-105 active:scale-95 cursor-pointer">
                <span>Ir a Plataforma RIHO</span>
                <span class="material-symbols-outlined text-base">open_in_new</span>
            </a>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const openSoporteBtn = document.getElementById('openSoporteBtn');
    const closeSoporteBtn = document.getElementById('closeSoporteBtn');
    const closeSoporteBtn2 = document.getElementById('closeSoporteBtn2');
    const modalSoporte = document.getElementById('modalSoporteRIHO');
    const modalContent = document.getElementById('modalSoporteContent');

    function openSoporteModal() {
        if (!modalSoporte || !modalContent) return;
        modalSoporte.classList.remove('hidden', 'pointer-events-none', 'opacity-0');
        modalSoporte.classList.add('opacity-100');
        modalContent.classList.remove('scale-95');
        modalContent.classList.add('scale-100');
    }

    function closeSoporteModal() {
        if (!modalSoporte || !modalContent) return;
        modalContent.classList.remove('scale-100');
        modalContent.classList.add('scale-95');
        modalSoporte.classList.remove('opacity-100');
        modalSoporte.classList.add('opacity-0');
        setTimeout(() => {
            modalSoporte.classList.add('hidden', 'pointer-events-none');
        }, 250);
    }

    if (openSoporteBtn) openSoporteBtn.addEventListener('click', openSoporteModal);
    if (closeSoporteBtn) closeSoporteBtn.addEventListener('click', closeSoporteModal);
    if (closeSoporteBtn2) closeSoporteBtn2.addEventListener('click', closeSoporteModal);
});
</script>
