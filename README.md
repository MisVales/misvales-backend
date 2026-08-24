# MisVales Backend

Backend oficial del sistema **MisVales**, desarrollado con Laravel.

MisVales administra líneas de crédito asignadas a distribuidoras, generación y validación de vales, clientes finales, relaciones de pago, conciliaciones, categorías, puntos, autorizaciones, auditoría y procesos operativos por sucursal.

La especificación funcional oficial se encuentra en el repositorio `misvales-documentation`, dentro del archivo `MisValesInfo.md`. Ninguna regla de negocio debe implementarse por deducción ni modificarse sin una definición aprobada.

## Responsables

| Función | Integrantes |
|---|---|
| Backend | Daniel y Jorge |
| Líder y QA | Alberto |
| Infraestructura en DigitalOcean | Azael |

## Tecnologías

- Laravel.
- MariaDB.
- Redis.
- Laravel Sanctum.
- Laravel Queue y Laravel Workers.
- OpenAPI para el contrato de la API.
- PHPDoc para documentación dentro del código.

## Responsabilidades del repositorio

- Exponer la API utilizada por las tres aplicaciones Angular.
- Implementar las reglas de negocio confirmadas de MisVales.
- Administrar autenticación, sesiones, permisos por rol y alcance por sucursal.
- Gestionar distribuidoras, clientes finales, prevales y vales digitales.
- Calcular préstamos, relaciones, comisiones, recargos y puntos.
- Gestionar fechas de corte, referencias de pago y conciliaciones.
- Implementar transferencias, reasignaciones y cambios de sucursal autorizados.
- Registrar auditoría, cambios de estado, autorizaciones y eventos relevantes.
- Ejecutar trabajos en segundo plano mediante Redis Queue y Laravel Workers.

## Arquitectura general

```mermaid
flowchart TD
    A[Aplicaciones Angular] --> B[API Laravel]
    B --> C[MariaDB]
    B --> D[Redis]
    D --> E[Laravel Workers]
    E --> F[Correo, PDF, notificaciones y reportes]
```

Los Workers ejecutan código de este mismo repositorio. Redis y los Workers no son aplicaciones ni repositorios independientes.

## Autenticación y sesiones

La autenticación definida para MisVales utiliza Laravel Sanctum en modo stateful:

- Guard `web`.
- Cookie de sesión `HttpOnly`.
- Protección CSRF.
- Middleware `auth:sanctum`.
- Sesiones administradas con Redis.
- Sin JWT almacenados en `localStorage` o `sessionStorage`.

La autorización siempre debe verificarse en el backend. Las validaciones del frontend no sustituyen las Policies, middleware ni controles por rol y sucursal.

## Redis y procesos en segundo plano

Redis se utilizará para:

- Colas.
- Sesiones.
- Rate limiting y sus contadores.
- Tokens temporales de autorización.
- Coordinación de procesos en segundo plano.

Flujo de trabajos asíncronos:

```text
Laravel -> Redis Queue -> Laravel Worker -> Correo / PDF / notificación / reporte
```

## Base de datos

MariaDB es la base de datos principal. El esquema debe administrarse mediante migraciones versionadas dentro de este repositorio.

Reglas obligatorias:

- Utilizar transacciones en operaciones financieras.
- Evitar operaciones duplicadas.
- No eliminar registros financieros históricos.
- No modificar relaciones cerradas sin un proceso autorizado.
- Validar CURP, folios, referencias y números de transacción.
- No hardcodear fechas, porcentajes, puntos, categorías, recargos ni valores de cálculo.
- Aplicar cambios de configuración solamente a operaciones futuras sin alterar el historial.

## Auditoría

La auditoría debe conservar, cuando corresponda:

- Información original y modificada.
- Usuario solicitante, autorizador y ejecutor.
- Fecha, hora, sucursal, sesión o dispositivo.
- Motivo de la modificación.
- Tokens utilizados.
- Conciliaciones manuales.
- Transferencias y reasignaciones.
- Incrementos de línea.
- Cambios de categoría, sucursal o estado.
- Canjes de puntos, alertas de riesgo y decisiones de morosidad.

Los tokens de autorización son de un solo uso, tienen una vigencia exacta de cinco minutos y deben quedar vinculados al usuario, operación, sucursal y registro autorizado.

## Ambientes

El backend se manejará en tres ambientes separados:

- Desarrollo.
- QA.
- Producción.

Azael administra la infraestructura y los despliegues directamente en DigitalOcean Droplets. La infraestructura no forma parte de este repositorio.

## Configuración local

Requisitos:

- PHP.
- Composer.
- MariaDB.
- Redis.

Después de clonar el repositorio:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Para procesar las colas durante el desarrollo:

