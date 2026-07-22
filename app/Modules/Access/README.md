# Módulo Access (M01)

Este módulo implementa exclusivamente las reglas definidas en `M01-Acceso-Backend.md`.

## Capas

- `Application`: casos de uso y DTO de entrada o salida.
- `Domain`: reglas, entidades, objetos de valor, policies y contratos.
- `Infrastructure`: adaptadores de PostgreSQL, Redis, WebAuthn, notificaciones y auditoría.
- `Presentation/Http`: rutas, controladores y Form Requests de `/api/v1`.

Las dependencias apuntan hacia el dominio. Los controladores no contienen reglas de negocio y cada escritura relevante se coordina mediante un caso de uso transaccional.
