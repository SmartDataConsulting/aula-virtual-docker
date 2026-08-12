<?php

return [

    'google_drive' => [
        'lms_folder_id' => env('GOOGLE_DRIVE_LMS_FOLDER_ID'),
    ],

    'attendance' => [
        'enabled' => filter_var(env('ATTENDANCE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'zoom_sync_enabled' => filter_var(env('ATTENDANCE_ZOOM_SYNC_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_SERVER_CLIENT_ID'),
        'client_secret' => env('ZOOM_SERVER_CLIENT_SECRET'),
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
    ],

];
