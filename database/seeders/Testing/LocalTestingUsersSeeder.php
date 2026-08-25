<?php

declare(strict_types=1);

namespace Database\Seeders\Testing;

use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\CategoryVersion;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\LineaCredito;
use App\Models\MediaFile;
use App\Models\MediaFileBinding;
use App\Models\MfaCredential;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\VerificationVisit;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

final class LocalTestingUsersSeeder extends Seeder
{
    private const PASSWORD = '1234';

    /** @var array<string, list<string>> */
    private const PEPE_MEDIA_SOURCES = [
        'identification' => [
            'C:\\Users\\saubt\\Downloads\\ine.webp',
            'C:\\Users\\saubt\\Downloads\\OIP.jpg',
        ],
        'vehicle' => [
            'C:\\Users\\saubt\\Downloads\\tenencia.webp',
            'C:\\Users\\saubt\\Downloads\\titulocarro.jpg',
        ],
        'asset' => ['C:\\Users\\saubt\\Downloads\\titulo casa.webp'],
        'visit' => [
            'C:\\Users\\saubt\\Downloads\\fachada.webp',
            'C:\\Users\\saubt\\Downloads\\interior.webp',
            'C:\\Users\\saubt\\Downloads\\evidencia-01a021e5-a5eb-71e2-821f-4b9423297f6c.png',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalTestingUsersSeeder solo puede ejecutarse en los entornos local o testing.');
        }

        DB::transaction(function (): void {
            $manager = $this->upsertUser('alberto@gmail.com', 'Alberto');
            $totpSecret = (string) config('bootstrap.local_testing_totp_secret');

            if ($totpSecret === '') {
                $totpSecret = (new Google2FA)->generateSecretKey();
            }

            $this->assign($manager, 'general_manager', 'GLOBAL');
            $this->seedTotp($manager, $totpSecret);

            $branch = $this->upsertMatamorosBranch($manager->id);

            foreach ([
                ['admin@gmail.com', 'Administrador QA', 'admin', 'GLOBAL', null],
                ['jorge@gmail.com', 'Jorge Ibarra', 'branch_manager', 'BRANCH', $branch->id],
                ['dani@gmail.com', 'Daniel Garcia', 'coordinator', 'BRANCH', $branch->id],
                ['jesus@gmail.com', 'Jesus Guillen', 'coordinator', 'BRANCH', $branch->id],
                ['saul@gmail.com', 'Saul Sanchez', 'verifier', 'BRANCH', $branch->id],
                ['aza@gmail.com', 'Azael Garcia', 'cashier', 'BRANCH', $branch->id],
                ['pepe@gmail.com', 'Pepe', 'distributor', 'DISTRIBUTOR', $branch->id],
            ] as [$email, $name, $roleCode, $scopeType, $branchId]) {
                $user = $this->upsertUser($email, $name);

                $scopeId = null;
                if ($roleCode === 'distributor' && $branchId !== null) {
                    $distribuidora = $this->setupDistributor($user, $branchId, $manager->id);
                    $scopeId = $distribuidora->id;
                    if (app()->environment('local')) {
                        $this->seedPepeMedia($user, $distribuidora, $manager);
                    }
                }

                $this->assign($user, $roleCode, $scopeType, $branchId, $manager->id, $scopeId);
                $this->seedTotp($user, $totpSecret);
            }
        });
    }

