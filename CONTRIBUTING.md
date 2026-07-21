# Contribuir a MisVales Backend

Este repositorio utiliza GitFlow y todos los cambios posteriores al bootstrap inicial deben incorporarse mediante Pull Request.

## Ramas permanentes

- `main`: versiones liberadas y código estable de producción.
- `develop`: integración del trabajo aprobado para la siguiente liberación.

Está prohibido trabajar o hacer push directamente sobre `main` y `develop`.

## Ramas de trabajo

Toda tarea debe utilizar el formato:

```text
tipo/MV-numero-descripcion-corta
```

Tipos autorizados:

- `feature/*`
- `bugfix/*`
- `release/*`
- `hotfix/*`
- `chore/*`

La descripción debe escribirse en minúsculas, utilizar guiones, ser breve y no contener espacios.

Ejemplos:

```text
feature/MV-101-autenticacion
bugfix/MV-205-corregir-calculo
chore/MV-010-actualizar-dependencias
release/MV-300-version-inicial
hotfix/MV-401-corregir-seguridad
```

## Flujo GitFlow

- `feature/*`, `bugfix/*` y `chore/*` abren Pull Request hacia `develop`.
- `develop` origina una rama `release/*` cuando una versión está lista para preparación.
- `release/*` abre Pull Request hacia `main`.
- `hotfix/*` se origina desde `main`, se integra mediante Pull Request a `main` y después debe sincronizarse también con `develop` mediante otro Pull Request.

## Convención de commits

Los mensajes utilizan el formato:

```text
tipo(alcance): descripción
```

Tipos permitidos:

```text
feat
fix
chore
refactor
test
docs
build
ci
perf
revert
```

Ejemplos:

```text
feat(auth): add Sanctum login endpoint
fix(relations): correct payment allocation
chore(deps): update Laravel dependencies
docs(readme): document local installation
```

## Pull Requests y revisiones

- Todo cambio debe ingresar mediante Pull Request.
- Los Pull Requests normales apuntan a `develop`.
- Los Pull Requests de liberación y hotfix apuntan a `main` según el flujo descrito.
- `@MisVales/managers` es responsable obligatorio de revisión mediante CODEOWNERS.
- El autor no puede aprobar ni fusionar su propio Pull Request.
- El autor no debe habilitar automerge para fusionar sus propios cambios.
- Solo el líder, QA o responsable autorizado puede fusionar.
- Los commits nuevos invalidan las aprobaciones anteriores.
- Todas las conversaciones deben resolverse antes del merge.
- El único método permitido es **Squash and merge**.
- No se permiten merge commits ni rebase merge.

Después del bootstrap administrativo inicial no existen excepciones al flujo de Pull Request.
