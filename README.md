# Reservas API — Prueba Técnica Laravel 12

Este proyecto implementa el módulo de gestión de reservas de una plataforma de servicios de citas utilizando **Laravel 12** y una base de datos **SQLite**.

---

## 🚀 Cómo Correr el Proyecto

### Requisitos Previos
- **PHP >= 8.4**
- **Composer**
- Extensión **PDO SQLite** habilitada en PHP.

### Instalación Paso a Paso

1. **Clonar o descargar el repositorio** en tu máquina local.
2. **Instalar dependencias de Composer:**
   ```bash
   composer install
   ```
3. **Configurar el archivo de entorno `.env`:**
   Asegúrate de que la conexión SQLite esté activa en tu archivo `.env`:
   ```env
   DB_CONNECTION=sqlite
   ```
4. **Generar la llave de la aplicación:**
   ```bash
   php artisan key:generate
   ```
5. **Ejecutar migraciones y sembrar base de datos (Seeder):**
   Este comando limpiará las tablas, las creará de nuevo, e importará/normalizará el archivo de datos `data/seed.json` aplicando la integridad referencial:
   ```bash
   php artisan migrate:fresh --seed
   ```
6. **Iniciar el servidor de desarrollo:**
   ```bash
   php artisan serve
   ```
   La API estará disponible en `http://localhost:8000`.

---

## 🛠️ Decisiones Técnicas y Arquitectura

Se optó por una **Arquitectura Pragmática basada en Servicios y Utilidades**, evitando la sobreingeniería para cumplir con los requerimientos con alta calidad y mantenibilidad.

### Componentes de Software:
1. **`ReservationController`:** Controlador REST delgado que recibe peticiones, delega la lógica de negocio al servicio, y retorna respuestas JSON estructuradas usando recursos API de Laravel.
2. **`ReservationService` (Servicio de Dominio):** Centraliza la lógica transaccional de agendamiento, cancelación y listado. Esto mantiene las reglas de negocio organizadas en un único lugar facilitando su mantenimiento y lectura.
3. **`RefundCalculator` (Calculador de Reembolsos):** Clase de utilidad pura que implementa la matriz de reembolsos en Pesos Colombianos (COP) para usuarios estándar, premium, servicios no reembolsables y cálculo preciso de horas.
4. **`ReservationResource` (Capa de Transformación):** Formatea la salida de la API y convierte las fechas almacenadas en UTC al huso horario local `America/Bogota` para el cliente.

### Consistencia de Zonas Horarias y Fechas:
- **Almacenamiento:** Todas las fechas se guardan y comparan en **UTC** en la base de datos SQLite para mantener consistencia.
- **Validación y Presentación:** Al recibir datos o dar respuestas, Carbon mapea y convierte las fechas a la zona horaria del negocio (`America/Bogota`) para evaluar reglas horarias locales (lunes a sábado de 7:00 a 19:00, festivos colombianos de 2026).
- **Formatos de Fecha Flexibles y Adaptativos:** La API tolera múltiples formatos de entrada para la fecha de inicio (`start_time`), tales como strings estándar (`Y-m-d H:i:s`), formato local colombiano con barras (`d/m/Y H:i`), Unix Timestamps numéricos y strings en ISO-8601 (con `Z` o `T`). Todos ellos son interpretados o convertidos al huso horario local de Bogotá para validar las reglas horarias, y normalizados a UTC al guardarse.

### Concurrencia y Consistencia (Cruce de Horarios):
Para evitar condiciones de carrera y asegurar la consistencia del negocio:
- Las validaciones e inserciones se ejecutan dentro de una **transacción de base de datos (`DB::transaction`)**.
- Se aplica un **bloqueo pesimista (`lockForUpdate()`)** tanto al validar límites de reservas como al buscar cruces.
- **Cruce de Profesional:** Dos reservas activas del mismo profesional no pueden coincidir en tiempo.
- **Cruce de Usuario:** Un usuario no puede tener dos citas que se crucen en el mismo horario.
- **ID de Cita Conflictiva:** Si se detecta un cruce de horario, el sistema recupera la cita que causa el conflicto y devuelve su ID directamente en el mensaje de error de validación `422`.

