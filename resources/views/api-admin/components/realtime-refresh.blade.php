@props([
    'metadata' => [],
])

<div
    data-api-admin-realtime-refresh
    data-api-admin-realtime='@json($metadata)'
    hidden
></div>

<script>
    (() => {
        const node = document.currentScript?.previousElementSibling;
        if (!node) {
            return;
        }

        const register = () => {
            if (!window.ApiAdminRealtimeRefresh) {
                return;
            }

            window.ApiAdminRealtimeRefresh.register(JSON.parse(node.dataset.apiAdminRealtime || '{}'));
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', register, { once: true });
        } else {
            register();
        }
    })();
</script>
