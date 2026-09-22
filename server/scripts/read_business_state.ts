import { initializeApp, cert } from 'firebase-admin/app';
import { getFirestore } from 'firebase-admin/firestore';
import * as dotenv from 'dotenv';
import * as path from 'path';

dotenv.config({ path: path.resolve(__dirname, '../.env') });

const serviceAccount = JSON.parse(process.env.FIREBASE_SERVICE_ACCOUNT_KEY || '{}');
const app = initializeApp({ credential: cert(serviceAccount) });
const db = getFirestore(app);

async function main() {
  const ts = new Date().toISOString();
  console.log(`[TIMESTAMP] ${ts}\n`);

  const collections = ['users', 'clients', 'projects', 'tasks', 'financeIncomes', 'financeExpenses', 'domainRecords', 'hostingRecords'];
  
  for (const col of collections) {
    console.log(`--- Collection: ${col} ---`);
    try {
      const snap = await db.collection(col).get();
      console.log(`Total records: ${snap.size}`);
      
      let sample = [];
      let activeCount = 0;
      snap.forEach(doc => {
        const data = doc.data();
        if (col === 'users') {
          if (data.role || data.active) activeCount++;
          sample.push({ id: doc.id, name: data.displayName || data.name, role: data.role, email: data.email });
        } else if (col === 'clients') {
          if (data.status === 'active') activeCount++;
          sample.push({ id: doc.id, name: data.name, status: data.status, email: data.email });
        } else if (col === 'projects') {
          if (data.status === 'active' || data.status === 'in_progress') activeCount++;
          sample.push({ id: doc.id, name: data.name, status: data.status, clientId: data.clientId });
        } else if (col === 'tasks') {
          if (data.status !== 'done' && data.status !== 'completed') activeCount++;
        } else if (col === 'financeIncomes') {
          sample.push({ id: doc.id, clientName: data.clientName, amount: data.amount, date: data.date });
        } else if (col === 'financeExpenses') {
          sample.push({ id: doc.id, amount: data.amount, date: data.date, category: data.category });
        } else if (col === 'domainRecords' || col === 'hostingRecords') {
          sample.push({ id: doc.id, domain: data.domainName || data.name, status: data.status });
        }
      });
      
      if (['users', 'clients', 'projects'].includes(col)) {
        console.log(`Active: ${activeCount}`);
      }
      if (col === 'tasks') {
        console.log(`Open tasks: ${activeCount}`);
      }
      
      if (sample.length > 0) {
        console.log('Sample (up to 5):', JSON.stringify(sample.slice(0, 5), null, 2));
      }
    } catch (e) {
      console.error(`Failed to read ${col}: ${e.message}`);
    }
    console.log('\n');
  }
}

main().catch(console.error).finally(() => process.exit(0));
