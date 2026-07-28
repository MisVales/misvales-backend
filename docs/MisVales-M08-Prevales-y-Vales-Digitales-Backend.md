# M08 — Prevales y vales digitales — Backend Laravel

## 1. Objetivo

Desarrollar el módulo backend encargado de generar y consultar prevales y vales digitales.

El módulo debe:

- Determinar automáticamente si la operación es un `PREVALE` o un `VALE_DIGITAL`.
- Validar que el cliente pertenezca actualmente a la distribuidora autenticada.
- Validar que la distribuidora pueda otorgar nuevos vales.
- Validar el producto, la categoría, la línea disponible y la restricción vigente del 50 %.
- Generar un folio único.
- Congelar las condiciones financieras aplicables al momento de generar el vale.
- Calcular y materializar las parcialidades del vale.
- Dejar el vale en estado `GENERADO`, listo para el proceso de caja del M09.
- Conservar trazabilidad, idempotencia, historial y auditoría.

El cliente final continúa siendo un registro interno. No tiene usuario, no inicia sesión y no ejecuta ninguna acción dentro de este módulo.

---

## 2. Dependencias

| Módulo | Dependencia utilizada |
| --- | --- |
| M01 — Acceso | Sesión, usuario autenticado, permisos, alcance y auditoría de seguridad. |
| M02 — Organización | Sucursal, roles y alcance efectivo. |
| M03 — Configuraciones y catálogos | Versiones publicadas de productos, categorías, tolerancia y parámetros financieros. |
| M05 — Distribuidoras | Estado, sucursal, coordinador y categoría vigente de la distribuidora. |
| M06 — Clientes finales y cartera informativa | Cliente, asignación vigente, domicilio, cuenta bancaria e historial informativo. |
| M07 — Línea de crédito e incrementos | Línea total, saldo utilizado, saldo disponible y restricción vigente del 50 %. |

Este módulo prepara información para:

- M09 — Caja, modificaciones autorizadas y feriado.
- M10 — Relaciones y cortes.
- M16 — Reportes.
- M17 — Notificaciones.
- M18 — Auditoría, archivos y evidencias.

---

## 3. Alcance

### 3.1 Incluye

- Estructura del módulo Laravel `Voucher`.
- Entidad y persistencia de vales.
- Tipos `PREVALE` y `VALE_DIGITAL`.
- Estado inicial `GENERADO`.
- Estados necesarios para representar el ciclo completo del vale.
- Determinación automática del tipo de vale.
- Validaciones de elegibilidad.
- Validación de producto y categoría vigentes.
- Validación de línea disponible.
- Aplicación de la regla especial del 50 %.
- Asociación de una restricción del 50 % con un vale pendiente.
- Generación de folio único.
- Snapshot financiero inmutable.
- Cálculo del vale.
- Generación de parcialidades.
- Consulta individual y listados por alcance.
- Idempotencia de la generación.
- Eventos de dominio y eventos de salida.
- Auditoría.
- Pruebas unitarias, de integración y funcionales.
- Documentación de la API y del código.

### 3.2 No incluye

- Alta o modificación general del cliente final.
- Administración de la cartera informativa.
- Creación o modificación de productos y categorías.
- Autorización de líneas o incrementos.
- Apertura del folio en caja.
- Validación física de identidad o documentos.
- Solicitud, autorización o uso del token de modificación.
- Liberación del vale.
- Depósito manual.
- Captura del número de transacción.
- Movimiento `VOUCHER_FULFILLED` de la línea de crédito.
- Feriado del vale.
- Generación de relaciones o cortes.
- Conciliación bancaria.
- Pagos, puntos, riesgo, morosidad o transferencias.
- Interfaces Angular.
- Contenedores, servidores, observabilidad o cualquier trabajo de infraestructura.

Las acciones de caja y feriado se desarrollan en el M09. El M08 únicamente entrega un vale válido en estado `GENERADO`.

---

## 4. Reglas obligatorias

### 4.1 Producto y vale

En MisVales:

> Producto = vale.

El producto seleccionado define:

- Capital nominal.
- Comisión del préstamo.
- Interés simple por quincena.
- Seguro.
- Número de quincenas.

La categoría vigente de la distribuidora define su porcentaje de ganancia.

El importe no se captura libremente al generar el vale. El backend toma el monto de la versión publicada y vigente del producto.

### 4.2 Determinación del tipo

El backend determina el tipo. Angular no puede decidirlo.

| Condición | Tipo |
| --- | --- |
| El cliente nunca ha tenido un vale dentro de MisVales | `PREVALE` |
| El cliente ya tiene al menos un vale dentro de MisVales | `VALE_DIGITAL` |

Reglas:

- Solo puede existir un `PREVALE` por cliente dentro de todo MisVales.
- El primer vale después de una transferencia es `VALE_DIGITAL`.
- Cambiar de distribuidora no reinicia el historial del cliente.
- Rechazar o cancelar el primer folio no elimina su historial. Cualquier folio creado después se considera posterior y se genera como `VALE_DIGITAL`.
- La determinación debe ejecutarse dentro de la misma transacción que crea el vale.
- Debe bloquearse el registro del cliente durante esta decisión para evitar dos prevales concurrentes.
- PostgreSQL debe impedir que dos solicitudes concurrentes creen más de un `PREVALE` para el mismo cliente.

