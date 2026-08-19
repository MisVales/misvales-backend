<?php

namespace App\Services\SolicitudDistribuidora;

use App\Models\MediaFileBinding;
use App\Models\SolicitudDistribuidora;
use Illuminate\Validation\ValidationException;

final class ValidadorExpedienteSolicitud
{
    public const SECCIONES = [
        'personal_data', 'residence', 'partner', 'children', 'family_references',
        'vehicles', 'assets', 'liabilities', 'employment', 'commercial_credits',
    ];

    /** @param array<string, string> $declaraciones */
    public function validarSecciones(array $declaraciones): void
    {
        $desconocidas = array_diff(array_keys($declaraciones), self::SECCIONES);

        if ($desconocidas !== []) {
            throw ValidationException::withMessages(['section_declarations' => ['Existen secciones no permitidas.']]);
        }

        foreach ($declaraciones as $seccion => $estado) {
            if (! in_array($estado, ['PENDING', 'COMPLETED', 'NOT_APPLICABLE'], true)) {
                throw ValidationException::withMessages([
                    "section_declarations.{$seccion}" => ['El estado de la sección no es válido.'],
                ]);
            }
        }
    }

    public function validarDatosPersonales(SolicitudDistribuidora $solicitud): void
    {
        if (! $solicitud->datosPersonales()->exists()) {
            throw ValidationException::withMessages(['section_declarations.personal_data' => ['Se requieren los datos personales.']]);
        }
    }

    public function validarDomicilioActual(SolicitudDistribuidora $solicitud): void
    {
        if (! $solicitud->domicilios()->where('is_current', true)->exists()) {
            throw ValidationException::withMessages(['residence' => ['Se requiere un domicilio actual.']]);
        }
    }

    /** @param array<string, string> $declaraciones */
    public function validarDeclaraciones(SolicitudDistribuidora $solicitud, array $declaraciones): void
    {
        $this->validarSecciones($declaraciones);

        foreach ($declaraciones as $seccion => $estado) {
            $tieneDatos = $this->seccionTieneDatos($solicitud, $seccion);

            if ($estado === 'COMPLETED' && ! $tieneDatos) {
                throw ValidationException::withMessages([
                    "section_declarations.{$seccion}" => ['La sección no puede completarse porque no contiene los datos requeridos.'],
                ]);
            }

            if ($estado === 'NOT_APPLICABLE' && $tieneDatos) {
                throw ValidationException::withMessages([
                    "section_declarations.{$seccion}" => ['La sección contiene registros y no puede declararse como no aplicable.'],
                ]);
            }

            if (in_array($seccion, ['personal_data', 'residence'], true) && $estado === 'NOT_APPLICABLE') {
                throw ValidationException::withMessages([
                    "section_declarations.{$seccion}" => ['Esta sección es obligatoria.'],
                ]);
            }
        }
    }

