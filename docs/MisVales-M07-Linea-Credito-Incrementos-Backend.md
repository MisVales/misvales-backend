# M07 — Línea de crédito e incrementos — Backend

## 1. Objetivo

Desarrollar en Laravel el módulo encargado de administrar la línea de crédito de cada distribuidora, conservar todos sus movimientos, calcular el saldo disponible, controlar la regla especial del 50 % y ejecutar el flujo completo de solicitudes de incremento.

El módulo debe garantizar que:

- Cada distribuidora activa tenga una sola línea de crédito.
- La línea inicial sea registrada únicamente como resultado de una autorización gerencial.
- La línea total, el saldo utilizado y el saldo disponible permanezcan consistentes.
- Ningún movimiento financiero sobrescriba o elimine el historial anterior.
- La distribuidora pueda solicitar incrementos.
- El coordinador revise y preautorice o rechace la solicitud sin modificar la línea.
- El gerente general o el gerente de sucursal autorice el importe solicitado, autorice un importe menor o rechace.
- Cada incremento autorizado genere un movimiento de línea y active una restricción del 50 %.
- La restricción del 50 % se consuma únicamente cuando el primer vale correspondiente quede feriado y su capital sea descontado de la línea.
- Los futuros módulos de vales y pagos puedan utilizar y recuperar línea mediante operaciones transaccionales seguras.

Este módulo pertenece exclusivamente al backend Laravel. No incluye frontend, infraestructura, despliegues, servidores, VPN, SSH ni observabilidad.

---

## 2. Resultado funcional esperado

Al finalizar el módulo debe existir:

1. Una línea de crédito única por distribuidora.
2. Un saldo vigente consultable compuesto por:
   - Línea total autorizada.
   - Saldo utilizado.
   - Saldo disponible.
   - Capital recuperado acumulado, cuando existan movimientos posteriores de recuperación.
3. Un libro inmutable de movimientos de crédito.
4. Una restricción del 50 % creada:
   - Al registrar la línea inicial.
   - Después de cada incremento autorizado.
5. Un flujo completo de incremento:
   - Solicitud de la distribuidora.
   - Revisión del coordinador.
   - Rechazo del coordinador o preautorización.
   - Decisión final del gerente.
   - Actualización transaccional de la línea cuando proceda.
   - Activación y consumo de la restricción correspondiente.
6. Consultas por rol y alcance.
7. Auditoría completa de solicitudes, decisiones, saldos y movimientos.
8. Eventos internos para notificaciones posteriores.
9. Contratos internos para que los módulos de vales y pagos modifiquen la línea sin editar saldos directamente.
10. Pruebas unitarias, de integración, autorización, concurrencia e idempotencia.

---

## 3. Alcance

### 3.1 Incluye

- Registro de la línea inicial autorizada.
- Consulta de la línea vigente.
- Cálculo y validación del saldo disponible.
- Libro de movimientos de línea.
- Historial de cambios de línea total y saldo utilizado.
- Creación y consulta de solicitudes de incremento.
- Revisión del coordinador.
- Preautorización con importe recomendado.
- Rechazo por el coordinador.
- Decisión final del gerente.
- Autorización total.
- Autorización parcial por un importe menor al solicitado.
- Rechazo gerencial.
- Actualización de la línea total.
- Regla del 50 % para la línea inicial.
- Regla del 50 % posterior a cada incremento.
- Vinculación de la restricción con el primer vale que intente utilizarla.
- Consumo de la restricción únicamente al feriar el vale.
- Liberación de la restricción vinculada cuando el vale sea cancelado o rechazado, sin marcarla como consumida.
- Operaciones internas para utilizar línea y recuperar capital.
- Control transaccional y bloqueo de concurrencia.
- Autorización por rol, sucursal, coordinador y propiedad de la cuenta.
- Reautenticación vigente para decisiones gerenciales de incremento, utilizando M01.
- Auditoría, eventos de dominio y errores normalizados.

### 3.2 No incluye

- Captura, verificación o autorización del expediente de aspirante.
- Cálculo automático de la línea inicial.
- Administración del perfil de la distribuidora.
- Administración de categorías o productos.
- Registro de clientes finales.
- Cartera informativa de clientes finales.
- Generación, validación, liberación o feriado de vales.
- Cálculo financiero completo de un vale.
- Generación de relaciones.
- Carga bancaria.
- Conciliación de pagos.
- Cálculo de la distribución de un pago entre recargos, intereses, seguro, comisión y capital.
- Puntos, morosidad, transferencias o reportes generales.
- Interfaces de usuario.
- Procesos de infraestructura.

Los módulos posteriores de vales y pagos deben invocar los servicios internos de M07. No pueden modificar directamente `credit_lines` ni insertar movimientos por su cuenta.

---

## 4. Dependencias

### 4.1 Módulos requeridos

| Módulo | Uso dentro de M07 |
| --- | --- |
| M01 — Acceso | Usuario autenticado, rol, permisos, alcance, sesión, reautenticación y contexto de auditoría. |
| M02 — Organización | Sucursal, jerarquía gerencial y asignación entre coordinador y distribuidora. |
| M03 — Configuraciones y catálogos | Tolerancia global vigente para la regla del 50 %. |
| M04 — Solicitud, verificación y autorización de distribuidoras | Entrega la autorización final y el importe de la línea inicial. |
| M05 — Distribuidoras | Perfil, estado, sucursal, coordinador y categoría vigente de la distribuidora. |
| M06 — Clientes finales y cartera informativa | Únicamente establece que el adeudo informativo de un cliente no afecta la línea ni bloquea incrementos o nuevos vales. |

### 4.2 Integraciones futuras

| Módulo futuro | Contrato que utilizará |
| --- | --- |
| Vales | Validar saldo y restricción, vincular el primer vale, descontar capital al feriar y liberar la vinculación al cancelar o rechazar. |
| Pagos y conciliación | Registrar recuperación de línea utilizando exclusivamente el capital efectivamente cubierto por un pago conciliado. |
| Reportes | Consultar líneas, saldos, movimientos e incrementos por alcance. |
| Notificaciones | Consumir los eventos críticos publicados por M07. |
| Auditoría | Consultar los eventos inmutables generados por M07. |

---

## 5. Estructura del módulo Laravel

El desarrollo debe ubicarse en:

