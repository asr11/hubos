import type { FastifyReply, FastifyRequest } from 'fastify';
export type AuthUser = {
    uid: string;
    email?: string;
    role?: string;
};
declare module 'fastify' {
    interface FastifyRequest {
        authUser?: AuthUser;
    }
}
export declare function requireAuth(req: FastifyRequest, reply: FastifyReply): Promise<void>;
//# sourceMappingURL=requireAuth.d.ts.map