import sqlite3
import json
from collections import defaultdict

db_path = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db'
conn = sqlite3.connect(db_path)
query = "SELECT data FROM generic_documents WHERE collection = 'financeIncomes'"
rows = conn.execute(query).fetchall()

yearly = defaultdict(float)

for r in rows:
    try:
        d = json.loads(r[0])
        date_str = d.get('invoiceDate')
        amount = d.get('amount', 0)
        status = d.get('status', '')
        if date_str and status != 'cancelled':
            yr = date_str[:4]
            yearly[yr] += float(amount)
    except:
        pass

sorted_years = sorted(yearly.keys())

print('```mermaid')
print('xychart-beta')
print('    title "הכנסות משחר ההיסטוריה (₪)"')

if len(sorted_years) > 0:
    x_axis = '[' + ', '.join(['"' + y + '"' for y in sorted_years]) + ']'
    data_points = '[' + ', '.join([str(int(yearly[y])) for y in sorted_years]) + ']'
    print('    x-axis "שנה" ' + x_axis)
    print('    y-axis "הכנסות"')
    print('    bar ' + data_points)
print('```')