```bash
php artisan queue:work redis
```

Para ejecutar Reverb localmente:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Realtime con Reverb

Reverb solo transporta invalidaciones. El navegador recibe `notifications.updated` en
`private-user.{user_uuid}` y vuelve a consultar por HTTP la bandeja y el contador. Los
mensajes no contienen modelos, datos personales, montos, permisos ni respuestas de la API.
Todas las escrituras continúan exclusivamente en los endpoints HTTP existentes.

La autorización del canal se publica en `POST /api/broadcasting/auth` y exige Sanctum,
usuario activo, MFA vigente y el rate limit `broadcasting`. El UUID del canal debe ser
exactamente el del usuario autenticado. Los eventos cliente y whispers están deshabilitados.
Las lecturas HTTP disparadas por estas señales usan el límite `realtime_reads` y Angular
consolida señales repetidas antes de volver a consultar.

En producción cada APP ejecuta su propio proceso `reverb:start` detrás del balanceador y
`REVERB_SCALING_ENABLED=true` distribuye los mensajes entre APP1/APP2 mediante el Redis
compartido. No se requieren sticky sessions. El proxy exterior termina TLS/WSS y debe
reenviar `Upgrade: websocket` y `Connection: Upgrade` al puerto interno de Reverb. Los
hosts de origen se declaran explícitamente, sin esquema, en `REVERB_ALLOWED_ORIGINS`;
nunca se acepta `*`.

Horizon atiende primero la cola `realtime` y después `default`. Durante un despliegue deben
reiniciarse de forma supervisada los procesos con `php artisan reverb:restart` y
`php artisan horizon:terminate`; las operaciones de negocio no dependen de que Reverb esté
disponible.

Los valores reales de conexión, credenciales, claves y secretos nunca deben incluirse en Git.

Los valores `*.example.invalid` de `.env.production.example` son marcadores y deben
reemplazarse por los dominios estables del despliegue antes de cachear configuración.
`APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`,
`SESSION_DOMAIN`, `WEBAUTHN_*`, `REVERB_HOST` y `REVERB_ALLOWED_ORIGINS` deben describir
la misma topología HTTPS/WSS y no aceptar comodines.

Horizon y los workers se ejecutan exclusivamente en Linux, WSL2 o un contenedor Linux
con `pcntl` y `posix`. Esas extensiones se instalan y verifican en la imagen/host de
producción; no se emulan en Windows ni se degrada Horizon para satisfacer Composer local.

## Pruebas

Las pruebas unitarias y de integración del backend deben mantenerse dentro de este repositorio.

```bash
php artisan test
```

No se debe afirmar que una funcionalidad está terminada únicamente porque compila. Debe cumplir los requisitos aprobados y pasar la revisión de QA.

## Documentación de la API

El contrato OpenAPI oficial se conserva en `misvales-documentation`. Todo cambio que modifique endpoints, solicitudes, respuestas o errores debe actualizar también el contrato correspondiente.

El código público, clases, servicios y métodos que lo requieran deben documentarse mediante PHPDoc.

## Flujo Git

Ramas autorizadas:

- `main`: código estable de producción.
- `develop`: integración del trabajo aprobado.
- `feature/*`: nuevas funcionalidades.
- `bugfix/*`: correcciones normales.
- `release/*`: preparación de versiones.
- `hotfix/*`: correcciones urgentes de producción.

Reglas obligatorias:

1. Toda tarea debe tener una rama propia.
2. No se permite trabajar directamente en `main` o `develop`.
3. Todo cambio debe ingresar mediante Pull Request.
4. El autor no puede aprobar ni fusionar su propio Pull Request.
5. Los nuevos commits invalidan las aprobaciones anteriores.
6. Todas las conversaciones deben resolverse antes del merge.
7. Solo Alberto, un QA autorizado o el responsable de versión puede realizar el merge.
8. No se permite push directo ni force push sobre ramas protegidas.

## Seguridad

- Aplicar mínimo privilegio.
- Restringir cada operación por rol y sucursal.
- Cifrar las comunicaciones.
- Proteger contraseñas mediante algoritmos de hash seguros.
- No registrar contraseñas, cookies, tokens ni datos personales sensibles en logs.
- Validar archivos de conciliación antes de procesarlos.
- Registrar errores y permitir reintentos controlados.
- Mantener trazabilidad de las operaciones críticas.

## Versionado

El proyecto utiliza versionado semántico:

```text
vMAJOR.MINOR.PATCH
```

- `MAJOR`: cambios incompatibles.
- `MINOR`: funcionalidades compatibles.
- `PATCH`: correcciones compatibles y ajustes menores.
