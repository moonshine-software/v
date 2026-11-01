const DEFAULT_EVENT = 'api-admin.upstream-resource-mutated';
const DOM_EVENT = 'api-admin:upstream-resource-mutated';

class ApiAdminRealtimeRefresh {
    constructor() {
        this.subscriptions = new Map();
        this.listenersAttached = false;
    }

    register(rawConfig) {
        const config = this.normalizeConfig(rawConfig);

        if (!config.enabled) {
            this.debug(config, 'registration skipped', { reason: 'disabled' });
            return;
        }

        this.attachDomListener();
        this.subscribeEcho(config);
        this.subscriptions.set(config.key, config);
        this.debug(config, 'registered', {
            channels: config.channels,
            strategy: config.refreshStrategy,
            page: config.page,
        });
    }

    handleMutation(mutation) {
        for (const config of this.subscriptions.values()) {
            if (!this.matches(config, mutation)) {
                continue;
            }

            this.debug(config, 'matching mutation received', {
                action: mutation.action,
                id: mutation.id || '',
            });
            this.scheduleRefresh(config, mutation);
        }
    }

    normalizeConfig(rawConfig) {
        const config = rawConfig && typeof rawConfig === 'object' ? rawConfig : {};
        const service = this.string(config.service);
        const resource = this.string(config.resource);
        const page = this.string(config.page) || this.detectPageType();
        const refreshStrategy = this.string(config.refreshStrategy) || 'auto';
        const debounceMs = Number.isFinite(Number(config.debounceMs)) ? Number(config.debounceMs) : 750;
        const channels = Array.isArray(config.channels)
            ? config.channels.map((channel) => this.string(channel)).filter(Boolean)
            : [];

        return {
            key: this.string(config.key) || `${service}:${resource}:${page}`,
            enabled: config.enabled === true && service !== '' && resource !== '',
            service,
            resource,
            page,
            channels,
            private: config.private === true,
            eventName: this.string(config.eventName) || DEFAULT_EVENT,
            refreshStrategy,
            debounceMs: Math.max(50, debounceMs),
            debug: config.debug === true,
            timer: null,
        };
    }

    subscribeEcho(config) {
        if (!window.Echo || config.channels.length === 0) {
            this.debug(config, 'echo subscription skipped', {
                hasEcho: Boolean(window.Echo),
                channels: config.channels,
            });
            return;
        }

        for (const channel of config.channels) {
            const subscription = config.private ? window.Echo.private(channel) : window.Echo.channel(channel);
            subscription.listen(`.${config.eventName}`, (mutation) => {
                this.handleMutation(mutation);
            });
            this.debug(config, 'echo subscribed', { channel });
        }
    }

    attachDomListener() {
        if (this.listenersAttached) {
            return;
        }

        window.addEventListener(DOM_EVENT, (event) => {
            this.handleMutation(event.detail || {});
        });
        this.listenersAttached = true;
    }

    matches(config, mutation) {
        if (!mutation || typeof mutation !== 'object') {
            return false;
        }

        return this.string(mutation.service) === config.service
            && this.string(mutation.resource) === config.resource;
    }

    scheduleRefresh(config, mutation) {
        if (config.refreshStrategy === 'disabled') {
            this.debug(config, 'refresh skipped', { reason: 'strategy_disabled' });
            return;
        }

        window.clearTimeout(config.timer);
        config.timer = window.setTimeout(() => {
            this.refresh(config, mutation);
        }, config.debounceMs);
        this.debug(config, 'refresh debounced', { debounceMs: config.debounceMs });
    }

    refresh(config, mutation) {
        if (config.page === 'form' || config.refreshStrategy === 'notice') {
            this.showNotice(config, mutation);
            return;
        }

        this.debug(config, 'reloading page');
        window.location.reload();
    }

    showNotice(config, mutation) {
        const id = 'api-admin-realtime-refresh-notice';
        let notice = document.getElementById(id);

        if (!notice) {
            notice = document.createElement('div');
            notice.id = id;
            notice.setAttribute('role', 'status');
            notice.className = 'api-admin-realtime-refresh-notice';
            Object.assign(notice.style, {
                position: 'fixed',
                right: '16px',
                bottom: '16px',
                zIndex: '9999',
                maxWidth: '360px',
                padding: '12px 14px',
                borderRadius: '8px',
                background: '#111827',
                color: '#ffffff',
                boxShadow: '0 10px 25px rgba(0, 0, 0, 0.18)',
                fontSize: '14px',
                lineHeight: '1.35',
            });
            document.body.appendChild(notice);
        }

        notice.textContent = mutation.id
            ? `Upstream ${mutation.resource || config.resource} changed: ${mutation.id}`
            : `Upstream ${mutation.resource || config.resource} changed`;
        this.debug(config, 'notice displayed', {
            action: mutation.action || '',
            id: mutation.id || '',
        });
    }

    detectPageType() {
        const path = window.location.pathname;

        if (path.includes('/create') || path.includes('/edit')) {
            return 'form';
        }

        if (path.includes('/detail') || path.match(/\/\d+$/)) {
            return 'detail';
        }

        return 'index';
    }

    string(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    debug(config, message, context = {}) {
        if (!config.debug) {
            return;
        }

        console.debug('[api-admin:realtime]', message, context);
    }
}

window.ApiAdminRealtimeRefresh = window.ApiAdminRealtimeRefresh || new ApiAdminRealtimeRefresh();

export { ApiAdminRealtimeRefresh };
