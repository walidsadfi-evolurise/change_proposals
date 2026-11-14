# Change Proposals

**Let AI agents, integrations and contributors *propose* content changes in Drupal. An editor reviews the field-by-field diff and approves or rejects. Approval applies the change as a new revision, authored by the reviewer, with the proposal kept as the audit trail.**

[![CI](https://github.com/walidsadfi-evolurise/change_proposals/actions/workflows/ci.yml/badge.svg)](https://github.com/walidsadfi-evolurise/change_proposals/actions/workflows/ci.yml)
![Drupal 10.3+ / 11](https://img.shields.io/badge/Drupal-10.3%2B%20%7C%2011-0678be)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4)
![License GPLv2](https://img.shields.io/badge/license-GPLv2-green)

## The problem

Giving an LLM agent (or an outside integration) an account that can edit content means trusting it blindly; giving it nothing means an editor retypes what the agent suggested. Content moderation and workflows cover *drafts by humans*, not *suggestions by machines* that should never hold the pen.

## What it does

A **change proposal** is a content entity: target (entity type, id or bundle, translation), the proposed field values, a reason, who proposed it, and later who decided what. The module adds:

* **One manager service for every channel** — the same checks run whether the proposal comes from the UI, JSON:API (`POST /jsonapi/change_proposal/change_proposal`), Drush or MCP: allow-listed entity types, blocked fields (entity keys, owner, revision metadata, plus your own list), view access on the target, and **Drupal's own field validation** run on a copy of the entity. The proposer gets the error immediately; the reviewer only sees valid proposals.
* **A review page** (`/admin/content/change-proposals`) with current vs proposed values per field, the reason, a staleness warning when the content was edited after the proposal, and *Approve and apply* / *Reject* with a note returned to the proposer.
* **Apply as a revision**: approval re-validates against the live content, then saves a new revision with the reviewer as revision author and a log message pointing at the proposal. Creation proposals create the entity (owner = proposer, revision = reviewer). A deleted target or a value that stopped validating marks the proposal *failed* instead of half-applying.
* **Expiry** on cron (default 14 days), **email** on new proposals, a **webhook** (Slack-compatible) on created / approved / rejected / failed / expired, hooks for the rest.
* **Drush**: `cp-list`, `cp-show`, `cp-propose`, `cp-approve`, `cp-reject`, `cp-expire`.
* **MCP** (submodule `change_proposals_mcp`, needs [MCP Server](https://www.drupal.org/project/mcp_server) 2.x): `describe_content_type`, `propose_content_update`, `propose_content_create`, `get_proposal`, `list_proposals`. The agent never gets a tool that writes content.

```jsonc
// propose_content_update {"id": 42, "values": {"title": "Pricing 2027"}, "reason": "The page still says 2026"}
{
  "id": 7, "status": "pending", "operation": "update", "target_type": "node", "target_id": 42,
  "summary": "Update Page #42 \"Pricing\": title",
  "diff": [{"field": "title", "label": "Title", "current": "Pricing", "proposed": "Pricing 2027", "changed": true}],
  "review_url": "https://example.com/admin/content/change-proposals/7",
  "next_step": "A human reviewer must approve this proposal before anything changes. Poll get_proposal, or wait for the site's notification."
}
```

## Install

```bash
composer require drupal/change_proposals
drush en change_proposals          # + change_proposals_mcp if you use MCP Server
```

1. **People → Permissions**: *Create change proposals* (and *View own change proposals*) for the agent / integration role; *Review change proposals* for editors. For MCP add *Propose content changes through MCP*.
2. **Configuration → Content authoring → Change proposals**: entity types that accept proposals (default: content), fields that can never be proposed, expiry, reviewer email, webhook.
3. Reviewers work from **Content → Change proposals**. Proposers follow the decision through the API that made the proposal.

### From the command line

```bash
drush --user=agent cp-propose node:42 '{"title":"Pricing 2027"}' --reason="The page still says 2026"
drush cp-list
drush cp-show 7
drush --user=editor cp-approve 7 --note="ok"
```

## Why a module and not a workflow

| | Content Moderation / Workflows | Change Proposals |
|---|---|---|
| Who holds the pen | the author saves a draft revision | the proposer never writes to the entity |
| Granularity | whole revision | the fields being proposed, with a diff |
| Validation | at save, by the author | twice: when proposed (feedback to the agent) and when approved (against live content) |
| Audit | revision log | proposal entity: payload, reason, source, decision, note, result |
| Channels | UI | UI, JSON:API, Drush, MCP, your own code via `change_proposals.manager` |

They combine well: an approved proposal becomes a revision your moderation workflow can still gate.

Reading side: [MCP Content Tools](https://www.drupal.org/project/mcp_content_tools) gives agents search and Markdown export of the same content.

## Development

```bash
vendor/bin/phpunit -c web/core web/modules/contrib/change_proposals
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/contrib/change_proposals
```

Kernel tests build real nodes and users and assert: pending until approved, diff, reviewer-authored revision, blocked and unknown fields refused, constraint violations refused at proposal time, creation proposals, expiry, deleted target, access. CI runs Drupal 10.3 and 11 on PHP 8.3/8.4 with SQLite.

## License

GPLv2 or later. Built by [Walid Sadfi](https://walidsadfi.com) — Forward Deployed Engineer, Drupal & AI agents.
