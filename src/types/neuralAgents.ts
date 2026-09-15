export type AgentRole = 'manager' | 'researcher' | 'executor' | 'auditor';

export interface NeuralNode {
  id: string;
  name: string;
  role: AgentRole;
  description: string;
  status: 'idle' | 'processing' | 'completed' | 'error';
  inputs: string[];
  outputs: string[];
  capabilities: string[];
}

export interface AgentChainTask {
  id: string;
  title: string;
  description: string;
  initiator: string;
  assignedNodeId: string;
  status: 'pending' | 'in_progress' | 'audited' | 'completed' | 'failed';
  dataPayload?: Record<string, unknown>;
  auditLogs: Array<{
    timestamp: string;
    passed: boolean;
    rule: string;
    note: string;
  }>;
  result?: string;
  nextTaskIds?: string[];
}

export interface NeuralNetworkState {
  nodes: NeuralNode[];
  activeChains: AgentChainTask[];
  isExecutionActive: boolean;
}
