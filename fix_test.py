import re

filename = 'tests/Feature/Vale/CajaValeApiTest.php'
with open(filename, 'r', encoding='utf-8') as f:
    content = f.read()

pattern = r"postIdempotent\('(/api/v1/vouchers)',\s*\[('client_id'\s*=>\s*[^,]+,\s*'product_version_id'\s*=>\s*[^\]]+)\]\)"
replacement = r"postIdempotent('\1', [\2, 'commission_rate' => 0.10, 'interest_rate' => 0.03, 'insurance_amount' => 100, 'installment_count' => 4])"
content = re.sub(pattern, replacement, content)

with open(filename, 'w', encoding='utf-8') as f:
    f.write(content)
