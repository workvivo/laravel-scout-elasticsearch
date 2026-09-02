<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;
use Matchish\ScoutElasticSearch\Import\QueueTimingPreflight;
use Matchish\ScoutElasticSearch\ScoutElasticSearchServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RuntimeException;

/**
 * The queue-timing pre-flight, driven entirely through its injected probe seam:
 * no AWS SDK, no network, no cluster.
 *
 * The invariant under test is
 *
 *     p99 chunk << effective job timeout < redelivery window < shutdown grace
 *
 * and the policy under test is deliberately narrow: only a PROBED
 * VisibilityTimeout that sits at or below the governing job timeout is fatal.
 * Everything else — a failed probe, a non-SQS driver, an undeclared value —
 * warns, because a check that cannot prove a problem must not be allowed to
 * block an import that works today.
 *
 * This test extends Testbench directly rather than Tests\TestCase: the checker
 * only needs config() and trans(), so there is no reason to boot a cluster.
 */
final class QueueTimingPreflightTest extends BaseTestCase
{
    /** An SQS connection name used throughout; only its `driver` matters here. */
    private const SQS = 'sqs_test';

    /** An asynchronous non-SQS connection, which does honour retry_after. */
    private const DATABASE = 'db_test';

    protected function getPackageProviders($app)
    {
        return [
            // Registers Scout itself, and loads the scout:: translations the
            // report is rendered from.
            ScoutElasticSearchServiceProvider::class,
            ElasticSearchServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Pin every value the checker reads, so a stray SCOUT_* env var in the
        // test container cannot change what these assertions mean. Written with
        // dotted keys on purpose: replacing whole arrays would fight the
        // provider's mergeConfigFrom of the package defaults.
        config()->set('elasticsearch.queue.timeout', null);
        config()->set('elasticsearch.import.lock_ttl', 3600);
        config()->set('elasticsearch.import.retry.tries', 1);
        config()->set('elasticsearch.import.retry.retry_until', 0);
        config()->set('elasticsearch.import.failure_budget', 1);
        config()->set('elasticsearch.import.redispatch_limit', 3);
        config()->set('elasticsearch.import.dispatch_batch', 1000);
        config()->set('elasticsearch.import.chunk.default', null);
        config()->set('elasticsearch.import.preflight.enabled', true);
        config()->set('elasticsearch.import.preflight.worker_timeout', 0);
        config()->set('elasticsearch.import.preflight.shutdown_grace', 0);
        config()->set('elasticsearch.import.preflight.expected_chunk_seconds', 0);
        config()->set('elasticsearch.import.preflight.probe_queue', true);
        config()->set('scout.chunk.searchable', 500);

        config()->set('queue.connections.'.self::SQS, [
            'driver' => 'sqs',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/1234567890',
            'queue' => 'default',
            'region' => 'us-east-1',
        ]);
        config()->set('queue.connections.'.self::DATABASE, [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    }

    // ---- THE INCIDENT ------------------------------------------------------

    /**
     * Regression test for the destroyed PERF import: a hand-made SQS queue kept
     * the AWS default VisibilityTimeout of 30s while SCOUT_QUEUE_TIMEOUT was
     * unset, so the effective bound was the worker's own `--timeout` (60s).
     * Every chunk slower than 30s was re-delivered mid-flight, and because
     * SqsJob::attempts() IS ApproximateReceiveCount, delivery #2 arrived with
     * attempts=2 and was failed by the worker BEFORE the job body ever ran.
     *
     * Nothing about that setup is visible without probing the queue, which is
     * exactly why this must abort: one probed fact turns an invisible,
     * import-destroying misconfiguration into a fatal pre-flight finding.
     *
     * @test
     */
    public function perf_incident_sqs_visibility_of_30s_with_no_job_timeout_is_fatal(): void
    {
        $report = $this->inspect(['visibility_timeout' => 30]);

        // Exactly one fatal, and it is the "worker timeout governs" variant —
        // SCOUT_QUEUE_TIMEOUT was never set, so F1 cannot be the one that fires.
        $this->assertSame(
            [QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_WORKER_TIMEOUT],
            $this->fatalCodes($report),
            'A probed VisibilityTimeout of 30s under an unset SCOUT_QUEUE_TIMEOUT is the PERF incident and must be fatal.'
        );
        $this->assertTrue($report['fatal']);

        // The governing timeout is the framework's documented queue:work
        // default, and the report says so rather than pretending to know it.
        $this->assertNull($report['job_timeout']);
        $this->assertSame(60, $report['effective_timeout']);
        $this->assertSame(
            QueueTimingPreflight::SOURCE_ASSUMED_WORKER_TIMEOUT,
            $report['effective_timeout_source']
        );

        // Both remedies must be spelled out: widen the window, or give the job
        // an alarm that fires first.
        $message = $this->findingMessage($report, QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_WORKER_TIMEOUT);
        $this->assertStringContainsString('VisibilityTimeout to at least 90 s', $message);
        $this->assertStringContainsString('SCOUT_QUEUE_TIMEOUT below 30 s', $message);
        $this->assertStringNotContainsString(':visibility', $message);
    }

    // ---- FATALS ------------------------------------------------------------

    /**
     * F1: SCOUT_QUEUE_TIMEOUT is set and the probed window does not EXCEED it.
     * Equal is fatal — a 300s window under a 300s alarm is a coin toss, and the
     * loser is a chunk that gets re-delivered while it is still running.
     *
     * @test
     */
    public function equal_sqs_visibility_and_job_timeout_is_fatal(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $report = $this->inspect(['visibility_timeout' => 300]);

        $this->assertSame(
            [QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_TIMEOUT],
            $this->fatalCodes($report)
        );
        $this->assertSame(300, $report['job_timeout']);
        $this->assertSame(300, $report['effective_timeout']);
        $this->assertSame(QueueTimingPreflight::SOURCE_JOB_TIMEOUT, $report['effective_timeout_source']);

        // Correctly ordered configs get the thin-margin warning; a fatal one
        // does not also get it (the ordering is not merely thin, it is wrong).
        $this->assertNotContains(
            QueueTimingPreflight::CODE_SQS_VISIBILITY_MARGIN_THIN,
            $this->codes($report)
        );
        $this->assertStringContainsString(
            'at least 330 s',
            $this->findingMessage($report, QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_TIMEOUT)
        );
    }

    /**
     * A comfortable margin is clean: no fatal, and no thin-margin warning
     * either at 120s of headroom.
     *
     * @test
     */
    public function comfortable_sqs_visibility_margin_produces_no_fatal_and_no_thin_margin_warning(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $report = $this->inspect(['visibility_timeout' => 420, 'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq']);

        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertSame([], $this->codes($report), 'A correctly ordered queue with a DLQ has nothing to report.');
    }

    /**
     * W10: ordering correct, margin thin. 20s is not enough room for a worker
     * to notice its own alarm and stop, so it warns — but it is not proven
     * broken, so it must not abort.
     *
     * @test
     */
    public function thin_sqs_visibility_margin_warns_without_aborting(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $report = $this->inspect(['visibility_timeout' => 320]);

        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertContains(QueueTimingPreflight::CODE_SQS_VISIBILITY_MARGIN_THIN, $this->codes($report));
        $this->assertStringContainsString(
            'margin is only 20 s',
            $this->findingMessage($report, QueueTimingPreflight::CODE_SQS_VISIBILITY_MARGIN_THIN)
        );
    }

    /**
     * A failed probe proves nothing, so it can never be fatal — not even in the
     * exact configuration (SQS + unset SCOUT_QUEUE_TIMEOUT) that produced the
     * incident. An IAM denial must not block an import.
     *
     * @test
     */
    public function a_failed_probe_never_produces_a_fatal(): void
    {
        $report = $this->inspect(['error' => 'AccessDenied: sqs:GetQueueAttributes']);

        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));

        $codes = $this->codes($report);
        $this->assertContains(QueueTimingPreflight::CODE_QUEUE_PROBE_UNAVAILABLE, $codes);
        $this->assertContains(QueueTimingPreflight::CODE_JOB_TIMEOUT_UNSET, $codes);

        // Nothing was probed, so nothing may be reported as probed — including
        // the absence of a dead-letter queue.
        $this->assertNotContains(QueueTimingPreflight::CODE_SQS_MISSING_DEAD_LETTER, $codes);
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_UNKNOWN,
            $this->fact($report, 'visibility_timeout')['provenance']
        );

        // The reason is passed through verbatim, so the operator can tell an IAM
        // denial from a missing SDK.
        $this->assertStringContainsString(
            'AccessDenied: sqs:GetQueueAttributes',
            $this->findingMessage($report, QueueTimingPreflight::CODE_QUEUE_PROBE_UNAVAILABLE)
        );
    }

