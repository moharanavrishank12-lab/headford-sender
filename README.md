# HeadFord Sender (cloud)

Cold emails 30 Google Workspace IDs se (Gmail API, service account), lead ke local subah ke time par.
PC wala Lead Manager ready leads `POST /api/leads` se bhejta hai aur `GET /api/sync` se replies / positive leads leta hai.

- Cron (har minute): `php artisan schedule:run`
- `sender:tick` (har minute) bhejna + handoff, `sender:replies` (5 min) inbox, `sender:health` (9 AM IST) report, `sender:backup` (2:30 AM IST)
- Setup check: `php artisan sender:check`
- Tests: `for t in tests/*_test.php; do php $t; done`
