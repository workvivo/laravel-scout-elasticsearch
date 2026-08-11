<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Product;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\Console\Commands\ImportCommand;
use Matchish\ScoutElasticSearch\ImportLock;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\IntegrationTestCase;

/**
 * `scout:import --probe` at the command level.
 *
 * An operator staring at a table with millions of rows wants two answers in
 * seconds — how long is one chunk, and is there an N+1 in toSearchableArray() —
 * and `--probe` is a report, not an import: it measures a few chunks against a
 * throwaway index, prints the numbers, deletes the index and exits. So the
 * command-level contract is mostly about what it does NOT do: no job, no lock,
 * no document in the live index, and no failing exit code just because the
 * diagnosis was ugly.
 *
 * The exit code is the part worth being pedantic about. SUCCESS means "the probe
 * ran", however alarming the findings; FAILURE is reserved for a probe that could
 * not run at all. An operator who asked "how slow is this?" must never be handed
 * the answer as a non-zero status their CI then treats as a broken deploy.
 *
 * Durations are never asserted (they depend on the machine and the cluster) —
 * only the structure of the report, the plan arithmetic, and the one finding that
 * can be made deterministic through {@see ProbeLazyProduct}.
 */
final class ProbeCommandTest extends IntegrationTestCase
{
    /** Chunk size is 3 (see TestCase), so 5 rows plan as 3 + 2. */
    private const PRODUCTS = 5;

    public function setUp(): void
    {
        parent::setUp();

        // The command hands the probe SCOUT_QUEUE_TIMEOUT as the chunk timeout,
        // and it is what decides whether the timeout rules in ProfileDiagnostics
        // can fire. Pinned to unset so the printed findings cannot depend on how
        // fast this machine is.
        config()->set('elasticsearch.queue.timeout', null);
        // Named explicitly because two tests below turn on the --parallel gates,
        // and those gates are about the resolved connection.
        $this->app['config']->set('queue.default', 'sync');
    }