La clasificación se basa en la existencia histórica del primer vale y no únicamente en que haya llegado a `FERIADO`. Un prevale rechazado o cancelado continúa siendo el primer vale histórico del cliente y no se elimina.

### 4.3 Adeudo del cliente final

El historial de vales, pagos y adeudos del cliente se muestra a la distribuidora únicamente como información de cartera.

Un adeudo pendiente o vencido del cliente:

- No bloquea automáticamente un prevale.
- No bloquea automáticamente un vale digital.
- No genera estado de morosidad del cliente.
- No requiere autorización gerencial.
- No exige un proceso de regularización o desbloqueo.
- No debe convertirse en una Policy, regla de elegibilidad o error de dominio.

La distribuidora decide si continúa prestando al cliente, utiliza su propia línea de crédito y conserva la obligación de pagar a MisVales.

La validación de saldo cero del cliente se usa exclusivamente en una transferencia. No se utiliza para generar vales.

### 4.4 Restricciones operativas reales

El backend sí debe impedir la generación cuando ocurra cualquiera de estas condiciones:

- Usuario sin permiso.
- Distribuidora distinta de la autenticada.
- Distribuidora deshabilitada.
- Distribuidora con morosidad confirmada y bloqueo de nuevos vales.
- Distribuidora sin sucursal o sin asignación operativa vigente.
- Cliente inexistente.
- Cliente sin asignación vigente con la distribuidora.
- Cliente asociado a otra distribuidora.
- Producto inexistente.
- Producto inactivo, no publicado o fuera de vigencia.
- Producto sin parámetros financieros completos.
- Categoría inexistente, inactiva o sin versión vigente.
- Línea de crédito inexistente o en estado incompatible.
- Producto mayor que el saldo disponible.
- Producto fuera del rango permitido por la regla del 50 %.
- Restricción especial del 50 % ya vinculada con otro vale pendiente.
- Estado concurrente o versión de datos desactualizada.
- Repetición no idempotente de una operación.

No se debe agregar un bloqueo basado en adeudos del cliente.

### 4.5 Momento de uso de la línea

Generar un folio no descuenta la línea.

- El M08 valida la línea disponible al generar.
- El vale permanece sin movimiento financiero de línea en estado `GENERADO`.
- La línea se utiliza únicamente cuando el vale queda `FERIADO` en el M09.
- El M09 debe volver a validar y bloquear la línea antes de feriar.
- Un folio generado no garantiza que podrá feriarse si cambian el saldo, el producto, el estado de la distribuidora o una restricción antes de la operación de caja.

No debe crearse un movimiento `VOUCHER_FULFILLED` en este módulo.

---

## 5. Estados del vale

Implementar el enum de dominio `VoucherStatus` con los estados:

| Estado | Significado |
| --- | --- |
| `GENERADO` | El folio fue creado y espera atención en caja. |
| `VALIDACION_CAJA` | La cajera abrió el folio y está validando la información. |
| `CORRECCION_PENDIENTE` | Existe una diferencia y se requiere el proceso autorizado de modificación. |
| `LIBERADO` | La cajera validó la información y liberó la operación. |
| `FERIADO` | Se realizó el depósito manual y se registró la transacción. |
| `RECHAZADO` | Caja determinó que la operación no procede. |
| `CANCELADO` | El vale fue cancelado antes de concluir el proceso. |

Transiciones canónicas:

| Estado actual | Acción | Estado siguiente | Módulo ejecutor |
| --- | --- | --- | --- |
| — | Generar folio | `GENERADO` | M08 |
| `GENERADO` | Abrir en caja | `VALIDACION_CAJA` | M09 |
| `VALIDACION_CAJA` | Detectar diferencia | `CORRECCION_PENDIENTE` | M09 |
| `CORRECCION_PENDIENTE` | Aplicar token válido | `VALIDACION_CAJA` | M09 |
| `VALIDACION_CAJA` | Validar y liberar | `LIBERADO` | M09 |
| `LIBERADO` | Capturar transacción | `FERIADO` | M09 |
| `VALIDACION_CAJA` | Rechazar | `RECHAZADO` | M09 |
| `GENERADO` | Cancelar | `CANCELADO` | Pendiente de definición funcional |

Reglas:

- `FERIADO`, `RECHAZADO` y `CANCELADO` son estados terminales.
- Ningún controlador puede cambiar el estado directamente.
- Cada transición debe estar implementada como comportamiento de dominio.
- El M08 solo ejecuta la transición de creación a `GENERADO`.
- El enum y las reglas de transición quedan preparados para el M09.
- No se expondrá una operación de cancelación hasta definir quién puede ejecutarla, en qué momento y con qué motivo obligatorio.
- No se implementará expiración o cancelación automática de folios porque no está definida.

---

## 6. Regla del 50 %

### 6.1 Cuándo aplica

La restricción se crea:

- Al autorizar la línea inicial.
- Después de cada incremento autorizado.

