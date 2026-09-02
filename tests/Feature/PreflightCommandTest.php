<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\Console\Commands\ImportCommand;
use Matchish\ScoutElasticSearch\Import\QueueTimingPreflight;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullChunks;
use Matchish\ScoutElasticSearch\Jobs\StageJob;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\IntegrationTestCase;

/**
 * `scout:import --parallel` at the command level: the queue-timing pre-flight
 * has to abort a proven-fatal configuration BEFORE anything is planned,
 * dispatched or locked, and `--preflight` has to report without importing at
 * all.
 *
 * The probe seam is bound through the container, so none of this touches AWS.
 */
final class PreflightCommandTest extends IntegrationTestCase
{
    /**
     * An asynchronous SQS connection as the app default, with scout.queue off —
     * the real-world shape of the setup that broke. Only the driver name matters:
     * the queue is never contacted, because the probe is faked and a fatal
     * pre-flight returns before dispatch.
     */
    private function useSqsDefaultQueue(): void
    {
        $this->app['config']->set('scout.queue', false);
        $this->app['config']->set('queue.connections.sqs_test', [
            'driver' => 'sqs',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/1234567890',
            'queue' => 'default',
            'region' => 'us-east-1',
        ]);
        $this->app['config']->set('queue.default', 'sqs_test');
    }

    /**
     * The PERF incident's configuration: AWS's default 30s VisibilityTimeout and
     * no SCOUT_QUEUE_TIMEOUT, so the worker's --timeout (assumed 60s) governs and
     * every slow chunk is re-delivered while it is still running.
     */
    private function useFatalQueueTiming(): void
    {
        $this->useSqsDefaultQueue();
        $this->app['config']->set('elasticsearch.queue.timeout', null);
        $this->bindProbe(['visibility_timeout' => 30]);
    }

    /**
     * A correctly ordered queue: a 900s window under a 300s job alarm, with a
     * dead-letter target, so the pre-flight has nothing at all to report.
     */
    private function useHealthyQueueTiming(): void
    {
        $this->useSqsDefaultQueue();
        $this->app['config']->set('elasticsearch.queue.timeout', 300);
        $this->bindProbe([
            'visibility_timeout' => 900,
            'max_receive_count' => 5,
            'dead_letter' => 'arn:aws:sqs:us-east-1:1234567890:reindex-dlq',
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bindProbe(array $result): void
    {
        $this->app->bind(QueueTimingPreflight::class, function () use ($result) {
            return new QueueTimingPreflight(function (?string $connection, ?string $queue) use ($result) {
                return $result;
            });
        });
    }

    /**
     * A probe that counts its invocations, for the cases where the check is
     * supposed to be skipped outright.
     */
    private function bindCountingProbe(?int &$calls): void
    {
        $calls = 0;

        $this->app->bind(QueueTimingPreflight::class, function () use (&$calls) {
            return new QueueTimingPreflight(function (?string $connection, ?string $queue) use (&$calls): array {
                $calls++;

                return ['visibility_timeout' => 30];
            });
        });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runImport(array $options, BufferedOutput $output): int
    {
        return Artisan::call(
            'scout:import',
            array_merge(['searchable' => [Product::class]], $options),
            $output
        );
    }

    /**
     * @test
     */
    public function preflight_dry_run_on_a_fatal_configuration_fails_and_imports_nothing(): void
    {
        Bus::fake();
        $this->useFatalQueueTiming();

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true, '--preflight' => true], $output);

        $this->assertEquals(ImportCommand::FAILURE, $exitCode);
        Bus::assertNothingDispatched();

        $text = $output->fetch();
        // The report names the resolved connection, shows the probed number that
        // proves the problem, and refuses to continue.
        $this->assertStringContainsString('connection [sqs_test]', $text);
        $this->assertStringContainsString('VisibilityTimeout', $text);
        $this->assertStringContainsString('SCOUT_QUEUE_TIMEOUT', $text);
        $this->assertStringContainsString('pre-flight failed', $text);
        $this->assertStringNotContainsString('Import job dispatched', $text);
    }

    /**
     * @test
     */
    public function preflight_dry_run_on_a_healthy_configuration_passes_and_still_imports_nothing(): void
    {
        Bus::fake();
        $this->useHealthyQueueTiming();

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true, '--preflight' => true], $output);

        // A dry run is a report, not an import: even a clean one dispatches
        // nothing and takes no lock.
        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        Bus::assertNothingDispatched();

        $text = $output->fetch();
        $this->assertStringContainsString('pre-flight passed', $text);
        // Provenance is visible in the table, so the operator can tell the
        // probed facts from the assumed defaults.
        $this->assertStringContainsString('probed', $text);
        $this->assertStringContainsString('900s', $text);
    }

