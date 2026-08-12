# Progreso de implementación — M08 a M19

Última actualización: 2026-08-12 02:42:00 -06:00
Estado global: EN EJECUCIÓN — M08 validado; preparando checkpoints y transición a M09

## Estado inicial Git
Backend branch: develop (alineada con origin/develop)
Backend SHA inicial: 21e101b533f8684e7a7cd3982943b1fef578f8c5
Frontend branch: develop (alineada con origin/develop)
Frontend SHA inicial: 332d7f187d855cca67e95f8386097ca7cb009097
Frontend cambios locales preexistentes: login.html, dashboard.html, admin-layout.component.css, admin-layout.component.html, sidebar.component.css y styles.css; preservar y no sobrescribir.

## Módulos
| Módulo | Backend | Frontend | Integración | Tests | Estado |
|---|---|---|---|---|---|
| M08 | COMPLETO | COMPLETO | COMPLETO | APROBADO | COMPLETO |
| M09 | COMPLETO | COMPLETO | COMPLETO | APROBADO | COMPLETO |
| M10 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M11 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M12 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M13 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M14 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M15 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M16 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M17 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M18 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |
| M19 | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE | PENDIENTE |

## Registro

### 2026-08-12 — inicio
- Archivos creados: `docs/PROGRESO-M08-M19.md`.
- Comandos: estado/branch/SHA/remotos, `git fetch --all --prune` y comparación con `origin/develop` en ambos repositorios.
- Resultado: ambos repositorios están en `develop` y alineados con remoto; backend limpio; frontend contiene seis archivos modificados preexistentes que deben preservarse.
- Decisiones: trabajar directamente en `develop`, con commits estables por módulo; no publicar credenciales locales ni alterar seguridad productiva.

### 2026-08-12 — auditoría inicial M08
- Backend encontrado: modelos `LineaCredito`, `MovimientoLineaCredito`, `RestriccionUsoCredito`, `SolicitudIncrementoLinea`, servicios de consulta/creación/preautorización/decisión, Policies, Resources, nueve rutas API y migraciones específicas M08.
- Frontend encontrado: servicio `CreditoApiService`, rutas y páginas iniciales de líneas e incrementos dentro de la feature `distribuidoras`; todavía son vistas de consulta y no cubren el flujo vertical requerido.
- Primer comando: `php artisan test tests/Feature/Credito/CreditIncreaseApiTest.php`.
- Primer resultado: ERROR, 3 pruebas sin ejecutar por PostgreSQL apagado en `127.0.0.1:5432`; base `misvales_testing` inaccesible.
- Corrección de entorno: ejecutado `docker compose up -d` desde `C:\Mis-Vales\docker`; PostgreSQL, Redis, MinIO y servicios auxiliares iniciaron correctamente.
- Segundo comando: `php artisan test tests/Feature/Credito/CreditIncreaseApiTest.php`.
- Segundo resultado: APROBADO, 3 pruebas, 18 aserciones, 8.132 s.
- Pendientes M08: completar UX distribuidora/coordinador/gerente/admin, acciones API y pruebas faltantes de invariantes, alcance, concurrencia, restricciones e historial.

