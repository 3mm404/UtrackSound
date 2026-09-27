@php
    $zones = $this->getTableRecords();
@endphp

<x-filament-panels::page>
    <div wire:poll.5s>
        <div class="zone-dashboard-grid">
            @forelse ($zones as $record)
                @include('filament.zones.zone-player-card', ['record' => $record])
            @empty
                <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">No hay zonas todavía</h2>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Crea una zona para empezar a controlar la reproducción.</p>
                </div>
            @endforelse
        </div>

        @if ($zones instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $zones->hasPages())
            <nav class="mt-6 flex items-center justify-center gap-4" aria-label="Paginación de zonas">
                <button
                    type="button"
                    wire:click="previousPage"
                    @disabled($zones->onFirstPage())
                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                >
                    Anterior
                </button>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Página {{ $zones->currentPage() }} de {{ $zones->lastPage() }}
                </span>
                <button
                    type="button"
                    wire:click="nextPage"
                    @disabled(! $zones->hasMorePages())
                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                >
                    Siguiente
                </button>
            </nav>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>