No se crea nuevamente cuando la línea se recupera por pagos.

### 6.2 Cálculo

```text
Referencia = Línea total autorizada × 0.50

Límite inferior = máximo(0, Referencia − Tolerancia)

Límite superior = mínimo(Saldo disponible, Referencia + Tolerancia)
```

El capital del producto debe cumplir:

```text
Límite inferior ≤ Capital del producto ≤ Límite superior
```

Si el límite superior es menor que el límite inferior, no existe un producto admisible.

### 6.3 Comportamiento durante la generación

Cuando existe una restricción activa:

1. Bloquear la fila de la línea y la restricción.
2. Recalcular la línea total, el saldo utilizado y el saldo disponible.
3. Resolver la tolerancia vigente asociada con la restricción.
4. Calcular referencia, límite inferior y límite superior.
5. Validar el capital de la versión del producto.
6. Verificar que la restricción no esté vinculada con otro vale pendiente.
7. Crear el vale.
8. Vincular la restricción con el vale creado.
9. Mantener la restricción activa y sin consumir.

La restricción:

- Usa la línea total vigente.
- No usa solamente el importe del incremento.
- Se vincula con un solo vale pendiente.
- No se consume al generar el folio.
- No se consume por rechazo o cancelación.
- Solo se consume al feriar el vale vinculado.
- Mantiene bloqueada la operación normal del saldo restante hasta que el vale vinculado sea feriado.

Si el vale vinculado termina rechazado o cancelado:

- La restricción continúa activa y sin consumir.
- El vínculo histórico con el vale terminal se conserva.
- La ocupación activa se libera para que la restricción pueda vincularse con el siguiente vale.
- El siguiente vale debe cumplir nuevamente el rango del 50 %.

La liberación de la ocupación activa se implementa junto con las transiciones de rechazo y cancelación del módulo que las ejecute.

### 6.4 Sin restricción activa

Cuando no existe una restricción del 50 %:

- El producto puede tener un capital igual o menor que el saldo disponible.
- No se aplica un mínimo especial.
- Recuperar toda la línea mediante pagos no vuelve a activar la regla.

---

## 7. Cálculo financiero del vale

### 7.1 Variables

| Variable | Origen | Significado |
| --- | --- | --- |
| `P` | Versión del producto | Capital nominal. |
| `C` | Versión del producto | Porcentaje de comisión del préstamo. |
| `I` | Versión del producto | Porcentaje de interés simple por quincena. |
| `Q` | Versión del producto | Número de quincenas. |
| `S` | Versión del producto | Seguro. |
| `G` | Versión de categoría | Porcentaje de ganancia de la distribuidora. |

El recargo no forma parte del vale inicial. Se calcula sobre la relación vencida en el módulo correspondiente.

### 7.2 Fórmulas

```text
Comisión del préstamo = P × C

Interés total = P × I × Q

Total base para MisVales =
    P + Comisión del préstamo + S + Interés total

Pago base por quincena =
    Total base para MisVales ÷ Q

Ganancia total de la distribuidora =
    P × G

Ganancia por quincena =
    Ganancia total de la distribuidora ÷ Q

Total a cobrar al cliente por quincena =
    Pago base por quincena + Ganancia por quincena
```

La ganancia por categoría:

- Pertenece a la distribuidora.
- No es la comisión del préstamo.
- No forma parte del importe que la distribuidora entrega a MisVales.
- No recupera línea de crédito.
- Debe guardarse separada de todos los demás componentes.

### 7.3 Precisión

- Usar decimal exacto.
- No usar `float` ni `double`.
- Mantener precisión interna de cuatro decimales.
- No redondear valores intermedios a dos decimales.
- Redondear el resultado monetario final a dos decimales.
- Aplicar redondeo aritmético: si el tercer decimal es 5 o mayor, aumentar el segundo decimal.
- Calcular cada componente por separado.
- La suma de parcialidades debe coincidir exactamente con los totales guardados.
- La última parcialidad debe absorber cualquier diferencia producida por la división.

Los porcentajes se guardan como decimales entre 0 y 1.

---

## 8. Snapshot financiero

Cada vale debe tener un snapshot financiero inmutable.

El snapshot debe conservar como mínimo:

- Identificador del vale.
- Identificador y versión del producto.
- Nombre del producto.
- Capital nominal.
- Porcentaje de comisión del préstamo.
- Importe total de comisión del préstamo.
- Porcentaje de interés por quincena.
- Importe total de interés.
- Importe total del seguro.
- Número de quincenas.
- Identificador y versión de la categoría.
- Nombre de la categoría.
- Porcentaje de ganancia de categoría.
- Ganancia total de la distribuidora.
- Total base para MisVales.
- Pago base por quincena.
- Ganancia por quincena.
- Total a cobrar al cliente por quincena.
- Versión del cálculo utilizada.
- Precisión y regla de redondeo aplicadas.
- Fecha y hora de creación.

Reglas:

- El snapshot se crea en la misma transacción que el vale.
- No se modifica si después cambia el producto.
- No se modifica si después cambia la categoría.
- No se recalcula al cambiar configuraciones.
- No debe depender de consultar versiones actuales para reconstruir un vale histórico.
- No se elimina físicamente.
- Cualquier corrección posterior deberá producir historial y no sobrescribir silenciosamente los valores originales.

