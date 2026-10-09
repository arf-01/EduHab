<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import { getRoomQuizzes, type RoomQuizItem } from './api';
    import { db } from './db';

    let { 
        roomName, 
        studentId, 
        isOnline, 
        onStartQuiz, 
        onLeaveRoom 
    } = $props<{
        roomName: string;
        studentId: string;
        isOnline: boolean;
        onStartQuiz: (quizId: number) => Promise<void>;
        onLeaveRoom: () => void;
    }>();

    let teacherName = $state('');
    let quizzes = $state<RoomQuizItem[]>([]);
    let loading = $state(true);
    let startingQuizId = $state<number | null>(null);
    let error = $state('');
    let nowTimestamp = $state(Date.now());

    let clockInterval: number;

    onMount(async () => {
        await fetchRoomData(true);

        // Update local wall clock every second for smooth countdowns
        clockInterval = window.setInterval(() => {
            nowTimestamp = Date.now();
        }, 1000);
    });

    onDestroy(() => {
        clearInterval(clockInterval);
    });

    async function fetchRoomData(isInitial = false) {
        if (isInitial) loading = true;
        error = '';

        try {
            const data = await getRoomQuizzes(roomName, studentId);
            teacherName = data.teacher_name;

            const incoming = JSON.stringify(data.quizzes.map(q => ({ id: q.id, status: q.status, score: q.score })));
            const current  = JSON.stringify(quizzes.map(q => ({ id: q.id, status: q.status, score: q.score })));
            if (incoming !== current) {
                quizzes = data.quizzes;
            }

            const staleQuizIds = data.quizzes
                .filter(q => q.status === 'ended' || q.status === 'submitted')
                .map(q => q.id);

            if (staleQuizIds.length > 0) {
                const unsyncedPending = await db.pendingSubmissions
                    .where('synced').equals(0)
                    .toArray();
                const unsyncedQuizIds = new Set(unsyncedPending.map(p => p.quizId));

                const safeToClean = staleQuizIds.filter(id => !unsyncedQuizIds.has(id));

                if (safeToClean.length > 0) {
                    const staleSessions = await db.quizSessions
                        .where('quizId')
                        .anyOf(safeToClean)
                        .toArray();
                    
                    for (const session of staleSessions) {
                        const hasPending = unsyncedPending.some((submission) => submission.sessionId === session.sessionId);
                        if (!hasPending) {
                            await db.quizStates.where('sessionId').equals(session.sessionId).delete();
                            await db.sessionAnswers.where('sessionId').equals(session.sessionId).delete();
                            await db.sessionQuestions.where('sessionId').equals(session.sessionId).delete();
                            await db.sessionQuizzes.delete(session.sessionId);
                            await db.quizSessions.delete(session.sessionId);
                        }
                    }
                }
            }
        } catch (err: any) {
            if (isInitial) {
                error = err.message || 'Failed to load room details.';
            }
        } finally {
            loading = false;
        }
    }

    async function handleStart(quizId: number) {
        startingQuizId = quizId;
        error = '';

        try {
            if (!isOnline && !navigator.onLine) {
                const count = await db.sessionQuestions.where('quizId').equals(quizId).count();
                if (count === 0) {
                    error = 'You must be online when starting a quiz for the first time.';
                    startingQuizId = null;
                    return;
                }
            }

            await onStartQuiz(quizId);
        } catch (err: any) {
            error = err.message || 'Could not start quiz. Please try again.';
            startingQuizId = null;
        }
    }

    function getRemainingSeconds(startDatetimeStr: string | null): number {
        if (!startDatetimeStr) return 0;
        const target = new Date(startDatetimeStr).getTime();
        return Math.max(0, Math.floor((target - nowTimestamp) / 1000));
    }

    function formatDuration(seconds: number): string {
        if (seconds <= 0) return '00:00:00';
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;

        const pad = (n: number) => n.toString().padStart(2, '0');

        if (d > 0) {
            return `${d}d ${pad(h)}:${pad(m)}:${pad(s)}`;
        }
        return `${pad(h)}:${pad(m)}:${pad(s)}`;
    }
</script>

