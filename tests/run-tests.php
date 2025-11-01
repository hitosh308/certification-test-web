<?php
declare(strict_types=1);

// Ensure a predictable request environment when including the main script.
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

ob_start();
require __DIR__ . '/../index.php';
ob_end_clean();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/** @var array<int, array{0: string, 1: callable}> $tests */
$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[] = [$name, $callback];
}

function assertSameValue($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $exportExpected = var_export($expected, true);
        $exportActual = var_export($actual, true);
        $prefix = $message !== '' ? $message . '\n' : '';
        throw new RuntimeException($prefix . "Expected {$exportExpected} but got {$exportActual}.");
    }
}

function assertTrue($condition, string $message = ''): void
{
    if ($condition !== true) {
        throw new RuntimeException($message !== '' ? $message : 'Failed asserting that condition is true.');
    }
}

// ---- Tests ----

test('normalizeCategoryIdentifier sanitizes and lowercases labels', function (): void {
    assertSameValue('basic-it_passport', normalizeCategoryIdentifier('  Basic-IT Passport! '));
    assertSameValue('category', normalizeCategoryIdentifier('   '));
    assertSameValue('category', normalizeCategoryIdentifier('カテゴリ'));
});


test('normalizeCategory normalizes string and array inputs', function (): void {
    assertSameValue(
        ['id' => 'aws_services', 'name' => 'AWS Services'],
        normalizeCategory(' AWS Services ')
    );

    assertSameValue(
        ['id' => 'security', 'name' => 'Information Security'],
        normalizeCategory(['id' => '  SECURITY ', 'title' => 'Information Security'])
    );

    assertSameValue(
        ['id' => 'uncategorized', 'name' => 'その他'],
        normalizeCategory(['id' => ' ', 'name' => ''])
    );
});


test('sanitizeDifficultySelection respects available options and falls back to random', function (): void {
    assertSameValue('easy', sanitizeDifficultySelection('easy'));
    assertSameValue(DIFFICULTY_RANDOM, sanitizeDifficultySelection('HARD'));
});


test('normalizeDifficulty maps common labels to canonical values', function (): void {
    assertSameValue('hard', normalizeDifficulty(' HARD '));
    assertSameValue('easy', normalizeDifficulty('優しい'));
    assertSameValue(DEFAULT_DIFFICULTY, normalizeDifficulty('unknown'));
});


test('extractAnswerKeyCandidates splits combined answers and trims whitespace', function (): void {
    assertSameValue(['A', 'B', 'C'], extractAnswerKeyCandidates(['A, B', ' C ']));
    assertSameValue([], extractAnswerKeyCandidates(null));
});


test('normalizeAnswerKeys keeps valid keys in original order without duplicates', function (): void {
    $validKeys = ['A', 'B', 'C'];
    $input = [' C ', 'A', 'Z'];
    assertSameValue(['A', 'C'], normalizeAnswerKeys($input, $validKeys));
});


test('filterQuestionsByDifficulty filters by specific level and handles random', function (): void {
    $questions = [
        ['id' => 'q1', 'difficulty' => 'easy'],
        ['id' => 'q2', 'difficulty' => 'normal'],
        ['id' => 'q3', 'difficulty' => 'hard'],
    ];

    assertSameValue([
        ['id' => 'q1', 'difficulty' => 'easy'],
    ], filterQuestionsByDifficulty($questions, 'easy'));

    assertSameValue($questions, filterQuestionsByDifficulty($questions, DIFFICULTY_RANDOM));
});


test('searchExamsByKeywords returns exams matching all keywords case-insensitively', function (): void {
    $exams = [
        'aws' => [
            'meta' => [
                'id' => 'aws',
                'title' => 'AWS Cloud Practitioner',
                'description' => 'Entry level AWS certification',
                'version' => '2023',
                'category' => ['id' => 'cloud', 'name' => 'Cloud Computing'],
            ],
        ],
        'sec' => [
            'meta' => [
                'id' => 'sec',
                'title' => 'Security Basics',
                'description' => 'Security fundamentals',
                'version' => '2022',
                'category' => ['id' => 'security', 'name' => 'Security'],
            ],
        ],
    ];

    assertSameValue(['aws' => $exams['aws']], searchExamsByKeywords($exams, 'Cloud Practitioner'));
    assertSameValue([], searchExamsByKeywords($exams, 'security advanced'));
});


test('buildPath generates links relative to the executing script', function (): void {
    $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;

    try {
        $_SERVER['SCRIPT_NAME'] = '/practice/index.php';
        unset($_SERVER['REQUEST_URI']);
        assertSameValue('/practice/index.php', buildPath());
        assertSameValue('/practice/index.php/landing', buildPath('landing'));
        assertSameValue(
            '/practice/index.php/home/cloud/aws',
            buildPath('home', 'cloud', 'aws')
        );

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        unset($_SERVER['REQUEST_URI']);
        assertSameValue('/index.php/history', buildPath('history'));
    } finally {
        if ($previousScriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $previousScriptName;
        }

        if ($previousRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $previousRequestUri;
        }
    }
});


test('buildPath omits index.php when using rewritten URLs', function (): void {
    $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;

    try {
        $_SERVER['SCRIPT_NAME'] = '/practice/index.php';
        $_SERVER['REQUEST_URI'] = '/practice/history';
        assertSameValue('/practice', buildPath());
        assertSameValue('/practice/landing', buildPath('landing'));
        assertSameValue('/practice/home/cloud/aws', buildPath('home', 'cloud', 'aws'));

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/history';
        assertSameValue('/landing', buildPath('landing'));
    } finally {
        if ($previousScriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $previousScriptName;
        }

        if ($previousRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $previousRequestUri;
        }
    }
});


test('assetUrl resolves resources within the script directory', function (): void {
    $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;

    try {
        $_SERVER['SCRIPT_NAME'] = '/foo/bar/index.php';
        assertSameValue('/foo/bar/assets/style.css', assetUrl('assets/style.css'));
        assertSameValue('/foo/bar/images/logo.svg', assetUrl('/images/logo.svg'));

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        assertSameValue('/assets/style.css', assetUrl('assets/style.css'));
    } finally {
        if ($previousScriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $previousScriptName;
        }
    }
});

// ---- Runner ----

$passed = 0;
$failed = 0;

foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        echo "PASS: {$name}\n";
        $passed++;
    } catch (Throwable $throwable) {
        $failed++;
        echo "FAIL: {$name}\n";
        echo $throwable->getMessage() . "\n";
    }
}

echo str_repeat('-', 40) . "\n";
echo sprintf("Total: %d, Passed: %d, Failed: %d\n", $passed + $failed, $passed, $failed);

if ($failed > 0) {
    exit(1);
}
