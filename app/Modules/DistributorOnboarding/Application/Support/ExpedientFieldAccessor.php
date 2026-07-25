<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Domain\Contracts\MediaPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use App\Modules\DistributorOnboarding\Domain\Expedients\NormalizedEmail;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationPersonalData;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** Lee y aplica únicamente rutas de campos definidas, sin permitir asignación arbitraria. */
final class ExpedientFieldAccessor
{
    /** @var list<string> */
    private const PERSONAL_FIELDS = [
        'first_name', 'paternal_surname', 'maternal_surname', 'curp', 'rfc',
        'birth_date', 'birth_place', 'birth_state', 'birth_city', 'declared_address',
        'official_identification_media_id',
    ];

    public function __construct(private readonly MediaPort $media) {}

    public function read(
        DistributorApplication $application,
        ExpedientSection $section,
        string $fieldPath,
    ): mixed {
        $this->assertSectionMatches($section, $fieldPath);

        if ($fieldPath === 'contact_email' || $fieldPath === 'account_name') {
            return $application->getAttribute($fieldPath);
        }

        $field = $this->personalField($fieldPath);
        $personal = $application->personalData()->first();

        if ($personal === null) {
            throw OnboardingDomainException::incomplete();
        }

        return $personal->getAttribute($field);
    }

    public function write(
        DistributorApplication $application,
        int $actorUserId,
        ExpedientSection $section,
        string $fieldPath,
        string $value,
    ): void {
        $this->assertSectionMatches($section, $fieldPath);

        if ($fieldPath === 'contact_email') {
            $email = new NormalizedEmail($value);
            $application->forceFill([
                'contact_email' => $email->value,
                'normalized_email_hash' => $email->protectedHash((string) config('app.key')),
            ])->save();

            return;
        }

        if ($fieldPath === 'account_name') {
            $name = trim($value);
            if ($name === '' || mb_strlen($name) > 255) {
                throw OnboardingDomainException::correctionNotAllowed();
            }
            $application->forceFill(['account_name' => $name])->save();

            return;
        }

        $field = $this->personalField($fieldPath);
        $personal = $application->personalData()->first();

        if (! $personal instanceof ApplicationPersonalData) {
            throw OnboardingDomainException::incomplete();
        }

        $this->assertPersonalValue($field, $value);
        if ($field === 'official_identification_media_id') {
            $this->media->assertReady($value, $application->public_id, $actorUserId);
        }

        $attributes = [$field => $value, 'lock_version' => $personal->lock_version + 1];
        if ($field === 'curp') {
            $attributes['curp_hash'] = hash_hmac('sha256', mb_strtoupper(trim($value)), (string) config('app.key'));
        }
        if ($field === 'rfc') {
            $attributes['rfc_hash'] = hash_hmac('sha256', mb_strtoupper(trim($value)), (string) config('app.key'));
        }
        $personal->forceFill($attributes)->save();
    }

    private function personalField(string $fieldPath): string
    {
        if (! str_starts_with($fieldPath, 'personal.')) {
            throw OnboardingDomainException::correctionNotAllowed();
        }

        $field = mb_substr($fieldPath, mb_strlen('personal.'));

        if (! in_array($field, self::PERSONAL_FIELDS, true)) {
            throw OnboardingDomainException::correctionNotAllowed();
        }

        return $field;
    }

    private function assertSectionMatches(ExpedientSection $section, string $fieldPath): void
    {
        $matches = match ($section) {
            ExpedientSection::CONTACT => in_array($fieldPath, ['contact_email', 'account_name'], true),
            ExpedientSection::PERSONAL => str_starts_with($fieldPath, 'personal.'),
            default => false,
        };

        if (! $matches) {
            throw OnboardingDomainException::correctionNotAllowed();
        }
    }

    private function assertPersonalValue(string $field, string $value): void
    {
        $maximum = match ($field) {
            'first_name', 'paternal_surname', 'maternal_surname', 'birth_state', 'birth_city' => 150,
            'curp' => 18,
            'rfc' => 13,
            'birth_place' => 255,
            'declared_address' => 2000,
            default => null,
        };

        if ($maximum !== null && mb_strlen($value) > $maximum) {
            throw OnboardingDomainException::correctionNotAllowed();
        }
        if ($field === 'birth_date') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw OnboardingDomainException::correctionNotAllowed();
            }
        }
        if ($field === 'official_identification_media_id' && ! Str::isUuid($value)) {
            throw OnboardingDomainException::correctionNotAllowed();
        }
    }
}