```text
app/Modules/Credit/
├── Application/
│   ├── Commands/
│   ├── Queries/
│   ├── DTOs/
│   ├── Contracts/
│   └── Services/
├── Domain/
│   ├── Aggregates/
│   ├── Entities/
│   ├── ValueObjects/
│   ├── Enums/
│   ├── Rules/
│   ├── Services/
│   ├── Events/
│   ├── Exceptions/
│   └── Repositories/
├── Infrastructure/
│   ├── Persistence/
│   │   └── Eloquent/
│   │       ├── Models/
│   │       ├── Repositories/
│   │       └── Mappers/
│   └── Providers/
└── Presentation/
    └── Http/
        ├── Controllers/
        ├── Requests/
        └── Resources/
```

Reglas:

- Los controladores solo reciben la petición, ejecutan autorización, construyen el comando o consulta y transforman la respuesta.
- Los controladores no calculan saldos ni cambian estados.
- Los modelos Eloquent no deben contener el flujo de autorización.
- Las reglas del 50 %, los movimientos y las transiciones pertenecen al dominio y a los casos de uso.
- Todo cambio de saldo se ejecuta mediante servicios de aplicación dentro de una transacción.
- Ningún consumidor externo recibe acceso directo a los repositorios de persistencia.

---

## 6. Conceptos e invariantes

### 6.1 Línea total autorizada

Es el importe máximo vigente que la distribuidora puede utilizar.

Solo cambia por:

- `INITIAL_AUTHORIZATION`: establece la línea inicial.
- `INCREASE`: suma un incremento autorizado.
- `AUTHORIZED_CORRECTION`: movimiento compensatorio proveniente de un proceso autorizado.

No debe existir un endpoint genérico para editar la línea total.

### 6.2 Saldo utilizado

Es el capital de vales feriados que todavía no se ha recuperado mediante pagos conciliados.

Solo cambia por:

- `VOUCHER_FULFILLED`: aumenta el saldo utilizado.
- `CAPITAL_RECOVERED`: disminuye el saldo utilizado.
- `AUTHORIZED_CORRECTION`: ajuste compensatorio autorizado.

La generación o liberación de un vale no utiliza línea. La línea se utiliza únicamente cuando el vale queda feriado.

### 6.3 Saldo disponible

```text
Saldo disponible = Línea total autorizada − Saldo utilizado
```

Siempre deben cumplirse estas invariantes:

```text
0 ≤ Saldo utilizado ≤ Línea total autorizada

0 ≤ Saldo disponible ≤ Línea total autorizada
```

El saldo disponible debe recalcularse y validarse dentro de la misma transacción que registra cada movimiento.

### 6.4 Línea recuperada

Solo el capital cubierto por un pago conciliado recupera línea.

M07 recibe del módulo de pagos el importe ya determinado como capital efectivamente cubierto. M07 no vuelve a distribuir el pago entre conceptos.

La recuperación:

- Reduce el saldo utilizado.
- No puede superar el capital pendiente.
- No puede elevar el saldo disponible por encima de la línea total.
- No reactiva la regla del 50 %.
- No se ejecuta por pagos no conciliados.
- No se ejecuta por registros de cartera informativa del cliente final.
- No utiliza excedentes retenidos o pendientes de devolución.

### 6.5 Dinero

- Usar valores decimales exactos.
- No usar `float` ni `double`.
- Conservar precisión interna de cuatro decimales.
- Redondear el resultado monetario final a dos decimales.
- Aplicar redondeo aritmético.
- Los valores intermedios no deben redondearse anticipadamente.
- La API debe entregar importes como cadenas decimales para evitar pérdida de precisión.

---

## 7. Regla del 50 %

### 7.1 Activación

La restricción se crea:

1. Al autorizar y registrar la línea inicial.
2. Después de cada incremento autorizado total o parcialmente.

No se crea ni se reactiva:

- Cuando un pago recupera línea.
- Cuando el saldo disponible vuelve a ser igual a la línea total.
- Al consultar o recalcular la línea.
- Por cambios en la cartera informativa de un cliente.

### 7.2 Cálculo

```text
Referencia = Línea total autorizada × 0.50

Límite inferior = máximo(0, Referencia − Tolerancia)

Límite superior = mínimo(Saldo disponible, Referencia + Tolerancia)
```

Reglas:

- La tolerancia inicial es de más o menos `$500.00`.
- La tolerancia se obtiene de la configuración global vigente.
- La restricción debe conservar la tolerancia utilizada al momento de su creación.
- El límite superior nunca puede superar el saldo disponible.
- El capital del producto debe encontrarse dentro del rango inclusivo.
- Si el límite superior es menor que el límite inferior, no existe un producto admisible.
- La regla usa la línea total autorizada vigente, no únicamente el incremento solicitado o autorizado.

### 7.3 Restricción inicial

Al registrar la línea inicial:

- La línea debe estar completamente disponible.
- El primer vale debe cumplir el rango.
- La recuperación futura de línea no vuelve a crear esta restricción.

### 7.4 Restricción posterior a un incremento

Después de un incremento:

- Se crea una restricción para el primer vale posterior.
- Se usa la nueva línea total autorizada.
- Se respeta el saldo realmente disponible.
- La restricción permanece activa hasta que el vale quede feriado.
- Mientras no se consuma, el saldo restante no puede liberarse como operación normal.
- Después de consumirla, los vales siguientes pueden utilizar el saldo restante sin repetir la regla especial.

### 7.5 Vinculación con un vale

El módulo de vales debe solicitar a M07 la validación y vinculación de la restricción.

M07 debe:

1. Bloquear la línea y la restricción activas.
2. Recalcular el saldo disponible.
3. Calcular nuevamente el rango.
4. Validar el capital del producto.
5. Vincular la restricción con ese vale.
6. Impedir que otro vale ignore o reclame la misma restricción.

La vinculación no consume la restricción.

### 7.6 Cancelación o rechazo

Si el vale vinculado se cancela o se rechaza antes del feriado:

- La restricción continúa activa.
- No se considera consumida.
- Se elimina la vinculación con ese vale.
- Otro vale posterior deberá cumplir la misma restricción.

### 7.7 Consumo

La restricción se consume únicamente cuando:

1. El vale vinculado vuelve a validar saldo y rango.
2. El vale queda feriado.
3. Se inserta el movimiento `VOUCHER_FULFILLED`.
4. El capital se refleja en el saldo utilizado.

La actualización de la línea, el movimiento, el feriado del vale y el consumo de la restricción deben formar una sola operación atómica coordinada entre los módulos.

### 7.8 Uso normal de una línea parcialmente utilizada

Cuando la línea ya fue utilizada y no existe una restricción del 50 % pendiente por línea inicial o incremento:

