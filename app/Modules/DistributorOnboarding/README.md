# M04 — Solicitud, verificación y autorización de distribuidoras

## Alcance implementado

El módulo `DistributorOnboarding` contiene:

- Persistencia separada para solicitud, datos personales, familiares, domicilio, vehículos, patrimonio, empleos, referencias, créditos comerciales, revisiones, asignaciones, visita, diferencias, correcciones, evaluaciones, autorizaciones, activación, historial, auditoría e idempotencia.
- Cifrado de valores sensibles mediante casts de Laravel y hashes HMAC independientes para búsquedas exactas autorizadas de correo, CURP y RFC.
- Máquina de estados cerrada sin ruta genérica para cambiar `status`, sin reapertura y sin eliminación física.
- Bloqueo de fila, `lock_version`, `Idempotency-Key`, historial, auditoría y outbox en las escrituras.
- Alcance SQL para gerente general, gerente de sucursal, coordinador, verificador y administrador de solo lectura.
- Casos de uso explícitos para captura personal, envío, devolución documental, asignación, visita, diferencias, correcciones, evaluación, decisión gerencial y activación.
- Puertos explícitos hacia M01, M02, M03, M05, M07, M17 y M18.
- API autenticada bajo `/api/v1/distributor-applications`.

M04 no crea contratos, firmas digitales ni categorías.

## Denegación por defecto

La especificación deja definiciones funcionales pendientes y los módulos propietarios todavía no tienen implementaciones en este repositorio. Por ese motivo los adaptadores reales mantienen cerradas estas operaciones:

- Crear una solicitud: M02 todavía no puede resolver de forma autoritativa la sucursal y el coordinador del capturista.
- Enviar a revisión: no existe una matriz aprobada de campos y documentos obligatorios.
- Asignar verificador: no se ha definido qué rol recibe `onboarding.verifications.assign` y M02 no implementa la validación organizacional.
- Registrar diferencias o archivos: no existe el catálogo aprobado ni el contrato operativo de M18.
- Autorizar una línea inicial: M03 todavía no publica los límites ni define si cero es válido.
- Activar: faltan los adaptadores propietarios de M02, M03, M05, M07 y el aprovisionamiento final de M01.

Los permisos `onboarding.applications.create`, `onboarding.applications.update_capture`, `onboarding.applications.submit` y `onboarding.verifications.assign` se registran en catálogo, pero no se conceden a ningún rol. No deben asignarse hasta que el líder resuelva la autoridad correspondiente.

Los adaptadores `Unavailable*` son controles fail-closed, no simulaciones de éxito. Las pruebas de integración pueden sustituir puertos por implementaciones controladas para comprobar la orquestación sin presentar esas sustituciones como integraciones productivas.

## Concurrencia e idempotencia

Cada transición bloquea `distributor_applications`, compara `lock_version` y utiliza una clave de operación. Un reintento con la misma clave y contenido devuelve el recurso ya confirmado; reutilizar la clave con otro contenido produce `IDEMPOTENCY_KEY_REUSED`.

La clave de activación se deriva del UUID de `application_authorizations`. Los puertos de aprovisionamiento reciben esa misma identidad para impedir duplicados. `ACTIVE`, `EV-008` y `EV-010` solo se registran después de obtener perfil, número, asignación, línea, movimiento, restricción y cuenta.

## Privacidad

- Los listados no incluyen documentos, fotografías ni secciones completas.
- El administrador recibe datos personales enmascarados y solo conteos de colecciones.
- Los eventos de outbox contienen identificadores, folio, estados y contexto mínimo; no incluyen correo, CURP, RFC, domicilio, archivos ni credenciales.
- IP y dispositivo se conservan como hash en auditoría.
- Los archivos se aceptan solo por UUID validado mediante M18; no se guardan binarios ni rutas públicas.

## Contrato

El contrato OpenAPI se encuentra en `docs/openapi.yaml`. Todas las escrituras requieren `Idempotency-Key`; las operaciones sobre una versión requieren `If-Match` o `lock_version`; las respuestas incluyen `X-Request-Id`.

No existe `DELETE`, reapertura, alta manual de distribuidora, cambio genérico de estado ni modificación posterior de una autorización.
