import sqlite3
import json
import csv
import uuid
import os
import shutil

# 1. Copy files to project folder
src_dir = '/home/asr11/Downloads'
dst_dir = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/morning'
os.makedirs(dst_dir, exist_ok=True)

files_to_copy = ['clients.csv', 'documents.csv', 'expenses.csv']
for f in files_to_copy:
    src_path = os.path.join(src_dir, f)
    if os.path.exists(src_path):
        shutil.copy(src_path, dst_dir)
        print(f"Copied {f} to {dst_dir}")

# 2. Insert into SQLite
db_path = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db'
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

def insert(collection, data):
    doc_id = data.get('id') or str(uuid.uuid4())
    data['id'] = doc_id
    cursor.execute(
        'INSERT OR REPLACE INTO generic_documents (id, collection, data) VALUES (?, ?, ?)',
        (doc_id, collection, json.dumps(data))
    )

def clear_collection(collection):
    cursor.execute('DELETE FROM generic_documents WHERE collection = ?', (collection,))
    print(f"Cleared {collection}")

# Import clients
clients_file = os.path.join(dst_dir, 'clients.csv')
if os.path.exists(clients_file):
    clear_collection('clients')
    with open(clients_file, 'r', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for row in reader:
            client_id = row.get('מזהה לקוח') or str(uuid.uuid4())
            data = {
                'id': client_id,
                'name': row.get('שם לקוח', ''),
                'contact': row.get('שם איש קשר', ''),
                'phone': row.get('נייד', '') or row.get('טלפון', ''),
                'email': row.get('כתובות מייל', ''),
                'taxId': row.get('מספר עוסק', ''),
                'city': row.get('עיר', ''),
                'addressLine': row.get('כתובת', ''),
                'status': 'active',
                'createdAt': '2026-08-31T00:00:00Z'
            }
            insert('clients', data)
    print("Imported clients.")

# Import documents (incomes/invoices)
docs_file = os.path.join(dst_dir, 'documents.csv')
if os.path.exists(docs_file):
    clear_collection('financeIncomes')
    with open(docs_file, 'r', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for row in reader:
            doc_id = row.get('מזהה מסמך') or str(uuid.uuid4())
            data = {
                'id': doc_id,
                'documentNumber': row.get('מספר המסמך', ''),
                'date': row.get('תאריך ערך', ''),
                'type': row.get('סוג המסמך', ''),
                'clientName': row.get('לקוח', ''),
                'description': row.get('תיאור', ''),
                'totalAmount': float(row.get('סכום', 0) or 0),
                'currency': row.get('מטבע', 'ILS'),
                'status': row.get('סטטוס', ''),
                'downloadUrl': row.get('קישור', '')
            }
            insert('financeIncomes', data)
    print("Imported documents.")

# Import expenses
exp_file = os.path.join(dst_dir, 'expenses.csv')
if os.path.exists(exp_file):
    clear_collection('financeExpenses')
    with open(exp_file, 'r', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for row in reader:
            doc_id = str(uuid.uuid4()) # Expenses CSV in GreenInvoice doesn't always have ID
            data = {
                'id': doc_id,
                'documentNumber': row.get('מספר המסמך', ''),
                'date': row.get('תאריך המסמך', ''),
                'supplierName': row.get('ספק', ''),
                'description': row.get('תיאור', ''),
                'totalAmount': float(row.get('סכום כולל מע״מ', 0) or 0),
                'currency': row.get('מטבע', 'ILS'),
                'status': row.get('סטטוס', '')
            }
            insert('financeExpenses', data)
    print("Imported expenses.")

conn.commit()
conn.close()
print("All done!")
