<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;

class InitialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Use the general manager or the first admin as the creator
        $user = User::whereHas('roleScopes.role', function ($q) {
            $q->whereIn('code', ['general_manager', 'admin']);
        })->first() ?? User::first();

        $creatorId = $user ? $user->id : null;

        // Categories
        $categorias = [
            'PLATA' => ['name' => 'Plata', 'profit' => '0.060000'],
            'ORO' => ['name' => 'Oro', 'profit' => '0.075000'],
            'DIAMANTE' => ['name' => 'Diamante', 'profit' => '0.100000'],
        ];

        foreach ($categorias as $codeSuffix => $data) {
            $code = 'CAT-' . $codeSuffix;
            $categoria = Category::firstOrNew(['code' => $code]);
            if (! $categoria->exists) {
                $categoria->forceFill([
                    'status' => 'ACTIVE',
                    'lock_version' => 0,
                    'created_by' => $creatorId,
                ])->save();
            }
            
            CategoryVersion::firstOrCreate(
                ['category_id' => $categoria->id, 'version' => 1],
                [
                    'name' => $data['name'],
                    'profit_percentage' => $data['profit'],
                    'status' => 'PUBLISHED',
                    'effective_from' => now()->subDay(),
                    'reason' => 'Catálogo inicial',
                    'created_by' => $creatorId,
                    'published_by' => $creatorId,
                    'published_at' => now(),
                ]
            );
        }

        // Products (Vales)
        $importes = [1000, 2000, 3000, 4000, 5000];
        foreach ($importes as $importe) {
            $producto = Product::firstOrNew(['code' => "VAL-{$importe}"]);
            if (! $producto->exists) {
                $producto->forceFill([
                    'status' => 'ACTIVE',
                    'lock_version' => 0,
                    'created_by' => $creatorId,
                ])->save();
            }

            ProductVersion::firstOrCreate(
                ['product_id' => $producto->id, 'version' => 1],
                [
                    'name' => "Vale de $" . number_format($importe),
                    'nominal_amount' => number_format($importe, 4, '.', ''),
                                        'status' => 'PUBLISHED',
                    'effective_from' => now()->subDay(),
                    'reason' => 'Catálogo inicial',
                    'created_by' => $creatorId,
                    'published_by' => $creatorId,
                    'published_at' => now(),
                ]
            );
        }
    }
}
