<?php
declare(strict_types=1);

session_start();

const DATA_DIRECTORY = __DIR__ . '/data';
const DEFAULT_DIFFICULTY = 'normal';
const DIFFICULTY_LEVELS = [
    'easy' => '優しい',
    'normal' => '普通',
    'hard' => '難しい',
];
const DIFFICULTY_RANDOM = 'random';
const DIFFICULTY_RANDOM_LABEL = 'ランダム';

/**
 * @return array{
 *     exams: array<string, array{
 *         meta: array{
 *             id: string,
 *             title: string,
 *             description: string,
 *             version: string,
 *             question_count: int,
 *             source_file: string,
 *             category: array{id: string, name: string}
 *         },
 *         questions: array<int, array{
 *             id: string,
 *             question: string,
 *             choices: array<int, array{
 *                 key: string,
 *                 text: string,
 *                 explanation: array{text: string, reference: string, reference_label: string}
 *             }>,
 *             answer: string,
 *             explanation: array{text: string, reference: string, reference_label: string}
 *         }>
 *     }>,
 *     categories: array<string, array{id: string, name: string, exam_ids: string[]}>,
 *     errors: string[]
 * }
 */
function loadExamCatalog(): array
{
    $exams = [];
    $errors = [];
    $categories = [];

    if (!is_dir(DATA_DIRECTORY)) {
        $errors[] = '問題データディレクトリが見つかりません。';
        return ['exams' => [], 'categories' => [], 'errors' => $errors];
    }

    $files = glob(DATA_DIRECTORY . '/*.json') ?: [];
    sort($files, SORT_NATURAL);

    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        $json = @file_get_contents($filePath);
        if ($json === false) {
            $errors[] = sprintf('%s を読み取れませんでした。', $fileName);
            continue;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $errors[] = sprintf('%s のJSON形式が不正です。（%s）', $fileName, json_last_error_msg());
            continue;
        }

        if (!isset($data['exam']) || !is_array($data['exam'])) {
            $errors[] = sprintf('%s に exam セクションが見つかりません。', $fileName);
            continue;
        }

        if (!isset($data['questions']) || !is_array($data['questions'])) {
            $errors[] = sprintf('%s に questions セクションが見つかりません。', $fileName);
            continue;
        }

        $examMeta = $data['exam'];
        $examId = isset($examMeta['id']) && is_string($examMeta['id']) && $examMeta['id'] !== ''
            ? $examMeta['id']
            : pathinfo($fileName, PATHINFO_FILENAME);
        if (isset($exams[$examId])) {
            $errors[] = sprintf('試験ID "%s" が重複しています。（%s）', $examId, $fileName);
            continue;
        }

        $title = isset($examMeta['title']) && is_string($examMeta['title']) && $examMeta['title'] !== ''
            ? $examMeta['title']
            : $examId;
        $description = isset($examMeta['description']) && is_string($examMeta['description']) ? $examMeta['description'] : '';
        $version = isset($examMeta['version']) && is_string($examMeta['version']) ? $examMeta['version'] : '';
        $categoryMeta = normalizeCategory($examMeta['category'] ?? null);

        $questions = [];
        $skipped = 0;
        $index = 0;
        foreach ($data['questions'] as $questionData) {
            $index++;
            if (!is_array($questionData)) {
                $skipped++;
                continue;
            }

            $questionText = isset($questionData['question']) ? trim((string)$questionData['question']) : '';
            $rawChoices = $questionData['choices'] ?? null;
            $answer = isset($questionData['answer']) ? (string)$questionData['answer'] : '';

            $questionExplanation = normalizeExplanation($questionData['explanation'] ?? null);

            $choiceExplanations = [];
            if (isset($questionData['choice_explanations']) && is_array($questionData['choice_explanations'])) {
                foreach ($questionData['choice_explanations'] as $choiceKey => $explanationData) {
                    if (is_string($choiceKey) || is_int($choiceKey)) {
                        $choiceExplanations[(string)$choiceKey] = normalizeExplanation($explanationData);
                    }
                }
            }

            if ($questionText === '' || !is_array($rawChoices) || empty($rawChoices) || $answer === '') {
                $skipped++;
                continue;
            }

            $questionId = isset($questionData['id']) && is_string($questionData['id']) && $questionData['id'] !== ''
                ? $questionData['id']
                : sprintf('%s-q%d', $examId, $index);

            $choices = [];
            foreach ($rawChoices as $choiceIndex => $choiceData) {
                if (is_array($choiceData)) {
                    $key = isset($choiceData['key']) ? (string)$choiceData['key'] : '';
                    $text = isset($choiceData['text']) ? (string)$choiceData['text'] : '';
                    $choiceExplanationRaw = $choiceData['explanation'] ?? null;
                    if ($choiceExplanationRaw === null && (isset($choiceData['reference']) || isset($choiceData['reference_label']))) {
                        $choiceExplanationRaw = [
                            'text' => $choiceData['detail'] ?? '',
                            'reference' => $choiceData['reference'] ?? '',
                            'reference_label' => $choiceData['reference_label'] ?? '',
                        ];
                    }
                } else {
                    $key = '';
                    $text = (string)$choiceData;
                    $choiceExplanationRaw = null;
                }

                if ($key === '') {
                    $key = chr(ord('A') + count($choices));
                }

                $text = trim($text);
                if ($text === '') {
                    continue;
                }

                $existingKeys = array_map(static fn ($choice) => $choice['key'], $choices);
                $baseKey = $key;
                $suffix = 1;
                while (in_array($key, $existingKeys, true)) {
                    $key = $baseKey . (++$suffix);
                }

                $choices[] = [
                    'key' => $key,
                    'text' => $text,
                    'explanation' => normalizeExplanation($choiceExplanationRaw ?? ($choiceExplanations[$key] ?? null)),
                ];
            }

            if (count($choices) < 2) {
                $skipped++;
                continue;
            }

            $choiceKeys = array_map(static fn ($choice) => $choice['key'], $choices);
            if (!in_array($answer, $choiceKeys, true)) {
                $skipped++;
                continue;
            }

        $difficulty = normalizeDifficulty($questionData['difficulty'] ?? null);

        $questions[] = [
            'id' => $questionId,
            'question' => $questionText,
            'choices' => $choices,
            'answer' => $answer,
            'explanation' => $questionExplanation,
            'difficulty' => $difficulty,
        ];
        }

        if ($skipped > 0) {
            $errors[] = sprintf('%s で %d 問が読み込めませんでした。', $fileName, $skipped);
        }

        if (empty($questions)) {
            $errors[] = sprintf('%s に有効な問題がありません。', $fileName);
            continue;
        }

        $categoryId = $categoryMeta['id'];
        if (!isset($categories[$categoryId])) {
            $categories[$categoryId] = [
                'id' => $categoryId,
                'name' => $categoryMeta['name'],
                'exam_ids' => [],
            ];
        } elseif ($categoryMeta['name'] !== '' && $categories[$categoryId]['name'] !== '' && $categories[$categoryId]['name'] !== $categoryMeta['name']) {
            $errors[] = sprintf('カテゴリID "%s" の表示名が複数定義されています。（%s）', $categoryId, $fileName);
        }

        if ($categories[$categoryId]['name'] === '' && $categoryMeta['name'] !== '') {
            $categories[$categoryId]['name'] = $categoryMeta['name'];
        }

        $categories[$categoryId]['exam_ids'][] = $examId;

        $exams[$examId] = [
            'meta' => [
                'id' => $examId,
                'title' => $title,
                'description' => $description,
                'version' => $version,
                'question_count' => count($questions),
                'source_file' => $fileName,
                'category' => $categoryMeta,
            ],
            'questions' => $questions,
        ];
    }

    uasort($exams, static function (array $a, array $b): int {
        $aTitle = $a['meta']['title'];
        $bTitle = $b['meta']['title'];
        if (function_exists('mb_strtolower')) {
            $aTitle = mb_strtolower($aTitle, 'UTF-8');
            $bTitle = mb_strtolower($bTitle, 'UTF-8');
        } else {
            $aTitle = strtolower($aTitle);
            $bTitle = strtolower($bTitle);
        }
        return strcmp($aTitle, $bTitle);
    });

    $categoryExamMap = [];
    foreach ($exams as $examId => $exam) {
        $categoryId = $exam['meta']['category']['id'];
        if (!isset($categoryExamMap[$categoryId])) {
            $categoryExamMap[$categoryId] = [];
        }
        $categoryExamMap[$categoryId][] = $examId;
    }

    foreach ($categories as $categoryId => &$category) {
        $category['exam_ids'] = $categoryExamMap[$categoryId] ?? [];
    }
    unset($category);

    $categories = array_filter($categories, static function (array $category): bool {
        return !empty($category['exam_ids']);
    });

    uasort($categories, static function (array $a, array $b): int {
        $aName = $a['name'];
        $bName = $b['name'];
        if (function_exists('mb_strtolower')) {
            $aName = mb_strtolower($aName, 'UTF-8');
            $bName = mb_strtolower($bName, 'UTF-8');
        } else {
            $aName = strtolower($aName);
            $bName = strtolower($bName);
        }
        return strcmp($aName, $bName);
    });

    return ['exams' => $exams, 'categories' => $categories, 'errors' => $errors];
}

