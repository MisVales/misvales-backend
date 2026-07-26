# M09 — Caja, modificaciones autorizadas y feriado

M09 procesa en caja los vales generados por M08. No mueve dinero: registra la
evidencia del depósito externo, consume únicamente el capital congelado mediante
M07 y conserva toda la evidencia operativa.

## Operaciones

- Búsqueda y detalle paginados con alcance por sucursal.
- Apertura `GENERADO -> VALIDACION_CAJA`.
- Solicitud `VALIDACION_CAJA -> CORRECCION_PENDIENTE`.
- Autorización o rechazo por coordinador, gerente de sucursal o gerente general.
- Token de un uso, almacenado como HMAC y vigente exactamente cinco minutos.
- Aplicación exclusiva de los campos publicados por
  `CorrectableClientFieldRegistry` de M06.
- Liberación `VALIDACION_CAJA -> LIBERADO`.
- Rechazo terminal desde `VALIDACION_CAJA`.
- Feriado `LIBERADO -> FERIADO` con HMAC único de transacción.
- Idempotencia durable, auditoría, historial y outbox en la transacción principal.

Todas las mutaciones requieren `Idempotency-Key`, `X-Request-Id` y
`lock_version`. La autorización de modificaciones requiere además una
reautenticación de M01 ligada a la solicitud, sucursal, cliente, vale y campos.

## Contrato de integración con M08

El adaptador `VoucherModel` consume la tabla propietaria `vouchers`. La
proyección requerida por M09 contiene como mínimo:

- `id`, `folio`, `type`, `status`, `branch_id` y `lock_version`.
- `client_id`, `distributor_id` y `distributor_user_id`.
- `product_id`, `product_version_id`, `capital_amount` y `financial_snapshot`.
- `client_name_snapshot`, `client_name_normalized` y `generated_at`.

La migración M09 agrega sus marcas de apertura, liberación, rechazo y feriado si
la tabla de M08 ya existe. En el orden final de integración, la migración de M08
debe ejecutarse antes de `2026_07_25_900000_create_voucher_counter_module_tables`.

## Fronteras pendientes de otros módulos

`VoucherEligibilityPort` permanece fail-closed hasta que M05/M08 publiquen la
validación transaccional de asociación vigente, distribuidora activa, producto
permitido y cuenta bancaria. No es un mock productivo. Las pruebas reemplazan
este puerto con una implementación controlada para verificar la orquestación.

M09 ya reemplaza para M06:

- `CashierVoucherAccessPort`.
- `AuthorizedChangePort`.
- `ConfirmedVoucherPort`.

La resolución final de referencias documentales privadas continúa siendo
propiedad de M18 a través del contrato que ya publica M06.

## Configuración

- `VOUCHER_TOKEN_HASH_KEY`.
- `VOUCHER_TRANSACTION_HMAC_KEY`.
- `VOUCHER_IDEMPOTENCY_HMAC_KEY`.
- `VOUCHER_MODIFICATION_TOKEN_BYTES`.

Los secretos deben ser distintos entre sí y nunca se incluyen en respuestas,
logs, auditorías ni eventos.
