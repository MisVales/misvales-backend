<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Identidad estable de un cliente final, sin relación de autenticación.
 *
 * @property string $id
 * @property string $given_names
 * @property string $surnames
 * @property string $curp_ciphertext
 * @property string $curp_hmac
 * @property string $curp_last4
 * @property ?string $rfc_ciphertext
 * @property ?CarbonImmutable $birth_date
 * @property int $created_by
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ?ClientAddress $currentAddress
 * @property-read ?ClientBankAccount $currentBankAccount
 * @property-read ?ClientDistributorAssignment $currentAssignment
 * @property-read Collection<int, ClientDistributorAssignment> $assignmentHistory
 * @property-read Collection<int, ClientDocument> $currentDocuments
 * @property-read Collection<int, ClientPortfolioSetting> $portfolioSettings
 * @property DistributorProfile $resolved_distributor_profile
 */
final class Client extends Model
{
    use UsesUuidPrimaryKey;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return HasOne<ClientAddress, $this> */
    public function currentAddress(): HasOne
    {
        return $this->hasOne(ClientAddress::class)->where('active_slot', true);
    }

    /** @return HasMany<ClientAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class);
    }

    /** @return HasOne<ClientBankAccount, $this> */
    public function currentBankAccount(): HasOne
    {
        return $this->hasOne(ClientBankAccount::class)->where('active_slot', true);
    }

    /** @return HasMany<ClientBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(ClientBankAccount::class);
    }

    /** @return HasOne<ClientDistributorAssignment, $this> */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(ClientDistributorAssignment::class)->where('active_slot', true);
    }

    /** @return HasMany<ClientDistributorAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClientDistributorAssignment::class);
    }

    /** @return HasMany<ClientDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }

    /** @return HasMany<ClientDocument, $this> */
    public function currentDocuments(): HasMany
    {
        return $this->hasMany(ClientDocument::class)->where('active_slot', true);
    }

    /** @return HasMany<ClientDistributorAssignment, $this> */
    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(ClientDistributorAssignment::class)->orderByDesc('effective_from');
    }

    /** @return HasMany<ClientPortfolioSetting, $this> */
    public function portfolioSettings(): HasMany
    {
        return $this->hasMany(ClientPortfolioSetting::class);
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
