# M11 — Conciliación, pagos y recuperación de línea

M11 delimita la recepción bancaria, la conciliación contra relaciones, el libro
inmutable de aplicaciones, la recuperación de línea por capital y los flujos de
aclaración, excedente y devolución.

## Implementado en este checkout

- Estados cerrados para importaciones, movimientos, aclaraciones,
  conciliaciones manuales, excedentes y devoluciones.
- Dinero decimal exacto con cuatro decimales internos y redondeo aritmético
  final.
- Motor de aplicación en el orden: recargo, interés, seguro, comisión del
  préstamo y capital.
- Cálculo separado de importe aplicado, saldo nuevo, excedente y capital que
  puede recuperar línea.
- Clasificación temporal por fecha bancaria efectiva y evaluación posterior.
- Esquema PostgreSQL para importaciones, filas, reserva única de folio,
  aplicaciones, partidas, recargo idempotente, evaluación posterior,
  aclaraciones, conciliación manual, excedentes, devoluciones, idempotencia y
  auditoría.
- Restricciones de base de datos para sumas monetarias, exclusión del excedente,
  estados permitidos e inmutabilidad de libros.
- API v1 autenticada, paginada y limitada por propiedad, asignación, sucursal o
  alcance global.
- Elección idempotente y concurrentemente protegida de un excedente propio como
  `SALDO_A_FAVOR`; esta decisión no modifica la línea.
- Outbox compartido y auditoría local preparada para M18.

## Contratos deliberadamente bloqueados

La especificación deja sin definir reglas necesarias y M10/M18 no existen en el
checkout. Los proveedores de M11 registran adaptadores que deniegan por defecto:

- `BankFileContract`: extensión, firma, MIME, hoja/sección, encabezados y
  límites del archivo.
- `BankFolioScopePort`: ámbito de unicidad del folio entre bancos.
- `RelationPaymentPort`: normalización M10, relación, partidas, saldos,
  estados e historial autoritativo.
- `PrivateMediaPort`: almacenamiento privado, inmutable y versionado de M18.
- `PaymentAuthorizationPort`: autorización crítica ligada y consumible de M01.
- `PaymentConfigurationPort`: recargo versionado de M03.
- `BankCoveragePort`: cobertura completa de sucursal y fecha operativa.
- `RefundMethodContract`: parcialidad, métodos y campos de devolución.

Por esta razón, carga/procesamiento, conciliación automática o manual,
aclaraciones con evidencia, aplicación de saldo a favor, recargo, evaluación
posterior y devoluciones responden con un error de contrato estable antes de
persistir un estado parcial. No se inventa CSV/XLSX, no se crea una relación
sombra y no se consulta una tabla interna de otro módulo.

## Idempotencia y concurrencia

- Las claves HTTP se conservan como HMAC mediante
  `PAYMENT_IDEMPOTENCY_HMAC_KEY`.
- El folio usa una reserva separada con unicidad
  `folio_scope + normalized_folio`; el ámbito debe llegar del contrato aprobado.
- Las aplicaciones y los movimientos derivados tienen claves únicas.
- Los libros financieros, recargos, evaluaciones, aplicaciones de excedente y
  auditorías son inmutables en PostgreSQL.
- El orden previsto de bloqueo es movimiento/saldo a favor, relación, partidas,
  línea, excedente y autorización. Un adaptador futuro debe conservarlo dentro
  de una única transacción de base de datos.

## API

Los 27 endpoints requeridos están publicados en `docs/openapi.yaml`:

- importaciones y movimientos;
- pagos por relación y aplicación;
- aclaraciones;
- conciliación manual;
- excedentes y devoluciones.

No existen rutas para editar o eliminar movimientos, aplicaciones o libros.

## Integración pendiente

Al integrar M10, su adaptador debe entregar un snapshot bloqueado,
financieramente consistente y una distribución aprobada por partida. Solo
después de persistir la aplicación exacta debe invocarse
`CreditRecoveryGateway::recover` de M07 con el componente de capital. M11 no
reactiva la regla del 50 %, no recupera línea por cargos y no incluye cartera
informativa de M06.
