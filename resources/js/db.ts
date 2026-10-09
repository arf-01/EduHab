import Dexie, { type Table } from 'dexie';

export interface QuizSession {
    sessionId: string;
    tabId: string;
    studentId: string;
    quizId: number;
    attemptId: number;
    attemptToken: string;
    createdAt: string;
    lastSaved: string;
    quizEndTime?: string;
}

export interface Quiz {
    id: string;
    sessionId: string;
    quizId: number;
    title: string;
    duration: number;
    start_datetime: string;
}

export interface Question {
    id: string;
    sessionId: string;
    quizId: number;
    questionId: number;
    text: string;
    image: string | null;
    imageData: string | null;
    option1: string;
    option2: string;
    option3: string;
    option4: string;
    duration: number;
}

export interface Answer {
    id: string;
    sessionId: string;
    quizId: number;
    questionId: number;
    selectedOption: number;
    answeredAt: string;
}

export interface QuizState {
    id?: number;
    sessionId: string;
    studentId: string;
    quizId: number;
    currentQuestionId: number;
    remainingTime: number;
    questionEndTime?: number;
    lastSaved: string;
}

export interface PendingSubmission {
    id?: number;
    submissionId: string;
    sessionId: string;
    tabId: string;
    studentId: string;
    quizId: number;
    attemptId: number;
    attemptToken: string;
    answers: Array<Pick<Answer, 'questionId' | 'selectedOption' | 'answeredAt'>>;
    createdAt: string;
    lastAttemptAt?: string;
    attemptCount: number;
    synced: number;
}

export const makeRecordId = (sessionId: string, recordId: number | string): string =>
    `${sessionId}:${recordId}`;

export const createSessionId = (): string => {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
};

export const getTabId = (): string => {
    if (typeof sessionStorage === 'undefined') return 'server';
    const existing = sessionStorage.getItem('quiz-tab-id');
    if (existing) return existing;
    const tabId = createSessionId();
    sessionStorage.setItem('quiz-tab-id', tabId);
    return tabId;
};

export class QuizDatabase extends Dexie {
    quizSessions!: Table<QuizSession, string>;
    sessionQuizzes!: Table<Quiz, string>;
    sessionQuestions!: Table<Question, string>;
    sessionAnswers!: Table<Answer, string>;
    quizStates!: Table<QuizState, number>;
    pendingSubmissions!: Table<PendingSubmission, number>;

