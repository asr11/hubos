#!/usr/bin/env python3
"""
Backfill script for client domains in os_hub.db.
Updates generic_documents (collection='clients') data JSON with the 'domain' field,
and updates the clients table 'domain' column.

Priority rules:
1. Existing valid domain field (preserved)
2. Most recent paid invoice for hosting / domain renewal for this client
3. Direct hosting record matching client ID
4. Client notes field
5. Associated project name
6. Non-generic email domain (verified: must not be claimed by another paying client)
"""

import sqlite3
import json
import re
import sys

DB_PATH = '/home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db'

DOMAIN_REGEX = re.compile(
    r'\b(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+(?:co\.il|org\.il|net\.il|ac\.il|muni\.il|k12\.il|gov\.il|com|org|net|info|biz|me|co|io|ai|tv|cc|app|dev|agency|store|online|tech|space|club|site|pro|eu|uk|de|fr|ru|us)\b',
    re.IGNORECASE
)

GENERIC_DOMAINS = {
    'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.il',
    'hotmail.com', 'hotmail.co.il', 'outlook.com', 'live.com', 'msn.com',
    'icloud.com', 'me.com', 'mac.com', 'walla.co.il', 'walla.com',
    '012.net.il', '012.net', '013.net', '013net.net', '014.net.il',
    'netvision.net.il', 'bezeqint.net', 'zahav.net.il', 'zahav.net', 'barak.net.il',
    'inter.net.il', 'actcom.co.il', 'actcom.net.il',
    'mail.ru', 'yandex.ru', 'yandex.com', 'protonmail.com', 'proton.me', 'aol.com',
    'hub.co.il', 'greeninvoice.co.il', 'morning.co.il'
}

def clean_domain(d):
    if not d:
        return None
    d = d.strip().lower()
    if d.startswith('http://') or d.startswith('https://'):
        d = d.split('://', 1)[1]
    d = d.split('/')[0]
    if d.startswith('www.'):
        d = d[4:]
    d = d.strip('.')
    return d

def extract_domains(text):
    if not text or not isinstance(text, str):
        return []
    matches = DOMAIN_REGEX.findall(text)
    res = []
    for m in matches:
        cd = clean_domain(m)
        if cd and cd not in GENERIC_DOMAINS and cd not in res:
            res.append(cd)
    return res

