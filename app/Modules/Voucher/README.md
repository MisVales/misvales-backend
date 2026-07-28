# M08/M09 — Generación, caja y feriado de vales

M08 genera el primer `PREVALE` histórico o un `VALE_DIGITAL`, siempre a partir
del cliente y producto enviados. La sesión determina distribuidora y sucursal;
M03 aporta las versiones publicadas, M05 la categoría vigente, M06 la asociación
del cliente, M07 la línea y la restricción del 50 %, y M14 el bloqueo confirmado
por morosidad. El adeudo informativo del cliente nunca participa en elegibilidad.

La generación:

- exige `Idempotency-Key` y `X-Request-Id`;
- bloquea cliente, perfil operativo, línea y restricción en una transacción;
- no descuenta línea ni crea `VOUCHER_FULFILLED`;
- materializa snapshot y todas las parcialidades con decimal exacto;
- deja el estado en `GENERADO`;
- registra auditoría, historial y `VoucherGenerated` en outbox.

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

## Persistencia compartida M08/M09

M08 es propietario de `vouchers`, del snapshot financiero normalizado y de las
parcialidades. El mismo `VoucherModel` expone a M09 los campos operativos:

- `id`, `folio`, `type`, `status`, `branch_id` y `lock_version`.
- `client_id`, `distributor_id` y `distributor_user_id`.
- `product_id`, `product_version_id`, `capital_amount` y `financial_snapshot`.
- `client_name_snapshot`, `client_name_normalized` y `generated_at`.

La migración de M08 crea la base inmutable antes de que la migración M09 agregue
sus marcas de apertura, liberación, rechazo y feriado.

## Integraciones productivas

`VoucherEligibilityPort` está enlazado con la asociación vigente, distribuidora
activa, producto publicado y cuenta bancaria real. `DistributorProfilePort` de
M06 se resuelve con M05/M01; no existen tablas de proyección temporales de M08.

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
