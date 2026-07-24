# Módulo Access (M01)

Este módulo implementa exclusivamente las reglas definidas en `M01-Acceso-Backend.md`.

## Capas

- `Application`: casos de uso y DTO de entrada o salida.
- `Domain`: reglas, entidades, objetos de valor, policies y contratos.
- `Infrastructure`: adaptadores de PostgreSQL, Redis, WebAuthn, notificaciones y auditoría.
- `Presentation/Http`: rutas, controladores y Form Requests de `/api/v1`.

Las dependencias apuntan hacia el dominio. Los controladores no contienen reglas de negocio y cada escritura relevante se coordina mediante un caso de uso transaccional.

## Gerente general inicial

El aprovisionamiento inicial requiere `INITIAL_GENERAL_MANAGER_ENABLED=true`, `INITIAL_GENERAL_MANAGER_EMAIL` y `INITIAL_GENERAL_MANAGER_NAME`. Después de ejecutar `InitialGeneralManagerSeeder` y confirmar la creación, el operador debe retirar la configuración sensible y volver a dejar `INITIAL_GENERAL_MANAGER_ENABLED=false`. El seeder es idempotente y nunca muestra la invitación, el token ni una contraseña en consola.
