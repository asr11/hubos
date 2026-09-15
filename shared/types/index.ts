import { z } from 'zod';

/**
 * Single Source of Truth (SSOT)
 * This file contains the domain models for the HUB POS application.
 * All frontend, backend, and AI agents MUST refer to these definitions.
 */

// Domain: Morning / Clients
export const ClientSchema = z.object({
  id: z.string().optional(),
  name: z.string().min(2, "Name must be at least 2 characters"),
  emails: z.array(z.string().email()).optional(),
  phone: z.string().optional(),
  morningClientId: z.string().optional()
});

export type Client = z.infer<typeof ClientSchema>;

// Domain: Invoices
export const InvoiceSchema = z.object({
  invoiceId: z.string(),
  clientId: z.string(),
  amount: z.number().positive(),
  status: z.enum(['pending', 'paid', 'cancelled']),
  createdAt: z.date().default(() => new Date())
});

export type Invoice = z.infer<typeof InvoiceSchema>;
