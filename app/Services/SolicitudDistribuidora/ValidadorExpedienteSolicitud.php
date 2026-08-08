<?php

namespace App\Services\SolicitudDistribuidora;

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
        $declaraciones = $solicitud->section_declarations ?? [];
        $faltantes = array_values(array_filter(self::SECCIONES, fn (string $seccion): bool => ! isset($declaraciones[$seccion]) || $declaraciones[$seccion] === 'PENDING'));

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['sections' => $faltantes]);
        }

        $this->validarDeclaraciones($solicitud, $declaraciones);
        $this->validarDatosPersonales($solicitud);
        $this->validarDomicilioActual($solicitud);
    }

    /** @return array{completed_sections: int, total_sections: int, can_submit: bool} */
    public function calcularSeccionesCompletas(SolicitudDistribuidora $solicitud): array
    {
        $declaraciones = $solicitud->section_declarations ?? [];

        $completadas = 0;

        foreach (self::SECCIONES as $seccion) {
            $estado = $declaraciones[$seccion] ?? 'PENDING';
            $tieneDatos = $this->seccionTieneDatos($solicitud, $seccion);
            $obligatoria = in_array($seccion, ['personal_data', 'residence'], true);

            if (($estado === 'COMPLETED' && $tieneDatos)
                || ($estado === 'NOT_APPLICABLE' && ! $tieneDatos && ! $obligatoria)) {
                $completadas++;
            }
        }

        return [
            'completed_sections' => $completadas,
            'total_sections' => count(self::SECCIONES),
            'can_submit' => $completadas === count(self::SECCIONES),
        ];
    }

    private function seccionTieneDatos(SolicitudDistribuidora $solicitud, string $seccion): bool
    {
        return match ($seccion) {
            'personal_data' => $solicitud->datosPersonales()->exists(),
            'residence' => $solicitud->domicilios()->where('is_current', true)->exists(),
            'partner' => $solicitud->familiares()->whereIn('relationship', ['SPOUSE', 'PARTNER'])->exists(),
            'children' => $solicitud->familiares()->where('relationship', 'CHILD')->exists(),
            'family_references' => $solicitud->familiares()->where('is_family_reference', true)->exists(),
            'vehicles' => $solicitud->vehiculos()->exists(),
            'assets' => $solicitud->patrimonio()->where('entry_type', 'ASSET')->where('is_active', true)->exists(),
            'liabilities' => $solicitud->patrimonio()->whereIn('entry_type', ['LIABILITY', 'ACTIVE_COMMITMENT'])->where('is_active', true)->exists(),
            'employment' => $solicitud->empleos()->exists(),
            'commercial_credits' => $solicitud->creditosComerciales()->exists(),
            default => false,
        };
    }
}
