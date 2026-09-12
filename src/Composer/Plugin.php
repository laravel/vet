<?php

declare(strict_types=1);

namespace Laravel\Vet\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Process\Process;

final class Plugin implements EventSubscriberInterface, PluginInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            InstallerEvents::PRE_OPERATIONS_EXEC => 'gate',
            ScriptEvents::POST_INSTALL_CMD => 'audit',
            ScriptEvents::POST_UPDATE_CMD => 'audit',
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public function gate(InstallerEvent $event): void
    {
        if (! $event->isExecutingOperations()) {
            return;
        }

        $io = $event->getIO();
        $gate = $this->gateOf($event->getComposer());

        if ($gate->nested()) {
            return;
        }

        $notice = $gate->firstInstallNotice();

        if ($notice !== null) {
            $io->writeError('<comment>'.$notice.'</comment>');

            return;
        }

        $operations = array_filter(array_map(
            fn (OperationInterface $operation): ?array => $this->operationOf($operation),
            $event->getTransaction()?->getOperations() ?? [],
        ));

        if ($operations === []) {
            return;
        }

        $planPath = $gate->writePlan($operations);

        if ($planPath === null) {
            return;
        }

        try {
            $this->run($gate, $io, $gate->commandWithPlan($io->isVerbose(), $io->isDecorated(), $planPath));
        } finally {
            $gate->deletePlan($planPath);
        }
    }

    public function audit(Event $event): void
    {
        $io = $event->getIO();
        $gate = $this->gateOf($event->getComposer());

        $notice = $gate->baselineNotice();

        if ($notice !== null) {
            $io->writeError('<comment>'.$notice.'</comment>');

            return;
        }

        $this->run($gate, $io, $gate->command($io->isVerbose(), $io->isDecorated()));
    }

    private function run(Gate $gate, IOInterface $io, array $command): void
    {
        if ($command === []) {
            return;
        }

        $process = new Process($command, $gate->rootPath, $gate->environment());
        $process->setTimeout(null);

        if ($io->isInteractive() && Process::isTtySupported()) {
            $process->setTty(true);
            $process->run();
        } else {
            $process->run(static function (string $type, string $buffer) use ($io): void {
                $io->writeRaw($buffer, false);
            });
        }

        if (! $process->isSuccessful()) {
            throw new ScriptExecutionException(
                'Vet found packages that your trust file does not cover.',
                $process->getExitCode() ?? 1,
            );
        }
    }

    private function gateOf(Composer $composer): Gate
    {
        $config = $composer->getConfig();
        $rootPath = (string) getcwd();
        $binDir = $config->get('bin-dir');
        $vendorDir = $config->get('vendor-dir');

        return new Gate(
            $rootPath,
            is_string($binDir) ? $binDir : '',
            is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : $rootPath.'/vendor',
        );
    }

    private function operationOf(OperationInterface $operation): ?array
    {
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();

            return [
                'package' => $package->getName(),
                'change' => 'install',
                'from' => null,
                'to' => $package->getPrettyVersion(),
                'dist_url' => $package->getDistUrl(),
                'dist_reference' => $package->getDistReference(),
                'dist_shasum' => $package->getDistSha1Checksum(),
            ];
        }

        if ($operation instanceof UpdateOperation) {
            $initial = $operation->getInitialPackage();
            $target = $operation->getTargetPackage();

            return [
                'package' => $target->getName(),
                'change' => version_compare($target->getVersion(), $initial->getVersion(), '<')
                    ? 'downgrade'
                    : 'upgrade',
                'from' => $initial->getPrettyVersion(),
                'to' => $target->getPrettyVersion(),
                'dist_url' => $target->getDistUrl(),
                'dist_reference' => $target->getDistReference(),
                'dist_shasum' => $target->getDistSha1Checksum(),
            ];
        }

        if ($operation instanceof UninstallOperation) {
            $package = $operation->getPackage();

            return [
                'package' => $package->getName(),
                'change' => 'remove',
                'from' => $package->getPrettyVersion(),
                'to' => null,
                'dist_url' => null,
                'dist_reference' => null,
                'dist_shasum' => null,
            ];
        }

        return null;
    }
}
