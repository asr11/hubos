import {
  addDoc as fsAddDoc,
  collection,
  deleteDoc as fsDeleteDoc,
  
  
  updateDoc as fsUpdateDoc,
} from 'firebase/firestore'
import { create } from 'zustand'
import { db } from '../lib/firebase'
import { stripUndefined } from '../lib/stripUndefined'
import type { HubStore, ToastType } from '../types'

export const useHubStore = create<HubStore>((set, get) => ({
  docs: [],

  currentUser: null,
  setCurrentUser: (user) => set({ currentUser: user }),

  selectedProjectId: null,
  selectedClientId: null,

  toasts: [],

  pushToast: (message: string, type: ToastType) => {
    const id = crypto.randomUUID()
    set((s) => ({ toasts: [...s.toasts, { id, message, type }] }))
    window.setTimeout(() => {
      get().dismissToast(id)
    }, 4200)
  },

  dismissToast: (id: string) => {
    set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) }))
  },

  addDoc: (data) => {
    void (async () => {
      try {
        await fsAddDoc(collection(db, 'docs'), {
          ...data,
          updatedAt: new Date().toISOString(),
        })
        get().pushToast('המסמך נוסף', 'success')
      } catch (e) {
        console.error('addDoc', e)
        get().pushToast('שגיאה בשמירת המסמך', 'error')
      }
    })()
  },

  updateDoc: (_id, _data) => {
    void (async () => {
      try {
        await fsUpdateDoc(null as any, {
          ...stripUndefined(_data as Record<string, unknown>),
          updatedAt: new Date().toISOString(),
        })
        get().pushToast('המסמך נשמר', 'success')
      } catch (e) {
        console.error('updateDoc', e)
        get().pushToast('שגיאה בשמירת המסמך', 'error')
      }
    })()
  },

  patchDocContent: (id, content) => {
    set((s) => ({
      docs: s.docs.map((d) =>
        d.id === id
          ? { ...d, content, updatedAt: new Date().toISOString() }
          : d,
      ),
    }))
    void (async () => {
      try {
        await fsUpdateDoc(null as any, {
          content,
          updatedAt: new Date().toISOString(),
        })
      } catch (e) {
        console.error('patchDocContent', e)
      }
    })()
  },

  deleteDoc: (_id) => {
    void (async () => {
      try {
        await fsDeleteDoc(null as any)
        get().pushToast('המסמך נמחק', 'success')
      } catch (e) {
        console.error('deleteDoc', e)
        get().pushToast('שגיאה במחיקת המסמך', 'error')
      }
    })()
  },

  setSelectedProject: (id) => set({ selectedProjectId: id }),
  setSelectedClient: (id) => set({ selectedClientId: id }),
}))

export const useStore = useHubStore
