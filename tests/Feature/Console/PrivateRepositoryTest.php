<?php

declare(strict_types=1);

use App\Support\Json;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\CraftedArchive;
use Tests\Fixtures\FakeHttp;
use Tests\Fixtures\PendingUpdate;

function privateArchive(): string
{
    $path = sys_get_temp_dir().'/vet-private-'.bin2hex(random_bytes(6)).'.zip';

    CraftedArchive::write($path, [
        ['name' => 'widget/composer.json', 'contents' => Json::encode(['name' => PendingUpdate::PACKAGE, 'autoload' => ['psr-4' => ['Acme\\Widget\\' => 'src/']]])],
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php // the private widget\n"],
    ]);

    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

function privatePlan(PendingUpdate $project): string
{
    return composerPlanFile([[
        'package' => PendingUpdate::PACKAGE,
        'change' => 'upgrade',
        'from' => PendingUpdate::TRUSTED_VERSION,
        'to' => PendingUpdate::TARGET_VERSION,
        'dist_url' => 'https://repo.acme.test/dists/acme/widget/2.0.0.zip',
        'dist_reference' => 'cccc3333',
        'dist_shasum' => null,
    ]]);
}

it('sends the credential that composer holds for a private repository when it audits a plan', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION, 'cccc3333');

    file_put_contents($project->rootPath.'/auth.json', Json::encode(['http-basic' => [
        'repo.acme.test' => ['username' => 'acme', 'password' => 'secret'],
    ]]));

    $http = new FakeHttp([FakeHttp::body((string) file_get_contents(privateArchive()))]);
    app()->instance(ClientInterface::class, $http->client);

    try {
        $status = withEnvironment(
            ['COMPOSER_HOME' => $project->rootPath.'/no-composer-home', 'COMPOSER_AUTH' => null, 'COMPOSER_AUTH_FILE' => null, 'VET_GITHUB_TOKEN' => null, 'GITHUB_TOKEN' => null, 'GH_TOKEN' => null],
            static fn (): int => vet(['--path' => $project->rootPath, '--plan' => privatePlan($project)]),
        );
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($http->header('https://repo.acme.test/dists/acme/widget/2.0.0.zip', 'Authorization'))->toBe('Basic '.base64_encode('acme:secret'))
        ->and($output)->toContain('acme/widget 1.0.0 → 2.0.0')
        ->and(str_contains($output, 'cannot read those bytes'))->toBeFalse();
});

it('names the auth file when a private repository refuses the download', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION, 'cccc3333');

    $http = new FakeHttp([new Response(401, [], 'unauthorized')]);
    app()->instance(ClientInterface::class, $http->client);

    try {
        $status = withEnvironment(
            ['COMPOSER_HOME' => $project->rootPath.'/no-composer-home', 'COMPOSER_AUTH' => null, 'COMPOSER_AUTH_FILE' => null, 'VET_GITHUB_TOKEN' => null, 'GITHUB_TOKEN' => null, 'GH_TOKEN' => null],
            static fn (): int => vet(['--path' => $project->rootPath, '--plan' => privatePlan($project)]),
        );
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($http->header('https://repo.acme.test/dists/acme/widget/2.0.0.zip', 'Authorization'))->toBeNull()
        ->and($output)
        ->toContain('cannot read those bytes')
        ->toContain('the [auth.json] that composer reads holds none for this host');
});
