# IDOR inversion evidence — 2026-08-09

`IDOR assertions passed` in a lane log is the **absence** of a mismatch line. Absence
is the exact shape of failure that let this lane report PASS while completely
unauthenticated for months (`71f027b11`). This file is the positive counterpart:
proof that the assertions actually execute and that the app really returns what the
gate claims.

**Method.** Swap the two `responseCode` values in `zap-active.sh`'s `idor_requests()`
so controls expect 404 and probes expect 200, then run the lane. ZAP's mismatch
message carries `Received : <code>`, so every request prints its **real** status.
Swap back afterwards with `cp` from a backup — never `git checkout --`.

**Result.** 44/44 requests mismatched, as they must when every expectation is
inverted. Correlating each URL against that run's `identities.json`:

- **22/22 controls** (identity A's own resource) — `Received : 200`
- **22/22 probes** (identity B's resource, identity A's token) — `Received : 404`
- **0 unclassified** — every URL resolved to a seeded fixture

Cross-tenant isolation holds on every probed ownership-deciding code path. No control
was skipped, 401'd, 422'd or 500'd; no probe leaked a 200.

**Scope note.** Taken at 22 surfaces, before `enquiry-spam`
(`UserEnquiryController::markSpam`) was added as the 23rd. The final committed lane
run covers 23 surfaces / 46 requests and passes; this transcript is not re-taken per
surface, and re-running it is only warranted when the assertion machinery itself
changes.

## Transcript

```
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/items/2d4b393b-d5c2-42fe-a9bd-f02de877a7f9/links/custom Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/items/2d4b393b-d5c2-42fe-a9bd-f02de877a7f9/overrides/core/headline Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/items/53271eab-c13c-4607-b8e3-6ddb5bb8a244 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/items/e0fc839f-6290-46ae-a275-4e5b212f11b9 Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/items/eb4d4704-df0d-4511-9bd6-e7d98c7f1065/links/custom Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/items/eb4d4704-df0d-4511-9bd6-e7d98c7f1065/overrides/core/headline Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/pools/watch/selection/2d4b393b-d5c2-42fe-a9bd-f02de877a7f9 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/pools/watch/selection/eb4d4704-df0d-4511-9bd6-e7d98c7f1065 Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/uploads/283c9cb3-500f-4db6-ae6c-e6396d85d966 Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/content/uploads/7e2ab899-7d7e-4e56-bc93-a06d5f622644 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/documents/6747bf27-0281-4d62-96db-23871f0716fb Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/documents/82e650c8-f908-4f01-9fa4-ee8352acbd69 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/gallery/019fe471-70aa-7353-ae75-a6716fa86035 Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/gallery/019fe471-7135-705f-b44e-0169e5bdff02 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/images/062c0524-b0a1-4a07-a9a8-9463f1b4ba33 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/images/111f34a0-f31e-493f-9fec-b78b977e7538 Expected : 404 Received : 200
Difference in response code values for message DELETE http://host.docker.internal:8100/api/site/pages/6f626abb-edf0-4c07-ab35-48fedd8a7dc7 Expected : 200 Received : 404
Difference in response code values for message DELETE http://host.docker.internal:8100/api/site/pages/caee6ae5-a0d3-4512-85f7-d08a8f13d865 Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/customers/019fe471-70bd-70be-a993-847850d4e671 Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/customers/019fe471-7145-724f-9178-e8dffaec55da Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/me/feedback/20077fb7-c7de-4921-9f66-a7f7a6137357 Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/me/feedback/87845412-089c-40e5-b41c-a6057268cb57 Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/service-categories/9e3582c4-e25e-42db-a1cb-bdbd7a68de4b Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/service-categories/dea78366-5a6f-451f-85e5-c52530dfded6 Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/services/391cde3c-a346-4753-b0c5-3047e8d785be Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/services/e5daed5e-d5e8-4420-b96b-da3aa887ca44 Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/922835ec-dd66-4221-b09c-48295b385352 Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/922835ec-dd66-4221-b09c-48295b385352/groups Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/922835ec-dd66-4221-b09c-48295b385352/items Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/922835ec-dd66-4221-b09c-48295b385352/trace Expected : 200 Received : 404
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/a8ce349a-08b5-4fa0-bd1c-eb95429608e8 Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/a8ce349a-08b5-4fa0-bd1c-eb95429608e8/groups Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/a8ce349a-08b5-4fa0-bd1c-eb95429608e8/items Expected : 404 Received : 200
Difference in response code values for message GET http://host.docker.internal:8100/api/site/sections/a8ce349a-08b5-4fa0-bd1c-eb95429608e8/trace Expected : 404 Received : 200
Difference in response code values for message POST http://host.docker.internal:8100/api/enquiries/019fe471-70be-707e-b5e7-8d45b9d09393/read Expected : 404 Received : 200
Difference in response code values for message POST http://host.docker.internal:8100/api/enquiries/019fe471-7146-73bb-a682-c49af8d8217c/read Expected : 200 Received : 404
Difference in response code values for message POST http://host.docker.internal:8100/api/me/notifications/2c7bd898-a49e-4dfe-becd-687cfbe11ea0/read Expected : 404 Received : 200
Difference in response code values for message POST http://host.docker.internal:8100/api/me/notifications/5d28a4dc-3c42-443f-af07-9e46e8ef7679/read Expected : 200 Received : 404
Difference in response code values for message POST http://host.docker.internal:8100/api/routing/connections/aaa2f6c8-e166-4ea0-9287-47de08828b95/primary Expected : 404 Received : 200
Difference in response code values for message POST http://host.docker.internal:8100/api/routing/connections/ea66e6d6-75b5-4cdc-a2a9-a62538703ca6/primary Expected : 200 Received : 404
Difference in response code values for message POST http://host.docker.internal:8100/api/routing/suggestions/8cce045a-f3e3-4d6b-ba00-8853d81175ac/dismiss Expected : 404 Received : 200
Difference in response code values for message POST http://host.docker.internal:8100/api/routing/suggestions/dcb77171-59c8-4693-aaf4-48e3ec1044d4/dismiss Expected : 200 Received : 404
Difference in response code values for message POST http://host.docker.internal:8100/api/site/restyle/4fded399-66fc-4c55-be3e-a919f15df2da/undo Expected : 200 Received : 404
Difference in response code values for message POST http://host.docker.internal:8100/api/site/restyle/8b4bb8e3-488b-4fa8-8685-96faa17020ef/undo Expected : 404 Received : 200
```