- La regla especial no vuelve a aplicarse.
- Puede utilizarse hasta el saldo disponible.
- Cada uso debe corresponder a un vale independiente.
- El saldo restante después del primer vale sujeto al 50 % se utiliza mediante uno o más vales nuevos.
- Continúan aplicando el estado de la distribuidora, el producto vigente y las demás validaciones del vale.
- El adeudo informativo del cliente final no forma parte de la elegibilidad financiera.

---

## 8. Flujo de incremento

### 8.1 Solicitud

La distribuidora autenticada puede solicitar un incremento para su propia línea.

Debe registrar:

- Importe solicitado.
- Motivo o comentario.
- Identificador de la distribuidora.
- Sucursal y coordinador responsables.
- Línea total, saldo utilizado y saldo disponible existentes al momento de la solicitud.
- Fecha y hora en `America/Monterrey`.

Cuando la solicitud se origina porque un producto supera el saldo disponible, también debe conservar:

- Importe del producto.
- Saldo disponible consultado.
- Diferencia calculada.

```text
Diferencia = Importe del producto − Saldo disponible
```

La diferencia sirve para presentar el importe requerido, pero la aprobación no garantiza que el primer vale pueda utilizar toda la nueva línea. Después de autorizar el incremento se aplica la regla del 50 %.

### 8.2 Revisión del coordinador

El coordinador responsable debe revisar:

- Historial de la distribuidora.
- Línea vigente.
- Movimientos de línea.
- Reportes disponibles.
- Pagos.
- Atrasos.

Puede:

- Rechazar la solicitud.
- Preautorizarla y registrar un importe recomendado.

El coordinador:

- Solo puede actuar sobre distribuidoras asignadas a él y pertenecientes a su sucursal.
- No puede autorizar finalmente.
- No puede modificar la línea total.
- No puede insertar movimientos `INCREASE`.
- No puede cambiar una solicitud ya resuelta.

### 8.3 Decisión gerencial

Solo una solicitud preautorizada puede llegar a decisión gerencial.

El gerente general puede decidir globalmente.

El gerente de sucursal solo puede decidir solicitudes de distribuidoras pertenecientes a su sucursal.

El gerente puede:

1. Autorizar el importe solicitado.
2. Autorizar un importe menor.
3. Rechazar.

No puede autorizar un importe mayor al solicitado.

Antes de decidir debe validarse:

- Permiso.
- Alcance.
- Estado actual de la solicitud.
- Reautenticación vigente emitida por M01.
- Versión vigente del registro.
- Existencia y estado de la distribuidora.
- Existencia de la línea.

### 8.4 Autorización total

Cuando el importe autorizado es igual al solicitado:

- La solicitud pasa a `AUTORIZADO_TOTAL`.
- Se incrementa la línea total.
- Se inserta un movimiento `INCREASE`.
- Se crea una restricción del 50 %.
- Se publican los eventos de autorización y activación.

### 8.5 Autorización parcial

Cuando el importe autorizado es mayor que cero y menor al solicitado:

- La solicitud pasa a `AUTORIZADO_PARCIAL`.
- Se conserva el importe solicitado.
- Se conserva el importe recomendado.
- Se conserva el importe finalmente autorizado.
- Se incrementa la línea total únicamente por el importe autorizado.
- Se inserta un movimiento `INCREASE`.
- Se crea una restricción del 50 %.
- Se publican los eventos de autorización parcial y activación.

### 8.6 Rechazo

El rechazo puede provenir del coordinador o del gerente.

En ambos casos:

- La línea total no cambia.
- No se crea movimiento `INCREASE`.
- No se crea restricción del 50 %.
- Se conserva actor, rol, motivo, fecha, hora y estado anterior.
- Se notifica a los destinatarios correspondientes.

---

## 9. Estados

### 9.1 Solicitud de incremento

| Estado | Significado | Transiciones permitidas |
| --- | --- | --- |
| `SOLICITADO` | La distribuidora registró la solicitud. | `PREAUTORIZADO`, `RECHAZADO_COORDINADOR`. |
| `PREAUTORIZADO` | El coordinador revisó y recomendó un importe. | `AUTORIZADO_TOTAL`, `AUTORIZADO_PARCIAL`, `RECHAZADO_GERENTE`. |
| `RECHAZADO_COORDINADOR` | El coordinador decidió no preautorizar. | Terminal. |
| `RECHAZADO_GERENTE` | El gerente rechazó una solicitud preautorizada. | Terminal. |
| `AUTORIZADO_TOTAL` | Se autorizó el importe solicitado. | `RESTRICCION_50_ACTIVA`. |
| `AUTORIZADO_PARCIAL` | Se autorizó un importe menor al solicitado. | `RESTRICCION_50_ACTIVA`. |
| `RESTRICCION_50_ACTIVA` | La línea aumentó y espera el primer vale posterior. | `COMPLETADO`. |
| `COMPLETADO` | El primer vale posterior quedó feriado y consumió la restricción. | Terminal. |

No debe existir un endpoint que permita asignar manualmente cualquiera de estos estados.

### 9.2 Restricción de uso

| Estado | Significado |
| --- | --- |
| `ACTIVE` | Debe aplicarse al primer vale correspondiente. |
| `BOUND` | Está vinculada a un vale todavía no feriado. |
| `CONSUMED` | El vale vinculado quedó feriado y la restricción terminó. |

Cancelar o rechazar un vale cambia `BOUND` nuevamente a `ACTIVE`; nunca a `CONSUMED`.

---

## 10. Persistencia

### 10.1 `credit_lines`

Debe conservar el saldo materializado actual de la distribuidora.

Campos mínimos:

| Campo | Regla |
| --- | --- |
| `id` | Identificador interno. |
| `distributor_id` | Relación única con la distribuidora. |
| `total_authorized` | Línea total vigente. Decimal exacto. |
| `used_balance` | Capital utilizado todavía no recuperado. |
| `available_balance` | Total autorizado menos saldo utilizado. |
| `recovered_capital_total` | Capital recuperado acumulado. |
| `last_movement_id` | Último movimiento aplicado. |
| `lock_version` | Control de concurrencia y conflictos de versión. |
| `created_at` | Fecha de creación. |
| `updated_at` | Fecha de última actualización materializada. |

Restricciones:

- `distributor_id` debe ser único.
- Los importes no pueden ser negativos.
- `used_balance` no puede superar `total_authorized`.
- `available_balance` debe coincidir con la fórmula.
- Una distribuidora no puede tener dos líneas.
- No se permite eliminación física.

### 10.2 `credit_line_movements`

Libro inmutable de todos los cambios.

Campos mínimos:

