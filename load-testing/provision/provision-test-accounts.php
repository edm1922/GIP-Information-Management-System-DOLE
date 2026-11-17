<?php

/**
 * Provisions dedicated load-testing accounts for GIMS.
 *
 *   php load-testing/provision/provision-test-accounts.php --count=20
 *   php load-testing/provision/provision-test-accounts.php --cleanup
 *
 * Behaviour:
 *  - creates (or resets) users named <prefix>01..<prefix>NN with role peso_manager,
 *    is_active = true and must_change_password = false, so they can log in and
 *    browse the read-only reviewer screens;
 *  - writes load-testing/accounts.json (gitignored) for k6 to consume;
 *  - never prints passwords;
 *  - refuses to run against a production environment unless --force is given;
 *  - --cleanup force-deletes only users matching the configured prefix and
 *    removes the generated accounts file.
 *
 * No application code is modified by this script.
 */

use App\Models\User;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$options = [
    'count' => 20,
    'prefix' => 'loadtest',
    'output' => dirname(__DIR__).DIRECTORY_SEPARATOR.'accounts.json',
    'cleanup' => false,
    'force' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--cleanup') {
        $options['cleanup'] = true;
        continue;
    }
    if ($argument === '--force') {
        $options['force'] = true;
        continue;
    }
    if (preg_match('/^--(count|prefix|output)=(.*)$/', $argument, $matches)) {
        $options[$matches[1]] = $matches[2];
    }
}

$options['count'] = max(1, (int) $options['count']);
$options['prefix'] = preg_replace('/[^a-z0-9_]/i', '', (string) $options['prefix']) ?: 'loadtest';

$environment = app()->environment();
if ($environment === 'production' && ! $options['force']) {
    fwrite(STDERR, "Refusing to run against the production environment (pass --force to override).\n");
    exit(1);
}

function loadTestUsernames(string $prefix, int $count): array
{
    $usernames = [];
    for ($index = 1; $index <= $count; $index++) {
        $usernames[] = $prefix.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }

    return $usernames;
}

if ($options['cleanup']) {
    $usernames = loadTestUsernames($options['prefix'], (int) $options['count']);
    $removed = User::withTrashed()->whereIn('username', $usernames)->forceDelete();

    if (is_file($options['output'])) {
        unlink($options['output']);
    }

    echo "Cleanup complete: removed {$removed} account(s) with prefix '{$options['prefix']}'.\n";
    echo "Removed generated accounts file: {$options['output']}\n";
    exit(0);
}

$accounts = [];
$created = 0;
$updated = 0;

foreach (loadTestUsernames($options['prefix'], (int) $options['count']) as $index => $username) {
    $password = bin2hex(random_bytes(9));

    $user = User::withTrashed()->where('username', $username)->first();

    if ($user) {
        $user->forceFill([
            'first_name' => 'Load',
            'last_name' => sprintf('Test %02d', $index + 1),
            'password' => Hash::make($password),
            'role' => 'peso_manager',
            'is_active' => true,
            'must_change_password' => false,
            'deleted_at' => null,
        ])->save();
        $updated++;
    } else {
        User::create([
            'first_name' => 'Load',
            'last_name' => sprintf('Test %02d', $index + 1),
            'username' => $username,
            'password' => Hash::make($password),
            'role' => 'peso_manager',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $created++;
    }

    $accounts[] = ['username' => $username, 'password' => $password];
}

$document = [
    'generated_at' => date(DATE_ATOM),
    'environment' => $environment,
    'role' => 'peso_manager',
    'note' => 'Load-test accounts. Keep this file local: it contains credentials and is gitignored.',
    'accounts' => $accounts,
];

$directory = dirname($options['output']);
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}

file_put_contents(
    $options['output'],
    json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
);

echo "Provisioned {$options['count']} load-test account(s) (role: peso_manager).\n";
echo "  created: {$created}, reset: {$updated}\n";
echo "  credentials file: {$options['output']} (gitignored)\n";
echo "\nCleanup when finished:\n";
echo '  php load-testing/provision/provision-test-accounts.php --cleanup'."\n";