    private function setupDistributor(User $distributor, string $branchId, string $managerId): Distribuidora
    {
        $coordinator = User::where('normalized_email', 'jesus@gmail.com')->first() ?? User::find($managerId);

        // Generar un sufijo numérico basado en el id del distribuidor para evitar colisiones
        $suffix = '99'.str_pad((string) (crc32($distributor->email) % 10000), 4, '0', STR_PAD_LEFT);

        $solicitud = DistributorApplication::firstOrCreate(
            ['application_number' => 'SOL-2026-'.$suffix],
            [
                'branch_id' => $branchId,
                'coordinator_id' => $coordinator->id,
                'created_by' => $managerId,
                'status' => 'DRAFT',
                'section_declarations' => [],
                'lock_version' => 1,
            ]
        );

        $distribuidora = Distribuidora::firstOrNew(['user_id' => $distributor->id]);
        if (! $distribuidora->exists) {
            $distribuidora->forceFill([
                'application_id' => $solicitud->id,
                'distributor_number' => 'DIS-2026-'.$suffix,
                'branch_id' => $branchId,
                'status' => 'ACTIVE',
                'activated_at' => now(),
                'activated_by' => $managerId,
                'lock_version' => 1,
            ])->save();
        }

        LineaCredito::query()->firstOrCreate(
            ['distributor_id' => $distribuidora->id],
            ['total_authorized' => '100000.0000', 'used_balance' => '0.0000', 'lock_version' => 1]
        );

        $categoria = CategoryVersion::whereHas('category', function ($query) {
            $query->where('code', 'CAT-PLATA');
        })->first();

        if ($categoria) {
            AsignacionCategoriaDistribuidora::query()->firstOrCreate(
                ['distributor_id' => $distribuidora->id, 'ends_at' => null],
                [
                    'category_version_id' => $categoria->id,
                    'starts_at' => now()->subDay(),
                    'assigned_by' => $managerId,
                    'reason' => 'Pruebas de desarrollo',
                ]
            );
        }

        CoordinatorDistributorAssignment::query()->firstOrCreate(
            ['distributor_id' => $distribuidora->id, 'status' => 'ACTIVE'],
            [
                'coordinator_id' => $coordinator->id,
                'branch_id' => $branchId,
                'valid_from' => now()->subDay(),
                'assigned_by' => $managerId,
                'assignment_reason' => 'Pruebas de desarrollo',
                'lock_version' => 1,
            ]
        );

        return $distribuidora;
    }

    private function upsertMatamorosBranch(string $managerId): BranchRecord
    {
        $branch = BranchRecord::query()->firstOrNew(['code' => 'MATAMOROS']);
        $branch->fill([
            'name' => 'Sucursal Matamoros',
            'address' => null,
            'address_validation_id' => null,
            'address_place_id' => null,
            'address_latitude' => null,
            'address_longitude' => null,
            'address_validated_at' => null,
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => 0,
        ]);
        $branch->created_by ??= $managerId;
        $branch->updated_by = $branch->exists ? $managerId : null;
        $branch->save();

        return $branch;
    }

    private function upsertUser(string $email, string $name): User
    {
        $normalizedEmail = Str::lower(trim($email));
        $user = User::query()->firstOrNew(['normalized_email' => $normalizedEmail]);
        $user->name = $name;
        $user->email = $email;
        $user->state = 'ACTIVE';
        $user->password = Hash::make(self::PASSWORD);
        $user->email_verified_at ??= now();
        $user->password_changed_at ??= now();
        $user->require_password_change = false;
        $user->save();

        return $user;
    }

