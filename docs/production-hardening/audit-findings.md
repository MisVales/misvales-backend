# Hallazgos de hardening de producción

Base auditada: backend `fa844ea2258774dc3cebcd82f0d436e5f2e65789` sobre `develop`.

| Prioridad | Hallazgo comprobado | Estado |
| --- | --- | --- |
| P0 | Puntos conservaba cinco rutas y dependencias runtime hacia un modelo ya eliminado. | Corregido en `f982879`. |
| P0 | `AuthSession` guardaba el hash del bearer completo, mientras Sanctum guarda el hash del secreto; la revocación no eliminaba el token. | Corregido en `a01b939`, con compatibilidad para sesiones históricas. |
| P0 | El repositorio incluía un script que creaba un usuario activo con credencial fija y sin MFA. | Eliminado; el guard de configuración se añadió en `452c5c4`. |
| P1 | El contrato de errores API mezcla envolturas y algunos errores no incluyen `request_id`. | Pendiente de una corrección coordinada con el consumidor frontend. |
| P1 | Readiness puede convertir una caída de PostgreSQL en 500 porque hay consultas fuera del aislamiento de checks; métricas detalladas son públicas. | Pendiente de corrección focalizada. |
| P1 | Los headers efectivos dependen de Nginx/Cloudflare y no pueden certificarse sólo leyendo Laravel. | Requiere smoke test en un entorno representativo. |

No se inspeccionaron ni exportaron filas de una base productiva durante esta fase. Los conteos y la restauración son gates obligatorios del runbook.