    constructor() {
        super('QuizAppDB');

        this.version(2).stores({
            quizzes: 'id',
            questions: 'id, quizId',
            answers: 'questionId',
            quizState: '++id, quizId',
            pendingSubmissions: '++id, synced'
        });

        this.version(3).stores({
            quizzes: 'id',
            questions: 'id, quizId',
            answers: 'questionId',
            quizState: '++id, quizId',
            pendingSubmissions: '++id, synced, sessionId, tabId, submissionId',
            quizSessions: 'sessionId, studentId, tabId, quizId, lastSaved',
            sessionQuizzes: 'id, sessionId, quizId',
            sessionQuestions: 'id, sessionId, quizId, questionId',
            sessionAnswers: 'id, sessionId, quizId, questionId',
            quizStates: '++id, sessionId, quizId, lastSaved'
        }).upgrade(async (tx) => {
            const oldQuizzes = await tx.table('quizzes').toArray() as Array<{
                id: number; title: string; duration: number; start_datetime: string;
            }>;
            const oldQuestions = await tx.table('questions').toArray() as Array<{
                id: number; quizId: number; text: string; image: string | null; imageData: string | null;
                option1: string; option2: string; option3: string; option4: string; duration: number;
            }>;
            const oldStates = await tx.table('quizState').toArray() as Array<{
                id?: number; studentId: string; quizId: number; currentQuestionId: number;
                remainingTime: number; questionEndTime?: number; lastSaved: string;
            }>;
            const oldAnswers = await tx.table('answers').toArray() as Array<{
                questionId: number; selectedOption: number; answeredAt: string;
            }>;
            const oldPending = await tx.table('pendingSubmissions').toArray() as Array<{
                id?: number; studentId: string; quizId: number; answers: PendingSubmission['answers'];
                createdAt: string; synced: number;
            }>;

            const sessionByQuiz = new Map<number, string>();
            const sessionForQuiz = (quizId: number, studentId = 'legacy') => {
                const existing = sessionByQuiz.get(quizId);
                if (existing) return existing;
                const sessionId = `legacy-${quizId}-${Date.now()}-${Math.random().toString(36).slice(2)}`;
                sessionByQuiz.set(quizId, sessionId);
                return sessionId;
            };

            const sessions = tx.table('quizSessions');
            const sessionQuizzes = tx.table('sessionQuizzes');
            const sessionQuestions = tx.table('sessionQuestions');
            const sessionAnswers = tx.table('sessionAnswers');
            const quizStates = tx.table('quizStates');

            for (const state of oldStates) {
                const sessionId = sessionForQuiz(state.quizId, state.studentId);
                await sessions.put({
                    sessionId,
                    tabId: 'legacy',
                    studentId: state.studentId,
                    quizId: state.quizId,
                    createdAt: state.lastSaved,
                    lastSaved: state.lastSaved
                });
                await quizStates.put({ ...state, sessionId });
            }

            for (const quiz of oldQuizzes) {
                const sessionId = sessionForQuiz(quiz.id);
                await sessions.put({
                    sessionId,
                    tabId: 'legacy',
                    studentId: 'legacy',
                    quizId: quiz.id,
                    createdAt: new Date().toISOString(),
                    lastSaved: new Date().toISOString()
                });
                await sessionQuizzes.put({
                    id: sessionId,
                    sessionId,
                    quizId: quiz.id,
                    title: quiz.title,
                    duration: quiz.duration,
                    start_datetime: quiz.start_datetime
                });
            }

            for (const question of oldQuestions) {
                const sessionId = sessionForQuiz(question.quizId);
                await sessionQuestions.put({
                    ...question,
                    id: makeRecordId(sessionId, question.id),
                    sessionId,
                    questionId: question.id
                });
            }

            for (const answer of oldAnswers) {
                const question = oldQuestions.find((item) => item.id === answer.questionId);
                if (!question) continue;
                const sessionId = sessionForQuiz(question.quizId);
                await sessionAnswers.put({
                    ...answer,
                    id: makeRecordId(sessionId, answer.questionId),
                    sessionId,
                    quizId: question.quizId
                });
            }

            for (const pending of oldPending) {
                const sessionId = sessionForQuiz(pending.quizId, pending.studentId);
                await tx.table('pendingSubmissions').update(pending.id!, {
                    submissionId: `legacy-${pending.id}`,
                    sessionId,
                    tabId: 'legacy',
                    lastAttemptAt: pending.createdAt,
                    attemptCount: 0
                });
            }
        });
    }
}

export const db = new QuizDatabase();

const ABANDONED_SESSION_GRACE_MS = 72 * 60 * 60 * 1000;

export async function clearSessionLocalData(sessionId: string, removePending = false): Promise<void> {
    await db.transaction(
        'rw',
        db.quizSessions,
        db.sessionQuizzes,
        db.sessionQuestions,
        db.sessionAnswers,
        db.quizStates,
        db.pendingSubmissions,
        async () => {
            await db.quizStates.where('sessionId').equals(sessionId).delete();
            await db.sessionAnswers.where('sessionId').equals(sessionId).delete();
            await db.sessionQuestions.where('sessionId').equals(sessionId).delete();
            await db.sessionQuizzes.where('sessionId').equals(sessionId).delete();
            if (removePending) {
                await db.pendingSubmissions.where('sessionId').equals(sessionId).delete();
            }
            await db.quizSessions.delete(sessionId);
        }
    );
}

export async function cleanupAbandonedSessions(now = Date.now()): Promise<number> {
    const sessions = await db.quizSessions.toArray();
    const pending = await db.pendingSubmissions.where('synced').equals(0).toArray();
    const pendingSessionIds = new Set(pending.map((submission) => submission.sessionId));
    let removed = 0;

    for (const session of sessions) {
        if (pendingSessionIds.has(session.sessionId)) continue;
        const lastActivity = new Date(session.lastSaved).getTime();
        if (!Number.isFinite(lastActivity) || now - lastActivity < ABANDONED_SESSION_GRACE_MS) continue;
        await clearSessionLocalData(session.sessionId);
        removed += 1;
    }

    return removed;
}