| Campo | Regla |
| --- | --- |
| `id` | Identificador del movimiento. |
| `credit_line_id` | Línea afectada. |
| `type` | Tipo de movimiento permitido. |
| `total_delta` | Variación de la línea total. |
| `used_delta` | Variación del saldo utilizado. |
| `total_before` | Línea total anterior. |
| `total_after` | Línea total posterior. |
| `used_before` | Saldo utilizado anterior. |
| `used_after` | Saldo utilizado posterior. |
| `available_before` | Saldo disponible anterior. |
| `available_after` | Saldo disponible posterior. |
| `source_type` | Tipo del registro que originó el movimiento. |
| `source_id` | Identificador del registro origen. |
| `actor_user_id` | Usuario que inició o ejecutó. |
| `authorized_by_user_id` | Autoridad cuando corresponda. |
| `branch_id` | Sucursal dentro de la cual ocurrió. |
| `reason` | Motivo. |
| `configuration_snapshot` | Regla o configuración utilizada cuando corresponda. |
| `occurred_at` | Fecha y hora del movimiento. |
| `idempotency_key` | Clave única para evitar duplicados. |

Tipos permitidos:

| Tipo | Efecto |
| --- | --- |
| `INITIAL_AUTHORIZATION` | Establece la línea inicial. |
| `INCREASE` | Aumenta la línea total. |
| `VOUCHER_FULFILLED` | Aumenta el saldo utilizado por el capital feriado. |
| `CAPITAL_RECOVERED` | Reduce el saldo utilizado por capital cubierto. |
| `AUTHORIZED_CORRECTION` | Registra un movimiento compensatorio autorizado. |

Reglas:

- Un movimiento insertado no se edita ni se elimina.
- Una corrección crea otro movimiento.
- Cada origen financiero debe ser idempotente.
- Los saldos anteriores y posteriores deben permitir reconstruir toda la línea.
- `source_type + source_id + type` debe impedir aplicar dos veces el mismo efecto.

### 10.3 `credit_usage_restrictions`

Campos mínimos:

| Campo | Regla |
| --- | --- |
| `id` | Identificador. |
| `credit_line_id` | Línea restringida. |
| `trigger_type` | `INITIAL_AUTHORIZATION` o `INCREASE`. |
| `trigger_id` | Autorización inicial o solicitud de incremento. |
| `base_total_authorized` | Línea total sobre la que se calcula el 50 %. |
| `percentage` | `0.50`. |
| `tolerance_amount` | Tolerancia vigente congelada al crear la restricción. |
| `reference_amount` | Resultado de línea total por 50 %. |
| `status` | `ACTIVE`, `BOUND` o `CONSUMED`. |
| `bound_voucher_id` | Vale vinculado, cuando exista. |
| `bound_at` | Fecha de vinculación. |
| `consumed_by_voucher_id` | Vale que consumió la restricción. |
| `consumed_at` | Fecha de consumo. |
| `created_at` | Fecha de activación. |

Los límites inferior y superior deben calcularse nuevamente con el saldo disponible transaccional al validar o feriar. No se deben confiar únicamente en límites calculados durante una consulta anterior.

### 10.4 `credit_increase_requests`

Campos mínimos:

| Campo | Regla |
| --- | --- |
| `id` | Identificador. |
| `folio` | Folio único de la solicitud. |
| `distributor_id` | Distribuidora solicitante. |
| `branch_id` | Sucursal responsable al crearla. |
| `coordinator_id` | Coordinador responsable al crearla. |
| `requested_amount` | Importe solicitado. |
| `recommended_amount` | Importe recomendado por el coordinador. |
| `authorized_amount` | Importe decidido por el gerente. |
| `origin_type` | Solicitud normal o derivada de crédito insuficiente. |
| `product_amount` | Importe del producto, cuando originó la solicitud. |
| `available_balance_snapshot` | Saldo disponible al solicitar. |
| `required_difference` | Diferencia calculada, cuando corresponda. |
| `total_authorized_snapshot` | Línea total al solicitar. |
| `used_balance_snapshot` | Saldo utilizado al solicitar. |
| `status` | Estado controlado por transición. |
| `request_reason` | Motivo de la distribuidora. |
| `coordinator_reason` | Motivo o comentario de revisión. |
| `manager_reason` | Motivo o comentario de la decisión. |
| `requested_by_user_id` | Usuario de la distribuidora. |
| `reviewed_by_user_id` | Coordinador que revisó. |
| `decided_by_user_id` | Gerente que decidió. |
| `requested_at` | Fecha de solicitud. |
| `reviewed_at` | Fecha de revisión. |
| `decided_at` | Fecha de decisión. |
| `restriction_id` | Restricción creada cuando se autoriza. |
| `lock_version` | Versión para evitar decisiones sobre información desactualizada. |
| `created_at` | Fecha de creación. |
| `updated_at` | Fecha de última transición. |

No se elimina físicamente ninguna solicitud.

### 10.5 Índices y restricciones

Implementar como mínimo:

- Índice único de línea por distribuidora.
- Índices por distribuidora, sucursal, coordinador, estado y fecha de solicitud.
- Índice único por folio.
- Índice único de idempotencia en movimientos.
- Restricción de importes no negativos.
- Restricción de saldos válidos.
- Llaves foráneas hacia distribuidora, sucursal, usuarios, línea, solicitud, movimiento y vale cuando corresponda.
- Protección contra eliminación física de líneas, movimientos, solicitudes y restricciones consumidas.

---

## 11. Submódulos de desarrollo

### B01 — Fundamento de crédito y persistencia

Implementar:

- Módulo `Credit`.
- Migraciones.
- Modelos de persistencia.
- Agregado de línea de crédito.
- Entidad de movimiento.
- Entidad de restricción.
- Entidad de solicitud de incremento.
- Value objects monetarios.
- Enums de movimientos, estados, orígenes y restricciones.
- Repositorios y mappers.
- Restricciones e índices de base de datos.

Debe quedar probado que una distribuidora no puede tener dos líneas y que ningún saldo inválido puede persistirse.

### B02 — Registro de línea inicial

Crear el caso de uso interno que recibirá de M04:

- Distribuidora autorizada.
- Importe autorizado.
- Gerente autorizador.
- Sucursal.
- Motivo.
- Identificador de la autorización final.
- Contexto de auditoría.

El caso de uso debe:

