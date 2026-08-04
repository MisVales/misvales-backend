<x-mail::message>
# Recuperación de Contraseña

Hola {{ $user->name }},

Recibimos una solicitud para restablecer la contraseña de tu cuenta en {{ config('app.name') }}.

<x-mail::button :url="$resetUrl">
Restablecer Contraseña
</x-mail::button>

Este enlace expirará en 60 minutos por razones de seguridad.

@if(!empty($context))
<x-mail::panel>
**Detalles de la solicitud:**
- **Dispositivo:** {{ $context['device'] ?? 'Desconocido' }}
- **Dirección IP:** {{ $context['ip'] ?? 'Desconocida' }}
- **Fecha y Hora:** {{ $context['time'] ?? now()->toDateTimeString() }}
</x-mail::panel>
@endif

Si no solicitaste un restablecimiento de contraseña, ignora este correo. Tu cuenta está segura.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
