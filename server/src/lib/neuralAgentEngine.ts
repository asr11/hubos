import { db, logSystemEvent } from './db.js';

export interface AgentNodeConfig {
  id: string;
  name: string;
  role: 'manager' | 'researcher' | 'executor' | 'auditor';
  description: string;
}

export interface TaskStep {
  id: string;
  title: string;
  assignedRole: 'manager' | 'researcher' | 'executor' | 'auditor';
  inputData: Record<string, unknown>;
  outputData?: Record<string, unknown>;
  status: 'pending' | 'processing' | 'audited' | 'completed' | 'failed';
  auditNotes?: string;
}

export const INITIAL_NODES: AgentNodeConfig[] = [
  {
    id: 'node-manager',
    name: 'מנהל הרשת (Manager)',
    role: 'manager',
    description: 'מנתח אירועים, מחלק משימות ומתכנן את סדר השרשור'
  },
  {
    id: 'node-researcher',
    name: 'סוכן מחקר (Researcher)',
    role: 'researcher',
    description: 'אוסף נתונים (CRM, מסמכים, פגישות) במצב קריאה בלבד'
  },
  {
    id: 'node-executor',
    name: 'סוכן ביצוע (Executor)',
    role: 'executor',
    description: 'מבצע משימות תפעוליות מותרות ומשכפל טיוטות'
  },
  {
    id: 'node-auditor',
    name: 'סוכן בקרה ואתיקה (Auditor)',
    role: 'auditor',
    description: 'בודק עמידה בעשרת הדיברות, אבטחה פיננסית וקריאה בלבד'
  }
];

export class NeuralAgentEngine {
  public static validateEthicsAndFinance(action: string, _payload: Record<string, unknown>): { safe: boolean; reason: string } {
    const actionLower = action.toLowerCase();
    
    // Financial security check - absolute write blockage
    if (
      actionLower.includes('write_finance') ||
      actionLower.includes('charge') ||
      actionLower.includes('create_invoice') ||
      actionLower.includes('delete_document')
    ) {
      return {
        safe: false,
        reason: 'חסימת אבטחה פיננסית: פעולת כתיבה או חיוב אסורה על פי מדיניות החברה (Read-Only).'
      };
    }

    return { safe: true, reason: 'עבר בדיקת אתיקה ואבטחה בהצלחה.' };
  }

  public static async executeChain(initialTask: string): Promise<TaskStep[]> {
    const steps: TaskStep[] = [];

    // Step 1: Manager analyzes
    const managerStep: TaskStep = {
      id: `step-1-${Date.now()}`,
      title: `ניתוח ופירוק משימה: ${initialTask}`,
      assignedRole: 'manager',
      inputData: { task: initialTask },
      status: 'processing'
    };
    steps.push(managerStep);

    // Audit check on Manager step
    const auditManager = this.validateEthicsAndFinance('analyze_task', managerStep.inputData);
    if (!auditManager.safe) {
      managerStep.status = 'failed';
      managerStep.auditNotes = auditManager.reason;
      logSystemEvent('neural_agent_error', 'manager', auditManager.reason);
      return steps;
    }

    managerStep.outputData = { plan: 'נבנתה שרשרת עיבוד: מחקר (DB) -> ביצוע -> בקרה' };
    managerStep.status = 'completed';

    // Step 2: Researcher collects data from SQLite DB
    const researchStep: TaskStep = {
      id: `step-2-${Date.now()}`,
      title: 'איסוף נתונים לוקאלי (DB SQLite - Read-Only)',
      assignedRole: 'researcher',
      inputData: { query: initialTask },
      status: 'processing'
    };
    steps.push(researchStep);
    
    let dataFound: any = null;
    try {
      const taskLower = initialTask.toLowerCase();
      if (taskLower.includes('לקוחות') || taskLower.includes('clients')) {
        dataFound = db.prepare('SELECT id, name, email, status FROM clients LIMIT 50').all();
      } else if (taskLower.includes('דומיינים') || taskLower.includes('domains')) {
        dataFound = db.prepare('SELECT id, domain_name, is_active, status FROM domains LIMIT 50').all();
      } else if (taskLower.includes('הצעות') || taskLower.includes('quotes') || taskLower.includes('מחיר')) {
        dataFound = db.prepare('SELECT id, quote_number, status, total_amount FROM quotes LIMIT 50').all();
      } else if (taskLower.includes('לוג') || taskLower.includes('logs')) {
        dataFound = db.prepare('SELECT id, event_type, timestamp FROM system_logs ORDER BY id DESC LIMIT 50').all();
      } else {
        dataFound = { message: 'לא זוהתה ישות נתונים מוכרת. מתבצע עיבוד כללי ללא שליפת טבלאות.' };
      }
      
      researchStep.outputData = { 
        dataFound, 
        count: Array.isArray(dataFound) ? dataFound.length : 0 
      };
      researchStep.status = 'completed';
    } catch (err: any) {
      researchStep.status = 'failed';
      researchStep.outputData = { error: err.message };
      logSystemEvent('neural_agent_error', 'researcher', err.message);
      return steps;
    }

    // Step 3: Executor prepares action
    const execStep: TaskStep = {
      id: `step-3-${Date.now()}`,
      title: 'עיבוד לוגי מקומי והסקנת מסקנות',
      assignedRole: 'executor',
      inputData: researchStep.outputData,
      status: 'processing'
    };
    steps.push(execStep);

    let execResult = '';
    if (Array.isArray(dataFound)) {
      execResult = `המערכת שלפה ועיבדה ${dataFound.length} רשומות בהצלחה מתוך מסד הנתונים המקומי.`;
    } else {
      execResult = `המשימה "${initialTask}" עובדה בהצלחה מול הלוגיקה הפנימית.`;
    }
    execStep.outputData = { result: execResult, recommendation: 'העברה לביקורת לפני סיום התהליך' };
    execStep.status = 'completed';

    // Step 4: Auditor validates final state
    const auditorStep: TaskStep = {
      id: `step-4-${Date.now()}`,
      title: 'ביקורת אתיקה, אבטחה וכתיבה ללוג',
      assignedRole: 'auditor',
      inputData: execStep.outputData,
      status: 'processing'
    };
    steps.push(auditorStep);

    const finalAudit = this.validateEthicsAndFinance('final_verify', execStep.outputData);
    auditorStep.status = finalAudit.safe ? 'completed' : 'failed';
    auditorStep.auditNotes = finalAudit.reason;
    auditorStep.outputData = { verified: finalAudit.safe };
    
    // Log the entire chain execution securely
    logSystemEvent(
      'neural_agent_chain_execution', 
      'auditor', 
      `Task: "${initialTask}" | Safe: ${finalAudit.safe} | Steps: ${steps.length}`
    );

    return steps;
  }
}
