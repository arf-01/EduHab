<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import { createSessionId, db, makeRecordId, type Question, type Quiz } from './db';
    import { submitQuiz } from './api';
    import { parseContentWithCode, highlightCodeSyntax, formatInlineCode } from './CodeHighlighter';

    let { sessionId, tabId, attemptId, attemptToken, quizId, studentId, isOnline, onComplete, onOfflineSubmit, onQuizEnded } = $props<{
        sessionId: string,
        tabId: string,
        attemptId: number,
        attemptToken: string,
        quizId: number, 
        studentId: string,
        isOnline: boolean,
        onComplete: (score: number, total: number) => void,
        onOfflineSubmit: () => void,
        onQuizEnded: (message: string) => void
    }>();

    let quiz = $state<Quiz | null>(null);
    let questions = $state<Question[]>([]);
    let currentQuestionIndex = $state(0);
    let selectedOption = $state<number | null>(null);
    let remainingTime = $state(0);
    let questionEndTime = $state(0);
    let isSubmitting = $state(false);

    let timerInterval: number;

    onMount(async () => {
        quiz = await db.sessionQuizzes.get(sessionId) || null;
        questions = await db.sessionQuestions.where('sessionId').equals(sessionId).toArray();
        
        if (!quiz || questions.length === 0) {
            console.error("Quiz data not found in local DB.");
            return;
        }

        const savedState = await db.quizStates.where('sessionId').equals(sessionId).first();
        if (savedState && savedState.studentId === studentId && (savedState.remainingTime > 0 || savedState.questionEndTime)) {
            const now = Date.now();
            let qIndex = savedState.currentQuestionId;
            let targetEnd = savedState.questionEndTime || (savedState.lastSaved ? (new Date(savedState.lastSaved).getTime() + savedState.remainingTime * 1000) : (now + savedState.remainingTime * 1000));

            while (now >= targetEnd && qIndex < questions.length - 1) {
                qIndex += 1;
                const nextQDuration = Number(questions[qIndex]?.duration) || 60;
                targetEnd += nextQDuration * 1000;
            }

            if (now >= targetEnd && qIndex >= questions.length - 1) {
                currentQuestionIndex = questions.length - 1;
                remainingTime = 0;
                questionEndTime = now;
                await loadAnswerForCurrentQuestion();
                await forceSubmit();
                return;
            } else {
                currentQuestionIndex = Math.min(qIndex, questions.length - 1);
                questionEndTime = targetEnd;
                remainingTime = Math.max(1, Math.ceil((questionEndTime - now) / 1000));
            }
        } else {
            currentQuestionIndex = 0;
            const duration = Number(questions[0]?.duration) || 60;
            questionEndTime = Date.now() + duration * 1000;
            remainingTime = duration;
        }

        await loadAnswerForCurrentQuestion();
        await saveState();

        startTimer();
    });

    onDestroy(() => {
        clearInterval(timerInterval);
    });

    function startTimer() {
        clearInterval(timerInterval);
        timerInterval = window.setInterval(() => {
            const now = Date.now();
            remainingTime = Math.max(0, Math.ceil((questionEndTime - now) / 1000));
            saveState();

            if (remainingTime <= 0) {
                handleTimeExpiry();
            }
        }, 1000);
    }

    async function handleTimeExpiry() {
        if (currentQuestionIndex < questions.length - 1) {
            await nextQuestion();
        } else {
            await forceSubmit();
        }
    }

    async function saveState() {
        const stateToSave = {
            sessionId,
            studentId,
            quizId,
            currentQuestionId: currentQuestionIndex,
            remainingTime,
            questionEndTime,
            lastSaved: new Date().toISOString()
        };

        const existing = await db.quizStates.where('sessionId').equals(sessionId).first();
        if (existing) {
            await db.quizStates.update(existing.id!, stateToSave);
        } else {
            await db.quizStates.add(stateToSave);
        }
        await db.quizSessions.update(sessionId, { lastSaved: stateToSave.lastSaved });
    }

    async function loadAnswerForCurrentQuestion() {
        if (questions.length === 0) return;
        const currentQ = questions[currentQuestionIndex];
        const savedAnswer = await db.sessionAnswers.get(makeRecordId(sessionId, currentQ.questionId));
        selectedOption = savedAnswer ? savedAnswer.selectedOption : null;
    }

    async function handleOptionSelect(optionNum: number) {
        selectedOption = optionNum;
        const currentQ = questions[currentQuestionIndex];
        
        await db.sessionAnswers.put({
            id: makeRecordId(sessionId, currentQ.questionId),
            sessionId,
            quizId,
            questionId: currentQ.questionId,
            selectedOption: optionNum,
            answeredAt: new Date().toISOString()
        });

        await saveState();
    }

    async function nextQuestion() {
        if (currentQuestionIndex < questions.length - 1) {
            selectedOption = null;
            currentQuestionIndex += 1;
            const dur = Number(questions[currentQuestionIndex]?.duration) || 60;
            questionEndTime = Date.now() + dur * 1000;
            remainingTime = dur;
            await loadAnswerForCurrentQuestion();
            await saveState();
        }
    }

    async function forceSubmit() {
        if (isSubmitting) return;
        isSubmitting = true;
        clearInterval(timerInterval);
        
        try {
            const allSavedAnswers = await db.sessionAnswers.where('sessionId').equals(sessionId).toArray();
            
            const payloadAnswers = allSavedAnswers.map(a => ({
                questionId: a.questionId,
                selectedOption: a.selectedOption,
                answeredAt: a.answeredAt
            }));
            const submissionId = createSessionId();

            let submittedOnline = false;

            if (isOnline && navigator.onLine) {
                try {
                    const response = await submitQuiz(quizId, studentId, payloadAnswers, {
                        submissionId,
                        sessionId,
                        attemptId,
                        attemptToken
                    });
                    await db.quizStates.where('sessionId').equals(sessionId).delete();
                    await db.sessionAnswers.where('sessionId').equals(sessionId).delete();
                    await db.sessionQuestions.where('sessionId').equals(sessionId).delete();
                    await db.sessionQuizzes.delete(sessionId);
                    await db.quizSessions.delete(sessionId);
                    onComplete(response.score, response.total);
                    submittedOnline = true;
                } catch (netError: any) {
                    if (netError?.quizEnded) {
                        await db.quizStates.where('sessionId').equals(sessionId).delete();
                        await db.sessionAnswers.where('sessionId').equals(sessionId).delete();
                        await db.sessionQuestions.where('sessionId').equals(sessionId).delete();
                        await db.sessionQuizzes.delete(sessionId);
                        await db.quizSessions.delete(sessionId);
                        onQuizEnded(netError.message || 'This quiz has already ended. Submissions are no longer accepted.');
                        return;
                    }
                    console.warn("Online submission failed. Queueing offline...", netError);
                }
            }

            if (!submittedOnline) {
                await db.pendingSubmissions.add({
                    submissionId,
                    sessionId,
                    tabId,
                    studentId,
                    quizId,
                    attemptId,
                    attemptToken,
                    answers: payloadAnswers,
                    createdAt: new Date().toISOString(),
                    attemptCount: 0,
                    synced: 0
                });
                
                await db.quizStates.where('sessionId').equals(sessionId).delete();
                onOfflineSubmit();
            }
        } catch (error: any) {
            console.error("Submission failed", error);
        } finally {
            isSubmitting = false;
        }
    }

    function formatTime(seconds: number) {
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    }

    let currentQuestion = $derived(questions[currentQuestionIndex]);
    let progressPercentage = $derived(questions.length > 0 ? ((currentQuestionIndex + 1) / questions.length) * 100 : 0);