---

## 9. Parcialidades del vale

Al generar el vale se deben materializar `Q` parcialidades.

Cada parcialidad debe conservar:

- Identificador.
- Vale.
- Número de pago.
- Total de pagos.
- Capital correspondiente.
- Comisión del préstamo correspondiente.
- Interés correspondiente.
- Seguro correspondiente.
- Pago base correspondiente.
- Ganancia de la distribuidora correspondiente.
- Total a cobrar al cliente.
- Importe exigible a MisVales.
- Estado de incorporación a relación.
- Relación asociada cuando se incorpore en el M10.
- Fecha y hora de creación.

Reglas:

- Deben existir exactamente `Q` parcialidades.
- La numeración inicia en 1 y termina en `Q`.
- Capital, comisión, interés, seguro y ganancia se distribuyen con precisión interna.
- La última parcialidad absorbe los residuos.
- La suma del capital de las parcialidades debe ser exactamente `P`.
- La suma de comisiones debe ser igual a la comisión total.
- La suma de intereses debe ser igual al interés total.
- La suma de seguros debe ser igual a `S`.
- La suma de ganancias debe ser igual a la ganancia total.
- La suma de pagos base debe coincidir con el total base para MisVales.
- Las parcialidades todavía no pertenecen a una relación.
- La asignación a cortes y relaciones corresponde al M10.
- No se deben inventar fechas individuales de vencimiento dentro del M08.

---

## 10. Modelo de datos

### 10.1 Tabla `vouchers`

Campos mínimos:

| Campo | Regla |
| --- | --- |
| `id` | UUID, clave primaria. |
| `folio` | Identificador visible, único e inmutable. |
| `type` | Enum `PREVALE` o `VALE_DIGITAL`. |
| `status` | Enum controlado; inicia en `GENERADO`. |
| `distributor_id` | Distribuidora que otorga el vale. |
| `client_id` | Cliente que recibe el vale. |
| `branch_id` | Sucursal vigente de la distribuidora al generar. |
| `product_version_id` | Versión de producto utilizada. |
| `category_version_id` | Versión de categoría utilizada. |
| `credit_line_id` | Línea validada. |
| `credit_usage_restriction_id` | Restricción del 50 % cuando aplique. |
| `capital_amount` | Capital del producto con decimal exacto. |
| `generated_by` | Usuario distribuidora que generó el folio. |
| `generated_at` | Fecha técnica en UTC. |
| `lock_version` | Control de concurrencia. |
| `created_at` y `updated_at` | Marcas técnicas. |

No usar eliminación física.

### 10.2 Tabla `voucher_financial_snapshots`

Debe contener los valores indicados en la sección de snapshot y una relación uno a uno con `vouchers`.

Aplicar:

- Restricción única por `voucher_id`.
- Decimal exacto.
- Inmutabilidad desde el dominio y la persistencia.
- Sin `soft delete`.

### 10.3 Tabla `voucher_installments`

Debe representar las parcialidades indicadas en la sección 9.

Restricciones mínimas:

- Único compuesto por `voucher_id` y `payment_number`.
- `payment_number` mayor o igual que 1.
- `payment_number` menor o igual que `total_payments`.
- Importes no negativos.
- Relación opcional con el elemento de relación que se agregará en el M10.

### 10.4 Restricciones de base de datos

PostgreSQL debe garantizar:

- `folio` único.
- Un solo snapshot por vale.
- Una sola parcialidad por número dentro del vale.
- Un solo `PREVALE` histórico por cliente.
- Integridad de distribuidora, cliente, sucursal, producto, categoría, línea y restricción.
- Estados y tipos válidos.
- Importes no negativos.
- Consistencia de número total de pagos.

Las validaciones de aplicación no sustituyen las restricciones de base de datos.

---

## 11. Estructura del módulo

```text
app/Modules/Voucher/
├── Application/
│   ├── Commands/
│   │   └── GenerateVoucher/
│   │       ├── Command.php
│   │       └── Handler.php
│   ├── Queries/
│   │   ├── GetVoucher/
│   │   └── ListVouchers/
│   ├── DTOs/
│   ├── Contracts/
│   └── Services/
├── Domain/
│   ├── Aggregates/
│   │   └── Voucher.php
│   ├── Entities/
│   │   ├── VoucherFinancialSnapshot.php
│   │   └── VoucherInstallment.php
│   ├── ValueObjects/
│   │   ├── VoucherFolio.php
│   │   ├── Money.php
│   │   └── Percentage.php
│   ├── Enums/
│   │   ├── VoucherType.php
│   │   └── VoucherStatus.php
│   ├── Rules/
│   ├── Services/
│   │   ├── VoucherEligibilityService.php
│   │   ├── VoucherTypeResolver.php
│   │   ├── VoucherCalculator.php
│   │   └── InstallmentAllocator.php
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
│       └── ModuleServiceProvider.php
├── Presentation/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Authorization/
│   │   └── Policies/
│   └── Routes/
│       └── api.php
└── Tests/
    ├── Unit/
    ├── Integration/
    └── Feature/
```

