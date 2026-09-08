<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root URL sends visitors into the panel, which turns a guest away at
     * its own door rather than the application deciding that here.
     */
    public function test_the_application_redirects_visitors_to_the_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
