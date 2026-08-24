<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use App\Models\MediaFileBinding;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DatosPersonalesSolicitudResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $protector = app(ProtectorDatosSolicitud::class);
        $puedeVerCompletos = ($request->user()?->hasPermissionTo('distributor_applications.view_sensitive') ?? false)
            || $request->attributes->get('verification_sensitive_application_id') === $this->application_id;

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'first_last_name' => $this->first_last_name,
            'second_last_name' => $this->second_last_name,
            'nationality' => $this->nationality,
            'birth_country' => $this->birth_country,
            'curp_masked' => $this->curp_ciphertext === null ? null : $protector->enmascarar($protector->descifrar($this->curp_ciphertext)),
            'curp' => $this->when($puedeVerCompletos, fn (): ?string => $this->curp_ciphertext === null ? null : $protector->descifrar($this->curp_ciphertext)),
            'rfc_masked' => $this->rfc_ciphertext === null ? null : $protector->enmascarar($protector->descifrar($this->rfc_ciphertext), 3, 3),
            'rfc' => $this->when($puedeVerCompletos, fn (): ?string => $this->rfc_ciphertext === null ? null : $protector->descifrar($this->rfc_ciphertext)),
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'birth_place' => $this->birth_place,
            'birth_state' => $this->birth_state,
            'birth_city' => $this->birth_city,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'identification_country' => $this->identification_country,
            'official_id_type' => $this->official_id_type,
            'official_id_number_masked' => $this->official_id_number_ciphertext === null ? null : $protector->enmascarar($protector->descifrar($this->official_id_number_ciphertext), 2, 2),
            'official_id_number' => $this->when($puedeVerCompletos, fn (): ?string => $this->official_id_number_ciphertext === null ? null : $protector->descifrar($this->official_id_number_ciphertext)),
            'has_identification_evidence' => MediaFileBinding::query()
                ->where('owner_type', 'distributor_application')
                ->where('owner_id', $this->application_id)
                ->where('purpose', 'IDENTIFICATION')
                ->exists(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
