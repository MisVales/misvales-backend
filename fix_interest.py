import re

filename = 'tests/Feature/Vale/GeneracionValeApiTest.php'
with open(filename, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace("'interest_rate' => 0.03", "'interest_rate' => 0.02")

with open(filename, 'w', encoding='utf-8') as f:
    f.write(content)
