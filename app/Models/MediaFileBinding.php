<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFileBinding extends Model {
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use HasUuids;

    protected $table = 'media_file_bindings';

    protected $fillable = [
        'media_file_id',
        'owner_type',
        'owner_id',
        'purpose',
        'created_by',
    ];

    public function mediaFile(): BelongsTo {
        return $this->belongsTo(MediaFile::class);
    }

    public function owner() {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    public function creator(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by');
    }
}
