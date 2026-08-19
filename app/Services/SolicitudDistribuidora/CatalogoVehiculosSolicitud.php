<?php

namespace App\Services\SolicitudDistribuidora;

final class CatalogoVehiculosSolicitud
{
    private const BRANDS = [
        'Acura', 'Audi', 'BAIC', 'BMW', 'Buick', 'Cadillac', 'Changan', 'Chevrolet', 'Chrysler', 'Cupra',
        'Dodge', 'Fiat', 'Ford', 'GMC', 'Honda', 'Hyundai', 'Infiniti', 'Isuzu', 'JAC', 'Jaguar', 'Jeep',
        'Kia', 'Land Rover', 'Lexus', 'Lincoln', 'Mazda', 'Mercedes-Benz', 'MG', 'MINI', 'Mitsubishi',
        'Nissan', 'Peugeot', 'Porsche', 'RAM', 'Renault', 'SEAT', 'Subaru', 'Suzuki', 'Tesla', 'Toyota',
        'Volkswagen', 'Volvo',
    ];

    private const VEHICLE_TYPES = [
        'Automóvil', 'Sedán', 'Hatchback', 'Coupé', 'Convertible', 'SUV', 'Crossover', 'Pickup', 'Miniván',
        'Van', 'Camioneta de carga', 'Camión', 'Motocicleta', 'Autobús', 'Remolque', 'Otro',
    ];

    /** @return array{brands: list<string>, vehicle_types: list<string>} */
    public function obtener(): array
    {
        return [
            'brands' => self::BRANDS,
            'vehicle_types' => self::VEHICLE_TYPES,
        ];
    }
}
