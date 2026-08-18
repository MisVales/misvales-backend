# ADR-002: tres experiencias estrictas por dispositivo

## Estado

Aceptada el 2026-08-17.

## Decisión

La SPA mantiene un único login y contrato API, pero presenta exactamente tres shells excluyentes. Gerente general, gerente de sucursal, cajera y administrador sólo usan escritorio; coordinador y verificador sólo usan tableta; distribuidora sólo usa móvil. Los roles desconocidos, ausentes o de familias distintas se niegan por defecto.

La clasificación combina puntero, hover, pantalla, touch y `userAgentData` cuando existe. Una ventana pequeña no convierte un escritorio en móvil. Una señal ambigua, una clase distinta o un viewport inviable llevan a la pantalla fuera de los módulos privados `dispositivo-no-compatible`.

Los guards se ejecutan después de identidad. Una falla de red de `/me` conserva la sesión y muestra disponibilidad de servicio, no se interpreta como incompatibilidad. Los cambios de señal reevalúan la decisión y desmontan el shell antes de redirigir. La autorización Laravel continúa siendo la fuente definitiva; el dispositivo nunca otorga permisos.

## Navegación y accesibilidad

Los tres shells consumen la misma navegación filtrada por `effective_permissions`. Tableta usa objetivos táctiles de 48 px; móvil conserva cuatro destinos como máximo más «Más», accesible como diálogo. La pantalla de incompatibilidad recibe foco, anuncia el resultado de reintento y no monta sidebar, datos ni rutas privadas.

## Relación con Puntos

Puntos fue retirado por ADR-001. Las referencias históricas del documento de planeación se conservan como trazabilidad, pero esta decisión no lo restituye en rutas, navegación, permisos ni experiencias móviles.

## Límites de validación

La matriz de roles, clases y viewports está cubierta por pruebas unitarias y de guard. La comprobación real de Safari/iPadOS requiere hardware o un servicio de dispositivos; no se declara validada desde Windows. No se usó Playwright en este release.