</script>

{#if !quiz || questions.length === 0}
    <div class="edu-card p-8 w-full max-w-md text-center space-y-5 shadow-2xl">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-amber-500/15 text-amber-400 border border-amber-500/30 flex items-center justify-center text-2xl">
            ⚡
        </div>
        <div class="space-y-1.5">
            <h3 class="text-lg font-bold text-white">Session Expired or Empty</h3>
            <p class="text-xs text-slate-400">No cached questions found for this quiz session.</p>
        </div>
        <button 
            type="button" 
            onclick={async () => {
                try {
                    await db.quizStates.where('sessionId').equals(sessionId).delete();
                    await db.sessionAnswers.where('sessionId').equals(sessionId).delete();
                } catch (e) {}
                window.location.reload();
            }}
            class="edu-btn-primary w-full text-sm"
        >
            Enter Room Name
        </button>
    </div>
{:else}
    <div class="edu-card w-full max-w-3xl overflow-hidden flex flex-col h-[82vh] max-h-[850px] shadow-2xl shadow-black/60 border-slate-800">
        
        <!-- Quiz Header -->
        <div class="bg-slate-900/90 border-b border-slate-800 p-5 sm:px-8 flex justify-between items-center shrink-0">
            <div>
                <h2 class="text-lg sm:text-xl font-extrabold text-white tracking-tight">{quiz.title}</h2>
                <p class="text-indigo-300 text-xs mt-0.5 font-mono font-semibold uppercase tracking-wider">
                    Question {currentQuestionIndex + 1} of {questions.length}
                </p>
            </div>
            {#if currentQuestion}
            <div class="flex items-center gap-2 px-3.5 py-1.5 rounded-xl border font-mono {remainingTime < 30 ? 'bg-rose-950/80 border-rose-700/60 text-rose-300 animate-pulse' : 'bg-slate-950/80 border-slate-700/60 text-indigo-300'}">
                <svg class="w-4 h-4 {remainingTime < 30 ? 'text-rose-400' : 'text-indigo-400'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="font-bold text-base tracking-wider">
                    {formatTime(remainingTime)}
                </span>
            </div>
            {/if}
        </div>

        <!-- Progress Bar -->
        <div class="w-full bg-slate-950 h-1.5 shrink-0 overflow-hidden">
            <div class="bg-gradient-to-r from-indigo-500 to-emerald-400 h-1.5 transition-all duration-500 ease-out" style="width: {progressPercentage}%"></div>
        </div>

        <!-- Question Body -->
        <div class="p-5 sm:p-8 flex-grow overflow-y-auto space-y-6" style="background:var(--edu-bg, #080b14);">
            <div class="bg-slate-900/80 p-5 sm:p-7 rounded-2xl border border-slate-800 shadow-sm space-y-4">
                {#if currentQuestion.text}
                    {#each parseContentWithCode(currentQuestion.text) as block}
                        {#if block.type === 'code'}
                            {@const highlighted = highlightCodeSyntax(block.content, block.language)}
                            <!-- Code Box -->
                            <div class="rounded-xl overflow-hidden shadow-lg border border-slate-700/80 bg-[#0d1117] font-mono text-xs sm:text-sm my-3">
                                <div class="bg-[#161b22] border-b border-slate-700/80 px-4 py-2 flex justify-between items-center text-xs text-slate-400 select-none">
                                    <div class="flex items-center gap-2">
                                        <div class="flex gap-1.5">
                                            <div class="w-2.5 h-2.5 rounded-full bg-red-500/80"></div>
                                            <div class="w-2.5 h-2.5 rounded-full bg-yellow-500/80"></div>
                                            <div class="w-2.5 h-2.5 rounded-full bg-green-500/80"></div>
                                        </div>
                                        <span class="ml-2 uppercase font-bold text-indigo-400 tracking-wider text-[10px] bg-indigo-950/60 px-2 py-0.5 rounded border border-indigo-700/40">
                                            {block.language || 'code'}
                                        </span>
                                    </div>
                                </div>
                                <div class="p-4 overflow-x-auto flex text-slate-200 text-xs sm:text-sm leading-relaxed">
                                    <div class="select-none text-slate-600 text-right pr-3 border-r border-slate-800 font-mono flex flex-col">
                                        {#each Array(highlighted.lineCount) as _, idx}
                                            <span>{idx + 1}</span>
                                        {/each}
                                    </div>
                                    <pre class="pl-3 font-mono overflow-x-auto text-slate-200 whitespace-pre"><code>{@html highlighted.html}</code></pre>
                                </div>
                            </div>
                        {:else}
                            <h3 class="text-base sm:text-xl font-bold text-white leading-snug">
                                {@html formatInlineCode(block.content)}
                            </h3>
                        {/if}
                    {/each}
                {/if}
            </div>

            <!-- Options Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                {#each [1, 2, 3, 4] as optNum}
                    {@const optText = currentQuestion[`option${optNum}` as keyof Question]}
                    {@const isSelected = selectedOption === optNum}
                    {#if optText}
                        <button 
                            class="w-full text-left p-4 rounded-xl border-1.5 transition-all duration-200 flex items-center justify-between gap-3 cursor-pointer {isSelected ? 'border-indigo-500 bg-indigo-950/60 shadow-lg shadow-indigo-600/15 ring-2 ring-indigo-500/40 text-indigo-200' : 'border-slate-800 bg-slate-900/70 hover:border-slate-700 hover:bg-slate-800/80 text-slate-200'}"
                            onclick={() => handleOptionSelect(optNum)}
                        >
                            <div class="flex items-center gap-3 pr-2 min-w-0">
                                <span class="w-7 h-7 rounded-lg flex items-center justify-center font-bold text-xs font-mono shrink-0 {isSelected ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700'}">
                                    {String.fromCharCode(64 + optNum)}
                                </span>
                                <span class="text-sm sm:text-base font-medium leading-snug break-words">{@html formatInlineCode(String(optText))}</span>
                            </div>

                            <div class="w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center transition-colors {isSelected ? 'border-indigo-400 bg-indigo-500' : 'border-slate-700'}">
                                {#if isSelected}
                                    <div class="w-2 h-2 rounded-full bg-white"></div>
                                {/if}
                            </div>
                        </button>
                    {/if}
                {/each}
            </div>
        </div>

        <!-- Sticky Bottom Action Bar -->
        {#if !isOnline}
            <div class="bg-amber-950/60 border-t border-amber-800/50 px-4 py-2 flex items-center justify-between text-xs text-amber-300 shrink-0">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                    <span class="font-bold">Offline Mode:</span>
                    <span>Answers saved locally and encrypted.</span>
                </div>
                <span class="font-mono text-[10px] bg-amber-900/60 px-2 py-0.5 rounded font-bold border border-amber-700/50">Local Buffer</span>
            </div>
        {/if}

        <div class="bg-slate-900 border-t border-slate-800 px-5 py-4 sm:px-8 flex flex-wrap items-center justify-between gap-3 shrink-0 shadow-lg z-10">
            <!-- Answer Status Feedback -->
            <div class="flex items-center gap-2">
                {#if selectedOption}
                    <span class="edu-badge bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                        <span class="text-emerald-400 font-bold">✓</span> Choice {String.fromCharCode(64 + selectedOption)} Selected
                    </span>
                {:else}
                    <span class="text-xs text-slate-400 flex items-center gap-1.5">
                        <span class="text-indigo-400">ℹ</span> Tap an option to select your answer
                    </span>
                {/if}
            </div>

            <!-- Action Button: Next or Submit -->
            <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                {#if currentQuestionIndex === questions.length - 1}
                    <button 
                        type="button"
                        onclick={forceSubmit}
                        disabled={isSubmitting}
                        class="edu-btn-primary w-full sm:w-auto px-7 py-2.5 text-sm font-bold bg-emerald-600 hover:bg-emerald-500 shadow-emerald-700/30 border border-emerald-500"
                    >
                        {#if isSubmitting}
                            <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>Submitting Exam...</span>
                        {:else}
                            <span>🚀 Submit Exam</span>
                        {/if}
                    </button>
                {:else}
                    <button 
                        type="button"
                        onclick={nextQuestion}
                        class="edu-btn-primary w-full sm:w-auto px-6 py-2.5 text-sm font-bold shadow-indigo-700/30"
                    >
                        <span>Next Question</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
                    </button>
                {/if}
            </div>
        </div>
    </div>
{/if}