Reglas:

- Los controladores no contienen reglas financieras.
- Los Form Requests solo validan forma, tipo y presencia de datos.
- Las Policies validan permiso y alcance.
- Los casos de uso coordinan transacciones.
- El dominio determina estados, elegibilidad y cálculos.
- La infraestructura implementa repositorios y persistencia.
- El módulo `Voucher` consume contratos públicos de `Client`, `Distributor`, `Configuration` y `Credit`.
- No consultar directamente tablas internas de otro módulo desde controladores.

---

## 12. Caso de uso: generar vale

### 12.1 Entrada

La distribuidora envía únicamente:

- `client_id`.
- `product_id`.

El backend deriva:

- `distributor_id` desde la sesión.
- Sucursal desde la asignación vigente de la distribuidora.
- Tipo de vale.
- Versión vigente del producto.
- Versión vigente de categoría.
- Línea de crédito.
- Restricción del 50 %.
- Todos los importes.
- Estado inicial.
- Usuario y marcas de auditoría.

No aceptar desde Angular:

- Tipo de vale.
- Estado.
- `distributor_id`.
- `branch_id`.
- Capital escrito manualmente.
- Porcentajes.
- Número de quincenas.
- Versión de producto.
- Versión de categoría.
- Saldo disponible.
- Límite del 50 %.
- Totales calculados.

### 12.2 Flujo transaccional

1. Validar sesión y permiso.
2. Exigir `Idempotency-Key`.
3. Resolver la distribuidora desde el usuario autenticado.
4. Cargar y bloquear al cliente para la determinación del tipo.
5. Validar la asignación vigente cliente-distribuidora.
6. Validar el estado operativo de la distribuidora.
7. Validar su sucursal y categoría vigente.
8. Resolver la versión publicada y vigente del producto.
9. Validar que todos sus parámetros financieros estén completos.
10. Bloquear la línea de crédito.
11. Recalcular saldo disponible desde los valores persistidos.
12. Cargar y bloquear la restricción vigente del 50 %, cuando exista.
13. Validar capital contra saldo y rango permitido.
14. Determinar `PREVALE` o `VALE_DIGITAL`.
15. Generar el folio único.
16. Calcular todos los componentes financieros.
17. Crear el vale en estado `GENERADO`.
18. Crear el snapshot financiero.
19. Crear exactamente `Q` parcialidades.
20. Vincular la restricción del 50 % con el vale cuando corresponda.
21. Registrar auditoría.
22. Registrar el evento en outbox.
23. Confirmar la transacción.
24. Responder con el recurso creado.

Todo el flujo se ejecuta en una sola transacción PostgreSQL.

Si cualquier paso falla:

- No se crea el vale.
- No se crea el snapshot.
- No se crean parcialidades.
- No se vincula la restricción.
- No se modifica la línea.
- No se publica un evento de éxito.

### 12.3 Idempotencia

- `Idempotency-Key` es obligatorio.
- La clave se vincula con el usuario, el caso de uso y una huella de la solicitud.
- Repetir la misma clave con el mismo cuerpo devuelve el resultado original.
- Repetir la misma clave con otro cuerpo devuelve conflicto.
- Una respuesta perdida no debe provocar un segundo folio.
- La idempotencia debe persistir durante el periodo definido por la política técnica general del backend.
- Redis puede ayudar con un candado, pero PostgreSQL conserva la decisión final.

---

## 13. Consultas

### 13.1 Detalle

El detalle debe incluir:

- UUID.
- Folio.
- Tipo.
- Estado.
- Cliente permitido y datos mínimos necesarios.
- Distribuidora.
- Sucursal.
- Producto y versión utilizada.
- Categoría y versión utilizada.
- Capital.
- Resumen financiero.
- Parcialidades.
- Aplicación de la regla del 50 % cuando corresponda.
- Fecha y usuario de generación.
- `lock_version`.

Los datos personales y bancarios deben enmascararse conforme al rol.

### 13.2 Listado

Permitir filtros controlados por:

- Folio.
- Tipo.
- Estado.
- Cliente.
- Distribuidora.
- Sucursal.
- Producto.
- Fecha de generación.

Aplicar:

- Paginación.
- Orden permitido mediante lista blanca.
- Alcance desde la construcción de la consulta.
- Índices para folio, estado, tipo, cliente, distribuidora, sucursal y fecha.

No cargar colecciones completas ni producir consultas N+1.

---

## 14. API REST

Base:

```text
/api/v1
```

### 14.1 Rutas

| Método | Ruta | Acción |
| --- | --- | --- |
| `POST` | `/vouchers` | Generar prevale o vale digital. |
| `GET` | `/vouchers` | Listar vales conforme al alcance. |
| `GET` | `/vouchers/{id}` | Consultar detalle autorizado. |

Las rutas de caja, modificación, liberación, feriado y rechazo pertenecen al M09.

### 14.2 Solicitud de generación

```json
{
  "client_id": "uuid",
  "product_id": "uuid"
}
```

Encabezados obligatorios:

