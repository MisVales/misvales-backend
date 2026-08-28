<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registro operacional de peticiones HTTP
    |--------------------------------------------------------------------------
    |
    | Conserva en operational_logs una traza por petición. La persistencia se
    | ejecuta mediante la cola Redis existente para no acoplar la disponibilidad
    | ni la latencia HTTP del aplicativo al almacenamiento de observabilidad.
    |
    */
    'operational_http_requests' => (bool) env('OBSERVABILITY_ENABLED', false),

    'queue_connection' => 'sync',

    'database_incident_path' => env('DATABASE_INCIDENT_PATH', storage_path('logs/database-incidents.jsonl')),

    /*
    |--------------------------------------------------------------------------
    | Rendimiento de consultas
    |--------------------------------------------------------------------------
    |
    | Acumula únicamente métricas numéricas por petición. Las consultas lentas
    | se registran por huella, sin SQL ni bindings, para no filtrar datos.
    |
    */
    'query_metrics' => (bool) env('OBSERVABILITY_QUERY_METRICS_ENABLED', false),

    'slow_query_log' => (bool) env('OBSERVABILITY_SLOW_QUERY_ENABLED', false),

    'slow_query_threshold_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_THRESHOLD_MS', 500),

    'slow_query_channel' => env('OBSERVABILITY_SLOW_QUERY_CHANNEL', 'performance'),

    'expose_server_timing' => (bool) env('OBSERVABILITY_EXPOSE_SERVER_TIMING', false),
];
