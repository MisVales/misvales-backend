# M07 — Línea de crédito e incrementos

El módulo implementa el contrato de `docs/MisVales-M07-Linea-Credito-Incrementos-Backend.md`.

## Límites

- `Application`: casos de uso transaccionales, consultas y contratos para módulos futuros.
- `Domain`: dinero exacto, agregado de línea, estados, regla del 50 %, errores y eventos.
- `Infrastructure`: persistencia Eloquent, repositorios, mappers y registro del módulo.
- `Presentation`: API v1 autenticada, validación y recursos.

No existe un endpoint para editar saldos o estados. Todo efecto financiero pasa por
`CreditMovementService` y queda respaldado por un movimiento inmutable.

## Integraciones internas

M04 registra la línea mediante `RegisterInitialCreditLine` o publica
`DistributorAccessProvisioned`, que el módulo consume idempotentemente.

Vales debe depender de `CreditVoucherGateway`:

1. `eligibility` consulta saldo y rango.
2. `bindRestriction` reclama transaccionalmente la restricción aplicable.
3. `releaseRestriction` libera un vale cancelado o rechazado.
4. `applyFulfilledVoucher` descuenta capital y consume la restricción.

Pagos debe depender de `CreditRecoveryGateway::recover`. El comando recibido debe
marcar el origen como conciliado y contener únicamente capital ya determinado por el
módulo financiero.

## Reautenticación gerencial

La decisión usa la acción M01 `credit.increase.decision`, ligada a:

- `resource_type`: `credit_increase_requests`
- `resource_id`: UUID público de la solicitud
- `branch_id`: UUID público de la sucursal
- `parameters`: `decision` y `authorized_amount`

El token es de un solo uso y se consume dentro de la misma transacción que la decisión.

## Configuración

- `CREDIT_FIFTY_PERCENT_TOLERANCE`, por defecto `500.0000`.
- `CREDIT_PAGE_SIZE`, por defecto `25`.
- `CREDIT_MAX_PAGE_SIZE`, por defecto `100`.

## Validación

El proyecto requiere PHP 8.4.1 o posterior:

```bash
composer check
```

Las pruebas de persistencia esperan la base PostgreSQL `misvales_testing` configurada
en el entorno. Las pruebas funcionales de M07 también pueden ejecutarse sobre SQLite
en memoria para una comprobación rápida de los servicios y la API.
