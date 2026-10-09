<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Result;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuizApiIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_room_quizzes_returns_404_for_nonexistent_room(): void
    {
        $response = $this->postJson('/api/quiz/room-quizzes', [
            'room_name' => 'NON_EXISTENT_ROOM_'.uniqid(),
            'student_id' => 'STU101',
        ]);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error']);
    }

    public function test_room_quizzes_returns_quiz_list_with_statuses(): void
    {
        $teacher = User::factory()->create([
            'room_name' => 'ROOM_'.strtoupper(uniqid()),
        ]);

        $liveQuiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'title' => 'Live Midterm',
            'start_datetime' => Carbon::now()->subMinutes(2),
            'duration' => 600,
        ]);

        Question::factory()->count(2)->create([
            'quiz_id' => $liveQuiz->id,
        ]);

        $scheduledQuiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'title' => 'Future Finals',
            'start_datetime' => Carbon::now()->addHours(2),
            'duration' => 1200,
        ]);

        Question::factory()->count(1)->create([
            'quiz_id' => $scheduledQuiz->id,
        ]);

        $response = $this->postJson('/api/quiz/room-quizzes', [
            'room_name' => $teacher->room_name,
            'student_id' => 'STU200',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'teacher_name',
            'room_name',
            'student_id',
            'server_time',
            'quizzes' => [
                '*' => [
                    'id',
                    'title',
                    'status',
                    'question_count',
                    'duration',
                ],
            ],
        ]);

        $data = $response->json('quizzes');
        $liveItem = collect($data)->firstWhere('id', $liveQuiz->id);
        $this->assertNotNull($liveItem);
        $this->assertEquals('live', $liveItem['status']);

        $scheduledItem = collect($data)->firstWhere('id', $scheduledQuiz->id);
        $this->assertNotNull($scheduledItem);
        $this->assertEquals('scheduled', $scheduledItem['status']);
    }

    public function test_quiz_start_returns_questions_when_quiz_is_live(): void
    {
        $teacher = User::factory()->create([
            'room_name' => 'ROOM_'.strtoupper(uniqid()),
        ]);

        $quiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'title' => 'Live Algorithm Quiz',
            'start_datetime' => Carbon::now()->subMinute(),
            'duration' => 300,
        ]);

        $question1 = Question::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'What is O(1)?',
            'option1' => 'Constant',
            'option2' => 'Linear',
            'option3' => 'Logarithmic',
            'option4' => 'Exponential',
            'right_option' => '1',
            'duration' => 60,
        ]);

        $question2 = Question::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'What is O(n)?',
            'option1' => 'Constant',
            'option2' => 'Linear',
            'option3' => 'Logarithmic',
            'option4' => 'Exponential',
            'right_option' => '2',
            'duration' => 60,
        ]);

        $response = $this->postJson('/api/quiz/start', [
            'quiz_id' => $quiz->id,
            'student_id' => 'STU300',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'student_id',
            'quiz' => ['id', 'title', 'duration', 'start_datetime'],
            'questions' => [
                '*' => ['id', 'quiz_id', 'text', 'option1', 'option2', 'option3', 'option4', 'duration'],
            ],
        ]);

        $this->assertCount(2, $response->json('questions'));
    }

    public function test_quiz_start_fails_when_quiz_not_started_yet(): void
    {
        $teacher = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'start_datetime' => Carbon::now()->addHours(1),
            'duration' => 300,
        ]);

        $response = $this->postJson('/api/quiz/start', [
            'quiz_id' => $quiz->id,
            'student_id' => 'STU400',
        ]);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error', 'start_datetime']);
    }

    public function test_quiz_submit_grades_answers_and_records_result_details(): void
    {
        $teacher = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'duration' => 300,
            'start_datetime' => Carbon::now()->subMinute(),
        ]);

        $q1 = Question::factory()->create([
            'quiz_id' => $quiz->id,
            'right_option' => '2',
        ]);

        $q2 = Question::factory()->create([
            'quiz_id' => $quiz->id,
            'right_option' => '4',
        ]);

        $studentId = 'STU500';
        $attempt = $this->createActiveAttempt($quiz, $studentId);

        $response = $this->postJson('/api/quiz/submit', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'submission_id' => (string) Str::uuid(),
            'answers' => [
                ['questionId' => $q1->id, 'selectedOption' => 2], // correct
                ['questionId' => $q2->id, 'selectedOption' => 1], // incorrect
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Quiz submitted successfully!',
            'score' => 1,
            'total' => 2,
        ]);

        $this->assertDatabaseHas('results', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'score' => 1,
        ]);

        $result = Result::where('quiz_id', $quiz->id)
            ->where('student_id', $studentId)
            ->first();

        $this->assertNotNull($result);
        $this->assertDatabaseHas('result_details', [
            'result_id' => $result->id,
            'question_id' => $q1->id,
            'selected_option' => 2,
        ]);
        $this->assertDatabaseHas('result_details', [
            'result_id' => $result->id,
            'question_id' => $q2->id,
            'selected_option' => 1,
        ]);
    }

    public function test_quiz_submit_duplicate_returns_existing_result(): void
    {
        $teacher = User::factory()->create();
        $quiz = Quiz::factory()->create(['userid' => $teacher->id]);
        $q = Question::factory()->create(['quiz_id' => $quiz->id, 'right_option' => '1']);

        $studentId = 'STU600';
        $attempt = $this->createActiveAttempt($quiz, $studentId);
        Result::create([
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'score' => 1,
        ]);

        $response = $this->postJson('/api/quiz/submit', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'submission_id' => (string) Str::uuid(),
            'answers' => [
                ['questionId' => $q->id, 'selectedOption' => 1],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Quiz already submitted.',
            'score' => 1,
        ]);
    }

    public function test_quiz_submit_rejected_when_quiz_has_ended(): void
    {
        $teacher = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'start_datetime' => Carbon::now()->subMinutes(30),
            'duration' => 300, // 5 minutes quiz that started 30 mins ago
        ]);

        $q = Question::factory()->create(['quiz_id' => $quiz->id, 'right_option' => '1']);
        $studentId = 'STU700';
        $attempt = $this->createActiveAttempt($quiz, $studentId);

        $response = $this->postJson('/api/quiz/submit', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'submission_id' => (string) Str::uuid(),
            'answers' => [
                ['questionId' => $q->id, 'selectedOption' => 1],
            ],
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'This quiz has already ended. Submissions are no longer accepted.',
            'status' => 'ended',
            'quiz_ended' => true,
        ]);

        $this->assertDatabaseMissing('results', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
        ]);
    }

    public function test_quiz_submit_rejected_when_quiz_not_started(): void
    {
        $teacher = User::factory()->create();
        $quiz = Quiz::factory()->unscheduled()->create([
            'userid' => $teacher->id,
        ]);

        $q = Question::factory()->create(['quiz_id' => $quiz->id, 'right_option' => '1']);
        $studentId = 'STU800';
        $attempt = $this->createActiveAttempt($quiz, $studentId);

        $response = $this->postJson('/api/quiz/submit', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'submission_id' => (string) Str::uuid(),
            'answers' => [
                ['questionId' => $q->id, 'selectedOption' => 1],
            ],
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'This quiz has not been started by the teacher yet.',
            'status' => 'idle',
        ]);
    }

    public function test_teacher_can_end_quiz_and_restart_quiz_live(): void
    {
        $teacher = User::factory()->create();
        $this->actingAs($teacher);

        $quiz = Quiz::factory()->create([
            'userid' => $teacher->id,
            'start_datetime' => Carbon::now(),
            'duration' => 300,
        ]);

        $q = Question::factory()->create([
            'quiz_id' => $quiz->id,
            'right_option' => '2',
            'duration' => 60,
        ]);

        // 1. Teacher ends the quiz
        $endResponse = $this->post(route('quiz.endnow', $quiz->id));
        $endResponse->assertRedirect();

        $quiz->refresh();
        $this->assertTrue(Carbon::now()->gt(Carbon::parse($quiz->start_datetime)->addSeconds($quiz->duration)));

        // 2. Student submission while ended is rejected
        $studentId = 'STU900';
        $attempt = $this->createActiveAttempt($quiz, $studentId);
        $submitResponse1 = $this->postJson('/api/quiz/submit', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'submission_id' => (string) Str::uuid(),
            'answers' => [
                ['questionId' => $q->id, 'selectedOption' => 2],
            ],
        ]);
        $submitResponse1->assertStatus(403);
        $submitResponse1->assertJson(['quiz_ended' => true]);

        // 3. Teacher restarts the quiz live
        $startResponse = $this->post(route('quiz.startnow', $quiz->id));
        $startResponse->assertRedirect();

        $quiz->refresh();
        $this->assertTrue(Carbon::now()->lte(Carbon::parse($quiz->start_datetime)->addSeconds($quiz->duration)));

        // 4. Student can now submit successfully
        $submitResponse2 = $this->postJson('/api/quiz/submit', [
            'quiz_id' => $quiz->id,
            'student_id' => $studentId,
            'attempt_id' => $attempt->id,
            'attempt_token' => $attempt->attempt_token,
            'submission_id' => (string) Str::uuid(),
            'answers' => [
                ['questionId' => $q->id, 'selectedOption' => 2],
            ],
        ]);
        $submitResponse2->assertStatus(200);
        $submitResponse2->assertJson([
            'message' => 'Quiz submitted successfully!',
            'score' => 1,
            'total' => 1,
        ]);
    }

    private function createActiveAttempt(Quiz $quiz, string $studentId): QuizAttempt
    {
        $now = Carbon::now();

        return QuizAttempt::create([
            'student_id' => $studentId,
            'quiz_id' => $quiz->id,
            'status' => 'active',
            'active_key' => "{$studentId}:{$quiz->id}",
            'attempt_token' => Str::random(64),
            'started_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'last_seen_at' => $now,
        ]);
    }
}
