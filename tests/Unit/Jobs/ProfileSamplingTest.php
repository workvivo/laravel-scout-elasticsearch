<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use PHPUnit\Framework\TestCase;

/**
 * The one piece of arithmetic every profile decision in the system goes through:
 * {@see PullFromSource::profileStride()} plus {@see PullFromSource::shouldProfile()}.
 *
 * It matters that this is a *pure function of (total, profile, samples)* and
 * nothing else. Four independent places compute it — the planning hop, every
 * continuation hop of a paged fan-out, the reaper when it revives a stranded
 * chunk, and chunked() on the sequential path — and none of them can see what
 * the others decided. Reproducibility is the only thing that keeps them
 * agreeing, so the tests below pin the exact stride for each input rather than
 * merely checking that "some" chunks are sampled.
 *
 * Both methods are static and touch neither the container nor config, so this
 * extends PHPUnit directly: no cluster, no Laravel boot.
 */
final class ProfileSamplingTest extends TestCase
{
    /**
     * The chunk ids that would be profiled for a plan of $total chunks, in plan
     * order — i.e. the stride actually applied over a whole plan.
     *
     * @return array<int, int>
     */
    private function sampledIds(int $total, bool $profile, ?int $samples): array
    {
        $stride = PullFromSource::profileStride($total, $profile, $samples);

        $ids = [];
        for ($chunkId = 0; $chunkId < $total; $chunkId++) {
            if (PullFromSource::shouldProfile($chunkId, $stride)) {
                $ids[] = $chunkId;
            }
        }

        return $ids;
    }

    /**
     * @test
     */
    public function without_profiling_the_stride_is_zero_and_no_chunk_is_ever_profiled(): void
    {
        $this->assertSame(0, PullFromSource::profileStride(1000, false, null));

        // Sampling is a *narrowing* knob: it can only ever remove profiling from
        // chunks, never add it to a run that never asked for it. A sample count
        // that is not usable therefore must not switch profiling on by itself.
        $this->assertSame(0, PullFromSource::profileStride(1000, false, 0));
        $this->assertSame(0, PullFromSource::profileStride(1000, false, -7));

        $this->assertSame([], $this->sampledIds(1000, false, null));

        // Chunk id 0 is the interesting one: `0 % 0` is a division by zero, so
        // the stride > 0 guard in shouldProfile() is load-bearing, not decoration.
        $this->assertFalse(PullFromSource::shouldProfile(0, 0));
        foreach ([0, 1, 2, 7, 999, 100000] as $chunkId) {
            $this->assertFalse(
                PullFromSource::shouldProfile($chunkId, 0),
                "stride 0 must never profile chunk {$chunkId}"
            );
        }
    }

    /**
     * @test
     */
    public function profile_without_a_sample_count_profiles_every_single_chunk(): void
    {
        // THE INTERNAL CONTRACT FOR THE BARE BOOL. No command line produces this
        // pair — profiling is asked for with --profile-samples, and the bool is
        // derived from it — but the two travel separately inside the system (every
        // continuation hop and the reaper are handed both), so "profiling on, no
        // target" has to keep meaning "every chunk", byte for byte, whatever the
        // plan size. The alternative failure is silent: a hop that read it as
        // "nothing" would stop profiling halfway through a run.
        $this->assertSame(1, PullFromSource::profileStride(1, true, null));
        $this->assertSame(1, PullFromSource::profileStride(6, true, null));
        $this->assertSame(1, PullFromSource::profileStride(100000, true, null));

        $this->assertSame(range(0, 999), $this->sampledIds(1000, true, null));

        foreach ([0, 1, 2, 3, 17, 999, 100000] as $chunkId) {
            $this->assertTrue(
                PullFromSource::shouldProfile($chunkId, 1),
                "stride 1 must profile chunk {$chunkId}"
            );
        }
    }

    /**
     * @test
     */
    public function an_unusable_sample_count_leaves_profile_meaning_every_chunk(): void
    {
        // A garbage target on a payload that already says "profiling is on" reads
        // as no target at all, which lands back on the every-chunk case above
        // rather than on silence. The command cannot produce this (an unusable
        // --profile-samples leaves the run unprofiled outright), but a payload can:
        // a job enqueued by an older release, or a hop deserialized with a count
        // that no longer makes sense. Nothing worse than "profiles more than asked"
        // may come out of it.
        $this->assertSame(1, PullFromSource::profileStride(100000, true, 0));
        $this->assertSame(1, PullFromSource::profileStride(100000, true, -5));
        $this->assertSame(1, PullFromSource::profileStride(100000, true, null));
    }

