<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_returns_api_info_as_json(): void
    {
        $this->get('/')->assertOk()->assertJsonStructure(['name', 'api']);
    }

    public function test_unknown_route_returns_json_404(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
    }
}