1. Validar que la autorización final sea válida.
2. Validar que la distribuidora no tenga una línea previa.
3. Crear la línea con saldo utilizado en cero.
4. Establecer el saldo disponible igual a la línea total.
5. Insertar `INITIAL_AUTHORIZATION`.
6. Obtener y congelar la tolerancia vigente.
7. Crear la restricción inicial del 50 %.
8. Publicar los eventos correspondientes.
9. Ejecutar todo en una sola transacción.

La línea inicial no se calcula automáticamente. M07 registra exclusivamente el importe introducido y autorizado por el gerente.

### B03 — Consulta de línea e historial

Implementar consultas para:

- Resumen de línea.
- Restricción activa.
- Rango vigente del 50 %.
- Historial paginado de movimientos.
- Solicitudes de incremento.
- Detalle de una solicitud.

La respuesta del resumen debe contener:

- Línea total.
- Saldo utilizado.
- Saldo disponible.
- Capital recuperado acumulado.
- Existencia de restricción.
- Referencia del 50 %.
- Tolerancia.
- Límite inferior actual.
- Límite superior actual.
- Estado de la restricción.
- Fecha del último movimiento.

No exponer datos de otras sucursales o distribuidoras fuera del alcance del actor.

### B04 — Regla inicial del 50 %

Implementar:

- Creación de la restricción inicial.
- Cálculo decimal exacto.
- Consulta del rango.
- Validación de capital.
- Vinculación transaccional con un vale.
- Liberación de la vinculación.
- Consumo al feriar.

Casos obligatorios:

- Capital exactamente en el límite inferior.
- Capital exactamente en el límite superior.
- Capital debajo del rango.
- Capital arriba del rango.
- Saldo disponible menor al límite teórico superior.
- Límite superior menor al inferior.
- Tolerancia configurada distinta de `$500.00`.
- Recuperación de línea sin reactivación.

### B05 — Solicitud de incremento

Implementar el caso de uso para que la distribuidora:

- Consulte su línea.
- Registre un importe solicitado.
- Registre el motivo.
- Genere una solicitud vinculada a su propia cuenta.
- Reciba folio y estado.

Cuando se origine desde un producto mayor al saldo:

- Recibir el importe del producto.
- Volver a consultar el saldo actual.
- Calcular la diferencia en backend.
- No confiar en una diferencia enviada por el cliente.

La creación debe ser idempotente y auditable.

### B06 — Revisión del coordinador

Implementar:

- Consulta de solicitudes bajo responsabilidad del coordinador.
- Detalle con información necesaria para la revisión.
- Rechazo desde `SOLICITADO`.
- Preautorización desde `SOLICITADO`.
- Registro del importe recomendado.
- Registro del motivo y evidencia disponible.
- Auditoría y eventos.

El coordinador no puede:

- Revisar distribuidoras no asignadas.
- Actuar fuera de su sucursal.
- Modificar solicitudes ya revisadas.
- Actualizar la línea.
- Aprobar finalmente.

### B07 — Decisión gerencial

Implementar:

- Decisión del gerente general.
- Decisión del gerente de sucursal dentro de su alcance.
- Autorización total.
- Autorización parcial.
- Rechazo.
- Reautenticación obligatoria.
- Control de versión.
- Auditoría.

Cuando se autoriza:

1. Bloquear la solicitud y la línea.
2. Confirmar que la solicitud sigue preautorizada.
3. Confirmar la versión de la línea.
4. Calcular la nueva línea total.
5. Insertar el movimiento `INCREASE`.
6. Actualizar el saldo materializado.
7. Crear una restricción del 50 %.
8. Vincular la restricción con la solicitud.
9. Publicar eventos.
10. Confirmar la transacción.

No debe quedar una línea aumentada sin su movimiento y restricción correspondientes.

### B08 — Ciclo de vida de la restricción posterior al incremento

Implementar:

- Activación después del incremento.
- Estado `RESTRICCION_50_ACTIVA` en el proceso.
- Vinculación con el primer vale posterior.
- Validación repetida al feriar.
- Consumo transaccional.
- Estado `COMPLETADO`.
- Regreso a `ACTIVE` cuando el vale vinculado se cancela o rechaza.

La restricción debe permanecer activa aunque:

- Se genere un folio que después sea rechazado.
- Se libere un vale que todavía no se feria.
- El saldo cambie antes del feriado.

### B09 — Utilización de línea por vales

Crear contratos internos para:

- Consultar elegibilidad financiera.
- Vincular una restricción.
- Liberar una vinculación.
- Aplicar el capital de un vale feriado.

Al aplicar un vale feriado:

1. Bloquear la línea.
2. Releer movimientos y saldo materializado.
3. Recalcular saldo disponible.
4. Validar que el capital no supere el saldo.
5. Validar la restricción aplicable.
6. Insertar `VOUCHER_FULFILLED`.
7. Aumentar el saldo utilizado.
8. Disminuir el saldo disponible.
9. Consumir la restricción, cuando corresponda.
10. Confirmar todo junto con el feriado.

Dos vales concurrentes no pueden exceder la línea.

### B10 — Recuperación de línea

Crear el contrato interno que utilizará el módulo de pagos.

Debe recibir:

- Línea o distribuidora afectada.
- Importe aplicado a capital.
- Pago o asignación que lo originó.
- Usuario o proceso ejecutor.
- Autorización, cuando corresponda.
- Clave de idempotencia.

Debe:

1. Bloquear la línea.
2. Validar que el origen sea conciliado.
3. Limitar la recuperación al saldo utilizado.
4. Insertar `CAPITAL_RECOVERED`.
5. Reducir el saldo utilizado.
6. Aumentar el saldo disponible sin superar la línea total.
7. Actualizar el acumulado recuperado.
8. No crear ni reactivar una restricción del 50 %.

M07 no determina cuánto del pago corresponde a capital; recibe ese resultado del módulo financiero responsable.

### B11 — Seguridad, auditoría y eventos

Implementar:

- Policies por rol y alcance.
- Verificación de asignación coordinador-distribuidora.
- Alcance de sucursal.
- Propiedad de la cuenta de distribuidora.
- Solo lectura global para administrador.
- Reautenticación gerencial.
- Auditoría de cada transición y movimiento.
- Eventos de dominio y publicación transaccional.
- Respuestas sin información sensible o fuera de alcance.

### B12 — API, documentación y pruebas de cierre

Implementar:

- Form Requests.
- Resources.
- Controladores.
- Rutas.
- Documentación OpenAPI.
- Ejemplos de solicitudes y respuestas.
- Catálogo de errores.
- Pruebas unitarias.
- Pruebas feature.
- Pruebas de autorización.
- Pruebas de concurrencia.
- Pruebas de idempotencia.
- Pruebas de reconstrucción del saldo desde movimientos.