```text
Idempotency-Key: valor-unico
X-Request-Id: uuid
```

### 14.3 Respuesta

```json
{
  "data": {
    "id": "uuid",
    "folio": "folio_generado",
    "type": "PREVALE",
    "status": "GENERADO",
    "client_id": "uuid",
    "product": {
      "id": "uuid",
      "version_id": "uuid",
      "name": "Producto",
      "capital": "15000.00"
    },
    "financial_summary": {
      "loan_commission": "1500.00",
      "total_interest": "6000.00",
      "insurance": "100.00",
      "misvales_total": "22600.00",
      "distributor_profit": "900.00",
      "client_total": "23500.00",
      "installments": 8
    },
    "credit_validation": {
      "available_before_fulfillment": "20000.00",
      "special_rule_applied": true,
      "minimum_allowed": "14500.00",
      "maximum_allowed": "15500.00"
    },
    "generated_at": "2026-07-24T20:00:00-06:00",
    "lock_version": 1
  }
}
```

Los valores del ejemplo son únicamente ilustrativos. No se deben precargar.

Los importes JSON se devuelven como cadenas decimales.

### 14.4 Errores de dominio

| Código | Uso |
| --- | --- |
| `AUTH_SCOPE_DENIED` | Usuario sin permiso o fuera de alcance. |
| `DISTRIBUTOR_INACTIVE` | Distribuidora deshabilitada o no operativa. |
| `DISTRIBUTOR_DELINQUENT` | Morosidad confirmada con bloqueo vigente. |
| `CLIENT_NOT_ASSIGNED_TO_DISTRIBUTOR` | El cliente pertenece a otra distribuidora. |
| `PRODUCT_NOT_AVAILABLE` | Producto inactivo, no publicado o fuera de vigencia. |
| `PRODUCT_CONFIGURATION_INCOMPLETE` | Faltan parámetros financieros obligatorios. |
| `DISTRIBUTOR_CATEGORY_NOT_AVAILABLE` | No existe una categoría vigente utilizable. |
| `CREDIT_INSUFFICIENT` | Capital mayor que el saldo disponible. |
| `CREDIT_50_PERCENT_RULE_NOT_SATISFIED` | Producto fuera del rango especial. |
| `CREDIT_RESTRICTION_ALREADY_LINKED` | Otro vale pendiente ocupa la restricción activa. |
| `VOUCHER_PREVALE_CONFLICT` | La concurrencia o el historial impiden crear otro prevale. |
| `RESOURCE_VERSION_CONFLICT` | Los datos cambiaron durante la operación. |
| `IDEMPOTENCY_KEY_REUSED` | La clave se reutilizó con otro contenido. |

No crear errores como:

- `CLIENT_HAS_DEBT`.
- `CLIENT_DELINQUENT`.
- `CLIENT_BLOCKED_BY_BALANCE`.
- `CLIENT_REGULARIZATION_REQUIRED`.

---

## 15. Autorización y alcance

### 15.1 Distribuidora

Puede:

- Generar vales para clientes asignados actualmente a ella.
- Consultar sus propios vales.
- Consultar el resumen informativo del cliente antes de decidir.

No puede:

- Generar para otra distribuidora.
- Enviar otro `distributor_id`.
- Generar para un cliente no asignado.
- Elegir el tipo o estado.
- Alterar cálculos.
- Omitir las validaciones de crédito.

### 15.2 Cajera

En el M08 únicamente puede:

- Buscar folios de su sucursal.
- Consultar la información necesaria para preparar el flujo del M09.

No puede generar un vale en nombre de una distribuidora.

### 15.3 Coordinador

Puede consultar, conforme a su alcance:

- Vales de sus distribuidoras asignadas.
- Operaciones bloqueadas que generen un evento consultable.

No genera vales ni modifica sus importes.

### 15.4 Gerente de sucursal

Puede consultar vales de su sucursal.

No utiliza su alcance para sustituir a la distribuidora en la generación ordinaria.

### 15.5 Gerente general

Puede consultar globalmente.

No altera snapshots ni importes históricos.

### 15.6 Administrador

Tiene consulta global de solo lectura.

No genera, modifica, cancela, libera ni feria vales.

### 15.7 Verificador

No tiene acciones dentro del M08 salvo que otro requerimiento autorizado le conceda una consulta específica. No debe recibir acceso por defecto.

---

## 16. Seguridad

- Usar Laravel Sanctum con sesión SPA.
- Exigir cookie segura, CSRF y sesión válida.
- Aplicar Policies a colección y recurso.
- No confiar en identificadores de alcance enviados por Angular.
- Aplicar rate limiting a la generación.
- Cifrar o enmascarar CURP, RFC, cuentas bancarias e identificaciones.
- No incluir datos sensibles completos en respuestas, logs o eventos.
- No registrar cookies, tokens o encabezados de autenticación.
- Validar UUID, tipos y longitudes.
- Usar consultas parametrizadas mediante Eloquent o Query Builder.
- Evitar asignación masiva no controlada.
- Evitar enumeración de folios fuera del alcance.
- Devolver el mismo comportamiento externo para un recurso inexistente y uno no autorizado cuando corresponda.
- Usar datos sintéticos o anonimizados en pruebas.

