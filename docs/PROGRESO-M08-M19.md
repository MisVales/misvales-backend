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
| M10 | COMPLETO | COMPLETO | COMPLETO | APROBADO | COMPLETO |
| M11 | COMPLETO | COMPLETO | COMPLETO | APROBADO | COMPLETO |
| M12 | COMPLETO | COMPLETO | COMPLETO | APROBADO | COMPLETO |
| M13 | COMPLETO | COMPLETO | COMPLETO | APROBADO | COMPLETO |
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
- Validación combinada final M08+M09: APROBADA, 29 pruebas y 138 aserciones; Pint enfocado y `git diff --check` aprobados.
- Commit backend M09: `c949c3d` — `feat: implementar prevales y motor financiero`.
- Commit frontend M09: `19bd82d` — `feat: integrar generación de prevales y vales`.

### 2026-08-12 — implementación y validación M10
- Backend: búsqueda por sucursal, liberación, feriado manual y modificación autorizada de CURP/domicilio, sin integración bancaria inventada.
- Seguridad: permisos por alcance; token criptográfico de 8 caracteres, hash persistido, vigencia de cinco minutos, un solo uso y vínculo con cajero, vale, cliente, sucursal y campos autorizados.
- Persistencia: solicitudes de modificación, transacciones de caja, estados/actores/fechas, unicidad de transacción y bloqueo/versionado contra doble feriado.
- Invariantes: saldo y restricciones se revalidan en la transacción; el usado aumenta y la restricción se consume únicamente al feriar; auditoría y outbox son atómicos.
- Frontend: pantalla de caja/feriado con búsqueda, datos enmascarados, liberación, corrección, confirmación del depósito externo y bandeja de autorizaciones.
- Pruebas backend combinadas M08-M10: APROBADAS, 19 pruebas y 115 aserciones.
- Pruebas frontend M09-M10: APROBADAS, 6 pruebas con jsdom. Build Angular y formato: APROBADOS.
- Prueba humana: el controlador local no conservó la navegación al abrir una pestaña nueva; se registra la limitación sin declarar evidencia visual inexistente.

### 2026-08-12 — implementación y validación M11
- Persistencia: ejecuciones de proceso, relaciones y partidas con unicidad por distribuidora/corte, referencia única y vínculo único de cada parcialidad.
- Corte: comando `relations:generate`, scheduler configurable en `America/Monterrey`, reintentos registrados y selección de todas las parcialidades exigibles de vales ya feriados, incluidos vales antiguos.
- Configuración: día 25, 00:05 y límite inicial de 20 días; periodo anticipado y datos bancarios deben estar publicados/configurados o el proceso falla cerrado y registra el error.
- Snapshots: encabezado operativo, línea/disponible cuando existe fuente, banco, producto, cliente, folio, componentes financieros, totales y estados. Campos sin fuente autoritativa todavía se conservan nulos.
- API/frontend: listado por propiedad/sucursal/global, detalle, referencia copiable, partidas, totales y descarga autorizada; administrador consulta pero no descarga.
- Pruebas backend combinadas M08-M11: APROBADAS, 22 pruebas y 130 aserciones. Migración limpia y rutas API: APROBADAS.
- Pruebas frontend M09-M11: APROBADAS, 8 pruebas. Build Angular y verificaciones de formato: APROBADOS.

### 2026-08-12 — implementación y validación M12
- Importación: XLSX manual, almacenamiento privado, hash único, usuario/sucursal/fecha/resultado/filas, fila original y error persistidos; ninguna integración bancaria.
- Validación atómica: tipo XLSX, archivo legible y cinco columnas obligatorias; estructura incompleta rechaza el archivo completo y registra motivo.
- Conciliación: folio bancario único; referencia exacta; abono, liquidación, excedente y no conciliado; solo el importe aplicable altera saldo y el excedente queda separado.
- Flujo manual: aclaración con evidencia privada, solicitud de cajera, autorización por alcance con prohibición de autoautorizar y ejecución posterior con snapshots antes/después.
- Frontend: carga XLSX, contrato visible, progreso, resumen por clasificación e historial; navegación protegida por permisos efectivos.
- Pruebas backend combinadas M08-M12: APROBADAS, 24 pruebas y 138 aserciones; incluyen XLSX real, columna ausente, doble archivo, abono y referencia inexistente. Migración limpia aprobada.
- Pruebas frontend M11-M12: APROBADAS, 3 pruebas. Build Angular y formato: APROBADOS.

### 2026-08-12 — implementación y validación M13
- Libro financiero: pagos y allocations inmutables por relación/partida/componente, con un único allocation por movimiento bancario.
- Orden: recargo, interés, seguro, comisión y capital; ganancia de categoría no reduce saldo exigible ni recupera línea.
- Recuperación: solo capital aplicado reduce `used_balance`, nunca debajo de cero; movimiento `PAYMENT_RECOVERY` y saldo materializado se guardan en la misma transacción.
- Determinismo: partidas ordenadas por vencimiento, folio y número; reintento del mismo movimiento es rechazado sin doble aplicación.
- Liquidación: momento preciso y clasificación EARLY/ON_TIME/LATE conforme a periodo anticipado y fecha límite congelados.
- Frontend: detalle de pagos, allocations, línea recuperada, saldo y comportamiento dentro del estado de cuenta.
- Caso numérico validado: pago 1000 = interés 200 + seguro 25 + comisión 250 + capital/recuperación 525; used_balance 5000 → 4475.
- Pruebas M13: APROBADAS dentro de 10 pruebas/65 aserciones del flujo M10-M13; frontend 3 pruebas y build Angular aprobados.

## Checkpoint actual
Módulo: M14
Actividad: auditoría inicial de recargos, excedentes, saldos a favor y devoluciones.
Último archivo modificado: docs/PROGRESO-M08-M19.md
Último comando ejecutado: commits M09 enfocados en backend y frontend.
Resultado: backend `c949c3d`; frontend `19bd82d`. Los seis cambios visuales preexistentes permanecen sin stage ni commit.
Siguiente paso exacto: implementar recargo único, ledger de excedentes y devolución autorizada sin exceder la línea total.
