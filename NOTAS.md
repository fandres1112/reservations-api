# NOTAS.md — Informe de Uso de Asistente de IA

Este documento describe con transparencia la colaboración con el asistente de Inteligencia Artificial (Antigravity) para el desarrollo de esta prueba técnica, detallando qué partes fueron generadas, qué partes requirieron reescritura manual, y las justificaciones de diseño correspondientes.

---

## 🤖 Informe de Colaboración con IA

### 1. Partes generadas con asistencia de IA
- **Diseño del Esquema de Base de Datos:** Estructura inicial de migraciones para `users` (con flag `is_premium`), `services`, `professionals` y `reservations`.
- **Configuración de Festivos:** Registro de los 18 días festivos nacionales de Colombia para el año 2026.
- **Scaffolding de Controladores y Recursos:** Plantillas base para `ReservationController`, `ReservationResource` y los `FormRequests` de validación sintáctica.
- **Suite de Pruebas Unitarias/Integración:** Diseño inicial de los casos de prueba base en `tests/Feature/ReservationTest.php`.

### 2. Partes ajustadas y reescritas manualmente durante el refinamiento
- **Lógica de Zonas Horarias y Carbon (Seeder vs API):** 
  Durante la importación de datos semilla, las fechas locales de Bogotá en `seed.json` se estaban guardando de forma directa en SQLite sin convertirse previamente a UTC. Esto causaba un desfase doble de 5 horas al ser convertidas nuevamente para el cliente en el API Resource.
  *Ajuste:* Se reescribió `JsonDataSeeder.php` para normalizar todas las fechas (inicio, fin y cancelación) a UTC antes de guardarlas, alineándolas con el comportamiento del servicio.
- **Cruce de Horarios de Usuarios (Regla Implícita):**
  La especificación técnica sólo mencionaba evitar el solapamiento del profesional. Sin embargo, en un sistema real, un usuario no puede estar en dos citas diferentes al mismo tiempo.
  *Ajuste:* Se añadió una validación en `ReservationService.php` para impedir que un usuario registre citas cruzadas (concurrentes), y se creó la prueba automatizada `test_cannot_cross_user_reservations`.
- **Mensaje de Error Detallado con ID de Conflicto:**
  Se modificó la lógica de búsqueda de cruces (tanto para profesional como para usuario) cambiando el método `exists()` por `first()`. De este modo, si ocurre un cruce de horario, el sistema recupera la cita en conflicto e inserta dinámicamente su ID en el mensaje de error de validación `422`.
- **Parseador de Fechas Flexible Adaptativo (API y Seeder):**
  Para cumplir con el requerimiento de tolerar inconsistencias en los formatos de fecha, implementamos un parseador adaptativo (`parseDateTime`) en la capa de servicios que soporta de manera nativa strings estándar (`Y-m-d H:i:s`), formatos locales con barras (`d/m/Y H:i`), timestamps Unix numéricos e ISO-8601 con zona horaria UTC (con `Z`).
  *Ajuste:* Las fechas recibidas se parsean adaptativamente, se interpretan o convierten a la zona horaria del negocio `America/Bogota` para evaluar las reglas locales de operación, y se normalizan a UTC al guardarse en base de datos. Para demostrar esta robustez, restauramos 2 formatos inconsistentes en `seed.json` (ID 3 en Unix y ID 4 en formato de barras) y añadimos el test unitario `test_can_create_reservation_with_alternative_date_formats` que valida este soporte en el API.
- **Visualización Global y Filtros de Estado:**
  Originalmente, el listado de reservas requería obligatoriamente el `user_id`. Se modificó la validación del request para hacer `user_id` opcional, lo que habilitó la consulta de la lista completa de reservas del sistema. Adicionalmente, se incorporó un filtro de estado opcional (`status=active` o `status=cancelled`).
- **Conversión de Moneda a Pesos Colombianos (COP):**
  Se reajustó la escala de precios y aserciones de reembolso del sistema. Los servicios base de la semilla se actualizaron a tarifas realistas en COP ($50,000, $100,000 y $150,000 COP) y se re-calibraron las proporciones de reembolso (100% y 50%) en la suite de pruebas unitarias.

### 3. Justificación de Decisiones Técnicas
- **Simulación de `data/seed.json` (Archivo ausente en la prueba original):** Se hace constar que en el material inicial recibido para la prueba técnica no se incluyó el documento `data/seed.json` referenciado. Por lo tanto, se diseñó y creó un archivo de datos semilla `data/seed.json` propio con usuarios, servicios, profesionales y registros de reservas que incluyera inconsistencias lógicas intencionales para poder desarrollar, testear y demostrar la robustez de la importación y la normalización de la API de forma completa.
- **Decisión de Simplificar el Plan:** La propuesta inicial incluía el patrón *Strategy* y *Actions* desacoplados. Se decidió simplificarlo a un servicio centralizado (`ReservationService`) y una utilidad pura (`RefundCalculator`). Esta decisión ahorró aproximadamente 2 horas de codificación y pruebas innecesarias, entregando un código limpio y legible sin complejidades artificiales.
- **Integridad Referencial en Datos Inconsistentes:** Para cumplir con el requerimiento de detectar y controlar inconsistencias en nuestro `seed.json` de manera profesional, el seeder descarta de forma controlada registros huérfanos (como la reserva ID 7, la cual apuntaba al servicio inexistente ID 99) emitiendo advertencias claras en consola en lugar de permitir inserciones corruptas o romper la base de datos.
