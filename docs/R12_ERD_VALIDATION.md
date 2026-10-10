# R12 Final As-Built ERD Validation

**Result: PASSED.** Documentation and diagram artifacts only.

## Frozen sources

- Frozen implementation commit: `62fdff1c40391d247e7741536fd397c7832a07f8`.
- R11 documentation commit: `8a0bd7cf9bd5e97240b296c49f011e926e0cfe3b`.
- Frozen schema version: `20261010_008`.
- Authoritative sources, read completely: [R11_SCHEMA_FREEZE.md](R11_SCHEMA_FREEZE.md) and [R11_SCHEMA_FREEZE.json](R11_SCHEMA_FREEZE.json).
- Both source files are byte-for-byte unchanged from the R11 documentation commit. No schema metadata was inferred.

## Artifacts

- [Editable Draw.io ERD](GSO_FINAL_AS_BUILT_ERD.drawio): uncompressed native XML; separate editable entity, field, key-marker, reference, and connector cells. No flattened image in the primary artifact.
- [Three-page PDF](GSO_FINAL_AS_BUILT_ERD.pdf): exactly three pages, each 792 × 612 PDF points (11 × 8.5 inches).
- 2200 × 1700 PNG previews: [Page 1](GSO_FINAL_AS_BUILT_ERD_PAGE_1.png), [Page 2](GSO_FINAL_AS_BUILT_ERD_PAGE_2.png), [Page 3](GSO_FINAL_AS_BUILT_ERD_PAGE_3.png).

## Exact schema comparison

| Check | Result |
|---|---:|
| Letter landscape pages | 3 |
| Unique physical tables, each represented once | 22 |
| Frozen fields represented, each once | 275 |
| Physical foreign keys represented, each once | 57 |
| Missing tables | 0 |
| Extra tables | 0 |
| Missing fields | 0 |
| Extra fields | 0 |
| Missing fks | 0 |
| Extra fks | 0 |
| Duplicate fields | 0 |
| Duplicate fks | 0 |
| Obsolete schema objects | 0 |

Comparison parsed the saved Draw.io XML independently of its generator. It compared table names, every field name and displayed label, PK/FK markers, FK constraint names, child/parent column lists, connector endpoints, update/delete actions, and cardinality against R11 JSON. It also checked reference labels and owning pages, native cell IDs, page dimensions, and actual native-render geometry.

`resource_requests.claim_slip_code` is present. Removed request-header resource/quantity and Adviser/Director fields are absent. No Adviser/Director-history, external-client, claim-slip, or maintenance-history entity was added. Generated columns appear under their actual names. Configuration keys remain row values, not invented columns.

## Page organization

| Page and subtitle | Tables | Fields | FKs owned by child | Off-page reference boxes |
|---|---:|---:|---:|---:|
| 1 — Identity, Requests & Audit | 8 | 111 | 17 | 1 |
| 2 — Inventory & Return Processing | 6 | 67 | 14 | 5 |
| 3 — Maintenance, AI & Configuration | 8 | 97 | 26 | 16 |

**Page 1:** `users`, `resource_requests`, `request_attachments`, `request_reschedule_history`, `request_items`, `notifications`, `activity_logs`, `login_attempts`.

**Page 2:** `resources`, `equipment_units`, `request_item_units`, `return_submission_items`, `return_submissions`, `return_submission_photos`.

**Page 3:** `preventive_maintenance_plans`, `incident_reports`, `maintenance_schedules`, `borrower_liabilities`, `ai_knowledge_entries`, `ai_conversations`, `ai_messages`, `system_settings`.

The requested logical table grouping is preserved. Page 3 uses compact references beside each owning entity to keep its routes local. References are explicitly labeled with the physical table name and `(Page N)` and are never counted as physical entities.

## Cardinality

All 57 connectors use black orthogonal Crow’s Foot notation. There are 24 direct same-page relationships and 33 relationships to correctly labeled off-page references. Each physical FK appears only on its child’s page.

