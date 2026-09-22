import sqlite3
import json
import os
import re

OUT_DIR = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/golden_map'
DB_PATH = '/home/asr11/02_HUB_Dev/os.hub.co.il/server/data/legacy_hub_os.db'
RAW_MORNING_DIR = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/morning/raw'

os.makedirs(OUT_DIR, exist_ok=True)

def normalize_phone(p):
    if not p: return ''
    p = re.sub(r'[\-\s\+\(\)]', '', str(p))
    return re.sub(r'^972', '0', p)

def normalize_name(n):
    if not n: return ''
    n = str(n).strip().lower()
    n = re.sub(r'בע"מ|בע״מ|שותפות|מוגבלת', '', n).strip()
    return n

# Load DB data
conn = sqlite3.connect(DB_PATH)
conn.row_factory = sqlite3.Row
def fetch_table(conn, table):
    try:
        return [dict(r) for r in conn.execute(f'SELECT * FROM {table}')]
    except:
        return []

os_hub_clients = fetch_table(conn, 'clients')
os_hub_domains = fetch_table(conn, 'domains')
os_hub_projects = fetch_table(conn, 'projects')
os_hub_leads = fetch_table(conn, 'leads')
conn.close()

# Load Morning Data
m_clients = []
if os.path.exists(os.path.join(RAW_MORNING_DIR, 'clients.jsonl')):
    with open(os.path.join(RAW_MORNING_DIR, 'clients.jsonl'), 'r', encoding='utf-8') as f:
        for line in f:
            if line.strip(): m_clients.append(json.loads(line))

m_docs = []
if os.path.exists(os.path.join(RAW_MORNING_DIR, 'documents.jsonl')):
    with open(os.path.join(RAW_MORNING_DIR, 'documents.jsonl'), 'r', encoding='utf-8') as f:
        for line in f:
            if line.strip(): m_docs.append(json.loads(line))

# Resolution Engine
golden_map = {}
entity_counter = 1

def get_or_create(match_fields):
    global entity_counter
    # Match
    for eid, e in golden_map.items():
        if match_fields.get('email') and match_fields['email'] in e['emails']: return e
        if match_fields.get('phone') and match_fields['phone'] in e['phones']: return e
        if match_fields.get('taxId') and e['tax_id'] == match_fields['taxId']: return e
        if match_fields.get('sourceId') and any(s['id'] == match_fields['sourceId'] for s in e['sources']): return e
    
    # Fuzzy Name
    if match_fields.get('name'):
        norm = normalize_name(match_fields['name'])
        for eid, e in golden_map.items():
            if normalize_name(e['name']) == norm: return e
            
    # Create new
    new_id = f"HUB_ENT_{entity_counter}"
    entity_counter += 1
    new_e = {
        'hub_id': new_id,
        'name': match_fields.get('name', 'Unknown'),
        'emails': set([match_fields['email']] if match_fields.get('email') else []),
        'phones': set([match_fields['phone']] if match_fields.get('phone') else []),
        'tax_id': match_fields.get('taxId', ''),
        'sources': [],
        'domains': set(),
        'projects': set(),
        'payments': 0,
        'totalRevenue': 0
    }
    golden_map[new_id] = new_e
    return new_e

# Ingest os_hub clients
for c in os_hub_clients:
    e = get_or_create({'email': c.get('email'), 'phone': normalize_phone(c.get('phone')), 'name': c.get('name')})
    e['sources'].append({'source': 'os_hub', 'type': 'client', 'id': str(c.get('id'))})
    if c.get('green_invoice_id'):
        e['sources'].append({'source': 'morning_linked', 'type': 'client', 'id': str(c.get('green_invoice_id'))})

# Ingest morning clients
for c in m_clients:
    emails = c.get('emails', [])
    phone = normalize_phone(c.get('phone'))
    e = get_or_create({'email': emails[0] if emails else None, 'phone': phone, 'taxId': c.get('taxId'), 'name': c.get('name'), 'sourceId': c.get('id')})
    e['sources'].append({'source': 'morning', 'type': 'client', 'id': str(c.get('id'))})
    for em in emails: e['emails'].add(em)
    if phone: e['phones'].add(phone)
    if c.get('taxId'): e['tax_id'] = c.get('taxId')

