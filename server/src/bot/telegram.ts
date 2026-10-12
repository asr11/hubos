// @ts-nocheck
import { Telegraf } from 'telegraf'
import { db } from '../lib/db.js'

export function startTelegramBot() {
  const token = process.env.TELEGRAM_BOT_TOKEN || process.env.BOT_API_KEY
  const allowedChatId = process.env.TELEGRAM_CHAT_ID

  if (!token) {
    console.log('🤖 Telegram bot token (TELEGRAM_BOT_TOKEN or BOT_API_KEY) not found in .env, skipping bot initialization.')
    return
  }

  const bot = new Telegraf(token)

  // Middleware for security (E2E Sovereign access)
  bot.use((ctx: any, next: any) => {
    if (!allowedChatId) {
      console.log(`[Telegram Bot] Message received from chat ID: ${ctx.chat?.id}. Please add TELEGRAM_CHAT_ID=${ctx.chat?.id} to your .env file to secure the bot.`)
      return next() 
    }
    
    if (ctx.chat?.id.toString() !== allowedChatId) {
      console.warn(`[Telegram Bot] Unauthorized access attempt from chat ID: ${ctx.chat?.id}`)
      return ctx.reply('🚫 אין לך הרשאה לגשת לבוט המנכ"ל הדיגיטלי.')
    }
    return next()
  })

  bot.start((ctx: any) => {
    ctx.reply(`שלום המנכ"ל. הבוט מחובר בהצלחה למסד הנתונים (OS Hub).
ה-Chat ID שלך הוא: ${ctx.chat?.id}

פקודות זמינות:
/tasks - סטטוס משימות חי
/revenue - דוח הכנסות
`)
  })

  bot.command('tasks', async (ctx: any) => {
    try {
      const stmt = db.prepare("SELECT status, COUNT(*) as count FROM tasks GROUP BY status")
      const rows = (await stmt.all()) as { status: string; count: number }[]
      
      if (rows.length === 0) {
        return ctx.reply('אין משימות במערכת כרגע.')
      }

      const statusHe = {
        todo: 'לביצוע',
        doing: 'בתהליך',
        done: 'בוצע / הושלם',
        blocked: 'חסום'
      }

      let message = '📊 <b>סיכום משימות (Live מה-DB):</b>\n\n'
      for (const r of rows) {
        const hebrewName = (statusHe as any)[r.status] || r.status
        message += `• ${hebrewName}: ${r.count}\n`
      }
      
      ctx.replyWithHTML(message)
    } catch (err) {
      console.error('[Telegram] Error querying tasks', err)
      ctx.reply('❌ שגיאה בשליפת נתונים מ-DB.')
    }
  })

  bot.command('revenue', async (ctx: any) => {
    try {
      const stmt = db.prepare("SELECT SUM(amount) as total FROM invoices WHERE status = 'paid'")
      const row = (await stmt.get()) as { total: number }
      const total = row?.total || 0

      const stmtUnpaid = db.prepare("SELECT SUM(amount) as total FROM invoices WHERE status = 'unpaid'")
      const rowUnpaid = (await stmtUnpaid.get()) as { total: number }
      const totalUnpaid = rowUnpaid?.total || 0

      ctx.replyWithHTML(`💰 <b>דוח פיננסי מהיר (Live):</b>\n\n✅ הכנסות ששולמו: ₪${total.toLocaleString()}\n⏳ חובות פתוחים: ₪${totalUnpaid.toLocaleString()}`)
    } catch (err) {
      console.error('[Telegram] Error querying revenue', err)
      ctx.reply('❌ שגיאה בשליפת נתונים פיננסיים.')
    }
  })

  console.log('🤖 Telegram Bot "המנכ"ל הדיגיטלי" (OS Hub) is initialized for SENDING ALERTS ONLY. Long Polling is disabled to avoid conflict with Cloudflare Webhook.')
  process.once('SIGINT', () => { try { bot.stop('SIGINT') } catch (e) {} })
  process.once('SIGTERM', () => { try { bot.stop('SIGTERM') } catch (e) {} })
}
