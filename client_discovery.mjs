import { initializeApp } from 'firebase/app';
import { getFirestore, collection, getCountFromServer } from 'firebase/firestore';

const firebaseConfig = {
  apiKey: 'AIzaSyAjMt8jq1b25AjXV6oRsK1uzlRpkjv-ADk',
  authDomain: 'hub-pos-f399c.firebaseapp.com',
  projectId: 'hub-pos-f399c',
  storageBucket: 'hub-pos-f399c.firebasestorage.app',
  messagingSenderId: '961483294937',
  appId: '1:961483294937:web:765583ea55dc05cfa416c8'
};

const app = initializeApp(firebaseConfig);
const db = getFirestore(app);

const collections = [
  'clients', 'leads', 'deals', 'projects', 'tasks', 
  'campaigns', 'suppliers', 'invoices', 'financeIncomes', 
  'financeExpenses', 'meetings', 'hostingRecords', 
  'recurringTaskTemplates', 'users', 'passwords_vault'
];

async function run() {
  for (const c of collections) {
    try {
      const snapshot = await getCountFromServer(collection(db, c));
      console.log(`${c} count: ${snapshot.data().count}`);
    } catch (e) {
      console.error(`${c} count: FAILED - ${e.message}`);
    }
  }
}
run();