    /**
     * @test
     */
    public function parallel_aborts_on_a_fatal_configuration_before_dispatching_anything(): void
    {
        Bus::fake();
        $this->useFatalQueueTiming();

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true], $output);

        $this->assertEquals(ImportCommand::FAILURE, $exitCode);
        Bus::assertNothingDispatched();
        $this->assertStringContainsString('pre-flight failed', $output->fetch());
    }

    /**
     * --force is the existing escape hatch (it already waives the sync-connection
     * abort), so it waives this too — but never silently.
     *
     * @test
     */
    public function force_waives_the_check_with_a_warning_and_proceeds(): void
    {
        Bus::fake();
        $this->useFatalQueueTiming();

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true, '--force' => true], $output);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        Bus::assertChained([
            StageJob::class,
            StageJob::class,
            DispatchPullChunks::class,
        ]);

        $text = $output->fetch();
        $this->assertStringContainsString('pre-flight skipped', $text);
        $this->assertStringNotContainsString('pre-flight failed', $text);
    }

    /**
     * --preflight --force still reports (that is what --preflight asks for) but
     * downgrades the fatals, so the dry run exits successfully.
     *
     * @test
     */
    public function preflight_with_force_reports_the_fatals_as_warnings_and_exits_successfully(): void
    {
        Bus::fake();
        $this->useFatalQueueTiming();

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true, '--preflight' => true, '--force' => true], $output);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        Bus::assertNothingDispatched();

        $text = $output->fetch();
        $this->assertStringContainsString('VisibilityTimeout', $text);
        $this->assertStringContainsString('--force was passed', $text);
        $this->assertStringNotContainsString('pre-flight failed', $text);
    }

    /**
     * @test
     */
    public function preflight_without_parallel_warns_and_is_ignored(): void
    {
        // The sequential path dispatches its own job; faking the bus keeps this
        // test about the flag handling rather than about the import.
        Bus::fake();
        $this->useFatalQueueTiming();

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--preflight' => true], $output);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);

        $text = $output->fetch();
        $this->assertStringContainsString('only applies to --parallel', $text);
        // Ignored means ignored: no report, and no abort on a configuration that
        // would be fatal for a --parallel run.
        $this->assertStringNotContainsString('Queue timing pre-flight for connection', $text);
        $this->assertStringNotContainsString('pre-flight failed', $text);
    }

    /**
     * @test
     */
    public function the_master_switch_skips_the_check_entirely(): void
    {
        Bus::fake();
        $this->useSqsDefaultQueue();
        $this->app['config']->set('elasticsearch.queue.timeout', null);
        $this->app['config']->set('elasticsearch.import.preflight.enabled', false);

        $calls = 0;
        $this->bindCountingProbe($calls);

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true], $output);

        // Same fatal configuration as above, but the check is off: the import
        // runs exactly as it did before this pre-flight existed.
        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        $this->assertSame(0, $calls, 'A disabled pre-flight must not probe the queue.');
        Bus::assertChained([
            StageJob::class,
            StageJob::class,
            DispatchPullChunks::class,
        ]);

        $text = $output->fetch();
        $this->assertStringNotContainsString('Queue timing pre-flight for connection', $text);
        $this->assertStringNotContainsString('pre-flight failed', $text);
    }

    /**
     * Being asked for the report is not the same as being checked: a dry run
     * still prints the resolved settings when the switch is off, and says so.
     *
     * @test
     */
    public function a_disabled_preflight_dry_run_still_reports_the_settings(): void
    {
        Bus::fake();
        $this->useSqsDefaultQueue();
        $this->app['config']->set('elasticsearch.queue.timeout', null);
        $this->app['config']->set('elasticsearch.import.preflight.enabled', false);
        $this->bindProbe(['visibility_timeout' => 30]);

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true, '--preflight' => true], $output);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        Bus::assertNothingDispatched();

        $text = $output->fetch();
        $this->assertStringContainsString('connection [sqs_test]', $text);
        $this->assertStringContainsString('pre-flight is disabled', $text);
        // Nothing was checked, so nothing is claimed either way.
        $this->assertStringNotContainsString('pre-flight failed', $text);
        $this->assertStringNotContainsString('pre-flight passed', $text);
    }

    /**
     * A probe failure (absent SDK, IAM denial, network) proves nothing, so it
     * warns and the import proceeds — the check must never break a setup that
     * works today.
     *
     * @test
     */
    public function a_failed_probe_warns_but_still_imports(): void
    {
        Bus::fake();
        $this->useSqsDefaultQueue();
        $this->app['config']->set('elasticsearch.queue.timeout', 300);
        $this->app->bind(QueueTimingPreflight::class, function () {
            return new QueueTimingPreflight(function (?string $connection, ?string $queue): array {
                throw new RuntimeException('AccessDenied: sqs:GetQueueAttributes');
            });
        });

        $output = new BufferedOutput();
        $exitCode = $this->runImport(['--parallel' => true], $output);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        Bus::assertChained([
            StageJob::class,
            StageJob::class,
            DispatchPullChunks::class,
        ]);

        $text = $output->fetch();
        $this->assertStringContainsString('AccessDenied: sqs:GetQueueAttributes', $text);
        $this->assertStringContainsString('unverified', $text);
        $this->assertStringNotContainsString('pre-flight failed', $text);
    }
}
