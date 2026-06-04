# MCP Abilities - Content Demand

Tracks zero-result frontend site searches and exposes content-demand candidates through MCP.

This plugin is intentionally agent-facing. It does not add a normal WordPress admin report and it does not auto-publish pages. Candidates must be used as evidence only: verify facts from primary sources, draft high-quality content, and review before publishing.

## Abilities

- `content-demand/list-candidates`
- `content-demand/update-candidate`

## Data

Searches are aggregated in a custom table keyed by a normalized term hash. Email-like searches are normalized to the domain to avoid storing full email addresses.