# Ingest Leads
for l in os_hub_leads:
    e = get_or_create({'email': l.get('email'), 'phone': normalize_phone(l.get('phone')), 'name': l.get('name')})
    e['sources'].append({'source': 'os_hub', 'type': 'lead', 'id': str(l.get('id'))})

# Match Domains
matched_domains = 0
for d in os_hub_domains:
    matched = False
    dname = d.get('domain_name')
    for eid, e in golden_map.items():
        if dname in e['emails'] or any(dname in em for em in e['emails']):
            e['domains'].add(dname)
            matched = True
            break
            
    for doc in m_docs:
        desc = doc.get('description', '') or ''
        if dname in desc and doc.get('client', {}).get('id'):
            e = get_or_create({'sourceId': doc['client']['id']})
            e['domains'].add(dname)
            matched = True
            
    if matched: matched_domains += 1

# Payments
matched_payments = 0
for doc in m_docs:
    dtype = doc.get('type')
    payments = doc.get('payment', [])
    if (dtype == 305 or dtype == 320) and payments:
        cid = doc.get('client', {}).get('id')
        if cid:
            e = get_or_create({'sourceId': cid})
            for p in payments:
                e['payments'] += 1
                e['totalRevenue'] += p.get('amount', 0)
                matched_payments += 1

paying_clients = [e for e in golden_map.values() if e['totalRevenue'] > 0]
multi_source = [e for e in golden_map.values() if len(e['sources']) > 1]
with_domains = [e for e in golden_map.values() if len(e['domains']) > 0]

report = f"""
# HUB GOLDEN MAP - MASTER DISCOVERY REPORT
**Date:** 2026-09-19

## 1. SOURCES DISCOVERED
- SQLite: os_hub.db (Tables: clients, leads, domains, projects, quotes, invoices, tasks, campaigns)
- Morning API: Extracted JSONL (clients, documents, expenses, recurring, retainers)

## 2. ENTITY COUNTS
- Total Unified Entities (Golden IDs): {len(golden_map)}
- Total os_hub Clients: {len(os_hub_clients)}
- Total Morning Clients: {len(m_clients)}
- Total Leads: {len(os_hub_leads)}
- Total Domains: {len(os_hub_domains)}
- Total Projects: {len(os_hub_projects)}

## 3. RELATIONSHIP COUNTS
- Entities with multiple sources matched (e.g. Lead -> Client -> Morning): {len(multi_source)}
- Entities with domains linked: {len(with_domains)}

## 4. DOMAIN ↔ CUSTOMER MATCH RATE
- Matched Domains: {matched_domains} / {len(os_hub_domains)}
- *Method:* Text search inside Morning document descriptions for the domain name + email domain matching.

## 5. CUSTOMER ↔ PROJECT MATCH RATE
- SQLite schema lacks explicit foreign key `client_id` on `projects`. Unmatched structurally.

## 6. PROJECT/ASSET ↔ REVENUE MATCH RATE
- Payments Successfully Linked to a Client: {matched_payments} payments.
- Total Unified Entities with Revenue: {len(paying_clients)}

## 7. UNMATCHED
- {len(os_hub_domains) - matched_domains} domains are floating without a clear paying entity.
- {len(os_hub_projects)} projects lack a strict DB foreign key to a client.

## 8. CONFLICTS
- Naming inconsistencies: Morning names vs os_hub names.
- Duplicate entries: Multiple Morning clients sharing the same email/phone but different IDs.

## 9. REUSABLE LOGIC FOUND
- 'os_hub' contains tables for `ssh_checks`, `generic_documents`, `ai_agent_logs`, `ceo_actions` which represent valuable structural ideas for the Sovereign Core.
- The linkage between a Domain and a Revenue stream exists purely in free-text (document descriptions in Morning).

## 10. TOP KNOWLEDGE GAPS
1. **No Hard Link between Tech and Finance:** The POS/Domain system does not store the `Morning Client ID`.
2. **Lead to Client Pipeline:** Leads are in `os_hub` but have no status machine connecting them to a won Morning client.
3. **Project Ownership:** Projects table is isolated.

---
*Generated by Digital CEO Identity Engine.*
"""

with open(os.path.join(OUT_DIR, 'HUB_MASTER_REPORT.md'), 'w', encoding='utf-8') as f:
    f.write(report)

print("Golden Map Generated.")
