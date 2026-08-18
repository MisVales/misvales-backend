# Checklist de readiness de producción

## Código y dependencias

- [x] Puntos ausente del runtime, permisos, configuración, reportes y esquema final.
- [x] Revocación Sanctum/AuthSession cubierta con tokens reales.
- [x] Bootstrap de credencial fija eliminado.
- [x] Restricción de `pragmarx/google2fa` compatible con el lock.
- [ ] Suite backend completa verde.
- [ ] Pint y `git diff --check` verdes sobre el resultado final.
- [ ] Auditoría de dependencias ejecutada sin secretos en la salida publicada.

## Configuración efectiva

- [ ] Ejecutar `php artisan app:validate-production` después de cargar la configuración de producción.
- [ ] Confirmar `APP_DEBUG=false`, URL HTTPS, cookie Secure/HttpOnly y rate limiting activo.
- [ ] Confirmar CORS exacto, sin wildcard con credenciales.
- [ ] Confirmar que el disco `private` no se sirve públicamente.
- [ ] Confirmar trusted proxies/hosts con la topología real de Cloudflare/Nginx.

## Datos y operación

- [ ] Medir filas y tamaño de las cuatro tablas de Puntos antes de migrar.
- [ ] Generar respaldo con `ops/backup/backup-misvales.ps1`.
- [ ] Verificar restauración aislada con `ops/backup/verify-backup.ps1` y revisar también objetos privados.
- [ ] Probar migración desde el estado previo soportado y `migrate:fresh --seed` en base aislada.
- [ ] Reiniciar workers y scheduler después de desplegar código y migraciones.
- [ ] Verificar `/up`, readiness, métricas internas y flujos smoke sin exponer detalles públicamente.
- [ ] Observar errores, colas fallidas y outbox después de restaurar tráfico.

## Estado de ejecución

- Estado: en hardening, no listo para producción.
- Rama: `hardening/case-1-production-readiness`.
- Backend base: `fa844ea2258774dc3cebcd82f0d436e5f2e65789`.
- Frontend: administrado en su rama separada.
- Deploy: no autorizado ni realizado.
