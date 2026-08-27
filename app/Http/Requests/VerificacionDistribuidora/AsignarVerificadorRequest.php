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
            'scheduled_for' => ['required', 'date'],
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

            $scheduled = CarbonImmutable::parse((string) $this->input('scheduled_for'))->setTimezone($timezone);
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
            'scheduled_for.required' => 'Selecciona la fecha y hora de la visita.',
            'scheduled_for.date' => 'La fecha y hora de la visita no son válidas.',
        ];
    }
}
