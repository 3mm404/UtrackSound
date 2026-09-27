<div class="space-y-4">
    <p>Resultados enviados por el equipo. Enviar una orden no confirma su ejecución. Cierra y vuelve a abrir para actualizar.</p>
    @forelse ($commands as $command)
        <div>
            <strong>#{{ $command->sequence }} · {{ $command->action }} · {{ $command->statusLabel() }}</strong>
            <p>{{ $command->created_at }} · Canción: {{ $command->song_id ?? 'Selección actual' }}</p>
            @if ($command->result['error'] ?? null)
                <p>{{ $command->result['error']['code'] }}: {{ $command->result['error']['message'] }}</p>
            @endif
        </div>
    @empty
        <p>No hay órdenes para esta zona y equipo.</p>
    @endforelse
</div>