function h(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl2brSafe(string $text): string
{
    return nl2br(h($text), false);
}

function normalizeCategoryIdentifier(string $label): string
{
    $normalized = trim($label);
    if ($normalized === '') {
        return 'category';
    }

    $normalized = preg_replace('/[^a-zA-Z0-9_-]+/u', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'category';
    }
    $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;

    if (function_exists('mb_strtolower')) {
        $normalized = mb_strtolower($normalized, 'UTF-8');
    } else {
        $normalized = strtolower($normalized);
    }

    return $normalized;
}

/**
 * @param mixed $value
 * @return array{id: string, name: string}
 */
function normalizeCategory($value): array
{
    $default = ['id' => 'uncategorized', 'name' => 'その他'];

    if ($value === null) {
        return $default;
    }

    if (is_string($value)) {
        $name = trim($value);
        if ($name === '') {
            return $default;
        }

        return [
            'id' => normalizeCategoryIdentifier($name),
            'name' => $name,
        ];
    }

    $id = '';
    $name = '';

    if (is_array($value)) {
        if (isset($value['id']) && is_string($value['id'])) {
            $id = trim($value['id']);
        }
        if (isset($value['name']) && is_string($value['name'])) {
            $name = trim($value['name']);
        } elseif (isset($value['title']) && is_string($value['title'])) {
            $name = trim($value['title']);
        } elseif (isset($value['label']) && is_string($value['label'])) {
            $name = trim($value['label']);
        }
    }

    if ($name === '' && $id !== '') {
        $name = $id;
    }

    if ($id === '' && $name !== '') {
        $id = normalizeCategoryIdentifier($name);
    } elseif ($id !== '') {
        $id = normalizeCategoryIdentifier($id);
    }

    if ($id === '' || $name === '') {
        return $default;
    }

    return ['id' => $id, 'name' => $name];
}

function getDifficultyOptions(bool $includeRandom = false): array
{
    $options = DIFFICULTY_LEVELS;
    if ($includeRandom) {
        $options[DIFFICULTY_RANDOM] = DIFFICULTY_RANDOM_LABEL;
    }

    return $options;
}

function difficultyLabel(string $difficulty): string
{
    if ($difficulty === DIFFICULTY_RANDOM) {
        return DIFFICULTY_RANDOM_LABEL;
    }

    return DIFFICULTY_LEVELS[$difficulty] ?? DIFFICULTY_LEVELS[DEFAULT_DIFFICULTY];
}

/**
 * @param mixed $value
 */
function sanitizeDifficultySelection($value): string
{
    $options = getDifficultyOptions(true);
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '' && isset($options[$trimmed])) {
            return $trimmed;
        }
    }

    return DIFFICULTY_RANDOM;
}

/**
 * @param mixed $value
 */
