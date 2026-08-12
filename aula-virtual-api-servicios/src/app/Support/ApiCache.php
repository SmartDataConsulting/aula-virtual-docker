<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ApiCache
{
    private const COURSE_SUMMARY_VERSION_KEY = 'course_summary_version';
    private const ATTENDANCE_SUMMARY_VERSION_KEY = 'attendance_summary_version';
    private const SURVEY_RESULTS_VERSION_KEY = 'survey_results_version';

    public static function courseSummaryKey(string $scope, ?string $role, ?string $email): string
    {
        return implode(':', [
            'course-summary',
            self::courseSummaryVersion(),
            $scope,
            self::clean($role ?: 'guest'),
            self::clean($email ?: 'all'),
        ]);
    }

    public static function bumpCourseSummary(): void
    {
        if (!Cache::has(self::COURSE_SUMMARY_VERSION_KEY)) {
            Cache::forever(self::COURSE_SUMMARY_VERSION_KEY, 1);
        }

        Cache::increment(self::COURSE_SUMMARY_VERSION_KEY);
    }

    public static function attendanceSummaryKey(string $scope, ?string $role, ?string $email): string
    {
        return implode(':', [
            'attendance-summary',
            self::attendanceSummaryVersion(),
            self::clean($scope),
            self::clean($role ?: 'guest'),
            self::clean($email ?: 'all'),
        ]);
    }

    public static function bumpAttendanceSummary(): void
    {
        if (!Cache::has(self::ATTENDANCE_SUMMARY_VERSION_KEY)) {
            Cache::forever(self::ATTENDANCE_SUMMARY_VERSION_KEY, 1);
        }

        Cache::increment(self::ATTENDANCE_SUMMARY_VERSION_KEY);
    }

    public static function surveyResultsKey(int $courseId, string $role, string $email, array $filters): string
    {
        ksort($filters);

        return implode(':', [
            'survey-results',
            self::surveyResultsVersion(),
            $courseId,
            self::clean($role),
            self::clean($email),
            sha1(json_encode($filters)),
        ]);
    }

    public static function bumpSurveyResults(): void
    {
        if (!Cache::has(self::SURVEY_RESULTS_VERSION_KEY)) {
            Cache::forever(self::SURVEY_RESULTS_VERSION_KEY, 1);
        }

        Cache::increment(self::SURVEY_RESULTS_VERSION_KEY);
    }

    private static function courseSummaryVersion(): int
    {
        return (int) Cache::get(self::COURSE_SUMMARY_VERSION_KEY, 1);
    }

    private static function attendanceSummaryVersion(): int
    {
        return (int) Cache::get(self::ATTENDANCE_SUMMARY_VERSION_KEY, 1);
    }

    private static function surveyResultsVersion(): int
    {
        return (int) Cache::get(self::SURVEY_RESULTS_VERSION_KEY, 1);
    }

    private static function clean(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', strtolower(trim($value))) ?: 'empty';
    }
}