### 2026-08-12 — cierre técnico M08
- Backend modificado: `LineaCreditoResource` ahora deriva capacidades de permisos efectivos, manteniendo al administrador en solo lectura; eliminada `ServicioActivacionRestriccionCredito`, implementación sin consumidores cuya base de restricción era el incremento aislado y contradecía la regla de nueva línea total.
- Backend tests: añadido caso explícito que comprueba capacidades de solo lectura del administrador.
- Frontend modificado: `CreditoApiService`, su spec, rutas M08, páginas de líneas/incrementos y permisos del menú.
- Frontend funcional: consulta propia para distribuidora, consulta por alcance para personal, resumen con porcentaje/rango, solicitud idempotente, detalle, preautorización, rechazo operativo, decisión total/parcial/rechazo y mensajes de error sin calcular dinero como fuente de verdad.
- Autorización frontend: rutas y navegación usan los permisos `credit_lines.*` y `credit_increase_requests.*`; las acciones se muestran solo con `capabilities` retornadas por Laravel.
- Comando backend: `php artisan test tests/Unit/Credito tests/Feature/Credito`.
- Resultado backend: APROBADO, 17 pruebas y 75 aserciones.
- Formato backend: Pint enfocado APROBADO y `git diff --check` APROBADO.
- Comando frontend: `npx vitest run --environment jsdom src/app/features/distribuidoras/data-access/api/credito-api.service.spec.ts`.
- Resultado frontend: APROBADO, 5 pruebas.
- Comando frontend: `npm run build`.
- Resultado frontend: APROBADO; bundle Angular generado.
- Prueba humana local: autenticación con la cuenta técnica local indicada, acceso a `/distribuidoras/lineas-credito` y `/distribuidoras/incrementos-linea`, estados vacíos reales y sin errores de consola.
- Nota de datos: la cuenta técnica tiene alcance global, pero el ambiente local no contiene líneas ni solicitudes M08; las mutaciones se validaron mediante pruebas HTTP con factories para los actores requeridos.
- Commit backend M08: `ce0c238` — `feat: completar integración de líneas de crédito`.
- Commit frontend M08: `3e3354f` — `feat: integrar líneas de crédito e incrementos`.
- Publicación M08: PENDIENTE. `git push origin develop` falló en ambos repositorios porque no existe conectividad a `github.com:443` desde la sesión actual; no se modificó historial ni remoto.

### 2026-08-12 — implementación y validación M09
- Estado inicial: no existían tablas, modelos, endpoints, servicios, pruebas ni feature Angular de vales; se reutilizaron M03 catálogos versionados, M06 clientes/asignaciones y M08 línea/restricción.
- Migración: `2026_08_12_000001_create_voucher_module_tables.php`; crea secuencia de folios, bloqueos operativos por morosidad, vales, snapshot, parcialidades, claves/índices/checks/FK y protección contra eliminación física.
- Backend: enums, modelos, excepción de dominio, calculador decimal, servicio transaccional, Policy, Request, Resource y `ValeController`.
- Endpoints: catálogo elegible, preview autoritativo, generación idempotente, listado por alcance y detalle; no existe endpoint genérico de cambio de estado.
- Reglas cubiertas: PREVALE global, VALE_DIGITAL posterior y tras transferencia, distribuidora activa, morosidad, asociación vigente, producto publicado/activo, categoría vigente, línea, regla 50 %, folio PostgreSQL no reutilizable, snapshot y parcialidades con residuo en la última.
- Contrato: `docs/openapi.yml` ampliado con códigos de dominio, schemas y cinco operaciones M09.
- Pruebas backend M09: APROBADAS, 12 pruebas y 63 aserciones; incluyen cálculo, precisión, residuo, primer/segundo vale, transferencia, 20 folios únicos, morosidad, adeudo informativo no bloqueante, producto, saldo, propiedad, regla 50 %, snapshot y administrador solo lectura.
- Migración limpia PostgreSQL: APROBADA hasta M09. Se corrigieron creación idempotente de secuencia y función de protección para convivir con `migrate:fresh`.
- Pruebas combinadas M08+M09 previas a los dos casos finales: APROBADAS, 27 pruebas y 113 aserciones.
- Frontend: nueva feature `vales`, cliente HTTP, rutas con guard, navegación por permisos, búsqueda de cliente, producto, preview financiero, explicación de rango, confirmación, folio, siguiente paso e historial.
- Frontend tests combinados M08+M09: APROBADOS, 8 pruebas.
- Build Angular: APROBADO.
- Prueba humana: sesión técnica local autenticada como gerente general; `/vales` carga en modo consulta, muestra estado vacío real y no expone el formulario de distribuidora. Las mutaciones se validaron con actors/factories porque la cuenta técnica debe conservar separación de funciones.
- Incidencia local: la migración limpia eliminó roles del ambiente compartido; se restauraron exclusivamente con `RolesAndPermissionsSeeder` y la sesión técnica volvió a autenticar. No se crearon credenciales nuevas.

## Checkpoint actual
Módulo: M09
Actividad: revisión final, commits estables y registro de SHAs.
Último archivo modificado: docs/PROGRESO-M08-M19.md
Último comando ejecutado: pruebas enfocadas M09 después de casos de transferencia y reserva de 20 folios.
Resultado: APROBADO, 12 pruebas y 63 aserciones.
Siguiente paso exacto: ejecutar validación combinada final M08+M09, revisar diffs, crear commits M09 enfocados y comenzar auditoría M10.
