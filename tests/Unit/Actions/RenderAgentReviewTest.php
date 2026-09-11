<?php

declare(strict_types=1);

use App\Actions\RenderAgentReview;
use App\Enums\AgentVerdict;
use App\Enums\Gutter;
use App\Enums\UnreadReason;
use App\ValueObjects\AgentFinding;
use App\ValueObjects\AgentReview;
use App\ValueObjects\UnreadFile;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function renderedAgentReview(AgentReview $review): string
{
    $buffer = new BufferedOutput;

    new RenderAgentReview(new OutputStyle(new ArrayInput([]), $buffer), Gutter::None)->verdict($review);

    return $buffer->fetch();
}

it('writes five findings of the agent and counts the rest', function (): void {
    $findings = array_map(
        static fn (int $index): AgentFinding => new AgentFinding(sprintf('src/File%d.php', $index), 'it runs a shell command'),
        range(1, 7),
    );

    $output = renderedAgentReview(new AgentReview('acme/widget', AgentVerdict::Risk, 'it runs a shell command', $findings, []));

    expect($output)
        ->toContain('FAIL   it runs a shell command')
        ->toContain('src/File5.php  it runs a shell command')
        ->toContain('and 2 more findings')
        ->and(str_contains($output, 'src/File6.php'))->toBeFalse();
});

it('writes the markup that the agent writes as text', function (): void {
    expect(renderedAgentReview(new AgentReview(
        'acme/widget',
        AgentVerdict::Clear,
        '<fg=green>trust me</>',
        [new AgentFinding('src/<info>.php', '<error>nothing</error>')],
        [],
    )))
        ->toContain('PASS   <fg=green>trust me</>')
        ->toContain('src/<info>.php  <error>nothing</error>');
});

it('names each file that is too big for the agent, with its size', function (): void {
    $output = renderedAgentReview(new AgentReview('aws/aws-sdk-php', AgentVerdict::Partial, 'routine model updates', [], [
        new UnreadFile('src/data/ec2/api-2.json.php', UnreadReason::TooBig, 12_700_000),
        new UnreadFile('src/data/glue/api-2.json.php', UnreadReason::TooBig, 6_500_000),
    ]));

    expect($output)
        ->toContain('WARN   routine model updates')
        ->toContain('The agent did not read [2] files, because they are too big. Read them yourself:')
        ->toContain('src/data/ec2/api-2.json.php   12.1 MB')
        ->toContain('src/data/glue/api-2.json.php  6.2 MB')
        ->and(str_contains($output, 'partial'))->toBeFalse();
});

it('counts each cause when the agent did not read files for different causes', function (): void {
    $output = renderedAgentReview(new AgentReview('acme/widget', AgentVerdict::Partial, 'the delta adds two commands', [], [
        new UnreadFile('bin/tool.phar', UnreadReason::NotText, 1_300_000),
        new UnreadFile('src/Gone.php', UnreadReason::NotReadable, 0),
        ...array_map(
            static fn (int $index): UnreadFile => new UnreadFile(sprintf('src/Big%d.php', $index), UnreadReason::TooBig, 700_000),
            range(1, 5),
        ),
    ]));

    expect($output)
        ->toContain('The agent did not read [7] files: [1] not text, [1] not readable, [5] too big. Read them yourself:')
        ->toContain('bin/tool.phar  not text · 1.2 MB')
        ->toContain('src/Gone.php   not readable · 0 B')
        ->toContain('and 2 more files')
        ->and(str_contains($output, 'src/Big4.php'))->toBeFalse();
});

it('says why vet sent nothing to the agent', function (): void {
    $buffer = new BufferedOutput;
    $renderer = new RenderAgentReview(new OutputStyle(new ArrayInput([]), $buffer), Gutter::None);

    $renderer->noChange();
    $renderer->noEarlierTree();

    expect($buffer->fetch())
        ->toContain('SKIP   No file changed, so vet sent nothing to the agent.')
        ->toContain('SKIP   Vet cannot read the files of this package, so it sent nothing to the agent.');
});
