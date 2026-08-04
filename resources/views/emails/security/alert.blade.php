<x-mail::message>
# {{ $title }}

Hola {{ $user->name }},

{{ $message }}

@if(!empty($context))
<x-mail::panel>
**Detalles de seguridad:**
@isset($context['device'])
- **Dispositivo:** {{ $context['device'] }}
@endisset
@isset($context['ip'])
- **Dirección IP:** {{ $context['ip'] }}
@endisset
@isset($context['time'])
- **Fecha y Hora:** {{ $context['time'] }}
@endisset
@isset($context['reason'])
- **Motivo:** {{ $context['reason'] }}
@endisset
</x-mail::panel>
@endif

Si fuiste tú quien realizó esta acción, puedes ignorar este correo de forma segura.

Si **no reconoces** esta actividad, tu cuenta podría estar comprometida. Por favor, cambia tu contraseña inmediatamente y revoca las sesiones desconocidas desde tu panel de seguridad.

Gracias,<br>
El equipo de Seguridad de {{ config('app.name') }}
</x-mail::message>
