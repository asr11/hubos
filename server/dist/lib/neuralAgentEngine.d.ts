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
export declare const INITIAL_NODES: AgentNodeConfig[];
export declare class NeuralAgentEngine {
    static validateEthicsAndFinance(action: string, _payload: Record<string, unknown>): {
        safe: boolean;
        reason: string;
    };
    static executeChain(initialTask: string): Promise<TaskStep[]>;
}
//# sourceMappingURL=neuralAgentEngine.d.ts.map