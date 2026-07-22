# AGENTS.md

## Flujo de trabajo Git

Antes de comenzar cualquier implementación:

1. Nunca trabajes directamente sobre `develop` ni `main`.
2. Para cada cambio crea una rama nueva.
3. Una rama representa **una única funcionalidad, corrección o tarea técnica**.
4. Cuando la tarea termine, los cambios deben integrarse mediante un Pull Request hacia `develop`.
5. Nunca mezcles varias funcionalidades en la misma rama.

## Convención para nombres de ramas

Usa el siguiente formato:

<tipo>/<descripcion-corta>

Ejemplos:

feat/login-mfa
feat/create-user
feat/access-session
fix/login-validation
fix/token-expiration
refactor/auth-service
docs/m01-access
test/auth-feature
chore/docker-config
build/php82
ci/github-actions
perf/session-cache
revert/remove-old-auth

## Tipos permitidos

- feat: nueva funcionalidad
- fix: corrección de errores
- chore: mantenimiento o configuración
- refactor: reorganización sin cambiar comportamiento
- test: pruebas
- docs: documentación
- build: dependencias o compilación
- ci: integración continua
- perf: mejoras de rendimiento
- revert: revertir cambios

## Commits

Usa Conventional Commits.

Ejemplos:

feat(auth): implement login endpoint
fix(auth): validate email format
refactor(auth): extract authentication service
docs(access): update backend rules

No hagas commits genéricos como:
- update
- cambios
- fixes
- prueba

## Pull Requests

Antes de crear un Pull Request:

- verifica que el proyecto compile;
- ejecuta las pruebas disponibles;
- confirma que no rompes funcionalidades existentes;
- crea el PR siempre hacia `develop`.
