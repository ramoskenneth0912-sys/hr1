<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\AiScreening;
use App\Models\ApiToken;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOnboarding;
use App\Models\EmploymentHistory;
use App\Models\EssProfile;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\OnboardingTask;
use App\Models\SystemSetting;
use App\Models\User;

Route::get('/health', function () {
    $db = ['ok' => false, 'error' => null];

    try {
        DB::select('SELECT 1');
        $db['ok'] = true;
    } catch (Throwable $e) {
        $db['error'] = $e->getMessage();
    }

    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'laravel' => app()->version(),
        'time' => now()->toIso8601String(),
        'db' => $db,
    ]);
});

/**
 * Read-only identity echo — same JSON contract as GET /api/v1/auth/me.
 * Resolves via Bearer api_tokens OR the legacy PHPSESSID session.
 */
Route::middleware('auth:legacy')->get('/auth/me', function (Illuminate\Http\Request $request) {
    $user = $request->user();

    return response()->json([
        'success' => true,
        'message' => 'Current user retrieved successfully.',
        'data' => [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'employee_id' => $user->employee_id !== null ? (int) $user->employee_id : null,
            'created_at' => $user->created_at,
        ],
    ], 200, [], JSON_UNESCAPED_UNICODE);
})->name('legacy.me');

/**
 * Read-only smoke check: one COUNT(*) per mapped model.
 * Proves every model resolves against the existing hr1_database tables.
 */
Route::get('/schema-check', function () {
    $map = [
        'departments' => Department::class,
        'users' => User::class,
        'employees' => Employee::class,
        'applicants' => Applicant::class,
        'job_postings' => JobPosting::class,
        'interviews' => Interview::class,
        'onboarding_tasks' => OnboardingTask::class,
        'employee_onboarding' => EmployeeOnboarding::class,
        'leave_requests' => LeaveRequest::class,
        'ess_profiles' => EssProfile::class,
        'notifications' => Notification::class,
        'employee_documents' => EmployeeDocument::class,
        'employment_history' => EmploymentHistory::class,
        'api_tokens' => ApiToken::class,
        'ai_screening' => AiScreening::class,
        'notification_preferences' => NotificationPreference::class,
        'system_settings' => SystemSetting::class,
    ];

    $results = [];
    foreach ($map as $table => $model) {
        try {
            $results[$table] = [
                'model' => $model,
                'count' => $model::count(),
                'ok' => true,
            ];
        } catch (Throwable $e) {
            $results[$table] = [
                'model' => $model,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    $allOk = collect($results)->every(fn ($r) => $r['ok'] === true);

    return response()->json([
        'ok' => $allOk,
        'tables_mapped' => count($results),
        'results' => $results,
    ]);
});
