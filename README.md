# LIHO | Sistema de Liquidación de Honorarios Médicos
### Hernán Ocazionez y Cía S.A.S. — Sistemas Diagnósticos

![Version](https://img.shields.io/badge/Version-v1.1.6-success)
![Manual](https://img.shields.io/badge/Manual-Corporativo%20Interactivo-teal)
![Status](https://img.shields.io/badge/Status-Activo-emerald)

---

## Descripción General

**LIHO** es la plataforma integral de liquidación de honorarios médicos, facturación diagnóstica y conciliación de exámenes desarrollada para la IPS **Hernán Ocazionez y Cía S.A.S.**

El sistema realiza la conciliación automática entre la información de producción clínica y la facturación hospitalaria institucional, calculando de forma automatizada y transparente los valores exactos a pagar a cada médico especialista según sus modalidades contractuales, vigencias tarifarias y esquemas tributarios colombianos.

Cuenta con un **Manual de Usuario Corporativo Interactivo** accesible directamente en la plataforma con buscador en tiempo real y filtrado de guías por perfil asistencial y administrativo.

---

## Módulos Principales

### 1. Cruce Bidireccional de Exámenes
- Conciliación automática por identificadores de fuente e ingreso.
- Clasificación de estados: *Cruzados OK*, *Solo en Sistema Asistencial*, *Solo en Facturación Hospitalaria*, *Discrepancias*.
- Detección inteligente de tipo de paciente (Entidad o Particular).
- Reglas avanzadas de liquidación: Bonificación de Tomografías Contrastadas (Regla de incentivo institucional), modalidades especiales de procedimiento, liquidación porcentual y por valor fijo.
- Exportación directa a reportes ejecutivos en formato digital y hojas de cálculo.
- Filtro inteligente de sanitización preventiva contra errores de digitación en cantidades hospitalarias.
- Resúmenes dinámicos de producción con desglose y trazabilidad de filtros activos por concepto.

### 2. Gestión de Tarifarios y Vigencias Temporales
- Catálogo maestro institucional de códigos de procedimiento, descripciones, unidades y valores.
- **Sistema de Vigencias Temporales**: Soporte para fechas de inicio y finalización de vigencia, garantizando la resolución retroactiva exacta del valor pactado según la fecha en que se realizó el procedimiento médico.
- Gestión histórica de versiones con importación masiva.

### 3. Tarifarios Especiales y Bloqueos
- Asignación de tarifas personalizadas por profesional médico o procedimiento particular.
- Bloqueo de cobros o tarifas restringidas para evitar liquidaciones no autorizadas.

### 4. Directorio Médico y Procedimientos
- Directorio de médicos especialistas con control de estado (activo/inactivo), entidad asignada, modalidades de pago y datos tributarios.
- Asignación individual y colectiva de procedimientos y tipos de examen autorizados por especialista.

### 5. Aprobación de Liquidaciones, Ajustes y Novedades
- Flujo de revisión, aprobación y emisión de preliquidaciones para el equipo financiero y administrativo.
- **Gestión Integral de Novedades**: Registro de adiciones y descuentos que impactan directamente el Total Factura / Valor General, aplicando proporcionalmente las deducciones de ley (salud, pensión y retenciones) sobre la base consolidada ajustada.
- **Transparencia en Bonificaciones**: Desglose explícito e individualizado de incentivos de productividad por tomografías con distintivos institucionales y auditoría por sede.
- Registro de **Notas de Ajuste** (débito/crédito) con auditoría completa de motivos, conciliación de saldos y doble huella criptográfica SHA-256.
- Generación de comprobantes de liquidación oficiales en PDF y reportes detallados en hojas de cálculo.

### 6. Certificados Tributarios
- Emisión formal de certificados de retención en la fuente para médicos especialistas.
- Módulo de autoservicio para consulta y descarga directa de certificados en formato oficial.

### 7. Parámetros Institucionales y Parafiscales
- Soporte multi-entidad para sedes e instituciones aliadas.
- Configuración de porcentajes de pago por modalidad asistencial.
- Parámetros dinámicos de aportes parafiscales (IBC, Salud, Pensión, ARL y retenciones tributarias).

### 8. Seguridad, Roles y Trazabilidad
- Control de acceso basado en roles: Administrador, Financiero, Médico.
- Autenticación segura con verificación por correo electrónico y copia de respaldo institucional (Mary Luz Ríos).
- Registro estricto de auditoría forense para cada inserción, modificación de tarifa o cambio de estado.
- Modo de protección de entorno para prevenir notificaciones accidentales durante mantenimientos.

### 9. Notificaciones y Alertas Automáticas
- Proceso programable para notificación de vencimiento de documentación y vigencias médicas.

---

## Plataforma Tecnológica

| Componente | Capacidad |
| :--- | :--- |
| **Lógica de Negocio** | Arquitectura modular con validaciones financieras estructuradas |
| **Almacenamiento y Datos** | Motores de base de datos relacionales empresariales con índices de alto rendimiento |
| **Interfaz de Usuario** | Diseño web responsivo adaptativo con modos claro y oscuro |
| **Generación Documental** | Motor de renderizado vectorial para comprobantes y certificados en PDF |
| **Comunicaciones** | Servicio seguro de mensajería con cifrado de transporte TLS |

---

## Historial de Versiones y Cambios

Todas las versiones y cambios significativos se documentan de forma ordenada en el archivo:
- **[CHANGELOG.md](CHANGELOG.md)**

---

© 2026 **Hernán Ocazionez y Cía S.A.S.** — Todos los derechos reservados.
