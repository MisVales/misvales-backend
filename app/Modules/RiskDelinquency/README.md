# M14 — Riesgo y morosidad

Este módulo es propietario del perfil de riesgo por distribuidora, las evaluaciones
versionadas posteriores al vencimiento, las secuencias consecutivas, las alertas,
la aplicación manual de morosidad, la regularización financiera y el retiro manual.
No crea relaciones, no concilia pagos y no calcula saldos financieros.

## Fuentes y límites

- M10 debe publicar relación, corte, fecha límite, orden y detalle de partidas.
- M11 debe publicar `LIQUIDO`, `ABONO` o `NO_PAGO`, saldo vencido, versión y
  confirmación de que terminó la conciliación requerida.
- Mientras esos contratos productivos no existan, `RelationRiskSourcePort` y
  `OverdueBalancePort` fallan con `RISK_SOURCE_NOT_READY`. No se decide con
  snapshots incompletos.
- Los registros de cartera de M06 son únicamente informativos y nunca se leen
  para crear evaluaciones, alertas, morosidad, regularización o bloqueos.
- M14 publica `CanDistributorIssueVoucher`; M09 lo revalida dentro de la
  transacción de feriado. M08 debe consumir el mismo contrato cuando exista.
- M17 entrega notificaciones y M18 consolida auditoría. M14 solo registra outbox
  y auditoría local dentro de la transacción.

## Flujo

1. Una evaluación definitiva de M11 se consume de forma idempotente por
   `relation_id + source_version + POST_DUE`.
2. `LIQUIDO` con saldo cero rompe la secuencia. `ABONO` o `NO_PAGO` con saldo
   mayor a cero la incrementan.
3. Los umbrales 1, 2 y 3 crean alertas y eventos. El tercero conserva exactamente
   tres relaciones de evidencia y habilita revisión; nunca aplica morosidad.
4. Un gerente de sucursal en alcance o el gerente general confirma la aplicación
   con reautenticación e `Idempotency-Key`. La misma transacción crea decisión,
   activa `DELINQUENCY`, historial, auditoría y outbox.
5. Un saldo vencido agregado igual a cero reinicia la secuencia. Si ya existía
   morosidad, pasa a `REGULARIZED_PENDING_REMOVAL` y conserva el bloqueo.
6. Solo el coordinador responsable prepara el retiro. Solo un gerente en alcance
   lo aprueba o rechaza con reautenticación. La aprobación vuelve a consultar M11
   y elimina el bloqueo; el rechazo lo conserva.
7. Si reaparece saldo antes de decidir, la solicitud `PREPARED` se invalida y la
   morosidad continúa.

El orden de bloqueo es: distribuidora, perfil, evaluación/secuencia, alerta,
decisión/solicitud e indicador materializado.

## Estados

- Evaluación: `PENDING_SOURCE`, `COMPLIANT`, `BREACHED`, `SUPERSEDED`.
- Perfil: `CURRENT`, `REBUILD_REQUIRED`, `REBUILDING`, `INCONSISTENT`.
- Alerta: `ACTIVE`, `RESOLVED_BY_DECISION`, `FINANCIALLY_REGULARIZED`,
  `SUPERSEDED`.
- Morosidad: `NOT_DELINQUENT`, `DELINQUENT`,
  `REGULARIZED_PENDING_REMOVAL`.
- Retiro: `PREPARED`, `APPROVED`, `REJECTED`, `INVALIDATED`.

## API

Las consultas están bajo `/api/v1/risk`; aplicación y retiro bajo
`/api/v1/delinquency`. Las mutaciones exigen `Idempotency-Key`; aplicar y decidir
retiros requieren reautenticación. Los IDs internos y la evidencia reutilizable de
reautenticación no forman parte de las respuestas públicas.

## Scheduler y recuperación

`RetryDeferredRiskEvaluationsJob` se programa a las 08:30 en
`America/Monterrey`. `php artisan risk:recover --missing` consume faltantes cuando
M10/M11 estén disponibles. `--distributor=<id> --rebuild` reconstruye el resumen
desde evaluaciones inmutables y después reconcilia. Ninguna operación técnica
aplica o retira morosidad.

## Definiciones deliberadamente no implementadas

No se agregó decisión de perdón/no aplicación, cierre manual de alertas,
recordatorios, escalamiento, alertas posteriores al tercer incumplimiento,
cancelación de solicitudes, reversiones de decisiones, reaplicación automática,
efectos sobre puntos/excedentes/transferencias/incrementos ni morosidad de clientes.
Los motivos y folios quedan como texto/UUID estable sin imponer obligatoriedad o
formato funcional todavía no aprobado.
