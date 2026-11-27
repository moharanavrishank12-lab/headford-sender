<?php

// HeadFord Sender - saari settings ek jagah (.env se)
return [
    'api_token' => env('SENDER_API_TOKEN'),                 // PC is token se data bhejta/leta hai
    'dashboard_password' => env('DASHBOARD_PASSWORD'),      // dashboard login
    'google_sa_json' => env('GOOGLE_SA_JSON') ?: storage_path('app/private/google-sa.json'),
    'claude_key' => env('CLAUDE_API_KEY'),
    'claude_model' => env('CLAUDE_MODEL', 'claude-haiku-5-5'),
    'report_email' => env('REPORT_EMAIL', 'jiten.headford@gmail.com'),
    'mark_email' => env('MARK_EMAIL', 'mark@headfordtechnology.biz'),
    'mark_name' => env('MARK_NAME', 'Mark'),

    // Lead ke APNE local time par (East = New York, West = Los Angeles), Mon-Fri
    'window_from' => env('SEND_WINDOW_FROM', '08:00'),
    'window_to' => env('SEND_WINDOW_TO', '10:30'),
    'zones' => ['E' => 'America/New_York', 'W' => 'America/Los_Angeles'],

    'daily_cap' => (int) env('DAILY_CAP_PER_ID', 20),       // follow-ups milake
    'ramp_start_cap' => (int) env('RAMP_START', 5),         // nayi ID: 5, 8, 11, 14, 17, 20
    'ramp_step' => (int) env('RAMP_STEP', 3),
    'followup_days' => [3, 4],                              // 1st follow-up 3 kaam ke din baad, 2nd 4 aur din baad
    'bounce_pause_pct' => (float) env('BOUNCE_PAUSE_PCT', 3.0),
    'bounce_min_sends' => (int) env('BOUNCE_MIN_SENDS', 30),
    'stock_days' => (int) env('STOCK_DAYS', 5),             // cloud mein kam se kam itne din ke leads
    'reverify_days' => (int) env('REVERIFY_DAYS', 30),      // isse purana verify = bhejne se mana (PC dobara verify kare)
    'handoff_delay_min' => [3, 10],
    'positive_classes' => ['INTERESTED', 'REFERRAL', 'UNCLEAR'],
];
