<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Validation\Validator;

trait RechazaPropiedadesDesconocidas
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidas = collect(array_keys($this->rules()))
                ->map(fn (string $campo): string => explode('.', $campo)[0])
                ->unique()
                ->all();

            foreach (array_diff(array_keys($this->all()), $permitidas) as $campo) {
                $validator->errors()->add($campo, 'La propiedad no está permitida.');
            }
        });
    }
}
