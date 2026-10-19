<?php

declare(strict_types=1);

/* Hebrew strings for M1 (entities, document types, import report). */

return [
    // Entities
    'entity.clients'           => 'לקוחות',
    'entity.client_contacts'   => 'אנשי קשר',
    'entity.suppliers'         => 'ספקים',
    'entity.projects'          => 'פרויקטים',
    'entity.tasks'             => 'משימות',
    'entity.hours_banks'       => 'בנקי שעות',
    'entity.time_entries'      => 'דיווחי שעות',
    'entity.active_timers'     => 'טיימרים פעילים',
    'entity.finance_documents' => 'מסמכים כספיים',
    'entity.expenses'          => 'הוצאות',
    'entity.leads'             => 'לידים',
    'entity.quotes'            => 'הצעות מחיר',
    'entity.assets'            => 'נכסים (דומיינים/אחסון)',
    'entity.vault_items'       => 'כספת גישות',
    'entity.interactions'      => 'שיחות ותקשורת',

    // Morning document kinds (as observed in the data)
    'doc_kind.transaction_account' => 'חשבון עסקה',
    'doc_kind.tax_invoice'         => 'חשבונית מס',
    'doc_kind.tax_invoice_receipt' => 'חשבונית מס/קבלה',
    'doc_kind.receipt'             => 'קבלה',

    // Income reasons
    'income.invoice'           => 'מסמך עסקה — נספר כהכנסה',
    'income.receipt_matched'   => 'קבלה עם חשבונית תואמת (לקוח+סכום) — לא נספרת',
    'income.receipt_unmatched' => 'קבלה ללא חשבונית תואמת — נספרת כהכנסה',
    'income.unknown_type'      => 'סוג מסמך לא מזוהה — מסומן לבדיקה, לא נספר',

    // Import report
    'import.title'        => 'דוח התאמה — ייבוא D1 ל-HubOS',
    'import.tenant'       => 'דייר',
    'import.source'       => 'מקור',
    'import.imported'     => 'יובא',
    'import.skipped'      => 'דולג',
    'import.reason'       => 'סיבה',
    'import.coverage'     => 'כיסוי קישור ללקוח',
    'import.income_years' => 'הכנסות לפי שנה (לפי חוק המסמך החשבונאי)',
    'import.doc_mapping'  => 'מיפוי קודי מסמכים (מהנתונים)',
    'import.flags'        => 'דגלים לבדיקת הבעלים',
    'import.inventory'    => 'מלאי מקור מלא (כל טבלה/אוסף)',
    'import.vault_no_key' => 'HUBOS_VAULT_KEY לא מוגדר: יובאו מטא-נתונים בלבד, הסודות לא נשמרו ({n} רשומות)',
    'import.vault_key'    => 'סודות הוצפנו ב-AES-256-GCM ({n} רשומות)',
];
