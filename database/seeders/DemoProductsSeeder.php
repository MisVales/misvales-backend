<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DemoProductsSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()
            ->whereHas('roleScopes.role', function ($query) {
                $query->whereIn('code', ['general_manager', 'admin']);
            })
            ->first() ?? User::first();

        $creatorId = $creator?->id;

        $productos = [
            [
                'code' => 'VAL-8-5000',
                'name' => '8-5000',
                'description' => 'Todo bien',
                'nominal_amount' => '5000.0000',
                'loan_commission_percentage' => '0.100000',
                'simple_interest_percentage' => '0.050000',
                'insurance_amount' => '100.0000',
                'fortnights_count' => 8,
                'late_fee_amount' => '300.0000',
            ],
            [
                'code' => 'VAL-8-10000',
                'name' => '8-10000',
                'description' => 'Todo bien',
                'nominal_amount' => '10000.0000',
                'loan_commission_percentage' => '0.100000',
                'simple_interest_percentage' => '0.050000',
                'insurance_amount' => '100.0000',
                'fortnights_count' => 8,
                'late_fee_amount' => '300.0000',
            ],
            [
                'code' => 'VAL-8-15000',
                'name' => '8-15000',
                'description' => 'Todo bien',
                'nominal_amount' => '15000.0000',
                'loan_commission_percentage' => '0.100000',
                'simple_interest_percentage' => '0.050000',
                'insurance_amount' => '100.0000',
                'fortnights_count' => 8,
                'late_fee_amount' => '300.0000',
            ],
        ];

        DB::transaction(function () use ($productos, $creatorId): void {
            foreach ($productos as $config) {
                $product = Product::query()->firstOrNew(['code' => $config['code']]);

                $product->fill([
                    'status' => BaseStatus::ACTIVE,
                    'loan_commission_percentage' => $config['loan_commission_percentage'],
                    'simple_interest_percentage' => $config['simple_interest_percentage'],
                    'insurance_amount' => $config['insurance_amount'],
                    'fortnights_count' => $config['fortnights_count'],
                    'late_fee_amount' => $config['late_fee_amount'],
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
                    'loan_commission_percentage' => $config['loan_commission_percentage'],
                    'simple_interest_percentage' => $config['simple_interest_percentage'],
                    'insurance_amount' => $config['insurance_amount'],
                    'fortnights_count' => $config['fortnights_count'],
                    'late_fee_amount' => $config['late_fee_amount'],
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
