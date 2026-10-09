<?php

use App\Http\Controllers\AuthManager;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\QuestionControlller;
use App\Http\Controllers\QuizExamController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Schedule_Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/////////////////////////
Route::get('/', function () {
    return view('welcome');
});

Route::get('/TorS', [RoleController::class, 'TorS'])->name('TorS');
//////////////////

///////////////
Route::get('/registration', [AuthManager::class, 'registration'])->name('registration');
Route::post('/registration.post', [AuthManager::class, 'registrationPost'])->name('registration.post');
Route::post('/login', [AuthManager::class, 'loginPost'])->name('login.post');
Route::get('/registration.post', function () {
    return redirect()->route('registration');
});
Route::get('/login', [AuthManager::class, 'login'])->name('login');

Route::get('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/TorS?role=teacher');
})->name('logout');
//////////////////

Route::post('/questions/add', [QuestionControlller::class, 'add'])->name('questions.add');
Route::delete('/questions/{id}', [QuestionControlller::class, 'destroyQuestion'])->name('questions.delete');
Route::post('/store-quiz', [QuestionControlller::class, 'storeQuiz'])->name('storeQuiz');
Route::post('/submit-student', [QuestionControlller::class, 'submitStudent'])->name('student.submit');
Route::get('/store-quiz', function () {
    return redirect()->back()->withErrors(['error' => 'Invalid request method. Use the form to submit.']);
});

///////////////////////

Route::get('/teacher', function () {
    return view('teacher.addquiz');
})->name('teacher.view');

//////////////

Route::get('/quiz-list', [App\Http\Controllers\QuizListController::class, 'showQuizList'])->name('quiz.list');
Route::get('/quiz/{id}/details', [App\Http\Controllers\QuizListController::class, 'showQuizDetails'])->name('quiz.details');
Route::match(['get', 'post'], '/enter-room', function () {
    return redirect()->route('student.app');
})->name('enter.room');
Route::get('/quiz-listStud', function () {
    return redirect()->route('student.app');
})->name('quiz.listStud');
Route::delete('/quiz/{id}', [App\Http\Controllers\QuizListController::class, 'destroy'])->name('quiz.destroy');

//////////////////////
Route::get('/quiz/{id}/leaderboard', [App\Http\Controllers\BoardController::class, 'showboard'])->name('quiz.leaderboard');
Route::get('/quiz/{id}/performance', [App\Http\Controllers\BoardController::class, 'performanceGraph'])->name('quiz.performance');
Route::get('/leaderboard/export/{id}', [App\Http\Controllers\BoardController::class, 'export']);
Route::delete('/leaderboard/{quiz}', [App\Http\Controllers\BoardController::class, 'destroy'])
    ->name('leaderboard.delete');

/////////////////////

Route::get('/quiz/{id}/take', function () {
    return redirect()->route('student.app');
})->name('quiz.take');
Route::post('/quiz/{quiz}/submit/{student}', [QuizExamController::class, 'submitQuizAnswered'])->name('quiz.submit');
Route::post('/quiz/startnow/{id}', [QuizExamController::class, 'startNow'])->name('quiz.startnow');
Route::post('/quiz/endnow/{id}', [QuizExamController::class, 'endNow'])->name('quiz.endnow');

/////////////////////////
Route::post('/store-result', [App\Http\Controllers\ResultController::class, 'storeResult'])->name('result.store');
Route::get('/student/results/{student_id}/{quiz_id?}', [ResultController::class, 'showResult'])->name('student.results');
Route::get('/quiz/{quiz_id}/analysis/{student_id}', [ResultController::class, 'showResultByQuiz'])->name('student.quiz.analysis');
////////////////

Route::post('quiz/{id}/schedule', [Schedule_Controller::class, 'schedule'])->name('quiz.schedule');

///////////////////

Route::get('/questions/edit/{id}', [QuestionControlller::class, 'edittoupdate'])->name('questions.edit');
Route::put('/questions/update/{id}', [QuestionControlller::class, 'update'])->name('questions.update');

Route::get('/password-reset', function () {
    return view('teacher.passwordreset');
})->name('password.request');

Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');

// routes/web.php
Route::get('/reset-password/{token}', function ($token) {
    return view('teacher.resetform', ['token' => $token]);
})->name('password.reset');

Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->name('password.update');

// Svelte Resilient Quiz App
Route::get('/student-app', function () {
    return view('quiz');
})->name('student.app');
