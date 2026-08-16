<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ApiAuthenticationResponseTest extends TestCase
{
    public function test_api_request_without_accept_header_returns_controlled_json_unauthorized(): void
    {
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'INVALID_SESSION')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }
}
