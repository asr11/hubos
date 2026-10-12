import './src/config/loadEnv.js';
import { runMorningSync } from './src/lib/morningSync.js';

async function main() {
  console.log('Starting full Morning sync from dawn of history (2010-01-01)...');
  try {
    const res = await runMorningSync({ fromDate: '2010-01-01', syncClientsFirst: true });
    console.log('Morning Sync Result:', JSON.stringify(res, null, 2));
    console.log('Done!');
  } catch (err) {
    console.error('Error during sync:', err);
    process.exit(1);
  }
}

main();
