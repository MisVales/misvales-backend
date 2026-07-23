<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['name', 'is_headquarters', 'is_active'];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return ['is_headquarters' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
