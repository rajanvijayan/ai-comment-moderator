# Changelog

## 2.0.0

Complete rewrite.

### Added
- Groq provider (default) using the OpenAI-compatible chat completions API with JSON mode; Gemini and OpenAI providers; automatic fallback provider; shared rate-limit backoff.
- Structured verdicts: spam and toxicity scores, categories, sentiment, language, reason; configurable thresholds and toxic action (hold / trash / spam).
- "Hold until checked" mode, optional AI approvals, fail-open behaviour when the AI is unavailable.
- Background processing via WP-Cron with exponential-backoff retries and a safety sweep.
- Local pre-filters: regex/word blocklist, email/domain allow-list, link limit, trusted users, returning commenters.
- Custom plain-language moderation rules; injection-resistant prompt construction; optional Groq Prompt Guard pre-check.
- Context-aware auto replies (post, excerpt, parent thread, language), human review workflow (Send / Edit / Regenerate / Discard), delay, daily cap, loop protection.
- Comments screen column, row and bulk actions, "AI drafts" filter.
- Dashboard with stats, daily chart, flags, sentiment, work queue, batch scan; audit log table with retention.
- Settings playground and per-provider connection test.
- Toxic-comment email alerts; WP-CLI commands; developer filters/actions; uninstall cleanup; i18n.

### Fixed
- Spam detection never triggered (string comparison against `'false'`).
- Replies generated for spam and before approval; replies posted without author name.
- Cron not cleared on deactivation; reference to missing `SpamCheckCron` class; Composer script referencing a non-existent installer class.
- Unescaped output and missing sanitisation on the settings page; generic option names (`api_key`) that could collide with other plugins.

### Changed
- Removed the `rajanvijayan/ai-engine` (`dev-main`) dependency; the plugin has no runtime Composer dependencies.
- Settings stored in a single `aicm_settings` option (v1 options migrated automatically).
- License clarified as GPL-2.0-or-later throughout.

## 1.0.0
- Initial release.
