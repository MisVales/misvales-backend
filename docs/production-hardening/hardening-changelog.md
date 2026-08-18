# Changelog de hardening

## Backend

- `f982879 refactor: eliminar modulo de puntos`
  - retira la superficie runtime, catálogos, esquema vigente, OpenAPI y pruebas obsoletas;
  - añade migración forward-only y regresión de ausencia.
- `a01b939 fix: hacer efectiva la revocacion de sesiones`
  - alinea el identificador de sesión con el hash persistido por Sanctum;
  - rechaza sesiones revocadas y elimina su token;
  - conserva una transición segura para hashes históricos;
  - cubre revocación remota, reset y cambio de contraseña.
- `452c5c4 security: retirar bootstrap de credenciales fijas`
  - elimina el script versionado de acceso fijo;
  - añade `app:validate-production` sin imprimir secretos.
- `105f10e build: restringir version de google2fa`
  - sustituye el wildcard por `^9.0`, compatible con `v9.0.0` ya fijado en lock;
  - no actualiza paquetes.

## Límite de evidencia

Las pruebas automatizadas no sustituyen una verificación real de cookies, headers, proxy, TLS, workers, scheduler, Redis, almacenamiento y restauración en el entorno de release.

