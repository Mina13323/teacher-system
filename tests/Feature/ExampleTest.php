<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * The SPA entrypoint is build output (gitignored), so point the public
     * path at a temporary directory holding a stub index.html.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $publicPath = sys_get_temp_dir().'/spa-public-'.uniqid();
        File::ensureDirectoryExists($publicPath);
        File::put($publicPath.'/index.html', '<!DOCTYPE html><div id="app"></div>');
        $this->app->usePublicPath($publicPath);

        try {
            $response = $this->get('/');

            $response->assertStatus(200);
        } finally {
            File::deleteDirectory($publicPath);
        }
    }
}
