# ADR-001: eliminación definitiva de Puntos

## Estado

Aceptada el 2026-08-17.

## Decisión vigente

Puntos queda eliminado completamente del producto MisVales. Esta decisión sustituye cualquier requisito previo de la fuente canónica que describa acumulación, descuento, saldo, periodos o canje de puntos.

## Alcance aplicado

- Se retiraron rutas, controlador, modelos, servicio, configuración y relaciones runtime.
- Los pagos y la recuperación de línea conservan sus reglas financieras; únicamente dejaron de invocar la clasificación de puntos.
- Las relaciones nuevas ya no escriben `points` en `header_snapshot`.
- Se retiraron permisos, versiones de configuración, reportes, proyección de notificaciones y contrato OpenAPI del módulo.
- Una migración nueva elimina los catálogos y las cuatro tablas detectadas, sin modificar migraciones históricas aplicadas.
- Una prueba de regresión impide que reaparezcan rutas, permisos, configuración, reportes o tablas del módulo.

## Excepción histórica deliberada

No se reescriben los `header_snapshot` de relaciones ya emitidas. Son evidencia histórica inmutable del documento generado en su momento; el campo dejó de producirse para relaciones nuevas y no participa en ningún cálculo ni respuesta runtime nueva.

Los registros inmutables de auditoría que pudieran contener el nombre de un evento histórico tampoco se borran sin una decisión explícita de retención. No conceden capacidades ni reactivan el módulo.

## Datos y despliegue

La migración es destructiva y forward-only. Antes de ejecutarla en un entorno con datos se deben medir filas, generar un respaldo, verificar su restauración aislada y confirmar una ventana de mantenimiento. Revertir solamente el código después de aplicar la migración no restaura datos.

