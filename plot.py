import csv
from collections import defaultdict
from datetime import datetime

totals = defaultdict(float)

with open('/home/asr11/02_HUB_Dev/os.hub.co.il/data/morning_csv/documents.csv', mode='r', encoding='utf-8-sig') as f:
    reader = csv.DictReader(f)
    for row in reader:
        date_str = row.get('תאריך ערך', '') or row.get('תאריך המסמך', '')
        amt_str = row.get('סכום', '0').replace(',', '')
        status = row.get('סטטוס', '')
        
        if date_str and status != 'מבוטל':
            try:
                dt = datetime.strptime(date_str, '%d/%m/%Y')
                month_key = dt.strftime('%Y-%m')
                amt = float(amt_str)
                totals[month_key] += amt
            except Exception as e:
                pass

sorted_months = sorted(totals.keys())
print('```mermaid')
print('xychart-beta')
print('    title "הכנסות משחר ההיסטוריה (שנתי)"')

if len(sorted_months) > 0:
    yearly = defaultdict(float)
    for m in sorted_months:
        yr = m.split('-')[0]
        yearly[yr] += totals[m]
    
    sorted_years = sorted(yearly.keys())
    x_axis = '[' + ', '.join(['"' + y + '"' for y in sorted_years]) + ']'
    data_points = '[' + ', '.join([str(int(yearly[y])) for y in sorted_years]) + ']'
    
    print('    x-axis "שנה" ' + x_axis)
    print('    y-axis "הכנסות"')
    print('    bar ' + data_points)
print('```')
