# M13 — Puntos y canjes

M13 es propietario de la cuenta de puntos, el saldo materializado, el libro
inmutable, las evaluaciones por relación, las reservas y el ciclo de canje.
Solo administra puntos de usuarios con rol `DISTRIBUTOR`.

## Fronteras

- M03 conserva las versiones publicadas de divisor, multiplicador, valor por
  punto y porcentaje de penalización. M13 usa el contrato
  `ConfigurationReadContract`; no duplica parámetros.
- La tabla `redemption_periods` creada por M03 se amplía y se reutiliza. No
  existe un segundo calendario de canjes.
- M10 debe entregar `products_capital_basis`, ventana anticipada y snapshots
  por `RelationPointSource`. M13 no selecciona vales ni suma parcialidades.
- M11 entrega clasificación definitiva, fecha efectiva, versión financiera y
  evidencia de saldo liquidado. M13 no concilia ni reclasifica pagos.
- M12 no produce puntos sin una clasificación financiera definitiva aprobada.
- M14–M18 consumen eventos versionados del outbox compartido.

Hasta que M10 publique su integración, el adaptador productivo es
`UnavailableRelationPointSource` y la recuperación falla cerrada.

## Fórmulas y precisión

```text
unidades = floor(products_capital_basis / divisor_snapshot)
puntos_generados = unidades * multiplier_snapshot

puntos_penalizados = floor(total_points_before * penalty_rate_snapshot)
```

Los puntos son enteros. Los importes usan decimales de cuatro posiciones en
persistencia y dos en presentación. El saldo disponible siempre es
`total_points - reserved_points`. Una penalización se bloquea si invalidaría
reservas activas.

## Atomicidad y bloqueo

El orden estable es: evidencia/versión financiera, cuenta, evaluación,
solicitud, reserva y movimiento. Generación, penalización, decisión y
liberación se ejecutan en transacciones. `relation_id`, `source_event_id` y los
orígenes del libro tienen restricciones de unicidad; los reintentos devuelven
el resultado ya conservado.

La reconciliación compara el saldo con `signed_points` y las reservas
materializadas con reservas `ACTIVE`. Una diferencia genera auditoría y outbox;
no se corrige automáticamente.

## API y decisiones pendientes

Las rutas habilitadas están bajo `/api/v1` y exigen sesión. Los listados están
paginados y aplican propiedad/sucursal antes de filtros.

Dos mutaciones no se publican deliberadamente:

- `POST /me/point-redemptions`: falta definir canje total o parcial, mínimos y
  máximos. El caso de uso existe como contrato fail-closed con
  `REDEMPTION_POLICY_UNDEFINED`.
- `POST /point-redemptions/{id}/complete`: falta definir el rol ejecutor,
  método y campos obligatorios de entrega. El contrato devuelve
  `POINT_DELIVERY_ROLE_UNDEFINED`.

No hay cancelaciones, ajustes manuales, reversos automáticos, periodo fijo en
diciembre ni Scheduler con una hora inventada.

## Errores relevantes

`POINT_ACCOUNT_NOT_FOUND`, `POINT_ACCOUNT_INCONSISTENT`,
`RELATION_POINT_BASIS_MISSING`, `LIQUIDATION_CLASSIFICATION_INVALID`,
`POINT_RESERVATION_CONFLICT`, `REDEMPTION_PERIOD_CLOSED`,
`REAUTHENTICATION_REQUIRED`, `IDEMPOTENCY_KEY_REUSED`,
`REDEMPTION_POLICY_UNDEFINED` y `POINT_DELIVERY_ROLE_UNDEFINED`.
