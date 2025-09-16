<?php
declare(strict_types=1);

session_start();

const DATA_DIRECTORY = __DIR__ . '/data';

/**
 * @return array{exams: array<string, array{meta: array{id: string, title: string, description: string, version: string, question_count: int, source_file: string}, questions: array<int, array{id: string, question: string, choices: array<int, array{key: string, text: string}>, answer: string, explanation: string}>}>, errors: string[]}
 */
function loadExamCatalog(): array
{
    $exams = [];
    $errors = [];

    if (!is_dir(DATA_DIRECTORY)) {
        $errors[] = '問題データディレクトリが見つかりません。';
        return ['exams' => [], 'errors' => $errors];
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
            $explanation = isset($questionData['explanation']) ? (string)$questionData['explanation'] : '';

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
                } else {
                    $key = '';
                    $text = (string)$choiceData;
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

            $questions[] = [
                'id' => $questionId,
                'question' => $questionText,
                'choices' => $choices,
                'answer' => $answer,
                'explanation' => trim($explanation),
            ];
        }

        if ($skipped > 0) {
            $errors[] = sprintf('%s で %d 問が読み込めませんでした。', $fileName, $skipped);
        }

        if (empty($questions)) {
            $errors[] = sprintf('%s に有効な問題がありません。', $fileName);
            continue;
        }

        $exams[$examId] = [
            'meta' => [
                'id' => $examId,
                'title' => $title,
                'description' => $description,
                'version' => $version,
                'question_count' => count($questions),
                'source_file' => $fileName,
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

    return ['exams' => $exams, 'errors' => $errors];
}

function h(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl2brSafe(string $text): string
{
    return nl2br(h($text), false);
}

function buildInputId(string $questionId, string $choiceKey): string
{
    $normalized = preg_replace('/[^a-zA-Z0-9_-]/u', '_', $questionId . '_' . $choiceKey);
    return 'choice_' . $normalized;
}

$catalog = loadExamCatalog();
$exams = $catalog['exams'];
$errorMessages = $catalog['errors'];

$currentQuiz = $_SESSION['current_quiz'] ?? null;
$view = $currentQuiz ? 'quiz' : 'home';
$results = null;

$selectedExamId = '';
if (!empty($exams)) {
    $keys = array_keys($exams);
    $selectedExamId = (string)array_shift($keys);
}

$questionCountInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $postedExamId = isset($_POST['exam_id']) ? (string)$_POST['exam_id'] : '';
    if ($postedExamId !== '' && isset($exams[$postedExamId])) {
        $selectedExamId = $postedExamId;
    }

    if (isset($_POST['question_count'])) {
        $questionCountInput = (string)max(0, (int)$_POST['question_count']);
    }

    switch ($action) {
        case 'start_quiz':
            if ($postedExamId === '' || !isset($exams[$postedExamId])) {
                $errorMessages[] = '選択した試験データが見つかりません。';
                $view = 'home';
                break;
            }

            $questionCount = isset($_POST['question_count']) ? (int)$_POST['question_count'] : 0;
            if ($questionCount < 1) {
                $errorMessages[] = '出題数は1以上を指定してください。';
                $view = 'home';
                break;
            }

            $availableQuestions = $exams[$postedExamId]['questions'];
            if ($questionCount > count($availableQuestions)) {
                $errorMessages[] = sprintf('出題数が多すぎます。（最大 %d 問まで）', count($availableQuestions));
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

            $submittedAnswers = $_POST['answers'] ?? [];
            if (!is_array($submittedAnswers)) {
                $submittedAnswers = [];
            }

            $questionResults = [];
            $correctCount = 0;
            foreach ($currentQuiz['questions'] as $index => $question) {
                $questionId = $question['id'];
                $userAnswer = null;
                if (isset($submittedAnswers[$questionId]) && !is_array($submittedAnswers[$questionId])) {
                    $userAnswer = (string)$submittedAnswers[$questionId];
                }
                $isCorrect = $userAnswer !== null && $userAnswer === $question['answer'];
                if ($isCorrect) {
                    $correctCount++;
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
                ];
            }

            $results = [
                'exam' => $currentQuiz['meta'],
                'total' => count($questionResults),
                'correct' => $correctCount,
                'questions' => $questionResults,
            ];

            unset($_SESSION['current_quiz']);
            $currentQuiz = null;
            $view = 'results';
            break;

        case 'reset_quiz':
            unset($_SESSION['current_quiz']);
            $currentQuiz = null;
            $view = 'home';
            break;

        default:
            // keep current view
            break;
    }
}

if ($view === 'quiz' && !$currentQuiz && isset($_SESSION['current_quiz'])) {
    $currentQuiz = $_SESSION['current_quiz'];
}

if ($view === 'quiz' && !$currentQuiz) {
    $view = 'home';
}

$selectedExam = ($selectedExamId !== '' && isset($exams[$selectedExamId])) ? $exams[$selectedExamId] : null;

if ($questionCountInput === '' || $questionCountInput === '0') {
    if ($selectedExam) {
        $defaultCount = min(5, $selectedExam['meta']['question_count']);
        if ($defaultCount < 1) {
            $defaultCount = $selectedExam['meta']['question_count'];
        }
        if ($defaultCount < 1) {
            $defaultCount = 1;
        }
        $questionCountInput = (string)$defaultCount;
    } else {
        $questionCountInput = '1';
    }
}

$totalExams = count($exams);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資格試験問題集</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app">
    <header>
        <h1>資格試験問題集</h1>
        <p>JSON形式で管理された問題データから出題する学習支援アプリです。</p>
    </header>

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
                    <input type="hidden" name="action" value="start_quiz">
                    <div class="form-field">
                        <label for="exam_id">資格試験</label>
                        <select name="exam_id" id="exam_id" required>
                            <?php foreach ($exams as $examId => $exam): ?>
                                <option value="<?php echo h($examId); ?>" <?php echo $examId === $selectedExamId ? 'selected' : ''; ?>>
                                    <?php echo h($exam['meta']['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="question_count">出題数</label>
                        <input type="number" id="question_count" name="question_count" min="1" max="<?php echo $selectedExam ? (int)$selectedExam['meta']['question_count'] : 1; ?>" value="<?php echo h($questionCountInput); ?>" required>
                        <?php if ($selectedExam): ?>
                            <small>この試験には <?php echo (int)$selectedExam['meta']['question_count']; ?> 問登録されています。</small>
                        <?php endif; ?>
                    </div>
                    <?php if ($selectedExam): ?>
                        <div class="exam-meta">
                            <span><strong>試験名:</strong> <?php echo h($selectedExam['meta']['title']); ?></span>
                            <?php if ($selectedExam['meta']['version'] !== ''): ?>
                                <span><strong>バージョン:</strong> <?php echo h($selectedExam['meta']['version']); ?></span>
                            <?php endif; ?>
                            <?php if ($selectedExam['meta']['description'] !== ''): ?>
                                <span><strong>概要:</strong> <?php echo nl2brSafe($selectedExam['meta']['description']); ?></span>
                            <?php endif; ?>
                            <span><strong>問題数:</strong> <?php echo (int)$selectedExam['meta']['question_count']; ?> 問</span>
                            <span><strong>データファイル:</strong> <?php echo h($selectedExam['meta']['source_file']); ?></span>
                        </div>
                    <?php endif; ?>
                    <button type="submit">問題を開始</button>
                </form>
            </div>
            <div class="section-card">
                <h2>登録済みの資格試験 (<?php echo $totalExams; ?>)</h2>
                <ul class="exam-list">
                    <?php foreach ($exams as $exam): ?>
                        <li>
                            <h3><?php echo h($exam['meta']['title']); ?></h3>
                            <?php if ($exam['meta']['description'] !== ''): ?>
                                <p><?php echo nl2brSafe($exam['meta']['description']); ?></p>
                            <?php endif; ?>
                            <p class="meta">
                                <?php if ($exam['meta']['version'] !== ''): ?>
                                    バージョン: <?php echo h($exam['meta']['version']); ?> / 
                                <?php endif; ?>
                                問題数: <?php echo (int)$exam['meta']['question_count']; ?> 問 / 
                                ファイル: <?php echo h($exam['meta']['source_file']); ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php elseif ($view === 'quiz' && $currentQuiz): ?>
        <div class="quiz-header">
            <h2><?php echo h($currentQuiz['meta']['title']); ?></h2>
            <p>全 <?php echo (int)$currentQuiz['meta']['question_count']; ?> 問中から <?php echo count($currentQuiz['questions']); ?> 問を出題中です。</p>
        </div>
        <form method="post" class="quiz-form">
            <?php foreach ($currentQuiz['questions'] as $index => $question): ?>
                <div class="question-card">
                    <h3>Q<?php echo $index + 1; ?>. <?php echo h($question['question']); ?></h3>
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
        <div class="results-summary">
            <h2><?php echo h($results['exam']['title']); ?> の結果</h2>
            <p><?php echo $results['correct']; ?> / <?php echo $results['total']; ?> 問正解（正答率 <?php echo $scorePercent; ?>%）</p>
        </div>
        <?php foreach ($results['questions'] as $question): ?>
            <div class="question-card <?php echo $question['is_correct'] ? 'correct' : 'incorrect'; ?>">
                <h3>Q<?php echo $question['number']; ?>. <?php echo h($question['question']); ?></h3>
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
                <div class="explanation">
                    <strong>解説:</strong>
                    <p><?php echo nl2brSafe($question['explanation']); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="actions">
            <form method="post">
                <input type="hidden" name="action" value="start_quiz">
                <input type="hidden" name="exam_id" value="<?php echo h($results['exam']['id']); ?>">
                <input type="hidden" name="question_count" value="<?php echo (int)$results['total']; ?>">
                <button type="submit">同じ条件で再挑戦</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="reset_quiz">
                <input type="hidden" name="exam_id" value="<?php echo h($results['exam']['id']); ?>">
                <button type="submit" class="secondary">別の試験を選ぶ</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