---

## 12. API

Las rutas deben quedar bajo la versión vigente de la API y protegidas por autenticación.

### 12.1 Consultar línea

```http
GET /distributors/{distributor}/credit-line
```

Acceso:

- Gerente general: global.
- Gerente de sucursal: su sucursal.
- Coordinador: distribuidoras asignadas.
- Administrador: global, solo lectura.
- Distribuidora: únicamente su propia línea.

### 12.2 Consultar movimientos

```http
GET /distributors/{distributor}/credit-line/movements
```

Debe permitir paginación y filtros por:

- Tipo.
- Fecha inicial.
- Fecha final.
- Registro origen.

### 12.3 Crear solicitud de incremento

```http
POST /distributors/{distributor}/credit-increase-requests
```

Solo la distribuidora propietaria puede crearla.

Ejemplo:

```json
{
  "requested_amount": "10000.00",
  "reason": "Incremento solicitado para continuar operaciones"
}
```

Origen por crédito insuficiente:

```json
{
  "requested_amount": "5000.00",
  "reason": "El producto seleccionado supera el saldo disponible",
  "origin": {
    "type": "INSUFFICIENT_CREDIT",
    "product_amount": "15000.00"
  }
}
```

El backend vuelve a calcular la diferencia.

### 12.4 Listar solicitudes

```http
GET /credit-increase-requests
```

Filtros:

- Estado.
- Sucursal.
- Coordinador.
- Distribuidora.
- Fecha inicial.
- Fecha final.

Los filtros siempre quedan limitados por el alcance del usuario.

### 12.5 Consultar solicitud

```http
GET /credit-increase-requests/{creditIncreaseRequest}
```

### 12.6 Preautorizar o rechazar como coordinador

```http
POST /credit-increase-requests/{creditIncreaseRequest}/preauthorize
```

Preautorización:

```json
{
  "decision": "PREAUTHORIZE",
  "recommended_amount": "8000.00",
  "reason": "Historial y comportamiento revisados"
}
```

Rechazo:

```json
{
  "decision": "REJECT",
  "reason": "La solicitud no fue preautorizada"
}
```

### 12.7 Decisión gerencial

```http
POST /credit-increase-requests/{creditIncreaseRequest}/manager-decision
```

Autorización total:

```json
{
  "decision": "AUTHORIZE",
  "authorized_amount": "10000.00",
  "reason": "Incremento autorizado",
  "reauthentication_id": "identificador-de-reautenticacion"
}
```

Autorización parcial:

```json
{
  "decision": "AUTHORIZE",
  "authorized_amount": "7000.00",
  "reason": "Se autoriza un importe menor",
  "reauthentication_id": "identificador-de-reautenticacion"
}
```

Rechazo:

```json
{
  "decision": "REJECT",
  "reason": "Incremento no autorizado",
  "reauthentication_id": "identificador-de-reautenticacion"
}
```

---

## 13. Permisos

| Acción | GG | GS | Coordinador | Administrador | Distribuidora | Otros |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Consultar cualquier línea | Sí | No | No | Sí, lectura | No | No |
| Consultar líneas de su sucursal | Sí | Sí | Solo asignadas | Sí, lectura | No | No |
| Consultar su propia línea | Sí | Según alcance | Según asignación | Sí, lectura | Sí | No |
| Registrar línea inicial | Sí, mediante autorización | Sí, su sucursal | No | No | No | No |
| Solicitar incremento | No | No | No | No | Sí, propia | No |
| Preautorizar incremento | No | No | Sí, asignadas | No | No | No |
| Rechazar como coordinador | No | No | Sí, asignadas | No | No | No |
| Decidir incremento | Sí, global | Sí, su sucursal | No | No | No | No |
| Modificar saldos directamente | No | No | No | No | No | No |
| Consultar historial | Sí | Sí, su sucursal | Sí, asignadas | Sí, lectura | Sí, propio | No |

Los servicios internos de vales y pagos actúan con identidad de proceso y contexto del usuario que originó la operación. No sustituyen las autorizaciones funcionales.

---

## 14. Auditoría

Cada operación relevante debe registrar:

- Identificador.
- Tipo de evento.
- Usuario solicitante.
- Usuario revisor.
- Usuario autorizador.
- Usuario o proceso ejecutor.
- Rol.
- Sucursal.
- Distribuidora.
- Sesión y dispositivo disponibles.
- Fecha y hora en `America/Monterrey`.
- Estado anterior.
- Estado nuevo.
- Línea total anterior y nueva.
- Saldo utilizado anterior y nuevo.
- Saldo disponible anterior y nuevo.
- Importe solicitado.
- Importe recomendado.
- Importe autorizado.
- Motivo.
- Evidencia disponible.
- Resultado.
- Configuración utilizada.
- Tolerancia aplicada.
- Registro origen.
- Clave de idempotencia.

Eventos auditables mínimos:

- Línea inicial autorizada.
- Movimiento inicial.
- Restricción inicial creada.
- Solicitud de incremento registrada.
- Solicitud rechazada por coordinador.
- Solicitud preautorizada.
- Incremento rechazado por gerente.
- Incremento autorizado totalmente.
- Incremento autorizado parcialmente.
- Movimiento de incremento.
- Restricción posterior creada.
- Restricción vinculada.
- Vinculación liberada.
- Restricción consumida.
- Capital utilizado por vale feriado.
- Capital recuperado.
- Intento fuera de alcance.
- Conflicto de versión.
- Intento duplicado.

No se permite editar ni eliminar auditorías.

---

## 15. Eventos

M07 debe publicar eventos internos después de confirmar la transacción.

| Evento | Destinatarios funcionales |
| --- | --- |
| `CreditLineInitiallyAuthorized` | Distribuidora y coordinador. |
| `InitialFiftyPercentRestrictionActivated` | Distribuidora y coordinador. |
| `CreditIncreaseRequested` | Coordinador. |
| `CreditIncreasePreauthorized` | Gerente correspondiente. |
| `CreditIncreaseFullyAuthorized` | Distribuidora y coordinador. |
| `CreditIncreasePartiallyAuthorized` | Distribuidora y coordinador. |
| `CreditIncreaseRejected` | Distribuidora y coordinador. |
| `PostIncreaseFiftyPercentRestrictionActivated` | Distribuidora y coordinador. |
| `FiftyPercentRestrictionConsumed` | Distribuidora y coordinador. |
| `CreditCapitalUsed` | Auditoría y consumidores financieros. |
| `CreditCapitalRecovered` | Distribuidora y consumidores financieros. |

