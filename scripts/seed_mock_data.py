import sqlite3
import json
import uuid
import datetime

conn = sqlite3.connect('server/data/os_hub.db')
cursor = conn.cursor()

def insert(collection, data):
    doc_id = data.get('id', str(uuid.uuid4()))
    data['id'] = doc_id
    cursor.execute(
        'INSERT OR REPLACE INTO generic_documents (id, collection, data) VALUES (?, ?, ?)',
        (doc_id, collection, json.dumps(data))
    )

p1_id = str(uuid.uuid4())
p2_id = str(uuid.uuid4())

now = datetime.datetime.now().isoformat() + "Z"

insert('projects', {'id': p1_id, 'name': 'מיתוג פרימיום - אלפא', 'description': 'עיצוב זהות חזותית מלאה', 'status': 'active', 'color': '#3B82F6', 'createdAt': now, 'taskCount': 2, 'doneCount': 1})
insert('projects', {'id': p2_id, 'name': 'קמפיין סושיאל - בטא', 'description': 'קמפיין לידים בפייסבוק', 'status': 'active', 'color': '#10B981', 'createdAt': now, 'taskCount': 1, 'doneCount': 0})

insert('tasks', {'title': 'עיצוב לוגו ראשוני', 'status': 'in-progress', 'projectId': p1_id, 'priority': 'high', 'createdAt': now})
insert('tasks', {'title': 'אישור מול הלקוח', 'status': 'done', 'projectId': p1_id, 'priority': 'medium', 'createdAt': now})
insert('tasks', {'title': 'כתיבת קופי לקמפיין', 'status': 'pending', 'projectId': p2_id, 'priority': 'high', 'createdAt': now})

insert('leads', {'name': 'דוד כהן', 'email': 'david@example.com', 'phone': '052-1234567', 'status': 'new', 'createdAt': now, 'source': 'Website'})
insert('leads', {'name': 'שרה לוי', 'email': 'sara@example.com', 'phone': '054-7654321', 'status': 'contacted', 'createdAt': now, 'source': 'Facebook'})
insert('leads', {'name': 'משה אהרון', 'email': 'moshe@example.com', 'phone': '050-1112223', 'status': 'qualified', 'createdAt': now, 'source': 'LinkedIn'})

insert('campaigns', {'name': 'קמפיין מיתוג 2026', 'status': 'active', 'budget': 5000, 'spent': 1200, 'createdAt': now})
insert('campaigns', {'name': 'קמפיין לידים חורף', 'status': 'active', 'budget': 10000, 'spent': 4500, 'createdAt': now})

conn.commit()
conn.close()

print("Mock data seeded successfully.")
