import sqlite3
db = sqlite3.connect("/home/asr11/02_HUB_Dev/os.hub.co.il/data/os_hub.db")
db.row_factory = sqlite3.Row
c = db.cursor()

clients = {row["id"]: dict(row) for row in c.execute("SELECT id, name FROM clients")}
counts = {cid: {"projects":0, "invoices":0} for cid in clients}

for row in c.execute("SELECT client_id, COUNT(*) as c FROM projects WHERE client_id IS NOT NULL GROUP BY client_id"):
    if row["client_id"] in counts: counts[row["client_id"]]["projects"] = row["c"]

for row in c.execute("SELECT client_id, COUNT(*) as c FROM invoices WHERE client_id IS NOT NULL GROUP BY client_id"):
    if row["client_id"] in counts: counts[row["client_id"]]["invoices"] = row["c"]

best_client_id = None
max_score = -1
for cid, stats in counts.items():
    score = sum(stats.values())
    if score > max_score:
        max_score = score
        best_client_id = cid

if best_client_id:
    print("BEST CLIENT:", clients[best_client_id]["name"], "ID:", best_client_id)
    print(counts[best_client_id])
else:
    print("No clients found with relations.")
