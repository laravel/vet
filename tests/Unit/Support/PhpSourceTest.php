<?php

declare(strict_types=1);

use App\Support\PhpSource;

it('reads a php file whose null byte stands inside a string literal as source', function (): void {
    expect(PhpSource::holdsNoSource('Resources/charset/from.us-ascii.php', "<?php\n\nstatic \$data = array (\n  '\0' => '\0',\n  \"\0\" => \"a{\$b}\0\",\n);\n"))->toBeFalse();
});

it('reads a php file whose null byte stands outside a string literal as no source', function (): void {
    expect(PhpSource::holdsNoSource('src/Widget.php', "<?php\n\0\0payload"))->toBeTrue()
        ->and(PhpSource::holdsNoSource('src/Widget.php', "\0<?php\n"))->toBeTrue();
});

it('reads a file of a different extension that holds a null byte as no source', function (): void {
    expect(PhpSource::holdsNoSource('src/data/table.json', "{\"a\":\"\0\"}"))->toBeTrue();
});

it('reads a file without a null byte as source', function (): void {
    expect(PhpSource::holdsNoSource('src/data/table.json', '{"a":"b"}'))->toBeFalse();
});
