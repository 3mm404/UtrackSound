<?php

namespace App\Console\Commands;

use App\Models\Engine;
use Illuminate\Console\Command;

class IssueEngineToken extends Command
{
    protected $signature = 'engine:token {engine : ID del equipo}';

    protected $description = 'Emite una credencial nueva e invalida la anterior (se muestra una sola vez)';

    public function handle(): int
    {
        $engine = Engine::findOrFail($this->argument('engine'));
        $this->line($engine->issueToken());

        return self::SUCCESS;
    }
}
