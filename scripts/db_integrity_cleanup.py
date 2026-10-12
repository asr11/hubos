#!/usr/bin/env python3
"""
Database Integrity Cleanup Script
=================================
Performs full sanity check and repairs on /home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db:
1. Syncs 16 missing clients from generic_documents to SQL clients table.
2. Merges duplicate clients (e.g. קפיטל מדיה גרופ בע"מ) and disambiguates placeholders.
3. Fixes 8 orphaned project_id references in tasks table (replaces project names with IDs).
4. Resolves Hebrew client names to client IDs in projects table.
5. Resolves Hebrew client names to client IDs in invoices table.
6. Links hostingRecords in generic_documents to matching client IDs.
7. Validates JSON payloads across generic_documents.
"""

import sqlite3
import json
import os
import sys

DB_PATH = '/home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db'

def run_integrity_cleanup(db_path=DB_PATH):
    print(f"Connecting to database at {db_path}...")
    conn = sqlite3.connect(db_path)
    cur = conn.cursor()

    # Step 1: Sync missing clients from generic_documents to clients SQL table
    print("\n--- STEP 1: Syncing missing clients from generic_documents to clients table ---")
    cur.execute("SELECT id, data FROM generic_documents WHERE collection = 'clients' AND id NOT IN (SELECT id FROM clients)")
    missing_clients = cur.fetchall()
    print(f"Found {len(missing_clients)} clients in generic_documents missing from SQL table.")
    
    synced_clients = 0
    for cid, data_str in missing_clients:
        d = json.loads(data_str)
        name = d.get('name') or ''
        # Special handling for duplicate placeholder names
        if cid == 'xl_b836e775-f7c3-4dab-9cdb-65abf8451aaa':
            name = "שוורץ פטס (schwartzpets.co.il)"
        elif cid == 'xl_372e5e1b-23d1-4730-bad3-eec69e2d0e65':
            name = "ש. שוורץ (s-schwartz.com)"

        green_invoice_id = d.get('greenInvoiceId') or (cid.replace('gi_', '') if cid.startswith('gi_') else cid)
        tax_id = d.get('taxId') or d.get('vatNumber') or ''
        email = d.get('email') or ''
        phone = d.get('phone') or ''
        status = d.get('status') or 'active'
        created_at = d.get('createdAt') or '2026-10-01 00:00:00'
        domain = d.get('domain') or None

        cur.execute("""
            INSERT INTO clients (id, green_invoice_id, name, tax_id, email, phone, status, created_at, domain)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (cid, green_invoice_id, name, tax_id, email, phone, status, created_at, domain))
        synced_clients += 1

    print(f"Synced {synced_clients} client records into SQL clients table.")

    # Also update generic_documents data for the disambiguated placeholder records
    for cid, new_name in [
        ('xl_b836e775-f7c3-4dab-9cdb-65abf8451aaa', "שוורץ פטס (schwartzpets.co.il)"),
        ('xl_372e5e1b-23d1-4730-bad3-eec69e2d0e65', "ש. שוורץ (s-schwartz.com)")
    ]:
        cur.execute("SELECT data FROM generic_documents WHERE id = ?", (cid,))
        r = cur.fetchone()
        if r:
            doc = json.loads(r[0])
            doc['name'] = new_name
            cur.execute("UPDATE generic_documents SET data = ? WHERE id = ?", (json.dumps(doc, ensure_ascii=False), cid))

    # Step 2: Merge duplicate client: קפיטל מדיה גרופ בע"מ
    print("\n--- STEP 2: Merging duplicate client 'קפיטל מדיה גרופ בע\"מ' ---")
    keep_id = 'gi_3a2d0f81-8363-41ac-8714-61a101c39268'
    remove_id = 'gi_61412eb5-3d62-47fa-9e93-4f1476239859'

    # Ensure keep_id has the complete info (phone 0522538498, email capitalnir@gmail.com, contact ניר הקוסם)
    cur.execute("UPDATE clients SET phone = '0522538498', email = 'capitalnir@gmail.com' WHERE id = ?", (keep_id,))
    
    # Re-link any references across all tables
    cur.execute("UPDATE projects SET client_id = ? WHERE client_id = ?", (keep_id, remove_id))
    cur.execute("UPDATE invoices SET client_id = ? WHERE client_id = ?", (keep_id, remove_id))
    cur.execute("UPDATE quotes SET client_id = ? WHERE client_id = ?", (keep_id, remove_id))
    
    # Remove redundant client record
    cur.execute("DELETE FROM clients WHERE id = ?", (remove_id,))
    cur.execute("DELETE FROM generic_documents WHERE id = ? AND collection = 'clients'", (remove_id,))
    print(f"Merged {remove_id} into {keep_id} and removed duplicate record.")

    # Step 3: Fix orphaned project references in tasks table
    print("\n--- STEP 3: Fixing project_id in tasks table ---")
    task_project_map = {
        'HUQAmVIyUIXye5nWKjH3': 'rxS76j9T7X7H1pKFnDBM',  # Seo-haifa
        'KbsGb93Ia3TrYCrQ0G3x': 'sNvJG97mkBzDP0VOphHB',  # Bakrayot.co.il
        'XHibQfJ4cb4empnWOFHs': 'Fh6UVDMeFUejDCayNBqJ',  # הקמת קטלוג מוצרים דיגיטלי
        'YyHur9QMPhRiYEq5fVrt': 'OqwN95sWdrv075zCf4Dn',  # פרוייקט אנלייזר
        'krnqqXnye5MutjxKPr2u': 'sbi875V3depQ0M8MuUv7',  # פרויקט x1
        'mFklignPoUZnAPrfZRKU': 'sNvJG97mkBzDP0VOphHB',  # Bakrayot.co.il
        'oDjDh3UhmfJezfd3KQXp': 'fCcgoqd3qutdQu5maCOY',  # Hub-store
        'p4trEnDUnoWcz9km54rp': 'rxS76j9T7X7H1pKFnDBM'   # Seo-haifa
    }
    fixed_tasks = 0
    for tid, pid in task_project_map.items():
        cur.execute("UPDATE tasks SET project_id = ? WHERE id = ?", (pid, tid))
        fixed_tasks += cur.rowcount
    print(f"Fixed project_id for {fixed_tasks} tasks.")

    # Step 4: Resolve client names in projects table to client IDs
    print("\n--- STEP 4: Resolving client_id in projects table ---")
    cur.execute("SELECT id, name FROM clients")
    client_name_to_id = {r[1].strip().lower(): r[0] for r in cur.fetchall()}

    cur.execute("SELECT id, name, client_id FROM projects WHERE client_id NOT IN (SELECT id FROM clients) AND client_id IS NOT NULL AND client_id != ''")
    unresolved_projects = cur.fetchall()
    fixed_projects = 0
    for pid, pname, pcid in unresolved_projects:
        match_id = client_name_to_id.get(pcid.strip().lower())
        if match_id:
            cur.execute("UPDATE projects SET client_id = ? WHERE id = ?", (match_id, pid))
            fixed_projects += cur.rowcount
    print(f"Resolved client_id for {fixed_projects} projects by name match.")

    # Step 5: Resolve client names in invoices table to client IDs
    print("\n--- STEP 5: Resolving client_id in invoices table ---")
    cur.execute("SELECT id, invoice_number, client_id FROM invoices WHERE client_id NOT IN (SELECT id FROM clients) AND client_id IS NOT NULL AND client_id != ''")
    unresolved_invoices = cur.fetchall()
    fixed_invoices = 0
    for iid, inum, icid in unresolved_invoices:
        cname_clean = icid.strip().lower()
        match_id = client_name_to_id.get(cname_clean)
        
        # Specific known aliases
        if not match_id:
            if 'דודה יונה' in icid or 'מסעדת יונה' in icid:
                match_id = 'gi_c1a9dbef-b79d-410b-b2fc-6d77cebc3841'
            elif 'שיקשוק' in icid:
                match_id = 'gi_3560834f-731d-41eb-8218-454283d7bcc9'
            elif 'בדיקה טכנית' in icid:
                match_id = 'gi_8d9cd8bc-1517-4262-8792-4380d907f996'

        if match_id:
            cur.execute("UPDATE invoices SET client_id = ? WHERE id = ?", (match_id, iid))
            fixed_invoices += cur.rowcount
    print(f"Resolved client_id for {fixed_invoices} invoices by name/alias match.")

    # Step 6: Link hostingRecords to clientId in generic_documents
    print("\n--- STEP 6: Linking hostingRecords to clientId in generic_documents ---")
    cur.execute("SELECT id, domain FROM clients WHERE domain IS NOT NULL AND domain != ''")
    client_domain_to_id = {}
    for cid, dom in cur.fetchall():
        d_clean = dom.strip().lower()
        if d_clean.startswith('www.'): d_clean = d_clean[4:]
        client_domain_to_id[d_clean] = cid

    cur.execute("SELECT id, data FROM generic_documents WHERE collection = 'hostingRecords'")
    h_rows = cur.fetchall()
    linked_hosting = 0
    for doc_id, data_str in h_rows:
        d = json.loads(data_str)
        if not d.get('clientId'):
            dom = (d.get('domain') or '').strip().lower()
            if dom.startswith('www.'): dom = dom[4:]
            match_cid = client_domain_to_id.get(dom)
            if match_cid:
                d['clientId'] = match_cid
                cur.execute("UPDATE generic_documents SET data = ? WHERE id = ? AND collection = 'hostingRecords'", 
                            (json.dumps(d, ensure_ascii=False), doc_id))
                linked_hosting += 1
    print(f"Linked {linked_hosting} hostingRecords to valid client IDs based on verified domain matching.")

    # Commit all changes
    conn.commit()
    print("\nAll database updates successfully committed!")

    # Step 7: Post-cleanup verification & report
    print("\n" + "="*50)
    print("=== POST-CLEANUP INTEGRITY VERIFICATION REPORT ===")
    print("="*50)

    # 1. Total clients & duplicates check
    cur.execute("SELECT count(*) FROM clients")
    total_clients_sql = cur.fetchone()[0]
    cur.execute("SELECT count(*) FROM generic_documents WHERE collection = 'clients'")
    total_clients_gd = cur.fetchone()[0]

    cur.execute("SELECT name, count(*) FROM clients GROUP BY TRIM(LOWER(name)) HAVING count(*) > 1")
    sql_dupes = cur.fetchall()

    cur.execute("SELECT id, data FROM generic_documents WHERE collection = 'clients'")
    c_names_gd = {}
    for did, dstr in cur.fetchall():
        n = (json.loads(dstr).get('name') or '').strip().lower()
        c_names_gd.setdefault(n, []).append(did)
    gd_dupes = {k: v for k, v in c_names_gd.items() if len(v) > 1}

    print(f"Total Clients (SQL table): {total_clients_sql}")
    print(f"Total Clients (generic_documents): {total_clients_gd}")
    print(f"Duplicate client names in SQL table: {len(sql_dupes)} (Passed: {len(sql_dupes) == 0})")
    print(f"Duplicate client names in generic_documents: {len(gd_dupes)} (Passed: {len(gd_dupes) == 0})")

    # 2. Tasks project_id integrity
    cur.execute("""
        SELECT count(*) FROM tasks t 
        LEFT JOIN projects p ON t.project_id = p.id 
        WHERE t.project_id IS NOT NULL AND t.project_id != '' AND p.id IS NULL
    """)
    orphaned_tasks_count = cur.fetchone()[0]
    print(f"Orphaned tasks (invalid project_id): {orphaned_tasks_count} (Passed: {orphaned_tasks_count == 0})")

    # 3. Projects client_id integrity
    cur.execute("""
        SELECT count(*) FROM projects p 
        LEFT JOIN clients c ON p.client_id = c.id 
        WHERE p.client_id IS NOT NULL AND p.client_id != '' AND c.id IS NULL
    """)
    orphaned_projects_count = cur.fetchone()[0]
    print(f"Orphaned projects (invalid client_id): {orphaned_projects_count} (Passed: {orphaned_projects_count == 0})")

    # 4. Invoices client_id integrity
    cur.execute("""
        SELECT count(*) FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        WHERE i.client_id IS NOT NULL AND i.client_id != '' AND c.id IS NULL
    """)
    orphaned_invoices_count = cur.fetchone()[0]
    print(f"Invoices with unmatched client_id: {orphaned_invoices_count} (Reduced from 280)")

    # 5. Generic documents client references integrity
    cur.execute("SELECT id, collection, data FROM generic_documents WHERE collection != 'clients'")
    gd_orphaned_clients = 0
    for did, col, dstr in cur.fetchall():
        d = json.loads(dstr)
        cid = d.get('clientId') or d.get('client_id')
        if cid:
            cur.execute("SELECT 1 FROM clients WHERE id = ?", (cid,))
            if not cur.fetchone():
                gd_orphaned_clients += 1
    print(f"Orphaned client references in generic_documents: {gd_orphaned_clients} (Passed: {gd_orphaned_clients == 0})")

    # 6. JSON validity check
    cur.execute("SELECT id, data FROM generic_documents")
    json_errors = 0
    for did, dstr in cur.fetchall():
        try:
            json.loads(dstr)
        except Exception:
            json_errors += 1
    print(f"Invalid JSON payloads in generic_documents: {json_errors} (Passed: {json_errors == 0})")

    conn.close()

if __name__ == '__main__':
    run_integrity_cleanup()
