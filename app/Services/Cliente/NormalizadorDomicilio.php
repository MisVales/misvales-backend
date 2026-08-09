<?php

namespace App\Services\Cliente;

use Illuminate\Support\Str;

final class NormalizadorDomicilio
{
    private const CAMPOS = ['street', 'exterior_number', 'interior_number', 'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'country'];

    private const ABREVIATURAS = [
        'street' => [
            '/\bAV(?:\.|\b)/u' => 'AVENIDA',
            '/\bBLVD(?:\.|\b)/u' => 'BOULEVARD',
            '/\bCALZ(?:\.|\b)/u' => 'CALZADA',
            '/\bCARR(?:\.|\b)/u' => 'CARRETERA',
            '/\bPROL(?:\.|\b)/u' => 'PROLONGACION',
        ],
        'neighborhood' => [
            '/\bCOL(?:\.|\b)/u' => 'COLONIA',
            '/\bFRACC(?:\.|\b)/u' => 'FRACCIONAMIENTO',
        ],
    ];

    public function normalizar(array $domicilio): array
    {
        $resultado = [];
        foreach (self::CAMPOS as $campo) {
            $valor = (string) ($domicilio[$campo] ?? '');
            $valor = mb_strtoupper(Str::ascii(trim($valor)));
            foreach (self::ABREVIATURAS[$campo] ?? [] as $patron => $reemplazo) {
                $valor = (string) preg_replace($patron, $reemplazo, $valor);
            }
            $resultado[$campo] = (string) preg_replace('/\s+/', ' ', $valor);
        }

        $resultado['country'] = $resultado['country'] ?: 'MX';

        return $resultado;
    }

    public function serializar(array $domicilio): string
    {
        $normalizado = $this->normalizar($domicilio);

        return collect(self::CAMPOS)
            ->map(fn (string $campo): string => strlen($normalizado[$campo]).':'.$normalizado[$campo])
            ->implode('|');
    }
}
