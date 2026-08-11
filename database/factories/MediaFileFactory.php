<?php
namespace Database\Factories;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MediaFileFactory extends Factory {
    protected $model = MediaFile::class;
    public function definition() {
        return [
            'id' => Str::uuid(),
            'file_type' => 'id_front',
            'disk' => 'private',
            'path' => 'evidences/' . Str::uuid() . '.jpg',
            'original_name' => 'id_front.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::random(10)),
            'uploaded_by' => User::factory(),
        ];
    }
}
