@php
    /** @var \App\Models\Zone $record */

    $reportedZone = collect(
        $record->engine?->observed_state['zones'] ?? []
    )->firstWhere(
        'zone_id',
        (string) $record->id
    );

    $state = $reportedZone['state'] ?? 'stopped';

    [$stateLabel, $stateClasses] = match ($state) {
        'playing' => ['Reproduciendo', 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300'],
        'paused' => ['En pausa', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300'],
        'loading' => ['Cargando', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300'],
        'recovering' => ['Recuperando', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300'],
        'error' => ['Error', 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300'],
        default => ['Detenida', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
    };

    $songId = $reportedZone['song_id'] ?? null;

    $reportedVolume = $reportedZone['volume'] ?? null;

    $song = $record
        ->playlist
        ?->songs
        ->firstWhere('id', $songId);

    $online = $record->engine?->isOnline() ?? false;
    $canControl = \Illuminate\Support\Facades\Gate::allows('update', $record)
        && $record->engine !== null
        && \Illuminate\Support\Facades\Gate::allows('update', $record->engine);
    $canView = \Illuminate\Support\Facades\Gate::allows('view', $record);
    $canEdit = \Illuminate\Support\Facades\Gate::allows('update', $record);
    $canDelete = \Illuminate\Support\Facades\Gate::allows('delete', $record);

    $volume = $reportedVolume
        ?? $record->volume
        ?? 0;

    $volume = max(
        0,
        min(100, (int) $volume)
    );
@endphp


<div
    class="group flex min-h-[28rem] w-full max-w-96 flex-col rounded-2xl border border-gray-200/80 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-md sm:p-6 dark:border-white/10 dark:bg-gray-900 dark:hover:border-white/20"
>

    {{-- HEADER --}}
    <div class="flex items-start justify-between gap-4">

        <div>

            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ $record->name }}
            </h2>

            <p
                class="
                    mt-1
                    text-sm
                    text-gray-500
                    dark:text-gray-400
                "
            >
                {{ $record->engine?->name ?? 'Sin equipo asignado' }}
            </p>

        </div>


        <div class="flex shrink-0 items-center gap-2">
            <span @class([
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $online,
                'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300' => ! $online,
            ])>
                <span @class([
                    'h-1.5 w-1.5 rounded-full',
                    'bg-success-500' => $online,
                    'bg-danger-500' => ! $online,
                ]) aria-hidden="true"></span>
                {{ $online ? 'Online' : 'Offline' }}
            </span>

            @if ($canView || $canEdit || $canDelete)
                <x-filament::dropdown placement="bottom-end">
                    <x-slot name="trigger">
                        <button
                            type="button"
                            aria-label="Más acciones para {{ $record->name }}"
                            title="Más acciones"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-5 w-5" />
                        </button>
                    </x-slot>

                    <div class="min-w-40 space-y-1 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
                        @if ($canView)
                            <button type="button" wire:click="mountTableAction('view', '{{ $record->getKey() }}')" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                                Ver
                            </button>
                            <button type="button" wire:click="mountTableAction('history', '{{ $record->getKey() }}')" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-4 w-4" />
                                Órdenes
                            </button>
                        @endif

                        @if ($canEdit)
                            <button type="button" wire:click="mountTableAction('edit', '{{ $record->getKey() }}')" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                Editar
                            </button>
                        @endif

                        @if ($canDelete)
                            <button type="button" wire:click="mountTableAction('delete', '{{ $record->getKey() }}')" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-danger-600 hover:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-500/10">
                                <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                Eliminar
                            </button>
                        @endif
                    </div>
                </x-filament::dropdown>
            @endif
        </div>

    </div>


    {{-- CANCIÓN --}}
    <div class="mt-7 border-y border-gray-100 py-8 text-center dark:border-white/10">

        <p
            class="
                text-xs
                font-medium
                uppercase
                text-gray-500
                dark:text-gray-400
            "
        >
            AHORA SUENA
        </p>


        <h3
            class="mt-3 break-words text-2xl font-bold text-gray-950 dark:text-white"
        >
            {{ $song?->title ?? 'Sin canción' }}
        </h3>


        <p
            class="mt-1.5 text-sm text-gray-500 dark:text-gray-400"
        >
            {{ $song?->artist ?? '—' }}
        </p>

    </div>


    {{-- CONTROLES --}}
    @if ($canControl)
    <div
        class="
            mt-6
            flex
            items-center
            justify-center
            gap-4
        "
    >

        {{-- ANTERIOR --}}
        <button
            type="button"
            aria-label="Anterior"
            title="Anterior"
            wire:click="mountTableAction('previous', '{{ $record->getKey() }}')"
            class="flex h-11 w-11 items-center justify-center rounded-full text-gray-600 transition hover:bg-gray-100 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
        >
            <x-filament::icon icon="heroicon-o-backward" class="h-5 w-5" />
        </button>


        {{-- PLAY / RESUME --}}
        @php
            $playAction = match ($state) {
                'playing' => 'pause',
                'paused' => 'resume',
                default => 'play',
            };
            $playIcon = $state === 'playing' ? 'heroicon-o-pause' : 'heroicon-o-play';
            $playLabel = $state === 'playing' ? 'Pausar' : ($state === 'paused' ? 'Reanudar' : 'Reproducir');
        @endphp

        <button
            type="button"
            aria-label="{{ $playLabel }}"
            title="{{ $playLabel }}"
            wire:click="mountTableAction('{{ $playAction }}', '{{ $record->getKey() }}')"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-600 text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:ring-offset-gray-900"
        >
            <x-filament::icon :icon="$playIcon" class="h-6 w-6" />
        </button>


        {{-- STOP --}}
        <button
            type="button"
            aria-label="Detener"
            title="Detener"
            wire:click="mountTableAction('stop', '{{ $record->getKey() }}')"
            class="flex h-11 w-11 items-center justify-center rounded-full text-gray-600 transition hover:bg-gray-100 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
        >
            <x-filament::icon icon="heroicon-o-stop" class="h-5 w-5" />
        </button>


        {{-- SIGUIENTE --}}
        <button
            type="button"
            aria-label="Siguiente"
            title="Siguiente"
            wire:click="mountTableAction('next', '{{ $record->getKey() }}')"
            class="flex h-11 w-11 items-center justify-center rounded-full text-gray-600 transition hover:bg-gray-100 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
        >
            <x-filament::icon icon="heroicon-o-forward" class="h-5 w-5" />
        </button>

    </div>
    @endif


    {{-- PLAYLIST --}}
    <div class="mt-6 flex items-center justify-between gap-4 border-b border-gray-100 pb-4 dark:border-white/10">
        <span class="text-sm text-gray-500 dark:text-gray-400">Playlist</span>
        <span class="truncate text-right text-sm font-semibold text-gray-950 dark:text-white">
            {{ $record->playlist?->name ?? 'Sin playlist' }}
        </span>
    </div>


    {{-- VOLUMEN --}}
    <div class="mt-5">

        <div
            class="
                flex
                items-center
                justify-between
            "
        >

            <span
                class="
                    text-sm
                    text-gray-500
                    dark:text-gray-400
                "
            >
                Volumen
            </span>

            <span
                class="
                    text-sm
                    font-semibold
                    text-gray-950
                    dark:text-white
                "
            >
                {{ $volume }}%
            </span>

        </div>


        <div
            class="mt-2 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
        >

            <div
                class="h-full rounded-full bg-primary-500 transition-all duration-300"
                style="width: {{ $volume }}%"
            ></div>

        </div>

    </div>


    {{-- ESTADO --}}
    <div class="mt-5">
        <span @class([
            'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold',
            $stateClasses,
        ])>
            <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
            {{ $stateLabel }}
        </span>
    </div>


    {{-- FOOTER --}}
    <div
        class="mt-auto flex items-center justify-between gap-4 border-t border-gray-200 pt-4 dark:border-white/10"
    >

        <div class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-300">

            @if ($online)

                <span class="h-1.5 w-1.5 rounded-full bg-success-500" aria-hidden="true"></span>
                <span>
                    Reporte actual
                </span>

            @else

                <span class="h-1.5 w-1.5 rounded-full bg-warning-500" aria-hidden="true"></span>
                <span>
                    Reporte desactualizado
                </span>

            @endif

        </div>


        @if ($canControl)
            <button
                type="button"
                wire:click="mountTableAction('settings', '{{ $record->getKey() }}')"
                class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-primary-600 transition hover:bg-primary-50 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:hover:bg-primary-400/10 dark:hover:text-primary-300"
            >
                <x-filament::icon icon="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                Ajustes
            </button>
        @endif

    </div>

</div>