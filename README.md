# LIHO | Sistema de Liquidación de Honorarios Médicos
### Hernán Ocazionez y Cía S.A.S. — Sistemas Diagnósticos

![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue?logo=php)
![Database](https://img.shields.io/badge/Database-SQL%20Server-red?logo=microsoft-sql-server)
![Oracle Servinte](https://img.shields.io/badge/ERP-Servinte%20(Oracle)-orange?logo=oracle)
![Frontend](https://img.shields.io/badge/UI-Tailwind%20CSS-teal?logo=tailwindcss)
![Version](https://img.shields.io/badge/Version-v1.1.5-success)
![Manual](https://img.shields.io/badge/Manual-Corporativo%20Interactivo-teal?logo=gitbook)
![Status](https://img.shields.io/badge/Status-Activo-emerald)

---

## 📋 Descripción General

**LIHO** es la plataforma integral de liquidación de honorarios médicos, facturación diagnóstica y conciliación de exámenes desarrollada para la IPS **Hernán Ocazionez y Cía S.A.S.** 

El sistema realiza el cruce bidireccional entre la información de producción clínica (registrada en **PROTEO / SQL Server**) y el sistema de facturación y admisiones hospitalarias (**SERVINTE / Oracle**), calculando de forma automatizada y transparente los valores exactos a pagar a cada médico especialista según sus modalidades contractuales, vigencias tarifarias y esquemas tributarios colombianos.

Cuenta con un **Manual de Usuario Corporativo Interactivo** accesible en vivo desde el sistema (`manual_usuario.php`) con buscador en tiempo real y filtrado de guías por perfil asistencial y administrativo.

---

## 🚀 Módulos Principales

### 1. 🔄 Cruce Bidireccional de Exámenes (`examenes_medicos.php`)
- Conciliación automática por **Fuente** e **Ingreso** entre PROTEO y Servinte.
- Clasificación de estados: *Cruzados OK*, *Solo en Proteo*, *Solo en Servinte*, *Discrepancias*.
- Detección inteligente de tipo de paciente (**Entidad 'E'** o **Particular 'P'**).
- Reglas avanzadas de liquidación: Bonificación de Tomografías Contrastadas (Regla 50x$150.000 COP), modalidad de Degluciones (45%), Tarifas Especiales (30%) y Pago Dinámico.
- Exportación instantánea a Excel y resúmenes ejecutivos en tiempo real.

### 2. 📊 Gestión de Tarifarios y Vigencias (`tarifario.php`, `historial_tarifario.php`)
- Catálogo maestro de códigos CUPS, descripciones, unidades y valores.
- **Sistema de Vigencias Temporales**: soporte para fechas de inicio (`vigencia_desde`) y fin (`vigencia_hasta`), permitiendo aplicar retroactivamente la tarifa exacta según la fecha en que se realizó el examen médico.
- Gestión de versiones de tarifario con importación masiva por Excel / CSV.

### 3. 🛡️ Tarifarios Especiales y Bloqueos (`tarifario_especial.php`, `tarifario_bloqueos.php`)
- Asignación de tarifas personalizadas por médico o por examen específico.
- Bloqueo de cobros o tarifas restringidas para evitar pagos no autorizados.

### 4. 👨‍⚕️ Gestión de Médicos y Procedimientos (`medicos.php`, `gestion_medicos_procedimientos.php`)
- Directorio de médicos especialistas con control de estado (activo/inactivo), entidad asignada, modalidades de pago y datos tributarios.
- Asignación individual y colectiva de procedimientos y tipos de examen autorizados.

### 5. 💰 Aprobación de Liquidaciones y Ajustes (`aprobacion_liquidaciones.php`, `notas_ajuste.php`)
- Flujo de revisión, aprobación y emisión de preliquidaciones para el equipo financiero y administrativo.
- **Transparencia en Bonificaciones**: Desglose explícito e individualizado del concepto de **Bonificación por Tomografías Contrastadas** (`BONIFICACIÓN TOMOGRAFÍAS`, 50 CT × $150.000 COP) con banner destacado de incentivo, badges institucionales y auditoría por sede.
- Registro de **Notas de Ajuste** (débito/crédito) con auditoría completa de motivos, conciliación de saldos y doble huella criptográfica SHA-256.
- Generación de reportes de liquidación en formato PDF y exportación completa a Excel.

### 6. 📑 Certificados Tributarios (`certificados_tributarios.php`)
- Emisión formal de certificados de retención en la fuente para médicos especialistas.
- Descarga directa en formato PDF oficial de Hernán Ocazionez y Cía S.A.S.

### 7. ⚙️ Maestros de Configuración (`maestro_entidades.php`, `maestro_porcentajes.php`, `maestro_parafiscales.php`)
- Multi-entidad (Hernán Ocazionez, IMADINSA SAS y otras IPS externas aliadas).
- Configuración de porcentajes de pago por modalidad (porcentual o valor fijo).
- Parámetros de aportes parafiscales (IBC 40%, Salud 12.5%, Pensión 16%, ARL).

### 8. 🔐 Seguridad, Roles y Auditoría (`usuarios.php`, `gestion_roles.php`, `logs.php`)
- Control de acceso por roles: **Administrador**, **Financiero**, **Médico**.
- Doble factor de autenticación y envío de tokens temporales de acceso vía SMTP (Gmail).
- Registro estricto de auditoría de acciones (`audit_logger.php`) y logs de acceso (`logs_acceso.php`).
- Módulo de control de entorno: bloqueo de correos a médicos en modo desarrollo.

### 9. ⏰ Alertas Automáticas (`cron_alerta_medicos.php`, `alerta_medicos.php`)
- Proceso automatizado programable para notificación de vencimiento de documentos y vigencias médicas.

---

## 🛠️ Arquitectura y Tecnologías

| Componente | Tecnología |
| :--- | :--- |
| **Backend** | PHP 8.1+ (Programación procedural estructurada y helpers modulares) |
| **Bases de Datos** | Microsoft SQL Server (vía extensión `sqlsrv` / `pdo_sqlsrv`), Oracle (Servinte) |
| **Frontend** | HTML5 Semántico, JavaScript Vanilla / Fetch API, Tailwind CSS, Google Fonts (Montserrat), Material Symbols |
| **Generación de PDF**| FPDF con tipografías corporativas personalizadas |
| **Servidor Web** | Apache (Entorno XAMPP en Windows) |
| **Mailing** | PHPMailer / Conexión SMTP TLS |

---

## 📁 Estructura del Proyecto

```text
LIHO/
├── assets/                     # Recursos visuales (imágenes, logos corporativos, estilos)
├── config/                     # Conexiones cifradas AES-256, SMTP y configuración global
│   ├── conexion.php            # Conexión a la base de datos SQL Server LIHO
│   ├── conexion_external.php   # Conexión a fuentes externas (Servinte/Oracle)
│   ├── config_sistema.json     # Parámetros del sistema (modo desarrollo, redirección)
│   ├── config_smtp.php         # Parámetros de envío de correos SMTP
│   └── security_crypto.php     # Algoritmo de encriptación/desencriptación de credenciales
├── includes/                   # Helpers, lógica de negocio y componentes reutilizables
│   ├── audit_logger.php        # Trazabilidad y auditoría de eventos
│   ├── liquidaciones_helper.php# Motor de cálculo de liquidaciones y retenciones
│   ├── navbar.php              # Menú de navegación principal con control de permisos
│   ├── permisos_helper.php     # Validación de roles y privilegios
│   ├── vigencias_helper.php    # Resolución de tarifas por vigencias temporales
│   └── pdf_*.php               # Plantillas de generación de reportes FPDF
├── uploads/                    # Almacenamiento de archivos y certificados
├── logs/                       # Registros de eventos y tareas programadas
├── scripts/                    # Scripts auxiliares y herramientas de versionado
├── CHANGELOG.md                # Bitácora cronológica de versiones y novedades
└── README.md                   # Documentación principal del sistema
```

---

## 📦 Historial de Versiones y Cambios

Todas las versiones y cambios significativos se documentan de forma ordenada en el archivo:
👉 **[CHANGELOG.md](CHANGELOG.md)**

---

## ⚙️ Instalación y Requisitos

1. **Requisitos del Servidor**:
   - XAMPP con PHP 8.1 o superior en Windows.
   - Controladores de Microsoft SQL Server para PHP (`php_sqlsrv_81_ts_x64.dll` y `php_pdo_sqlsrv_81_ts_x64.dll`).
   - Extensiones PHP activas: `curl`, `mbstring`, `openssl`, `gd`.
2. **Configuración de Base de Datos**:
   - Restaurar o conectar la base de datos `[LIHO]` en Microsoft SQL Server.
   - Ajustar credenciales cifradas en `config/conexion.php`.
3. **Ejecución Local**:
   - Ubicar el proyecto en `c:\xampp\htdocs\LIHO`.
   - Iniciar Apache en el panel de control de XAMPP.
   - Abrir en el navegador: `http://localhost/LIHO`.

---

© 2026 **Hernán Ocazionez y Cía S.A.S.** — Todos los derechos reservados.
