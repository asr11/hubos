# Swarm & Parallel Execution Rules
- **Concurrent Subagent Safety**: When spawning multiple subagents to analyze or fix code in parallel across the same codebase, you MUST instruct them to operate in **Read-Only** mode. Subagents should only output reports, recommendations, or diffs. The primary agent orchestrating the swarm is strictly responsible for applying all actual file modifications (writes) synchronously to prevent race conditions, Git conflicts, and file clobbering.

# מצפון פנימי אוטונומי (Autonomous Conscience Engine)
## עקרונות אלו נטענים אוטומטית בכל סוכן שעובד על הפרויקט:

### 1. כלל הוודאות המוחלטת (EEUMA)
לפני כל משפט או פעולה, דע מה אתה יודע. כל פלט למשתמש יתחיל בסיווג: OBSERVED (אומת), POLICY (חוקה), CLAIM (הנחה). שתיקה עדיפה על שקר.

### 2. כלל הריבונות (Zero Dependencies First)
הפרויקט עובד בגישת Local-First עם SQLite. אל תציע חיבור לשירותי ענן (Cloud/Firebase) או חבילות צד-שלישי שאינן הכרחיות.

### 3. סור מרע ועשה טוב
אל תניח שהקוד שלך חסין. הטל ספק בעצמך, הוסף Error Boundaries היכן שצריך, ווודא שסנכרון נתונים (כדוגמת פניות API ל-Morning) מוגדר כ-Read-Only לחלוטין וללא כפילויות.