    /**
     * @test
     */
    public function probe_reports_a_sample_of_chunks_and_imports_nothing(): void
    {
        $this->products(self::PRODUCTS);
        $this->importForReal();
        $liveBefore = $this->indexedCount('products');
        $this->assertSame(self::PRODUCTS, $liveBefore);

        Bus::fake();

        $output = new BufferedOutput();
        $exitCode = $this->probe([], $output);
        $text = $output->fetch();

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        Bus::assertNothingDispatched();

        // The header names the model, the sample, the whole plan and the
        // throwaway index, and says out loud that nothing is being imported.
        $this->assertStringContainsString('Probing [App\Product]', $text);
        $this->assertStringContainsString('measuring 2 of 2 chunk(s)', $text);
        $this->assertStringContainsString('throwaway index [products_probe_', $text);

        // The per-sample table: one row per measured chunk, with the breakdown
        // that separates a slow database from a slow cluster.
        foreach (['Chunk', 'Fetched', 'Indexed', 'Fetch ms', 'Filter ms', 'Index ms', 'Total ms', 'Payload KB'] as $header) {
            $this->assertStringContainsString($header, $text);
        }

        // Then the aggregates and the extrapolation, labelled as an estimate
        // from a sample rather than as a measurement of the whole import.
        $this->assertStringContainsString('Measured 2 chunk(s) of [App\Product]', $text);
        $this->assertStringContainsString('ESTIMATE from 2 sampled chunk(s), not a measurement', $text);
        $this->assertStringContainsString('on one worker', $text);

        // The index name is printed when it is created AND when it is deleted,
        // so an interrupted probe leaves a name an operator can act on — and
        // when it is not interrupted, the trail ends with the deletion.
        $index = $this->probeIndexNameIn($text);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($text, $index),
            'The probe index has to be named both on the way in and on the way out.'
        );
        $this->assertStringContainsString('Probe index ['.$index.'] deleted', $text);
        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => $index]));
        $this->assertSame([], $this->probeIndices());

        // Nothing was imported: the live index is untouched.
        $this->assertSame($liveBefore, $this->indexedCount('products'));

        // Everything reached the terminal as a sentence: no lang key and no
        // unreplaced placeholder.
        $this->assertStringNotContainsString('scout::import.', $text);
        $this->assertStringNotContainsString(':index', $text);
        $this->assertStringNotContainsString(':searchable', $text);
        $this->assertStringNotContainsString(':total', $text);
    }

    /**
     * @test
     */
    public function probe_needs_no_parallel_and_is_not_stopped_by_its_gates(): void
    {
        Bus::fake();
        $this->products(self::PRODUCTS);

        // Control: this configuration really does stop a --parallel import, so
        // the next assertion is not vacuous.
        $control = new BufferedOutput();
        $this->assertSame(
            ImportCommand::FAILURE,
            Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true], $control)
        );
        $this->assertStringContainsString('needs an asynchronous queue', $control->fetch());

        // A probe dispatches nothing, so no question about the queue can be
        // relevant to it: the same flags now produce a report instead of an
        // abort.
        $withParallel = new BufferedOutput();
        $this->assertSame(ImportCommand::SUCCESS, $this->probe(['--parallel' => true], $withParallel));
        $this->assertStringContainsString('Total ms', $withParallel->fetch());

        // And --parallel is not required either: probing a sequential import is
        // just as useful, so its absence must not even draw a warning.
        $sequential = new BufferedOutput();
        $this->assertSame(ImportCommand::SUCCESS, $this->probe([], $sequential));
        $text = $sequential->fetch();
        $this->assertStringContainsString('ESTIMATE from', $text);
        $this->assertStringNotContainsString('--parallel', $text);

        Bus::assertNothingDispatched();
        $this->assertSame([], $this->probeIndices());

        // Two probes in, and the lease has never been taken: the probe sits
        // before the lock in import() precisely so it can neither block a real
        // import nor be blocked by one.
        $this->assertFalse(
            $this->app['cache']->has(ImportLock::keyFor('products')),
            'A probe must not acquire (or leave behind) the import lease.'
        );
    }

    /**
     * @test
     */
    public function findings_are_printed_with_their_remedy_and_do_not_change_the_exit_code(): void
    {
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('scout:import', [
            'searchable' => [ProbeLazyProduct::class],
            '--probe' => true,
        ], $output);
        $text = $output->fetch();

        $this->assertSame(
            ImportCommand::SUCCESS,
            $exitCode,
            'A finding is a diagnosis, not an outcome: the probe ran, so it exits 0.'
        );

        // The same sentences the --wait renderer prints for the same code — the
        // diagnosis must read identically whether a worker measured it during an
        // import or a probe measured it here.
        $this->assertStringContainsString('N+1 while indexing', $text);
        $this->assertStringContainsString(ProbeLazyProduct::class.'::variants was lazy-loaded 3 times', $text);
        $this->assertStringContainsString('makeAllSearchableUsing', $text);

        // Deduped, with how many of the sampled chunks hit it.
        $this->assertStringContainsString('2 of 2 sampled chunk(s)', $text);
        $this->assertSame(
            1,
            substr_count($text, 'N+1 while indexing'),
            'Two chunks tripping the same rule is one line, not one per chunk.'
        );
        $this->assertStringContainsString('diagnostics only', $text);

        $this->assertStringNotContainsString('scout::import.', $text);
        $this->assertStringNotContainsString(':loads', $text);
        $this->assertStringNotContainsString(':relation', $text);

        $this->assertSame([], $this->probeIndices(), 'A probe that found something still cleans up after itself.');
    }

    /**
     * @test
     */
    public function an_invalid_probe_samples_warns_and_falls_back_to_the_default(): void
    {
        $this->products(self::PRODUCTS);

        foreach (['0', 'abc', '-3'] as $value) {
            $output = new BufferedOutput();
            $exitCode = $this->probe(['--probe-samples' => $value], $output);
            $text = $output->fetch();

            // A mistyped DIAGNOSTIC knob must never abort, and must never
            // silently measure something other than what was asked for.
            $this->assertSame(ImportCommand::SUCCESS, $exitCode, 'A bad --probe-samples is a warning, not a failure.');
            $this->assertStringContainsString('--probe-samples option must be a positive integer', $text);
            $this->assertStringContainsString('measures 3 chunks', $text);

            // Fell back to the default rather than to zero samples: the plan is
            // 2 chunks, so the default of 3 measures both.
            $this->assertStringContainsString('measuring 2 of 2 chunk(s)', $text);
            $this->assertStringContainsString('Measured 2 chunk(s)', $text);
        }
    }

    /**
     * @test
     */
    public function an_invalid_probe_workers_warns_and_the_estimate_assumes_one_worker(): void
    {
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = $this->probe(['--probe-workers' => '-2'], $output);
        $text = $output->fetch();

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('--probe-workers option must be a positive integer', $text);
        $this->assertStringContainsString('assumes 1 worker', $text);
        $this->assertStringContainsString('across 1 worker(s)', $text);
    }

    /**
     * @test
     */
    public function probe_workers_divides_the_estimate_across_the_stated_workers(): void
    {
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = $this->probe(['--probe-workers' => '4'], $output);
        $text = $output->fetch();

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        // Both halves of the same sentence: the serial cost the probe measured,
        // and the operator's own assumption applied to it.
        $this->assertStringContainsString('on one worker', $text);
        $this->assertStringContainsString('across 4 worker(s)', $text);
    }

    /**
     * @test
     */
    public function probe_honours_chunk_so_the_measured_shape_is_the_shape_of_the_real_import(): void
    {
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = $this->probe(['--chunk' => '2'], $output);
        $text = $output->fetch();

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        // 5 rows at --chunk=2 plan as 3 chunks of 2, not the 2 chunks of 3 the
        // configured chunk size would give: the probe measures what the operator
        // is actually going to run.
        $this->assertStringContainsString('measuring 3 of 3 chunk(s) (~2 rows each)', $text);
        $this->assertStringContainsString('Measured 3 chunk(s)', $text);
        $this->assertStringContainsString('3 chunks x', $text);
    }

    /**
     * @test
     */
    public function a_probe_that_cannot_create_its_index_fails_and_leaves_nothing_behind(): void
    {
        Bus::fake();
        // A mapping the cluster refuses: the probe has nowhere safe to write, and
        // writing anywhere else — the live index the alias points at — is the one
        // thing it must never do. That is a FAILED PROBE, the only kind.
        $this->app['config']->set('elasticsearch.indices.mappings.products', [
            'properties' => ['price' => ['type' => 'no_such_type']],
        ]);
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = $this->probe([], $output);
        $text = $output->fetch();

        $this->assertSame(ImportCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('Could not probe [App\Product]', $text);
        $this->assertStringNotContainsString('Total ms', $text, 'Nothing was measured, so no table may be printed.');

        Bus::assertNothingDispatched();
        $this->assertSame([], $this->probeIndices());
    }

    /**
     * @test
     */
    public function an_empty_plan_is_reported_and_still_succeeds(): void
    {
        Bus::fake();

        $output = new BufferedOutput();
        $exitCode = $this->probe([], $output);
        $text = $output->fetch();

        // Nothing to measure is not a failure, and it must not create an index
        // just to delete it again.
        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('has nothing to probe', $text);
        $this->assertStringNotContainsString('Total ms', $text);
        $this->assertStringNotContainsString('ESTIMATE from', $text);

        Bus::assertNothingDispatched();
        $this->assertSame([], $this->probeIndices());
    }

    // ---- harness -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $options
     */
    private function probe(array $options, BufferedOutput $output): int
    {
        return Artisan::call(
            'scout:import',
            array_merge(['searchable' => [Product::class], '--probe' => true], $options),
            $output
        );
    }

    private function products(int $amount): void
    {
        // Model events off, or Scout indexes each row on create and the live
        // document count would no longer be the import's alone.
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        factory(Product::class, $amount)->create();

        Product::setEventDispatcher($dispatcher);
    }

    /**
     * A full, ordinary import, so there is a live index behind the `products`
     * alias for the probe to leave alone.
     */
    private function importForReal(): void
    {
        Artisan::call('scout:import', ['searchable' => [Product::class]]);
    }

    /**
     * The probe index name as the command printed it — the operator's only
     * handle on a leftover index, so the test reads it the same way they would.
     */
    private function probeIndexNameIn(string $text): string
    {
        $this->assertSame(1, preg_match('/products_probe_\d+_[a-z0-9]{6}/', $text, $matches));

        return $matches[0];
    }

    /**
     * @return array<int, string>
     */
    private function probeIndices(): array
    {
        $names = [];

        foreach ($this->elasticsearch->cat()->indices(['h' => 'index', 'format' => 'json']) as $row) {
            $name = $row['index'] ?? null;

            if (is_string($name) && strpos($name, '_probe_') !== false) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    private function indexedCount(string $index): int
    {
        $this->elasticsearch->indices()->refresh(['index' => $index]);

        $response = $this->elasticsearch->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);

        return (int) $response['hits']['total']['value'];
    }
}

/**
 * Test-only Product variant that lazy-loads a relation while building its
 * document, so `--probe` has an N+1 to find.
 *
 * Kept out of tests/laravel/app so SearchableListFactory never discovers it as a
 * searchable of its own, and pinned to the `products` table so it keeps
 * Product's searchableAs() (and therefore the index names the suite already
 * cleans up). The relation is a self-join on the unique per-row custom_key: one
 * lazy-loading violation per model, so the hit count equals the chunk size.
 */
class ProbeLazyProduct extends Product
{
    protected $table = 'products';

    /**
     * @return HasMany<Product>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'custom_key', 'custom_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            // Not eager-loaded anywhere, so reading it here is the violation.
            'variants' => $this->variants->count(),
        ];
    }
}
