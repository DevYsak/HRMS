# Payroll Enterprise Roadmap — Pulse HRMS

Grounded in the July 2026 module audit. Items marked ✅ exist, 🟡 partially exist, ❌ are absent.
UI: `/payroll/*` Livewire screens · Engine: `app/Services/` · PDF: `resources/views/pdf/`.

## 1. Architecture (current + target)

```
                      ┌────────────────────────────────────────────┐
   Attendance ────►   │  SalaryCalculationService (per employee)    │
   Leave / LWP ───►   │   1 fixed components (effective-dated)      │
   OT engine ─────►   │   2 percentage / formula components         │
   Incentives ────►   │   3 statutory deductions (per-emp gates)    │──► Payslip + items
   Reimbursements ►   │   4 OT → incentives → reimb → F&F → LE      │
   Exit records ──►   │   5 LWP deduction                           │
                      └────────────────────────────────────────────┘
   PayrollService: draft → pending_finance → finalized (maker–checker)
   StatutoryService: PF / ESI / PT(MH) / TDS(new regime) — hardcoded consts
   FormulaEvaluator: arithmetic engine (no functions/conditionals yet)
```

Target changes: statutory rates → DB with effective dates; `salary_cycles` table drives run dates;
`company_id` scoping on payroll tables; audit log on every state change.

## 2. Admin workflow (updated)

1. **Configure once**: components (`/payroll/components`) → structures (`/payroll/structures`) →
   per-employee payroll settings (profile) → salary cycles (`/settings/salary-cycles` — ⚠ currently
   decorative, see §8.1).
2. **Monthly**: `/payroll/process` generate draft → review payslips → submit → finance approves at
   `/payroll/finance-approve` → payslips flip to `paid`, OT marked paid → employees notified.
3. **Outputs**: payslip PDF (single/combined, QR-verified), payroll summary PDF.

## 3. Employee workflow (ESS)

My Payslips → period filters (All / L3 / L6 / FY / month / custom) → window totals →
view breakdown → download / print (≤6 combined) / email → QR on PDF verifies authenticity publicly.
Absent: tax summary, Form 16, loan view (§8).

## 4. Database changes required

| Change | Tables | Priority |
|---|---|---|
| Statutory rate config w/ effective dates | `statutory_rates` (type, state, slab json, effective_from) | Critical |
| Wire cycles into runs | none — consume existing `salary_cycles` | Critical |
| Arrears/retro | `payroll_arrears` (employee, source_payroll, delta, status) | High |
| Loans & advances | `loans`, `loan_installments` | High |
| Company scoping | `company_id` on payrolls/payslips/components/structures | Medium |
| Component versioning | `salary_component_versions` or effective dates on components | Medium |
| Payroll lock | `locked_at`/`locked_by` on payrolls + reopen audit | High |
| Declarations (80C/HRA/regime) | `tax_declarations` | High |

## 5. API changes required

Internal (Livewire) — no public API today. If/when exposed: version under `/api/v1/payroll/*`,
Eloquent API Resources, read-only first (payslips, register), token-scoped per §9.

## 6–7. UI/UX improvements

Done this cycle: Inter-embedded PDF, KPI attendance cards, info card, single accent from Company
branding, Indian numerals/words, QR verify, ESS filters + combined print.
Next: formula/statutory fields in the Components UI (engine already supports them); payroll process
stepper showing draft→finance→locked; statutory due-date calendar on `/payroll/overview`.

## 8. Missing production features (audit-verified)

1. ⚠ **`/settings/salary-cycles` is decorative** — `PayrollService::resolveCycleDates()` hardcodes
   cycle_a/b; the admin screen writes a table nothing reads. Highest-value small fix.
2. `Payroll::isApproved()` dead code (checks a status the enum forbids).
3. Components UI can't edit `formula_expression`/statutory flags (DB-only).
4. No `AuditLog` calls in PayrollService — runs/approvals untracked.
5. ❌ Gratuity, LWF, Bonus Act, state-wise PT (Maharashtra hardcoded), TDS old regime + declarations,
   arrears/retro, loans, bank transfer file, salary register export, Form 16/24Q, PF ECR, ESI returns,
   journal entries, multi-currency, grade/band salary mapping.
6. 🔥 **`PayrollDemoSeeder` truncates payroll tables with no env guard** — delete or guard before
   go-live. Safe demo pair: `DemoPayrollHistorySeeder` + `payroll:demo-clear`.

## 9. Security improvements

- QR verify links are signed URLs (done); keep ids non-enumerable.
- Enforce maker ≠ checker: block `finance_approved_by === processed_by`.
- Mask PAN/bank in admin list views; full values only on authorized detail screens.
- Rate-limit payslip download/email endpoints; audit-log every download of another user's slip.

## 10. Performance improvements

- Payslip PDF ~46–65 KB with font subsetting (done).
- `generateDraft()` loads salaries per employee — chunk employees, eager-load components.
- Queue payslip emailing (currently synchronous in the Livewire request).
- YTD strip runs one query per payslip render — acceptable now; cache per employee+FY if bulk-rendering.

## 11. Compliance (India 2026)

- New Labour Codes: keep "wages ≥ 50% of CTC" check as a validation warning on structures.
- Move PF/ESI/PT/TDS rates + slabs to `statutory_rates` with `effective_from` so FY changes are data,
  not deploys; add LWF (state-wise) and Bonus Act (8.33%–20%, ₹21k eligibility) computation.
- TDS: old-vs-new regime election per employee + declaration capture (Form 12BB) + Form 16 (Part B)
  generation from payslip history; 24Q quarterly export.
- Filing artifacts: PF ECR text file, ESI return CSV, PT state challans, bank NEFT file.

## 12. Priority roadmap

| Priority | Items |
|---|---|
| **Critical** | Wire salary_cycles into runs · guard/delete PayrollDemoSeeder · audit-log payroll actions · fix isApproved() |
| **High** | statutory_rates table (+LWF/Bonus/state PT) · TDS regimes + declarations · explicit lock/reopen · bank file + salary register export · maker≠checker enforcement |
| **Medium** | Components UI formula builder · arrears/retro · loans & advances · Form 16/24Q/ECR · company scoping · payroll dashboard/calendar |
| **Low** | Multi-currency · grade/band mapping · journal entries · cost-center analytics · payroll version history |
