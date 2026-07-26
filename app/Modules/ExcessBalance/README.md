# M12 — Excedentes

M12 es propietario del ciclo posterior al excedente calculado por M11. Reutiliza
`excess_balances`, `excess_applications` y `refund_requests`; agrega el libro
inmutable, historial, idempotencia, auditoría y consumidores de relación
disponible.

## Fronteras

- M11 invoca `DetectedExcessRegistrar` dentro de su transacción financiera.
- M10/M11 implementan `CreditBalanceApplicationPort` respetando el orden de
  bloqueo: relación, línea, excedente, solicitud y aplicación.
- El adaptador productivo de aplicación queda cerrado hasta que M10/M11
  publiquen esa frontera.
- `RefundExecutionPolicy` y `PrivateEvidencePort` quedan cerrados hasta definir
  métodos, campos y límites exactos. Una reserva rechazada permanece reservada.
- Si existen varios saldos a favor, la aplicación se bloquea con
  `PENDING_BUSINESS_DEFINITION`; no se elige FIFO, LIFO ni otro orden.
- La fecha efectiva y clasificación temporal de saldo a favor se conservan
  nulas; solo se registra `applied_at`.

El libro no se corrige automáticamente. La reconciliación únicamente registra
una incidencia y emite `ExcessLedgerInconsistencyDetected`.
