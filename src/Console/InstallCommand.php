<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Puts the configuration and the instructions for AI agents into the project that installs the package.
 *
 * Inside `vendor/` no agent reads them on its own: this command brings them where they are read.
 *
 * @internal
 */
final class InstallCommand extends Command
{
    protected $signature = 'platform:install {--force : Sovrascrive i file già presenti}';

    protected $description = 'Pubblica la configurazione del connettore di I tuoi professionisti digitali e le istruzioni per gli agenti AI';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'platform-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->writeAgentRules($files);

        $this->components->info('Connettore di I tuoi professionisti digitali installato.');
        $this->components->bulletList([
            'Compila PLATFORM_URL, PLATFORM_CLIENT_ID, PLATFORM_CLIENT_SECRET e PLATFORM_SIGNING_SECRET nel .env',
            'Le credenziali le rilascia l\'operatore del portale e si vedono una volta sola',
            'Poi presenta il sistema con Platform::present(route(\'platform.webhook\'))',
        ]);

        return self::SUCCESS;
    }

    /**
     * Writes the rules where the project keeps them: `.ai/rules` when it exists, the root otherwise.
     */
    private function writeAgentRules(Filesystem $files): void
    {
        $destination = $files->isDirectory(base_path('.ai/rules'))
            ? base_path('.ai/rules/platform-connector.md')
            : base_path('AGENTS-platform-connector.md');

        if ($files->exists($destination) && !$this->option('force'))
        {
            $this->components->warn("Regole già presenti in {$destination}, lasciate invariate.");

            return;
        }

        $files->put($destination, $files->get(__DIR__.'/../../AGENTS.md'));

        $this->components->info("Regole per gli agenti scritte in {$destination}.");
    }
}
