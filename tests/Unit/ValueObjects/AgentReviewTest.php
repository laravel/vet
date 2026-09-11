<?php

declare(strict_types=1);

use App\Enums\AgentVerdict;
use App\Enums\UnreadReason;
use App\ValueObjects\AgentReview;
use App\ValueObjects\UnreadFile;

/**
 * @param  array<int, UnreadFile>  $unread
 */
function reviewWithUnread(array $unread): AgentReview
{
    return new AgentReview('acme/widget', AgentVerdict::Partial, 'nothing', [], $unread);
}

it('names the files that the agent did not read in a few words', function (): void {
    $big = new UnreadFile('src/Big.php', UnreadReason::TooBig, 500_000);
    $other = new UnreadFile('src/Other.php', UnreadReason::TooBig, 500_000);
    $phar = new UnreadFile('bin/tool.phar', UnreadReason::NotText, 10);

    expect(reviewWithUnread([])->unreadNote())->toBe('')
        ->and(reviewWithUnread([$big])->unreadNote())->toBe('1 file too big')
        ->and(reviewWithUnread([$big, $other])->unreadNote())->toBe('2 files too big')
        ->and(reviewWithUnread([$big, $phar])->unreadNote())->toBe('2 files not read');
});