---

## 17. Concurrencia e integridad

La generación debe proteger:

- Cliente, para determinar el tipo.
- Línea de crédito, para validar saldo.
- Restricción del 50 %, para vincularla una sola vez.
- Folio, mediante restricción única.
- Idempotencia, mediante persistencia única por actor y operación.

Orden de bloqueos:

1. Cliente.
2. Distribuidora y asignaciones requeridas.
3. Línea de crédito.
4. Restricción de crédito.
5. Persistencia del vale.

Mantener el mismo orden en todos los casos de uso para reducir interbloqueos.

Redis no es fuente de verdad. Un candado Redis nunca sustituye:

- Transacción PostgreSQL.
- Bloqueo de fila.
- Restricción única.
- Validación de estado.

---

## 18. Eventos y notificaciones

Registrar en outbox, dentro de la misma transacción:

### 18.1 `VoucherGenerated`

Datos mínimos:

- `event_id`.
- `voucher_id`.
- Folio.
- Tipo.
- Distribuidora.
- Cliente con identificador interno.
- Sucursal.
- Capital.
- Estado anterior y nuevo.
- Regla del 50 % aplicada.
- Actor.
- Fecha de negocio.
- `request_id`.
- `trace_id`.

Destinatarios funcionales:

- Distribuidora.
- Cajera de la sucursal conforme a la estrategia del M17.

### 18.2 `VoucherGenerationBlocked`

Se registra cuando la operación no continúa por una restricción operativa real.

Debe incluir:

- Motivo controlado.
- Regla que falló.
- Distribuidora.
- Cliente.
- Producto.
- Sucursal.
- Actor.
- Fecha.
- `request_id`.

El adeudo informativo del cliente no puede originar este evento como bloqueo.

### 18.3 Duplicidad del cliente

El evento de prevale rechazado por CURP o domicilio pertenece al alta del cliente del M06. El M08 no debe intentar crear otro cliente ni duplicar esa validación como si fuera una regla del vale.

Los consumidores de notificaciones se desarrollan en el M17. El M08 debe dejar los eventos persistidos sin enviar correos directamente desde el controlador.

---

## 19. Auditoría

Auditar como mínimo:

- Intento de generación.
- Vale generado.
- Tipo determinado.
- Producto y versión utilizados.
- Categoría y versión utilizadas.
- Resultado de la validación de crédito.
- Rango del 50 % cuando aplique.
- Vinculación de la restricción.
- Snapshot creado.
- Parcialidades creadas.
- Generación bloqueada y código de motivo.
- Consultas de información sensible.
- Conflictos de concurrencia.
- Repeticiones idempotentes.

Cada evento debe conservar:

- Identificador.
- Tipo.
- Solicitante y ejecutor.
- Rol.
- Sucursal.
- Fecha UTC.
- Fecha de negocio en `America/Monterrey`.
- Sesión, IP y dispositivo disponibles.
- Vale, folio, cliente y distribuidora afectados.
- Estado anterior y nuevo.
- Regla o configuración utilizada.
- Motivo.
- Resultado.
- `request_id`.
- `trace_id`.

La auditoría es de inserción. No se actualiza ni elimina.

No registrar:

- CURP completa.
- RFC completo.
- Cuenta bancaria completa.
- Contenido de documentos.
- Cookies.
- Tokens.
- Secretos.

---

## 20. Orden de entrega backend

| Orden | Submódulo | Depende de |
| ---: | --- | --- |
| B01 | Base del módulo, enums, migraciones y contratos | M03, M05, M06 y M07 terminados |
| B02 | Agregado `Voucher` y máquina de estados | B01 |
| B03 | Resolución de tipo y protección de prevale único | B02 |
| B04 | Elegibilidad de distribuidora, cliente, producto y categoría | B03 |
| B05 | Integración con línea y regla del 50 % | B04 |
| B06 | Motor de cálculo y objetos de valor monetarios | B01 |
| B07 | Snapshot financiero y parcialidades | B06 |
| B08 | Caso de uso transaccional de generación e idempotencia | B03, B05 y B07 |
| B09 | Consultas, filtros, Resources y Policies | B08 |
| B10 | Eventos outbox, auditoría e integración con notificaciones | B08 |
| B11 | Pruebas unitarias, integración, seguridad y concurrencia | B01 a B10 |
| B12 | Integración final, documentación y cierre | B11 |

El equipo puede repartir trabajo dentro del mismo submódulo, pero no debe desarrollar un submódulo que dependa de otro incompleto.

---

## 21. Pruebas obligatorias

### 21.1 Unitarias

Probar:

- Resolución de `PREVALE`.
- Resolución de `VALE_DIGITAL`.
- Vale posterior a transferencia como `VALE_DIGITAL`.
- Prevención de segundo prevale.
- Estados y transiciones.
- Cálculo de comisión.
- Cálculo de interés total.
- Cálculo del total base.
- Cálculo de ganancia.
- Cálculo por quincena.
- Precisión de cuatro decimales.
- Redondeo aritmético.
- Distribución del residuo en la última parcialidad.
- Exactitud de sumas de parcialidades.
- Regla del 50 % con límite inferior.
- Regla del 50 % con límite superior.
- Límite superior restringido por saldo disponible.
- Caso sin producto admisible.
- Caso sin restricción especial.
- Recuperación normal de línea sin reactivar la regla.
- Adeudo del cliente sin producir bloqueo.

