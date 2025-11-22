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


test('buildClientResultPayload keeps extended exam metadata', function (): void {
    $completedAt = date(DATE_ATOM);
    $results = [
        'exam' => [
            'id' => 'sample',
            'title' => 'Sample Exam',
            'description' => 'Desc',
            'version' => 'v1',
            'difficulty' => 'Advanced',
            'price' => '12000',
            'official_site' => 'https://example.com',
            'question_count' => 1,
            'category' => ['id' => 'cat', 'name' => 'Category'],
        ],
        'questions' => [],
        'total' => 1,
        'correct' => 1,
        'incorrect' => 0,
        'result_id' => 'r1',
    ];

    $payload = buildClientResultPayload($results, 'hard', $completedAt);

    assertSameValue('Advanced', $payload['exam']['difficulty']);
    assertSameValue('12000', $payload['exam']['price']);
    assertSameValue('https://example.com', $payload['exam']['official_site']);
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


test('applicationUrl builds absolute URLs when host information is available', function (): void {
    $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $previousHttps = $_SERVER['HTTPS'] ?? null;

    try {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        unset($_SERVER['REQUEST_URI']);
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';

        assertSameValue('https://example.com/index.php/landing', applicationUrl('landing'));
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

        if ($previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }

        if ($previousHttps === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $previousHttps;
        }
    }
});


test('buildSitemapXml includes landing, manual, history, category, and exam URLs', function (): void {
    $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $previousHttps = $_SERVER['HTTPS'] ?? null;

    try {
        $_SERVER['SCRIPT_NAME'] = '/practice/index.php';
        unset($_SERVER['REQUEST_URI']);
        $_SERVER['HTTP_HOST'] = 'example.com';
        unset($_SERVER['HTTPS']);

        $categories = [
            'cloud' => ['id' => 'cloud', 'name' => 'Cloud', 'exam_ids' => ['aws']],
        ];
        $exams = [
            'aws' => [
                'meta' => [
                    'id' => 'aws',
                    'title' => 'AWS Cloud Practitioner',
                    'category' => ['id' => 'cloud', 'name' => 'Cloud'],
                ],
            ],
        ];

        $xml = buildSitemapXml($categories, $exams);

        assertTrue(strpos($xml, '<?xml version="1.0" encoding="UTF-8"?>') === 0);
        assertTrue(strpos($xml, '<loc>http://example.com/practice/index.php</loc>') !== false);
        assertTrue(strpos($xml, '<loc>http://example.com/practice/index.php/landing</loc>') !== false);
        assertTrue(strpos($xml, '<loc>http://example.com/practice/index.php/manual</loc>') !== false);
        assertTrue(strpos($xml, '<loc>http://example.com/practice/index.php/history</loc>') !== false);
        assertTrue(strpos($xml, '<loc>http://example.com/practice/index.php/home/cloud</loc>') !== false);
        assertTrue(strpos($xml, '<loc>http://example.com/practice/index.php/home/cloud/aws</loc>') !== false);
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

        if ($previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }

        if ($previousHttps === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $previousHttps;
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
