<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\Directives\Directive;
use Cbox\Telemetry\Support\Cast;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Record more of one slice of traffic for a while:
 *
 *     php artisan telemetry:watch --user=42 --minutes=120 --profile --reason="ticket 881"
 *     php artisan telemetry:watch --route="checkout.*" --minutes=15
 *     php artisan telemetry:watch --context=tenant.id=7
 *     php artisan telemetry:watch --list
 *     php artisan telemetry:watch --remove=<id>
 */
final class WatchCommand extends Command
{
    protected $signature = 'telemetry:watch
        {--route= : Route name or URI template (Str::is pattern)}
        {--path= : Request path (Str::is pattern)}
        {--user= : Authenticated user id}
        {--context= : A Telemetry::context() dimension, as key=value}
        {--job= : Queued job class (Str::is pattern)}
        {--minutes=60 : How long it lasts}
        {--profile : Also capture CPU profiles}
        {--reason= : Why, for whoever finds it later}
        {--list : Show the active directives}
        {--remove= : Remove a directive by id}
        {--clear : Remove every directive}';

    protected $description = 'Keep every trace (and optionally a profile) for one route, path, user, tenant or job, for a while';

    public function handle(TelemetryManager $telemetry): int
    {
        $store = $telemetry->directives();

        if ($this->option('clear')) {
            $store->clear();
            $this->components->info('All directives removed.');

            return self::SUCCESS;
        }

        if (is_string($id = $this->option('remove'))) {
            $removed = $store->remove($id);
            $removed ? $this->components->info("Removed {$id}.") : $this->components->error("No directive {$id}.");

            return $removed ? self::SUCCESS : self::FAILURE;
        }

        try {
            $directive = $this->directive();

            if ($directive === null) {
                $this->list($store->all());

                return self::SUCCESS;
            }

            $directive = $store->add(
                $directive->profile((bool) $this->option('profile'))
                    ->for(max(1, Cast::int($this->option('minutes'), 60)))
                    ->because(is_string($this->option('reason')) ? $this->option('reason') : null)
                    ->by('artisan'),
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Watching %s %s until %s (id %s).',
            $directive->match,
            $directive->key === null ? $directive->value : $directive->key.'='.$directive->value,
            date('Y-m-d H:i:s', $directive->expiresAt),
            $directive->id,
        ));

        return self::SUCCESS;
    }

    private function directive(): ?Directive
    {
        foreach (['route', 'path', 'user', 'job'] as $kind) {
            if (is_string($value = $this->option($kind)) && $value !== '') {
                return Directive::{$kind}($value);
            }
        }

        if (is_string($context = $this->option('context')) && str_contains($context, '=')) {
            [$key, $value] = explode('=', $context, 2);

            return Directive::context($key, $value);
        }

        return null;
    }

    /**
     * @param  list<Directive>  $directives
     */
    private function list(array $directives): void
    {
        if ($directives === []) {
            $this->components->info('No active directives.');

            return;
        }

        $this->table(['id', 'match', 'value', 'profile', 'expires', 'reason', 'by'], array_map(static fn (Directive $d): array => [
            $d->id,
            $d->match,
            $d->key === null ? $d->value : $d->key.'='.$d->value,
            $d->profile ? 'yes' : '',
            date('Y-m-d H:i:s', $d->expiresAt),
            $d->reason ?? '',
            $d->createdBy ?? '',
        ], $directives));
    }
}
