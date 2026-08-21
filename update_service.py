import re

with open('app/Services/Vale/ServicioGeneracionVale.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update previsualizar
content = content.replace(
    'public function previsualizar(User $actor, string $clienteId, string $versionProductoId): array',
    'public function previsualizar(User $actor, string $clienteId, string $versionProductoId, float $commissionRate, float $interestRate, float $insuranceAmount, int $installmentCount): array'
)
content = content.replace(
    '$contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId);',
    '$contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId, $commissionRate, $interestRate, $insuranceAmount, $installmentCount);'
)

# 2. Update generar
content = content.replace(
    'public function generar(User $actor, string $clienteId, string $versionProductoId): Vale',
    'public function generar(User $actor, string $clienteId, string $versionProductoId, float $commissionRate, float $interestRate, float $insuranceAmount, int $installmentCount): Vale'
)
content = content.replace(
    'return DB::transaction(function () use ($actor, $clienteId, $versionProductoId): Vale {',
    'return DB::transaction(function () use ($actor, $clienteId, $versionProductoId, $commissionRate, $interestRate, $insuranceAmount, $installmentCount): Vale {'
)

# 3. Update resolverContexto
content = content.replace(
    'private function resolverContexto(User $actor, string $clienteId, string $versionProductoId): array',
    'private function resolverContexto(User $actor, string $clienteId, string $versionProductoId, float $commissionRate, float $interestRate, float $insuranceAmount, int $installmentCount): array'
)

# Remove the check for PRODUCT_FINANCIAL_CONFIGURATION_MISSING
pattern_to_remove = r"if \(\s*\$versionProducto->loan_commission_percentage === null\s*\|\|.*?PRODUCT_FINANCIAL_CONFIGURATION_MISSING.*?409,\s*\);\s*}"
content = re.sub(pattern_to_remove, '', content, flags=re.DOTALL)

# Change the calculador inputs
old_calc = """$calculo = $this->calculador->calcular(
            (string) $versionProducto->nominal_amount,
            (string) $versionProducto->loan_commission_percentage,
            (string) $versionProducto->simple_interest_percentage,
            (int) $versionProducto->fortnights_count,
            (string) $versionProducto->insurance_amount,
            (string) $asignacionCategoria->versionCategoria->profit_percentage,
        );"""
new_calc = """$calculo = $this->calculador->calcular(
            (string) $versionProducto->nominal_amount,
            (string) $commissionRate,
            (string) $interestRate,
            (int) $installmentCount,
            (string) $insuranceAmount,
            (string) $asignacionCategoria->versionCategoria->profit_percentage,
        );"""

content = content.replace(old_calc, new_calc)

with open('app/Services/Vale/ServicioGeneracionVale.php', 'w', encoding='utf-8') as f:
    f.write(content)
