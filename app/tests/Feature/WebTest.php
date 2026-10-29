<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebTest extends TestCase
{
    public function test_root_serves_the_compiled_interface(): void
    {
        $this->get('/')->assertOk();
        $this->assertStringContainsString('id="root"', file_get_contents(public_path('ui/index.html')));
    }
}