- Parent endpoints: 24 mandatory `1`; 33 nullable `0..1`, derived from the frozen FK column nullability.
- Child endpoints: 55 `0..many`; 2 `0..1`, derived from a PRIMARY/UNIQUE constraint on the FK columns.
- The unique child endpoints are `return_submissions.request_id` and `borrower_liabilities.return_item_id`. The latter also has a nullable parent endpoint.
- No business-only relationships, minimum-child requirements, or authoritative request-to-resource shortcut were invented.

## Native rendering and visual inspection

The saved XML was decoded separately for each page using the official diagrams.net mxGraph library and its native ER marker implementation, then rendered locally in headless Chrome. No diagram or database data was uploaded. All 57 native edge states rendered. Rendered text bounding boxes and the actual connector coordinates were checked programmatically, and all three final PNG previews were visually inspected.

| Geometry/visual check | Result |
|---|---|
| Text outside entity headers, field rows, or reference boxes | 0 |
| Overlapping entity/reference boxes | 0 |
| Connector segments through cards or field text | 0 |
| Overlapping connector segments | 0 |
| Diagonal segments or terminal reversals | 0 |
| Page overflow/clipping | 0 |
| Titles, exact field names, key markers, and reference labels | Visible and readable |
| White cards, subtle borders, dark text, consistent table typography/padding | Passed |
| Severe crossing clusters | None observed in final previews |

Isolated right-angle connector intersections remain: 16 on Page 1, 2 on Page 2, and 7 on Page 3. These are crossings, not additional relationship junctions. No segments overlap and none passes through a card. Page 3 off-page references use smaller text than the full entity fields; the vector PDF supports zooming and clear printing.

**Export scope:** exports use native mxGraph/ER-shape rendering and Chrome PDF printing, rather than the diagrams.net desktop Export dialog. The primary XML successfully decodes through diagrams.net’s underlying native library, but an interactive desktop/web editor session was not available for a manual open-and-save check. No export blocker remains. The editable `.drawio` is the authoritative deliverable; PDF and PNGs are viewing copies.

Format and marker references: [diagrams.net diagram-generation format](https://www.drawio.com/docs/reference/diagram-generation/), [Crow’s Foot notation](https://www.drawio.com/docs/tutorials/crows-foot-notation/), [official mxGraph bundle](https://github.com/jgraph/drawio/blob/dev/src/main/webapp/mxgraph/mxClient.js), and [native ER shapes and markers](https://github.com/jgraph/drawio/blob/dev/src/main/webapp/shapes/er/mxER.js).

## Scope and repository protection

R12 made no application, migration, schema, database-record, or test changes. Application regression suites were not rerun for this artifact-only task; R11’s 3,033-check freeze remains the recorded implementation validation. The pre-existing protected paths remain unstaged and excluded from the R12 commit:

```text
 D app/controllers/pages/landing.php
?? app/controllers/gso_staff/pages/
```

The requested single local commit is `Generate final as-built GSO ERD`, containing only this report, the Draw.io XML, its three PNG previews, and its PDF. No push is performed.

## SHA-256 provenance

| File | SHA-256 |
|---|---|
| `docs/R11_SCHEMA_FREEZE.md` | `ef62edccb0fefc51af351d3d8ab97bf90349773d6435b391f2b27adcf21e5e1a` |
| `docs/R11_SCHEMA_FREEZE.json` | `2817763753abb1e96616169003ae1094fee005e3711bd48eb8fe157253f02027` |
| `docs/GSO_FINAL_AS_BUILT_ERD.drawio` | `894cac0ba23fa1cfd760be4313037f80a4964d3235e997e15c1ea77f017a9670` |
| `docs/GSO_FINAL_AS_BUILT_ERD.pdf` | `36893c643fe38f6f5f6418b5f595b616675359b8ab76974b0d93c1b31cfab325` |
| `docs/GSO_FINAL_AS_BUILT_ERD_PAGE_1.png` | `296684e64546d23732f3907dc34804e2d0f5724100a355095443425d171c1b4f` |
| `docs/GSO_FINAL_AS_BUILT_ERD_PAGE_2.png` | `c0070a50d250df053cdf9ed951b06390cbae999e4e1a004343ca40c3ea01f6d3` |
| `docs/GSO_FINAL_AS_BUILT_ERD_PAGE_3.png` | `3785a82ec4a2f3e56058b55dc86122b0bc48ae52a854e8f98bdb16f8721ef151` |
