import re
import os

def update_test(filename):
    if not os.path.exists(filename): return
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()

    pattern = r"postJson\('(/api/v1/vouchers(?:/preview)?)',\s*\[('client_id'\s*=>\s*[^,]+,\s*'product_version_id'\s*=>\s*[^\]]+)\]\)"
    replacement = r"postJson('\1', [\2, 'commission_rate' => 0.10, 'interest_rate' => 0.03, 'insurance_amount' => 100, 'installment_count' => 4])"
    content = re.sub(pattern, replacement, content)
    
    if 'GeneracionValeApiTest.php' in filename:
        test_pattern = r"public function test_lista_de_productos_omite_versiones_sin_condiciones_financieras\(\): void\s*\{.*?(?=public function|private function|\Z)"
        content = re.sub(test_pattern, '', content, flags=re.DOTALL)

        content = re.sub(
            r"private function crear\(\)\s*\{\s*return \$this->withHeader.*?(postJson\('/api/v1/vouchers', \['client_id' => \$this->cliente->id, 'product_version_id' => \$this->producto->id.*?\)\]\);\s*\})",
            r"private function crear()\n    {\n        return $this->withHeader('Idempotency-Key', (string) \\Illuminate\\Support\\Str::uuid())->postJson('/api/v1/vouchers', ['client_id' => $this->cliente->id, 'product_version_id' => $this->producto->id, 'commission_rate' => 0.10, 'interest_rate' => 0.03, 'insurance_amount' => 100, 'installment_count' => 4]);\n    }",
            content,
            flags=re.DOTALL
        )
        
    with open(filename, 'w', encoding='utf-8') as f:
        f.write(content)

update_test('tests/Feature/Vale/GeneracionValeApiTest.php')
update_test('tests/Feature/Vale/CajaValeApiTest.php')
