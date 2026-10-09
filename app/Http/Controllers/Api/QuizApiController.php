<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\Result;
use App\Models\ResultDetail;
use App\Models\QuizAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class QuizApiController extends Controller
{
    /**
     * Get all quizzes for a room with real-time status (live, scheduled, idle, ended, submitted)
     */
    public function roomQuizzes(Request $request)
    {
        $request->validate([
            'room_name' => 'required|string',
            'student_id' => 'required|string',
        ]);

        $roomName = $request->room_name;
        $studentId = $request->student_id;

        $teacher = User::where('room_name', $roomName)->first();

        if (! $teacher) {
            return response()->json(['error' => 'Room not found. Please verify the room name.'], 404);
        }

        $now = Carbon::now();

        $quizzes = Quiz::where('userid', $teacher->id)
            ->select('id', 'title', 'start_datetime', 'duration', 'created_at', 'userid')
            ->withCount('questions')
            ->where(function ($q) use ($now) {
                // Include: idle (no start), scheduled/live/recently-ended (within last 24 h)
                $q->whereNull('start_datetime')
                    ->orWhere('start_datetime', '>=', $now->copy()->subDay());
            })
            ->orderBy('created_at', 'desc')
            ->limit(20) // safety cap — prevents unbounded growth under load
            ->get();

        $studentResults = Result::whereIn('quiz_id', $quizzes->pluck('id'))
            ->where('student_id', $studentId)
            ->get()
            ->keyBy('quiz_id');

        $quizList = $quizzes->map(function ($quiz) use ($now, $studentResults) {
            $hasSubmitted = isset($studentResults[$quiz->id]);
            $studentResult = $hasSubmitted ? $studentResults[$quiz->id] : null;

            $startTime = $quiz->start_datetime ? Carbon::parse($quiz->start_datetime) : null;
            // duration stores total quiz duration in SECONDS (sum of per-question durations).
            $durationSeconds = max(60, (int) ($quiz->duration ?: ($quiz->questions_count * 60)));
            $endTime = $startTime ? $startTime->copy()->addSeconds($durationSeconds) : null;

            // Determine accurate real-time status.
            // A quiz with 0 questions is always idle — it can't be started.
            if ($quiz->questions_count === 0) {
                $status = 'idle';
            } elseif ($hasSubmitted) {
                $status = 'submitted';
            } elseif ($startTime) {
                if ($now->lt($startTime)) {
                    $status = 'scheduled';
                } elseif ($now->between($startTime, $endTime)) {
                    $status = 'live';
                } else {
                    $status = 'ended';
                }
            } else {
                $status = 'idle';
            }

            // server_time is returned once at the root level — omitted here to keep payload lean.
            return [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'status' => $status,
                'question_count' => $quiz->questions_count,
                'duration' => $durationSeconds,
                'start_datetime' => $startTime ? $startTime->toIso8601String() : null,
                'end_datetime' => $endTime ? $endTime->toIso8601String() : null,
                'score' => $studentResult ? $studentResult->score : null,
                'total' => $quiz->questions_count,
            ];
        });

        return response()->json([
            'teacher_name' => $teacher->name,
            'room_name' => $teacher->room_name,
            'student_id' => $studentId,
            'server_time' => $now->toIso8601String(),
            'quizzes' => $quizList,
        ]);
    }

    /**
     * Start an active/live quiz and fetch its question bank for local IndexedDB caching
     */
    public function start(Request $request)
    {
        $request->validate([
            'quiz_id' => 'required|integer|exists:quizzes,id',
            'student_id' => 'required|string',
        ]);

        $quizId = $request->quiz_id;
        $studentId = $request->student_id;

        // Check if student already submitted
        $existingResult = Result::where('quiz_id', $quizId)
            ->where('student_id', $studentId)
            ->first();

        if ($existingResult) {
            $quiz = Quiz::withCount('questions')->findOrFail($quizId);

            return response()->json([
                'error' => 'You have already submitted this quiz.',
                'score' => $existingResult->score,
                'total' => $quiz->questions_count,
                'status' => 'submitted',
            ], 400);
        }

        $quiz = Quiz::with(['questions' => function ($query) {
            $query->select(
                'id',
                'quiz_id',
                'text',
                'image',
                'option1',
                'option2',
                'option3',
                'option4',
                'duration'
            );
        }])->findOrFail($quizId);

        $now = Carbon::now();
        $startTime = $quiz->start_datetime ? Carbon::parse($quiz->start_datetime) : null;
        $durationSeconds = max(60, (int) ($quiz->duration ?: ($quiz->questions->count() * 60)));
        $endTime = $startTime ? $startTime->copy()->addSeconds($durationSeconds) : null;

        // Verify if quiz is started and not ended
        if (! $startTime) {
            return response()->json(['error' => 'This quiz has not been started by the teacher yet.'], 403);
        }

        if ($now->lt($startTime)) {
            return response()->json([
                'error' => 'This quiz is scheduled and has not started yet.',
                'start_datetime' => $startTime->toIso8601String(),
                'server_time' => $now->toIso8601String(),
            ], 403);
        }

        // Allow a 1-minute buffer for clock jitter
        if ($endTime && $now->gt($endTime->copy()->addMinutes(1))) {
            return response()->json(['error' => 'This quiz has already ended.'], 403);
        }

        $attempt = DB::transaction(function () use ($studentId, $quizId, $now, $durationSeconds) {
            $activeKey = "{$studentId}:{$quizId}";
            $activeAttempt = QuizAttempt::where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if ($activeAttempt && $activeAttempt->expires_at->isFuture()) {
                return null;
            }

            if ($activeAttempt) {
                $activeAttempt->update([
                    'status' => 'expired',
                    'active_key' => null,
                ]);
            }

            return QuizAttempt::create([
                'student_id' => $studentId,
                'quiz_id' => $quizId,
                'status' => 'active',
                'active_key' => $activeKey,
                'attempt_token' => Str::random(64),
                'started_at' => $now,
                'expires_at' => $now->copy()->addSeconds($durationSeconds),
                'last_seen_at' => $now,
            ]);
        });

        if (! $attempt) {
            return response()->json([
                'error' => 'This quiz is already active in another tab or device.',
                'status' => 'already_active',
            ], 409);
        }

        return response()->json([
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'started_at' => $attempt->started_at->toIso8601String(),
            'expires_at' => $attempt->expires_at->toIso8601String(),
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'duration' => $durationSeconds,
                'start_datetime' => $startTime->toIso8601String(),
            ],
            'questions' => $quiz->questions,
        ]);
    }

    /**
     * Legacy join route kept for backward compatibility
     */
    public function join(Request $request)
    {
        return $this->roomQuizzes($request);
    }

    /**
     * Submit quiz answers
     */
    public function submit(Request $request)
    {
        $request->validate([
            'quiz_id' => 'required|integer|exists:quizzes,id',
            'student_id' => 'required|string',
            'attempt_id' => 'required|integer|exists:quiz_attempts,id',
            'attempt_token' => 'required|string|size:64',
            'submission_id' => 'required|uuid',
            'answers' => 'present|array',
        ]);

        $quizId = $request->quiz_id;
        $studentId = $request->student_id;
        $answers = $request->answers;
        $submissionId = $request->submission_id;
        $canonicalAnswers = $answers;
        usort($canonicalAnswers, function (array $left, array $right): int {
            return ((int) ($left['questionId'] ?? 0)) <=> ((int) ($right['questionId'] ?? 0));
        });
        $payloadHash = hash('sha256', json_encode([
            'quiz_id' => $quizId,
            'student_id' => $studentId,
            'answers' => $canonicalAnswers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $attempt = QuizAttempt::whereKey($request->attempt_id)
            ->where('quiz_id', $quizId)
            ->where('student_id', $studentId)
            ->where('attempt_token', $request->attempt_token)
            ->first();

        if (! $attempt) {
            return response()->json([
                'error' => 'Quiz attempt is invalid or does not belong to this student.',
                'status' => 'invalid_attempt',
            ], 403);
        }

        $existingSubmission = Result::where('submission_id', $submissionId)->first();

        if ($existingSubmission) {
            if ($existingSubmission->submission_payload_hash !== $payloadHash) {
                return response()->json([
                    'error' => 'This submission ID was already used with different answers.',
                    'status' => 'submission_conflict',
                ], 409);
            }

            $quiz = Quiz::withCount('questions')->findOrFail($existingSubmission->quiz_id);

            return response()->json([
                'message' => 'Submission already processed.',
                'score' => $existingSubmission->score,
                'total' => $quiz->questions_count,
                'idempotent_replay' => true,
            ]);
        }

        if ($attempt->status !== 'active') {
            return response()->json([
                'error' => 'This quiz attempt is no longer active.',
                'status' => $attempt->status,
            ], 409);
        }

        if ($attempt->expires_at->isPast()) {
            $attempt->update([
                'status' => 'expired',
                'active_key' => null,
            ]);

            return response()->json([
                'error' => 'This quiz attempt has expired.',
                'status' => 'ended',
                'quiz_ended' => true,
            ], 403);
        }

        // 1. Check for duplicate submission
        $start = microtime(true);

        $existingResult = Result::where('quiz_id', $quizId)
            ->where('student_id', $studentId)
            ->first();

        $existingResultTime = (microtime(true) - $start) * 1000;

        Log::info('SUBMIT - Existing result query', [
            'time_ms' => round($existingResultTime, 2),
        ]);

        if ($existingResult) {

            $start = microtime(true);

            $quiz = Quiz::withCount('questions')->findOrFail($quizId);

            $quizCountTime = (microtime(true) - $start) * 1000;

            Log::info('SUBMIT - Question count query', [
                'time_ms' => round($quizCountTime, 2),
            ]);

            return response()->json([
                'message' => 'Quiz already submitted.',
                'score' => $existingResult->score,
                'total' => $quiz->questions_count,
            ], 200);
        }

        // 2. Load quiz + questions

        $start = microtime(true);

        $quiz = Quiz::with('questions')->findOrFail($quizId);

        $quizQuestionsTime = (microtime(true) - $start) * 1000;

        Log::info('SUBMIT - Quiz + questions query', [
            'time_ms' => round($quizQuestionsTime, 2),
        ]);

        $now = Carbon::now();
        $startTime = $quiz->start_datetime ? Carbon::parse($quiz->start_datetime) : null;
        $durationSeconds = max(60, (int) ($quiz->duration ?: ($quiz->questions->count() * 60)));
        $endTime = $startTime ? $startTime->copy()->addSeconds($durationSeconds) : null;

        // Verify if quiz is started and not ended
        if (! $startTime) {
            return response()->json([
                'error' => 'This quiz has not been started by the teacher yet.',
                'status' => 'idle',
            ], 403);
        }

        if ($now->lt($startTime)) {
            return response()->json([
                'error' => 'This quiz is scheduled and has not started yet.',
                'status' => 'scheduled',
            ], 403);
        }

        // Allow a 30-second buffer for network jitter on live submissions
        if ($endTime && $now->gt($endTime->copy()->addSeconds(30))) {
            return response()->json([
                'error' => 'This quiz has already ended. Submissions are no longer accepted.',
                'status' => 'ended',
                'quiz_ended' => true,
            ], 403);
        }

        // Grade submission
        $score = 0;
        $correctAnswers = [];

        foreach ($quiz->questions as $question) {
            $correctAnswers[$question->id] = $question;
        }

        $resultDetails = [];

        foreach ($answers as $answer) {
            $questionId = $answer['questionId'] ?? null;
            $selectedOptionIndex = $answer['selectedOption'] ?? null;

            if (! $questionId || ! $selectedOptionIndex) {
                continue;
            }

            $question = $correctAnswers[$questionId] ?? null;

            if ($question) {

                if ((int) $selectedOptionIndex === (int) $question->right_option) {
                    $score++;
                }

                $resultDetails[] = [
                    'question_id' => $questionId,
                    'selected_option' => $selectedOptionIndex,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }
        }

        // 3. Store result inside transaction

        $transactionStart = microtime(true);

        DB::beginTransaction();

        try {

            $start = microtime(true);

            $result = Result::create([
                'student_id' => $studentId,
                'quiz_id' => $quizId,
                'submission_id' => $submissionId,
                'submission_payload_hash' => $payloadHash,
                'score' => $score,
            ]);

            $resultCreateTime = (microtime(true) - $start) * 1000;

            Log::info('SUBMIT - Result create', [
                'time_ms' => round($resultCreateTime, 2),
            ]);

            foreach ($resultDetails as &$detail) {
                $detail['result_id'] = $result->id;
            }

            $start = microtime(true);

            ResultDetail::insert($resultDetails);

            $attempt->update([
                'status' => 'submitted',
                'active_key' => null,
                'submitted_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
            ]);

            $detailsInsertTime = (microtime(true) - $start) * 1000;

            Log::info('SUBMIT - Result details insert', [
                'time_ms' => round($detailsInsertTime, 2),
            ]);

            $start = microtime(true);

            DB::commit();

            $commitTime = (microtime(true) - $start) * 1000;

            Log::info('SUBMIT - Commit', [
                'time_ms' => round($commitTime, 2),
            ]);

            $totalTransactionTime =
                (microtime(true) - $transactionStart) * 1000;

            Log::info('SUBMIT - TOTAL TRANSACTION', [
                'time_ms' => round($totalTransactionTime, 2),
            ]);

            return response()->json([
                'message' => 'Quiz submitted successfully!',
                'score' => $score,
                'total' => count($quiz->questions),
            ]);

        } catch (QueryException $e) {

            DB::rollBack();

            if ($e->getCode() === '23000') {
                $raceResult = Result::where('submission_id', $submissionId)->first();

                if ($raceResult) {
                    if ($raceResult->submission_payload_hash !== $payloadHash) {
                        return response()->json([
                            'error' => 'This submission ID was already used with different answers.',
                            'status' => 'submission_conflict',
                        ], 409);
                    }

                    $quiz = Quiz::withCount('questions')->findOrFail($raceResult->quiz_id);

                    return response()->json([
                        'message' => 'Submission already processed.',
                        'score' => $raceResult->score,
                        'total' => $quiz->questions_count,
                        'idempotent_replay' => true,
                    ]);
                }
            }

            return response()->json([
                'error' => 'Failed to save submission. Please try again.',
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('SUBMIT - Unexpected submission failure', [
                'quiz_id' => $quizId,
                'student_id' => $studentId,
                'submission_id' => $submissionId,
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to save submission. Please try again.',
            ], 500);
        }
    }
}
