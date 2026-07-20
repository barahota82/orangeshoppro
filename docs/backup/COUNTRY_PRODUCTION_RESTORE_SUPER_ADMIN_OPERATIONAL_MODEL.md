# Country Production Restore — Super Admin Operational Model (Clarification)

| Field | Value |
|-------|--------|
| **Status** | Architecture / UX clarification only — **no implementation** |
| **Date** | 2026-07-20 |
| **Purpose** | Explain how already-approved OWNER_APPROVED workflow is presented to the Super Admin |
| **New Owner Decisions** | **None** — this document does not create or reopen any OD-* |
| **Parent architecture** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` |
| **Owner register** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Workshop** | `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Dependencies** | `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **C3–C8** | Must not be modified |
| **P1** | Not started |

**Hard rule:** This clarification **must not** be read as changing any OWNER_APPROVED decision text. Where UI wording and an OD conflict, the **OWNER_APPROVED** register wins.

---

## 1. Super Admin operational model

The Super Admin dashboard shall be the **complete operational interface** for Country Production Restore.

**Normal operation shall never require** SSH, CLI, Terminal, or direct server access.

All normal Production Restore operations are performed **entirely through the administrative interface**.

This presents OWNER_APPROVED **OD-DUAL** Workflow A (and Super Admin execution in Workflow B) as a dashboard-first experience. It does not replace CLI-only production mutation constraints elsewhere in the architecture for emergency/engineering paths; **normal** Super Admin CPR operations are dashboard-complete.

---

## 2. Starting Production Restore

The Super Admin starts Production Restore by:

1. Selecting the desired restore package.  
2. Pressing the **Production Restore** action.

The system shall **automatically** execute the already-approved workflow:

1. Enter Maintenance Mode (**OD-MAINT**, **OD-MAINT-SCOPE** GLOBAL).  
2. Automatically create a **NEW** Full Backup (**OD-PIN**).  
3. Verify the backup (**OD-PIN**).  
4. Pin the backup (**OD-PIN**).  
5. Begin Country Production Restore (subject to remaining gates and approvals already frozen).

The Super Admin **never manually creates** the required pre-restore backup.

This automatic start sequence **implements the already-approved OD-PIN policy** (and related Maintenance decisions). It is a UX/orchestration clarification, not a new Owner Decision.

Security controls already OWNER_APPROVED (e.g. **OD-PHRASE** confirmation phrase `RESTORE`, password re-authentication, audit, one-time authorization) continue to govern authorization before execution as frozen in the register.

---

## 3. Live operation screen

During execution, the Super Admin dashboard shall provide a dedicated **Production Restore management screen** showing, where applicable:

| Display | Implements / aligns with |
|---------|---------------------------|
| Current phase | Execution state machine (P0); OD-FAIL-* pause surfaces |
| Progress percentage | OD-FAIL-IMPORT display requirements; operational monitoring |
| Completed batches | OD-FAIL-IMPORT |
| Estimated duration | OD-MAINT-MAX / OD-RTO (monitoring only) |
| Logs | Audit / execution logging (OD-DUAL protections, OD-ROLLBACK logging) |
| Current status | Live job status |
| Failure reason (when applicable) | OD-FAIL-DELETE / OD-FAIL-IMPORT |

---

## 4. Paused state

If Production Restore pauses because of failure (**OD-FAIL-DELETE**, **OD-FAIL-IMPORT**):

- The same management screen shall remain available.  
- The dashboard shall continue displaying execution details.  
- Maintenance Mode remains active (**Maintenance State** OWNER_APPROVED; **OD-MAINT** / **OD-MAINT-SCOPE**).

Users must not regain normal application access while the restore process is incomplete (already OWNER_APPROVED).

---

## 5. Super Admin actions

When applicable, the dashboard shall provide the appropriate actions, including:

| Action | Governance (already OWNER_APPROVED) |
|--------|-------------------------------------|
| **Resume** | OD-FAIL-DELETE / OD-FAIL-IMPORT — only when the restore stage safely supports continuation; Super Admin decision |
| **Rollback** | **OD-ROLLBACK** — dedicated Super Admin dashboard action; visible only to Super Admin; available only when session is paused because of failure; never automatic; same security controls as Production Restore |
| **View Logs** | Complete audit / execution logging surfaces |

These actions remain governed by **all previously approved security controls and OWNER_APPROVED decisions** (including OD-DUAL, OD-PHRASE, OD-PIN, OD-FAIL-*, Maintenance State). Country Admins must never receive Rollback or Production Restore execution actions (**OD-DUAL**, **OD-ROLLBACK**).

---

## 6. Explicit non-claims

This document:

- Introduces **no** new OD-* IDs.  
- Does **not** reopen or amend OWNER_APPROVED wording in the register.  
- Does **not** authorize enablement or implementation.  
- Does **not** start P1.  
- Does **not** modify C3–C8.

**Next steps:** Owner Decision workshop is complete (all OD-* OWNER_APPROVED). P1 detailed design begins only when the Owner **explicitly authorizes** P1. Architecture narrative SoT for policy remains the Owner Decision Register.

---

*End of Super Admin Operational Model clarification — documentation only.*
