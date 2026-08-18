# Runbook de release y recuperación

Este procedimiento prepara el release; no autoriza un despliegue real.

## Antes de la ventana

1. Confirmar SHA y que la rama integrada esté limpia.
2. Ejecutar suite, Pint, `composer validate`, auditoría de dependencias y revisión de secretos.
3. Cargar la configuración efectiva y ejecutar `php artisan app:validate-production`.
4. Medir sin exportar datos sensibles:
   - filas de `point_accounts`, `point_movements`, `point_redemption_requests` y `redemption_periods`;
   - permisos cuyo módulo/código sea `points`;
   - definiciones/versiones de las cuatro configuraciones retiradas;
   - tamaño de tablas y espera de locks esperable.
5. Crear el respaldo con `ops/backup/backup-misvales.ps1` y verificar una restauración aislada con `ops/backup/verify-backup.ps1`. La verificación actual de PostgreSQL debe complementarse con comprobación de objetos privados de MinIO.

## Orden de release

1. Activar la estrategia de mantenimiento o drenado definida por la infraestructura real.
2. Publicar el artefacto construido desde el SHA aprobado.
3. Ejecutar `composer install --no-dev --classmap-authoritative --no-interaction` en el artefacto.
4. Ejecutar `php artisan app:validate-production`.
5. Ejecutar `php artisan migrate --force`. La eliminación de Puntos requiere una ventana porque toma locks exclusivos.
6. Ejecutar `php artisan optimize`.
7. Reiniciar o recargar workers de cola y el proceso que ejecuta el scheduler.
8. Verificar liveness, readiness y métricas desde su red autorizada.
9. Ejecutar smoke tests de login/MFA, identidad, revocación de otra sesión, un flujo de lectura por rol y ausencia de rutas de Puntos.
10. Restaurar tráfico gradualmente y observar logs correlacionados, fallos de cola, outbox y latencia.

## Rollback y forward-fix

- La migración que elimina Puntos no tiene `down`: una reversión de código por sí sola es incompatible con el esquema ya migrado.
- Antes de migrar, un rollback puede volver al artefacto previo sin tocar datos.
- Después de migrar, elegir explícitamente una de estas rutas:
  1. mantener el código nuevo y aplicar una migración forward-fix;
  2. detener tráfico, restaurar el respaldo verificado completo y después volver al artefacto previo.
- No recrear tablas vacías como supuesto rollback: eso no recupera saldos, movimientos ni solicitudes eliminadas.
- Las relaciones y cálculos financieros ajenos a Puntos no se revierten ni recalculan por este cambio.

## Evidencia a conservar

Registrar SHA, hora, operador, resultado del backup/restore, salida sin secretos del validador, migraciones aplicadas, salud, smokes y decisión de restaurar tráfico.
