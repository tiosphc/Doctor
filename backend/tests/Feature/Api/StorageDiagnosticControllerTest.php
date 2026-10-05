<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class StorageDiagnosticControllerTest extends TestCase
{
    public function test_it_returns_404_when_the_diagnostic_key_is_not_configured(): void
    {
        config()->set('storage_diagnostics.key', null);

        $this->getJson('/api/internal/diagnostics/storage', ['X-Diagnostic-Key' => 'any-key'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found']);
    }

    public function test_it_returns_403_without_the_correct_header(): void
    {
        config()->set('storage_diagnostics.key', 'expected-key');

        $this->getJson('/api/internal/diagnostics/storage')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden']);

        $this->getJson('/api/internal/diagnostics/storage', ['X-Diagnostic-Key' => 'wrong-key'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden']);
    }

    public function test_it_returns_only_safe_storage_checks_with_the_correct_key(): void
    {
        config()->set('storage_diagnostics.key', 'expected-key');

        $response = $this->getJson('/api/internal/diagnostics/storage', ['X-Diagnostic-Key' => 'expected-key']);

        $response->assertOk()
            ->assertJsonStructure([
                'storage_disk_exists', 'storage_file_exists', 'storage_file_readable',
                'public_storage_exists', 'public_storage_is_link', 'public_storage_is_dir',
                'public_demo_file_exists', 'public_demo_file_readable',
                'storage_realpath_available', 'public_realpath_available',
                'public_storage_targets_storage_disk', 'document_root_available',
                'document_root_is_public', 'document_root_demo_file_exists',
                'filesystem_disk', 'app_url', 'disk_check_exception_class',
                'checks' => [
                    "Storage::disk('public')->exists('products/demo/DEMO-PRD-12.jpg')",
                    "file_exists(storage_path('app/public/products/demo/DEMO-PRD-12.jpg'))",
                    "file_exists(public_path('storage/products/demo/DEMO-PRD-12.jpg'))",
                ],
            ])
            ->assertJsonPath('filesystem_disk', config('filesystems.default'))
            ->assertJsonPath('app_url', config('app.url'));

        $this->assertIsBool($response->json('storage_file_exists'));
        $this->assertIsBool($response->json('public_demo_file_exists'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('expected-key', $response->getContent());
        $this->assertSame([
            'storage_disk_exists', 'storage_file_exists', 'storage_file_readable',
            'public_storage_exists', 'public_storage_is_link', 'public_storage_is_dir',
            'public_demo_file_exists', 'public_demo_file_readable',
            'storage_realpath_available', 'public_realpath_available',
            'public_storage_targets_storage_disk', 'document_root_available',
            'document_root_is_public', 'document_root_demo_file_exists',
            'filesystem_disk', 'app_url', 'disk_check_exception_class', 'checks',
        ], array_keys($response->json()));
    }
}
