import { hubFetch } from '../lib/hubFetch'
import React, { useState } from 'react';
import { Network, Cpu, ShieldCheck, Play, CheckCircle2, AlertTriangle, ArrowRight } from 'lucide-react';
import type { AgentRole } from '../types/neuralAgents';

interface ChainStep {
  id: string;
  title: string;
  assignedRole: AgentRole;
  status: 'pending' | 'processing' | 'audited' | 'completed' | 'failed';
  auditNotes?: string;
  outputData?: Record<string, unknown>;
}

export function AgentsNeuralNetwork() {
  const [taskInput, setTaskInput] = useState('');
  const [isRunning, setIsRunning] = useState(false);
  const [steps, setSteps] = useState<ChainStep[]>([]);
  const [activeNode, setActiveNode] = useState<AgentRole | null>(null);

  const handleRunChain = async () => {
    if (!taskInput.trim() || isRunning) return;
    setIsRunning(true);
    setSteps([]);

    try {
      const res = await hubFetch('/api/neural-agents/chain/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task: taskInput })
      });
      const data = await res.json();
      if (data.ok && Array.isArray(data.steps)) {
        for (const step of data.steps) {
          setActiveNode(step.assignedRole);
          await new Promise((r) => setTimeout(r, 600));
          setSteps((prev) => [...prev, step]);
        }
      }
    } catch (e) {
      console.error('Error executing chain:', e);
    } finally {
      setIsRunning(false);
      setActiveNode(null);
    }
  };

  const nodeCards: Array<{ role: AgentRole; title: string; desc: string; color: string; icon: React.ReactNode }> = [
    {
      role: 'manager',
      title: 'נוירון מנהל (Manager)',
      desc: 'מנתח כוונות ומפרק משימות מורכבות',
      color: 'from-blue-600 to-indigo-600',
      icon: <Network className="w-6 h-6 text-blue-400" />
    },
    {
      role: 'researcher',
      title: 'נוירון מחקר (Researcher)',
      desc: 'איסוף מידע מקומי Read-Only בלבד',
      color: 'from-cyan-600 to-teal-600',
      icon: <Cpu className="w-6 h-6 text-teal-400" />
    },
    {
      role: 'executor',
      title: 'נוירון ביצוע (Executor)',
      desc: 'עיבוד לוגי מקומי ללא עלויות אסימונים',
      color: 'from-amber-600 to-orange-600',
      icon: <Play className="w-6 h-6 text-amber-400" />
    },
    {
      role: 'auditor',
      title: 'נוירון אתיקה (Auditor)',
      desc: 'סריקה ואבטחה מול עשרת הדיברות',
      color: 'from-emerald-600 to-green-600',
      icon: <ShieldCheck className="w-6 h-6 text-emerald-400" />
    }
  ];

  return (
    <div className="p-6 dir-rtl text-right min-h-screen bg-slate-950 text-slate-100 space-y-8" dir="rtl">
      {/* Header */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-slate-900/60 p-6 rounded-2xl border border-slate-800 backdrop-blur-xl">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-3 text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-emerald-400">
            <Network className="w-8 h-8 text-blue-400 animate-pulse" />
            רשת נוירונים & שרשור סוכנים אוטונומי
          </h1>
          <p className="text-sm text-slate-400 mt-1">
            מערכת סוכנים לוקאלית הפועלת בשרשור אוטונומי, תוך שמירה הדוקה על אבטחה פיננסית ועשרת הדיברות.
          </p>
        </div>
        <div className="flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">
          <ShieldCheck className="w-4 h-4" />
          אבטחה פיננסית: Read-Only מופעל
        </div>
      </div>

      {/* Neural Nodes Diagram */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {nodeCards.map((n) => {
          const isActive = activeNode === n.role;
          return (
            <div
              key={n.role}
              className={`p-5 rounded-2xl border transition-all duration-300 ${
                isActive
                  ? 'bg-slate-800/90 border-blue-500 shadow-lg shadow-blue-500/20 scale-105'
                  : 'bg-slate-900/40 border-slate-800 hover:border-slate-700'
              }`}
            >
              <div className="flex items-center justify-between mb-3">
                <div className="p-2.5 rounded-xl bg-slate-800/80 border border-slate-700">{n.icon}</div>
                {isActive && <span className="w-2.5 h-2.5 rounded-full bg-blue-400 animate-ping" />}
              </div>
              <h3 className="font-semibold text-slate-100 mb-1">{n.title}</h3>
              <p className="text-xs text-slate-400">{n.desc}</p>
            </div>
          );
        })}
      </div>

      {/* Task Initiation Bar */}
      <div className="bg-slate-900/50 p-6 rounded-2xl border border-slate-800 space-y-4">
        <h2 className="text-lg font-semibold text-slate-200">הפעלת שרשרת אוטונומית מקומית</h2>
        <div className="flex flex-col sm:flex-row gap-3">
          <input
            type="text"
            value={taskInput}
            onChange={(e) => setTaskInput(e.target.value)}
            placeholder="הזן משימה לשרשור סוכנים (למשל: סרוק פרויקטים פתוחים וסכם דוח)"
            className="flex-1 px-4 py-3 bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors"
          />
          <button
            onClick={handleRunChain}
            disabled={isRunning || !taskInput.trim()}
            className="px-6 py-3 bg-gradient-to-r from-blue-600 to-emerald-600 hover:from-blue-500 hover:to-emerald-500 disabled:opacity-50 text-white font-medium rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg shadow-blue-600/20"
          >
            {isRunning ? (
              <>
                <Cpu className="w-5 h-5 animate-spin" />
                מריץ שרשרת...
              </>
            ) : (
              <>
                <Play className="w-5 h-5" />
                הפעל שרשרת
              </>
            )}
          </button>
        </div>
      </div>

      {/* Execution Log Timeline */}
      {steps.length > 0 && (
        <div className="bg-slate-900/50 p-6 rounded-2xl border border-slate-800 space-y-4">
          <h2 className="text-lg font-semibold text-slate-200">יומן שרשור בזמן אמת</h2>
          <div className="space-y-3">
            {steps.map((step, idx) => (
              <div
                key={step.id}
                className="p-4 rounded-xl bg-slate-950 border border-slate-800/80 flex items-start gap-4 animate-in fade-in slide-in-from-bottom-2"
              >
                <div className="mt-1">
                  {step.status === 'completed' ? (
                    <CheckCircle2 className="w-5 h-5 text-emerald-400" />
                  ) : step.status === 'failed' ? (
                    <AlertTriangle className="w-5 h-5 text-rose-400" />
                  ) : (
                    <ArrowRight className="w-5 h-5 text-blue-400 animate-pulse" />
                  )}
                </div>
                <div className="flex-1">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-slate-200">{step.title}</span>
                    <span className="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">
                      צעד {idx + 1} • {step.assignedRole}
                    </span>
                  </div>
                  {step.auditNotes && (
                    <p className="text-xs text-emerald-400 mt-1 bg-emerald-950/40 p-2 rounded border border-emerald-800/40">
                      {step.auditNotes}
                    </p>
                  )}
                  {step.outputData && (
                    <pre className="text-xs font-mono text-slate-400 mt-2 bg-slate-900 p-2 rounded overflow-x-auto dir-ltr text-left">
                      {JSON.stringify(step.outputData, null, 2)}
                    </pre>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
