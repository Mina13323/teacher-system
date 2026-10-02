import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { describe, expect, it } from 'vitest';

const workerSource = readFileSync(new URL('../../../public/push-sw.js', import.meta.url), 'utf8');

function loadPushWorker() {
    const handlers = {};
    const shown = [];
    const self = {
        location: { origin: 'https://lms.example' },
        registration: {
            showNotification: (title, options) => {
                shown.push({ title, options });
                return Promise.resolve();
            },
        },
        addEventListener: (name, handler) => { handlers[name] = handler; },
        clients: {
            matchAll: async () => [],
            openWindow: async () => null,
        },
    };

    runInNewContext(workerSource, { self, URL, Object, Promise });
    return { handlers, self, shown };
}

describe('push notification navigation URL safety', () => {
    it('keeps notification payload URLs on the LMS origin', async () => {
        const { handlers, shown } = loadPushWorker();
        let pending;
        handlers.push({
            data: {
                json: () => ({
                    url: '/student/exams?from=push',
                    data: { url: 'https://attacker.example/phish', kind: 'exam_opening' },
                }),
            },
            waitUntil: (promise) => { pending = promise; },
        });
        await pending;

        expect(shown[0].options.data.url).toBe('https://lms.example/student/exams?from=push');
        expect(shown[0].options.data.kind).toBe('exam_opening');
    });

    it('falls back to the LMS root when a notification click contains an external or unsafe URL', async () => {
        const { handlers, self } = loadPushWorker();
        let navigated = null;
        let pending;
        self.clients.matchAll = async () => [{
            navigate: (url) => { navigated = url; return Promise.resolve(); },
            focus: () => Promise.resolve(),
        }];
        handlers.notificationclick({
            notification: {
                data: { url: 'javascript:alert(1)' },
                close: () => {},
            },
            waitUntil: (promise) => { pending = promise; },
        });
        await pending;

        expect(navigated).toBe('https://lms.example/');
    });
});
