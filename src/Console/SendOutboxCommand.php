<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Enums\OutboxStatus;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformProfessional;
use ITuoiProfessionistiDigitali\Connector\Jobs\SendPlatformTransaction;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Models\PlatformTransactionOutbox;

/**
 * Sends again the versions left behind: a system not yet active, a platform down for longer than the retries,
 * a declaration of a record waiting for the registration of the transactions to the person.
 * Scheduled every five minutes by the service provider.
 *
 * @internal
 */
final class SendOutboxCommand extends Command
{
    protected $signature = 'platform:send-outbox';

    protected $description = 'Rimanda al portale le versioni delle transazioni e le anagrafiche non ancora confermate';

    public function handle(ConnectorConfig $config): int
    {
        if (!$config->isConnected())
        {
            $this->components->warn('Il sistema non è collegato al portale.');

            return self::SUCCESS;
        }

        $references = PlatformTransactionOutbox::query()
            ->where('status', OutboxStatus::Pending)
            ->where(fn (Builder $query): Builder => $query->whereNull('sent_revision')->orWhereColumn('sent_revision', '!=', 'revision'))
            ->pluck('reference');
        $this->requeue($references, static fn (string $reference): SendPlatformTransaction => new SendPlatformTransaction($reference), $config->queue);
        $this->components->info("Transazioni rimesse in coda: {$references->count()}.");

        $taxCodes = PlatformProfessionalOutbox::query()->where('status', OutboxStatus::Pending)->pluck('tax_code');
        $this->requeue($taxCodes, static fn (string $taxCode): SendPlatformProfessional => new SendPlatformProfessional($taxCode), $config->queue);
        $this->components->info("Anagrafiche rimesse in coda: {$taxCodes->count()}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<array-key, mixed>  $keys
     * @param  Closure(string): object  $job
     */
    private function requeue(Collection $keys, Closure $job, ?string $queue): void
    {
        foreach ($keys as $key)
        {
            if (is_string($key))
            {
                dispatch($job($key))->onQueue($queue);
            }
        }
    }
}