    public function validarEnvio(SolicitudDistribuidora $solicitud): void
    {
        $declaraciones = $this->declaracionesAutomaticas($solicitud);
        $faltantes = array_values(array_filter(
            self::SECCIONES,
            fn (string $seccion): bool => $declaraciones[$seccion] === 'PENDING',
        ));

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['sections' => $faltantes]);
        }

        $this->validarDatosPersonales($solicitud);
        $this->validarDomicilioActual($solicitud);
    }

    /** @return array<string, string> */
    public function declaracionesAutomaticas(SolicitudDistribuidora $solicitud): array
    {
        return collect(self::SECCIONES)
            ->mapWithKeys(function (string $seccion) use ($solicitud): array {
                if ($this->seccionTieneDatos($solicitud, $seccion)) {
                    return [$seccion => 'COMPLETED'];
                }

                if ($this->seccionTieneRegistros($solicitud, $seccion)
                    || $this->seccionTieneBorradorSinClasificar($solicitud, $seccion)) {
                    return [$seccion => 'PENDING'];
                }

                return [$seccion => in_array($seccion, ['personal_data', 'residence'], true)
                    ? 'PENDING'
                    : 'NOT_APPLICABLE'];
            })
            ->all();
    }

    /** @return array{completed_sections: int, total_sections: int, can_submit: bool} */
    /** @param array<string, string>|null $declaraciones */
    public function calcularSeccionesCompletas(SolicitudDistribuidora $solicitud, ?array $declaraciones = null): array
    {
        $declaraciones ??= $this->declaracionesAutomaticas($solicitud);
        $aplicables = array_filter($declaraciones, fn (string $estado): bool => $estado !== 'NOT_APPLICABLE');
        $completadas = count(array_filter($aplicables, fn (string $estado): bool => $estado === 'COMPLETED'));

        return [
            'completed_sections' => $completadas,
            'total_sections' => count($aplicables),
            'can_submit' => ! in_array('PENDING', $declaraciones, true),
        ];
    }

    private function seccionTieneDatos(SolicitudDistribuidora $solicitud, string $seccion): bool
    {
        return match ($seccion) {
            'personal_data' => $this->datosPersonalesCompletos($solicitud),
            'residence' => $this->camposPresentes($solicitud->domicilios()->where('is_current', true), [
                'street', 'exterior_number', 'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'housing_tenure',
            ]),
            'partner' => $this->camposPresentes($solicitud->familiares()->whereIn('relationship', ['SPOUSE', 'PARTNER']), [
                'relationship', 'first_name', 'first_last_name',
            ]),
            'children' => $this->camposPresentes($solicitud->familiares()->where('relationship', 'CHILD'), [
                'relationship', 'first_name', 'first_last_name',
            ]),
            'family_references' => $this->camposPresentes($solicitud->familiares(), [
                'relationship', 'first_name', 'first_last_name',
            ], 2),
            'vehicles' => $this->camposPresentes($solicitud->vehiculos(), ['vehicle_type']),
            'assets' => $this->camposPresentes($solicitud->patrimonio()->where('entry_type', 'ASSET')->where('is_active', true), ['entry_type', 'name']),
            'liabilities' => $this->camposPresentes($solicitud->patrimonio()->whereIn('entry_type', ['LIABILITY', 'ACTIVE_COMMITMENT'])->where('is_active', true), ['entry_type', 'name']),
            'employment' => $this->camposPresentes($solicitud->empleos(), ['employer_name']),
            'commercial_credits' => $this->camposPresentes($solicitud->creditosComerciales(), ['company_name', 'credit_limit']),
            default => false,
        };
    }

    private function seccionTieneRegistros(SolicitudDistribuidora $solicitud, string $seccion): bool
    {
        return match ($seccion) {
            'personal_data' => $solicitud->datosPersonales()->exists(),
            'residence' => $solicitud->domicilios()->where('is_current', true)->exists(),
            'partner' => $solicitud->familiares()->whereIn('relationship', ['SPOUSE', 'PARTNER'])->exists(),
            'children' => $solicitud->familiares()->where('relationship', 'CHILD')->exists(),
            'family_references' => $solicitud->familiares()->exists(),
            'vehicles' => $solicitud->vehiculos()->exists(),
            'assets' => $solicitud->patrimonio()->where('entry_type', 'ASSET')->exists(),
            'liabilities' => $solicitud->patrimonio()->whereIn('entry_type', ['LIABILITY', 'ACTIVE_COMMITMENT'])->exists(),
            'employment' => $solicitud->empleos()->exists(),
            'commercial_credits' => $solicitud->creditosComerciales()->exists(),
            default => false,
        };
    }

    private function seccionTieneBorradorSinClasificar(SolicitudDistribuidora $solicitud, string $seccion): bool
    {
        return match ($seccion) {
            'family_references' => $solicitud->familiares()
                ->where(function ($consulta): void {
                    $consulta
                        ->whereNull('relationship')
                        ->orWhere(function ($consulta): void {
                            $consulta
                                ->whereNotIn('relationship', ['SPOUSE', 'PARTNER', 'CHILD'])
                                ->where(function ($consulta): void {
                                    $consulta
                                        ->whereNull('first_name')
                                        ->orWhere('first_name', '')
                                        ->orWhereNull('first_last_name')
                                        ->orWhere('first_last_name', '');
                                });
                        });
                })
                ->exists(),
            'assets' => $solicitud->patrimonio()->whereNull('entry_type')->exists(),
            default => false,
        };
    }

    private function datosPersonalesCompletos(SolicitudDistribuidora $solicitud): bool
    {
        $datos = $solicitud->datosPersonales;

        if ($datos === null || ! $this->camposPresentes($solicitud->datosPersonales(), [
            'nationality', 'first_name', 'first_last_name', 'birth_date', 'birth_country', 'birth_state',
            'birth_city', 'email', 'phone_number', 'official_id_type', 'official_id_number_ciphertext',
        ])) {
            return false;
        }

        if ($datos->nationality === 'MEXICAN' && $datos->curp_ciphertext === null) {
            return false;
        }

        if ($datos->nationality === 'FOREIGN' && blank($datos->identification_country)) {
            return false;
        }

        return MediaFileBinding::query()
            ->where('owner_type', 'distributor_application')
            ->where('owner_id', $solicitud->id)
            ->where('purpose', 'IDENTIFICATION')
            ->exists();
    }

    /** @param \Illuminate\Database\Eloquent\Relations\Relation<*, *, *> $consulta @param array<int, string> $campos */
    private function camposPresentes($consulta, array $campos, int $minimumRecords = 1): bool
    {
        foreach ($campos as $campo) {
            $consulta->whereNotNull($campo);

            if (! in_array($campo, ['birth_date', 'credit_limit'], true)) {
                $consulta->where($campo, '<>', '');
            }
        }

        return $consulta->count() >= $minimumRecords;
    }
}
