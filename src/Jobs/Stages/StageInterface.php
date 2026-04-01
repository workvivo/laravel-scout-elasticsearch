<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use OpenSearch\Client;

interface StageInterface
{
    public function title(): string;

    public function estimate(): int;

    public function handle(Client $elasticsearch): void;
}