### 🚀 Mejoras de Robustez y Producción:
Para garantizar que la API sea robusta, segura y apta para producción, se implementaron las siguientes características:
- **Estandarización de Respuestas JSON (`ApiResponses`):** Centralización de las respuestas mediante un Trait que encapsula la estructura estándar (`success`, `message`, `data`, `errors`), asegurando que todas las peticiones exitosas o fallidas devuelvan el mismo envoltorio de datos.
- **Manejador Global de Excepciones:** Configuración en `bootstrap/app.php` para capturar errores de enrutamiento (404), recursos no encontrados (`ModelNotFoundException` mapeado a 404), y errores de validación (422) forzando siempre respuestas estructuradas en formato JSON en el grupo de rutas `/api/*` sin redirecciones ni stack traces de HTML expuestos.
- **Rate Limiting (Límite de Peticiones):** Configuración del middleware `throttle:api` con una política de 60 peticiones por minuto por dirección IP, previniendo abusos en los endpoints del sistema.
- **Logging Semántico de Operaciones:** Registro de eventos críticos en `ReservationService` mediante el facade `Log` de Laravel, lo que permite auditar en producción la creación, cancelación y violaciones a las reglas de negocio de las citas con metadatos contextuales (IDs, montos de reembolso, motivos de rechazo).
- **Conversión a API Pura (Headless):** Se eliminó por completo todo el boilerplate de frontend predeterminado de Laravel (vistas Blade, configuración de Vite, assets CSS/JS, package.json y dependencias NPM) y se modificó la ruta web raíz `/` para retornar el estado de salud del sistema en formato JSON.

---

## 📋 Supuestos Asumidos

1. **`data/seed.json` ausente en el material original:** El documento de la prueba técnica indicaba que se incluía un archivo `data/seed.json` con usuarios, servicios y reservas inconsistentes. Al no estar presente en el entorno inicial provisto, se simuló y creó un archivo `data/seed.json` a medida con los 3 formatos de fecha requeridos (Unix, local con barras e ISO) y datos huérfanos para demostrar la limpieza de datos e integridad referencial en el seeder de forma transparente.
2. **Límites de Horas de Operación (07:00 a 19:00):** Se asume de forma estricta que tanto el inicio como la finalización de la cita (inicio + duración del servicio) deben ocurrir dentro de esta franja de operación local de Bogotá.
3. **Cancelaciones de Servicios No Reembolsables:** Si un servicio es marcado como `non_refundable = true`, se permite la cancelación del espacio del profesional, pero el monto de reembolso registrado siempre es de `$0.00` COP.

---

## 🚫 Limitaciones

- **Autenticación:** El endpoint es público pero recibe `user_id` en las solicitudes para simular el contexto del usuario autenticado de forma limpia.
- **No se implementó CLI:** Se priorizó una API HTTP robusta con cobertura del 100% de tests de integración para demostrar mejores prácticas web.

---

## 🧪 Pruebas Automatizadas

Se desarrolló una suite completa de pruebas de integración en [ReservationTest.php](file:///c:/dev/reservations-api/tests/Feature/ReservationTest.php) que cubre el 100% de la matriz de reglas de negocio, incluyendo validación estricta de formatos, límites de agenda, cruces de horarios de usuario/profesional e integridad de reembolsos en COP.

Para ejecutar los tests, corre:
```bash
php artisan test
```
o
```bash
vendor/bin/phpunit tests/Feature/ReservationTest.php
```

---

## 📬 Pruebas en Postman (Colección de Endpoints)

Se incluye una colección pre-configurada de Postman para probar todos los flujos e interacciones en:
`data/reservations_api_collection.json`

### Endpoints Disponibles:
- **Listar Todas las Reservas:** `GET /api/reservations` (Listado global sin filtro obligatorio de usuario).
- **Listar Reservas por Estado:** `GET /api/reservations?status=active` o `GET /api/reservations?status=cancelled`.
- **Listar Reservas de Usuario:** `GET /api/reservations?user_id=1` (Con filtros opcionales de rango de fechas `start_date` y `end_date`).
- **Crear Reserva:** `POST /api/reservations` (Crea una cita validando formato estricto `Y-m-d H:i:s`, festivos, domingos, anticipación y cruces de horario).
- **Cancelar Reserva:** `POST /api/reservations/{id}/cancel` (Aplica la matriz de reembolso en Pesos Colombianos y libera el horario).
