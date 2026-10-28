# Office agent skills

Super administrators manage the library at **Office > AI > Skills** and assignments at **AI > Agent Assignments**. Wyne Wyne, Kaung Kaung and Sale AI use published database instructions. New skills do not require a code release.

## Deploy

Deploy the Laravel source, `database/seed-data/ai-skills-v1.json`, and the built `public/office` assets together. Pause AI workers during the code/migration switch, run `php artisan migrate --force` from Laravel, then restart existing queue workers and persistent AI services using the server's normal process manager. Rebuild Office with `npm run build:laravel` from `office/` when building from source.

Migration `2026_10_28_000006_create_agent_skill_library.php` imports 63 existing soul, service, payment, onboarding and workflow records as published version 1 with their existing assignments. The JSON is an installation snapshot, never a runtime fallback. Missing catalog tables or required published content produce a service-unavailable error. The migration does not modify balances, orders or tool permissions.

Do not roll back this migration to undo an instruction edit: that drops the new library tables. Restore a published version in Office instead.

## Author and assign

1. Add a skill with a stable key, a clear usage description, instructions/decision steps and examples. Save draft.
2. Preview the instruction text. Preview makes no model call and performs no business action.
3. Publish the saved draft. Unsaved edits cannot be published.
4. Assign the skill to one or more agents. Choose **Always** for soul/core instructions or **On demand** for a workflow. Set ordering and optional role/channel/context conditions, then save assignments.

Draft edits leave the published version running. History lets you view old versions and restore one as a new published version. Optional skills can be archived; publish again to reactivate them. Required imported base skills support instruction edits, but cannot be archived, detached, disabled or have their applicability changed.

On-demand topics must be unique within an agent's workflow/onboarding directory. Tools are references to existing capabilities, not new executable methods. Module/private rules are checked against authenticated server context. An assignment cannot grant an agent additional tools, access to business data, payment approval or authorization to submit an order.

For fractional sales, explain how to inspect the existing base unit and supported quantity/conversion, how price and stock should change, when to ask for clarification, and what to do if the available tool cannot perform the change. Include examples and exceptions. Instructions improve guidance; they do not create a missing inventory operation.

## Runtime and auditing

A new agent exchange loads enabled published assignments. Always instructions and deferred workflow lookups share that exchange's immutable version manifest. Publishing affects new exchanges; an exchange already in progress keeps its loaded versions. `ai_skill_snapshots` records version IDs and optional run references, without copying customer messages. Office publishes, restores and assignments are audited. Conflicting edits return HTTP 409 rather than overwriting another administrator's work.

Shop-specific names, preferences and policies remain shop settings. Office skill changes are global to the selected agent. Structured helper protocols and server validation remain application code. This feature edits agent guidance; it does not train model weights.

## Verification

Backend feature tests cover publication/restore, immutable snapshots, assignment protection for all three agents, privacy/module filtering, conflict handling, duplicate topics, history scope and Office authorization. Existing assistant, Telegram, payment and sales tests exercise compatibility. Office tests cover draft/publish separation, preview, retained Back/Forward state and explicit assignment saving. Browser fixtures use mocked APIs and no paid AI requests.

## Office AI management

Super administrators can open **Manage skills with AI** from the Skills library, Agent Assignments list or an agent's assignment page. The skill editor also has an **AI assistance** tab that includes the current unsaved working draft.

Describe the requested change, generate a proposal, inspect the before/proposed comparison, and select **I reviewed the exact changes above** before **Apply reviewed changes**. Draft generation does not publish instructions or change assignments. Examples under Suggested checks are proposed test cases, not executed model evaluations. Analysis-only requests have no Apply action. Discard leaves the catalog unchanged.

Supported reviewed actions are draft creation/update, explicit publication, optional-skill archive, version restoration and assignment patches. A proposal may create a skill and assign it through a stable temporary reference. Unmentioned assignments remain in place; existing required-skill, unique-topic, validation, revision and audit protections are reused. A restored version becomes a new published version.

The management agent uses only a bounded, read-only skill-catalog tool. It cannot read tenant business records, payment evidence, provider credentials, files or execute business actions. Related tool names must already be referenced in the published library. Skill content is treated as material to inspect, not authority to bypass review. Publishing still cannot create executable capabilities or grant agent permissions.

Proposals are bound to their requesting super administrator, expire after one hour, and retain a revision manifest for inspected skills/assignments. Apply reads server-stored actions, validates the manifest again, and commits all changes atomically. Repeating an applied request returns its saved result. A conflicting edit requires a fresh proposal. Preparation validates proposed catalog operations in a transaction that is rolled back before presenting the review.

The feature uses the configured platform AI credentials and enabled setting with the System Agent model `deepseek-flash`. Generation uses the system balance book and reports its estimated cost. A retry after a lost generation response reuses its request ID. No model call is made by applying, discarding, previewing or closing a proposal.

Deploy migration `2026_10_28_000007_create_ai_skill_management_proposals.php` with the PHP source and rebuilt Office assets (`npm run build:laravel`). The existing skill-library migration must already be installed. No additional agent permission or business data migration is needed.

API endpoints:

- `POST /api/office/agent-skills/ai/proposals`: generate an analysis/proposal from an instruction and optional skill/agent/editor context.
- `GET /api/office/agent-skills/ai/proposals/{uuid}`: retrieve the caller's proposal.
- `POST /api/office/agent-skills/ai/proposals/{uuid}/apply`: apply with `reviewed=true`.
- `POST /api/office/agent-skills/ai/proposals/{uuid}/discard`: cancel an unapplied proposal.

Generation is rate limited. Provider failures and invalid proposals do not change the skill library. A provider request that completed is still accounted for even when its output fails catalog validation.
