<?php

use App\Enums\AssetType;
use App\Jobs\DownloadAsset;
use App\Models\WpOrg\Asset;
use App\Services\Downloads\DownloadService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    Http::fake();
});

describe('DownloadService on local storage', function () {
    it('streams local file when asset exists in storage', function () {
        // Arrange
        $asset = Asset::factory()->create([
            'asset_type' => AssetType::PLUGIN_ZIP->value,
            'slug'       => 'test-plugin',
            'local_path' => 'plugins/test-plugin/test-plugin.1.0.0.zip',
        ]);

        Storage::put($asset->local_path, 'test content');

        $service = new DownloadService();

        // Act
        $response = $service->download(
            AssetType::PLUGIN_ZIP,
            'test-plugin',
            'test-plugin.1.0.0.zip'
        );

        // Assert
        expect($response)
            ->toBeInstanceOf(StreamedResponse::class)
            ->and($response->headers->get('Content-Type'))->toBe('application/zip')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
    });

    it('proxies and queues download when asset does not exist locally', function () {
        // Arrange
        Http::fake([
            'wordpress.org/*' => Http::response('remote content', 200, [
                'Content-Type'   => 'application/zip',
                'Content-Length' => 13,
            ]),
        ]);

        $service = new DownloadService();

        // Act
        $response = $service->download(
            AssetType::PLUGIN_ZIP,
            'test-plugin',
            'test-plugin.1.0.0.zip'
        );

        // Assert
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'wordpress.org');
        });

        Queue::assertPushed(DownloadAsset::class, function ($job) {
            return $job->slug === 'test-plugin'
                   && $job->file === 'test-plugin.1.0.0.zip';
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('Content-Type'))->toBe('application/zip')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
    });

    it('handles images differently from zip files', function () {
        // Arrange
        Http::fake([
            'ps.w.org/*' => Http::response('image content', 200, [
                'Content-Type'   => 'image/png',
                'Content-Length' => 12,
            ]),
        ]);

        $service = new DownloadService();

        // Act
        $response = $service->download(
            AssetType::SCREENSHOT,
            'test-plugin',
            'screenshot-1.png'
        );

        // Assert
        expect($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('Content-Disposition'))->toBeNull();
    });

    it('downloads and stores asset correctly', function () {
        // Arrange
        Http::fake([
            '*' => Http::response('file content'),
        ]);

        $job = new DownloadAsset(
            AssetType::PLUGIN_ZIP,
            'test-plugin',
            'test-plugin.1.0.0.zip',
            'https://downloads.wordpress.org/plugin/test-plugin.1.0.0.zip'
        );

        // Act
        $job->handle();

        // Assert
        Storage::assertExists('plugins/test-plugin/test-plugin.1.0.0.zip');
        expect(Asset::count())->toBe(1);

        $asset = Asset::first();
        expect($asset->asset_type->value)->toBe(AssetType::PLUGIN_ZIP->value)
            ->and($asset->slug)->toBe('test-plugin')
            ->and($asset->version)->toBe('1.0.0');
    });
})->skip(fn() => config('filesystems.default') !== 'local');

