# Superseded suites

These suites tested workflows that the system no longer has. They are kept as
historical reference only. They are not part of the active regression, they
are not expected to pass, and production code must not be changed to make them
pass.

| Suite | Tested | Retired by |
|---|---|---|
| `phase2_adviser_endorsement_e2e.php` | Online Adviser selection, endorsement and revision | R2B (manual signed letter) |
| `phase3_director_conflict_e2e.php` | Director approval and facility conflict prioritisation | R2A (read-only Director schedule) |

Their endpoints, helpers and database structures were removed in R10. The
`require` paths inside them still assume the old `scripts/` location.
