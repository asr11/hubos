import { hubFetch } from '../lib/hubFetch'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useAuth } from '../contexts/AuthContext'
import { applyClientFinanceBalanceDelta } from '../lib/financeClientBalance'
import {
  buildIncomePaymentRecord,
  incomeBalanceDelta,
  incomePaymentRecords,
  incomeTotalPaid,
  normalizeIncomePayments,
  resolveIncomeStatusFromPaidTotal,
  syncTaxReceiptPaymentDate,
  type IncomePaymentInput,
} from '../lib/financeIncomePayment'
import { isFinanceHostingType } from '../lib/financeHostingTypes'
import { incomeDocumentKind, incomeUsesPaymentAnchor } from '../lib/financeIncomeDocument'
import { prorateVatBreakdown, resolveIncomeVat } from '../lib/israeliVat'
import { todayYmd } from '../lib/dates'
import { withDisplayOverdueIncomes, formatNis } from '../lib/financeCalc'
import { stripUndefined } from '../lib/stripUndefined'
import { toastFsError, toastFsSuccess } from '../lib/fsToast'
import type {
  FinanceExpectedCollection,
  FinanceIncome,
  FinanceIncomeDocumentKind,
  FinanceIncomeStatus,
  FinanceHostingType,
  FinanceIncomePaymentRecord,
  FinancePaymentMethod,
} from '../types'

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

function applyPaymentFields(
  row: Record<string, unknown>,
  data: {
    status: FinanceIncomeStatus
    paidAmount?: number
    paymentMethod?: FinancePaymentMethod
    paidDate?: string
  },
  clearing = false,
) {
  if (data.status === 'paid' || data.status === 'partial') {
    if (typeof data.paidAmount === 'number' && Number.isFinite(data.paidAmount)) {
      row.paidAmount = round2(data.paidAmount)
    }
    if (data.paymentMethod) row.paymentMethod = data.paymentMethod
    if (data.paidDate?.trim()) row.paidDate = data.paidDate.trim().slice(0, 10)
    return
  }
  if (clearing) {
    row.paidAmount = null
    row.paymentMethod = null
    row.paidDate = null
    row.payments = null
  }
}

async function syncClientBalance(
  oldInc: FinanceIncome | null,
  nextInc: FinanceIncome,
) {
  const oldClientId = oldInc?.clientId?.trim()
  const newClientId = nextInc.clientId.trim()
  const oldDelta = oldInc ? incomeBalanceDelta(oldInc) : 0
  const newDelta = incomeBalanceDelta(nextInc)

  if (oldClientId && oldClientId !== newClientId) {
    await applyClientFinanceBalanceDelta(oldClientId, -oldDelta)
    await applyClientFinanceBalanceDelta(newClientId, newDelta)
    return
  }
  if (newClientId) {
    await applyClientFinanceBalanceDelta(newClientId, newDelta - oldDelta)
  }
}

function paymentRowsFromInput(data: {
  status: FinanceIncomeStatus
  paidAmount?: number
  paymentMethod?: FinancePaymentMethod
  paidDate?: string
  invoiceDate: string
}): FinanceIncomePaymentRecord[] {
  if (
    (data.status !== 'paid' && data.status !== 'partial') ||
    typeof data.paidAmount !== 'number' ||
    data.paidAmount <= 0
  ) {
    return []
  }
  return [
    buildIncomePaymentRecord({
      amount: data.paidAmount,
      paymentMethod: data.paymentMethod ?? 'bank_transfer',
      paidDate: data.paidDate ?? data.invoiceDate,
    }),
  ]
}

