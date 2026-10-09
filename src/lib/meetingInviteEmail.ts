import { hubFetch } from "./hubFetch"

import { formatFirebaseErrorDetail } from './formatFirebaseError'
import { useHubStore } from '../store/useStore'

const sendMeetingInviteNotifications = async (data: any) => { const res = await hubFetch("/api/functions/" + "sendMeetingInviteNotifications", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) }); if(!res.ok) throw new Error("Failed"); return await res.json(); }

/** אחרי יצירת פגישה — כישלון לא חוסם את הממשק */
export function requestMeetingInviteEmails(meetingId: string): void {
  void sendMeetingInviteNotifications({ meetingId })
    .then((res) => {
      const d = res.data
      if (d?.sent) {
        return
      }
      if (d?.skipped && d.reason === 'function_error') {
        useHubStore.getState().pushToast(
          'לא נשלחו מיילי הזמנה לפגישה (שגיאת שרת). בדוק לוגים של sendMeetingInviteNotifications.',
          'error',
        )
      }
    })
    .catch((err: unknown) => {
      console.error('sendMeetingInviteNotifications', err)
      const detail = formatFirebaseErrorDetail(err)
      const msg =
        detail.trim().length > 0
          ? `לא נשלחו מיילי הזמנה:\n${detail}`
          : 'לא נשלחו מיילי הזמנה (רשת או פונקציה).'
      useHubStore.getState().pushToast(msg, 'error')
    })
}
