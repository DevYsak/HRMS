# Employee Guide screenshots

The in-app Employee Guide (`/help/employee-guide`, route `help.employee-guide`) is illustrated with
real screenshots of the employee portal. They are captured by Playwright from a fictional
Employee-role account, so they show exactly what an employee sees, with no real staff data.

## Regenerate

Use a **local** app only. The script refuses any host other than `localhost`, `127.0.0.1` or `*.test`.

```bash
php artisan serve          # in another terminal, if the app isn't already running
npm run build              # so the pages render with current styles
npm run guide:screenshots
```

What the command does:

1. Runs `php artisan guide:demo-employee`, which deletes and recreates the demo account
   **Alex Morgan** (`guide.employee@example.com` / `GuideDemo#2026`) and their manager
   **Jordan Reed**, with sample attendance, leave, WFH, OT, expenses, a goal, a payslip,
   a document and notifications. Every value is invented. The command only runs when
   `APP_ENV` is `local` or `testing`.
2. Logs in as that employee and visits each employee page.
3. Saves into `public/images/employee-guide/`:
   - `<shot>.jpg`: the untouched screen (1440×900; the mobile shot is 390×844 @2x)
   - `annotated/<shot>.png`: the same screen with numbered markers
   - `manifest.json`: titles and marker labels, which the guide reads for its legends

Nothing is submitted: forms are opened for the picture and then left unsaved.

## Options

| Variable | Effect |
|---|---|
| `GUIDE_BASE_URL` | App URL (default `http://127.0.0.1:8000`) |
| `GUIDE_ONLY=leave-top,payslips` | Recapture only these shots; the others stay as they are |
| `GUIDE_SKIP_SEED=1` | Reuse the existing demo account instead of recreating it |
| `GUIDE_EMPLOYEE_PASSWORD` | Use a different demo password |
| `GUIDE_ALLOW_HOST=staging.example.test` | Allow a disposable non-local host. Never use it for production. |

Remove the demo account when you no longer need it:

```bash
php artisan guide:demo-employee --remove
```

## When the UI changes

Each shot in `employee-guide.spec.js` names the elements it marks. If a page moves, is renamed
or starts returning 403, the run stops and names the shot and marker, for example:

```
[leave-top] marker "Apply Leave" not found — the page changed
```

Update that shot's locator, or its wording in `app/Services/Help/EmployeeGuide.php` if the
feature itself changed, then rerun.

The guide's written steps live in `app/Services/Help/EmployeeGuide.php`. The Leave section uses
the Phase 2E leave terms (Base Entitlement, Carry Forward, Add-On, Accrued, Available to Request…).
If My Time Off changes again, rerun the capture and update that glossary to match the new labels.
