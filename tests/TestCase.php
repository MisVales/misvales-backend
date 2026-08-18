<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Previene que RefreshDatabase ejecute migrate:fresh en la base de datos
     */
    public function refreshTestDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }
}
