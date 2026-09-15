# Morning (חשבונית ירוקה) — מדריך API מלא
> מקור אמת פיננסי של HUB | קריאה בלבד (READ-ONLY) | מבוסס הצלבת 3 סוכני מחקר

> [!CAUTION]
> **חוק ברזל:** גישה למערכת Morning מותרת **לקריאה בלבד**. איסור מוחלט על כתיבה, עדכון, יצירה או מחיקה.

---

## 1. אימות (Authentication)

| פרמטר | ערך |
|-------|-----|
| **פרוטוקול** | OAuth 2.0 (Client Credentials) |
| **כתובת IdP** | `POST https://api.morning.co/idp/v1/oauth/token` |
| **Sandbox IdP** | `POST https://api.sandbox.morning.dev/idp/v1/oauth/token` |
| **Body** | `{ "grant_type": "client_credentials", "client_id": "<API_ID>", "client_secret": "<SECRET>" }` |
| **תוקף טוקן** | **60 דקות** — בקבלת `401` יש לחדש |
| **שימוש** | `Authorization: Bearer <accessToken>` |

> [!WARNING]
> הנתיב הישן `POST /api/v1/account/token` **אינו נתמך יותר!**

---

## 2. כתובות בסיס

| סביבה | URL |
|-------|-----|
| **ייצור** | `https://api.greeninvoice.co.il/api/v1` |
| **Sandbox** | `https://sandbox.d.greeninvoice.co.il/api/v1` |

**מגבלת קצב:** ~3 בקשות/שנייה (429 = Backoff)
**דפדוף:** `page` (מ-1), `pageSize` (ברירת מחדל 25)

---

## 3. נקודות קצה — קריאה בלבד

### 📋 לקוחות (Clients)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /clients/{id}` | פרטי לקוח מלאים (שם, ח.פ, מיילים, טלפון, כתובת, יתרה) |
| `GET /clients/{id}/balance` | כרטיס ויתרת לקוח |
| `POST /clients/search` ⚡ | חיפוש לפי: `name`, `email`, `active`, `labels`, `contactPerson` |

### 📄 מסמכים וחשבוניות (Documents)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /documents/{id}` | מסמך מלא (סוג, מספר, תאריך, פריטים, סכומים, PDF) |
| `GET /documents/{id}/linked` | מסמכים מקושרים (קבלה ↔ חשבונית) |
| `GET /documents/{id}/download` | קישור להורדת PDF חתום |
| `GET /documents/types` | קודי סוגי מסמכים |
| `GET /documents/statuses` | סטטוסים אפשריים |
| `GET /documents/info?type={code}` | חוקיות והגדרות סוג מסמך |
| `POST /documents/search` ⚡ | חיפוש לפי: `type`, `dateFrom`, `dateTo`, `client`, `status`, `search` |

**קודי מסמכים:**
`10` הצעת מחיר · `100` הזמנה · `200` ת. משלוח · `300` חשבון עסקה · `305` חשבונית מס · `320` חשבונית מס/קבלה · `330` זיכוי · `400` קבלה

### 💰 תשלומים (Payments)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /payments/{id}` | פרטי עסקת תשלום/סליקה |
| `GET /payments/methods` | שיטות תשלום זמינות |
| `GET /payments/form/{documentId}` | URL לדף סליקה |
| `GET /payments/links/{id}` | פרטי לינק תשלום |
| `GET /payments/links/{id}/status` | סטטוס תשלום (Paid/Pending/Expired) |

### 📦 פריטים וקטלוג (Items)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /items/{id}` | פריט: שם, תיאור, מק"ט, מחיר, מטבע, מע"מ |
| `POST /items/search` ⚡ | חיפוש לפי: `search` (שם/מק"ט), `active` |

### 🏢 ספקים והוצאות (Suppliers & Expenses)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /suppliers/{id}` | פרטי ספק |
| `POST /suppliers/search` ⚡ | חיפוש ספקים |
| `GET /expenses/{id}` | פרטי הוצאה (סכום, מע"מ, סיווג, קבצים) |
| `GET /expenses/classifications` | קטגוריות הוצאות מוכרות |
| `POST /expenses/search` ⚡ | חיפוש הוצאות |

### 🏗️ פרטי עסק וחשבון (Business & Account)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /business` | שם, ח.פ, כתובת, לוגו, הגדרות מע"מ |
| `GET /business/numbering` | רצפי מספור מסמכים |
| `GET /business/footer` | טקסט תחתית מסמכים |
| `GET /account` | פרטי חשבון ומנוי |

### 🔧 כלי עזר (Tools)
| Endpoint | מה מחזיר |
|----------|----------|
| `GET /tools/currencies` | מטבעות ושערי חליפין |
| `GET /tools/countries` | מדינות וקודי ISO |
| `GET /tools/cities?countryCode={code}` | ערים לפי מדינה |
| `GET /tools/categories/business` | סיווגי ענפים |
| `GET /webhooks` | רשימת וובהוקים מוגדרים |

> [!NOTE]
> **⚡ = שאילתת POST לקריאה בלבד.** ב-Morning, חיפושים מתבצעים ב-POST עם גוף JSON (לא GET עם פרמטרים). הם Idempotent ולא משנים מידע.

---

## 4. מלכודות ודגשים (Gotchas)

| נושא | פירוט |
|------|-------|
| **מסלול נדרש** | API פתוח רק למנויי **"Best" ומעלה** (אחרת 403) |
| **שמות שדות** | `income` (לא items), `payment` (יחיד), `lang` (לא language) |
| **דוחות** | אין endpoint ייעודי — יש לאגרג ידנית מ-documents/search |
| **חשבוניות** | לא ניתן לערוך/למחוק — תיקון רק דרך חשבונית זיכוי (330) |
| **Sandbox** | סביבת בדיקות נפרדת לחלוטין, ללא נתונים אמיתיים |

---

## 5. ספריות וכלים קיימים

| כלי | שפה | קישור |
|-----|------|-------|
| `green-invoice` (PyPI) | Python | SDK רשמי/חצי-רשמי עם OAuth 2.0 |
| `GreenInvoice-MCP` | MCP | 66 endpoints ב-10 כלים |
| `morning-cli` | CLI | ממשק שורת פקודה |
| `bariew/greeninvoice` | PHP | ספרייה קהילתית |

---

## 6. מפתחות API קיימים (מצילום המסך)

| תיאור | מזהה | תאריך יצירה |
|-------|------|-------------|
| os.hub.co.il | `39eefa7c-b034-...` | 04/09/2026 |
| hub380-pixel | `7a5fa340-0c88-...` | 24/04/2026 |
| חיבור למערכת crm.hub.co.il | `102352d0-024e-...` | 24/10/2024 |
