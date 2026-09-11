<?php

declare(strict_types=1);

use App\Actions\RenderAgentReview;
use App\Enums\AgentVerdict;
use App\Enums\Gutter;
use App\ValueObjects\AgentFinding;
use App\ValueObjects\AgentReview;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('writes five findings of the agent and counts the rest', function (): void {
    $buffer = new BufferedOutput;

    $findings = array_map(
        static fn (int $index): AgentFinding => new AgentFinding(sprintf('src/File%d.php', $index), 'it runs a shell command'),
        range(1, 7),
    );

    (new RenderAgentReview(new OutputStyle(new ArrayInput([]), $buffer), Gutter::None))
        ->verdict(new AgentReview('acme/widget', AgentVerdict::Risk, 'it runs a shell command', $findings));

    $output = $buffer->fetch();

    expect($output)
        ->toContain('agent  RISK  it runs a shell command')
        ->toContain('src/File5.php  it runs a shell command')
        ->toContain('and 2 more finding(s)')
        ->and(str_contains($output, 'src/File6.php'))->toBeFalse();
});

it('writes the markup that the agent writes as text', function (): void {
    $buffer = new BufferedOutput;

    (new RenderAgentReview(new OutputStyle(new ArrayInput([]), $buffer), Gutter::None))->verdict(new AgentReview(
        'acme/widget',
        AgentVerdict::Clear,
        '<fg=green>trust me</>',
        [new AgentFinding('src/<info>.php', '<error>nothing</error>')],
    ));

    expect($buffer->fetch())
        ->toContain('agent  clear  <fg=green>trust me</>')
        ->toContain('src/<info>.php  <error>nothing</error>');
});

it('says why vet sent nothing to the agent', function (): void {
    $buffer = new BufferedOutput;
    $renderer = new RenderAgentReview(new OutputStyle(new ArrayInput([]), $buffer), Gutter::None);

    $renderer->noChange();
    $renderer->noEarlierTree();

    expect($buffer->fetch())
        ->toContain('agent  not sent  This delta holds no change, so vet sent nothing.')
        ->toContain('agent  not sent  Vet cannot read the bytes of this package, so it sent nothing.');
});
