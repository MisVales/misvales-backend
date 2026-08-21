import re

filename = 'tests/Feature/Vale/GeneracionValeApiTest.php'
with open(filename, 'r', encoding='utf-8') as f:
    content = f.read()

# Change the base_total from 30000.0000 to 10000.0000 to trigger the 409 error
content = content.replace(
    "'base_total' => '30000.0000'",
    "'base_total' => '10000.0000'"
)

with open(filename, 'w', encoding='utf-8') as f:
    f.write(content)
