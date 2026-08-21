import re

# Clean InitialCatalogSeeder.php
filename = 'database/seeders/InitialCatalogSeeder.php'
with open(filename, 'r', encoding='utf-8') as f:
    content = f.read()

# Match the product generation loops and remove the fields
pattern = r"(\s*)'loan_commission_percentage'.*?\n\s*'simple_interest_percentage'.*?\n\s*'insurance_amount'.*?\n\s*'fortnights_count'.*?\n"
content = re.sub(pattern, r"\1", content)
with open(filename, 'w', encoding='utf-8') as f:
    f.write(content)

# Clean ValesEjemploSeeder.php
filename = 'database/seeders/ValesEjemploSeeder.php'
with open(filename, 'r', encoding='utf-8') as f:
    content = f.read()

content = re.sub(pattern, r"\1", content)

# In ValesEjemploSeeder.php, it uses calculador:
old_calc = """$calculo = $calculador->calcular(
            (string) $versionProducto->nominal_amount,
            (string) $versionProducto->loan_commission_percentage,
            (string) $versionProducto->simple_interest_percentage,
            (int) $versionProducto->fortnights_count,
            (string) $versionProducto->insurance_amount,
            (string) $asignacionCategoria->versionCategoria->profit_percentage,
        );"""
new_calc = """$calculo = $calculador->calcular(
            (string) $versionProducto->nominal_amount,
            '0.100000', // commission_rate
            '0.020000', // interest_rate
            4,          // fortnights_count
            '100.0000', // insurance_amount
            (string) $asignacionCategoria->versionCategoria->profit_percentage,
        );"""
content = content.replace(old_calc, new_calc)

with open(filename, 'w', encoding='utf-8') as f:
    f.write(content)

