#!/usr/bin/env python3
import sqlite3
import os
import json

D1_SCHEMAS = {
    'users': ['id', 'email', 'password_hash', 'role', 'created_at'],
    'clients': ['id', 'green_invoice_id', 'name', 'tax_id', 'email', 'phone', 'status', 'created_at'],
    'people': ['id', 'full_name', 'phone', 'created_at', 'updated_at', 'telegram_user_id', 'primary_email', 'role', 'referred_by_partner_id', 'morning_client_id', 'client_id', 'user_id'],
    'domains': ['id', 'domain_name', 'registrar', 'expiry_date', 'is_active', 'status', 'updated_at'],
    'ssh_checks': ['id', 'domain_name', 'host', 'port', 'status', 'last_check', 'error_log'],
    'suppliers': ['id', 'name', 'contact_info', 'created_at'],
    'projects': ['id', 'name', 'client_id', 'status', 'created_at'],
    'leads': ['id', 'name', 'email', 'phone', 'status', 'created_at'],
    'tasks': ['id', 'title', 'description', 'status', 'project_id', 'assignee', 'due_at', 'created_at'],
    'meetings': ['id', 'title', 'date', 'created_at'],
    'campaigns': ['id', 'name', 'status', 'created_at'],
    'quotes': ['id', 'quote_number', 'client_id', 'total_amount', 'status', 'signature_data', 'signed_at', 'created_at', 'updated_at'],
    'invoices': ['id', 'client_id', 'amount', 'status', 'created_at'],
    'generic_documents': ['id', 'collection', 'data', 'updated_at'],
    'system_logs': ['id', 'event_type', 'target', 'details', 'created_at'],
    'ai_agent_logs': ['id', 'agent_id', 'timestamp', 'prompt_tokens', 'completion_tokens', 'execution_time_ms', 'estimated_cost_usd', 'business_value_score', 'context_summary'],
    'ceo_actions': ['action_id', 'action_type', 'status', 'reasoning', 'proposed_payload', 'ledger', 'created_at', 'updated_at']
}

def sql_escape(val):
    if val is None:
        return 'NULL'
    if isinstance(val, (int, float)):
        return str(val)
    # String or bytes
    s = str(val)
    s = s.replace("'", "''")
    return f"'{s}'"

def dump_db_tables(db_path, out_file, seen_keys):
    if not os.path.exists(db_path):
        print(f"Skipping non-existent db: {db_path}")
        return
    
    con = sqlite3.connect(db_path)
    cur = con.cursor()
    print(f"Processing database: {db_path}")
    
    for table_name, target_cols in D1_SCHEMAS.items():
        exists = cur.execute(f"SELECT count(*) FROM sqlite_master WHERE type='table' AND name='{table_name}'").fetchone()[0]
        if not exists:
            continue
        
        # Get source columns
        src_info = cur.execute(f"PRAGMA table_info({table_name})").fetchall()
        src_col_names = [r[1] for r in src_info]
        
        # Column mapping (e.g. system_logs created_at vs timestamp)
        col_mapping = {}
        for tc in target_cols:
            if tc in src_col_names:
                col_mapping[tc] = tc
            elif tc == 'created_at' and 'timestamp' in src_col_names:
                col_mapping[tc] = 'timestamp'
        
        if not col_mapping:
            continue
            
        final_cols = list(col_mapping.keys())
        select_clause = ", ".join([f'"{col_mapping[c]}"' for c in final_cols])
        
        rows = cur.execute(f'SELECT {select_clause} FROM "{table_name}"').fetchall()
        count = 0
        for row in rows:
            # Primary key or unique key check to deduplicate across dbs
            first_val = row[0]
            dedup_key = (table_name, first_val)
            if dedup_key in seen_keys:
                continue
            seen_keys.add(dedup_key)
            
            vals_str = ", ".join([sql_escape(v) for v in row])
            cols_str = ", ".join([f'"{c}"' for c in final_cols])
            stmt = f'INSERT OR IGNORE INTO "{table_name}" ({cols_str}) VALUES ({vals_str});\n'
            out_file.write(stmt)
            count += 1
            
        if count > 0:
            print(f"  {table_name}: wrote {count} statements from {db_path}")
    con.close()

def main():
    repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
    server_dir = os.path.join(repo_root, 'server')
    dump_path = os.path.join(server_dir, 'dump.sql')
    
    seen_keys = set()
    
    with open(dump_path, 'w', encoding='utf-8') as out:
        out.write("-- Cloudflare D1 Data Migration Dump\n")
        out.write("-- Generated automatically\n\n")
        
        # 1. Source database from data/os_hub.db (primary business tables)
        db_root = os.path.join(repo_root, 'data', 'os_hub.db')
        dump_db_tables(db_root, out, seen_keys)
        
        # 2. Source database from server/data/os_hub.db (server generic docs, agent logs)
        db_server = os.path.join(server_dir, 'data', 'os_hub.db')
        dump_db_tables(db_server, out, seen_keys)
        
        # 3. Seed people table with admin user if not present
        admin_person_sql = (
            "INSERT OR IGNORE INTO \"people\" "
            "(\"id\", \"full_name\", \"phone\", \"created_at\", \"role\", \"primary_email\", \"user_id\") "
            "VALUES ('person_0001', 'אשר רייכמן', '0527777817', '2026-10-04 10:00:00', 'admin', 'a0527777817@gmail.com', '00000000-0000-4000-a000-000000000001');\n"
        )
        out.write("\n-- People Table Seed\n")
        out.write(admin_person_sql)
        print("  people: added admin seed record")

    file_size = os.path.getsize(dump_path)
    print(f"\nGenerated {dump_path} successfully. Total size: {file_size / 1024:.2f} KB ({file_size} bytes)")

if __name__ == '__main__':
    main()
