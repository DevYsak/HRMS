# NEXCORE / Pulse HRMS — UI & Responsive Checklist

Status: **NOT TESTED** for every cell. Test Chrome latest and one supported secondary browser. Capture `ROLE-UI-TC-ID-description.png` for defects.

| Area | 1920×1080 | 1366×768 | 768×1024 | 390×844 | Expected result |
|---|---|---|---|---|---|
| Login / reset / first password | ☐ | ☐ | ☐ | ☐ | Labels, password controls and errors remain visible and usable |
| Sidebar / header / avatar / notifications | ☐ | ☐ | ☐ | ☐ | Menu collapses appropriately; no duplicate/blank group; touch targets usable |
| Dashboards / cards / charts | ☐ | ☐ | ☐ | ☐ | Accurate cards wrap without overlap, clipping, fake blank space or repeated values |
| Tables / filters / pagination | ☐ | ☐ | ☐ | ☐ | Filters reachable; table has intentional scroll/responsive view; no hidden actions |
| Employee forms / validation | ☐ | ☐ | ☐ | ☐ | Required marks, errors and long values stay beside correct field |
| Attendance timeline / drawer / break controls | ☐ | ☐ | ☐ | ☐ | Times, timeline and actions readable; drawer/modals close and scroll |
| Leave / OT / regularisation / approvals | ☐ | ☐ | ☐ | ☐ | Date pickers, half-day controls and decision buttons remain reachable |
| Payroll / payslip / finance approval | ☐ | ☐ | ☐ | ☐ | Money totals readable; protected actions not inadvertently exposed |
| Documents / uploads / downloads | ☐ | ☐ | ☐ | ☐ | File names, progress/errors, version badges and acknowledgement work |
| Performance / KPI | ☐ | ☐ | ☐ | ☐ | Weights/scores do not truncate; review action ordering clear |
| Settings / reports / audit / Help | ☐ | ☐ | ☐ | ☐ | Long menu names, charts and exports are usable and aligned |
| Error / empty / loading states | ☐ | ☐ | ☐ | ☐ | Clear recovery text; no spinner lock, stack trace or layout shift |

Check on every screen: consistent font hierarchy, card padding, alignment, contrast, keyboard focus, error proximity, readable empty state, no horizontal page overflow, and no content behind sticky elements. Log visible cosmetic faults as Low unless they obscure action/data.
