import sqlite3
import json

db_path = '/home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db'

def main():
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()

    # Step 1: Add columns to invoices table
    columns = [
        ('invoice_number', 'TEXT'),
        ('doc_type', 'TEXT'),
        ('description', 'TEXT'),
        ('due_date', 'TEXT')
    ]
    
    cursor.execute("PRAGMA table_info(invoices)")
    existing_cols = [row[1] for row in cursor.fetchall()]

    for col_name, col_type in columns:
        if col_name not in existing_cols:
            print(f"Adding column {col_name}...")
            cursor.execute(f"ALTER TABLE invoices ADD COLUMN {col_name} {col_type}")
    
    conn.commit()

    # Step 2: Backfill from financeIncomes
    cursor.execute("SELECT id, data FROM generic_documents WHERE collection='financeIncomes'")
    rows = cursor.fetchall()
    
    doc_type_map = {
        'tax_invoice': 'חשבונית מס',
        'receipt': 'קבלה',
        'transaction_account': 'חשבון עסקה',
        'tax_invoice_receipt': 'חשבונית מס/קבלה',
        'credit_invoice': 'חשבונית זיכוי'
    }

    updates = 0
    for row in rows:
        doc_id, data_str = row
        if not data_str:
            continue
        try:
            data = json.loads(data_str)
            invoice_number = data.get('greenInvoiceNumber', '')
            document_kind = data.get('documentKind', '')
            doc_type = doc_type_map.get(document_kind, document_kind)
            description = data.get('description', '')
            due_date = data.get('invoiceDate', '')

            cursor.execute('''
                UPDATE invoices 
                SET invoice_number=?, doc_type=?, description=?, due_date=?
                WHERE id=?
            ''', (invoice_number, doc_type, description, due_date, doc_id))
            updates += cursor.rowcount
        except Exception as e:
            print(f"Error processing doc {doc_id}: {e}")

    conn.commit()
    conn.close()
    print(f"Backfilled {updates} invoices.")

if __name__ == '__main__':
    main()