### 21.2 Integración

Probar:

- Migraciones en PostgreSQL.
- Restricción única de folio.
- Restricción de un snapshot por vale.
- Restricción de parcialidad por número.
- Prevención concurrente de dos prevales.
- Bloqueo de línea.
- Vinculación única de la restricción del 50 %.
- Rollback completo si falla el snapshot.
- Rollback completo si falla una parcialidad.
- Rollback completo si falla outbox.
- Idempotencia persistente.
- Snapshot sin cambios después de modificar producto.
- Snapshot sin cambios después de modificar categoría.

### 21.3 Feature

Probar:

- Distribuidora genera prevale correctamente.
- Distribuidora genera vale digital correctamente.
- Solicitud sin `Idempotency-Key`.
- Repetición idempotente devuelve el mismo vale.
- Reutilización de clave con cuerpo distinto devuelve conflicto.
- Distribuidora intenta generar para cliente ajeno.
- Distribuidora deshabilitada.
- Distribuidora morosa con bloqueo confirmado.
- Producto inactivo.
- Producto fuera de vigencia.
- Producto incompleto.
- Categoría sin versión vigente.
- Línea insuficiente.
- Producto fuera del rango del 50 %.
- Restricción del 50 % ocupada.
- Cliente con adeudo puede generar si las demás reglas se cumplen.
- Cajera consulta un folio de su sucursal.
- Cajera intenta consultar otra sucursal.
- Coordinador consulta solamente sus distribuidoras.
- Gerente de sucursal consulta solamente su sucursal.
- Administrador consulta sin poder modificar.
- Verificador sin acceso por defecto.
- Respuesta no expone datos sensibles.

### 21.4 Concurrencia

Probar con transacciones reales:

- Dos solicitudes simultáneas para el primer vale.
- Dos solicitudes simultáneas sobre la misma restricción del 50 %.
- Dos solicitudes simultáneas con la misma clave idempotente.
- Dos solicitudes diferentes mientras cambia la línea.
- Cambio de versión del producto durante la generación.
- Cambio de categoría durante la generación.

### 21.5 Arquitectura y calidad

Verificar:

- Ningún controlador contiene cálculos.
- Ningún Form Request decide reglas de negocio.
- No se usa punto flotante para dinero.
- No se hardcodean tolerancia, porcentajes ni productos.
- No existen consultas directas fuera de alcance.
- No se producen consultas N+1.
- PHPStan, Pint y pruebas del repositorio terminan correctamente.
- Las clases públicas tienen PHPDoc útil.
- OpenAPI coincide con el comportamiento real.

---

## 22. Criterios de terminado

El M08 se considera terminado cuando:

- El módulo `Voucher` respeta la arquitectura del backend.
- Las migraciones funcionan desde una base vacía.
- El backend determina el tipo sin confiar en Angular.
- No puede existir más de un prevale concurrente para el mismo cliente.
- Un cliente transferido recibe vales digitales.
- El adeudo del cliente nunca se usa como bloqueo automático.
- Solo la distribuidora autorizada genera para sus clientes.
- Producto y categoría se resuelven por versiones vigentes.
- La línea y la regla del 50 % se validan transaccionalmente.
- La restricción especial se vincula con un solo vale.
- La línea no se descuenta antes del feriado.
- El snapshot financiero es completo e inmutable.
- Se crean exactamente `Q` parcialidades.
- Los cálculos y redondeos coinciden con las reglas.
- El folio es único.
- La generación es idempotente.
- Las consultas respetan rol, sucursal y relación jerárquica.
- Los eventos quedan en outbox.
- La auditoría conserva reglas, versiones, actor y resultado.
- No se exponen datos sensibles.
- Las pruebas unitarias, de integración, feature y concurrencia pasan.
- La documentación de API y PHPDoc está actualizada.
- El M09 puede abrir y procesar el vale sin reconstruir información histórica.

---

## 23. Pendientes que no deben inventarse

La especificación no define:

- Formato visible exacto del folio.
- Vigencia o expiración automática de un folio generado.
- Quién puede cancelar un vale.
- Motivos y momento exacto permitidos para la cancelación.
- Duración técnica de conservación de una clave de idempotencia.

Hasta recibir una decisión:

- El folio debe ser único, pero no se debe imponer un formato de negocio no aprobado.
- No se implementa expiración automática.
- No se expone la cancelación.
- No se consume una restricción especial por rechazo o cancelación.
- La liberación de su ocupación activa debe conservar el vínculo histórico con el vale terminal.
- No se genera un segundo prevale; cualquier folio posterior es `VALE_DIGITAL`.
- La duración de idempotencia debe usar la política técnica general que el backend ya tenga definida; si no existe, debe quedar como configuración pendiente y no como constante dispersa.
