# Feeds Moderation State

Forces a configured Content Moderation state on entities imported via
[Feeds 3.x](https://www.drupal.org/project/feeds). Supports bidirectional
sync — entities can be transitioned to an unpublished state when the source
signals unpublished, and back to a published state when the source signals
published.

## Requirements

- Drupal 10 or 11
- [Feeds](https://www.drupal.org/project/feeds) 3.x
- Core Content Moderation module (enabled)
- Core Workflows module (enabled)
- At least one Content Moderation workflow configured for the target entity
  type and bundle

**Entity type support:** Only entity types that are moderated by Content
Moderation are affected. The entity type must be revisionable and the
Content Moderation workflow must be applied to the specific bundle you are
importing. Taxonomy terms are **not** revisionable by default and cannot use
Content Moderation without a third-party module that adds revision support.

## Installation

Install as a standard Drupal custom module. No additional configuration is
required at install time — all settings are per-FeedType.

```bash
drush en feeds_moderation_state
```

## How It Works

The module attaches its configuration to each FeedType as third-party
settings. When Feeds processes an import and is about to save an entity, the
module reads the raw source `status` value and transitions the entity to the
configured moderation state.

**You do not map the source field to `moderation_state` directly.** Feeds
does not expose `moderation_state` as a standard mapping target. Instead,
this module handles the transition internally via the
`PROCESS_ENTITY_PRESAVE` event. The only role of the Feeds mapping is to
ensure the source `status` field participates in the Feeds item hash so that
status-only changes are detected (Feeds only re-processes items whose hash
changes). The module injects this mapping automatically when you enable the
feature.

**New entities are skipped.** The module only transitions existing entities
that are being updated. Initial imports create entities at whatever default
moderation state is defined by the workflow for new content.

## Configuration

1. Go to **Structure > Feed types** and edit the feed type you want to
   configure.
2. Expand the **Content moderation state** fieldset in the feed type settings.
3. Check **Update moderation state on existing entities when publishing
   status changes on source**.
4. Select the **Target moderation state** — the moderation state to apply
   when the source signals unpublished (e.g. `draft`, `archived`). Only
   unpublished states are listed here.
5. Set the **Unpublished indicator value** — the value your source feed uses
   to signal "unpublished":
   - Use `0` for raw API responses that return a boolean-style status.
   - If you are using [Feeds Tamper](https://www.drupal.org/project/feeds_tamper)
     to transform the status field (e.g. `0` → `draft`), enter the
     post-tamper value here (e.g. `draft`).
6. **Optional: Publish direction.** Check **Transition existing entities to
   a published state when source status changes to published** and select the
   target published moderation state (e.g. `published`). Set the
   **Published indicator value** similarly (default: `1`).
7. Save the feed type. The module automatically adds an internal mapping
   (`status` → *Moderation status tracker*) to keep the item hash current.
   You do not need to add this mapping manually.

### Bypass Workflow Transitions

Check **Bypass workflow transition validation (setSyncing)** to call
`setSyncing(TRUE)` on the entity before save. This bypasses Drupal's
workflow transition validation, allowing the entity to jump directly to any
state regardless of allowed transitions. Use this only for data migration or
sync scenarios where transition rules are deliberately not enforced.

## feeds_tamper Integration

If you use Feeds Tamper to transform the source `status` field before
mapping, set the **Unpublished indicator value** and **Published indicator
value** to whatever value the tamper plugin produces, not the raw source
value. The module reads the item status after all tamper transforms have
been applied.

Example: if Feeds Tamper maps `0` → `archived` and `1` → `published`, set:
- Unpublished indicator value: `archived`
- Published indicator value: `published`

## Troubleshooting

**Entities are not being transitioned.**

- Confirm the entity type and bundle has Content Moderation enabled for the
  correct workflow (check `/admin/config/workflow/workflows`).
- Taxonomy terms are not moderated by default — see Entity type support above.
- Check that the FeedType has the module enabled (the fieldset checkbox is
  checked and the feed type was saved).
- Run the import and check Drupal logs for any errors.
- Verify the **Unpublished indicator value** matches what the source actually
  sends. Enable Feeds Tamper and add a *Display value* tamper plugin to
  inspect the raw value before it reaches this module.

**Status-only changes are not detected / entities are not re-imported.**

This happens when the source `status` field is not included in the Feeds
item hash. The module auto-injects the required mapping when you enable the
feature, but if you manually removed the *Moderation status tracker* mapping
from the feed type, status-only changes will produce an identical hash and
the entity will not be updated. Re-save the feed type settings to restore
the mapping.

## Architecture Notes

### Why not map directly to `moderation_state`?

Drupal's `moderation_state` is not a standard field that Feeds can write to
via its target system — it is a computed virtual field managed by Content
Moderation. The module intercepts at `PROCESS_ENTITY_PRESAVE` and sets the
field directly on the entity object before Feeds saves it.

### Why is there a no-op `feeds_moderation_state_status` target?

Feeds only includes source fields in the change-detection hash when they
are mapped to a target. Without a mapping for `status`, a source record
whose only change is a status flip produces an identical hash and Feeds
skips the entity entirely — `PROCESS_ENTITY_PRESAVE` is never fired. The
`ModerationStatusTarget` plugin registers a target that accepts the source
`status` value and deliberately does nothing with it. Its sole purpose is
hash inclusion.