    private function assign(
        User $user,
        string $roleCode,
        string $scopeType,
        ?string $branchId = null,
        ?string $assignedBy = null,
        ?string $scopeId = null,
    ): void {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        UserRoleScope::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'branch_id' => $branchId,
            'scope_id' => $scopeId,
            'status' => 'ACTIVE',
            'revoked_at' => null,
        ], [
            'assigned_by_user_id' => $assignedBy ?? $user->id,
            'assigned_at' => now(),
            'assignment_reason' => 'Usuario auxiliar de pruebas locales',
        ]);
    }

    private function seedTotp(User $user, string $secret): void
    {
        MfaCredential::query()->updateOrCreate([
            'user_id' => $user->id,
            'type' => 'TOTP',
        ], [
            'label' => 'QA local/testing',
            'confirmed_at' => now(),
            'revoked_at' => null,
            'secret_ciphertext' => Crypt::encryptString($secret),
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
    }

    private function seedPepeMedia(User $pepe, Distribuidora $distribuidora, User $manager): void
    {
        $application = $distribuidora->solicitud;
        if ($application === null) {
            throw new RuntimeException('No se encontró la solicitud de la distribuidora de Pepe para adjuntar sus evidencias.');
        }

        $this->attachMediaIfMissing(
            'distributor_application',
            $application->id,
            'IDENTIFICATION',
            'IDENTIFICATION_EVIDENCE',
            $this->randomSource('identification'),
            $pepe,
        );
        $this->attachMediaIfMissing(
            'distributor_application',
            $application->id,
            'VEHICLE_EVIDENCE',
            'VEHICLE_EVIDENCE',
            $this->randomSource('vehicle'),
            $pepe,
        );
        $this->attachMediaIfMissing(
            'distributor_application',
            $application->id,
            'ASSET_EVIDENCE',
            'ASSET_EVIDENCE',
            $this->randomSource('asset'),
            $pepe,
        );

        $verifier = User::query()->where('normalized_email', 'saul@gmail.com')->firstOrFail();
        $visit = VerificationVisit::query()->firstOrCreate(
            [
                'application_id' => $application->id,
                'status' => 'COMPLETED',
                'result' => 'FAVORABLE',
            ],
            [
                'verifier_id' => $verifier->id,
                'assigned_by' => $manager->id,
                'assigned_at' => now()->subHour(),
                'started_at' => now()->subMinutes(50),
                'visited_at' => now()->subMinutes(30),
                'completed_at' => now()->subMinutes(30),
                'observations' => 'Visita de demostración con evidencias adjuntas.',
                'differences_payload' => null,
                'lock_version' => 1,
            ],
        );

        foreach ([
            'FACHADA' => self::PEPE_MEDIA_SOURCES['visit'][0],
            'INTERIOR' => self::PEPE_MEDIA_SOURCES['visit'][1],
            'DOCUMENTO' => self::PEPE_MEDIA_SOURCES['visit'][2],
        ] as $fileType => $source) {
            $this->attachMediaIfMissing('verification_visit', $visit->id, 'EVIDENCE', $fileType, $source, $verifier);
        }
    }

    private function attachMediaIfMissing(
        string $ownerType,
        string $ownerId,
        string $purpose,
        string $fileType,
        string $source,
        User $uploadedBy,
    ): void {
        $exists = MediaFileBinding::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('purpose', $purpose)
            ->whereHas('mediaFile', fn ($query) => $query->where('file_type', $fileType))
            ->exists();

        if ($exists) {
            return;
        }

        if (! is_file($source)) {
            $source = storage_path('framework/qa-evidence-facade.png');
        }

        if (! is_file($source)) {
            throw new RuntimeException("No se encontró el archivo de demostración requerido ni su respaldo local: {$source}");
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $destination = 'seed/pepe/'.Str::uuid().'.'.$extension;
        $contents = file_get_contents($source);
        if ($contents === false || ! Storage::disk(config('filesystems.default'))->put($destination, $contents)) {
            throw new RuntimeException("No fue posible almacenar el archivo de demostración: {$source}");
        }

        $media = MediaFile::query()->create([
            'file_type' => $fileType,
            'disk' => config('filesystems.default'),
            'path' => $destination,
            'original_name' => basename($source),
            'mime_type' => $this->mimeType($extension),
            'size_bytes' => filesize($source),
            'sha256' => hash_file('sha256', $source),
            'uploaded_by' => $uploadedBy->id,
            'validation_status' => 'VALIDATED',
            'validated_at' => now(),
        ]);

        MediaFileBinding::query()->create([
            'media_file_id' => $media->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'purpose' => $purpose,
            'created_by' => $uploadedBy->id,
        ]);
    }

    private function randomSource(string $group): string
    {
        $availableSources = collect(self::PEPE_MEDIA_SOURCES[$group])
            ->filter(fn (string $source): bool => is_file($source))
            ->values();

        if ($availableSources->isEmpty()) {
            throw new RuntimeException("No se encontró ningún archivo de demostración disponible para {$group}.");
        }

        return $availableSources->random();
    }

    private function mimeType(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => throw new RuntimeException("Extensión no permitida para evidencia de demostración: {$extension}"),
        };
    }
}
