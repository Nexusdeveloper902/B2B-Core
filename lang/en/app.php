<?php

/*
|--------------------------------------------------------------------------
| Dashboard UI Language Lines (English)
|--------------------------------------------------------------------------
*/

return [
    // Common / nav
    'app_name' => 'Presence Platform',
    'dashboard' => 'Dashboard',
    'teacher_dashboard' => 'Teacher Dashboard',
    'admin_dashboard' => 'Admin Dashboard',
    'logout' => 'Log out',
    'login' => 'Log in',
    'email' => 'Email',
    'password' => 'Password',
    'remember' => 'Remember me',
    'language' => 'Language',
    'welcome' => 'Welcome, :name',
    'back' => 'Back',

    // Shell / chrome (TASK-005 UI passover)
    'skip_to_content' => 'Skip to content',
    'primary_nav' => 'Primary',
    'footer_note' => 'Core operations console — attendance, PAE meals and recycling rewards for the school day.',
    'demo_credentials' => 'Demo credentials (seeded)',
    'env' => 'Environment',

    // Login
    'login_title' => 'Sign in to the Presence Platform',
    'login_hint' => 'Demo users (from the seeder): admin@presence.test / teacher@presence.test — password "password".',

    // Teacher dashboard
    'today_attendance' => 'Today\'s attendance',
    'class' => 'Class',
    'student' => 'Student',
    'status' => 'Status',
    'present' => 'Present',
    'late' => 'Late',
    'absent' => 'Absent',
    'tapped_at' => 'Tapped at',
    'late_cutoff_note' => 'Late = tapped after :cutoff',
    'no_students' => 'No students in this class yet.',
    'pae_enrolled' => 'PAE',
    'points' => 'Points',

    // Admin dashboard
    'school_today' => 'School today',
    'attendance_count' => 'Attendance (distinct students)',
    'pae_breakfast' => 'PAE breakfast',
    'pae_lunch' => 'PAE lunch',
    'recycling_today' => 'Recycling today',
    'recycling_items' => 'Items',
    'recycling_points' => 'Points awarded',
    'readers' => 'Readers',
    'reader' => 'Reader',
    'reader_type' => 'Type',
    'active_mode' => 'Active mode',
    'change_mode' => 'Change mode',
    'mode_updated' => 'Reader mode updated.',
    'nl_query' => 'Natural language query',
    'nl_query_placeholder' => 'Ask e.g.: How many students attended today? / PAE lunch totals? / recycling this week?',
    'ask' => 'Ask',
    'nl_query_not_configured' => 'NL query is not configured (no GEMINI_API_KEY) — endpoint reports this as blocked.',
    'redemption' => 'Redeem reward',
    'student' => 'Student',
    'reward' => 'Reward',
    'redeem' => 'Redeem',
    'balance' => 'Balance',
    'students' => 'Students',
    'view_parent' => 'Parent view',

    // Parent timeline
    'parent_timeline' => 'Parent view — :name\'s timeline',
    'event_type' => 'Event',
    'reader_label' => 'Reader',
    'material' => 'Material',
    'no_events' => 'No events recorded yet for this student.',

    // Generic statuses
    'ok' => 'OK',
    'saving' => 'Saving…',
    'error_generic' => 'Something went wrong, please retry.',
];
