<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $quiz->title }} — Quiz</title>
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="description" content="Take the {{ $quiz->title }} quiz. Answer all questions within the time limit.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    <style>
        :root {
            --color-bg: #080b14;
            --color-surface: #0f1623;
            --color-card: #141d2e;
            --color-border: #1e2d45;
            --color-accent: #6366f1;
            --color-accent-glow: rgba(99,102,241,0.15);
        }
        html, body {
            background: var(--color-bg);
            color: #e2e8f0;
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100%;
        }
        .opt-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1.5px solid var(--color-border);
            background: var(--color-card);
            color: #cbd5e1;
            font-size: 0.9rem;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 52px;
        }
        .opt-btn:hover:not(:disabled) {
            border-color: rgba(99,102,241,0.5);
            background: rgba(99,102,241,0.08);
            color: #e2e8f0;
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(99,102,241,0.12);
        }
        .opt-btn:disabled { cursor: default; }
        .opt-btn.selected {
            border-color: var(--color-accent);
            background: var(--color-accent-glow);
            color: #a5b4fc;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15), 0 4px 16px rgba(99,102,241,0.2);
        }
        .opt-letter {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.75rem;
            flex-shrink: 0;
            background: rgba(255,255,255,0.06);
            color: #94a3b8;
            transition: all 0.18s;
        }
        .opt-btn.selected .opt-letter {
            background: var(--color-accent);
            color: white;
        }
        .timer-ring {
            transition: stroke-dashoffset 1s linear;
        }
        .progress-bar {
            transition: width 0.4s ease;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .animate-slide-up { animation: slideUp 0.35s ease forwards; }
        .animate-fade-in { animation: fadeIn 0.3s ease forwards; }
        .hide { display: none !important; }
        .spinner-ring {
            border: 3px solid rgba(255,255,255,0.08);
            border-top: 3px solid #6366f1;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <!-- Fixed Timer Bar (top) -->
    <div id="timer-bar-wrap" class="fixed top-0 left-0 right-0 z-50 hide">
        <div class="flex items-center justify-between px-4 py-2.5 backdrop-blur-md" style="background: rgba(8,11,20,0.92); border-bottom: 1px solid #1e2d45;">
            <div class="flex items-center gap-3">
                <!-- SVG Circular Timer -->
                <svg width="36" height="36" viewBox="0 0 36 36" class="shrink-0 -rotate-90">
                    <circle cx="18" cy="18" r="15" fill="none" stroke="#1e2d45" stroke-width="3"/>
                    <circle id="timer-ring" cx="18" cy="18" r="15" fill="none" stroke="#6366f1" stroke-width="3"
                        stroke-dasharray="94.25" stroke-dashoffset="0" stroke-linecap="round" class="timer-ring"/>
                </svg>
                <div>
                    <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest">Time</div>
                    <div id="timer-display" class="text-lg font-extrabold text-white font-mono leading-tight">--</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right">
                    <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest">Progress</div>
                    <div id="question-counter" class="text-sm font-bold text-white">0 / 0</div>
                </div>
            </div>
        </div>
        <!-- Progress Line -->
        <div class="h-0.5 bg-slate-800/60">
            <div id="progress-line" class="h-full bg-indigo-500 progress-bar" style="width: 0%"></div>
        </div>
    </div>

    <!-- Main Quiz Container -->
    <div class="min-h-screen pt-0 pb-12 px-4 flex flex-col items-center justify-center" id="quiz-root">

        <!-- Start Screen -->
        <div id="start-screen" class="w-full max-w-lg text-center space-y-6 animate-slide-up py-12">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-3xl">🎯</div>
            <div class="space-y-2">
                <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">{{ $quiz->title }}</h1>
                <p class="text-slate-400 text-sm">Answer all questions within the time limit. Each question has its own timer.</p>
            </div>

            <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 text-left space-y-3">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-400 flex items-center gap-1.5">📋 Total Questions</span>
                    <span class="font-bold text-white">{{ count($questions) }}</span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-400 flex items-center gap-1.5">⏱️ Per-Question Timer</span>
                    <span class="font-bold text-white">Varies</span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-400 flex items-center gap-1.5">📝 Question Type</span>
                    <span class="font-bold text-white">Multiple Choice</span>
                </div>
            </div>

            <button id="start-btn"
                class="w-full py-4 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-base shadow-2xl shadow-indigo-900/40 transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] flex items-center justify-center gap-2">
                <span>🚀</span> Start Quiz
            </button>
        </div>

        <!-- Question Display -->
        <div id="question-screen" class="hide w-full max-w-2xl pt-20 pb-8 space-y-5">

            <!-- Question Card -->
            <div id="question-card" class="rounded-2xl border overflow-hidden shadow-2xl"
                 style="background: var(--color-card); border-color: var(--color-border);">
                <!-- Question header bar -->
                <div class="px-5 py-3 border-b flex items-center justify-between" style="border-color: var(--color-border); background: rgba(255,255,255,0.02);">
                    <span id="qcard-index" class="text-xs font-bold text-indigo-400 uppercase tracking-widest">Question 1</span>
                    <span id="qcard-points" class="text-xs font-mono text-slate-400 bg-slate-800/60 px-2.5 py-0.5 rounded-full border border-slate-700">⏱ --s</span>
                </div>

                <!-- Question Prompt -->
                    <div id="question-prompt" class="text-lg sm:text-xl font-semibold text-slate-100 leading-snug"></div>
                </div>
            </div>

            <!-- Options -->
            <div id="options-container" class="space-y-2.5 animate-slide-up"></div>

            <!-- Feedback Flash -->
            <div id="feedback-flash" class="hide text-center">
                <span id="feedback-text" class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold bg-amber-900/40 text-amber-200 border border-amber-700/40">
                    ⏰ Time's up! Moving on...
                </span>
            </div>
        </div>

        <!-- End / Submit Screen -->
        <div id="end-screen" class="hide w-full max-w-lg text-center space-y-6 animate-slide-up py-12">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-emerald-600/20 border border-emerald-500/30 flex items-center justify-center text-3xl">🎉</div>
            <div>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-white">All Done!</h2>
                <p class="text-slate-400 text-sm mt-1">You've answered all the questions. Submit your responses below.</p>
            </div>
            <form id="quiz-form" action="{{ route('result.store') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="quiz_id" value="{{ $quiz->id }}">
                <input type="hidden" name="student_id" value="{{ session('student_id') }}">
                <div id="answers-container"></div>
                <button type="submit"
                    class="w-full py-4 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-base shadow-2xl shadow-emerald-900/40 transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] flex items-center justify-center gap-2">
                    <span>✅</span> Submit Results
                </button>
            </form>
        </div>

    </div>

<script>
(function () {
    const questions = @json($questions);
    const quizId = {{ $quiz->id }};

    const startScreen = document.getElementById('start-screen');
    const questionScreen = document.getElementById('question-screen');
    const endScreen = document.getElementById('end-screen');
    const timerBarWrap = document.getElementById('timer-bar-wrap');

    const timerDisplay = document.getElementById('timer-display');
    const timerRing = document.getElementById('timer-ring');
    const progressLine = document.getElementById('progress-line');
    const questionCounter = document.getElementById('question-counter');

    const questionCard = document.getElementById('question-card');
    const qcardIndex = document.getElementById('qcard-index');
    const qcardPoints = document.getElementById('qcard-points');
    const questionPrompt = document.getElementById('question-prompt');
    const optionsContainer = document.getElementById('options-container');
    const feedbackFlash = document.getElementById('feedback-flash');
    const feedbackText = document.getElementById('feedback-text');

    const answersContainer = document.getElementById('answers-container');
    const quizForm = document.getElementById('quiz-form');

    let currentIndex = 0;
    let timerId = null;
    let quizStarted = false;
    let selectedAnswers = [];
    let shuffledQuestions = [];
    const optLetters = ['A', 'B', 'C', 'D'];
    const RING_CIRCUMFERENCE = 94.25;

    // Shuffle helper
    function shuffle(arr) {
        const a = [...arr];
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    // Render question
    function renderQuestion() {
        const q = shuffledQuestions[currentIndex];
        if (!q) return;

        const H = window.QuizHighlighter;

        // Update header
        qcardIndex.textContent = `Question ${currentIndex + 1}`;
        qcardPoints.textContent = `⏱ ${q.duration || 30}s`;
        questionCounter.textContent = `${currentIndex + 1} / ${shuffledQuestions.length}`;

        // Animate card entry
        questionCard.style.opacity = '0';
        questionCard.style.transform = 'translateY(16px)';
        optionsContainer.style.opacity = '0';
        feedbackFlash.classList.add('hide');

        requestAnimationFrame(() => {
            questionCard.style.transition = 'opacity 0.28s ease, transform 0.28s ease';
            questionCard.style.opacity = '1';
            questionCard.style.transform = 'translateY(0)';
            optionsContainer.style.transition = 'opacity 0.28s ease 0.1s';
            optionsContainer.style.opacity = '1';
        });

        // Render prompt
        questionPrompt.innerHTML = '';
        if (q.text) {
            if (H) {
                const blocks = H.parseContentWithCode(q.text);
                blocks.forEach(block => {
                    if (block.type === 'code') {
                        const div = document.createElement('div');
                        div.innerHTML = H.renderCodeBoxHtml(block.content, block.language);
                        questionPrompt.appendChild(div);
                    } else {
                        const trimmed = block.content.trim();
                        if (trimmed) {
                            const p = document.createElement('p');
                            p.className = 'text-xl font-semibold text-slate-100 leading-snug mb-2';
                            p.innerHTML = H.formatInlineCode(H.escapeHtml(trimmed));
                            questionPrompt.appendChild(p);
                        }
                    }
                });
            } else {
                const p = document.createElement('p');
                p.textContent = q.text;
                questionPrompt.appendChild(p);
            }
        }

        // Render options
        optionsContainer.innerHTML = '';
        for (let i = 1; i <= 4; i++) {
            const raw = q['option' + i] || '';
            if (!raw) continue;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'opt-btn';
            btn.dataset.opt = i;

            const letter = document.createElement('span');
            letter.className = 'opt-letter';
            letter.textContent = optLetters[i - 1];

            const label = document.createElement('span');
            label.className = 'flex-1 leading-snug';
            label.innerHTML = H ? H.formatInlineCode(H.escapeHtml(raw)) : raw;

            btn.appendChild(letter);
            btn.appendChild(label);

            btn.addEventListener('click', () => handleAnswer(parseInt(btn.dataset.opt), q));
            optionsContainer.appendChild(btn);
        }

        // Start timer
        startTimer(parseInt(q.duration) || 30, q);
        updateProgress();
    }

    function handleAnswer(selectedOpt, q) {
        clearInterval(timerId);

        // Visual selection
        document.querySelectorAll('.opt-btn').forEach(b => {
            b.disabled = true;
            if (parseInt(b.dataset.opt) === selectedOpt) {
                b.classList.add('selected');
            }
        });

        selectedAnswers.push({
            question_id: q.id,
            selected_option: selectedOpt
        });

        setTimeout(() => nextQuestion(), 420);
    }

    function handleTimeout(q) {
        clearInterval(timerId);
        document.querySelectorAll('.opt-btn').forEach(b => b.disabled = true);

        // Show timeout flash
        feedbackFlash.classList.remove('hide');

        selectedAnswers.push({
            question_id: q.id,
            selected_option: 0  // 0 = no answer
        });

        setTimeout(() => nextQuestion(), 1200);
    }

    function nextQuestion() {
        currentIndex++;
        if (currentIndex < shuffledQuestions.length) {
            renderQuestion();
        } else {
            endQuiz();
        }
    }

    function startTimer(duration, q) {
        clearInterval(timerId);
        let timeLeft = isNaN(duration) || duration < 1 ? 30 : duration;
        const totalTime = timeLeft;

        function tick() {
            timerDisplay.textContent = timeLeft + 's';

            // Ring progress
            const ratio = timeLeft / totalTime;
            timerRing.style.strokeDashoffset = RING_CIRCUMFERENCE * (1 - ratio);

            // Color change as time runs low
            if (ratio <= 0.25) {
                timerRing.style.stroke = '#ef4444';
                timerDisplay.style.color = '#fca5a5';
            } else if (ratio <= 0.5) {
                timerRing.style.stroke = '#f59e0b';
                timerDisplay.style.color = '#fcd34d';
            } else {
                timerRing.style.stroke = '#6366f1';
                timerDisplay.style.color = '#ffffff';
            }

            if (timeLeft <= 0) {
                clearInterval(timerId);
                handleTimeout(q);
                return;
            }
            timeLeft--;
        }

        tick();
        timerId = setInterval(tick, 1000);
    }

    function updateProgress() {
        const pct = (currentIndex / shuffledQuestions.length) * 100;
        progressLine.style.width = pct + '%';
    }

    function endQuiz() {
        clearInterval(timerId);
        timerBarWrap.classList.add('hide');
        questionScreen.classList.add('hide');
        endScreen.classList.remove('hide');
        window.onbeforeunload = null;

        selectedAnswers.forEach((ans, index) => {
            answersContainer.innerHTML += `
                <input type="hidden" name="answers[${index}][question_id]" value="${ans.question_id}" />
                <input type="hidden" name="answers[${index}][selected_option]" value="${ans.selected_option}" />
            `;
        });
    }

    // Start button
    document.getElementById('start-btn').addEventListener('click', () => {
        quizStarted = true;
        shuffledQuestions = shuffle(questions);
        currentIndex = 0;
        selectedAnswers = [];

        window.onbeforeunload = () => 'Are you sure you want to leave? Your quiz progress will be lost.';

        startScreen.classList.add('hide');
        questionScreen.classList.remove('hide');
        timerBarWrap.classList.remove('hide');

        questionCounter.textContent = `1 / ${shuffledQuestions.length}`;
        renderQuestion();
    });

    // Submit form
    quizForm.addEventListener('submit', () => {
        quizStarted = false;
        window.onbeforeunload = null;
    });

})();
</script>
</body>
</html>
