import json

with open('clients_export.json') as f:
    clients = json.load(f)
with open('domains_export.json') as f:
    domains = json.load(f)

# The D1 output format usually returns [{'results': [...]}]
if len(clients) > 0 and 'results' in clients[0]:
    clients = clients[0]['results']
if len(domains) > 0 and 'results' in domains[0]:
    domains = domains[0]['results']

with open('/home/asr11/.gemini/antigravity/brain/2ed9022b-aad8-49c3-a8e7-493886839afd/hub_os_catalog.md', 'w') as out:
    out.write("# קטלוג הלקוחות והדומיינים - רשומות Cloudflare D1\n\n")
    out.write(f"**סה\"כ לקוחות:** {len(clients)}\n")
    out.write(f"**סה\"כ דומיינים:** {len(domains)}\n\n")
    
    out.write("## רשימת לקוחות\n")
    out.write("| שם הלקוח | אימייל | טלפון |\n")
    out.write("|---|---|---|\n")
    for i, c in enumerate(clients):
        out.write(f"| {c.get('name', '')} | {c.get('email', '')} | {c.get('phone', '')} |\n")
    
    out.write("\n## רשימת דומיינים\n")
    out.write("| דומיין | רשם | תאריך תפוגה |\n")
    out.write("|---|---|---|\n")
    for i, d in enumerate(domains):
        out.write(f"| {d.get('domain_name', '')} | {d.get('registrar', '')} | {d.get('expiry_date', '')} |\n")
