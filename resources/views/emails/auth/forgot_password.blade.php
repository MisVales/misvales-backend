<x-mail::message>
# Recuperación de Contraseña

Hemos recibido una solicitud para restablecer la contraseña asociada a tu cuenta de MisVales.

Si no fuiste tú quien solicitó este cambio, puedes ignorar este correo de forma segura. Tu cuenta sigue estando protegida.

Para continuar con el proceso de recuperación, haz clic en el siguiente botón o utiliza el código de recuperación en la aplicación.

<x-mail::panel>
**Código de Recuperación:** {{ $resetToken }}
</x-mail::panel>

<x-mail::button :url="env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $resetToken">
Restablecer mi contraseña
</x-mail::button>

Este enlace expirará en 60 minutos por razones de seguridad.

Saludos cordiales,<br>
El equipo de seguridad de {{ config('app.name') }}
</x-mail::message>
