<?php

namespace App\Services\Cliente;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionCliente;
use App\Models\Cliente;
use App\Models\ClientRegistrationDraft;
use App\Models\Distribuidora;
use App\Models\MediaFileBinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ServicioRegistroClienteBorrador
{
    public function __construct(private readonly ServicioRegistroCliente $registro) {}

    public function crear(array $datos, User $actor): ClientRegistrationDraft
    {
        $distribuidora = $this->distribuidoraActiva($actor);

        return ClientRegistrationDraft::query()->create([
            'distributor_id' => $distribuidora->id,
            'branch_id' => $distribuidora->branch_id,
            'created_by' => $actor->id,
            'payload' => $datos,
            'status' => 'OPEN',
        ]);
    }

    public function completar(ClientRegistrationDraft $draft, User $actor): Cliente
    {
        return DB::transaction(function () use ($draft, $actor): Cliente {
            $draft = ClientRegistrationDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $this->validarAlcance($draft, $actor);
            if ($draft->status !== 'OPEN') {
                if ($draft->client_id) {
                    return Cliente::query()->findOrFail($draft->client_id);
                }
                throw new ExcepcionCliente('CLIENT_REGISTRATION_DRAFT_STATUS_INVALID', 'El borrador ya no está disponible.', 409);
            }

            $payload = $draft->payload ?? [];
            $errores = Validator::make($payload, [
                'first_name' => ['required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
                'first_last_name' => ['required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
                'second_last_name' => ['required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
                'birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
                'phone_number' => ['required', 'string', 'regex:/^\+\d{1,4}\d{10}$/'],
                'address' => ['required', 'array'],
                'address.state' => ['required', 'string', 'max:120'],
                'address.city' => ['required', 'string', 'max:160'],
                'address.municipality' => ['required', 'string', 'max:160'],
                'address.postal_code' => ['required', 'regex:/^\d{5}$/'],
                'address.neighborhood' => ['required', 'string', 'max:160'],
                'address.street' => ['required', 'string', 'max:180'],
                'address.exterior_number' => ['required', 'string', 'max:32'],
            ])->errors()->toArray();
            if ($errores !== []) {
                throw new ExcepcionCliente('CLIENT_REGISTRATION_DRAFT_INVALID', 'Completa correctamente los datos del cliente.', 422, $errores);
            }

            $bindings = MediaFileBinding::query()
                ->where('owner_type', 'client_registration_draft')
                ->where('owner_id', $draft->id)
                ->whereIn('purpose', ['CLIENT_INE_FRONT', 'ADDRESS_PROOF'])
                ->get()
                ->groupBy('purpose');
            if ($bindings->get('CLIENT_INE_FRONT', collect())->count() !== 1 || $bindings->get('ADDRESS_PROOF', collect())->count() !== 1) {
                throw new ExcepcionCliente('CLIENT_DOCUMENTS_REQUIRED', 'Adjunta la INE frontal y el comprobante de domicilio.', 422);
            }

            $payload['official_id_type'] = 'INE';
            $payload['official_id_media_id'] = $bindings['CLIENT_INE_FRONT']->first()->media_file_id;
            $payload['address']['address_proof_media_id'] = $bindings['ADDRESS_PROOF']->first()->media_file_id;
            $cliente = $this->registro->registrar($payload, $actor);

            foreach ([$bindings['CLIENT_INE_FRONT']->first(), $bindings['ADDRESS_PROOF']->first()] as $binding) {
                MediaFileBinding::query()->firstOrCreate([
                    'media_file_id' => $binding->media_file_id,
                    'owner_type' => 'client',
                    'owner_id' => $cliente->id,
                    'purpose' => $binding->purpose === 'CLIENT_INE_FRONT' ? 'CLIENT_INE_FRONT' : 'ADDRESS_PROOF',
                ], ['created_by' => $actor->id]);
            }

            $draft->forceFill(['client_id' => $cliente->id, 'status' => 'COMPLETED', 'completed_at' => now()])->save();

            return $cliente->refresh();
        }, 3);
    }

    private function distribuidoraActiva(User $actor): Distribuidora
    {
        $scopeIds = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'DISTRIBUTOR')->whereNotNull('scope_id')->pluck('scope_id');
        $distribuidora = Distribuidora::query()->where('user_id', $actor->id)->whereIn('id', $scopeIds)->first();
        if ($distribuidora === null || $distribuidora->status !== EstadoDistribuidora::ACTIVA) {
            throw new ExcepcionCliente('AUTH_SCOPE_DENIED', 'No existe una distribuidora activa para la sesión.', 403);
        }

        return $distribuidora;
    }

    private function validarAlcance(ClientRegistrationDraft $draft, User $actor): void
    {
        if ($draft->created_by !== $actor->id && ! $actor->hasScopeForBranch($draft->branch_id)) {
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El borrador no está dentro del alcance autorizado.', 403);
        }
    }
}
