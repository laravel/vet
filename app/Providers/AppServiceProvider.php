<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\CacheArtifact;
use App\Actions\DiskCacheArtifact;
use App\Support\ProgressDots;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClientInterface::class, static fn (): ClientInterface => new Client);
        $this->app->bind(CacheArtifact::class, static fn (): CacheArtifact => DiskCacheArtifact::default());
        $this->app->singleton(ProgressDots::class, static fn (): ProgressDots => new ProgressDots(new OutputStyle(new ArrayInput([]), new NullOutput)));
    }
}
