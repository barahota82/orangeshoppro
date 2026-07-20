# Global Restore Operational Policy (Clarification)

| Field | Value |
|-------|--------|
| **Status** | Architecture / operational clarification only — **no implementation** |
| **Date** | 2026-07-20 |
| **Purpose** | Clarify platform-wide operational behavior whenever **any** production Restore enters Maintenance Mode |
| **New Owner Decisions** | **None** — does not create or reopen any OD-* |
| **Related OWNER_APPROVED** | OD-MAINT, OD-MAINT-SCOPE (GLOBAL), Maintenance State on failure pause; CPR Super Admin UX: `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **Applies to** | Country Production Restore · Full Global Restore · Disaster Recovery Restore · any future production Restore workflow |
| **Hard rule** | Does **not** amend OWNER_APPROVED decision text. If wording conflicts with the owner register, the register wins. |

---

## 1. Scope

This document clarifies the **operational behavior** during **ANY** production Restore operation.

The policy applies equally to:

- Country Production Restore.  
- Full Global Restore.  
- Disaster Recovery Restore.  
- Any future production Restore workflow.

It documents behavior **implied by** the already approved Global Maintenance architecture (especially OWNER_APPROVED **OD-MAINT** / **OD-MAINT-SCOPE** for CPR, and the existing Full DR maintenance chassis).

---

## 2. Global Maintenance behavior

Whenever **ANY** Restore operation enters Production Maintenance Mode:

- The **entire Orange platform** enters **Global Maintenance**.  
- This is a **platform-wide operational state** (not country-scoped).

Country-only maintenance is not an approved production mode under the current architecture (CPR: OWNER_APPROVED OD-MAINT-SCOPE).

---

## 3. Customer experience

All customer-facing storefronts for **every country** shall display the Maintenance page.

Customers shall **not** be able to:

- Create orders.  
- Complete payments.  
- Modify accounts.  
- Submit forms that create or modify production data.  
- Perform any operation that writes production data.

---

## 4. Country Admin experience

All Country Admin dashboards for **every country** shall become **unavailable** during Maintenance.

Country Admins shall **not** be able to perform any production mutation.

---

## 5. Background services

All production writers remain **suspended** according to the approved Global Maintenance policy, including applicable:

- Queues  
- Cron jobs  
- Integrations  
- Payment callbacks  
- Webhooks  
- Other write-capable services  

…until Maintenance is released.

---

## 6. Super Admin

The **Super Admin** is the only operational user allowed to access the **Restore Management** interface during Maintenance.

From this interface the Super Admin manages, as applicable to the active restore workflow:

- Backup progress  
- Verification  
- Restore execution  
- Progress  
- Logs  
- Resume  
- Rollback  
- Final verification  
- Release Maintenance  

Security controls for CPR actions remain those already OWNER_APPROVED (e.g. OD-DUAL, OD-PHRASE, OD-ROLLBACK, OD-FAIL-*, OD-PIN). This section does not add new decisions.

---

## 7. Return to service

Normal platform operation resumes **only after** the Super Admin **explicitly releases Maintenance** following successful completion of the approved workflow (or after an approved Resume/Rollback path that ends with a successful maintenance release).

Only then shall:

- Customer storefronts reopen.  
- Country Admin dashboards reopen.  
- Background services resume.  
- Normal production traffic return.

Users must never regain normal access while a restore process remains incomplete (aligned with OWNER_APPROVED Maintenance State on failure pause for CPR).

---

## 8. Explicit non-claims

This document:

- Introduces **no** new OD-* IDs.  
- Does **not** reopen or amend OWNER_APPROVED wording in the CPR register.  
- Does **not** authorize enablement or implementation.  
- Does **not** start P1.  
- Does **not** modify C3–C8.

---

*End of Global Restore Operational Policy clarification — documentation only.*
