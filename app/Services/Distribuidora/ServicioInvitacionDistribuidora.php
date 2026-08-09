<?php

namespace App\Services\Distribuidora;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionDistribuidora;
use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\Distribuidora;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ServicioInvitacionDistribuidora
{
    public function __construct(private readonly AuditorDistribuidora $auditor) {}

    public function reenviar(Distribuidora $distribuidora, User $actor): void
    {
        $token = null;
        $usuario = null;

        DB::transaction(function () use ($distribuidora, $actor, &$token, &$usuario): void {
            $bloqueada = Distribuidora::query()->with('usuario')->lockForUpdate()->findOrFail($distribuidora->id);
            if ($bloqueada->status !== EstadoDistribuidora::PENDIENTE_ACTIVACION
                || $bloqueada->usuario->state !== 'PENDING_ACTIVATION') {
                throw new ExcepcionDistribuidora(
                    'DISTRIBUTOR_ACTIVATION_STATE_INVALID',
                    'La distribuidora ya no está pendiente de activación.',
                    409,
                );
            }

            AccountInvitation::query()
                ->where('user_id', $bloqueada->user_id)
                ->whereIn('state', ['ACTIVE', 'PREPARED'])
                ->update([
                    'state' => 'REVOKED',
                    'revoked_at' => now(),
                    'exchange_token_hash' => null,
                    'updated_at' => now(),
                ]);

            $token = Str::random(60);
            $usuario = $bloqueada->usuario;
            AccountInvitation::create([
                'user_id' => $usuario->id,
                'created_by_user_id' => $actor->id,
                'purpose' => 'ACCOUNT_ACTIVATION',
                'token_hash' => hash('sha256', $token),
                'state' => 'ACTIVE',
                'expires_at' => now()->addHours(48),
            ]);

            OutboxEvent::create([
                'event_type' => 'DISTRIBUTOR_ACTIVATION_INVITATION_RESENT',
                'payload' => [
                    'distributor_id' => $bloqueada->id,
                    'user_id' => $usuario->id,
                    'branch_id' => $bloqueada->branch_id,
                ],
                'status' => 'PENDING',
            ]);

            $this->auditor->registrar(
                'DISTRIBUTOR_ACTIVATION_INVITATION_RESENT',
                'Distributor',
                $bloqueada->id,
                $actor,
                $bloqueada->branch_id,
            );
        });

        Mail::to($usuario->email)->queue(new ActivationInvitationMail($usuario, $token));
    }
}