    /**
     * F2 compares against the DECLARED worker timeout when there is one. With
     * SCOUT_IMPORT_WORKER_TIMEOUT=30 a 90s window is correctly ordered, so the
     * same probe that would be fatal against the assumed 60s default is clean —
     * proving the declaration is used in place of the guess.
     *
     * @test
     */
    public function a_declared_worker_timeout_replaces_the_assumed_default(): void
    {
        config()->set('elasticsearch.import.preflight.worker_timeout', 30);

        $report = $this->inspect(['visibility_timeout' => 90, 'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq']);

        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertSame(30, $report['effective_timeout']);
        $this->assertSame(
            QueueTimingPreflight::SOURCE_DECLARED_WORKER_TIMEOUT,
            $report['effective_timeout_source']
        );

        // 60s of headroom, so not even the thin-margin warning.
        $this->assertNotContains(QueueTimingPreflight::CODE_SQS_VISIBILITY_MARGIN_THIN, $this->codes($report));
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_DECLARED,
            $this->fact($report, 'worker_timeout')['provenance']
        );
    }

    // ---- THE AWS CEILING (F0) ----------------------------------------------

    /**
     * F0: SQS caps VisibilityTimeout at 12 hours, so an effective job timeout
     * above 43200s makes `job_timeout < VisibilityTimeout` UNSATISFIABLE — there
     * is no legal AWS value left to order above it. A real PERF worker runs
     * `queue:work sqs --timeout=86400`, which is exactly this.
     *
     * The dangerous old behaviour was not the missing finding, it was the advice:
     * F1/F2 would have told the operator to "raise VisibilityTimeout to 86430 s",
     * a SetQueueAttributes call AWS refuses. So F0 must both fire and SUPPRESS
     * them, and no message in the report may name a VisibilityTimeout above the
     * ceiling.
     *
     * @test
     */
    public function an_effective_timeout_above_the_sqs_ceiling_is_fatal_and_replaces_the_visibility_findings(): void
    {
        // The worker's --timeout is invisible to PHP; declaring it is the only
        // way this check can ever see the 86400 that governs the run.
        config()->set('elasticsearch.import.preflight.worker_timeout', 86400);

        $report = $this->inspect([
            'visibility_timeout' => 30,
            'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq',
        ]);

        $this->assertSame(43200, QueueTimingPreflight::SQS_MAX_VISIBILITY_TIMEOUT, '12 hours, the AWS hard cap.');
        $this->assertTrue($report['fatal']);
        $this->assertSame(86400, $report['effective_timeout']);
        $this->assertSame(
            [QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING],
            $this->fatalCodes($report),
            'Above the ceiling the ceiling finding is the only fatal: F1/F2 are suppressed, not merely reordered.'
        );

        $codes = $this->codes($report);
        $this->assertSame(
            QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING,
            $codes[0],
            'F0 subsumes F1/F2 and must be the first thing the operator reads.'
        );
        $this->assertNotContains(QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_TIMEOUT, $codes);
        $this->assertNotContains(
            QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_WORKER_TIMEOUT,
            $codes,
            'A probed 30s window under an unset SCOUT_QUEUE_TIMEOUT is normally F2; up here its remedy is illegal, so it must not be printed.'
        );

        // THE POINT OF THE FINDING: nowhere in the whole rendered report may an
        // operator be told to set a VisibilityTimeout AWS would reject.
        foreach ($report['findings'] as $finding) {
            $message = (string) $finding['message'];

            $this->assertStringNotContainsString(
                'VisibilityTimeout to at least',
                $message,
                "Finding [{$finding['code']}] still suggests raising VisibilityTimeout above the AWS ceiling."
            );

            // Every "at least N s" remedy anywhere in the report has to name a
            // number AWS could actually accept.
            preg_match_all('/at least (\d+) s/', $message, $matches);
            foreach ($matches[1] as $suggested) {
                $this->assertLessThanOrEqual(
                    QueueTimingPreflight::SQS_MAX_VISIBILITY_TIMEOUT,
                    (int) $suggested,
                    "Finding [{$finding['code']}] suggests {$suggested}s, which is above the SQS ceiling."
                );
            }
        }

        $message = $this->findingMessage($report, QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING);
        // The remedy runs the other way: bring the timeout DOWN to the highest
        // value that still leaves the usual thin margin under a legal window.
        $this->assertStringContainsString('at most 43170 s', $message);
        $this->assertStringContainsString('43200', $message);
        $this->assertStringContainsString('SCOUT_QUEUE_TIMEOUT', $message);
        // `--timeout=86400` is a real setting in the wild and the usual cause,
        // so the message has to name it rather than only the env var.
        $this->assertStringContainsString('--timeout', $message);
        $this->assertStringNotContainsString(':ceiling', $message);
        $this->assertStringNotContainsString(':suggested', $message);
    }

    /**
     * F0 is the ONLY fatal that fires without probed evidence, and that is
     * deliberate: F1/F2 claim something about one particular queue, so an unread
     * queue proves nothing — whereas an effective timeout above the AWS ceiling
     * fails on every queue that could exist, which configuration alone already
     * proves. Both probe-less shapes are checked: a probe that failed, and a
     * probe that was never attempted.
     *
     * @test
     */
    public function the_sqs_ceiling_fatal_fires_with_no_probed_evidence_at_all(): void
    {
        config()->set('elasticsearch.import.preflight.worker_timeout', 86400);

        $denied = $this->inspect(['error' => 'AccessDenied: sqs:GetQueueAttributes']);

        $this->assertNull($denied['probe']['visibility_timeout'], 'Nothing was learned about the queue.');
        $this->assertTrue($denied['fatal']);
        $this->assertSame(
            [QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING],
            $this->fatalCodes($denied),
            'An IAM denial cannot make an unsatisfiable ordering satisfiable.'
        );
        $this->assertContains(QueueTimingPreflight::CODE_QUEUE_PROBE_UNAVAILABLE, $this->codes($denied));

        // probe_queue=false: the check performs no I/O whatsoever and still
        // reaches the same verdict.
        config()->set('elasticsearch.import.preflight.probe_queue', false);

        $called = 0;
        $unprobed = (new QueueTimingPreflight($this->countingProbe($called, ['visibility_timeout' => 900])))
            ->inspect(self::SQS, 'reindex');

        $this->assertSame(0, $called, 'F0 must need no probe to be proven.');
        $this->assertSame(
            [QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING],
            $this->fatalCodes($unprobed)
        );
    }

    /**
     * The boundary is the AWS cap itself, and it is exclusive: 43200 IS a legal
     * VisibilityTimeout, so an effective timeout of exactly 43200 leaves the
     * ordering satisfiable (tightly) and F0 stays silent. One second more and no
     * legal value exists.
     *
     * Only the timeout moves between the two reports, so nothing but the
     * comparison itself can explain the difference.
     *
     * @test
     */
    public function the_sqs_ceiling_boundary_is_exclusive(): void
    {
        $probe = ['dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq'];

        config()->set('elasticsearch.queue.timeout', QueueTimingPreflight::SQS_MAX_VISIBILITY_TIMEOUT);
        $atCeiling = $this->inspect($probe);

        $this->assertSame(43200, $atCeiling['effective_timeout']);
        $this->assertFalse($atCeiling['fatal']);
        $this->assertSame(
            [],
            $this->fatalCodes($atCeiling),
            'Exactly at the cap a legal VisibilityTimeout still exists, so nothing is proven unsatisfiable.'
        );

        config()->set('elasticsearch.queue.timeout', QueueTimingPreflight::SQS_MAX_VISIBILITY_TIMEOUT + 1);
        $aboveCeiling = $this->inspect($probe);

        $this->assertSame(43201, $aboveCeiling['effective_timeout']);
        $this->assertTrue($aboveCeiling['fatal']);
        $this->assertSame(
            [QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING],
            $this->fatalCodes($aboveCeiling),
            'One second above the cap there is no legal window left, and that is fatal.'
        );
    }

    /**
     * The ceiling is an AWS fact, not a universal one: `retry_after` on the
     * database/redis drivers has no such cap, so a 24-hour timeout there is
     * merely worth a warning (W9) and must never inherit the SQS fatal.
     *
     * @test
     */
    public function a_huge_timeout_on_a_non_sqs_driver_never_hits_the_sqs_ceiling(): void
    {
        config()->set('elasticsearch.queue.timeout', 86400);

        $called = 0;
        $report = (new QueueTimingPreflight($this->countingProbe($called, ['visibility_timeout' => 30])))
            ->inspect(self::DATABASE, 'reindex');

        $this->assertSame(0, $called, 'There is no queue to probe on a non-SQS driver.');
        $this->assertSame('database', $report['driver']);
        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertNotContains(QueueTimingPreflight::CODE_TIMEOUT_ABOVE_SQS_CEILING, $this->codes($report));

        // The right finding for this driver still fires: retry_after 90s cannot
        // hold a 86400s job either, it just is not an unfixable AWS limit.
        $this->assertContains(QueueTimingPreflight::CODE_RETRY_AFTER_BELOW_TIMEOUT, $this->codes($report));
    }

    // ---- WARNINGS ----------------------------------------------------------

    /**
     * W9: the same re-delivery race on a driver that does read retry_after.
     * These drivers reserve and release differently, so it warns rather than
     * aborting — and the SQS probe is never even attempted.
     *
     * @test
     */
    public function retry_after_at_or_below_the_job_timeout_warns_on_a_non_sqs_driver(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $called = 0;
        $report = (new QueueTimingPreflight($this->countingProbe($called, [])))
            ->inspect(self::DATABASE, 'reindex');

        $this->assertSame(0, $called, 'The SQS probe must never run on a non-SQS driver.');
        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertSame([QueueTimingPreflight::CODE_RETRY_AFTER_BELOW_TIMEOUT], $this->codes($report));

        $message = $this->findingMessage($report, QueueTimingPreflight::CODE_RETRY_AFTER_BELOW_TIMEOUT);
        $this->assertStringContainsString('database', $message);
        $this->assertStringContainsString('retry_after 90 s', $message);

        // retry_after is a real config value here, unlike on SQS where the
        // connector never reads it.
        $this->assertSame(90, $this->fact($report, 'retry_after')['raw']);
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_DECLARED,
            $this->fact($report, 'retry_after')['provenance']
        );
    }

    /**
     * W3: a non-zero retry deadline silently disables `tries` (the worker only
     * compares attempts against maxTries when no deadline is set) and is stamped
     * once at dispatch, so on a large fan-out every chunk still queued when it
     * passes fails on its first receive, having never run.
     *
     * @test
     */
    public function a_retry_deadline_warns_because_it_disables_tries(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);
        config()->set('elasticsearch.import.retry.retry_until', 900);

        $report = $this->healthyReport();

        $this->assertFalse($report['fatal']);
        $this->assertContains(QueueTimingPreflight::CODE_RETRY_UNTIL_SET, $this->codes($report));
        $this->assertStringContainsString(
            '900',
            $this->findingMessage($report, QueueTimingPreflight::CODE_RETRY_UNTIL_SET)
        );
        $this->assertSame(900, $this->fact($report, 'retry_until')['raw']);
    }

    /**
     * W4: a redrive policy whose maxReceiveCount does not EXCEED tries lets the
     * dead-letter queue swallow a message the worker still meant to retry.
     * Equal is already wrong, hence the boundary value here.
     *
     * @test
     */
    public function a_max_receive_count_that_does_not_exceed_tries_warns(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);
        config()->set('elasticsearch.import.retry.tries', 3);

        $report = $this->inspect([
            'visibility_timeout' => 900,
            'max_receive_count' => 3,
            'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq',
        ]);

        $this->assertFalse($report['fatal']);
        $this->assertSame(
            [QueueTimingPreflight::CODE_SQS_MAX_RECEIVE_COUNT_BELOW_TRIES],
            $this->codes($report)
        );
        $this->assertStringContainsString(
            'at least 4',
            $this->findingMessage($report, QueueTimingPreflight::CODE_SQS_MAX_RECEIVE_COUNT_BELOW_TRIES)
        );
    }

    /**
     * W5: only a successful probe can tell us a dead-letter target is missing,
     * and attaching one silences it.
     *
     * @test
     */
    public function a_probed_queue_without_a_dead_letter_target_warns(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $without = $this->inspect(['visibility_timeout' => 900]);
        $this->assertSame([QueueTimingPreflight::CODE_SQS_MISSING_DEAD_LETTER], $this->codes($without));
        $this->assertSame('none', $this->fact($without, 'dead_letter')['value']);

        $with = $this->inspect(['visibility_timeout' => 900, 'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq']);
        $this->assertSame([], $this->codes($with));
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_PROBED,
            $this->fact($with, 'dead_letter')['provenance']
        );
    }

    /**
     * W6: an import lease that is not longer than a single chunk's timeout can
     * lapse under a running chunk and admit a second, overlapping import of the
     * same model.
     *
     * @test
     */
    public function a_lock_ttl_at_or_below_the_job_timeout_warns(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);
        config()->set('elasticsearch.import.lock_ttl', 60);

        $report = $this->healthyReport();

        $this->assertFalse($report['fatal']);
        $this->assertContains(QueueTimingPreflight::CODE_LOCK_TTL_BELOW_TIMEOUT, $this->codes($report));
        $this->assertStringContainsString(
            '60 s',
            $this->findingMessage($report, QueueTimingPreflight::CODE_LOCK_TTL_BELOW_TIMEOUT)
        );
    }

    /**
     * W7: a declared shutdown grace below the job timeout means a deploy
     * SIGKILLs a worker mid-chunk. Undeclared (0) says nothing, and a grace
     * equal to the timeout is not below it.
     *
     * @test
     */
    public function a_declared_shutdown_grace_below_the_job_timeout_warns(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        config()->set('elasticsearch.import.preflight.shutdown_grace', 120);
        $short = $this->healthyReport();
        $this->assertSame([QueueTimingPreflight::CODE_SHUTDOWN_GRACE_BELOW_TIMEOUT], $this->codes($short));
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_DECLARED,
            $this->fact($short, 'shutdown_grace')['provenance']
        );

        config()->set('elasticsearch.import.preflight.shutdown_grace', 300);
        $this->assertSame([], $this->codes($this->healthyReport()));

        // Undeclared is unknown, never a finding.
        config()->set('elasticsearch.import.preflight.shutdown_grace', 0);
        $undeclared = $this->healthyReport();
        $this->assertSame([], $this->codes($undeclared));
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_UNKNOWN,
            $this->fact($undeclared, 'shutdown_grace')['provenance']
        );
        $this->assertSame('unknown', $this->fact($undeclared, 'shutdown_grace')['value']);
    }

    /**
     * W8: a declared chunk duration needs 2x headroom under the job timeout, or
     * a chunk near p99 trips the alarm. 150s against 300s is exactly enough;
     * 200s is not.
     *
     * @test
     */
    public function a_declared_chunk_duration_without_headroom_warns(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        config()->set('elasticsearch.import.preflight.expected_chunk_seconds', 200);
        $tight = $this->healthyReport();
        $this->assertSame([QueueTimingPreflight::CODE_CHUNK_DURATION_HEADROOM], $this->codes($tight));
        $this->assertStringContainsString(
            'at least 400 s',
            $this->findingMessage($tight, QueueTimingPreflight::CODE_CHUNK_DURATION_HEADROOM)
        );

        config()->set('elasticsearch.import.preflight.expected_chunk_seconds', 150);
        $this->assertSame([], $this->codes($this->healthyReport()));
    }

    // ---- PROVENANCE AND THE REPORT TABLE -----------------------------------

    /**
     * Provenance is the whole point of the report: the operator has to be able
     * to see which numbers are facts and which are guesses. Every row carries
     * one, and every label/marker resolves to a real translation.
     *
     * @test
     */
    public function every_reported_fact_carries_a_resolved_label_and_a_provenance_marker(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $report = $this->inspect([
            'visibility_timeout' => 900,
            'max_receive_count' => 5,
            'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq',
            'queue_url' => 'https://sqs.us-east-1.amazonaws.com/1234567890/reindex',
        ]);

        $this->assertSame([
            'connection',
            'driver',
            'queue',
            'queue_url',
            'job_timeout',
            'effective_timeout',
            'worker_timeout',
            'visibility_timeout',
            'retry_after',
            'max_receive_count',
            'dead_letter',
            'tries',
            'retry_until',
            'failure_budget',
            'redispatch_limit',
            'dispatch_batch',
            'lock_ttl',
            'chunk',
            'shutdown_grace',
            'expected_chunk_seconds',
        ], array_column($report['facts'], 'key'));

        $allowed = [
            QueueTimingPreflight::PROVENANCE_PROBED,
            QueueTimingPreflight::PROVENANCE_DECLARED,
            QueueTimingPreflight::PROVENANCE_ASSUMED,
            QueueTimingPreflight::PROVENANCE_UNKNOWN,
        ];

        foreach ($report['facts'] as $fact) {
            $this->assertContains($fact['provenance'], $allowed, "Fact [{$fact['key']}] has no provenance marker.");
            $this->assertNotSame('', $fact['provenance_label']);
            $this->assertNotSame('', $fact['value']);
            // A missing lang key makes trans() echo the key back, so this also
            // asserts every label/marker exists in resources/lang/en/import.php.
            $this->assertStringNotContainsString('scout::import.', $fact['label']);
            $this->assertStringNotContainsString('scout::import.', $fact['provenance_label']);
        }

        // The three interesting kinds, spot-checked: probed from the broker,
        // declared by the operator, assumed because nothing said otherwise.
        $this->assertSame(QueueTimingPreflight::PROVENANCE_PROBED, $this->fact($report, 'visibility_timeout')['provenance']);
        $this->assertSame('900s', $this->fact($report, 'visibility_timeout')['value']);
        $this->assertSame(QueueTimingPreflight::PROVENANCE_PROBED, $this->fact($report, 'queue_url')['provenance']);
        $this->assertSame(QueueTimingPreflight::PROVENANCE_DECLARED, $this->fact($report, 'job_timeout')['provenance']);
        $this->assertSame(QueueTimingPreflight::PROVENANCE_UNKNOWN, $this->fact($report, 'expected_chunk_seconds')['provenance']);
    }

    /**
     * The chunk size is only "declared" when something actually declared it:
     * the caller's resolved --chunk, then config, then a documented guess.
     *
     * @test
     */
    public function the_chunk_fact_distinguishes_a_declared_size_from_an_assumed_one(): void
    {
        config()->set('elasticsearch.queue.timeout', 300);

        $passed = $this->inspect(['visibility_timeout' => 900], self::SQS, 'reindex', 250);
        $this->assertSame(250, $this->fact($passed, 'chunk')['raw']);
        $this->assertSame(QueueTimingPreflight::PROVENANCE_DECLARED, $this->fact($passed, 'chunk')['provenance']);

        $fromConfig = $this->inspect(['visibility_timeout' => 900]);
        $this->assertSame(500, $this->fact($fromConfig, 'chunk')['raw']);
        $this->assertSame(QueueTimingPreflight::PROVENANCE_DECLARED, $this->fact($fromConfig, 'chunk')['provenance']);

        config()->set('scout.chunk.searchable', null);
        $assumed = $this->inspect(['visibility_timeout' => 900]);
        $this->assertSame(QueueTimingPreflight::PROVENANCE_ASSUMED, $this->fact($assumed, 'chunk')['provenance']);
    }

    // ---- SWITCHES ----------------------------------------------------------

    /**
     * probe_queue=false skips the only I/O the checker does. The ordering is
     * then unverifiable, so it warns and says why — and, crucially, still finds
     * nothing fatal in a configuration that would otherwise be the PERF case.
     *
     * @test
     */
    public function disabling_the_probe_skips_it_entirely_and_warns(): void
    {
        config()->set('elasticsearch.import.preflight.probe_queue', false);

        $called = 0;
        $report = (new QueueTimingPreflight($this->countingProbe($called, ['visibility_timeout' => 30])))
            ->inspect(self::SQS, 'reindex');

        $this->assertSame(0, $called, 'probe_queue=false must not perform the GetQueueAttributes call.');
        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertNull($report['probe']['visibility_timeout']);
        $this->assertSame(
            trans('scout::import.preflight_probe_reason_disabled'),
            $report['probe']['error']
        );
        $this->assertContains(QueueTimingPreflight::CODE_QUEUE_PROBE_UNAVAILABLE, $this->codes($report));
    }

    /**
     * The master switch turns off the checking, not the reporting: the facts are
     * still resolved (so `--preflight` can show them) but nothing is judged.
     *
     * @test
     */
    public function the_master_switch_reports_the_facts_and_finds_nothing(): void
    {
        config()->set('elasticsearch.import.preflight.enabled', false);

        $called = 0;
        $report = (new QueueTimingPreflight($this->countingProbe($called, ['visibility_timeout' => 30])))
            ->inspect(self::SQS, 'reindex');

        $this->assertFalse(QueueTimingPreflight::isEnabled());
        $this->assertSame(0, $called);
        $this->assertFalse($report['enabled']);
        $this->assertFalse($report['fatal']);
        $this->assertSame([], $report['findings']);
        $this->assertCount(20, $report['facts']);
        $this->assertSame(self::SQS, $this->fact($report, 'connection')['value']);
        $this->assertSame('unknown', $this->fact($report, 'visibility_timeout')['value']);
    }

    // ---- HELPER SURFACE ----------------------------------------------------

    /**
     * The --force path: the findings are still worth printing, but the operator
     * has taken the wheel, so nothing may abort.
     *
     * @test
     */
    public function without_fatals_downgrades_every_fatal_and_clears_the_flag(): void
    {
        $report = $this->inspect(['visibility_timeout' => 30]);
        $this->assertTrue(QueueTimingPreflight::hasFatal($report));

        $forced = QueueTimingPreflight::withoutFatals($report);

        $this->assertFalse($forced['fatal']);
        $this->assertFalse(QueueTimingPreflight::hasFatal($forced));
        $this->assertSame([], $this->fatalCodes($forced));
        // Nothing is lost, only re-labelled.
        $this->assertCount(count($report['findings']), $forced['findings']);
        $this->assertContains(
            QueueTimingPreflight::CODE_SQS_VISIBILITY_BELOW_WORKER_TIMEOUT,
            $this->codes($forced)
        );
        $this->assertFalse(QueueTimingPreflight::hasFatal(['findings' => []]));
        $this->assertFalse(QueueTimingPreflight::hasFatal([]));
    }

    /**
     * A fake — or a real SDK response — may return partial or junk data. The
     * probe result is normalised, and a throwing probe becomes a warning rather
     * than an exception that would take the import down with it.
     *
     * @test
     */
    public function probe_results_are_normalised_and_a_throwing_probe_degrades_to_a_warning(): void
    {
        // Numeric strings (what the SQS API actually returns) become ints, and
        // an empty dead-letter string is no dead letter at all.
        $report = $this->inspect([
            'visibility_timeout' => '900',
            'max_receive_count' => '5',
            'dead_letter' => '',
            'queue_url' => '',
        ]);
        $this->assertSame(900, $report['probe']['visibility_timeout']);
        $this->assertSame(5, $report['probe']['max_receive_count']);
        $this->assertNull($report['probe']['dead_letter']);
        $this->assertNull($report['probe']['queue_url']);
        $this->assertNull($report['probe']['error']);

        // A probe that returns something that is not an array.
        $garbage = (new QueueTimingPreflight(function (?string $connection, ?string $queue) {
            return 'not a probe result';
        }))->inspect(self::SQS, 'reindex');
        $this->assertSame(
            trans('scout::import.preflight_probe_reason_invalid'),
            $garbage['probe']['error']
        );
        $this->assertSame([], $this->fatalCodes($garbage));

        // A probe that throws (an IAM denial, a network timeout, a bad region).
        $threw = (new QueueTimingPreflight(function (?string $connection, ?string $queue): array {
            throw new RuntimeException('could not reach sqs');
        }))->inspect(self::SQS, 'reindex');
        $this->assertSame('could not reach sqs', $threw['probe']['error']);
        $this->assertSame([], $this->fatalCodes($threw));
        $this->assertContains(QueueTimingPreflight::CODE_QUEUE_PROBE_UNAVAILABLE, $this->codes($threw));
    }

    /**
     * The real probe is the default, and with no AWS SDK installed it reports
     * that as its reason instead of exploding on a missing class.
     *
     * @test
     */
    public function the_default_probe_reports_a_missing_aws_sdk_instead_of_failing(): void
    {
        if (class_exists('Aws\Sqs\SqsClient')) {
            $this->markTestSkipped('aws/aws-sdk-php is installed, so the SDK-absent branch cannot be exercised.');
        }

        $probe = QueueTimingPreflight::defaultProbe(self::SQS, 'reindex');

        $this->assertSame(trans('scout::import.preflight_probe_reason_sdk_missing'), $probe['error']);
        $this->assertNull($probe['visibility_timeout']);
        $this->assertSame(QueueTimingPreflight::emptyProbe()['visibility_timeout'], $probe['visibility_timeout']);

        // And the checker built with no seam at all uses it, so the SDK being
        // absent is a warning rather than a fatal.
        $report = (new QueueTimingPreflight())->inspect(self::SQS, 'reindex');
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertContains(QueueTimingPreflight::CODE_QUEUE_PROBE_UNAVAILABLE, $this->codes($report));
    }

    /**
     * A run with no queue connection at all (nothing resolved) knows nothing and
     * therefore says nothing fatal.
     *
     * @test
     */
    public function an_unresolvable_connection_is_never_fatal(): void
    {
        $called = 0;
        $report = (new QueueTimingPreflight($this->countingProbe($called, [])))->inspect(null, null);

        $this->assertSame(0, $called, 'With no driver there is nothing to probe.');
        $this->assertNull($report['driver']);
        $this->assertFalse($report['fatal']);
        $this->assertSame([], $this->fatalCodes($report));
        $this->assertSame(
            QueueTimingPreflight::PROVENANCE_UNKNOWN,
            $this->fact($report, 'driver')['provenance']
        );
        $this->assertSame('(driver default)', $this->fact($report, 'queue')['value']);
    }

    // ---- harness -----------------------------------------------------------

    /**
     * Inspect with a fake probe: no SDK, no network, no AWS credentials.
     *
     * @param  array<string, mixed>  $probeResult
     * @return array<string, mixed>
     */
    private function inspect(
        array $probeResult,
        ?string $connection = self::SQS,
        ?string $queue = 'reindex',
        ?int $chunkSize = null
    ): array {
        $preflight = new QueueTimingPreflight(function (?string $c, ?string $q) use ($probeResult) {
            return $probeResult;
        });

        return $preflight->inspect($connection, $queue, $chunkSize);
    }

    /**
     * A queue that is ordered correctly and has a dead-letter target, so the
     * only findings left are the ones the test itself provoked.
     *
     * @return array<string, mixed>
     */
    private function healthyReport(): array
    {
        return $this->inspect([
            'visibility_timeout' => 900,
            'max_receive_count' => 5,
            'dead_letter' => 'arn:aws:sqs:us-east-1:1:dlq',
        ]);
    }

    /**
     * A probe that records whether it ran, for the cases where "was not called
     * at all" is the assertion.
     *
     * @param  array<string, mixed>  $result
     * @return callable(?string, ?string): array<string, mixed>
     */
    private function countingProbe(?int &$calls, array $result): callable
    {
        $calls = 0;

        return function (?string $connection, ?string $queue) use (&$calls, $result): array {
            $calls++;

            return $result;
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function codes(array $report): array
    {
        return array_map(function (array $finding): string {
            return (string) $finding['code'];
        }, $report['findings']);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function fatalCodes(array $report): array
    {
        $fatals = array_filter($report['findings'], function (array $finding): bool {
            return $finding['severity'] === QueueTimingPreflight::SEVERITY_FATAL;
        });

        return array_values(array_map(function (array $finding): string {
            return (string) $finding['code'];
        }, $fatals));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function findingMessage(array $report, string $code): string
    {
        foreach ($report['findings'] as $finding) {
            if ($finding['code'] === $code) {
                $this->assertStringNotContainsString('scout::import.', $finding['message']);

                return (string) $finding['message'];
            }
        }

        $this->fail("No finding with code [{$code}] in the report.");
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function fact(array $report, string $key): array
    {
        foreach ($report['facts'] as $fact) {
            if ($fact['key'] === $key) {
                return $fact;
            }
        }

        $this->fail("No fact with key [{$key}] in the report.");
    }

    // ---- THE __call REGRESSION -------------------------------------------
    // An AWS SDK v3 client declares no real method per API operation; every one
    // is dispatched through __call and only documented as an @method annotation.
    // A method_exists() guard therefore reports FALSE on a healthy SqsClient and
    // makes the probe answer "unsupported client" forever, leaving
    // VisibilityTimeout — the one fact worth probing, and the one whose absence
    // caused the incident this check exists to catch — permanently unknown.

    /**
     * @test
     */
    public function the_attribute_read_honours_a_client_that_dispatches_through_call(): void
    {
        $client = new PreflightMagicCallClient([
            'Attributes' => [
                'VisibilityTimeout' => '30',
                'RedrivePolicy' => '{"maxReceiveCount":"5","deadLetterTargetArn":"arn:aws:sqs:eu-west-1:1:dlq"}',
            ],
        ]);

        // Guard the premise: if this ever became true the test would pass for the
        // wrong reason and stop protecting anything.
        $this->assertFalse(
            method_exists($client, 'getQueueAttributes'),
            'The stub must NOT declare the method — a real SDK client does not either.'
        );

        $attributes = $this->readQueueAttributes($client);

        $this->assertNotNull($attributes, 'A __call-backed client must be treated as usable.');
        $this->assertSame('30', $attributes['VisibilityTimeout']);
        $this->assertSame('getQueueAttributes', $client->calledMethod);
        $this->assertSame(
            ['VisibilityTimeout', 'RedrivePolicy'],
            $client->calledArguments[0]['AttributeNames'] ?? null
        );
    }

    /**
     * @test
     */
    public function the_attribute_read_still_rejects_a_client_that_cannot_answer_at_all(): void
    {
        $this->assertNull($this->readQueueAttributes(new \stdClass()));
        $this->assertNull($this->readQueueAttributes('not-an-object'));
    }

    /**
     * @param  mixed  $client
     * @return array<string, mixed>|null
     */
    private function readQueueAttributes($client): ?array
    {
        $method = new \ReflectionMethod(QueueTimingPreflight::class, 'readQueueAttributes');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke(null, $client, 'https://sqs.eu-west-1.amazonaws.com/1/q');

        return $result;
    }
}

/**
 * Stands in for an AWS SDK client: no declared API methods, everything through
 * __call. See the regression tests above.
 */
final class PreflightMagicCallClient
{
    /** @var string|null */
    public $calledMethod;

    /** @var array<int, mixed> */
    public $calledArguments = [];

    /** @var array<string, mixed> */
    private $response;

    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(array $response)
    {
        $this->response = $response;
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function __call(string $name, array $arguments): array
    {
        $this->calledMethod = $name;
        $this->calledArguments = $arguments;

        return $this->response;
    }
}