    /**
     * @test
     */
    public function a_sample_target_spreads_the_samples_across_the_whole_plan(): void
    {
        // The PERF-scale case: ~100k chunks, 100 samples wanted.
        $stride = PullFromSource::profileStride(100000, false, 100);
        $this->assertSame(1000, $stride);

        $sampled = $this->sampledIds(100000, false, 100);

        // Asked for 100, got exactly 100.
        $this->assertCount(100, $sampled);

        // Spread, not clustered at the head of the plan. The first few chunks are
        // the unrepresentative ones (low ids, cold caches) and the heavy tail is
        // what drives p99, so both ends have to be represented.
        $this->assertSame(0, $sampled[0]);
        $this->assertSame(99000, $sampled[99]);

        $firstDecile = array_filter($sampled, function (int $id): bool {
            return $id < 10000;
        });
        $lastDecile = array_filter($sampled, function (int $id): bool {
            return $id >= 90000;
        });
        $this->assertCount(10, $firstDecile, 'the first decile of the plan must hold its even share of samples');
        $this->assertCount(10, $lastDecile, 'the last decile of the plan must hold its even share of samples');

        // Evenly spaced, which is the property "spread across the plan" actually
        // means: every gap between consecutive samples is exactly one stride.
        $gaps = [];
        for ($i = 1; $i < count($sampled); $i++) {
            $gaps[] = $sampled[$i] - $sampled[$i - 1];
        }
        $this->assertSame([$stride], array_values(array_unique($gaps)));
    }

    /**
     * @test
     */
    public function the_sample_count_wins_when_profile_is_also_given(): void
    {
        // Both carried at once — which is what every profiled run now looks like,
        // since the command derives the bool from the target: the target decides the
        // stride and the bool adds nothing on top of it, so `--profile-samples=10`
        // is a sampled run and not a full one.
        $this->assertSame(100, PullFromSource::profileStride(1000, true, 10));
        $this->assertSame(100, PullFromSource::profileStride(1000, false, 10));
        $this->assertSame(
            $this->sampledIds(1000, true, 10),
            $this->sampledIds(1000, false, 10),
            'the profile bool must not change which chunks a sampled run profiles'
        );
    }

    /**
     * @test
     */
    public function asking_for_more_samples_than_there_are_chunks_profiles_every_chunk(): void
    {
        // intdiv(50, 100) is 0, and a stride of 0 would mean "profile nothing" —
        // the exact opposite of what asking for more samples means. It degrades
        // upwards to every chunk instead.
        $this->assertSame(1, PullFromSource::profileStride(50, false, 100));
        $this->assertSame(1, PullFromSource::profileStride(1, false, 100));
        $this->assertSame(1, PullFromSource::profileStride(99, false, 100));

        $this->assertSame(range(0, 49), $this->sampledIds(50, false, 100));

        // This degradation is exactly what makes `--profile-samples=all` free: the
        // command resolves "all" to PHP_INT_MAX, a target no plan can ever exceed,
        // so "every chunk" needs no flag, no mode and no branch of its own here.
        $this->assertSame(1, PullFromSource::profileStride(100000, false, PHP_INT_MAX));
        $this->assertSame(1, PullFromSource::profileStride(100000, true, PHP_INT_MAX));
        $this->assertSame(1, PullFromSource::profileStride(1, false, PHP_INT_MAX));
        $this->assertSame(range(0, 49), $this->sampledIds(50, false, PHP_INT_MAX));
    }

    /**
     * @test
     */
    public function a_sample_target_never_yields_fewer_samples_than_requested(): void
    {
        // A stride is floor(total / samples), so the real count can overshoot the
        // target (1000 chunks / 300 samples => stride 3 => 334 samples) but can
        // never undershoot it. "Roughly this many chunks" is a floor, never a cap
        // that silently loses coverage.
        foreach ([[1000, 300], [1000, 7], [17, 5], [100000, 100], [100000, 3]] as $case) {
            [$total, $samples] = $case;

            $this->assertGreaterThanOrEqual(
                $samples,
                count($this->sampledIds($total, false, $samples)),
                "a plan of {$total} chunks must yield at least {$samples} samples"
            );
        }

        $this->assertCount(334, $this->sampledIds(1000, false, 300));
    }

    /**
     * @test
     */
    public function a_degenerate_total_or_sample_count_produces_a_sane_stride(): void
    {
        // total <= 0 happens for real: an empty table plans zero chunks, and a
        // continuation hop reads its total from a payload that can carry null
        // (cast to 0). Dividing by the sample count is fine here, but the guard
        // keeps the answer meaningful instead of accidental.
        $this->assertSame(1, PullFromSource::profileStride(0, true, null));
        $this->assertSame(1, PullFromSource::profileStride(0, true, 100));
        $this->assertSame(1, PullFromSource::profileStride(0, false, 100));
        $this->assertSame(1, PullFromSource::profileStride(-5, true, 10));

        // ...and with profiling off, nothing switches it on, degenerate or not.
        $this->assertSame(0, PullFromSource::profileStride(0, false, 0));
        $this->assertSame(0, PullFromSource::profileStride(-5, false, null));

        // No chunks means no decisions to make either way.
        $this->assertSame([], $this->sampledIds(0, true, 100));
    }
}
