@php
    $action = collect($items)->firstWhere('is_action', true);
    $services = collect($items)->reject(static fn (array $item): bool => ($item['is_action'] ?? false) === true);
@endphp

@once
    <style>
        .api-admin-hub {
            --api-admin-hub-border: var(--ms-cm-base-stroke);
            --api-admin-hub-muted: var(--ms-cm-base-500);
            --api-admin-hub-card-bg: var(--ms-cm-base-default);
            --api-admin-hub-card-hover: var(--ms-cm-base-50);
            --api-admin-hub-accent: var(--ms-cm-primary);
        }

        .api-admin-hub__bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .api-admin-hub__title {
            margin: 0;
            color: var(--ms-cm-base-900);
            font-size: 1rem;
            font-weight: 650;
            letter-spacing: 0;
        }

        .api-admin-hub__subtitle {
            margin-top: .15rem;
            color: var(--api-admin-hub-muted);
            font-size: .8125rem;
            line-height: 1.3;
        }

        .api-admin-hub__grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: .875rem;
        }

        .api-admin-hub-card {
            display: block;
            min-width: 0;
            padding: 1rem;
            border: 1px solid var(--api-admin-hub-border);
            border-radius: .75rem;
            background: var(--api-admin-hub-card-bg);
            color: inherit;
            text-decoration: none;
            transition: background-color 140ms ease, border-color 140ms ease, box-shadow 140ms ease;
        }

        .api-admin-hub-card:hover {
            border-color: color-mix(in srgb, var(--api-admin-hub-accent) 32%, var(--api-admin-hub-border));
            background: var(--api-admin-hub-card-hover);
            box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        }

        :root.dark .api-admin-hub-card:hover {
            box-shadow: 0 6px 18px rgba(0, 0, 0, .18);
        }

        .api-admin-hub-card__top,
        .api-admin-hub-card__meta {
            display: flex;
            align-items: center;
            min-width: 0;
        }

        .api-admin-hub-card__top {
            justify-content: space-between;
            gap: .75rem;
        }

        .api-admin-hub-card__title {
            min-width: 0;
            overflow: hidden;
            color: var(--ms-cm-base-900);
            font-size: 1rem;
            font-weight: 650;
            line-height: 1.25;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .api-admin-hub-card__status {
            display: inline-flex;
            align-items: center;
            flex: 0 0 auto;
            gap: .375rem;
            color: var(--api-admin-hub-muted);
            font-size: .75rem;
            font-weight: 600;
            line-height: 1;
        }

        .api-admin-hub-card__dot {
            width: .45rem;
            height: .45rem;
            border-radius: 999px;
            background: var(--ms-cm-base-400);
        }

        .api-admin-hub-card__status--ok .api-admin-hub-card__dot {
            background: var(--ms-cm-success);
        }

        .api-admin-hub-card__status--degraded .api-admin-hub-card__dot {
            background: var(--ms-cm-warning);
        }

        .api-admin-hub-card__status--down .api-admin-hub-card__dot {
            background: var(--ms-cm-error);
        }

        .api-admin-hub-card__meta {
            gap: .5rem;
            margin-top: .75rem;
            color: var(--api-admin-hub-muted);
            font-size: .8125rem;
            line-height: 1.25;
        }

        .api-admin-hub-card__service {
            flex: 0 0 auto;
            max-width: 42%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .api-admin-hub-card__url {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .api-admin-hub__empty {
            padding: 1rem;
            border: 1px dashed var(--api-admin-hub-border);
            border-radius: .75rem;
            color: var(--api-admin-hub-muted);
            font-size: .875rem;
        }

        @media (max-width: 640px) {
            .api-admin-hub__bar {
                align-items: stretch;
                flex-direction: column;
            }

            .api-admin-hub__bar .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
@endonce

<section class="api-admin-hub">
    <div class="api-admin-hub__bar">
        <div>
            <h2 class="api-admin-hub__title">Select a service</h2>
            <div class="api-admin-hub__subtitle">Open a generated panel or connect another upstream API.</div>
        </div>

        @if($action !== null)
            <a href="{{ $action['url'] }}" class="btn btn-primary btn-sm">
                Add service
            </a>
        @endif
    </div>

    @if($services->isEmpty())
        <div class="api-admin-hub__empty">
            No services are registered yet.
        </div>
    @else
        <div class="api-admin-hub__grid">
            @foreach($services as $service)
                @php
                    $status = $service['status'] ?? null;
                    $state = is_array($status) ? ($status['state'] ?? 'unknown') : 'unknown';
                    $stateClass = match ($state) {
                        'ok' => 'api-admin-hub-card__status--ok',
                        'degraded' => 'api-admin-hub-card__status--degraded',
                        'down' => 'api-admin-hub-card__status--down',
                        default => '',
                    };
                @endphp

                <a href="{{ $service['url'] }}" class="api-admin-hub-card">
                    <div class="api-admin-hub-card__top">
                        <div class="api-admin-hub-card__title">{{ $service['title'] }}</div>

                        @if(is_array($status))
                            <div class="api-admin-hub-card__status {{ $stateClass }}">
                                <span class="api-admin-hub-card__dot"></span>
                                <span>{{ $status['label'] }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="api-admin-hub-card__meta">
                        <span class="api-admin-hub-card__service">{{ $service['description'] }}</span>

                        @if(is_string($service['base_url'] ?? null) && $service['base_url'] !== '')
                            <span class="api-admin-hub-card__url">{{ $service['base_url'] }}</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</section>
