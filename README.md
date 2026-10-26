# AI Comment Moderator

AI-powered comment moderation for WordPress. Every new comment is scored for **spam** and **toxicity** by a fast LLM (Groq by default, with Google Gemini and OpenAI also supported). The plugin then approves, holds, spams or trashes it according to thresholds you control, and can draft **context-aware replies** that a human approves before they go live.

## Features

**AI providers**
- **Groq** (default): very low latency, a good fit for real-time moderation. Ships with `llama-3.3-70b-versatile`, plus the Llama 3.1 8B and GPT-OSS models.
- **Google Gemini** and **OpenAI** are also supported. Any model name can be typed in.
- **Automatic fallback provider** for when the primary errors or is rate-limited (HTTP 429 sets a shared back-off).
- Structured **JSON mode** responses with tolerant parsing and schema normalisation.
- Optional **prompt-injection guard** using Groq's `llama-prompt-guard-2-86m` (preview model).
- API keys can live in `wp-config.php` (`AICM_GROQ_API_KEY`, `AICM_GEMINI_API_KEY`, `AICM_OPENAI_API_KEY`) instead of the database. Keys are never echoed back in the UI.

**Moderation**
- Separate spam and toxicity scores (0–1), with configurable thresholds and a "review" band for borderline comments.
- Categories (spam, self-promotion, harassment, hate, off-topic, personal info…), sentiment and language detection.
- **Hold until checked**: new comments stay pending until the AI clears them, so spam is never visible. If the AI is unavailable, comments are released the way WordPress would normally handle them (fail-open).
- The AI only makes moderation *stricter* than your Discussion settings, unless you opt in to AI approvals.
- **Background processing** through WP-Cron, so comment submission stays instant. Failed calls retry with exponential backoff, and a safety sweep catches stuck jobs.
- Free local pre-filters that run before any API call: blocklist (words or regex), allow-list (emails or domains), link limit, trusted users, returning commenters.
- **Custom rules** written in plain language and injected into the prompt.
- Injection-resistant prompts: the post, parent comment and comment are passed as delimited, neutralised data.

**Auto replies**
- Context-aware: uses the post title, excerpt and parent thread, and replies in the commenter's language or the site's.
- **Human review** by default: drafts appear in *Comments* with **Send / Edit / Regenerate / Discard**, plus an "AI drafts" filter.
- Natural delay, daily cap, max length, and an option to skip negative comments. Links and markdown are stripped from replies.
- Loop protection: never replies to its own replies or AI threads.

**Admin & ops**
- *AI Moderation* column on the Comments screen with score meters, flags and the reason.
- Row and bulk actions: **Re-check with AI**, **Draft AI reply**.
- **Dashboard** (*Comments → AI Moderation*): totals, block rate, latency, token usage, daily chart, top flags, sentiment, work queue and batch scan of existing comments.
- **Audit log** in its own table, with filters, search and retention.
- **Playground** for testing your configuration against any text without saving anything.
- Email alert for toxic comments.
- **WP-CLI**: `wp ai-moderator check|scan|stats|test`.
- Developer hooks: `aicm_verdict`, `aicm_classification_prompt`, `aicm_reply_prompt`, `aicm_should_reply`, `aicm_fail_open`, `aicm_after_moderation`, `aicm_reply_sent`.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An API key from [Groq](https://console.groq.com/keys) (recommended), [Google AI Studio](https://aistudio.google.com/app/apikey) or [OpenAI](https://platform.openai.com/api-keys)

The plugin has **no runtime Composer dependencies**. It works when installed straight from the repository (a built-in PSR-4 autoloader is used when `vendor/` is absent).

## Installation

1. Download the latest `ai-comment-moderator.zip` from [Releases](https://github.com/rajanvijayan/ai-comment-moderator/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, then install and activate it.
3. Go to **Settings → AI Comment Moderator**, paste your Groq key and click **Test connection**.
4. Recommended: turn on **Moderation → Hold until checked**.
5. Optional: enable **Auto Replies** and select a reply author.

To keep the key out of the database, add this to `wp-config.php`:

```php
define( 'AICM_GROQ_API_KEY', 'gsk_...' );
```

## How a comment is processed

```
comment_post ─► pre-filters (blocklist / allow-list / trusted)  ──► decision
                     │ no match
                     ▼
            [hold until checked] ─► WP-Cron job ─► Groq (JSON) ──429/5xx──► fallback provider
                                                     │                          │
                                                     ▼                          ▼
                                    thresholds ─► approve | hold | spam | trash ─► log
                                                     │
                                                     ▼ (approved & reply-worthy)
                                     draft reply ─► human review ─► publish as reply author
```

## WP-CLI

```bash
wp ai-moderator test                      # ping configured providers
wp ai-moderator check 123 --dry-run       # show the verdict without acting
wp ai-moderator scan --status=hold --limit=500
wp ai-moderator stats --days=7 --format=json
```

## Developer hooks

```php
// Never auto-spam comments from a partner domain.
add_filter( 'aicm_verdict', function ( $verdict, $comment ) {
    if ( '@partner.com' === substr( $comment->comment_author_email, -12 ) && 'spam' === $verdict->action ) {
        $verdict->action = 'hold';
    }
    return $verdict;
}, 10, 2 );

// Add site-specific context to the classification prompt.
add_filter( 'aicm_classification_prompt', fn ( $prompt ) => $prompt . "\nThis is a medical blog; flag unverified health claims for review." );

// Only reply on posts in the "tutorials" category.
add_filter( 'aicm_should_reply', fn ( $ok, $comment ) => $ok && has_category( 'tutorials', $comment->comment_post_ID ), 10, 2 );
```

## Upgrading from 1.x

Settings migrate automatically on first load:

- The v1 API key becomes the **Gemini** key and Gemini stays the active provider, so nothing changes until you switch to Groq.
- If auto-response was on, it stays on with review **off**, matching v1 behaviour.
- Replies v1 had queued but not sent become drafts awaiting review.

**Bugs fixed from 1.x:**
- Spam was never detected, because the model's text was compared to the string `'false'`.
- Every comment got an AI call and a reply, including spam.
- Replies had no author name.
- The cron schedule was never cleared on deactivation.
- The code referenced a missing `SpamCheckCron` class.

## Privacy

Comment content, author name/URL and the post title/excerpt are sent to the selected AI provider for analysis. Commenter email and IP addresses are **not** sent. Mention this in your privacy policy.

## License

GPL-2.0-or-later
