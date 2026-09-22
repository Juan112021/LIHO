# Bitácora de Cambios y Versiones (CHANGELOG)
Todas las modificaciones notables del proyecto **LIHO** (Hernán Ocazionez y Cía S.A.S.) se documentan en este archivo de manera cronológica.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y este proyecto se adhiere a [Semantic Versioning (SemVer)](https://semver.org/lang/es/).

---

## [1.1.3] - 2026-09-21

### 🚀 Novedades y Transparencia Financiera
- **Visualización y Desglose del Concepto de Bonificación por Tomografías (`aprobacion_liquidaciones.php`)**:
  - **Identificación y Aislamiento de Concepto**: Ahora el bono de tomografías contrastadas (regla de 50 tomografías × $150.000 COP, código `BONI_TOHO`) se desagrega y presenta como un concepto institucional independiente (`BONIFICACIÓN TOMOGRAFÍAS`), evitando que quede oculto o agrupado dentro del concepto de `RXSI`.
  - **Banner Corporativo de Incentivo**: Al abrir el detalle de una liquidación (`Ver Detalle`), si el profesional cuenta con bonos causados, se despliega un banner destacado en gradiente dorado/ámbar con icono de mérito (`military_tech`), especificando la regla aplicada, la sede donde se reconoció y el valor monetario adicional.
  - **Fila Destacada por Sede**: En la tabla de centros de costo de cada sede, la bonificación se resalta con distintivo ámbar, indicando la cantidad exacta de bonos alcanzados (`1 bono(s)`) y el monto (`+$150.000 COP`).
  - **Auditoría en Informe Detallado de Sede**: En la vista drill-down por sede (`Ver informe`), se incluye la bonificación tanto en el resumen de conceptos como en la tabla cronológica de registros con etiqueta `INCENTIVO POR PRODUCTIVIDAD` e insignia `Bonificación`.
  - **Exportación en Excel y PDF**: Se actualizó la exportación completa a Excel y la generación de PDF oficial para que el concepto de incentivo se describa explícitamente como `BONIFICACIÓN TOMOGRAFÍAS (REGLA 50 CT x $150.000 COP)`.
  - **Regeneración Dinámica y Retrocompatibilidad**: En `includes/liquidaciones_helper.php`, se adaptó `generarResumenSedesJSON()` y `obtenerLiquidacionPorIdBD()` para recalcular y actualizar en caliente el resumen de sedes en liquidaciones que contengan el bono.
  - **Actualización del Manual de Usuario**: Se incorporó en `manual_usuario.php` la explicación operativa sobre la visualización e interpretación del bono de tomografías en las liquidaciones.

### 📂 Archivos Modificados
- `config/version.php`
- `aprobacion_liquidaciones.php`
- `includes/liquidaciones_helper.php`
- `includes/pdf_liquidaciones.php`
- `notas_ajuste.php`
- `manual_usuario.php`
- `README.md`
- `CHANGELOG.md`

---

## [1.1.2] - 2026-09-21

### 🔧 Mejoras de Navegación
- **Apertura en Nueva Pestaña (`target="_blank"`)**:
  - Se configuraron todos los accesos al **Manual de Usuario** (Dashboard, Drawer de Módulos, Menú de Usuario y Footer) con `target="_blank"` y `rel="noopener noreferrer"`.
  - Ahora el manual se abre en una pestaña independiente sin interrumpir la sesión o el flujo de trabajo activo en el sistema.

### 📂 Archivos Modificados
- `config/version.php`
- `dashboard.php`
- `includes/navbar.php`
- `includes/footer.php`
- `README.md`
- `CHANGELOG.md`

---

## [1.1.1] - 2026-09-21

### 🔧 Mejoras y Refinamiento Visual
- **Limpieza de Barra de Navegación**:
  - Retiro del botón del manual de la barra superior para preservar la estética limpia y minimalista del header.
- **Restauración del Hero Banner de Bienvenida**:
  - Eliminación de la tarjeta interna en el banner principal del Dashboard, devolviéndole su formato limpio y equilibrado.
- **Ubicación Integrada del Manual de Usuario**:
  - Integración como módulo oficial dentro del Drawer offcanvas de **Módulos (`grid_view`)**.
  - Acceso sutil como botón secundario en el encabezado de la sección de *Acciones Rápidas de Control*.
  - Mantenimiento del acceso en el menú desplegable del avatar de usuario y en el pie de página.

### 📂 Archivos Modificados
- `config/version.php`
- `includes/navbar.php`
- `dashboard.php`
- `README.md`
- `CHANGELOG.md`

---

## [1.1.0] - 2026-09-21

### 🚀 Novedades
- **Manual de Usuario Corporativo Interactivo (`manual_usuario.php`)**:
  - Plataforma integral de documentación institucional y guía operativa para la IPS Hernán Ocazionez y Cía S.A.S.
  - Buscador en tiempo real para encontrar secciones y términos clave al instante.
  - Filtro interactivo por perfil de usuario (`Todos`, `Médico`, `Financiero`, `Administrador`).
  - Documentación paso a paso de los 9 módulos del sistema: conciliación bidireccional, liquidaciones, notas de ajuste, tarifarios y vigencias temporales, bloqueos, certificados tributarios y control forense.
  - Función de impresión y exportación a PDF para archivo físico o digital.
- **Acceso Rápido y Destacado en Interfaz**:
  - Botón prominente en la barra de navegación (`includes/navbar.php`) con badge dinámico de versión.
  - Tarjeta de acceso interactiva dentro del banner de bienvenida principal (`dashboard.php`).
  - Enlace directo en el menú desplegable de usuario y en el pie de página (`includes/footer.php`).

### 🔧 Mejoras
- **Centralización del Versionado (`config/version.php`)**:
  - Creación de constantes institucionales `LIHO_VERSION`, `LIHO_VERSION_DATE` y `LIHO_VERSION_NAME` vinculadas a todas las vistas, footer, navbar y changelog.
- **Normalización de Versión en Footer**:
  - Reemplazo de versión estática por la constante centralizada `LIHO_VERSION`.

### 📂 Archivos Modificados
- `config/version.php` [NUEVO]
- `manual_usuario.php` [NUEVO]
- `includes/navbar.php`
- `includes/footer.php`
- `dashboard.php`
- `README.md`
- `CHANGELOG.md`

---

## [1.0.0] - 2026-09-21

### 🚀 Versión Base Inicial y Consolidación del Sistema

Esta versión establece la línea base formal del sistema **LIHO** bajo control de versiones Git, integrando los módulos diagnósticos, administrativos, financieros y de conciliación clínica.

#### 🔄 Cruce Bidireccional de Exámenes y Liquidación
- **Cruce Automático Proteo vs Servinte**: Algoritmo de conciliación por pares Fuente-Ingreso con detección de discrepancias y estados de cruce (`CRUZADOS_OK`, `SOLO_PROTEO`, `SOLO_SERVINTE`).
- **Cálculo de Tarifas y Modalidades**:
  - Detección de tipo de paciente: Entidad (`E`) vs Particular (`P`).
  - Regla especial de **Bonificación para Tomografías Contrastadas** (50 exámenes x $150.000 COP).
  - Modalidades dinámicas: Degluciones (45%), Tarifas Especiales (30%), Pago Dinámico porcentual y por valor fijo.
  - Soporte de base de cálculo (`VALOR_LIQUIDACION` vs `VALOR_EXAMEN`).
  - Flag de `pagar_por_cantidad` para multiplicar o fijar el valor unitario.

#### 📊 Tarifarios y Vigencias Temporales
- Catálogo maestro de tarifas por código CUPS y entidad de salud.
- Sistema de **Vigencias Temporales** con fechas de inicio (`vigencia_desde`) y fin (`vigencia_hasta`), permitiendo resolución histórica exacta de la tarifa que aplicaba en la fecha en que se realizó el examen médico.
- Módulo de Tarifarios Especiales por médico y por examen.
- Bloqueos de tarifas para restringir procedimientos no autorizados.

#### 💰 Gestión Financiera, Aprobaciones y Retenciones
- Pantalla de aprobación de liquidaciones con estados de flujo de trabajo.
- Módulo de Notas de Ajuste débito y crédito con trazabilidad de usuario y motivo.
- Emisión de Certificados Tributarios oficiales con retención en la fuente en PDF (FPDF).

#### 🛡️ Seguridad, Auditoría y Entornos
- Cifrado AES-256 en cadenas de conexión a bases de datos (`security_crypto.php`).
- Autenticación con tokens de acceso vía correo electrónico (Gmail SMTP).
- Asignación granular de roles (`ADMINISTRADOR`, `FINANCIERO`, `MÉDICO`) y permisos por vista.
- Módulo de configuración de entorno: bloqueo de correos a médicos en desarrollo con redirección controlada a cuenta de pruebas.
- Trazabilidad con `audit_logger.php` y registro de accesos.

#### 🐛 Correcciones y Optimizaciones Recientes
- **examenes_medicos.php**: Corrección de variable no inicializada `$valorUndServinte` en la línea 1569 que provocaba advertencias PHP y rompía la respuesta JSON en el navegador.
- **Optimización de Índices en SQL Server**:
  - Depuración y creación del índice `IX_tarifario_estado1` (`[estado], [entidad_id]`) con columnas calculadas para acelerar la carga de tarifas en milisegundos.
  - Implementación del índice `IX_tarifario_version_id1` para búsquedas históricas por versión.
  - Eliminación de índices redundantes que sobrecargaban las operaciones de escritura.

#### 📂 Archivos Principales Incorporados
- `examenes_medicos.php` — Conciliación bidireccional y liquidación médica.
- `tarifario.php`, `historial_tarifario.php`, `tarifario_especial.php`, `tarifario_bloqueos.php` — Gestión tarifaria.
- `aprobacion_liquidaciones.php`, `notas_ajuste.php`, `certificados_tributarios.php` — Módulos financieros.
- `medicos.php`, `gestion_medicos_procedimientos.php` — Directorio médico.
- `maestro_entidades.php`, `maestro_porcentajes.php`, `maestro_parafiscales.php` — Tablas maestras.
- `usuarios.php`, `gestion_roles.php`, `logs.php` — Administración y auditoría.
- `cron_alerta_medicos.php`, `alerta_medicos.php` — Tareas programadas de notificación.

---

## 📝 Guía para Nuevas Versiones

Cada vez que se realicen cambios significativos, se añadirá una nueva sección superior respetando esta plantilla:

```markdown
## [X.Y.Z] - AAAA-MM-DD

### 🚀 Novedades
- Descripción de nuevas funcionalidades o pantallas agregadas.

### 🔧 Mejoras
- Optimizaciones de rendimiento, mejoras de interfaz o refactorizaciones.

### 🐛 Correcciones
- Solución de errores reportados o comportamientos inesperados.

### 🔒 Seguridad y Configuración
- Cambios en políticas de acceso, credenciales o variables de entorno.

### 📂 Archivos Modificados
- `archivo1.php`
- `archivo2.php`
```
