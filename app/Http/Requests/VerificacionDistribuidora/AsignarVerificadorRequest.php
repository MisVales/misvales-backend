<?php

namespace App\Http\Requests\VerificacionDistribuidora;

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

            $timezone = config('app.timezone');
            $scheduled = CarbonImmutable::parse((string) $this->input('scheduled_for'))->setTimezone($timezone);
            $now = CarbonImmutable::now($timezone);
            $minutesToNextSlot = 15 - ($now->minute % 15);
            $earliest = $now->addMinutes($minutesToNextSlot)->startOfMinute();

            if ($scheduled->lessThan($earliest)) {
                $validator->errors()->add('scheduled_for', 'Selecciona un horario a partir del siguiente bloque de 15 minutos.');
            }

            if ($scheduled->hour < 8 || $scheduled->hour > 19 || $scheduled->minute % 15 !== 0) {
                $validator->errors()->add('scheduled_for', 'Selecciona un horario cada 15 minutos entre las 08:00 y las 19:00.');
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
