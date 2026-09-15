import { env, morningConfigured } from '../config/env.js';
export async function registerHealthRoutes(app) {
    const handler = async () => {
        return {
            ok: true,
            service: 'hub-pos-server',
            env: env.nodeEnv,
            uptimeSec: Math.floor(process.uptime()),
            integrations: {
                morning: morningConfigured() ? 'configured' : 'not_configured',
            },
        };
    };
    app.get('/health', handler);
    app.get('/api/health', handler);
}
//# sourceMappingURL=health.js.map