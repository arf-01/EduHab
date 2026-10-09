<script lang="ts">
    import { onMount, onDestroy } from 'svelte';
    import Login from './Login.svelte';
    import RoomLobby from './RoomLobby.svelte';
    import Quiz from './Quiz.svelte';
    import { cleanupAbandonedSessions, clearSessionLocalData, createSessionId, db, getTabId } from './db';
    import { submitQuiz, startQuiz, checkRealOnlineStatus } from './api';

    // Show the room form immediately; recovery and connectivity checks run in the background.
    let loading = $state(false);
    let initError = $state('');
    let isOnline = $state(navigator.onLine);
    
    // State management
    let currentRoomName = $state<string | null>(null);
    let studentId = $state<string | null>(null);
    let activeQuizId = $state<number | null>(null);
    let activeSessionId = $state<string | null>(null);
    let activeAttemptId = $state<number | null>(null);
    let activeAttemptToken = $state<string | null>(null);
    let quizScore = $state<number | null>(null);
    let quizTotal = $state<number | null>(null);
    let hasPendingSubmission = $state(false);
    let quizEndedNotice = $state<{ message: string } | null>(null);

    let networkCheckInterval: number;
    const tabId = getTabId();

    function shuffleArray<T>(array: T[]): T[] {
        const arr = [...array];
        for (let i = arr.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    async function clearQuizLocalData(sessionId?: string | null, removePending = true) {
        try {
            if (sessionId) {
                await clearSessionLocalData(sessionId, removePending);
            } else {
                const sessions = await db.quizSessions.toArray();
                for (const session of sessions) {
                    await clearSessionLocalData(session.sessionId, removePending);
                }
            }
        } catch (e) {
            console.warn('Failed to clear quiz data from IndexedDB:', e);
        }
    }

    onMount(async () => {
        try {
            // Setup network listeners
            window.addEventListener('online', handleOnline);
            window.addEventListener('offline', handleOffline);
            await cleanupAbandonedSessions();

            // Run initial real connectivity check
            isOnline = await checkRealOnlineStatus();

            // Periodic reachability heartbeat (every 10 seconds)
            networkCheckInterval = window.setInterval(async () => {
                const reallyOnline = await checkRealOnlineStatus();
                if (reallyOnline !== isOnline) {
                    isOnline = reallyOnline;
                    if (isOnline) {
                        await syncPendingSubmissions();
                    }
                }
            }, 10000);

            // Check if there is an unsynced submission
            const pending = (await db.pendingSubmissions.where('synced').equals(0).toArray())
                .filter((submission) => submission.tabId === tabId || submission.tabId === 'legacy');
            if (pending.length > 0) {
                hasPendingSubmission = true;
                activeQuizId = pending[0].quizId;
                activeSessionId = pending[0].sessionId;
                activeAttemptId = pending[0].attemptId;
                activeAttemptToken = pending[0].attemptToken;
                studentId = pending[0].studentId;
            } else {
                // Check for active crash recovery session
                const savedState = [];
                for (const state of await db.quizStates.toArray()) {
                    const session = await db.quizSessions.get(state.sessionId);
                    if (session && (session.tabId === tabId || session.tabId === 'legacy')) {
                        savedState.push(state);
                    }
                }
                if (savedState.length > 0) {
                    const state = savedState[0];
                    const qCount = await db.sessionQuestions.where('sessionId').equals(state.sessionId).count();
                    if (qCount > 0) {
                        activeQuizId = state.quizId;
                        activeSessionId = state.sessionId;
                        activeAttemptId = session.attemptId;
                        activeAttemptToken = session.attemptToken;
                        studentId = state.studentId;
                    } else {
                        await clearSessionLocalData(state.sessionId);
                    }
                }
            }

            // Initial sync attempt if online
            if (isOnline) {
                await syncPendingSubmissions();
            }
        } catch (err: any) {
            console.error("Initialization error:", err);
            initError = err.message || "Failed to initialize database.";
        } finally {
            loading = false;
        }
    });

    onDestroy(() => {
        clearInterval(networkCheckInterval);
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    });

    async function handleOnline() {
        const reallyOnline = await checkRealOnlineStatus();
        isOnline = reallyOnline;
        if (reallyOnline) {
            await syncPendingSubmissions();
        }
    }

    function handleOffline() {
        isOnline = false;
    }

    async function syncPendingSubmissions() {
        const pending = await db.pendingSubmissions.where('synced').equals(0).toArray();
        for (const submission of pending) {
            try {
                const response = await submitQuiz(
                    submission.quizId,
                    submission.studentId,
                    submission.answers,
                    {
                        submissionId: submission.submissionId,
                        sessionId: submission.sessionId,
                        attemptId: submission.attemptId,
                        attemptToken: submission.attemptToken
                    }
                );
                
                await db.pendingSubmissions.delete(submission.id!);
                await clearQuizLocalData(submission.sessionId, false);
                
                isOnline = true;
                
                if (submission.sessionId === activeSessionId) {
                    quizScore = response.score;
                    quizTotal = response.total;
                    hasPendingSubmission = false;
                    quizEndedNotice = null;
                }
            } catch (error: any) {
                const isEnded = Boolean(
                    error?.quizEnded || 
                    error?.response?.data?.quiz_ended || 
                    error?.status === 'ended' || 
                    (typeof error?.message === 'string' && error.message.toLowerCase().includes('ended'))
                );

                if (isEnded) {
                    // Quiz has ended on server - mark as synced, clean local DB completely, and notify student
                    await db.pendingSubmissions.delete(submission.id!);
                    await clearQuizLocalData(submission.sessionId, false);

                    if (submission.sessionId === activeSessionId) {
                        hasPendingSubmission = false;
                        activeQuizId = null;
                        activeSessionId = null;
                        activeAttemptId = null;
                        activeAttemptToken = null;
                        quizEndedNotice = {
                            message: error.message || 'This quiz has already ended. Submissions are no longer being accepted.'
                        };
                    }
                } else {
                    await db.pendingSubmissions.update(submission.id!, {
                        lastAttemptAt: new Date().toISOString(),
                        attemptCount: submission.attemptCount + 1
                    });
                    isOnline = false;
                    console.warn('Sync waiting for connection.');
                }
            }
        }
    }

    function handleRoomJoin(roomName: string, sId: string) {
        currentRoomName = roomName;
        studentId = sId;
        activeQuizId = null;
        activeSessionId = null;
        quizScore = null;
        quizTotal = null;
        hasPendingSubmission = false;
        quizEndedNotice = null;
    }

    async function handleStartQuiz(quizId: number) {
        if (!studentId) return;

        const data = await startQuiz(quizId, studentId);
        const sessionId = createSessionId();
        const shuffledQuestions = shuffleArray(data.questions || []);

        const questionsToInsert = shuffledQuestions.map((q: any) => ({
            id: `${sessionId}:${q.id}`,
            sessionId,
            quizId: data.quiz.id,
            questionId: q.id,
            text: q.text,
            image: null,
            imageData: null,
            option1: q.option1,
            option2: q.option2,
            option3: q.option3,
            option4: q.option4,
            duration: Number(q.duration) || 60
        }));

        await db.transaction('rw', db.quizSessions, db.sessionQuizzes, db.sessionQuestions, async () => {
            const now = new Date().toISOString();
            await db.quizSessions.put({
                sessionId,
                tabId,
                studentId,
                quizId: data.quiz.id,
                attemptId: data.attempt_id,
                attemptToken: data.attempt_token,
                createdAt: now,
                lastSaved: now
            });
            await db.sessionQuizzes.put({
                id: sessionId,
                sessionId,
                quizId: data.quiz.id,
                title: data.quiz.title,
                duration: data.quiz.duration,
                start_datetime: data.quiz.start_datetime
            });

            await db.sessionQuestions.where('sessionId').equals(sessionId).delete();
            await db.sessionQuestions.bulkAdd(questionsToInsert);
        });

        activeQuizId = quizId;
        activeSessionId = sessionId;
        activeAttemptId = data.attempt_id;
        activeAttemptToken = data.attempt_token;
        quizScore = null;
        quizTotal = null;
        hasPendingSubmission = false;
        quizEndedNotice = null;
    }

    async function handleLeaveRoom() {
        await clearQuizLocalData(activeSessionId);
        currentRoomName = null;
        studentId = null;
        activeQuizId = null;
        activeSessionId = null;
        activeAttemptId = null;
        activeAttemptToken = null;
        quizScore = null;
        quizTotal = null;
        hasPendingSubmission = false;
        quizEndedNotice = null;
    }

    function handleOfflineSubmit() {
        hasPendingSubmission = true;
    }

    function handleComplete(score: number, total: number) {
        quizScore = score;
        quizTotal = total;
        quizEndedNotice = null;
    }

    function handleQuizEnded(message: string) {
        hasPendingSubmission = false;
        activeQuizId = null;
        activeSessionId = null;
        activeAttemptId = null;
        activeAttemptToken = null;
        quizEndedNotice = { message };
    }

    async function resetApp() {
        await clearQuizLocalData();
        currentRoomName = null;
        activeQuizId = null;
        activeSessionId = null;
        studentId = null;
        quizScore = null;
        quizTotal = null;
        hasPendingSubmission = false;
        quizEndedNotice = null;
    }
</script>

<main class="min-h-screen flex flex-col font-sans relative overflow-hidden" style="background:var(--edu-bg, #080b14); color:var(--edu-text, #e2e8f0);">

    <!-- Background glowing blobs -->
    <div class="edu-blob edu-animate-blob w-[500px] h-[500px] -top-40 -left-40 bg-indigo-700 pointer-events-none" style="opacity:.15;"></div>
    <div class="edu-blob edu-animate-blob w-[400px] h-[400px] -bottom-32 -right-32 bg-violet-600 pointer-events-none" style="opacity:.12; animation-delay:-3s;"></div>

    <!-- EduHub Navigation Header -->
    <header class="edu-nav sticky top-0 z-50 shrink-0">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-3.5 flex items-center justify-between gap-4">
            
            <!-- Logo / Home Link -->
            <button 
                type="button" 
                onclick={resetApp} 
                class="flex items-center gap-2.5 group cursor-pointer"
            >
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/30 group-hover:scale-105 transition-transform">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <span class="text-lg font-extrabold tracking-tight" style="background: linear-gradient(135deg, #a5b4fc 0%, #818cf8 50%, #c4b5fd 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                    EduHub
                </span>
                <span class="text-xs px-2 py-0.5 rounded-full font-mono font-bold bg-indigo-950/80 text-indigo-300 border border-indigo-700/40 hidden sm:inline-block">
                    Student Portal
                </span>
            </button>

            <!-- Navigation Actions & Connectivity Badge -->
            <div class="flex items-center gap-3">
                <a href="/" class="text-xs font-semibold text-slate-400 hover:text-slate-200 transition hidden sm:inline-flex items-center gap-1">
                    <span>🏠</span> Home
                </a>

                <span class="w-px h-4 bg-slate-800 hidden sm:block"></span>

                {#if isOnline}
                    <span class="edu-badge bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block edu-animate-live"></span>
                        Online
                    </span>
                {:else}
                    <span class="edu-badge bg-amber-500/15 text-amber-300 border border-amber-500/30">
                        <svg class="w-3 h-3 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        Offline (Local Cache)
                    </span>
                {/if}
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <div class="flex-grow p-4 md:p-8 flex items-center justify-center relative z-10">
        {#if loading}
            <div class="edu-card p-10 flex flex-col items-center max-w-sm text-center">
                <div class="h-10 w-10 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin mb-4"></div>
                <p class="text-white font-bold">Initializing Portal...</p>
                <p class="text-xs mt-1 text-slate-400">Loading offline exam engine</p>
            </div>
        {:else if initError}
            <div class="edu-card p-8 sm:p-10 w-full max-w-md text-center shadow-2xl space-y-4">
                <div class="w-16 h-16 bg-rose-500/15 text-rose-400 rounded-2xl border border-rose-500/30 flex items-center justify-center mx-auto text-2xl">
                    ⚠️
                </div>
                <h2 class="text-xl font-bold text-white">Initialization Failed</h2>
                <p class="text-rose-400 text-xs">{initError}</p>
                <button type="button" onclick={() => window.location.reload()} class="edu-btn-primary w-full text-sm">Retry</button>
            </div>
        {:else if quizEndedNotice}
            <!-- Quiz Ended Rejection Card -->
            <div class="edu-card p-8 sm:p-10 w-full max-w-md text-center shadow-2xl space-y-6 edu-animate-scale-in border-rose-500/30">
                <div class="w-16 h-16 bg-rose-500/15 text-rose-400 rounded-2xl border border-rose-500/30 flex items-center justify-center mx-auto text-3xl">
                    🏁
                </div>
                <div class="space-y-2">
                    <span class="edu-badge bg-rose-500/15 text-rose-300 border border-rose-500/30 text-xs">
                        Submissions Closed
                    </span>
                    <h2 class="text-2xl font-extrabold text-white">Quiz Finished</h2>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        {quizEndedNotice.message || 'This quiz has already ended. Submissions are no longer being accepted by the teacher.'}
                    </p>
                </div>
                
                <div class="pt-2">
                    <button 
                        type="button" 
                        onclick={async () => {
                            await clearQuizLocalData();
                            quizEndedNotice = null;
                            activeQuizId = null;
                            hasPendingSubmission = false;
                        }} 
                        class="edu-btn-primary w-full text-sm py-2.5 justify-center shadow-lg shadow-indigo-600/30"
                    >
                        ← Back to Room Lobby
                    </button>
                </div>
            </div>
        {:else if hasPendingSubmission}
            <!-- Pending Sync View -->
            <div class="edu-card p-8 sm:p-10 w-full max-w-md text-center shadow-2xl space-y-5 edu-animate-scale-in">
                <div class="w-16 h-16 bg-amber-500/15 text-amber-400 rounded-2xl border border-amber-500/30 flex items-center justify-center mx-auto text-2xl edu-animate-float">
                    💾
                </div>
                <div class="space-y-1.5">
                    <h2 class="text-2xl font-extrabold text-white">Exam Saved Offline</h2>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        Your answers are encrypted and saved locally. They will automatically upload and grade as soon as network is detected.
                    </p>
                </div>
                
                {#if isOnline}
                    <div class="bg-indigo-950/60 border border-indigo-700/50 text-indigo-200 rounded-xl p-4 text-xs font-semibold flex flex-col items-center justify-center gap-3">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></div>
                            Syncing answers with server...
                        </div>
                        <button type="button" onclick={syncPendingSubmissions} class="edu-btn-primary text-xs py-1.5 px-4 shadow-none">Force Sync Now</button>
                    </div>
                {:else}
                    <div class="bg-amber-950/40 border border-amber-800/50 text-amber-300 rounded-xl p-3.5 text-xs font-semibold flex items-center justify-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></div>
                        Waiting for active connection...
                    </div>
                {/if}
            </div>
        {:else if quizScore !== null}
            <!-- Results View -->
            <div class="edu-card p-8 sm:p-12 w-full max-w-lg text-center shadow-2xl shadow-black/60 edu-animate-scale-in space-y-6">
                <!-- Celebration Icon -->
                <div class="w-20 h-20 mx-auto rounded-3xl bg-gradient-to-br from-amber-400/20 to-yellow-500/20 border border-amber-400/30 flex items-center justify-center text-4xl edu-animate-float shadow-lg shadow-amber-500/15">
                    🏆
                </div>

                <div class="space-y-1">
                    <h2 class="text-3xl font-extrabold text-white">Exam Complete!</h2>
                    <p class="text-sm" style="color:var(--edu-text2);">Your submission has been evaluated successfully.</p>
                </div>
                
                <!-- Score Callout Card -->
                <div class="inline-flex flex-col items-center px-10 py-5 rounded-2xl border w-full" style="background:var(--edu-card2); border-color:var(--edu-border2);">
                    <p class="text-xs font-bold uppercase tracking-widest mb-1 text-slate-400">Total Score</p>
                    <div class="text-5xl font-black text-white font-mono">
                        {quizScore} <span class="text-2xl text-slate-500 font-normal">/ {quizTotal}</span>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="space-y-3 pt-2">
                    <!-- Detailed Analysis Button (when online or studentId present) -->
                    {#if studentId}
                        <a 
                            href={activeQuizId ? `/quiz/${activeQuizId}/analysis/${studentId}` : `/student/results/${studentId}`}
                            class="edu-btn-primary w-full py-3 text-sm font-bold shadow-lg shadow-indigo-600/30 justify-center"
                        >
                            📊 View Detailed Quiz Analysis
                        </a>
                    {/if}

                    {#if currentRoomName && studentId}
                        <button 
                            type="button"
                            onclick={async () => { await clearQuizLocalData(); quizScore = null; quizTotal = null; activeQuizId = null; }}
                            class="edu-btn-ghost w-full py-2.5 text-xs font-semibold justify-center"
                        >
                            ← Back to Room Lobby
                        </button>
                    {:else}
                        <button 
                            type="button"
                            onclick={resetApp}
                            class="edu-btn-ghost w-full py-2.5 text-xs font-semibold justify-center"
                        >
                            Return to Student Home
                        </button>
                    {/if}
                </div>
            </div>
        {:else if activeQuizId && activeSessionId && studentId}
            <!-- Quiz Taking View -->
            <Quiz 
                sessionId={activeSessionId!}
                tabId={tabId}
                attemptId={activeAttemptId!}
                attemptToken={activeAttemptToken!}
                quizId={activeQuizId} 
                studentId={studentId} 
                isOnline={isOnline} 
                onComplete={handleComplete} 
                onOfflineSubmit={handleOfflineSubmit}
                onQuizEnded={handleQuizEnded}
            />
        {:else if currentRoomName && studentId}
            <!-- Room Lobby View -->
            <RoomLobby 
                roomName={currentRoomName} 
                studentId={studentId} 
                isOnline={isOnline} 
                onStartQuiz={handleStartQuiz} 
                onLeaveRoom={handleLeaveRoom} 
            />
        {:else}
            <!-- Login View -->
            <Login onJoin={handleRoomJoin} />
        {/if}
    </div>
</main>
