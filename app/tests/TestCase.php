<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\BuildsLedgerDatabase;

abstract class TestCase extends BaseTestCase
{
    use BuildsLedgerDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerDatabase();
    }
}
