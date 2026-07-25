# M06 — Clientes finales y cartera informativa

M06 mantiene la identidad interna del cliente final, su domicilio, cuenta bancaria, documentos privados, historial de asignación y cartera opcional. El cliente no es un usuario y ninguna tabla del módulo tiene una relación de autenticación desde `clients` hacia `users`.

## Límites

- M06 no crea cuentas, sesiones, credenciales, prevales, vales, línea, relaciones, pagos bancarios, puntos, morosidad o transferencias.
- CURP, RFC, domicilio y cuenta se cifran para lectura autorizada. CURP y domicilio usan HMAC independiente para coincidencia exacta.
- Las distribuidoras solo operan sobre la asociación vigente que M05 resuelva desde la cuenta autenticada.
- Los cambios sensibles se ejecutan únicamente por `ApplyAuthorizedClientChanges`, después de que M09 consuma una autorización exacta.
- Las reasignaciones se ejecutan únicamente por `ApplyAuthorizedClientAssignment`, después de la autorización de M15 y de volver a validar la versión de cartera.
- Los cargos de vale solo ingresan por `RecordClientVoucherReference` después de que M08 confirme la referencia.
- La cartera es informativa: no escribe sobre línea, relaciones, conciliación, puntos ni ningún libro financiero externo.

## API

La API versionada está documentada en `docs/openapi.yaml`.

- `GET|POST /api/v1/clients`
- `GET /api/v1/clients/{client}`
- `GET|POST /api/v1/clients/{client}/bank-accounts`
- `GET|POST /api/v1/clients/{client}/portfolio-entries`
- `PATCH /api/v1/clients/{client}/portfolio-entries/{entry}`

No existe `PATCH /clients/{client}`, `DELETE`, inicio de sesión ni rutas de morosidad.

## Dependencias todavía no implementadas en este checkout

El proveedor registra adaptadores que deniegan por defecto para M05, M08, M09, M15 y M18. Cada módulo propietario debe reemplazar su puerto al integrarse:

- `DistributorProfilePort`: perfil, sucursal y alcance M02/M05.
- `DocumentReferencePort`: referencia privada M18.
- `ConfirmedVoucherPort`: vale confirmado M08.
- `CashierVoucherAccessPort`: vale atendible y sucursal de la cajera en M08.
- `AuthorizedChangePort`: consumo transaccional M09.
- `AuthorizedMobilityPort`: movilidad autorizada M15.

No se infiere un perfil M05 desde las tablas de M04 y no se fabrican referencias o autorizaciones cuando una dependencia falta.

M08 puede consultar `ResolveClientForVoucher` para confirmar exclusivamente identidad, asociación y domicilio; el resultado no considera cartera, deuda, morosidad, producto, línea ni la regla del 50 %.

## Pendientes funcionales preservados

- Separación exacta de apellidos.
- Obligatoriedad individual de RFC y datos de nacimiento.
- Catálogo de identificaciones.
- Formato bancario definitivo.
- Abreviaturas equivalentes del normalizador de domicilio.
- Tratamiento de pagos superiores al saldo; actualmente se rechazan para no crear automáticamente saldo a favor.
- Flujo público para activar o confirmar cartera. Los casos de uso internos existen, pero no se agregó una ruta no especificada.
