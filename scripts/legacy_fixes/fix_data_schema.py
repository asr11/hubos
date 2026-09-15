import sqlite3
import json
import datetime

conn = sqlite3.connect('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db')
cursor = conn.cursor()

def parse_date(date_str):
    try:
        # 12/08/2026
        parts = date_str.split('/')
        if len(parts) == 3:
            return f"{parts[2]}-{parts[1]}-{parts[0]}"
    except:
        pass
    return "2026-01-01"

def fix_incomes():
    cursor.execute("SELECT id, data FROM generic_documents WHERE collection IN ('financeIncomes', 'invoices')")
    rows = cursor.fetchall()
    for doc_id, data_str in rows:
        data = json.loads(data_str)
        
        fixed = {
            "id": doc_id,
            "projectId": "",
            "clientId": "", # UI doesn't strictly need a valid one if it just shows them
            "clientName": data.get("clientName", ""),
            "amount": data.get("totalAmount", 0),
            "amountIncludesVat": True,
            "invoiceDate": parse_date(data.get("date", "01/01/2026")),
            "invoiceNumber": data.get("documentNumber", "0"),
            "documentKind": "invoice_receipt",
            "description": data.get("description", ""),
            "status": "paid",
            "paidDate": parse_date(data.get("date", "01/01/2026")),
        }
        
        cursor.execute("UPDATE generic_documents SET data = ? WHERE id = ? AND collection IN ('financeIncomes', 'invoices')", (json.dumps(fixed), doc_id))
    print(f"Fixed {len(rows)} incomes/invoices.")

def fix_expenses():
    cursor.execute("SELECT id, data FROM generic_documents WHERE collection = 'financeExpenses'")
    rows = cursor.fetchall()
    for doc_id, data_str in rows:
        data = json.loads(data_str)
        
        dt = parse_date(data.get("date", "01/01/2026"))
        month = dt[:7] # YYYY-MM
        
        fixed = {
            "id": doc_id,
            "type": "fixed",
            "supplierName": data.get("supplierName", "Unknown"),
            "name": data.get("description", "Expense"),
            "amount": data.get("totalAmount", 0),
            "billingMonth": month,
            "pnlMonth": month,
            "status": "approved",
            "vatClass": "full",
        }
        
        cursor.execute("UPDATE generic_documents SET data = ? WHERE id = ? AND collection = 'financeExpenses'", (json.dumps(fixed), doc_id))
    print(f"Fixed {len(rows)} expenses.")

fix_incomes()
fix_expenses()

conn.commit()
conn.close()
