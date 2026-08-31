<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class InitialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Use the general manager or the first admin as the creator.
        $user = User::query()->whereHas('roleScopes.role', function ($query): void {
            $query->whereIn('code', ['general_manager', 'admin']);
        })->first() ?? User::query()->first();

        $creatorId = $user?->id;

        $categories = [
            'COBRE' => ['name' => 'Cobre', 'profit' => '0.030000'],
            'PLATA' => ['name' => 'Plata', 'profit' => '0.060000'],
            'ORO' => ['name' => 'Oro', 'profit' => '0.100000'],
        ];

        foreach ($categories as $codeSuffix => $data) {
            $code = 'CAT-'.$codeSuffix;
            $category = Category::query()->firstOrNew(['code' => $code]);
            if (! $category->exists) {
                $category->forceFill([
                    'status' => BaseStatus::ACTIVE,
                    'lock_version' => 0,
                    'created_by' => $creatorId,
                ])->save();
            }

            CategoryVersion::query()->firstOrCreate(
                ['category_id' => $category->id, 'version' => 1],
                [
                    'name' => $data['name'],
                    'profit_percentage' => $data['profit'],
                    'status' => VersionStatus::PUBLISHED,
                    'effective_from' => now()->subDay(),
                    'reason' => 'Catalogo inicial',
                    'created_by' => $creatorId,
                    'published_by' => $creatorId,
                    'published_at' => now(),
                ],
            );
        }

        // These products use the same complete 8-fortnight configuration as the demo products.
        $products = [
            [
                'code' => 'VAL-1000',
                'name' => 'Vale de $1,000',
                'description' => 'Importe disponible para otorgamiento.',
                'nominal_amount' => '1000.0000',
            ],
            [
                'code' => 'VAL-2000',
                'name' => 'Vale de $2,000',
                'description' => 'Importe disponible para otorgamiento.',
                'nominal_amount' => '2000.0000',
            ],
            [
                'code' => 'VAL-3000',
                'name' => 'Vale de $3,000',
                'description' => 'Importe disponible para otorgamiento.',
                'nominal_amount' => '3000.0000',
            ],
            [
                'code' => 'VAL-4000',
                'name' => 'Vale de $4,000',
                'description' => 'Importe disponible para otorgamiento.',
                'nominal_amount' => '4000.0000',
            ],
            [
                'code' => 'VAL-5000',
                'name' => 'Vale de $5,000',
                'description' => 'Importe disponible para otorgamiento.',
                'nominal_amount' => '5000.0000',
            ],
        ];

        DB::transaction(function () use ($products, $creatorId): void {
            foreach ($products as $config) {
                $product = Product::query()->firstOrNew(['code' => $config['code']]);

                $product->fill([
                    'status' => BaseStatus::ACTIVE,
                    'loan_commission_percentage' => '0.100000',
                    'simple_interest_percentage' => '0.050000',
                    'insurance_amount' => '100.0000',
                    'fortnights_count' => 8,
                    'late_fee_amount' => '300.0000',
                    'lock_version' => $product->exists ? $product->lock_version : 0,
                    'created_by' => $product->exists ? $product->created_by : $creatorId,
                    'updated_by' => $creatorId,
                ]);
                $product->save();

                $version = ProductVersion::query()->firstOrNew([
                    'product_id' => $product->id,
                    'version' => 1,
                ]);

                $version->fill([
                    'name' => $config['name'],
                    'description' => $config['description'],
                    'nominal_amount' => $config['nominal_amount'],
                    'loan_commission_percentage' => '0.100000',
                    'simple_interest_percentage' => '0.050000',
                    'insurance_amount' => '100.0000',
                    'fortnights_count' => 8,
                    'late_fee_amount' => '300.0000',
                    'status' => VersionStatus::PUBLISHED,
                    'effective_from' => $version->exists ? $version->effective_from : now()->subDays(7),
                    'effective_to' => null,
                    'reason' => 'Configuración de producto 8 quincenas.',
                    'lock_version' => $version->exists ? $version->lock_version : 0,
                    'created_by' => $version->exists ? $version->created_by : $creatorId,
                    'published_by' => $creatorId,
                    'published_at' => $version->exists ? $version->published_at : now()->subDays(7),
                ]);
                $version->save();

                Cache::forget("producto:{$product->code}");
            }

            Cache::forget('productos:todos_vigentes');
        });
    }
}