def run_backfill(db_path=DB_PATH):
    conn = sqlite3.connect(db_path)
    c = conn.cursor()

    # Step 1: Ensure clients table has domain column
    c.execute("PRAGMA table_info(clients)")
    existing_cols = [row[1] for row in c.fetchall()]
    if 'domain' not in existing_cols:
        print("Adding 'domain' column to clients table...")
        c.execute("ALTER TABLE clients ADD COLUMN domain TEXT")
        conn.commit()

    # Step 2: Load clients from generic_documents
    c.execute("SELECT id, data FROM generic_documents WHERE collection='clients'")
    clients_gd = {row[0]: json.loads(row[1]) for row in c.fetchall()}
    print(f"Loaded {len(clients_gd)} clients from generic_documents.")

    # Step 3: Map invoices / financeIncomes
    c.execute("SELECT id, collection, data FROM generic_documents WHERE collection IN ('financeIncomes', 'invoices')")
    inv_by_cid = {}
    inv_by_cname = {}
    domain_latest_payment = {}

    for doc_id, col, data_str in c.fetchall():
        d = json.loads(data_str)
        cid = d.get('clientId') or d.get('client_id')
        cname = (d.get('greenInvoiceClientName') or d.get('clientName') or d.get('client_name') or '').strip()
        date = d.get('invoiceDate') or d.get('date') or d.get('dueDate') or '1970-01-01'
        desc = d.get('description') or ''
        domains = extract_domains(desc)
        for dm in domains:
            if cid:
                inv_by_cid.setdefault(cid, []).append((date, dm))
            if cname:
                inv_by_cname.setdefault(cname, []).append((date, dm))
            if dm not in domain_latest_payment or date > domain_latest_payment[dm][0]:
                domain_latest_payment[dm] = (date, cid, cname)

    # Step 4: Map hosting records
    c.execute("SELECT id, collection, data FROM generic_documents WHERE collection IN ('hostingRecords', 'hosting', 'hosting_records')")
    hosting_by_cid = {}
    for doc_id, col, data_str in c.fetchall():
        h = json.loads(data_str)
        d = clean_domain(h.get('domain'))
        if d and d not in GENERIC_DOMAINS:
            cid = h.get('clientId') or h.get('client_id')
            if cid:
                hosting_by_cid.setdefault(cid, []).append(d)

    # Step 5: Map projects
    c.execute("SELECT id, name, client_id FROM projects")
    proj_by_cid = {}
    proj_by_cname = {}
    for pid, pname, pcid in c.fetchall():
        pdomains = extract_domains(pname)
        if pcid and pdomains:
            proj_by_cid.setdefault(pcid, []).extend(pdomains)
            proj_by_cname.setdefault(pcid, []).extend(pdomains)

    # Step 6: Determine domain assignment for each client
    assignments = {}
    reasons = {}

    for cid, client in clients_gd.items():
        cname = (client.get('name') or '').strip()
        assigned = None
        reason = None

        # Priority A: Existing valid domain
        ex = clean_domain(client.get('domain'))
        if ex and ex not in GENERIC_DOMAINS:
            assigned = ex
            reason = 'existing_valid_field'

        # Priority B: Invoices (verified: client must be the latest payer for this domain)
        if not assigned:
            c_invs = inv_by_cid.get(cid, []) or inv_by_cname.get(cname, [])
            if c_invs:
                c_invs.sort(key=lambda x: x[0], reverse=True)
                for inv_date, idom in c_invs:
                    latest = domain_latest_payment.get(idom)
                    if latest and (latest[1] == cid or latest[2] == cname):
                        assigned = idom
                        reason = f'invoice_payer ({inv_date})'
                        break

        # Priority C: Hosting records by explicit client ID
        if not assigned and cid in hosting_by_cid:
            for hdom in hosting_by_cid[cid]:
                latest = domain_latest_payment.get(hdom)
                if not latest or latest[1] == cid or latest[2] == cname:
                    assigned = hdom
                    reason = 'hosting_record_direct'
                    break

        # Priority D: Client notes
        if not assigned:
            notes = client.get('notes') or ''
            notes_doms = extract_domains(notes)
            for ndom in notes_doms:
                latest = domain_latest_payment.get(ndom)
                if not latest or latest[1] == cid or latest[2] == cname:
                    assigned = ndom
                    reason = 'client_notes'
                    break

        # Priority E: Projects associated with client
        if not assigned:
            pdoms = proj_by_cid.get(cid, []) or proj_by_cname.get(cname, [])
            for pdom in pdoms:
                latest = domain_latest_payment.get(pdom)
                if not latest or latest[1] == cid or latest[2] == cname:
                    assigned = pdom
                    reason = 'project_name'
                    break

        # Priority F: Email domain (strict check against other payers)
        if not assigned:
            email = (client.get('email') or '').strip()
            if '@' in email:
                edom = clean_domain(email.split('@')[1])
                if edom and edom not in GENERIC_DOMAINS:
                    latest = domain_latest_payment.get(edom)
                    if not latest or latest[1] == cid or latest[2] == cname:
                        assigned = edom
                        reason = 'email_domain'

        if assigned:
            assignments[cid] = assigned
            reasons[cid] = reason

    print(f"Identified domains for {len(assignments)} clients.")

    # Step 7: Apply updates to generic_documents and clients table
    updated_gd_count = 0
    updated_sql_count = 0

    for cid, domain in assignments.items():
        client_data = clients_gd[cid]
        # Only update if domain is changing or missing
        if client_data.get('domain') != domain:
            client_data['domain'] = domain
            c.execute(
                "UPDATE generic_documents SET data=? WHERE id=? AND collection='clients'",
                (json.dumps(client_data, ensure_ascii=False), cid)
            )
            updated_gd_count += c.rowcount

        # Update SQL clients table
        c.execute("UPDATE clients SET domain=? WHERE id=?", (domain, cid))
        updated_sql_count += c.rowcount

    conn.commit()
    print(f"Updated {updated_gd_count} generic_documents client records.")
    print(f"Updated {updated_sql_count} clients table records.")

    # Step 8: Verify Avantgarde
    avantgarde_id = 'gi_c32e6cf6-4344-477f-a1f6-e852de8bbfc9'
    c.execute("SELECT data FROM generic_documents WHERE id=? AND collection='clients'", (avantgarde_id,))
    row = c.fetchone()
    if row:
        av_data = json.loads(row[0])
        print(f"\n[VERIFICATION] Avantgarde in generic_documents:")
        print(f"  Name: {av_data.get('name')}")
        print(f"  Email: {av_data.get('email')}")
        print(f"  Domain: {av_data.get('domain')}")
        assert av_data.get('domain') == 'av-re.co.il', f"Expected av-re.co.il, got {av_data.get('domain')}"

    c.execute("SELECT id, name, domain FROM clients WHERE id=?", (avantgarde_id,))
    row = c.fetchone()
    if row:
        print(f"[VERIFICATION] Avantgarde in clients table:")
        print(f"  ID: {row[0]}")
        print(f"  Name: {row[1]}")
        print(f"  Domain: {row[2]}")
        assert row[2] == 'av-re.co.il', f"Expected av-re.co.il, got {row[2]}"

    conn.close()
    print("\nBackfill and verification completed successfully!")

if __name__ == '__main__':
    run_backfill()
