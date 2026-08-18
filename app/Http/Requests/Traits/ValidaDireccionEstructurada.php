<?php

namespace App\Http\Requests\Traits;

trait ValidaDireccionEstructurada
{
    /**
     * @return array<string, array>
     */
    protected function reglasDireccionEstructurada(string $prefix = '', string $presencia = 'required'): array
    {
        $p = $prefix ? "{$prefix}." : '';

        return [
            "{$p}country" => ['sometimes', 'string', 'size:2'],
            "{$p}state" => [$presencia, 'string', 'max:120'],
            "{$p}city" => [$presencia, 'string', 'max:160'],
            "{$p}municipality" => [$presencia, 'string', 'max:160'],
            "{$p}postal_code" => [$presencia, 'string', 'max:10'],
            "{$p}neighborhood" => [$presencia, 'string', 'max:160'],
            "{$p}street" => [$presencia, 'string', 'max:180'],
            "{$p}exterior_number" => [$presencia, 'string', 'max:32'],
            "{$p}interior_number" => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, array>
     */
    protected function reglasCodigoPostalMexicano(string $prefix = '', string $presencia = 'required'): array
    {
        $p = $prefix ? "{$prefix}." : '';

        return [
            "{$p}postal_code" => [$presencia, 'regex:/^\d{5}$/'],
        ];
    }
}
