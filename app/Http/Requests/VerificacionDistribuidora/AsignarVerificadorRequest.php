<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Services\VerificacionDistribuidora\PoliticaHorarioVerificacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AsignarVerificadorRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'verifier_id' => 'required|uuid|exists:users,id',
            'scheduled_for' => [
                'required',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/',
                'date',
            ],
            'lock_version' => 'required|integer|min:1',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('scheduled_for')) {
                return;
            }

            $policyService = app(PoliticaHorarioVerificacion::class);
            $policy = $policyService->obtener();
            $timezone = $policy['timezone'] ?? config('app.timezone');

            try {
                $scheduled = CarbonImmutable::parse((string) $this->input('scheduled_for'))->setTimezone($timezone);
            } catch (\Throwable) {
                $validator->errors()->add('scheduled_for', 'La fecha y hora de la visita no son válidas.');
                return;
            }
            $now = CarbonImmutable::now($timezone);
            $slotMinutes = (int) ($policy['slot_minutes'] ?? 15);
            $minutesToNextSlot = $slotMinutes - ($now->minute % $slotMinutes);
            $earliest = $now->addMinutes($minutesToNextSlot)->startOfMinute();

            if ($scheduled->lessThan($earliest)) {
                $validator->errors()->add('scheduled_for', 'Selecciona un horario a partir del siguiente bloque de '.$slotMinutes.' minutos.');
            }

            $time = $scheduled->format('H:i');
            if ($time < $policy['start_time'] || $time > $policy['max_start_time'] || $scheduled->minute % $slotMinutes !== 0) {
                $validator->errors()->add('scheduled_for', "Selecciona un horario cada {$slotMinutes} minutos entre las {$policy['start_time']} y las {$policy['max_start_time']}.");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'scheduled_for.regex' => 'La fecha y hora deben usar un formato ISO válido, por ejemplo 2026-08-28T15:00:00.000Z.',
            'scheduled_for.required' => 'Selecciona la fecha y hora de la visita.',
            'scheduled_for.date' => 'La fecha y hora de la visita no son válidas.',
        ];
    }
}
