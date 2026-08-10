<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SepomexSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = 'C:\Users\saubt\Downloads\sepomex_seeder.csv';

        if (!file_exists($csvPath)) {
            $this->command->error("Archivo no encontrado: {$csvPath}");
            return;
        }

        $this->command->info("Leyendo archivo CSV: {$csvPath}");

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file); // Saltar header

        $estadosMap = [];
        $municipiosMap = [];
        $cpsMap = [];

        $estadosBatch = [];
        $municipiosBatch = [];
        $cpsBatch = [];
        $coloniasBatch = [];

        DB::disableQueryLog();

        $estadoIdCounter = 1;
        $municipioIdCounter = 1;
        $cpIdCounter = 1;
        
        $count = 0;

        while (($row = fgetcsv($file)) !== false) {
            $cpCode = $row[0];
            $coloniaName = $row[1];
            $tipoAsentamiento = $row[2];
            $municipioName = $row[3];
            $estadoName = $row[4];

            // 1. Estado
            if (!isset($estadosMap[$estadoName])) {
                $estadosMap[$estadoName] = $estadoIdCounter;
                $estadosBatch[] = [
                    'id' => $estadoIdCounter,
                    'name' => $estadoName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $estadoIdCounter++;
            }
            $estadoId = $estadosMap[$estadoName];

            // 2. Municipio
            $munKey = $estadoId . '_' . $municipioName;
            if (!isset($municipiosMap[$munKey])) {
                $municipiosMap[$munKey] = $municipioIdCounter;
                $municipiosBatch[] = [
                    'id' => $municipioIdCounter,
                    'estado_id' => $estadoId,
                    'name' => $municipioName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $municipioIdCounter++;
            }
            $municipioId = $municipiosMap[$munKey];

            // 3. Código Postal
            $cpKey = $municipioId . '_' . $cpCode;
            if (!isset($cpsMap[$cpKey])) {
                $cpsMap[$cpKey] = $cpIdCounter;
                $cpsBatch[] = [
                    'id' => $cpIdCounter,
                    'municipio_id' => $municipioId,
                    'code' => $cpCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $cpIdCounter++;
            }
            $cpId = $cpsMap[$cpKey];

            // 4. Colonia
            $coloniasBatch[] = [
                'codigo_postal_id' => $cpId,
                'name' => $coloniaName,
                'settlement_type' => $tipoAsentamiento,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $count++;

            // Insertar por lotes
            if (count($coloniasBatch) >= 5000) {
                $this->flushBatches($estadosBatch, $municipiosBatch, $cpsBatch, $coloniasBatch);
                $this->command->info("Procesados {$count} registros...");
            }
        }

        // Flush remaining
        $this->flushBatches($estadosBatch, $municipiosBatch, $cpsBatch, $coloniasBatch);
        $this->command->info("Completado. Total: {$count} registros procesados.");

        fclose($file);
    }

    private function flushBatches(&$estados, &$municipios, &$cps, &$colonias)
    {
        if (count($estados) > 0) {
            DB::table('estados')->insert($estados);
            $estados = [];
        }
        if (count($municipios) > 0) {
            DB::table('municipios')->insert($municipios);
            $municipios = [];
        }
        if (count($cps) > 0) {
            DB::table('codigos_postales')->insert($cps);
            $cps = [];
        }
        if (count($colonias) > 0) {
            DB::table('colonias')->insert($colonias);
            $colonias = [];
        }
    }
}