Cada evento debe incluir:

- Identificador único.
- Tipo.
- Fecha y hora.
- Distribuidora.
- Sucursal.
- Actor.
- Autorizador cuando corresponda.
- Importe.
- Saldos anteriores y posteriores cuando corresponda.
- Solicitud o movimiento origen.
- Motivo.

La publicación debe ser idempotente y no debe provocar que una operación financiera se repita.

---

## 16. Errores de dominio

| Código | Uso |
| --- | --- |
| `AUTH_SCOPE_DENIED` | Actor sin permiso, sucursal, asignación o propiedad. |
| `REAUTHENTICATION_REQUIRED` | La decisión gerencial no tiene reautenticación vigente. |
| `RESOURCE_VERSION_CONFLICT` | La solicitud o línea cambió desde que fue consultada. |
| `CREDIT_LINE_ALREADY_EXISTS` | La distribuidora ya tiene línea. |
| `CREDIT_LINE_NOT_FOUND` | No existe línea para la distribuidora. |
| `CREDIT_INVALID_BALANCE` | La operación viola las invariantes de saldo. |
| `CREDIT_INSUFFICIENT` | El capital supera el saldo disponible. |
| `CREDIT_50_PERCENT_RULE_NOT_SATISFIED` | El capital está fuera del rango especial. |
| `CREDIT_50_PERCENT_NO_ADMISSIBLE_AMOUNT` | El límite superior actual es menor al límite inferior. |
| `CREDIT_RESTRICTION_ALREADY_BOUND` | Otro vale está vinculado a la restricción. |
| `CREDIT_RESTRICTION_NOT_ACTIVE` | La restricción no puede vincularse o consumirse. |
| `CREDIT_RESTRICTION_VOUCHER_MISMATCH` | El vale no corresponde a la vinculación activa. |
| `CREDIT_INCREASE_INVALID_STATE` | La transición solicitada no corresponde al estado actual. |
| `CREDIT_INCREASE_AMOUNT_INVALID` | Importe solicitado, recomendado o autorizado inválido. |
| `CREDIT_INCREASE_NOT_PREAUTHORIZED` | Se intentó decidir sin preautorización. |
| `CREDIT_INCREASE_EXCEEDS_REQUESTED` | El gerente intentó autorizar más de lo solicitado. |
| `CREDIT_MOVEMENT_DUPLICATE` | El origen financiero ya fue aplicado. |
| `CAPITAL_RECOVERY_EXCEEDS_USED_BALANCE` | La recuperación solicitada supera el saldo utilizado; debe limitarse al capital pendiente. |

La API no debe devolver trazas, SQL, secretos ni datos fuera del alcance del usuario.

---

## 17. Transacciones, concurrencia e idempotencia

### 17.1 Operaciones transaccionales

Deben ejecutarse en una sola transacción:

- Creación de línea inicial, movimiento y restricción.
- Autorización de incremento, movimiento, actualización de saldo y restricción.
- Vinculación de restricción.
- Feriado, utilización de línea y consumo de restricción.
- Recuperación de capital y actualización de saldo.

### 17.2 Bloqueos

Antes de cambiar saldos:

1. Bloquear `credit_lines`.
2. Bloquear la solicitud o restricción involucrada.
3. Releer saldos.
4. Validar la versión.
5. Recalcular la operación.
6. Insertar el movimiento.
7. Actualizar el saldo materializado.

No se debe validar saldo fuera de la transacción y reutilizarlo después como si continuara vigente.

### 17.3 Idempotencia

Debe impedirse:

- Crear dos líneas desde la misma autorización.
- Aplicar dos veces una decisión gerencial.
- Insertar dos movimientos por el mismo incremento.
- Utilizar dos veces el mismo vale.
- Recuperar dos veces capital desde la misma asignación de pago.
- Publicar dos veces el mismo evento funcional.

Una repetición válida debe devolver el resultado ya existente sin duplicar efectos.

### 17.4 Reconstrucción

Debe existir una prueba o servicio de verificación que reconstruya:

- Línea total.
- Saldo utilizado.
- Saldo disponible.
- Capital recuperado.

La reconstrucción desde `credit_line_movements` debe coincidir con `credit_lines`.

---

## 18. Validaciones obligatorias

### 18.1 Línea inicial

- Distribuidora autorizada dentro del flujo de activación.
- Autorización final existente.
- Gerente con alcance.
- Importe decimal válido.
- Ausencia de línea previa.
- Sucursal coherente.
- Tolerancia global publicada.

### 18.2 Solicitud

- Actor distribuidora.
- Distribuidora propietaria.
- Línea existente.
- Importe solicitado mayor que cero.
- Motivo presente.
- Producto y diferencia recalculados cuando corresponda.

### 18.3 Preautorización

- Solicitud en `SOLICITADO`.
- Coordinador responsable.
- Misma sucursal.
- Importe recomendado mayor que cero.
- Motivo o comentario registrado.

### 18.4 Decisión gerencial

- Solicitud en `PREAUTORIZADO`.
- Gerente autorizado.
- Alcance correcto.
- Reautenticación vigente.
- Importe autorizado mayor que cero cuando se aprueba.
- Importe autorizado no mayor al solicitado.
- Motivo registrado.
- Versión vigente.

### 18.5 Uso de línea

- Distribuidora activa.
- Ausencia de bloqueo de morosidad de la distribuidora.
- Línea existente.
- Capital positivo.
- Saldo suficiente.
- Restricción satisfecha cuando exista.
- Vale no aplicado previamente.

El adeudo informativo del cliente final no participa en estas validaciones.

### 18.6 Recuperación

- Origen conciliado.
- Importe de capital positivo.
- Origen no aplicado previamente.
- Saldo utilizado mayor que cero.
- Recuperación limitada al capital pendiente.

---

## 19. Pruebas

### 19.1 Pruebas unitarias

Probar:

- Cálculo del saldo disponible.
- Invariantes de la línea.
- Aplicación de cada tipo de movimiento.
- Cálculo de referencia, límite inferior y límite superior.
- Redondeo y precisión.
- Transiciones de solicitud.
- Transiciones de restricción.
- Autorización total y parcial.
- Rechazos.
- Recuperación limitada al saldo utilizado.
- Recuperación sin reactivar la regla.

### 19.2 Pruebas de integración

Probar:

- Creación de línea inicial completa.
- Restricción inicial creada en la misma transacción.
- Solicitud de incremento.
- Preautorización.
- Autorización total.
- Autorización parcial.
- Rechazo del coordinador.
- Rechazo del gerente.
- Movimiento y saldo después del incremento.
- Restricción posterior al incremento.
- Vinculación, liberación y consumo.
- Utilización de línea por un vale feriado.
- Recuperación de capital.
- Reconstrucción del saldo.

