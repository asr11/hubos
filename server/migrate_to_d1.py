#!/usr/bin/env python3
import sqlite3
import os
import subprocess
import glob
import sys

def sql_escape(val):
    if val is None:
        return 'NULL'
    if isinstance(val, (int, float)):
        return str(val)
    s = str(val)
    s = s.replace("'", "''")
    return f"'{s}'"

def format_insert(table, cols, row):
    cols_str = ", ".join([f'"{c}"' for c in cols])
    vals_str = ", ".join([sql_escape(v) for v in row])
    return f'INSERT OR IGNORE INTO "{table}" ({cols_str}) VALUES ({vals_str});\n'

def main():
    repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
    server_dir = os.path.join(repo_root, 'server')
    batch_dir = os.path.join(server_dir, 'd1_batches')
    os.makedirs(batch_dir, exist_ok=True)
    
    # Clean previous batches
    for f in glob.glob(os.path.join(batch_dir, '*.sql')):
        os.remove(f)

    db_root = os.path.join(repo_root, 'data', 'os_hub.db')
    db_server = os.path.join(server_dir, 'data', 'os_hub.db')

    src1 = sqlite3.connect(db_root)
    src2 = sqlite3.connect(db_server)

    all_statements = []

    # 1. users
    users = src1.execute('SELECT id, email, password_hash, role, created_at FROM users').fetchall()
    cols = ['id', 'email', 'password_hash', 'role', 'created_at']
    for r in users:
        all_statements.append(format_insert('users', cols, r))

    # 2. clients
    clients = src1.execute('SELECT id, green_invoice_id, name, tax_id, email, phone, status, created_at FROM clients').fetchall()
    cols = ['id', 'green_invoice_id', 'name', 'tax_id', 'email', 'phone', 'status', 'created_at']
    for r in clients:
        all_statements.append(format_insert('clients', cols, r))
    valid_clients = set([r[0] for r in clients])

    # 3. people (seed admin)
    cols = ['id', 'full_name', 'phone', 'created_at', 'role', 'primary_email', 'user_id']
    admin_row = ('person_0001', 'אשר רייכמן', '0527777817', '2026-10-04 10:00:00', 'admin', 'a0527777817@gmail.com', '00000000-0000-4000-a000-000000000001')
    all_statements.append(format_insert('people', cols, admin_row))

    # 4. domains
    domains = src1.execute('SELECT id, domain_name, registrar, expiry_date, is_active, status, updated_at FROM domains').fetchall()
    cols = ['id', 'domain_name', 'registrar', 'expiry_date', 'is_active', 'status', 'updated_at']
    for r in domains:
        all_statements.append(format_insert('domains', cols, r))

    # 5. suppliers
    suppliers = src1.execute('SELECT id, name, contact_info, created_at FROM suppliers').fetchall()
    cols = ['id', 'name', 'contact_info', 'created_at']
    for r in suppliers:
        all_statements.append(format_insert('suppliers', cols, r))

    # 6. projects
    raw_projects = src1.execute('SELECT id, name, client_id, status, created_at FROM projects').fetchall()
    cols = ['id', 'name', 'client_id', 'status', 'created_at']
    valid_projects = set()
    for p in raw_projects:
        cid = p[2] if (p[2] and p[2] in valid_clients) else None
        valid_projects.add(p[0])
        all_statements.append(format_insert('projects', cols, (p[0], p[1], cid, p[3], p[4])))

    # 7. leads
    leads = src1.execute('SELECT id, name, email, phone, status, created_at FROM leads').fetchall()
    cols = ['id', 'name', 'email', 'phone', 'status', 'created_at']
    for r in leads:
        all_statements.append(format_insert('leads', cols, r))

    # 8. tasks
    raw_tasks = src1.execute('SELECT id, title, description, status, project_id, assignee, due_at, created_at FROM tasks').fetchall()
    cols = ['id', 'title', 'description', 'status', 'project_id', 'assignee', 'due_at', 'created_at']
    for t in raw_tasks:
        pid = t[4] if (t[4] and t[4] in valid_projects) else None
        all_statements.append(format_insert('tasks', cols, (t[0], t[1], t[2], t[3], pid, t[5], t[6], t[7])))

    # 9. quotes
    raw_quotes = src1.execute('SELECT id, quote_number, client_id, total_amount, status, signature_data, signed_at, created_at, updated_at FROM quotes').fetchall()
    cols = ['id', 'quote_number', 'client_id', 'total_amount', 'status', 'signature_data', 'signed_at', 'created_at', 'updated_at']
    for q in raw_quotes:
        cid = q[2] if (q[2] and q[2] in valid_clients) else None
        all_statements.append(format_insert('quotes', cols, (q[0], q[1], cid, q[3], q[4], q[5], q[6], q[7], q[8])))

    # 10. invoices
    raw_invoices = src1.execute('SELECT id, client_id, amount, status, created_at FROM invoices').fetchall()
    cols = ['id', 'client_id', 'amount', 'status', 'created_at']
    for inv in raw_invoices:
        cid = inv[1] if (inv[1] and inv[1] in valid_clients) else None
        all_statements.append(format_insert('invoices', cols, (inv[0], cid, inv[2], inv[3], inv[4])))

    # 11. generic_documents (merge both sources, deduplicate by id)
    docs1 = src1.execute('SELECT id, collection, data, updated_at FROM generic_documents').fetchall()
    docs2 = src2.execute('SELECT id, collection, data, updated_at FROM generic_documents').fetchall()
    seen_docs = set()
    cols = ['id', 'collection', 'data', 'updated_at']
    for r in docs1:
        if r[0] not in seen_docs:
            seen_docs.add(r[0])
            all_statements.append(format_insert('generic_documents', cols, r))
    for r in docs2:
        if r[0] not in seen_docs:
            seen_docs.add(r[0])
            all_statements.append(format_insert('generic_documents', cols, r))

    # 12. system_logs (merge both sources)
    logs1 = src1.execute('SELECT id, event_type, target, details, created_at FROM system_logs').fetchall()
    logs2 = src2.execute('SELECT id, event_type, target, details, timestamp FROM system_logs').fetchall()
    cols = ['id', 'event_type', 'target', 'details', 'created_at']
    seen_logs = set()
    for r in logs1:
        if r[0] not in seen_logs:
            seen_logs.add(r[0])
            all_statements.append(format_insert('system_logs', cols, r))
    for r in logs2:
        if r[0] not in seen_logs:
            seen_logs.add(r[0])
            all_statements.append(format_insert('system_logs', cols, r))

    # 13. ceo_actions
    ceo1 = src1.execute('SELECT action_id, action_type, status, reasoning, proposed_payload, ledger, created_at, updated_at FROM ceo_actions').fetchall()
    cols = ['action_id', 'action_type', 'status', 'reasoning', 'proposed_payload', 'ledger', 'created_at', 'updated_at']
    for r in ceo1:
        all_statements.append(format_insert('ceo_actions', cols, r))

    # 14. ai_agent_logs
    ai2 = src2.execute('SELECT id, agent_id, timestamp, prompt_tokens, completion_tokens, execution_time_ms, estimated_cost_usd, business_value_score, context_summary FROM ai_agent_logs').fetchall()
    cols = ['id', 'agent_id', 'timestamp', 'prompt_tokens', 'completion_tokens', 'execution_time_ms', 'estimated_cost_usd', 'business_value_score', 'context_summary']
    for r in ai2:
        all_statements.append(format_insert('ai_agent_logs', cols, r))

    src1.close()
    src2.close()

    print(f"Total statements compiled: {len(all_statements)}")

    # Write master dump.sql
    dump_path = os.path.join(server_dir, 'dump.sql')
    with open(dump_path, 'w', encoding='utf-8') as f:
        f.write("-- Complete Clean D1 Migration Dump\n\n")
        f.writelines(all_statements)
    print(f"Wrote master {dump_path} ({os.path.getsize(dump_path) / 1024:.2f} KB)")

    # Partition into batches of 200 statements
    BATCH_SIZE = 200
    batch_files = []
    for i in range(0, len(all_statements), BATCH_SIZE):
        batch_idx = (i // BATCH_SIZE) + 1
        batch_filename = f"batch_{batch_idx:03d}.sql"
        batch_path = os.path.join(batch_dir, batch_filename)
        with open(batch_path, 'w', encoding='utf-8') as f:
            f.writelines(all_statements[i:i + BATCH_SIZE])
        batch_files.append(batch_path)

    print(f"Created {len(batch_files)} batch files in {batch_dir}")

    # Execute each batch against Cloudflare D1
    env = os.environ.copy()
    env['CLOUDFLARE_ACCOUNT_ID'] = '4fdbb9a5ab5ca7023d857c6762c99f6d'

    for idx, bpath in enumerate(batch_files, 1):
        rel_path = os.path.relpath(bpath, server_dir)
        print(f"[{idx}/{len(batch_files)}] Executing {rel_path} on D1 remote...")
        cmd = [
            'npx', 'wrangler', 'd1', 'execute', 'hub-os-d1',
            '--file', rel_path,
            '--remote', '-y'
        ]
        res = subprocess.run(cmd, cwd=server_dir, env=env, capture_output=True, text=True)
        if res.returncode != 0:
            print(f"ERROR executing {rel_path}:")
            print(res.stderr or res.stdout)
            sys.exit(1)
        else:
            print(f"  Batch {idx} applied successfully.")

    print("\nAll batches successfully migrated to Cloudflare D1!")

if __name__ == '__main__':
    main()
