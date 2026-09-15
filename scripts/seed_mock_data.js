const Database = require('better-sqlite3');
const crypto = require('crypto');

const db = new Database('data/os_hub.db');

function insert(collection, data) {
  const id = data.id || crypto.randomUUID();
  data.id = id;
  const stmt = db.prepare('INSERT OR REPLACE INTO generic_documents (id, collection, data) VALUES (?, ?, ?)');
  stmt.run(id, collection, JSON.stringify(data));
}

// 1. Projects
const p1Id = crypto.randomUUID();
const p2Id = crypto.randomUUID();
insert('projects', { id: p1Id, name: 'מיתוג פרימיום - אלפא', description: 'עיצוב זהות חזותית מלאה', status: 'active', color: '#3B82F6', createdAt: new Date().toISOString(), taskCount: 2, doneCount: 1 });
insert('projects', { id: p2Id, name: 'קמפיין סושיאל - בטא', description: 'קמפיין לידים בפייסבוק', status: 'active', color: '#10B981', createdAt: new Date().toISOString(), taskCount: 1, doneCount: 0 });

// 2. Tasks
insert('tasks', { title: 'עיצוב לוגו ראשוני', status: 'in-progress', projectId: p1Id, priority: 'high', createdAt: new Date().toISOString() });
insert('tasks', { title: 'אישור מול הלקוח', status: 'done', projectId: p1Id, priority: 'medium', createdAt: new Date().toISOString() });
insert('tasks', { title: 'כתיבת קופי לקמפיין', status: 'pending', projectId: p2Id, priority: 'high', createdAt: new Date().toISOString() });

// 3. Leads
insert('leads', { name: 'דוד כהן', email: 'david@example.com', phone: '052-1234567', status: 'new', createdAt: new Date().toISOString(), source: 'Website' });
insert('leads', { name: 'שרה לוי', email: 'sara@example.com', phone: '054-7654321', status: 'contacted', createdAt: new Date().toISOString(), source: 'Facebook' });
insert('leads', { name: 'משה אהרון', email: 'moshe@example.com', phone: '050-1112223', status: 'qualified', createdAt: new Date().toISOString(), source: 'LinkedIn' });

// 4. Campaigns
insert('campaigns', { name: 'קמפיין מיתוג 2026', status: 'active', budget: 5000, spent: 1200, createdAt: new Date().toISOString() });
insert('campaigns', { name: 'קמפיין לידים חורף', status: 'active', budget: 10000, spent: 4500, createdAt: new Date().toISOString() });

console.log('Mock data seeded successfully.');