function normalizeDifficulty($value): string
{
    $default = DEFAULT_DIFFICULTY;

    if (!is_string($value)) {
        return $default;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return $default;
    }

    if (function_exists('mb_strtolower')) {
        $normalized = mb_strtolower($trimmed, 'UTF-8');
    } else {
        $normalized = strtolower($trimmed);
    }

    $map = [
        'easy' => 'easy',
        'e' => 'easy',
        'beginner' => 'easy',
        'やさしい' => 'easy',
        '優しい' => 'easy',
        '簡単' => 'easy',
        'normal' => 'normal',
        'medium' => 'normal',
        'standard' => 'normal',
        'regular' => 'normal',
        '普通' => 'normal',
        '標準' => 'normal',
        'hard' => 'hard',
        'difficult' => 'hard',
        'challenging' => 'hard',
        '難しい' => 'hard',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    return $default;
}

/**
 * @param mixed $value
 * @return array{text: string, reference: string, reference_label: string}
 */
function normalizeExplanation($value): array
{
    if ($value === null) {
        return ['text' => '', 'reference' => '', 'reference_label' => ''];
    }

    if (is_string($value)) {
        return ['text' => trim($value), 'reference' => '', 'reference_label' => ''];
    }

    $text = '';
    $reference = '';
    $referenceLabel = '';

    if (is_array($value)) {
        if (isset($value['text'])) {
            $text = trim((string)$value['text']);
        } elseif (isset($value['description'])) {
            $text = trim((string)$value['description']);
        }

        if (isset($value['reference'])) {
            $reference = trim((string)$value['reference']);
        } elseif (isset($value['url'])) {
            $reference = trim((string)$value['url']);
        } elseif (isset($value['link'])) {
            $reference = trim((string)$value['link']);
        }

        if (isset($value['reference_label'])) {
            $referenceLabel = trim((string)$value['reference_label']);
        } elseif (isset($value['label'])) {
            $referenceLabel = trim((string)$value['label']);
        }
    }

    return [
        'text' => $text,
        'reference' => $reference,
        'reference_label' => $referenceLabel,
    ];
}

/**
 * @param array{text: string, reference: string, reference_label: string} $explanation
 */
function hasExplanationContent(array $explanation): bool
{
    return trim($explanation['text']) !== '' || trim($explanation['reference']) !== '' || trim($explanation['reference_label']) !== '';
}

function buildInputId(string $questionId, string $choiceKey): string
{
    $normalized = preg_replace('/[^a-zA-Z0-9_-]/u', '_', $questionId . '_' . $choiceKey);
    return 'choice_' . $normalized;
}

/**
 * @param array<string, array{id: string, name: string, exam_ids: string[]}> $categories
 * @param array<string, mixed> $exams
 * @return string[]
 */
function examIdsForCategory(array $categories, array $exams, string $categoryId): array
{
    if (!isset($categories[$categoryId])) {
        return [];
    }

    $ids = [];
    foreach ($categories[$categoryId]['exam_ids'] as $categoryExamId) {
        if (isset($exams[$categoryExamId])) {
            $ids[] = $categoryExamId;
        }
    }

    return $ids;
}

/**
 * @param array<int, array<string, mixed>> $questions
 * @return array<int, array<string, mixed>>
 */
function filterQuestionsByDifficulty(array $questions, string $difficulty): array
{
    if ($difficulty === DIFFICULTY_RANDOM) {
        return array_values($questions);
    }

    return array_values(array_filter($questions, static function (array $question) use ($difficulty): bool {
        $questionDifficulty = $question['difficulty'] ?? DEFAULT_DIFFICULTY;
        return $questionDifficulty === $difficulty;
    }));
}

$catalog = loadExamCatalog();
$exams = $catalog['exams'];
$categories = $catalog['categories'];
$errorMessages = $catalog['errors'];

$currentQuiz = $_SESSION['current_quiz'] ?? null;
$view = $currentQuiz ? 'quiz' : 'home';
$results = null;

$selectedCategoryId = '';
$selectedExamId = '';

if (!empty($categories)) {
    $categoryKeys = array_keys($categories);
    $selectedCategoryId = (string)array_shift($categoryKeys);
    $initialExamIds = examIdsForCategory($categories, $exams, $selectedCategoryId);
    if (!empty($initialExamIds)) {
        $selectedExamId = (string)array_shift($initialExamIds);
    }
}

if ($selectedExamId === '' && !empty($exams)) {
    $examKeys = array_keys($exams);
    $selectedExamId = (string)array_shift($examKeys);
    if ($selectedExamId !== '' && isset($exams[$selectedExamId]['meta']['category']['id'])) {
        $selectedCategoryId = $exams[$selectedExamId]['meta']['category']['id'];
    }
}

if (!$currentQuiz) {
    $requestedView = isset($_GET['view']) ? (string)$_GET['view'] : '';
    if ($requestedView === 'history') {
        $view = 'history';
    }
}

$questionCountInput = '';
$selectedDifficulty = sanitizeDifficultySelection($_SESSION['last_selected_difficulty'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if (isset($_POST['difficulty'])) {
        $selectedDifficulty = sanitizeDifficultySelection($_POST['difficulty']);
    }

    $postedCategoryId = isset($_POST['category_id']) ? (string)$_POST['category_id'] : '';
    if ($postedCategoryId !== '' && isset($categories[$postedCategoryId])) {
        $selectedCategoryId = $postedCategoryId;
    }

    $postedExamId = isset($_POST['exam_id']) ? (string)$_POST['exam_id'] : '';
    if ($postedExamId !== '' && isset($exams[$postedExamId])) {
        if ($action !== 'change_category') {
            $selectedExamId = $postedExamId;
            $selectedCategoryId = $exams[$postedExamId]['meta']['category']['id'];
        }
    }

    if (isset($_POST['question_count'])) {
        $questionCountInput = (string)max(0, (int)$_POST['question_count']);
    }

    switch ($action) {
        case 'change_category':
            $selectedExamId = '';
            $questionCountInput = '';
            $view = 'home';
            break;

        case 'select_exam':
            $questionCountInput = '';
            $view = 'home';
            break;

        case 'change_difficulty':
            $questionCountInput = '';
            $view = 'home';
            break;

        case 'start_quiz':
            if ($postedExamId === '' || !isset($exams[$postedExamId])) {
                $errorMessages[] = '選択した試験データが見つかりません。';
                $view = 'home';
                break;
            }

            $selectedExamId = $postedExamId;
            $selectedCategoryId = $exams[$postedExamId]['meta']['category']['id'];

            $questionCount = isset($_POST['question_count']) ? (int)$_POST['question_count'] : 0;
            if ($questionCount < 1) {
                $errorMessages[] = '出題数は1以上を指定してください。';
                $view = 'home';
                break;
            }

            $availableQuestions = filterQuestionsByDifficulty($exams[$postedExamId]['questions'], $selectedDifficulty);
            $availableCount = count($availableQuestions);
            if ($availableCount === 0) {
                if ($selectedDifficulty === DIFFICULTY_RANDOM) {
                    $errorMessages[] = '出題できる問題が見つかりません。';
                } else {
                    $errorMessages[] = '選択した難易度の問題が登録されていません。';
                }
                $view = 'home';
                break;
            }

            if ($questionCount > $availableCount) {
                if ($selectedDifficulty === DIFFICULTY_RANDOM) {
                    $errorMessages[] = sprintf('出題数が多すぎます。（最大 %d 問まで）', $availableCount);
                } else {
                    $errorMessages[] = sprintf('選択した難易度の問題が不足しています。（最大 %d 問まで）', $availableCount);
                }
                $view = 'home';
                break;
            }

            $questions = $availableQuestions;
            shuffle($questions);
            $questions = array_slice($questions, 0, $questionCount);

            $currentQuiz = [
                'exam_id' => $postedExamId,
                'meta' => $exams[$postedExamId]['meta'],
                'questions' => $questions,
                'started_at' => time(),
                'difficulty' => $selectedDifficulty,
            ];
            $_SESSION['current_quiz'] = $currentQuiz;
            $view = 'quiz';
            break;

        case 'submit_answers':
            if (!$currentQuiz) {
                $errorMessages[] = '先に問題を開始してください。';
                $view = 'home';
                break;
            }

            $selectedDifficulty = $currentQuiz['difficulty'] ?? $selectedDifficulty;

            $submittedAnswers = $_POST['answers'] ?? [];
            if (!is_array($submittedAnswers)) {
                $submittedAnswers = [];
            }

            $questionResults = [];
            $correctCount = 0;
            $incorrectDetails = [];
            foreach ($currentQuiz['questions'] as $index => $question) {
                $questionId = $question['id'];
                $userAnswer = null;
                if (isset($submittedAnswers[$questionId]) && !is_array($submittedAnswers[$questionId])) {
                    $userAnswer = (string)$submittedAnswers[$questionId];
                }
                $isCorrect = $userAnswer !== null && $userAnswer === $question['answer'];
                if ($isCorrect) {
                    $correctCount++;
                } else {
                    $incorrectDetails[] = [
                        'number' => $index + 1,
                        'question' => $question['question'],
                        'correct_answer' => $question['answer'],
                        'user_answer' => $userAnswer,
                    ];
                }
                $questionResults[] = [
                    'number' => $index + 1,
                    'id' => $questionId,
                    'question' => $question['question'],
                    'choices' => $question['choices'],
                    'answer' => $question['answer'],
                    'explanation' => $question['explanation'],
                    'user_answer' => $userAnswer,
                    'is_correct' => $isCorrect,
                    'difficulty' => $question['difficulty'] ?? DEFAULT_DIFFICULTY,
                ];
            }

            $incorrectCount = count($incorrectDetails);
            $resultId = '';
            try {
                $resultId = bin2hex(random_bytes(16));
            } catch (Exception $exception) {
                $resultId = uniqid('result_', true);
            }

            $results = [
                'exam' => $currentQuiz['meta'],
                'total' => count($questionResults),
                'correct' => $correctCount,
                'questions' => $questionResults,
                'difficulty' => $currentQuiz['difficulty'] ?? DIFFICULTY_RANDOM,
                'incorrect' => $incorrectCount,
                'incorrect_questions' => $incorrectDetails,
                'completed_at' => date(DATE_ATOM),
                'result_id' => $resultId,
            ];

            $selectedExamId = $currentQuiz['exam_id'];
            $selectedCategoryId = $currentQuiz['meta']['category']['id'] ?? $selectedCategoryId;

            unset($_SESSION['current_quiz']);
            $currentQuiz = null;
            $view = 'results';
            break;

        case 'reset_quiz':
            if ($currentQuiz) {
                $selectedExamId = $currentQuiz['exam_id'];
                $selectedCategoryId = $currentQuiz['meta']['category']['id'] ?? $selectedCategoryId;
                $selectedDifficulty = $currentQuiz['difficulty'] ?? $selectedDifficulty;
            }
            unset($_SESSION['current_quiz']);
            $currentQuiz = null;
            $view = 'home';
            break;

        default:
            // keep current view
            break;
    }

    $_SESSION['last_selected_difficulty'] = $selectedDifficulty;
}

if ($view === 'quiz' && !$currentQuiz && isset($_SESSION['current_quiz'])) {
    $currentQuiz = $_SESSION['current_quiz'];
}

if ($view === 'quiz' && !$currentQuiz) {
    $view = 'home';
}

if ($view === 'quiz' && $currentQuiz) {
    $selectedDifficulty = $currentQuiz['difficulty'] ?? $selectedDifficulty;
}

if ($results) {
    $selectedDifficulty = sanitizeDifficultySelection($results['difficulty'] ?? $selectedDifficulty);
}

if ($selectedCategoryId === '' || !isset($categories[$selectedCategoryId])) {
    if (!empty($categories)) {
        $categoryKeys = array_keys($categories);
        $selectedCategoryId = (string)array_shift($categoryKeys);
    }
}

$selectedCategory = ($selectedCategoryId !== '' && isset($categories[$selectedCategoryId])) ? $categories[$selectedCategoryId] : null;
$categoryExamIds = $selectedCategory ? examIdsForCategory($categories, $exams, $selectedCategoryId) : [];

if ($selectedExamId === '' || !isset($exams[$selectedExamId]) || (!empty($categoryExamIds) && !in_array($selectedExamId, $categoryExamIds, true))) {
    if (!empty($categoryExamIds)) {
        $selectedExamId = (string)$categoryExamIds[0];
    } elseif (!empty($exams)) {
        $examKeys = array_keys($exams);
        $selectedExamId = (string)array_shift($examKeys);
        if ($selectedExamId !== '' && isset($exams[$selectedExamId]['meta']['category']['id'])) {
            $selectedCategoryId = $exams[$selectedExamId]['meta']['category']['id'];
            $selectedCategory = $categories[$selectedCategoryId] ?? $selectedCategory;
            $categoryExamIds = $selectedCategory ? examIdsForCategory($categories, $exams, $selectedCategoryId) : [];
        }
    }
}

$selectedExam = ($selectedExamId !== '' && isset($exams[$selectedExamId])) ? $exams[$selectedExamId] : null;
$selectedCategory = ($selectedCategoryId !== '' && isset($categories[$selectedCategoryId])) ? $categories[$selectedCategoryId] : $selectedCategory;
$categoryExamIds = $selectedCategory ? examIdsForCategory($categories, $exams, $selectedCategoryId) : [];

$availableQuestionsForSelectedDifficulty = $selectedExam ? filterQuestionsByDifficulty($selectedExam['questions'], $selectedDifficulty) : [];
$availableQuestionCountForSelectedDifficulty = count($availableQuestionsForSelectedDifficulty);
$questionCountMax = max(1, $availableQuestionCountForSelectedDifficulty);
$canStartQuiz = $selectedExam !== null && $availableQuestionCountForSelectedDifficulty > 0;

if ($questionCountInput === '' || $questionCountInput === '0') {
    if ($availableQuestionCountForSelectedDifficulty > 0) {
        $defaultCount = min(5, $availableQuestionCountForSelectedDifficulty);
        if ($defaultCount < 1) {
            $defaultCount = $availableQuestionCountForSelectedDifficulty;
        }
        if ($defaultCount < 1) {
            $defaultCount = 1;
        }
        $questionCountInput = (string)$defaultCount;
    } else {
        $questionCountInput = '1';
    }
}

$difficultyOptions = getDifficultyOptions(true);

$totalExams = count($exams);

$currentResultForStorage = null;
if ($view === 'results' && $results) {
    $resultsDifficultyForStorage = sanitizeDifficultySelection($results['difficulty'] ?? DIFFICULTY_RANDOM);
    $incorrectQuestionsForStorage = [];
    if (!empty($results['incorrect_questions']) && is_array($results['incorrect_questions'])) {
        foreach ($results['incorrect_questions'] as $incorrectQuestion) {
            if (!is_array($incorrectQuestion)) {
                continue;
            }
            $incorrectQuestionsForStorage[] = [
                'number' => isset($incorrectQuestion['number']) ? (int)$incorrectQuestion['number'] : 0,
                'question' => isset($incorrectQuestion['question']) ? (string)$incorrectQuestion['question'] : '',
                'correctAnswer' => isset($incorrectQuestion['correct_answer']) ? (string)$incorrectQuestion['correct_answer'] : '',
                'userAnswer' => isset($incorrectQuestion['user_answer']) ? (string)$incorrectQuestion['user_answer'] : '',
            ];
        }
    }

    $completedAt = isset($results['completed_at']) && is_string($results['completed_at'])
        ? $results['completed_at']
        : date(DATE_ATOM);

    $currentResultForStorage = [
        'resultId' => (string)($results['result_id'] ?? ''),
        'examId' => (string)($results['exam']['id'] ?? ''),
        'examTitle' => (string)($results['exam']['title'] ?? ''),
        'categoryId' => (string)($results['exam']['category']['id'] ?? ''),
        'categoryName' => (string)($results['exam']['category']['name'] ?? ''),
        'difficulty' => $resultsDifficultyForStorage,
        'correct' => (int)($results['correct'] ?? 0),
        'incorrect' => (int)($results['incorrect'] ?? max(0, (int)($results['total'] ?? 0) - (int)($results['correct'] ?? 0))),
        'total' => (int)($results['total'] ?? 0),
        'completedAt' => $completedAt,
        'incorrectQuestions' => $incorrectQuestionsForStorage,
    ];
}

$isHistoryView = ($view === 'history');
$appAttributes = ' data-view="' . h($view) . '"';
if ($currentResultForStorage !== null) {
    $currentResultJson = json_encode($currentResultForStorage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($currentResultJson)) {
        $appAttributes .= ' data-current-result="' . h($currentResultJson) . '"';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資格試験問題集</title>
    <script>
        document.documentElement.classList.add('js-enabled');
    </script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app"<?php echo $appAttributes; ?>>
    <header>
        <div class="header-top">
            <h1><a href="index.php">資格試験問題集</a></h1>
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-controls="categorySidebar" aria-expanded="false">
                <span class="sr-only">カテゴリメニューを開く</span>
                <span class="hamburger" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
        <p>JSON形式で管理された問題データから出題する学習支援アプリです。</p>
    </header>

    <div class="layout">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <aside class="category-sidebar" id="categorySidebar" aria-label="メニュー" tabindex="-1">
            <div class="sidebar-header">
                <h2>メニュー</h2>
                <button type="button" class="sidebar-close" id="sidebarClose" aria-label="カテゴリメニューを閉じる">&times;</button>
            </div>
            <h3 class="sidebar-section-title">試験カテゴリ</h3>
            <?php if (!empty($categories)): ?>
                <div class="category-accordion">
                    <?php foreach ($categories as $categoryId => $category): ?>
                        <?php $examIds = examIdsForCategory($categories, $exams, $categoryId); ?>
                        <?php $categoryExamCount = count($examIds); ?>
                        <?php $isActiveCategory = $categoryId === $selectedCategoryId; ?>
                        <details class="category-item"<?php echo $isActiveCategory ? ' open' : ''; ?>>
                            <summary class="category-summary">
                                <span class="category-name"><?php echo h($category['name']); ?></span>
                                <span class="category-count" aria-hidden="true"><?php echo $categoryExamCount; ?></span>
                                <span class="accordion-icon" aria-hidden="true"></span>
                            </summary>
                            <?php if ($categoryExamCount > 0): ?>
                                <div class="exam-list">
                                    <?php foreach ($examIds as $examId): ?>
                                        <?php if (!isset($exams[$examId])) { continue; } ?>
                                        <?php $exam = $exams[$examId]; ?>
                                        <?php $isActiveExam = $examId === $selectedExamId; ?>
                                        <form method="post" class="exam-select-form">
                                            <input type="hidden" name="action" value="select_exam">
                                            <input type="hidden" name="difficulty" value="<?php echo h($selectedDifficulty); ?>">
                                            <input type="hidden" name="category_id" value="<?php echo h($categoryId); ?>">
                                            <button type="submit" name="exam_id" value="<?php echo h($examId); ?>" class="exam-button<?php echo $isActiveExam ? ' active' : ''; ?>">
                                                <span class="exam-title"><?php echo h($exam['meta']['title']); ?></span>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="empty-message accordion-empty">このカテゴリには試験が登録されていません。</p>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-message">カテゴリが登録されていません。</p>
            <?php endif; ?>
            <nav class="sidebar-nav" aria-label="ページ切り替え">
                <a href="?view=history" class="sidebar-nav-link<?php echo $isHistoryView ? ' active' : ''; ?>">受験履歴</a>
            </nav>
        </aside>
        <main class="main-content">
            <?php foreach ($errorMessages as $message): ?>
                <div class="alert error"><?php echo h($message); ?></div>
            <?php endforeach; ?>

            <?php if ($view === 'home'): ?>
        <?php if ($totalExams === 0): ?>
            <div class="form-card">
                <h2>問題データが見つかりません</h2>
                <p>data ディレクトリに資格試験ごとのJSONファイルを配置してください。</p>
                <p>フォーマット例や詳細はREADMEをご覧ください。</p>
            </div>
        <?php else: ?>
            <div class="form-card">
                <h2>問題を開始する</h2>
                <form method="post">
                    <input type="hidden" name="action" value="start_quiz" id="form_action">
                    <input type="hidden" name="category_id" value="<?php echo h($selectedCategoryId); ?>">
                    <input type="hidden" name="exam_id" value="<?php echo h($selectedExamId); ?>">
                    <div class="form-field static-field">
                        <label>選択中のカテゴリ</label>
                        <div class="selected-category-display">
                            <?php if ($selectedCategory): ?>
                                <span class="selected-category-name"><?php echo h($selectedCategory['name']); ?></span>
                                <span class="selected-category-count">登録試験: <?php echo count($categoryExamIds); ?> 件</span>
                            <?php else: ?>
                                <span class="selected-category-name">カテゴリが選択されていません。</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-field static-field">
                        <label>選択中の試験</label>
                        <div class="selected-exam-display">
                            <?php if ($selectedExam): ?>
                                <span class="selected-exam-name"><?php echo h($selectedExam['meta']['title']); ?></span>
                                <div class="exam-meta">
                                    <span><strong>カテゴリ:</strong> <?php echo h($selectedExam['meta']['category']['name']); ?></span>
                                    <?php if ($selectedExam['meta']['version'] !== ''): ?>
                                        <span><strong>バージョン:</strong> <?php echo h($selectedExam['meta']['version']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($selectedExam['meta']['description'] !== ''): ?>
                                        <span class="exam-meta-description"><strong>概要:</strong> <?php echo nl2brSafe($selectedExam['meta']['description']); ?></span>
                                    <?php endif; ?>
                                    <span><strong>問題数:</strong> <?php echo (int)$selectedExam['meta']['question_count']; ?> 問</span>
                                    <span><strong>データファイル:</strong> <?php echo h($selectedExam['meta']['source_file']); ?></span>
                                </div>
                            <?php else: ?>
                                <span class="selected-exam-name">試験が選択されていません。</span>
                                <span class="selected-exam-placeholder">下のボタンから試験を選択してください。</span>
                            <?php endif; ?>
                        </div>
                        <small class="field-hint">メニューまたは下のボタンから試験を選択してください。</small>
                    </div>
                    <div class="form-field">
                        <label for="difficulty">難易度</label>
                        <select name="difficulty" id="difficulty" onchange="document.getElementById('form_action').value='change_difficulty'; this.form.submit();" <?php echo $selectedExam ? '' : 'disabled'; ?>>
                            <?php foreach ($difficultyOptions as $difficultyKey => $difficultyText): ?>
                                <option value="<?php echo h($difficultyKey); ?>" <?php echo $difficultyKey === $selectedDifficulty ? 'selected' : ''; ?>>
                                    <?php echo h($difficultyText); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selectedExam): ?>
                            <?php if ($selectedDifficulty === DIFFICULTY_RANDOM): ?>
                                <small class="field-hint">この試験には <?php echo (int)$selectedExam['meta']['question_count']; ?> 問登録されています。</small>
                            <?php else: ?>
                                <small class="field-hint">この難易度の問題数: <?php echo $availableQuestionCountForSelectedDifficulty; ?> 問</small>
                                <?php if (!$canStartQuiz): ?>
                                    <small class="field-hint error">選択した難易度の問題が登録されていません。</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label for="question_count">出題数</label>
                        <input type="number" id="question_count" name="question_count" min="1" max="<?php echo $questionCountMax; ?>" value="<?php echo h($questionCountInput); ?>" <?php echo $canStartQuiz ? '' : 'disabled'; ?> required>
                        <?php if ($selectedExam): ?>
                            <?php if ($selectedDifficulty === DIFFICULTY_RANDOM): ?>
                                <small class="field-hint">最大 <?php echo (int)$selectedExam['meta']['question_count']; ?> 問まで選択できます。</small>
                            <?php elseif ($availableQuestionCountForSelectedDifficulty > 0): ?>
                                <small class="field-hint">この難易度では最大 <?php echo $availableQuestionCountForSelectedDifficulty; ?> 問まで選べます。</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <button type="submit" <?php echo $canStartQuiz ? '' : 'disabled'; ?>>問題を開始</button>
                        <button type="button" class="open-sidebar-button" data-sidebar-target="categorySidebar"><?php echo $selectedExam ? '別の試験を選ぶ' : '試験を選択する'; ?></button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php elseif ($view === 'history'): ?>
        <section class="section-card history-card" data-history-root>
            <div class="history-header">
                <div class="history-header-text">
                    <h2>受験履歴</h2>
                    <p class="history-description">保存された受験結果を検索、並べ替え、絞り込みできます。</p>
                </div>
                <button type="button" class="secondary danger-action" data-clear-history>履歴をすべて削除</button>
            </div>
            <form class="history-filters" aria-label="受験履歴の絞り込み">
                <div class="history-filter-group">
                    <label for="historySearch">キーワード</label>
                    <input type="search" id="historySearch" name="search" placeholder="試験名やカテゴリで検索">
                </div>
                <div class="history-filter-group">
                    <label for="historyDifficulty">難易度</label>
                    <select id="historyDifficulty" name="difficulty">
                        <option value="">すべて</option>
                        <?php foreach ($difficultyOptions as $difficultyKey => $difficultyText): ?>
                            <option value="<?php echo h($difficultyKey); ?>"><?php echo h($difficultyText); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="history-filter-group">
                    <label for="historySort">ソート</label>
                    <select id="historySort" name="sort">
                        <option value="date_desc">受験日時（新しい順）</option>
                        <option value="date_asc">受験日時（古い順）</option>
                        <option value="score_desc">正答率（高い順）</option>
                        <option value="score_asc">正答率（低い順）</option>
                    </select>
                </div>
            </form>
            <p class="history-status" data-history-status aria-live="polite"></p>
            <p class="history-count" data-history-count></p>
            <p class="history-empty" data-history-empty hidden>保存された履歴はありません。</p>
            <ul class="history-list saved-results-list" data-history-list></ul>
            <div class="history-pagination" data-history-pagination hidden>
                <button type="button" class="secondary" data-pagination-prev aria-label="前のページ">前へ</button>
                <span class="history-page-info" data-pagination-info>1 / 1</span>
                <button type="button" class="secondary" data-pagination-next aria-label="次のページ">次へ</button>
            </div>
        </section>
    <?php elseif ($view === 'quiz' && $currentQuiz): ?>
        <?php $quizDifficulty = $currentQuiz['difficulty'] ?? DIFFICULTY_RANDOM; ?>
        <div class="quiz-header">
            <h2><?php echo h($currentQuiz['meta']['title']); ?></h2>
            <?php if (!empty($currentQuiz['meta']['category']['name'])): ?>
                <p class="quiz-category">カテゴリ: <?php echo h($currentQuiz['meta']['category']['name']); ?></p>
            <?php endif; ?>
            <p>全 <?php echo (int)$currentQuiz['meta']['question_count']; ?> 問中から <?php echo count($currentQuiz['questions']); ?> 問を出題中です。</p>
            <p class="quiz-difficulty">選択した難易度: <span class="difficulty-tag difficulty-<?php echo h($quizDifficulty); ?>"><?php echo h(difficultyLabel($quizDifficulty)); ?></span></p>
        </div>
        <form method="post" class="quiz-form">
            <?php foreach ($currentQuiz['questions'] as $index => $question): ?>
                <?php $questionDifficulty = $question['difficulty'] ?? DEFAULT_DIFFICULTY; ?>
                <div class="question-card">
                    <h3>Q<?php echo $index + 1; ?>. <?php echo h($question['question']); ?> <span class="difficulty-tag difficulty-<?php echo h($questionDifficulty); ?>"><?php echo h(difficultyLabel($questionDifficulty)); ?></span></h3>
                    <ul class="choice-list">
                        <?php foreach ($question['choices'] as $choice): ?>
                            <?php $inputId = buildInputId($question['id'], $choice['key']); ?>
                            <li class="choice-item">
                                <input type="radio" id="<?php echo h($inputId); ?>" name="answers[<?php echo h($question['id']); ?>]" value="<?php echo h($choice['key']); ?>">
                                <label for="<?php echo h($inputId); ?>">
                                    <span class="option-key"><?php echo h($choice['key']); ?>.</span>
                                    <?php echo h($choice['text']); ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            <div class="actions">
                <button type="submit" name="action" value="submit_answers">採点する</button>
                <button type="submit" name="action" value="reset_quiz" class="secondary">やり直す</button>
            </div>
        </form>
    <?php elseif ($view === 'results' && $results): ?>
        <?php $scorePercent = $results['total'] > 0 ? round(($results['correct'] / $results['total']) * 100) : 0; ?>
        <?php $resultsDifficulty = sanitizeDifficultySelection($results['difficulty'] ?? DIFFICULTY_RANDOM); ?>
        <div class="results-summary">
            <h2><?php echo h($results['exam']['title']); ?> の結果</h2>
            <?php if (!empty($results['exam']['category']['name'])): ?>
                <p class="results-category">カテゴリ: <?php echo h($results['exam']['category']['name']); ?></p>
            <?php endif; ?>
            <p class="results-difficulty">選択した難易度: <span class="difficulty-tag difficulty-<?php echo h($resultsDifficulty); ?>"><?php echo h(difficultyLabel($resultsDifficulty)); ?></span></p>
            <p><?php echo $results['correct']; ?> / <?php echo $results['total']; ?> 問正解（正答率 <?php echo $scorePercent; ?>%）</p>
        </div>
        <?php foreach ($results['questions'] as $question): ?>
            <?php $questionDifficulty = $question['difficulty'] ?? DEFAULT_DIFFICULTY; ?>
            <div class="question-card <?php echo $question['is_correct'] ? 'correct' : 'incorrect'; ?>">
                <h3>Q<?php echo $question['number']; ?>. <?php echo h($question['question']); ?> <span class="difficulty-tag difficulty-<?php echo h($questionDifficulty); ?>"><?php echo h(difficultyLabel($questionDifficulty)); ?></span></h3>
                <?php if ($question['user_answer'] === null): ?>
                    <p class="no-answer">未回答</p>
                <?php endif; ?>
                <ul class="choice-list">
                    <?php foreach ($question['choices'] as $choice): ?>
                        <?php
                        $class = 'choice-item';
                        if ($choice['key'] === $question['answer']) {
                            $class .= ' correct';
                        }
                        $isSelected = $question['user_answer'] !== null && $choice['key'] === $question['user_answer'];
                        if ($isSelected) {
                            $class .= ' selected';
                            if ($choice['key'] !== $question['answer']) {
                                $class .= ' incorrect';
                            }
                        }
                        ?>
                        <li class="<?php echo h($class); ?>">
                            <span class="option-key"><?php echo h($choice['key']); ?>.</span>
                            <span><?php echo h($choice['text']); ?></span>
                            <?php if ($choice['key'] === $question['answer']): ?>
                                <span>（正解）</span>
                            <?php elseif ($isSelected): ?>
                                <span>（選択）</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                </ul>
                <?php
                $questionExplanation = is_array($question['explanation']) ? $question['explanation'] : normalizeExplanation($question['explanation']);
                $choiceExplanationItems = [];
                foreach ($question['choices'] as $choiceForExplanation) {
                    $choiceExplanationValue = $choiceForExplanation['explanation'] ?? null;
                    if (!is_array($choiceExplanationValue)) {
                        $choiceExplanationValue = normalizeExplanation($choiceExplanationValue);
                    }
                    if (hasExplanationContent($choiceExplanationValue)) {
                        $choiceForExplanation['explanation'] = $choiceExplanationValue;
                        $choiceExplanationItems[] = $choiceForExplanation;
                    }
                }
                $hasQuestionExplanation = hasExplanationContent($questionExplanation);
                ?>
                <?php if ($hasQuestionExplanation || !empty($choiceExplanationItems)): ?>
                    <div class="explanation">
                        <?php if ($hasQuestionExplanation): ?>
                            <div class="explanation-block">
                                <h4>問題全体の解説</h4>
                                <?php if ($questionExplanation['text'] !== ''): ?>
                                    <p><?php echo nl2brSafe($questionExplanation['text']); ?></p>
                                <?php endif; ?>
                                <?php if ($questionExplanation['reference'] !== ''): ?>
                                    <?php $questionReferenceLabel = $questionExplanation['reference_label'] !== '' ? $questionExplanation['reference_label'] : '公式資料'; ?>
                                    <p class="explanation-reference">
                                        <a href="<?php echo h($questionExplanation['reference']); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($questionReferenceLabel); ?></a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($choiceExplanationItems)): ?>
                            <div class="explanation-block">
                                <details class="choice-explanations-toggle">
                                    <summary>選択肢ごとの解説</summary>
                                    <ul class="choice-explanations">
                                        <?php foreach ($choiceExplanationItems as $choiceExplanationItem): ?>
                                            <?php $choiceExplanation = $choiceExplanationItem['explanation']; ?>
                                            <li class="choice-explanation-item">
                                                <span class="option-key"><?php echo h($choiceExplanationItem['key']); ?>.</span>
                                                <div class="choice-explanation-body">
                                                    <?php if ($choiceExplanationItem['text'] !== ''): ?>
                                                        <p class="choice-statement"><?php echo h($choiceExplanationItem['text']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($choiceExplanation['text'] !== ''): ?>
                                                        <p><?php echo nl2brSafe($choiceExplanation['text']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($choiceExplanation['reference'] !== ''): ?>
                                                        <?php $choiceReferenceLabel = $choiceExplanation['reference_label'] !== '' ? $choiceExplanation['reference_label'] : '公式資料'; ?>
                                                        <p class="explanation-reference">
                                                            <a href="<?php echo h($choiceExplanation['reference']); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($choiceReferenceLabel); ?></a>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="actions">
            <form method="post">
                <input type="hidden" name="action" value="start_quiz">
                <input type="hidden" name="category_id" value="<?php echo h($results['exam']['category']['id'] ?? ''); ?>">
                <input type="hidden" name="exam_id" value="<?php echo h($results['exam']['id']); ?>">
                <input type="hidden" name="question_count" value="<?php echo (int)$results['total']; ?>">
                <input type="hidden" name="difficulty" value="<?php echo h($resultsDifficulty); ?>">
                <button type="submit">同じ条件で再挑戦</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="reset_quiz">
                <input type="hidden" name="category_id" value="<?php echo h($results['exam']['category']['id'] ?? ''); ?>">
                <input type="hidden" name="exam_id" value="<?php echo h($results['exam']['id']); ?>">
                <input type="hidden" name="difficulty" value="<?php echo h($resultsDifficulty); ?>">
                <button type="submit" class="secondary">別の試験を選ぶ</button>
            </form>
            <a class="link-button" href="?view=history">受験履歴ページを開く</a>
        </div>
    <?php endif; ?>

        </main>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const html = document.documentElement;
        const toggleButton = document.getElementById('sidebarToggle');
        const closeButton = document.getElementById('sidebarClose');
        const backdrop = document.getElementById('sidebarBackdrop');
        const sidebar = document.getElementById('categorySidebar');

        if (toggleButton && sidebar && backdrop && closeButton) {
            const openSidebar = function () {
                html.classList.add('sidebar-open');
                toggleButton.setAttribute('aria-expanded', 'true');
                sidebar.focus();
            };

            const closeSidebar = function () {
                html.classList.remove('sidebar-open');
                toggleButton.setAttribute('aria-expanded', 'false');
                toggleButton.focus();
            };

            const accordionItems = sidebar.querySelectorAll('.category-item');
            accordionItems.forEach(function (item) {
                item.addEventListener('toggle', function () {
                    if (item.open) {
                        accordionItems.forEach(function (other) {
                            if (other !== item) {
                                other.open = false;
                            }
                        });
                    }
                });
            });

            const externalSidebarButtons = document.querySelectorAll('[data-sidebar-target="categorySidebar"]');
            externalSidebarButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    openSidebar();
                });
            });

            toggleButton.addEventListener('click', function () {
                if (html.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });

            closeButton.addEventListener('click', closeSidebar);
            backdrop.addEventListener('click', closeSidebar);

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && html.classList.contains('sidebar-open')) {
                    event.preventDefault();
                    closeSidebar();
                }
            });
        }

        const appElement = document.querySelector('.app');
        if (!appElement) {
            return;
        }

        const resultDataRaw = appElement.getAttribute('data-current-result');
        let currentResult = null;
        if (resultDataRaw) {
            try {
                currentResult = JSON.parse(resultDataRaw);
            } catch (error) {
                console.error('Failed to parse result data', error);
            }
        }

        const difficultyLabels = {
            easy: '優しい',
            normal: '普通',
            hard: '難しい',
            random: 'ランダム'
        };

        const historyRoot = document.querySelector('[data-history-root]');
        const statusElement = document.querySelector('[data-history-status]');
        const summaryElement = document.querySelector('[data-history-count]');
        const listElement = document.querySelector('[data-history-list]');
        const emptyElement = document.querySelector('[data-history-empty]');
        const paginationElement = document.querySelector('[data-history-pagination]');
        const prevButton = paginationElement ? paginationElement.querySelector('[data-pagination-prev]') : null;
        const nextButton = paginationElement ? paginationElement.querySelector('[data-pagination-next]') : null;
        const pageInfoElement = paginationElement ? paginationElement.querySelector('[data-pagination-info]') : null;
        const clearButton = historyRoot ? historyRoot.querySelector('[data-clear-history]') : document.querySelector('[data-clear-history]');
        const searchInput = document.getElementById('historySearch');
        const difficultyFilter = document.getElementById('historyDifficulty');
        const sortSelect = document.getElementById('historySort');
        const filtersForm = document.querySelector('.history-filters');

        const isIndexedDBAvailable = typeof indexedDB !== 'undefined';
        const isHistoryPage = Boolean(historyRoot);
        const PAGE_SIZE = 5;

        function setStatus(message, type) {
            if (!statusElement) {
                return;
            }
            statusElement.textContent = message || '';
            statusElement.className = 'history-status';
            if (type) {
                statusElement.classList.add('status-' + type);
            }
        }

        function updateSummary(total, visible) {
            if (!summaryElement) {
                return;
            }
            if (!total) {
                summaryElement.textContent = '';
                return;
            }
            summaryElement.textContent = '全' + total + '件中' + visible + '件を表示しています。';
        }

        function updateEmptyState(show, message) {
            if (!emptyElement) {
                return;
            }
            if (show) {
                if (typeof message === 'string' && message !== '') {
                    emptyElement.textContent = message;
                }
                emptyElement.hidden = false;
            } else {
                emptyElement.hidden = true;
            }
        }

        if (filtersForm) {
            filtersForm.addEventListener('submit', function (event) {
                event.preventDefault();
            });
        }

        if (!isIndexedDBAvailable) {
            if (clearButton) {
                clearButton.disabled = true;
                clearButton.title = 'このブラウザでは履歴機能を利用できません。';
            }
            if (isHistoryPage) {
                updateEmptyState(true, 'このブラウザでは履歴機能を利用できません。');
            }
            setStatus('このブラウザでは履歴機能を利用できません。', 'error');
            return;
        }

        const DB_NAME = 'quizResults';
        const DB_VERSION = 1;
        const STORE_NAME = 'results';
        let databasePromise = null;

        function openDatabase() {
            if (databasePromise) {
                return databasePromise;
            }

            databasePromise = new Promise(function (resolve, reject) {
                const request = indexedDB.open(DB_NAME, DB_VERSION);

                request.addEventListener('error', function () {
                    databasePromise = null;
                    reject(request.error || new Error('データベースのオープンに失敗しました。'));
                });

                request.addEventListener('upgradeneeded', function (event) {
                    const db = event.target.result;
                    if (!db.objectStoreNames.contains(STORE_NAME)) {
                        db.createObjectStore(STORE_NAME, { keyPath: 'id' });
                    }
                });

                request.addEventListener('success', function () {
                    const db = request.result;
                    db.addEventListener('close', function () {
                        databasePromise = null;
                    });
                    db.addEventListener('versionchange', function () {
                        db.close();
                    });
                    resolve(db);
                });
            });

            return databasePromise;
        }

        function addResult(result) {
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    const transaction = db.transaction(STORE_NAME, 'readwrite');
                    const store = transaction.objectStore(STORE_NAME);
                    store.put(result);

                    transaction.addEventListener('complete', function () {
                        resolve();
                    });

                    transaction.addEventListener('error', function () {
                        reject(transaction.error || new Error('結果の保存に失敗しました。'));
                    });

                    transaction.addEventListener('abort', function () {
                        reject(transaction.error || new Error('結果の保存が中断されました。'));
                    });
                });
            });
        }

        function clearAllResults() {
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    const transaction = db.transaction(STORE_NAME, 'readwrite');
                    const store = transaction.objectStore(STORE_NAME);
                    store.clear();

                    transaction.addEventListener('complete', function () {
                        resolve();
                    });

                    transaction.addEventListener('error', function () {
                        reject(transaction.error || new Error('履歴の削除に失敗しました。'));
                    });

                    transaction.addEventListener('abort', function () {
                        reject(transaction.error || new Error('履歴の削除が中断されました。'));
                    });
                });
            });
        }

        function fetchAllResults() {
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    const transaction = db.transaction(STORE_NAME, 'readonly');
                    const store = transaction.objectStore(STORE_NAME);
                    const request = store.getAll();

                    request.addEventListener('success', function () {
                        resolve(request.result || []);
                    });

                    request.addEventListener('error', function () {
                        reject(request.error || new Error('保存済みの結果を読み込めませんでした。'));
                    });
                });
            });
        }

        function calculateScorePercent(correct, total) {
            const totalNumber = Number(total);
            const correctNumber = Number(correct);
            if (!totalNumber) {
                return 0;
            }
            return Math.round((correctNumber / totalNumber) * 100);
        }

        function formatDifficulty(value) {
            if (!value) {
                return '未設定';
            }
            return difficultyLabels[value] || value;
        }

        function formatDateTime(value) {
            if (!value) {
                return '不明';
            }
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return '不明';
            }
            return date.toLocaleString('ja-JP', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function generateId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return 'result-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
        }

        function normalizeIncorrectQuestions(questions) {
            if (!Array.isArray(questions)) {
                return [];
            }
            return questions.map(function (question) {
                if (!question || typeof question !== 'object') {
                    return null;
                }
                return {
                    number: typeof question.number === 'number' ? question.number : 0,
                    question: typeof question.question === 'string' ? question.question : '',
                    correctAnswer: typeof question.correctAnswer === 'string' ? question.correctAnswer : '',
                    userAnswer: typeof question.userAnswer === 'string' ? question.userAnswer : ''
                };
            }).filter(function (question) {
                return question !== null;
            });
        }

        const historyState = {
            results: [],
            filtered: [],
            page: 1,
            pageSize: PAGE_SIZE,
            sort: sortSelect ? sortSelect.value : 'date_desc',
            search: searchInput ? searchInput.value : '',
            difficulty: difficultyFilter ? difficultyFilter.value : ''
        };

        function createHistoryListItem(result) {
            const item = document.createElement('li');
            item.className = 'saved-result-item history-list-item';

            const details = document.createElement('details');
            details.className = 'history-entry';

            const summary = document.createElement('summary');
            summary.className = 'history-summary';

            const summaryMain = document.createElement('div');
            summaryMain.className = 'history-summary-main';

            const title = document.createElement('span');
            title.className = 'history-summary-title';
            title.textContent = result.examTitle || '不明な試験';
            summaryMain.appendChild(title);

            const timestamp = document.createElement('span');
            timestamp.className = 'history-summary-timestamp';
            timestamp.textContent = '受験日時: ' + formatDateTime(result.completedAt || result.savedAt);
            summaryMain.appendChild(timestamp);

            const tags = document.createElement('div');
            tags.className = 'history-summary-tags';
            if (result.categoryName) {
                const categoryTag = document.createElement('span');
                categoryTag.className = 'history-tag';
                categoryTag.textContent = result.categoryName;
                tags.appendChild(categoryTag);
            }
            if (result.difficulty) {
                const difficultyTag = document.createElement('span');
                difficultyTag.className = 'history-tag';
                difficultyTag.textContent = '難易度: ' + formatDifficulty(result.difficulty);
                tags.appendChild(difficultyTag);
            }
            if (tags.childElementCount > 0) {
                summaryMain.appendChild(tags);
            }

            const correctNumber = Number(result.correct) || 0;
            const totalNumber = Number(result.total) || 0;
            const incorrectNumberRaw = Number(result.incorrect);
            const incorrectNumber = Number.isFinite(incorrectNumberRaw)
                ? incorrectNumberRaw
                : Math.max(totalNumber - correctNumber, 0);
            const scorePercent = typeof result.scorePercent === 'number'
                ? result.scorePercent
                : calculateScorePercent(correctNumber, totalNumber);

            const scoreLine = document.createElement('span');
            scoreLine.className = 'history-summary-score';
            scoreLine.textContent = '正解 ' + correctNumber + '問 / 不正解 ' + incorrectNumber + '問（正答率 ' + scorePercent + '%）';

            summary.appendChild(summaryMain);
            summary.appendChild(scoreLine);
            details.appendChild(summary);

            const detailsBody = document.createElement('div');
            detailsBody.className = 'history-details';

            const infoList = document.createElement('dl');
            infoList.className = 'history-detail-info';

            const infoItems = [
                ['受験日時', formatDateTime(result.completedAt || result.savedAt)],
                ['カテゴリ', result.categoryName || '未設定'],
                ['難易度', formatDifficulty(result.difficulty)],
                ['出題数', totalNumber + '問'],
                ['正解数', correctNumber + '問'],
                ['誤答数', incorrectNumber + '問']
            ];

            infoItems.forEach(function (entry) {
                const wrapper = document.createElement('div');
                wrapper.className = 'history-detail-item';
                const dt = document.createElement('dt');
                dt.textContent = entry[0];
                const dd = document.createElement('dd');
                dd.textContent = entry[1];
                wrapper.appendChild(dt);
                wrapper.appendChild(dd);
                infoList.appendChild(wrapper);
            });

            detailsBody.appendChild(infoList);

            const mistakesSection = document.createElement('div');
            mistakesSection.className = 'history-detail-section';

            const mistakesTitle = document.createElement('h4');
            mistakesTitle.className = 'history-detail-title';
            mistakesTitle.textContent = '間違えた問題';
            mistakesSection.appendChild(mistakesTitle);

            const incorrectQuestions = Array.isArray(result.incorrectQuestions) ? result.incorrectQuestions : [];

            if (incorrectQuestions.length) {
                const mistakesList = document.createElement('ul');
                mistakesList.className = 'history-incorrect-list';

                incorrectQuestions.forEach(function (question) {
                    if (!question || typeof question !== 'object') {
                        return;
                    }

                    const listItem = document.createElement('li');
                    listItem.className = 'history-incorrect-item';

                    const number = typeof question.number === 'number' && !Number.isNaN(question.number)
                        ? question.number
                        : 0;
                    const questionText = typeof question.question === 'string' ? question.question : '';
                    const correctAnswer = typeof question.correctAnswer === 'string' ? question.correctAnswer : '';
                    const userAnswerRaw = typeof question.userAnswer === 'string' ? question.userAnswer : '';
                    const userAnswer = userAnswerRaw !== '' ? userAnswerRaw : '未回答';

                    const questionLine = document.createElement('p');
                    questionLine.className = 'history-incorrect-question';
                    const label = number > 0 ? 'Q' + number + '. ' : '';
                    questionLine.textContent = label + (questionText || '問題文がありません。');
                    listItem.appendChild(questionLine);

                    const answerLine = document.createElement('p');
                    answerLine.className = 'history-incorrect-answer';
                    const correctLabel = correctAnswer !== '' ? correctAnswer : '不明';
                    answerLine.textContent = '正解: ' + correctLabel + ' / あなたの回答: ' + userAnswer;
                    listItem.appendChild(answerLine);

                    mistakesList.appendChild(listItem);
                });

                mistakesSection.appendChild(mistakesList);
            } else {
                const allCorrect = document.createElement('p');
                allCorrect.className = 'history-detail-note';
                allCorrect.textContent = '間違えた問題はありません。';
                mistakesSection.appendChild(allCorrect);
            }

            detailsBody.appendChild(mistakesSection);
            details.appendChild(detailsBody);
            item.appendChild(details);

            return item;
        }

        function renderList() {
            if (!listElement) {
                return;
            }
            listElement.innerHTML = '';
            const startIndex = (historyState.page - 1) * historyState.pageSize;
            const endIndex = startIndex + historyState.pageSize;
            historyState.filtered.slice(startIndex, endIndex).forEach(function (result) {
                listElement.appendChild(createHistoryListItem(result));
            });
        }

        function updatePagination() {
            if (!paginationElement) {
                return;
            }

            const totalItems = historyState.filtered.length;
            const totalPages = Math.max(1, Math.ceil(totalItems / historyState.pageSize));

            if (totalItems <= historyState.pageSize) {
                paginationElement.hidden = true;
                return;
            }

            paginationElement.hidden = false;
            if (pageInfoElement) {
                pageInfoElement.textContent = historyState.page + ' / ' + totalPages;
            }
            if (prevButton) {
                prevButton.disabled = historyState.page <= 1;
            }
            if (nextButton) {
                nextButton.disabled = historyState.page >= totalPages;
            }
        }

        function applyFilters() {
            const searchTerm = (historyState.search || '').trim().toLowerCase();
            const difficultyValue = historyState.difficulty || '';
            const sortKey = historyState.sort || 'date_desc';

            const filtered = historyState.results.filter(function (result) {
                if (!result || typeof result !== 'object') {
                    return false;
                }

                let matchesSearch = true;
                if (searchTerm) {
                    const haystack = [
                        result.examTitle || '',
                        result.categoryName || '',
                        formatDifficulty(result.difficulty || '')
                    ].join(' ').toLowerCase();
                    matchesSearch = haystack.indexOf(searchTerm) !== -1;
                }

                let matchesDifficulty = true;
                if (difficultyValue) {
                    matchesDifficulty = (result.difficulty || '') === difficultyValue;
                }

                return matchesSearch && matchesDifficulty;
            });

            const sorted = filtered.sort(function (a, b) {
                const aTimeSource = a && (a.completedAt || a.savedAt) ? (a.completedAt || a.savedAt) : null;
                const bTimeSource = b && (b.completedAt || b.savedAt) ? (b.completedAt || b.savedAt) : null;
                const aTime = aTimeSource ? new Date(aTimeSource).getTime() : 0;
                const bTime = bTimeSource ? new Date(bTimeSource).getTime() : 0;

                const aScore = typeof a.scorePercent === 'number'
                    ? a.scorePercent
                    : calculateScorePercent(a.correct, a.total);
                const bScore = typeof b.scorePercent === 'number'
                    ? b.scorePercent
                    : calculateScorePercent(b.correct, b.total);

                switch (sortKey) {
                    case 'date_asc':
                        return aTime - bTime;
                    case 'score_desc':
                        if (bScore !== aScore) {
                            return bScore - aScore;
                        }
                        return bTime - aTime;
                    case 'score_asc':
                        if (aScore !== bScore) {
                            return aScore - bScore;
                        }
                        return aTime - bTime;
                    case 'date_desc':
                    default:
                        return bTime - aTime;
                }
            });

            historyState.filtered = sorted;

            const totalResults = historyState.results.length;
            const totalFiltered = sorted.length;

            if (totalFiltered === 0) {
                historyState.page = 1;
            } else {
                const totalPages = Math.max(1, Math.ceil(totalFiltered / historyState.pageSize));
                if (historyState.page > totalPages) {
                    historyState.page = totalPages;
                }
                if (historyState.page < 1) {
                    historyState.page = 1;
                }
            }

            if (clearButton) {
                clearButton.disabled = totalResults === 0;
            }

            if (totalFiltered === 0) {
                if (listElement) {
                    listElement.innerHTML = '';
                }
                const message = totalResults === 0
                    ? '保存された履歴はありません。'
                    : '条件に一致する履歴はありません。';
                updateEmptyState(true, message);
                if (paginationElement) {
                    paginationElement.hidden = true;
                }
            } else {
                updateEmptyState(false);
                renderList();
                updatePagination();
            }

            updateSummary(totalResults, totalFiltered);
        }

        function loadResults() {
            return fetchAllResults().then(function (results) {
                historyState.results = Array.isArray(results) ? results : [];
                historyState.page = 1;
                applyFilters();
            }).catch(function (error) {
                console.error('Failed to load saved results', error);
                setStatus('保存済みの結果を読み込めませんでした。', 'error');
                if (listElement) {
                    listElement.innerHTML = '';
                }
                updateEmptyState(true, '保存済みの結果を読み込めませんでした。');
                if (clearButton) {
                    clearButton.disabled = true;
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                historyState.search = searchInput.value;
                historyState.page = 1;
                applyFilters();
            });
        }

        if (difficultyFilter) {
            difficultyFilter.addEventListener('change', function () {
                historyState.difficulty = difficultyFilter.value;
                historyState.page = 1;
                applyFilters();
            });
        }

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                historyState.sort = sortSelect.value;
                historyState.page = 1;
                applyFilters();
            });
        }

        if (prevButton) {
            prevButton.addEventListener('click', function () {
                if (historyState.page > 1) {
                    historyState.page -= 1;
                    renderList();
                    updatePagination();
                }
            });
        }

        if (nextButton) {
            nextButton.addEventListener('click', function () {
                const totalFiltered = historyState.filtered.length;
                const totalPages = Math.max(1, Math.ceil(totalFiltered / historyState.pageSize));
                if (historyState.page < totalPages) {
                    historyState.page += 1;
                    renderList();
                    updatePagination();
                }
            });
        }

        function saveCurrentResult() {
            if (!currentResult) {
                return;
            }

            const correctNumber = Number(currentResult.correct) || 0;
            const totalNumber = Number(currentResult.total) || 0;
            const incorrectNumberRaw = Number(currentResult.incorrect);
            const incorrectNumber = Number.isFinite(incorrectNumberRaw)
                ? incorrectNumberRaw
                : Math.max(totalNumber - correctNumber, 0);

            const normalizedIncorrectQuestions = normalizeIncorrectQuestions(currentResult.incorrectQuestions);

            const resultToSave = {
                id: currentResult.resultId || generateId(),
                resultId: currentResult.resultId || '',
                examId: currentResult.examId || '',
                examTitle: currentResult.examTitle || '',
                categoryId: currentResult.categoryId || '',
                categoryName: currentResult.categoryName || '',
                difficulty: currentResult.difficulty || '',
                correct: correctNumber,
                incorrect: incorrectNumber,
                total: totalNumber,
                scorePercent: calculateScorePercent(correctNumber, totalNumber),
                completedAt: currentResult.completedAt || new Date().toISOString(),
                savedAt: new Date().toISOString(),
                incorrectQuestions: normalizedIncorrectQuestions
            };

            setStatus('最新の結果を保存しています...', 'info');

            addResult(resultToSave).then(function () {
                setStatus('最新の結果を保存しました。', 'success');
                if (isHistoryPage) {
                    loadResults();
                }
            }).catch(function (error) {
                console.error('Failed to save result', error);
                setStatus('結果の保存に失敗しました。', 'error');
            });
        }

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                if (clearButton.disabled) {
                    return;
                }
                const confirmed = window.confirm('保存されている受験履歴をすべて削除しますか？');
                if (!confirmed) {
                    return;
                }
                clearButton.disabled = true;
                setStatus('履歴を削除しています...', 'info');
                clearAllResults().then(function () {
                    setStatus('履歴を削除しました。', 'success');
                    historyState.results = [];
                    historyState.filtered = [];
                    historyState.page = 1;
                    if (paginationElement) {
                        paginationElement.hidden = true;
                    }
                    updateEmptyState(true, '保存された履歴はありません。');
                    updateSummary(0, 0);
                }).catch(function (error) {
                    console.error('Failed to clear saved results', error);
                    setStatus('履歴の削除に失敗しました。', 'error');
                    if (historyState.results.length) {
                        clearButton.disabled = false;
                    }
                });
            });
        }

        if (clearButton && isHistoryPage) {
            clearButton.disabled = true;
        }

        if (isHistoryPage) {
            loadResults();
        }

        if (currentResult) {
            saveCurrentResult();
        }
    });
</script>
</body>
</html>
