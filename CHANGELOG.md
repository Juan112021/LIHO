# Bitácora de Cambios y Versiones (CHANGELOG)
Todas las modificaciones notables del proyecto **LIHO** (Hernán Ocazionez y Cía S.A.S.) se documentan en este archivo de manera cronológica.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y este proyecto se adhiere a [Semantic Versioning (SemVer)](https://semver.org/lang/es/).

---

## [1.1.6] - 2026-09-23

### Novedades Financieras y Deducciones sobre Total Factura
- **Afectación de Novedades sobre Base de Facturación**: Las notas de ajuste y novedades ahora impactan directamente el **Total Factura / Valor General** de la liquidación en lugar del neto posterior, permitiendo que todas las deducciones de ley (aportes a seguridad social en salud, pensión y retenciones en la fuente) se recalculen y apliquen automáticamente sobre la base consolidada ajustada.
- **Sincronización en Todo el Ciclo de Liquidación**: Homologación del cálculo en el registro de ajustes, en la pantalla de aprobación de liquidaciones, en el motor de liquidación y en la generación de comprobantes oficiales en PDF.
- **Módulo Maestro de Novedades**: Incorporación del panel administrativo para la gestión y parametrización centralizada de tipos de novedades contractuales y descuentos institucionales.

### Notificaciones y Respaldo Institucional
- **Copia Automática a Mary Luz Ríos**: Integración en el servicio de correo para que todas las liquidaciones, comprobantes de pago y avisos remitidos por correo electrónico a los médicos especialistas se envíen con copia de auditoría obligatoria a Mary Luz Ríos, garantizando trazabilidad administrativa inmediata.

### Protección y Sanitización de Datos
- **Filtro Preventivo Anti-Errores de Digitación**: Regla automatizada en el motor de cruce bidireccional que detecta cuando el personal de admisiones hospitalarias digita por error el código del procedimiento en la casilla de cantidad.
- **Normalización Automática a Unidad Real**: Se neutralizan cantidades anómalas (como cientos de miles de unidades en un procedimiento individual), asignando automáticamente cantidad 1 y aplicando la tarifa real unitaria pactada en lugar de cifras distorsionadas.

### Transparencia y Experiencia de Usuario (UI/UX)
- **Indicador Dinámico de Monto Filtrado**: Cuando el usuario realiza una búsqueda o filtra la tabla por un concepto médico específico (ej. RX Simples), la tarjeta superior de *Monto a Pagar* aclara explícitamente el subtotal visible y el consolidado global (`Filtrado (X reg.) • Total: $...`).
- **Pastilla Interactiva de Filtro Activo**: Visualización de una etiqueta interactiva `[Concepto: CODIGO x]` junto al contador de registros mostrados, permitiendo identificar de inmediato cualquier filtro aplicado y retirarlo con un solo clic.

### Componentes y Módulos Actualizados
- Módulo de Conciliación y Cruce de Exámenes
- Módulo de Aprobación de Liquidaciones
- Módulo de Notas de Ajuste y Novedades
- Módulo Maestro de Novedades
- Motor Central de Liquidación y Retenciones
- Servicio de Notificaciones y Despacho de Correo Electrónico
- Plantillas de Generación de Comprobantes en PDF
- Manual de Usuario Corporativo

---

## [1.1.5] - 2026-09-21

### Diseño y Experiencia de Usuario (UI/UX)
- **Homologación Visual de Detalle de Liquidación por Sedes**:
  - **Identidad Visual Corporativa Unificada**: Se homologó el diseño del modal de pre-liquidación para igualar con total precisión la interfaz moderna y refinada de la pantalla de aprobación de liquidaciones.
  - **Barra de Encabezado Superior Sólida**: Se implementó la barra superior institucional en azul marino oscuro con el título `DETALLE DE LIQUIDACIÓN: ESTUDIOS REALIZADOS`.
  - **Tarjetas de Sede Homologadas**:
    - Encabezado con icono de sede corporativa, nombre en mayúsculas y botón interactivo para informe detallado.
    - Insignia compacta integrada en la cabecera cuando existen registros no cruzados, eliminando franjas amarillas voluminosas dentro del cuerpo de la tarjeta.
    - Columnas estándar de tabla: Centro de Costo, Cantidad y Valor.
    - Fila inferior formal de Total con la sumatoria de cantidades y montos monetarios en tipografía monoespaciada de alto contraste.
  - **Reubicación de Alerta Lateral**: El banner de advertencia global de registros no cruzados se reubicó en la columna lateral derecha, ubicándose armónicamente sobre la estructura administrativa.
  - **Encabezado de Tabla de Producción por Médico**: Barra superior sólida azul marino con distintivo estilizado de liquidación global.

### Componentes Actualizados
- Módulo de Conciliación de Exámenes
- Control de Versiones del Sistema
- Manual de Usuario y Documentación

---

## [1.1.4] - 2026-09-21

### Diseño y Experiencia de Usuario (UI/UX)
- **Armonización Cromática: Tono Amarillo Mate y Opaco en Modo Claro**:
  - **Reducción de Saturación y Fatiga Visual**: Se reemplazaron los tonos amarillos fluorescentes y de alta saturación que generaban estridencia visual en modo claro.
  - **Paleta Mate y Confortable**: Se implementó una paleta equilibrada de tonos lino, arena cálida y bronce/ocre mate, insignias atenuadas y cifras en ocre profundo de alto contraste.
  - **Banners de Pre-Liquidación y Aprobación**: Fondo arena suave con borde mate de 1px y textos cálidos legibles sin brillo invasivo.
  - **Tarjetas y Detalle de Sedes**: Bordes neutros cálidos, punto indicador ocre no incandescente y franja de alerta suave.
  - **Modo Oscuro Preservado**: Se mantuvo intacta la armonía de alto contraste en modo oscuro.

### Componentes Actualizados
- Módulos de Conciliación y Liquidación
- Pantalla de Aprobación de Liquidaciones
- Directorio de Médicos y Asignación de Procedimientos

---

## [1.1.3] - 2026-09-21

### Novedades y Transparencia Financiera
- **Visualización y Desglose del Concepto de Bonificación por Tomografías**:
  - **Identificación y Aislamiento de Concepto**: La bonificación de tomografías contrastadas por cumplimiento de meta de productividad se desagrega y presenta como un concepto institucional independiente, evitando agrupaciones opacas.
  - **Banner Corporativo de Incentivo**: Al abrir el detalle de una liquidación, se despliega un banner destacado en gradiente dorado/ámbar especificando la regla aplicada, la sede donde se reconoció y el valor monetario adicional.
  - **Fila Destacada por Sede**: En la tabla de centros de costo de cada sede, la bonificación se resalta con distintivo ámbar, indicando la cantidad exacta de bonos alcanzados y el monto reconocido.
  - **Auditoría en Informe Detallado de Sede**: En la vista drill-down por sede, se incluye la bonificación tanto en el resumen de conceptos como en la tabla cronológica de registros.
  - **Exportación en Excel y PDF**: Inclusión de la descripción explícita del incentivo en reportes en hojas de cálculo y documentos oficiales en PDF.

### Componentes Actualizados
- Módulo de Aprobación de Liquidaciones
- Motor de Cálculo y Resumen de Sedes
- Generador de Comprobantes PDF
- Manual de Usuario Corporativo

---

## [1.1.2] - 2026-09-21

### Mejoras de Navegación
- **Apertura de Documentación en Nueva Pestaña**:
  - Se configuraron todos los accesos al Manual de Usuario (panel principal, menú de módulos, menú de perfil y pie de página) para abrir en una pestaña independiente sin interrumpir la sesión o el flujo de trabajo activo en el sistema.

### Componentes Actualizados
- Panel Principal y Barra de Navegación
- Pie de Página Global

---

## [1.1.1] - 2026-09-21

### Mejoras y Refinamiento Visual
- **Limpieza de Barra de Navegación**:
  - Retiro de botones redundantes de la barra superior para preservar una estética limpia y minimalista.
- **Restauración del Banner de Bienvenida**:
  - Eliminación de elementos invasivos en el banner principal del panel de control, devolviéndole su formato limpio y equilibrado.
- **Ubicación Integrada del Manual de Usuario**:
  - Integración como módulo oficial dentro del cajón lateral de herramientas y módulos.
  - Mantenimiento del acceso en el menú de usuario y en el pie de página.

### Componentes Actualizados
- Barra de Navegación Principal
- Panel de Control (Dashboard)

---

## [1.1.0] - 2026-09-21

### Novedades
- **Manual de Usuario Corporativo Interactivo**:
  - Plataforma integral de documentación institucional y guía operativa para la IPS Hernán Ocazionez y Cía S.A.S.
  - Buscador en tiempo real para encontrar secciones y términos clave al instante.
  - Filtro interactivo por perfil de usuario (General, Médico, Financiero, Administrador).
  - Documentación paso a paso de los módulos del sistema: conciliación bidireccional, liquidaciones, notas de ajuste, tarifarios y vigencias temporales, bloqueos, certificados tributarios y control de auditoría.
  - Función de impresión y exportación a PDF para archivo físico o digital.
- **Acceso Rápido y Destacado en Interfaz**:
  - Acceso directo en la barra de navegación con indicador dinámico de versión.
  - Integración en el panel de bienvenida principal y en el pie de página global.

### Mejoras
- **Centralización del Versionado**:
  - Creación de constantes institucionales de versión sincronizadas con el repositorio y el manual de usuario.

### Componentes Actualizados
- Módulo de Documentación Interactiva
- Barra de Navegación y Pie de Página

---

## [1.0.0] - 2026-09-21

### Versión Base Inicial y Consolidación del Sistema

Esta versión establece la línea base formal del sistema **LIHO** bajo control de versiones, integrando los módulos diagnósticos, administrativos, financieros y de conciliación clínica.

#### Cruce Bidireccional de Exámenes y Liquidación
- **Cruce Automático**: Algoritmo de conciliación por pares Fuente-Ingreso con detección de discrepancias y estados de coincidencia.
- **Cálculo de Tarifas y Modalidades**:
  - Detección de tipo de paciente (Entidad vs Particular).
  - Regla especial de incentivo para tomografías contrastadas.
  - Modalidades dinámicas de liquidación porcentual y por valor fijo.
  - Configuración de base de cálculo por valor de liquidación o valor facturado.
  - Control de liquidación por unidad o tarifa única.

#### Tarifarios y Vigencias Temporales
- Catálogo maestro de tarifas por código de procedimiento y entidad de salud.
- Sistema de **Vigencias Temporales** con fechas de inicio y fin, permitiendo resolución histórica exacta de la tarifa aplicable a la fecha del examen.
- Módulo de Tarifarios Especiales por profesional y por procedimiento.
- Bloqueos de tarifas para restringir procedimientos no reconocidos.

#### Gestión Financiera, Aprobaciones y Retenciones
- Pantalla de aprobación de liquidaciones con estados de flujo de trabajo formal.
- Módulo de Notas de Ajuste débito y crédito con trazabilidad de usuario y justificación contable.
- Emisión de Certificados Tributarios oficiales con retención en la fuente en PDF.

#### Seguridad, Auditoría y Entornos
- Cifrado robusto en almacenamiento de credenciales y parámetros de conexión.
- Autenticación con verificación de códigos temporales vía correo electrónico corporativo.
- Asignación granular de roles (Administrador, Financiero, Médico) y permisos por funcionalidad.
- Módulo de protección de entorno para prevenir notificaciones accidentales en pruebas.
- Trazabilidad y auditoría completa de eventos y registros de acceso.

---

## Guía para Nuevas Versiones

Cada vez que se realicen cambios significativos, se añadirá una nueva sección superior respetando esta plantilla:

```markdown
## [X.Y.Z] - AAAA-MM-DD

### Novedades
- Descripción de nuevas funcionalidades o pantallas agregadas.

### Mejoras
- Optimizaciones de rendimiento, mejoras de interfaz o refactorizaciones.

### Correcciones
- Solución de errores reportados o comportamientos inesperados.

### Seguridad y Configuración
- Cambios en políticas de acceso, credenciales o parámetros globales.

### Componentes Actualizados
- Lista de módulos y submódulos intervenidos.
```
