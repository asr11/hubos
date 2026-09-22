import { runMorningSync } from '../src/lib/morningSync.js';

async function main() {
  console.log('Starting full Morning sync from dawn of history...');
  try {
    const resIncomes = await runMorningSync({ fromDate: '2010-01-01', syncClientsFirst: true });
    console.log('Incomes Sync Result:', resIncomes);
    console.log('Done!');
  } catch (err) {
    console.error('Error during sync:', err);
  }
}

main();
