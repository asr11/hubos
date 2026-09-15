import { loadMorningCredentials } from './src/lib/morningCredentials.js'
import { MorningClient } from './src/lib/morningClient.js'

async function test() {
  const creds = await loadMorningCredentials()
  if (!creds) {
    console.log("No credentials loaded")
    return
  }
  const client = new MorningClient(creds)
  try {
    const res = await client.verifyConnection()
    console.log("Connection Verified:", res)
    
    // test sync clients
    const clients = await client.searchClients({page: 1, pageSize: 5})
    console.log("Clients fetched:", clients.items.length)
  } catch (e) {
    console.error("Error:", e)
  }
}
test()
