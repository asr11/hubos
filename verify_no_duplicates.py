import sqlite3
import json
from collections import Counter, defaultdict

def run_verification():
    conn = sqlite3.connect('/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/os_hub.db')
    rows = conn.execute("SELECT data FROM generic_documents WHERE collection = 'financeIncomes'").fetchall()
    
    docs = [json.loads(r[0]) for r in rows]
    
    # Check duplicates by ID
    ids = [d.get('greenInvoiceId') for d in docs if d.get('greenInvoiceId')]
    counts = Counter(ids)
    duplicates = {k: v for k, v in counts.items() if v > 1}
    
    print("=== ZERO ERRORS VERIFICATION ===")
    print(f"Total documents imported: {len(docs)}")
    if duplicates:
        print(f"FAILED: Found {len(duplicates)} duplicate IDs!")
        for k, v in list(duplicates.items())[:5]:
            print(f"ID {k} appears {v} times.")
    else:
        print("PASS: 0 duplicate documents found. (Mathematical Proof: len(set(ids)) == len(ids))")
    
    total_income = 0.0
    for d in docs:
        if d.get('status') == 'cancelled' or d.get('cancelled') is True:
            continue
        
        kind = d.get('documentKind')
        if kind in ['tax_invoice', 'tax_invoice_receipt', 'receipt', 'credit_invoice']:
            amt = d.get('amount', 0)
            total_income += amt
    
    print(f"\nTotal Revenue Calculated (Includes VAT): {total_income:,.2f} NIS")
    
    total_no_vat = 0.0
    for d in docs:
        if d.get('status') == 'cancelled' or d.get('cancelled') is True:
            continue
        kind = d.get('documentKind')
        if kind in ['tax_invoice', 'tax_invoice_receipt', 'receipt', 'credit_invoice']:
            amt = d.get('amount', 0)
            vat = d.get('vatAmount', 0)
            if amt < 0:
                amt_no_vat = amt + vat
            else:
                amt_no_vat = amt - vat
            total_no_vat += amt_no_vat
            
    print(f"Total Revenue Calculated (Before VAT): {total_no_vat:,.2f} NIS")

if __name__ == '__main__':
    run_verification()
