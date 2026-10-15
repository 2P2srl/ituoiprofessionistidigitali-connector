<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use ITuoiProfessionistiDigitali\Connector\Outbox\ProfessionalOutbox;
use ITuoiProfessionistiDigitali\Connector\Outbox\TransactionOutbox;

/**
 * The public API: what the README describes and the applications use, a namespace when it ends with a backslash.
 * Everything else in src/ is @internal.
 */
const PUBLIC_API = [
    'Concerns\\',
    'ConnectorConfig',
    'Contract',
    'Contracts\\',
    'Data\\',
    'Enums\\',
    'Events\\',
    'Exceptions\\',
    'Facades\\',
    'Models\\PlatformProfessionalOutbox',
    'Models\\PlatformTransactionOutbox',
    'Outbox\\',
    'PlatformClient',
    'Signature\\',
    'Testing\\',
    'Validation\\Rules\\',
];

/**
 * Every class, interface, trait and enum of src/, by its name relative to the namespace of the package.
 *
 * @return array<string, array{string}>
 */
function packageClasses(): array
{
    $src = dirname(__DIR__).'/src/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    $classes = [];

    foreach ($files as $file)
    {
        $name = Str::of($file->getPathname())->after($src)->beforeLast('.php')->replace('/', '\\')->toString();
        $classes[$name] = [$name];
    }

    ksort($classes);

    return $classes;
}

it('marks @internal everything outside the public API', function (string $name): void {
    $isPublic = collect(PUBLIC_API)->contains(fn (string $public): bool => $public === $name || (str_ends_with($public, '\\') && str_starts_with($name, $public)));
    $docComment = (string) (new ReflectionClass('ITuoiProfessionistiDigitali\\Connector\\'.$name))->getDocComment();

    expect(str_contains($docComment, '@internal'))->toBe(!$isPublic);
})->with(packageClasses());

it('marks @internal the methods of the outboxes the package calls by itself', function (string $class, string $method): void {
    expect((string) (new ReflectionMethod($class, $method))->getDocComment())->toContain('@internal');
})->with([
    [TransactionOutbox::class, 'record'],
    [TransactionOutbox::class, 'confirmed'],
    [TransactionOutbox::class, 'changedByPlatform'],
    [ProfessionalOutbox::class, 'resume'],
    [ProfessionalOutbox::class, 'hasUnregisteredTransactions'],
]);