async function persistIncomePayments(
  oldInc: FinanceIncome,
  records: FinanceIncomePaymentRecord[],
) {
  const totalPaid = round2(records.reduce((s, p) => s + p.amount, 0))
  if (totalPaid > oldInc.amount + 0.001) {
    throw new Error('total payments exceed invoice amount')
  }
  const newStatus = resolveIncomeStatusFromPaidTotal(oldInc.amount, totalPaid)
  const last = records[records.length - 1]
  const patch: Record<string, unknown> = { status: newStatus }

  if (records.length === 0) {
    patch.payments = null
    patch.paidAmount = null
    patch.paymentMethod = null
    patch.paidDate = null
  } else {
    patch.payments = records
    patch.paidAmount = totalPaid
    patch.paymentMethod = last?.paymentMethod
    patch.paidDate = last?.paidDate
  }

  if (
    incomeDocumentKind(oldInc) === 'tax_invoice_receipt' ||
    incomeDocumentKind(oldInc) === 'receipt'
  ) {
    const docDate = oldInc.invoiceDate.trim().slice(0, 10)
    if (docDate) {
      patch.invoiceDate = docDate
      patch.paidDate = docDate
      if (records.length > 0) {
        patch.payments = records.map((p) => ({ ...p, paidDate: docDate }))
      }
    }
  }
  await hubFetch(`/api/financeIncomes/${oldInc.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(stripUndefined(patch)),
      })
      
  const nextInc: FinanceIncome = {
    ...oldInc,
    payments: records.length > 0 ? records : undefined,
    paidAmount: records.length > 0 ? totalPaid : undefined,
    paymentMethod: last?.paymentMethod,
    paidDate: records.length > 0 ? last?.paidDate : undefined,
    status: newStatus,
  }
  await syncClientBalance(oldInc, nextInc)
  return nextInc
}

export type NewFinanceIncomeInput = {
  projectId?: string
  clientId: string
  amount: number
  amountIncludesVat?: boolean
  vatAmount?: number
  amountBeforeVat?: number
  documentKind?: FinanceIncomeDocumentKind
  invoiceNumber: string
  invoiceDate: string
  description?: string
  status: FinanceIncomeStatus
  monthlyRetainer?: boolean
  hostingClient?: boolean
  hostingType?: FinanceHostingType
  hourlyServiceId?: string
  paidAmount?: number
  paymentMethod?: FinancePaymentMethod
  paidDate?: string
}

export type FinanceIncomePatchInput = {
  projectId?: string
  clientId?: string
  amount?: number
  amountIncludesVat?: boolean
  vatAmount?: number | null
  amountBeforeVat?: number | null
  documentKind?: FinanceIncomeDocumentKind
  invoiceNumber?: string
  invoiceDate?: string
  description?: string | null
  status?: FinanceIncomeStatus
  monthlyRetainer?: boolean
  hostingClient?: boolean
  hostingType?: FinanceHostingType | null
  /** מזהה בנק שעות · `null` / `''` לניקוי */
  hourlyServiceId?: string | null
  paidAmount?: number | null
  paymentMethod?: FinancePaymentMethod | null
  paidDate?: string | null
  /** YYYY-MM-DD · null מוחק */
  expectedPaymentDate?: string | null
  /** לוח תשלומים צפוי · null מוחק */
  expectedPaymentSchedule?: FinanceExpectedCollection[] | null
  payments?: FinanceIncomePaymentRecord[] | null
  sourceTransactionInvoiceId?: string | null
}

export type ConvertToTaxReceiptInput = {
  /** מספר חשבונית מס קבלה — חובה בהמרת תשלום חלקי */
  taxReceiptNumber?: string
  /** תאריך מס קבלה (= תאריך תשלום) — חובה, מוזן ידנית */
  taxReceiptDate: string
}

/** האם יש תשלום חלקי שיש להפריד למס קבלה נפרדת */
export function incomeNeedsPartialConversionSplit(inc: FinanceIncome): boolean {
  const paid = incomeTotalPaid(inc)
  return paid > 0 && paid < inc.amount - 0.001
}

export type AddIncomePaymentInput = IncomePaymentInput

export type UpdateIncomePaymentInput = IncomePaymentInput

type FinanceIncomesContextValue = {
  incomes: FinanceIncome[]
  loading: boolean
  error: Error | null
  addIncome: (data: NewFinanceIncomeInput) => Promise<void>
  updateIncome: (id: string, patch: FinanceIncomePatchInput, opts?: { silent?: boolean }) => Promise<void>
  convertToTaxReceipt: (
    id: string,
    options?: ConvertToTaxReceiptInput,
  ) => Promise<void>
  addIncomePayment: (id: string, payment: AddIncomePaymentInput) => Promise<void>
  updateIncomePayment: (
    id: string,
    paymentId: string,
    payment: UpdateIncomePaymentInput,
  ) => Promise<void>
  deleteIncomePayment: (id: string, paymentId: string) => Promise<void>
  deleteIncome: (id: string) => Promise<void>
}

const FinanceIncomesContext = createContext<FinanceIncomesContextValue | null>(
  null,
)

export function FinanceIncomesProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [incomes, setIncomes] = useState<FinanceIncome[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)

  useEffect(() => {
    if (!user) {
      setIncomes([])
      setLoading(false)
      setError(null)
      return
    }
    setLoading(true)
    let active = true
    hubFetch('/api/financeIncomes')
      .then((res) => res.json())
      .then((data) => {
        if (!active) return
        const today = todayYmd()
        const mapped = Array.isArray(data) ? data : []
        setIncomes(withDisplayOverdueIncomes(mapped, today))
        setLoading(false)
        setError(null)
      })
      .catch((err) => {
        if (!active) return
        console.error('financeIncomes fetch', err)
        toastFsError('שגיאה בטעינת הכנסות', err, 'financeIncomes fetch')
        setError(err instanceof Error ? err : new Error(String(err)))
        setLoading(false)
      })
    return () => { active = false }
  }, [user])

  const addIncome = useCallback(async (data: NewFinanceIncomeInput) => {
    try {
      const row: Record<string, unknown> = {
        clientId: data.clientId.trim(),
        amount: Math.round(data.amount * 100) / 100,
        amountIncludesVat: data.amountIncludesVat !== false,
        documentKind: data.documentKind ?? 'transaction_invoice',
        invoiceNumber: data.invoiceNumber.trim(),
        invoiceDate: data.invoiceDate.slice(0, 10),
        status: data.status,
        createdAt: new Date().toISOString(),
      }
      if (data.description?.trim()) row.description = data.description.trim()
        if (data.hostingClient === true || data.hostingType) {
          row.hostingClient = true
          if (isFinanceHostingType(data.hostingType)) {
            row.hostingType = data.hostingType
          }
        } else if (data.projectId?.trim()) {
        row.projectId = data.projectId.trim()
      }
      if (data.monthlyRetainer === true) row.monthlyRetainer = true
      if (typeof data.vatAmount === 'number' && Number.isFinite(data.vatAmount)) {
        row.vatAmount = Math.round(data.vatAmount * 100) / 100
      }
      if (
        typeof data.amountBeforeVat === 'number' &&
        Number.isFinite(data.amountBeforeVat)
      ) {
        row.amountBeforeVat = Math.round(data.amountBeforeVat * 100) / 100
      }
      applyPaymentFields(row, data)
      const docDate = data.invoiceDate.slice(0, 10)
      if (incomeUsesPaymentAnchor({ documentKind: data.documentKind })) {
        row.invoiceDate = docDate
        row.paidDate = docDate
      }
      const payments = paymentRowsFromInput({
        status: data.status,
        paidAmount: data.paidAmount,
        paymentMethod: data.paymentMethod,
        paidDate: incomeUsesPaymentAnchor({ documentKind: data.documentKind })
          ? docDate
          : data.paidDate,
        invoiceDate: docDate,
      })
      if (payments.length) row.payments = payments
      const res_add = await hubFetch('/api/financeIncomes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(row),
      })
      if (!res_add.ok) throw new Error('API Error')
      await syncClientBalance(null, {
        id: '',
        projectId: data.projectId?.trim() ?? '',
        clientId: data.clientId.trim(),
        amount: row.amount as number,
        invoiceNumber: data.invoiceNumber.trim(),
        invoiceDate: data.invoiceDate.slice(0, 10),
        status: data.status,
        paidAmount: data.paidAmount,
        payments: payments.length ? payments : undefined,
        paymentMethod: data.paymentMethod,
        paidDate: data.paidDate,
        createdAt: '',
      })
      toastFsSuccess('ההכנסה נוספה')
    } catch (e) {
      toastFsError('שגיאה בשמירת ההכנסה', e, 'addIncome')
    }
  }, [])

  const updateIncome = useCallback(
    async (id: string, patch: FinanceIncomePatchInput, opts?: { silent?: boolean }) => {
      try {
        const oldRes = await hubFetch(`/api/financeIncomes/${id}`)
        const oldInc = oldRes.ok ? await oldRes.json() : null

        const next: Record<string, unknown> = {}
        if (typeof patch.hostingClient === 'boolean') {
          next.hostingClient = patch.hostingClient
          if (patch.hostingClient) {
            next.projectId = null
            next.hourlyServiceId = null
          } else {
            next.hostingType = null
          }
        }
        if (patch.hostingType === null) {
          next.hostingType = null
        } else if (isFinanceHostingType(patch.hostingType)) {
          next.hostingType = patch.hostingType
          next.hostingClient = true
          next.projectId = null
          next.hourlyServiceId = null
        }
        if (patch.hourlyServiceId === null || patch.hourlyServiceId === '') {
          next.hourlyServiceId = null
        } else if (
          typeof patch.hourlyServiceId === 'string' &&
          patch.hourlyServiceId.trim()
        ) {
          next.hourlyServiceId = patch.hourlyServiceId.trim()
          next.hostingClient = false
          next.hostingType = null
          next.projectId = null
        }
        if (
          typeof patch.projectId === 'string' &&
          patch.projectId.trim() &&
          patch.hostingClient !== true &&
          !patch.hourlyServiceId
        ) {
          next.projectId = patch.projectId.trim()
          next.hostingClient = false
          next.hourlyServiceId = null
        } else if (
          patch.projectId === '' &&
          patch.hostingClient !== true &&
          !patch.hourlyServiceId
        ) {
          // ביטול שיוך לפרויקט
          next.projectId = ''
        }
        if (typeof patch.clientId === 'string' && patch.clientId.trim()) {
          next.clientId = patch.clientId.trim()
        }
        if (
          typeof patch.amount === 'number' &&
          Number.isFinite(patch.amount) &&
          patch.amount >= 0
        ) {
          next.amount = Math.round(patch.amount * 100) / 100
        }
        if (typeof patch.amountIncludesVat === 'boolean') {
          next.amountIncludesVat = patch.amountIncludesVat
        }
        if (patch.vatAmount === null) {
          next.vatAmount = null
        } else if (
          typeof patch.vatAmount === 'number' &&
          Number.isFinite(patch.vatAmount)
        ) {
          next.vatAmount = Math.round(patch.vatAmount * 100) / 100
        }
        if (patch.amountBeforeVat === null) {
          next.amountBeforeVat = null
        } else if (
          typeof patch.amountBeforeVat === 'number' &&
          Number.isFinite(patch.amountBeforeVat)
        ) {
          next.amountBeforeVat = Math.round(patch.amountBeforeVat * 100) / 100
        }
        if (
          typeof patch.invoiceNumber === 'string' &&
          patch.invoiceNumber.trim()
        ) {
          next.invoiceNumber = patch.invoiceNumber.trim()
        }
        if (patch.documentKind != null) {
          next.documentKind = patch.documentKind
        }
        if (patch.sourceTransactionInvoiceId === null) {
          next.sourceTransactionInvoiceId = null
        } else if (
          typeof patch.sourceTransactionInvoiceId === 'string' &&
          patch.sourceTransactionInvoiceId.trim()
        ) {
          next.sourceTransactionInvoiceId =
            patch.sourceTransactionInvoiceId.trim()
        }
        if (typeof patch.invoiceDate === 'string' && patch.invoiceDate.trim()) {
          next.invoiceDate = patch.invoiceDate.trim().slice(0, 10)
        }
        if (patch.description === null) {
          next.description = null
        } else if (typeof patch.description === 'string') {
          const t = patch.description.trim()
          next.description = t ? t : null
        }
        if (patch.status != null) next.status = patch.status
        if (typeof patch.monthlyRetainer === 'boolean') {
          next.monthlyRetainer = patch.monthlyRetainer
        }

        const mergedStatus =
          (patch.status ?? oldInc?.status ?? 'pending') as FinanceIncomeStatus
        const mergedAmount =
          typeof patch.amount === 'number' ? patch.amount : (oldInc?.amount ?? 0)
        const mergedPaidAmount =
          patch.paidAmount === null
            ? undefined
            : typeof patch.paidAmount === 'number'
              ? patch.paidAmount
              : oldInc?.paidAmount
        const mergedPaymentMethod =
          patch.paymentMethod === null
            ? undefined
            : patch.paymentMethod ?? oldInc?.paymentMethod
        const mergedPaidDate =
          patch.paidDate === null
            ? undefined
            : patch.paidDate ?? oldInc?.paidDate

        if (patch.status != null) {
          applyPaymentFields(
            next,
            {
              status: mergedStatus,
              paidAmount: mergedPaidAmount,
              paymentMethod: mergedPaymentMethod,
              paidDate: mergedPaidDate,
            },
            true,
          )
        } else if (
          patch.paidAmount != null ||
          patch.paymentMethod != null ||
          patch.paidDate != null
        ) {
          applyPaymentFields(next, {
            status: mergedStatus,
            paidAmount: mergedPaidAmount,
            paymentMethod: mergedPaymentMethod,
            paidDate: mergedPaidDate,
          })
        }

        if (patch.payments === null) {
          next.payments = null
        } else         if (Array.isArray(patch.payments)) {
          next.payments = patch.payments
        }

        if (patch.expectedPaymentDate === null) {
          next.expectedPaymentDate = null
        } else if (
          typeof patch.expectedPaymentDate === 'string' &&
          patch.expectedPaymentDate.trim()
        ) {
          next.expectedPaymentDate = patch.expectedPaymentDate.trim().slice(0, 10)
        }

        if (patch.expectedPaymentSchedule === null) {
          next.expectedPaymentSchedule = null
        } else if (Array.isArray(patch.expectedPaymentSchedule)) {
          next.expectedPaymentSchedule = patch.expectedPaymentSchedule
        }

        const effectiveKind =
          (patch.documentKind ??
            (oldInc ? incomeDocumentKind(oldInc) : undefined)) ??
          'transaction_account'
        if (
          (effectiveKind === 'tax_invoice_receipt' ||
            effectiveKind === 'receipt') &&
          (patch.paidDate != null ||
            patch.invoiceDate != null ||
            Array.isArray(patch.payments))
        ) {
          const payD = (
            (typeof patch.invoiceDate === 'string' ? patch.invoiceDate : null) ??
            (typeof patch.paidDate === 'string' ? patch.paidDate : null) ??
            oldInc?.invoiceDate ??
            mergedPaidDate ??
            oldInc?.paidDate ??
            ''
          ).slice(0, 10)
          if (payD) {
            const mergedPayments =
              patch.payments === null
                ? undefined
                : Array.isArray(patch.payments)
                  ? patch.payments
                  : oldInc?.payments
            const synced = syncTaxReceiptPaymentDate(payD, mergedPayments)
            next.invoiceDate = synced.invoiceDate
            next.paidDate = synced.paidDate
            if (synced.payments) next.payments = synced.payments
          }
        }

        if (Object.keys(next).length === 0) return
        await hubFetch(`/api/financeIncomes/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(stripUndefined(next)),
      })
      

        const mergedPayments =
          patch.payments === null
            ? undefined
            : Array.isArray(patch.payments)
              ? patch.payments
              : oldInc?.payments
        const mergedNext: FinanceIncome = {
          ...(oldInc ?? {
            id,
            projectId: '',
            clientId: '',
            amount: 0,
            invoiceNumber: '',
            invoiceDate: '',
            createdAt: '',
          }),
          clientId: String(next.clientId ?? oldInc?.clientId ?? ''),
          amount: mergedAmount,
          status: mergedStatus,
          paidAmount: mergedPaidAmount,
          paymentMethod: mergedPaymentMethod,
          paidDate: mergedPaidDate,
          payments: mergedPayments,
        }
        await syncClientBalance(oldInc, mergedNext)
        if (!opts?.silent) toastFsSuccess('ההכנסה עודכנה')
      } catch (e) {
        if (!opts?.silent) {
          toastFsError('שגיאה בעדכון ההכנסה', e, 'updateIncome')
        }
      }
    },
    [],
  )

  const convertToTaxReceipt = useCallback(
    async (id: string, options?: ConvertToTaxReceiptInput) => {
      try {
        const oldRes = await hubFetch(`/api/financeIncomes/${id}`)
        if (!oldRes.ok) return
        const oldInc = await oldRes.json()
        if (
    incomeDocumentKind(oldInc) === 'tax_invoice_receipt' ||
    incomeDocumentKind(oldInc) === 'receipt'
  ) {
          toastFsError(
            'כבר מס קבלה',
            new Error('already tax receipt'),
            'convertToTaxReceipt',
          )
          return
        }

        const receiptDate = options?.taxReceiptDate?.trim().slice(0, 10) ?? ''
        if (!receiptDate) {
          toastFsError(
            'חובה לציין תאריך מס קבלה',
            new Error('missing tax receipt date'),
            'convertToTaxReceipt',
          )
          return
        }

        const paid = incomeTotalPaid(oldInc)
        const isPartialSplit = incomeNeedsPartialConversionSplit(oldInc)

        if (isPartialSplit) {
          const receiptNumber = options?.taxReceiptNumber?.trim()
          if (!receiptNumber) {
            toastFsError(
              'חובה לציין מספר חשבונית מס קבלה',
              new Error('missing tax receipt number'),
              'convertToTaxReceipt',
            )
            return
          }

          const fullVat = resolveIncomeVat(oldInc)
          const paidPortion = prorateVatBreakdown(fullVat, paid, oldInc.amount)
          const remainingGross = round2(oldInc.amount - paid)
          const remainingPortion = prorateVatBreakdown(
            fullVat,
            remainingGross,
            oldInc.amount,
          )
          const payments = normalizeIncomePayments(oldInc)
          const last = payments[payments.length - 1]
          const syncedDates = syncTaxReceiptPaymentDate(receiptDate, payments)

          const receiptRow: Record<string, unknown> = {
            clientId: oldInc.clientId.trim(),
            amount: paidPortion.gross,
            amountIncludesVat: oldInc.amountIncludesVat !== false,
            vatAmount: paidPortion.vat,
            amountBeforeVat: paidPortion.net,
            documentKind: 'tax_invoice_receipt',
            invoiceNumber: receiptNumber,
            invoiceDate: syncedDates.invoiceDate,
            status: 'paid',
            paidAmount: paid,
            paymentMethod: last?.paymentMethod ?? 'bank_transfer',
            paidDate: syncedDates.paidDate,
            payments: syncedDates.payments ?? payments,
            sourceTransactionInvoiceId: id,
            createdAt: new Date().toISOString(),
          }
          if (oldInc.description?.trim()) {
            receiptRow.description = oldInc.description.trim()
          }
          if (oldInc.projectId?.trim()) {
            receiptRow.projectId = oldInc.projectId.trim()
          }
          if (oldInc.hostingClient) {
            receiptRow.hostingClient = true
            if (oldInc.hostingType) receiptRow.hostingType = oldInc.hostingType
          }
          if (oldInc.hourlyServiceId?.trim()) {
            receiptRow.hourlyServiceId = oldInc.hourlyServiceId.trim()
          }

          const res_add = await hubFetch('/api/financeIncomes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(receiptRow),
      })
      if (!res_add.ok) throw new Error('API Error')

          await hubFetch(`/api/financeIncomes/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(stripUndefined({
              amount: remainingPortion.gross,
              vatAmount: remainingPortion.vat,
              amountBeforeVat: remainingPortion.net,
              status: 'pending',
              paidAmount: null,
              payments: null,
              paymentMethod: null,
              paidDate: null,
            }))
      })
      const remainingInc: FinanceIncome = {
            ...oldInc,
            amount: remainingPortion.gross,
            vatAmount: remainingPortion.vat,
            amountBeforeVat: remainingPortion.net,
            status: 'pending',
            paidAmount: undefined,
            payments: undefined,
            paymentMethod: undefined,
            paidDate: undefined,
          }
          await syncClientBalance(oldInc, remainingInc)

          toastFsSuccess(
            `נוצרה מס קבלה על ${formatNis(paid)} — יתרת ${formatNis(remainingGross)} נשארה כחשבונית עסקה`,
          )
          return
        }

        const syncedDates = syncTaxReceiptPaymentDate(
          receiptDate,
          normalizeIncomePayments(oldInc),
        )
        const res_sync = await hubFetch(`/api/financeIncomes/${id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(stripUndefined({
            documentKind: 'tax_invoice_receipt',
            sourceTransactionInvoiceId: id,
            invoiceDate: syncedDates.invoiceDate,
            paidDate: syncedDates.paidDate,
            ...(syncedDates.payments ? { payments: syncedDates.payments } : {}),
          }))
        })
        if (!res_sync.ok) throw new Error('API Error')
        toastFsSuccess('הומר לחשבונית מס קבלה — נספר כהכנסה בפועל')
      } catch (e) {
        toastFsError('שגיאה בהמרה למס קבלה', e, 'convertToTaxReceipt')
      }
    },
    [],
  )

  const addIncomePayment = useCallback(
    async (id: string, payment: AddIncomePaymentInput) => {
      try {
        const oldRes = await hubFetch(`/api/financeIncomes/${id}`)
        if (!oldRes.ok) return
        const oldInc = await oldRes.json()
        const records = normalizeIncomePayments(oldInc)
        const outstanding = Math.max(
          0,
          round2(oldInc.amount - incomeTotalPaid(oldInc)),
        )
        const amount = round2(payment.amount)
        if (!Number.isFinite(amount) || amount <= 0 || amount > outstanding + 0.001) {
          toastFsError(
            'סכום התשלום חורג מהיתרה',
            new Error('invalid payment amount'),
            'addIncomePayment',
          )
          return
        }
        const nextRecords = [
          ...records,
          buildIncomePaymentRecord(payment),
        ]
        const nextInc = await persistIncomePayments(oldInc, nextRecords)
        toastFsSuccess(
          nextInc.status === 'paid'
            ? 'התשלום נרשם — החשבון שולם במלואו'
            : 'התשלום הנוסף נרשם',
        )
      } catch (e) {
        toastFsError('שגיאה ברישום תשלום', e, 'addIncomePayment')
      }
    },
    [],
  )

  const updateIncomePayment = useCallback(
    async (id: string, paymentId: string, payment: UpdateIncomePaymentInput) => {
      try {
        const oldRes = await hubFetch(`/api/financeIncomes/${id}`)
        if (!oldRes.ok) return
        const oldInc = await oldRes.json()
        const records = normalizeIncomePayments(oldInc)
        const rawRecords = incomePaymentRecords(oldInc)
        const targetIdx = rawRecords.findIndex((p) => p.id === paymentId)
        if (targetIdx < 0) {
          toastFsError(
            'התשלום לא נמצא',
            new Error('payment not found'),
            'updateIncomePayment',
          )
          return
        }
        const resolvedId = records[targetIdx]!.id
        const amount = round2(payment.amount)
        const others = records
          .filter((p) => p.id !== resolvedId)
          .reduce((s, p) => s + p.amount, 0)
        const maxAmount = round2(oldInc.amount - others)
        if (!Number.isFinite(amount) || amount <= 0 || amount > maxAmount + 0.001) {
          toastFsError(
            'סכום התשלום לא תקין',
            new Error('invalid payment amount'),
            'updateIncomePayment',
          )
          return
        }
        const nextRecords = records.map((p) =>
          p.id === resolvedId
            ? {
                ...p,
                amount,
                paymentMethod: payment.paymentMethod,
                paidDate: payment.paidDate.slice(0, 10),
              }
            : p,
        )
        await persistIncomePayments(oldInc, nextRecords)
        toastFsSuccess('התשלום עודכן')
      } catch (e) {
        toastFsError('שגיאה בעדכון התשלום', e, 'updateIncomePayment')
      }
    },
    [],
  )

  const deleteIncomePayment = useCallback(
    async (id: string, paymentId: string) => {
      try {
        const oldRes = await hubFetch(`/api/financeIncomes/${id}`)
        if (!oldRes.ok) return
        const oldInc = await oldRes.json()
        const records = normalizeIncomePayments(oldInc)
        const rawRecords = incomePaymentRecords(oldInc)
        const targetIdx = rawRecords.findIndex((p) => p.id === paymentId)
        if (targetIdx < 0) {
          toastFsError(
            'התשלום לא נמצא',
            new Error('payment not found'),
            'deleteIncomePayment',
          )
          return
        }
        const resolvedId = records[targetIdx]!.id
        const nextRecords = records.filter((p) => p.id !== resolvedId)
        const nextInc = await persistIncomePayments(oldInc, nextRecords)
        toastFsSuccess(
          nextInc.status === 'pending'
            ? 'התשלום נמחק — החשבון ממתין לתשלום'
            : 'התשלום נמחק',
        )
      } catch (e) {
        toastFsError('שגיאה במחיקת התשלום', e, 'deleteIncomePayment')
      }
    },
    [],
  )

  const deleteIncome = useCallback(async (id: string) => {
    try {
      const oldRes = await hubFetch(`/api/financeIncomes/${id}`)
        const oldInc = oldRes.ok ? await oldRes.json() : null
      if (oldInc) {
        await syncClientBalance(oldInc, {
          ...oldInc,
          status: 'pending',
          paidAmount: undefined,
          payments: undefined,
        })
      }
      const res_del = await hubFetch(`/api/financeIncomes/${id}`, { method: 'DELETE' })
      if (!res_del.ok) throw new Error('API Error')
      toastFsSuccess('ההכנסה נמחקה')
    } catch (e) {
      toastFsError('שגיאה במחיקת ההכנסה', e, 'deleteIncome')
    }
  }, [])

  const value = useMemo(
    () => ({
      incomes,
      loading,
      error,
      addIncome,
      updateIncome,
      convertToTaxReceipt,
      addIncomePayment,
      updateIncomePayment,
      deleteIncomePayment,
      deleteIncome,
    }),
    [
      incomes,
      loading,
      error,
      addIncome,
      updateIncome,
      convertToTaxReceipt,
      addIncomePayment,
      updateIncomePayment,
      deleteIncomePayment,
      deleteIncome,
    ],
  )

  return (
    <FinanceIncomesContext.Provider value={value}>
      {children}
    </FinanceIncomesContext.Provider>
  )
}

export function useFinanceIncomes(): FinanceIncomesContextValue {
  const ctx = useContext(FinanceIncomesContext)
  if (!ctx) {
    throw new Error('useFinanceIncomes חייב להיות בתוך FinanceIncomesProvider')
  }
  return ctx
}
