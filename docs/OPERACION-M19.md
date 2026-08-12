# Operación M19

## Dependencias y topología

Laravel usa PostgreSQL como fuente de verdad para todas las decisiones financieras. Redis se limita a caché, sesiones y colas; una réplica futura solo puede atender reportes explícitamente tolerantes a retraso y nunca feriado, conciliación, pagos, línea, puntos, recargos o morosidad. El workspace administrado `C:\Mis-Vales\docker` ya contiene PostgreSQL, Redis privado, MinIO privado y Prometheus, Loki, Tempo, Alloy y Grafana. Sus cambios locales concurrentes no se modificaron durante M19.

Cloudflare y el balanceador deben terminar TLS y conservar `X-Request-Id`, `X-Correlation-Id`, `traceparent`/`X-Trace-Id` y la IP confiable. Nginx entrega Angular y enruta `/api` a Laravel. Workers y scheduler ejecutan el mismo release y configuración que Laravel. `/api/v1/health/readiness` comprueba PostgreSQL, Redis, storage y heartbeat; `/api/v1/metrics` expone únicamente contadores sin PII para Prometheus.

## Procesos

- Web: `php artisan serve` solo en desarrollo; producción usa PHP-FPM/Nginx.
- Worker: `php artisan queue:work --tries=3 --backoff=5 --timeout=300` bajo supervisor/systemd.
- Scheduler: `php artisan schedule:work`; `operations:heartbeat` permite detectar su ausencia.
- Fallos: `php artisan queue:failed`, revisar causa y `php artisan queue:retry <uuid>` después de corregirla.
- Notificaciones: `notifications:project`, programado cada minuto e idempotente.
- Readiness: el scheduler debe haber registrado heartbeat en los últimos cinco minutos.

La caída del stack de telemetría no se consulta en el camino financiero y no detiene operaciones. Los logs estructurados se persisten con manejo de error fail-open y nunca incluyen body, cookies o secretos.

## Archivos privados

`POST /api/v1/media` almacena primero en `tmp`, valida extensión, MIME real, tamaño máximo de 15 MiB, contexto, propietario, alcance y propósito; luego mueve a una clave UUID privada, vincula la entidad, guarda SHA-256 y audita. `GET /api/v1/media/{id}/download` revalida autorización y entrega `private, no-store`. No existe URL pública permanente.

## Respaldo y restauración verificable

Ejecutar fuera del repositorio para no versionar respaldos:

```powershell
./ops/backup/backup-misvales.ps1 -ComposeDirectory C:\Mis-Vales\docker -DestinationDirectory D:\MisValesBackups -RetentionDays 14
./ops/backup/verify-backup.ps1 -ComposeDirectory C:\Mis-Vales\docker -BackupDirectory D:\MisValesBackups\<marca-utc>
```

El respaldo incluye dump PostgreSQL custom, datos privados MinIO, configuración no secreta y manifiesto SHA-256. La verificación recalcula hashes, crea una base aislada temporal, restaura con `--exit-on-error`, comprueba tablas y elimina la base temporal. Nunca ejecutar restore contra producción. La retención es un parámetro operativo obligatorio; el ejemplo conserva 14 días. Credenciales permanecen exclusivamente en `.env` del stack y no se imprimen.

## Gate de liberación

Ejecutar en testing: `migrate:status`, `route:list`, `test`, `pint --test`, worker vacío, `schedule:list`, heartbeat/readiness, storage y `queue:failed`; frontend ejecuta los scripts reales `npm test` y `npm run build`. Revisar `git diff --check`, secretos, migraciones pendientes, failed jobs y que los seis archivos visuales ajenos permanezcan fuera de commits M08-M19.
