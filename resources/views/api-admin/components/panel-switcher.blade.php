@once
    <style>
        .api-admin-panel-switcher {
            width: 100%;
            padding-block: .5rem;
        }

        .api-admin-panel-switcher .dropdown,
        .api-admin-panel-switcher .dropdown-btn {
            width: 100%;
        }

        .api-admin-panel-switcher .dropdown-btn {
            min-width: 0;
        }

        .api-admin-panel-switcher__title,
        .api-admin-panel-switcher__item-title {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .api-admin-panel-switcher__title {
            flex: 1;
            text-align: left;
        }

        .api-admin-panel-switcher .dropdown-body,
        .api-admin-panel-switcher .dropdown-content {
            width: min(256px, calc(100vw - 1rem));
            min-width: min(256px, calc(100vw - 1rem));
            max-width: min(256px, calc(100vw - 1rem));
        }

        @media (min-width: 1024px) {
            .layout-menu._is-minimized .api-admin-panel-switcher {
                display: flex;
                justify-content: center;
            }

            .layout-menu._is-minimized .api-admin-panel-switcher .dropdown,
            .layout-menu._is-minimized .api-admin-panel-switcher .dropdown-btn {
                width: auto;
            }

            .layout-menu._is-minimized .api-admin-panel-switcher .dropdown-body,
            .layout-menu._is-minimized .api-admin-panel-switcher .dropdown-content {
                width: min(256px, calc(100vw - 1rem));
                min-width: min(256px, calc(100vw - 1rem));
                max-width: min(256px, calc(100vw - 1rem));
            }

            .layout-menu._is-minimized .api-admin-panel-switcher .dropdown-btn {
                justify-content: center;
                padding-inline: .75rem;
            }

            .layout-menu._is-minimized .api-admin-panel-switcher__title,
            .layout-menu._is-minimized .api-admin-panel-switcher__chevron {
                display: none;
            }
        }
    </style>
@endonce

<div class="api-admin-panel-switcher">
    <x-moonshine::dropdown
        title="Services"
        :searchable="count($items) > 8"
        search-placeholder="Search service..."
        strategy="fixed"
    >
        <x-slot:toggler class="btn btn-primary btn-sm w-full justify-start gap-2">
            <x-moonshine::icon icon="server" />
            <span class="api-admin-panel-switcher__title">{{ $title }}</span>
            <x-moonshine::icon icon="chevron-down" class="api-admin-panel-switcher__chevron ml-auto" />
        </x-slot:toggler>

        <ul class="dropdown-menu">
            @foreach($items as $item)
                <li class="dropdown-menu-item">
                    <a
                        href="{{ $item['href'] }}"
                        class="dropdown-menu-link flex w-full items-center justify-between gap-2"
                    >
                        <span class="api-admin-panel-switcher__item-title">{{ $item['title'] }}</span>
                        @if($item['active'])
                            <x-moonshine::icon icon="check" class="text-primary" />
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </x-moonshine::dropdown>
</div>
