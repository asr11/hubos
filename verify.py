import sqlite3

db_path = '/home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db'
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

cursor.execute('''
    SELECT id, client_id, invoice_number, doc_type, description, due_date
    FROM invoices 
    WHERE client_id = "gi_7ae5b6c0-4972-42ca-81c3-8af25c64a94e"
    LIMIT 5
''')

rows = cursor.fetchall()
for r in rows:
    print(r)