### 19.3 Pruebas de permisos

Probar:

- Distribuidora consultando su propia línea.
- Distribuidora intentando consultar otra línea.
- Coordinador actuando sobre una distribuidora asignada.
- Coordinador intentando actuar sobre una no asignada.
- Gerente de sucursal dentro y fuera de su sucursal.
- Gerente general con alcance global.
- Administrador consultando sin poder modificar.
- Cajera, verificador u otro rol intentando decidir incrementos.
- Decisión gerencial sin reautenticación.

### 19.4 Pruebas de concurrencia

Probar:

- Dos vales intentando utilizar el mismo saldo.
- Dos vales intentando vincular la misma restricción.
- Dos decisiones sobre la misma solicitud.
- Recuperación y utilización simultáneas.
- Dos intentos de crear línea inicial.
- Conflicto de versión después de consultar.

En todos los casos los saldos deben permanecer válidos.

### 19.5 Pruebas de idempotencia

Probar:

- Repetición de autorización inicial.
- Repetición de autorización de incremento.
- Repetición de feriado del mismo vale.
- Repetición de recuperación del mismo pago.
- Repetición de publicación de evento.

### 19.6 Casos funcionales mínimos

1. Línea inicial de `$20,000.00` con tolerancia de `$500.00`:
   - Referencia: `$10,000.00`.
   - Límite inferior: `$9,500.00`.
   - Límite superior: `$10,500.00`, limitado por el saldo disponible.
2. Vale exactamente por `$9,500.00`: permitido.
3. Vale exactamente por `$10,500.00`: permitido.
4. Vale por `$9,400.00`: rechazado por la regla.
5. Vale por `$10,600.00`: rechazado por la regla.
6. Recuperación de toda la línea después del primer vale: no reactiva la restricción inicial.
7. Incremento autorizado:
   - Se suma a la línea total.
   - El 50 % se calcula sobre la nueva línea total.
   - No se calcula solo sobre el incremento.
8. Incremento parcialmente autorizado:
   - La línea aumenta solo por el importe autorizado.
   - La solicitud conserva los tres importes.
9. Producto mayor al saldo:
   - El vale no continúa.
   - La diferencia se calcula en backend.
   - Puede iniciarse la solicitud.
10. Vale vinculado y rechazado:
    - La restricción sigue activa.
11. Vale vinculado y feriado:
    - Se usa el capital.
    - Se consume la restricción.
    - El saldo restante queda disponible.

---

## 20. Reglas de implementación

- Usar `strict_types=1` en clases PHP propias cuando corresponda al estándar del proyecto.
- Tipar argumentos, retornos, propiedades y DTOs.
- Documentar con PHPDoc reglas, contratos, excepciones y estructuras complejas.
- Evitar PHPDoc redundante cuando el tipo ya sea completamente evidente.
- No usar arreglos sin estructura para representar dinero, líneas o decisiones.
- Usar enums para estados y tipos.
- Usar value objects para importes y rangos.
- No usar valores monetarios en punto flotante.
- No hardcodear la tolerancia.
- No colocar lógica financiera en controladores, Form Requests, Resources o modelos Eloquent.
- No permitir asignación masiva de estados o saldos.
- No exponer endpoints genéricos `PATCH` para editar línea, saldo o estado.
- No eliminar movimientos, solicitudes o restricciones históricas.
- Usar movimientos compensatorios para correcciones autorizadas.
- Evitar consultas N+1.
- Paginar historiales y listados.
- Documentar la API en OpenAPI.
- Mantener mensajes de error estables mediante códigos de dominio.
- Agregar pruebas por cada regla y transición.

---

## 21. Orden de desarrollo

El equipo debe avanzar en este orden:

1. B01 — Fundamento de crédito y persistencia.
2. B02 — Registro de línea inicial.
3. B03 — Consulta de línea e historial.
4. B04 — Regla inicial del 50 %.
5. B05 — Solicitud de incremento.
6. B06 — Revisión del coordinador.
7. B07 — Decisión gerencial.
8. B08 — Restricción posterior al incremento.
9. B09 — Utilización de línea por vales.
10. B10 — Recuperación de línea.
11. B11 — Seguridad, auditoría y eventos.
12. B12 — API, documentación y pruebas de cierre.

Cada bloque debe quedar probado antes de continuar con el siguiente.

---

## 22. Criterios de aceptación

El módulo se considera terminado cuando:

- Cada distribuidora puede tener una sola línea.
- La línea inicial se crea desde una autorización gerencial válida.
- La línea inicial no se calcula automáticamente.
- Todo cambio financiero genera un movimiento inmutable.
- El saldo materializado coincide con el libro de movimientos.
- La regla inicial del 50 % funciona con la tolerancia configurada.
- La recuperación de línea no reactiva la regla.
- La distribuidora puede solicitar incrementos propios.
- El coordinador puede preautorizar o rechazar únicamente sus casos.
- El coordinador no puede modificar la línea.
- El gerente puede autorizar total, parcialmente o rechazar según su alcance.
- Una autorización actualiza línea, movimiento y restricción en una sola transacción.
- El 50 % posterior se calcula sobre la nueva línea total.
- La restricción solo se consume al feriar el primer vale correspondiente.
- Cancelar o rechazar el vale no consume la restricción.
- Dos operaciones concurrentes no pueden exceder la línea.
- La recuperación se limita al capital pendiente.
- El adeudo informativo del cliente final no bloquea la operación.
- Todos los endpoints aplican permisos y alcance.
- Las decisiones gerenciales exigen reautenticación.
- Los intentos duplicados no duplican efectos.
- La auditoría conserva actores, estados, importes, saldos, motivo y configuración.
- Los eventos críticos se publican una sola vez.
- La documentación OpenAPI está actualizada.
- Las pruebas unitarias, de integración, permisos, concurrencia e idempotencia pasan.

---

## 23. Entregables

- Código del módulo `Credit`.
- Migraciones.
- Modelos y mappers.
- Entidades, agregados, value objects y enums.
- Repositorios.
- Commands, handlers, queries y DTOs.
- Policies y Form Requests.
- Controllers y Resources.
- Rutas.
- Servicios internos para línea inicial, incrementos, utilización y recuperación.
- Eventos de dominio.
- Auditoría.
- Catálogo de errores.
- Documentación OpenAPI.
- Pruebas automatizadas.
