@php
    $input = $session?->input ?? [];
    $warnings = $session?->warnings ?? [];
    $proposal = $summary['proposal'] ?? [];
    $applyResult = $summary['apply_result'] ?? [];
@endphp

@once
    <style>
        .api-admin-instructor {
            display: grid;
            gap: 1rem;
        }

        .api-admin-instructor__layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 20rem;
            gap: 1rem;
            align-items: start;
        }

        .api-admin-instructor__stack {
            display: grid;
            gap: 1rem;
            min-width: 0;
        }

        .api-admin-instructor__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .api-admin-instructor__full {
            grid-column: 1 / -1;
        }

        .api-admin-instructor__actions {
            display: flex;
            justify-content: flex-end;
            gap: .75rem;
            margin-top: 1rem;
        }

        .api-admin-instructor__code {
            max-height: 22rem;
            margin: 0;
            overflow: auto;
            padding: .875rem;
            border-radius: .5rem;
            background: var(--ms-cm-base-950);
            color: var(--ms-cm-base-50);
            font-size: .75rem;
            line-height: 1.45;
        }

        .api-admin-instructor__list {
            display: grid;
            gap: .5rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .api-admin-instructor__list li,
        .api-admin-instructor__session {
            padding: .75rem;
            border: 1px solid var(--ms-cm-base-stroke);
            border-radius: .5rem;
            color: var(--ms-cm-base-700);
            font-size: .875rem;
            line-height: 1.4;
        }

        .api-admin-instructor__session {
            display: grid;
            gap: .75rem;
        }

        @media (max-width: 960px) {
            .api-admin-instructor__layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .api-admin-instructor__grid {
                grid-template-columns: 1fr;
            }

            .api-admin-instructor__actions {
                align-items: stretch;
                flex-direction: column;
            }

            .api-admin-instructor__actions .btn {
                justify-content: center;
                width: 100%;
            }
        }
    </style>
@endonce

<div class="api-admin-instructor">
    <div class="api-admin-instructor__stack">
        @if (session('status'))
            <x-moonshine::alert :removable="false" type="success">
                {{ session('status') }}
            </x-moonshine::alert>
        @endif

        @if (session('warning'))
            <x-moonshine::alert :removable="false" type="warning">
                {{ session('warning') }}
            </x-moonshine::alert>
        @endif

        @if ($errors->any())
            <x-moonshine::alert :removable="false" type="error">
                Check the form fields.
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-moonshine::alert>
        @endif
    </div>

    <div class="api-admin-instructor__layout">
        <div class="api-admin-instructor__stack">
            <x-moonshine::layout.box title="API discovery">
                <form method="post" action="{{ $startUrl }}">
                    @csrf

                    <div class="api-admin-instructor__grid">
                        <x-moonshine::form.wrapper class="api-admin-instructor__full" label="API base URL" required>
                            <x-moonshine::form.input id="base_url" name="base_url" type="url" value="{{ old('base_url', data_get($input, 'base_url', '')) }}" required />
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper label="Service name">
                            <x-moonshine::form.input id="service_name" name="service_name" value="{{ old('service_name', data_get($input, 'service_name', '')) }}" />
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper label="Initial resource name">
                            <x-moonshine::form.input id="resource_name" name="resource_name" value="{{ old('resource_name', data_get($input, 'resource_name', '')) }}" />
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper class="api-admin-instructor__full" label="Endpoint paths">
                            <x-moonshine::form.textarea id="endpoint_paths" name="endpoint_paths" placeholder="/users&#10;/posts">{{ old('endpoint_paths', data_get($input, 'endpoint_paths', '')) }}</x-moonshine::form.textarea>
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper label="Auth type">
                            <x-moonshine::form.select id="auth_type" name="auth_type" native>
                                <x-slot:options>
                                    @foreach (['none', 'bearer', 'header', 'query', 'basic'] as $authType)
                                        <option value="{{ $authType }}" @selected(old('auth_type', data_get($input, 'auth_type', 'none')) === $authType)>{{ $authType }}</option>
                                    @endforeach
                                </x-slot:options>
                            </x-moonshine::form.select>
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper label="Auth header">
                            <x-moonshine::form.input id="auth_header" name="auth_header" value="{{ old('auth_header', data_get($input, 'auth_header', 'Authorization')) }}" />
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper label="Token or credential">
                            <x-moonshine::form.input id="api_key" name="api_key" type="password" autocomplete="off" />
                        </x-moonshine::form.wrapper>

                        <x-moonshine::form.wrapper label="Health path">
                            <x-moonshine::form.input id="health_path" name="health_path" value="{{ old('health_path', data_get($input, 'health_path', '/health')) }}" />
                        </x-moonshine::form.wrapper>
                    </div>

                    <div class="api-admin-instructor__actions">
                        <button class="btn btn-primary" type="submit">Probe API</button>
                    </div>
                </form>
            </x-moonshine::layout.box>

            @if ($warnings !== [])
                <x-moonshine::layout.box title="Warnings">
                    <ul class="api-admin-instructor__list">
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </x-moonshine::layout.box>
            @endif

            @if ($proposal !== [])
                <x-moonshine::layout.box title="Review proposal">
                    <pre class="api-admin-instructor__code">{{ json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

                    <form method="post" action="{{ $confirmUrl }}">
                        @csrf
                        <x-moonshine::form.wrapper label="Token or credential for generation">
                            <x-moonshine::form.input id="confirm_api_key" name="api_key" type="password" autocomplete="off" />
                        </x-moonshine::form.wrapper>

                        <div class="api-admin-instructor__actions">
                            <button class="btn btn-primary" type="submit">Generate service and resource</button>
                        </div>
                    </form>
                </x-moonshine::layout.box>
            @endif

            @if ($applyResult !== [])
                <x-moonshine::layout.box title="Generation result">
                    <pre class="api-admin-instructor__code">{{ json_encode($applyResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

                    @if (is_string(data_get($applyResult, 'next_url')))
                        <div class="api-admin-instructor__actions">
                            <a class="btn btn-primary" href="{{ data_get($applyResult, 'next_url') }}">Open generated panel</a>
                        </div>
                    @endif
                </x-moonshine::layout.box>
            @endif
        </div>

        <aside class="api-admin-instructor__stack">
            <x-moonshine::layout.box title="Session">
                <div class="api-admin-instructor__session">
                    <div><strong>Step:</strong> {{ $session?->step ?? 'idle' }}</div>
                    <div>Use the probe result to review generated service and resource settings before files are created.</div>

                    <form method="post" action="{{ $resetUrl }}">
                        @csrf
                        <button class="btn" type="submit">Reset instructor state</button>
                    </form>
                </div>
            </x-moonshine::layout.box>
        </aside>
    </div>
</div>
