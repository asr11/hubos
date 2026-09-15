import type { Campaign, Client, Deal, Invoice, Lead, Project } from '../types'
import { clientsForAgentUid, leadsVisibleToAgent } from './salesAgent'

export type AgentWorkspaceData = {
  uid: string
  myClients: Client[]
  myClientIds: Set<string>
  myLeads: Lead[]
  quotesOpen: Lead[]
  myProjects: Project[]
  myInvoices: Invoice[]
  myDeals: Deal[]
  myCampaigns: Campaign[]
  clientNameById: Map<string, string>
}

/** נתוני פורטל סוכן לפי UID — משמש גם אדמין (מרכז ניהול) וגם `/agent` */
export function buildAgentWorkspaceData(
  uid: string,
  clients: Client[],
  leads: Lead[],
  projects: Project[],
  invoices: Invoice[],
  deals: Deal[],
  campaigns: Campaign[],
): AgentWorkspaceData {
  const trimmedUid = (uid || '').trim()
  const safeClients = Array.isArray(clients) ? clients : []
  const safeLeads = Array.isArray(leads) ? leads : []
  const safeProjects = Array.isArray(projects) ? projects : []
  const safeInvoices = Array.isArray(invoices) ? invoices : []
  const safeDeals = Array.isArray(deals) ? deals : []
  const safeCampaigns = Array.isArray(campaigns) ? campaigns : []

  const myClients = clientsForAgentUid(safeClients, trimmedUid)
  const myClientIds = new Set(myClients.map((c) => c?.id).filter(Boolean))
  const myLeads = leadsVisibleToAgent(safeLeads, myClientIds, trimmedUid)
  const quotesOpen = myLeads.filter((l) => l?.status === 'quote_sent')
  const myProjects = safeProjects.filter((p) => {
    const cid = p?.clientId?.trim()
    return cid && myClientIds.has(cid)
  })
  const myInvoices = safeInvoices.filter((i) => i?.clientId && myClientIds.has(i.clientId))
  const myDeals = safeDeals.filter(
    (d) =>
      (d?.clientId && myClientIds.has(d.clientId)) ||
      (d?.salesAgentUserId ?? '').trim() === trimmedUid,
  )
  const myCampaigns = safeCampaigns.filter((c) => {
    const cid = c?.clientId?.trim()
    return cid && myClientIds.has(cid)
  })
  const clientNameById = new Map<string, string>()
  for (const c of myClients) {
    if (c?.id) clientNameById.set(c.id, c.name || '')
  }

  return {
    uid: trimmedUid,
    myClients,
    myClientIds,
    myLeads,
    quotesOpen,
    myProjects,
    myInvoices,
    myDeals,
    myCampaigns,
    clientNameById,
  }
}
