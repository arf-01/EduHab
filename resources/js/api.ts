// resources/js/api.ts
import axios from 'axios';

// Create a configured axios instance
const apiClient = axios.create({
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    }
});

// Fast real-connectivity verification to eliminate mobile navigator.onLine false positives
export const checkRealOnlineStatus = async (): Promise<boolean> => {
    if (!navigator.onLine) return false;
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 2000);
        const response = await fetch('/api/ping?_t=' + Date.now(), {
            method: 'HEAD',
            cache: 'no-store',
            signal: controller.signal
        });
        clearTimeout(timeoutId);
        return response.ok;
    } catch {
        return false;
    }
};

export interface RoomQuizItem {
    id: number;
    title: string;
    status: 'idle' | 'scheduled' | 'live' | 'ended' | 'submitted';
    question_count: number;
    duration: number; // in seconds
    start_datetime: string | null;
    end_datetime: string | null;
    server_time: string;
    score: number | null;
    total: number | null;
}

export interface RoomQuizzesResponse {
    teacher_name: string;
    room_name: string;
    student_id: string;
    server_time: string;
    quizzes: RoomQuizItem[];
}

export const getRoomQuizzes = async (roomName: string, studentId: string): Promise<RoomQuizzesResponse> => {
    try {
        const response = await apiClient.post('/api/quiz/room-quizzes', {
            room_name: roomName,
            student_id: studentId
        });
        return response.data;
    } catch (error: any) {
        throw new Error(error.response?.data?.error || 'Failed to connect to room.');
    }
};

export const startQuiz = async (quizId: number, studentId: string) => {
    try {
        const response = await apiClient.post('/api/quiz/start', {
            quiz_id: quizId,
            student_id: studentId
        });
        return response.data;
    } catch (error: any) {
        throw new Error(error.response?.data?.error || 'Failed to start quiz.');
    }
};

export const joinQuiz = async (roomName: string, studentId: string) => {
    return getRoomQuizzes(roomName, studentId);
};

export class QuizApiError extends Error {
    statusCode?: number;
    quizEnded: boolean;
    status?: string;
    responseData?: any;

    constructor(message: string, statusCode?: number, quizEnded: boolean = false, status?: string, responseData?: any) {
        super(message);
        this.name = 'QuizApiError';
        this.statusCode = statusCode;
        this.quizEnded = quizEnded;
        this.status = status;
        this.responseData = responseData;
    }
}

export const submitQuiz = async (
    quizId: number,
    studentId: string,
    answers: any[],
    metadata: {
        submissionId?: string;
        sessionId?: string;
        attemptId?: number;
        attemptToken?: string;
    } = {}
) => {
    try {
        const response = await apiClient.post('/api/quiz/submit', {
            quiz_id: quizId,
            student_id: studentId,
            answers: answers,
            submission_id: metadata.submissionId,
            session_id: metadata.sessionId,
            attempt_id: metadata.attemptId,
            attempt_token: metadata.attemptToken
        });
        return response.data;
    } catch (error: any) {
        const data = error.response?.data;
        const statusCode = error.response?.status;
        const isEnded = Boolean(
            data?.quiz_ended || 
            data?.status === 'ended' || 
            (typeof data?.error === 'string' && data.error.toLowerCase().includes('ended'))
        );
        const msg = data?.error || data?.message || error.message || 'Failed to submit quiz.';
        throw new QuizApiError(msg, statusCode, isEnded, data?.status, data);
    }
};