<div class="w-full max-w-4xl space-y-6 edu-animate-slide-up">
    <!-- Lobby Top Card -->
    <div class="edu-card p-6 sm:p-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div class="space-y-2">
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--edu-text2);">
                <span>Room</span>
                <span class="text-slate-600">/</span>
                <span class="text-indigo-400">{roomName}</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                {teacherName ? `${teacherName}'s Classroom` : `Room: ${roomName}`}
            </h1>
            <div class="flex items-center gap-2 text-xs font-mono text-slate-400">
                <span class="px-2.5 py-1 rounded-lg border font-bold" style="background:var(--edu-card2); border-color:var(--edu-border2); color:var(--edu-text);">
                    👤 {studentId}
                </span>
                <span>·</span>
                <span>{quizzes.length} {quizzes.length === 1 ? 'Quiz Available' : 'Quizzes Available'}</span>
            </div>
        </div>

        <div class="flex items-center gap-3 w-full md:w-auto">
            <button 
                type="button" 
                onclick={onLeaveRoom} 
                class="edu-btn-ghost text-xs py-2 px-3 flex-1 md:flex-initial text-rose-400 border-rose-500/30 hover:border-rose-500/60"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span>Switch Room</span>
            </button>
        </div>
    </div>

    {#if error}
        <div class="mb-4 px-4 py-3 rounded-xl border border-rose-500/30 bg-rose-500/10 text-rose-300 text-xs sm:text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{error} — Please refresh the page to try again.</span>
        </div>
    {/if}

    <!-- Quizzes Grid -->
    {#if loading}
        <div class="edu-card p-12 text-center space-y-4">
            <div class="w-10 h-10 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
            <p class="text-sm font-semibold" style="color:var(--edu-text2);">Connecting to room...</p>
        </div>
    {:else if quizzes.length === 0}
        <div class="edu-card p-12 text-center space-y-3">
            <div class="w-14 h-14 rounded-2xl bg-slate-800/80 border border-slate-700 flex items-center justify-center text-2xl mx-auto">
                📚
            </div>
            <h3 class="text-lg font-bold text-white">No Quizzes Found</h3>
            <p class="text-sm max-w-md mx-auto" style="color:var(--edu-text2);">
                Your teacher has not uploaded any quizzes to room <strong class="text-indigo-300">{roomName}</strong> yet. Stay on this screen; new quizzes will appear automatically.
            </p>
        </div>
    {:else}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            {#each quizzes as quiz, i (quiz.id)}
                {@const secondsUntilStart = getRemainingSeconds(quiz.start_datetime)}
                {@const isEffectivelyLive = quiz.status === 'live' || (quiz.status === 'scheduled' && secondsUntilStart <= 0)}
                
                <div class="edu-card edu-card-hover p-6 flex flex-col justify-between gap-5 relative overflow-hidden {isEffectivelyLive ? 'border-emerald-500/50 shadow-emerald-500/10' : ''}">
                    
                    {#if isEffectivelyLive}
                        <div class="absolute inset-0 bg-gradient-to-br from-emerald-500/5 to-transparent pointer-events-none"></div>
                    {/if}

                    <div class="space-y-3 relative z-10">
                        <!-- Status Badge & Meta -->
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-mono font-semibold text-slate-400">
                                📋 {quiz.question_count} {quiz.question_count === 1 ? 'Question' : 'Questions'}
                            </span>

                            {#if quiz.status === 'submitted'}
                                <span class="edu-badge bg-purple-500/15 text-purple-300 border border-purple-500/30">
                                    <span>✓</span> Completed
                                </span>
                            {:else if isEffectivelyLive}
                                <span class="edu-badge bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 edu-animate-live">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> LIVE NOW
                                </span>
                            {:else if quiz.status === 'scheduled'}
                                <span class="edu-badge bg-amber-500/15 text-amber-300 border border-amber-500/30">
                                    <span>⏱️</span> Scheduled
                                </span>
                            {:else if quiz.status === 'ended'}
                                <span class="edu-badge bg-slate-800 text-slate-400 border border-slate-700">
                                    🏁 Ended
                                </span>
                            {:else}
                                <span class="edu-badge bg-slate-800 text-slate-400 border border-slate-700">
                                    ⏸️ Idle
                                </span>
                            {/if}
                        </div>

                        <!-- Quiz Title -->
                        <h2 class="text-lg font-bold text-white leading-snug">
                            {quiz.title}
                        </h2>

                        <!-- Duration & Time details -->
                        <div class="text-xs text-slate-400 space-y-1">
                            <p class="flex items-center gap-1.5">
                                <span>⏳ Duration:</span>
                                <strong class="text-slate-200 font-mono">{Math.round(quiz.duration / 60)} mins</strong>
                            </p>

                            {#if quiz.status === 'scheduled' && !isEffectivelyLive}
                                <div class="bg-amber-950/40 border border-amber-800/50 rounded-xl p-3 text-amber-300 mt-2 space-y-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-amber-400">Starts in</p>
                                    <p class="text-2xl font-black font-mono text-amber-300">
                                        {formatDuration(secondsUntilStart)}
                                    </p>
                                </div>
                            {/if}

                            {#if quiz.status === 'submitted' && quiz.score !== null}
                                <div class="bg-purple-950/40 border border-purple-800/50 rounded-xl p-3 text-purple-300 mt-2 flex items-center justify-between">
                                    <span class="text-xs font-semibold">Your Score:</span>
                                    <span class="text-xl font-extrabold font-mono text-purple-300">{quiz.score} / {quiz.total}</span>
                                </div>
                            {/if}
                        </div>
                    </div>

                    <!-- Action Button -->
                    <div class="pt-3 border-t border-slate-800/80 relative z-10 flex flex-col gap-2">
                        {#if isEffectivelyLive}
                            <button
                                type="button"
                                onclick={() => handleStart(quiz.id)}
                                disabled={startingQuizId === quiz.id}
                                class="edu-btn-primary w-full text-sm py-2.5 font-bold shadow-lg shadow-indigo-600/30"
                            >
                                {#if startingQuizId === quiz.id}
                                    <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                                    <span>Preparing Exam...</span>
                                {:else}
                                    <span>🚀 Start Exam Now</span>
                                {/if}
                            </button>
                        {:else if quiz.status === 'submitted'}
                            <div class="flex gap-2">
                                <a 
                                    href={`/quiz/${quiz.id}/analysis/${studentId}`}
                                    class="edu-btn-primary flex-1 text-xs py-2.5 justify-center shadow-lg shadow-indigo-600/30"
                                >
                                    📊 View Detailed Analysis
                                </a>
                            </div>
                        {:else if quiz.status === 'scheduled'}
                            <div class="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-center border" style="background:var(--edu-card2); border-color:var(--edu-border2); color:var(--edu-text2);">
                                🔒 Locked until scheduled time
                            </div>
                        {:else if quiz.status === 'ended'}
                            <div class="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-center text-slate-500 bg-slate-900/60 border border-slate-800">
                                🏁 Quiz Closed
                            </div>
                        {:else}
                            <div class="w-full py-2.5 px-4 rounded-xl text-xs font-semibold text-center text-slate-500 bg-slate-900/60 border border-slate-800">
                                ⏸ Waiting for teacher to start
                            </div>
                        {/if}
                    </div>
                </div>
            {/each}
        </div>
    {/if}
</div>
