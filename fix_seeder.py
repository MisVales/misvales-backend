import re

filename = 'database/seeders/ValesEjemploSeeder.php'
with open(filename, 'r', encoding='utf-8') as f:
    content = f.read()

old_calc = """        $calculo = app(CalculadorFinancieroVale::class)->calcular(
            number_format($importe, 4, '.', ''),
            (string) $versionProducto->loan_commission_percentage,
            (string) $versionProducto->simple_interest_percentage,
            (int) $versionProducto->fortnights_count,
            (string) $versionProducto->insurance_amount,
            (string) $categoria->profit_percentage,
        );"""
        
new_calc = """        $calculo = app(CalculadorFinancieroVale::class)->calcular(
            number_format($importe, 4, '.', ''),
            '0.100000',
            '0.020000',
            4,
            '100.0000',
            (string) $categoria->profit_percentage,
        );"""
content = content.replace(old_calc, new_calc)

with open(filename, 'w', encoding='utf-8') as f:
    f.write(content)