describe('S3 Asset Storage', function () {
    beforeEach(function () {
        Storage::fake('s3');
        Http::fake();
        Queue::fake();
    });

    it('stores downloaded plugin in S3', function () {
        // Arrange
        Storage::fake('s3');
        $pluginContent = '';
        Http::fake([
            '*' => Http::response($pluginContent, 200, [
                'Content-Type'   => 'application/zip',
                'Content-Length' => strlen($pluginContent),
            ]),
        ]);

        // Act
        $job = new DownloadAsset(
            AssetType::PLUGIN_ZIP,
            'test-plugin',
            'test-plugin.1.0.0.zip',
            'https://downloads.wordpress.org/plugin/test-plugin.1.0.0.zip'
        );

        $job->handle();

        // Assert
        Storage::disk('s3')->assertExists('plugins/test-plugin/test-plugin.1.0.0.zip');
        expect(Storage::disk('s3')->get('plugins/test-plugin/test-plugin.1.0.0.zip'))
            ->toBe($pluginContent);
    });

    it('stores downloaded theme in S3', function () {
        // Arrange
        Storage::fake('s3');
        $themeContent = '';
        Http::fake([
            '*' => Http::response($themeContent, 200, [
                'Content-Type'   => 'application/zip',
                'Content-Length' => strlen($themeContent),
            ]),
        ]);

        // Act
        $job = new DownloadAsset(
            AssetType::THEME_ZIP,
            'test-theme',
            'test-theme.1.0.0.zip',
            'https://downloads.wordpress.org/theme/test-theme.1.0.0.zip'
        );

        $job->handle();

        // Assert
        Storage::disk('s3')->assertExists('themes/test-theme/test-theme.1.0.0.zip');
        expect(Storage::disk('s3')->get('themes/test-theme/test-theme.1.0.0.zip'))
            ->toBe($themeContent);
    });

    it('stores asset images in S3', function () {
        // Arrange
        Storage::fake('s3');
        $imageContent = '';
        Http::fake([
            '*' => Http::response($imageContent, 200, [
                'Content-Type'   => 'image/png',
                'Content-Length' => strlen($imageContent),
            ]),
        ]);

        // Act
        $job = new DownloadAsset(
            AssetType::SCREENSHOT,
            'test-plugin',
            'screenshot-1.png',
            'https://ps.w.org/test-plugin/assets/screenshot-1.png'
        );

        $job->handle();

        // Assert
        Storage::disk('s3')->assertExists('assets/test-plugin/screenshot-1.png');
        expect(Storage::disk('s3')->get('assets/test-plugin/screenshot-1.png'))
            ->toBe($imageContent);
    });

    it('creates correct S3 URL for assets', function () {
        // Arrange
        Storage::fake('s3');
        $imageContent = '';
        Http::fake([
            '*' => Http::response($imageContent, 200),
        ]);

        // Act
        $job = new DownloadAsset(
            AssetType::BANNER,
            'test-plugin',
            'banner-772x250.jpg',
            'https://ps.w.org/test-plugin/assets/banner-772x250.jpg'
        );

        $job->handle();

        // Assert
        $asset = Asset::first();
        $url   = Storage::disk('s3')->url($asset->local_path);

        expect($url)->toContain('banner-772x250.jpg')
            ->toContain('test-plugin');
    });

    it('downloads and stores asset correctly on S3', function () {
        // Arrange
        Storage::fake('s3');
        $fileContent = '';
        Http::fake([
            '*' => Http::response('', 200, [
                'Content-Type'   => 'application/zip',
                'Content-Length' => strlen($fileContent),
            ]),
        ]);

        $job = new DownloadAsset(
            AssetType::PLUGIN_ZIP,
            'test-plugin',
            'test-plugin.1.0.0.zip',
            'https://downloads.wordpress.org/plugin/test-plugin.1.0.0.zip'
        );

        // Act
        $job->handle();

        // Assert
        Storage::disk('s3')->assertExists('plugins/test-plugin/test-plugin.1.0.0.zip');
        expect(Storage::disk('s3')->get('plugins/test-plugin/test-plugin.1.0.0.zip'))
            ->toBe($fileContent)
            ->and(Asset::count())->toBe(1);

        $asset = Asset::first();

        expect($asset->asset_type->value)->toBe(AssetType::PLUGIN_ZIP->value)
            ->and($asset->slug)->toBe('test-plugin')
            ->and($asset->version)->toBe('1.0.0')
            ->and($asset->local_path)->toBe('plugins/test-plugin/test-plugin.1.0.0.zip');
    });
})->skip(fn() => config('filesystems.default') !== 's3');

describe('DownloadAsset Job', function () {
    it('extracts version correctly from different file patterns', function () {
        // Arrange
        Storage::fake('local');
        Http::fake([
            '*' => Http::response('file content'),
        ]);

        // Test core version extraction
        $coreJob = new DownloadAsset(
            AssetType::CORE_ZIP,
            'wordpress',
            'wordpress-6.4.2.zip',
            'https://wordpress.org/wordpress-6.4.2.zip'
        );
        $coreJob->handle();

        // Test plugin version extraction
        $pluginJob = new DownloadAsset(
            AssetType::PLUGIN_ZIP,
            'test-plugin',
            'test-plugin.2.1.0.zip',
            'https://downloads.wordpress.org/plugin/test-plugin.2.1.0.zip'
        );
        $pluginJob->handle();

        // Assert
        $coreAsset   = Asset::where('asset_type', AssetType::CORE_ZIP->value)->first();
        $pluginAsset = Asset::where('asset_type', AssetType::PLUGIN_ZIP->value)->first();

        expect($coreAsset->version)->toBe('6.4.2')
            ->and($pluginAsset->version)->toBe('2.1.0');
    });
});

describe('Download Routes', function () {
    it('handles WordPress core download requests', function () {
        $response = $this->get('/wordpress-6.4.2.zip');

        expect($response->status())->toBe(200);
        Queue::assertPushed(DownloadAsset::class, function ($job) {
            return $job->type === AssetType::CORE_ZIP
                   && str_contains($job->file, 'wordpress-6.4.2.zip');
        });
    });

    it('handles plugin download requests', function () {
        $response = $this->get('/plugin/test-plugin.1.0.0.zip');

        expect($response->status())->toBe(200);
        Queue::assertPushed(DownloadAsset::class, function ($job) {
            return $job->type === AssetType::PLUGIN_ZIP
                   && $job->slug === 'test-plugin';
        });
    });

    it('handles theme download requests', function () {
        $response = $this->get('/theme/test-theme.1.0.0.zip');

        expect($response->status())->toBe(200);
        Queue::assertPushed(DownloadAsset::class, function ($job) {
            return $job->type === AssetType::THEME_ZIP
                   && $job->slug === 'test-theme';
        });
    });

    it('handles asset download requests', function () {
        $response = $this->get('/test-plugin/assets/screenshot-1.png');

        expect($response->status())->toBe(200);
        Queue::assertPushed(DownloadAsset::class, function ($job) {
            return $job->type === AssetType::SCREENSHOT
                   && $job->slug === 'test-plugin'
                   && $job->file === 'screenshot-1.png';
        });
    });

    it('handles asset download requests with revision', function () {
        // Todo: this is failing check why
        $response = $this->get('/test-plugin/assets/banner-1544x500.png?rev=3164133');

        expect($response->status())->toBe(200);
        Queue::assertPushed(DownloadAsset::class, function ($job) {
            return $job->type === AssetType::BANNER
                   && $job->slug === 'test-plugin'
                   && $job->revision === '3164133';
        });
    });
});
