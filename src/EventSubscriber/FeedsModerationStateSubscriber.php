<?php

/**
 * @file
 * Event subscriber that forces a moderation state on Feeds-imported entities.
 */

declare(strict_types=1);

namespace Drupal\feeds_moderation_state\EventSubscriber;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\feeds\Event\EntityEvent;
use Drupal\feeds\Event\FeedsEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets a configured content moderation state on entities imported via Feeds.
 *
 * Subscribes to PROCESS_ENTITY_PRESAVE so the moderation state is applied
 * before Feeds saves the entity. Only acts on existing entities being updated
 * — new entities are skipped so that initial-import defaults are not
 * overridden. Supports bidirectional sync: transitions entities to an
 * unpublished state when the source signals unpublished, and to a published
 * state when the source signals published.
 */
class FeedsModerationStateSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a FeedsModerationStateSubscriber.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   The content moderation information service.
   */
  public function __construct(
    protected readonly ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FeedsEvents::PROCESS_ENTITY_PRESAVE => 'onProcessEntityPresave',
    ];
  }

  /**
   * Forces a moderation state on the entity before Feeds saves it.
   *
   * Reads the raw parsed item to determine publish status. The entity's
   * status field is NOT used because Feeds has no mapping for it — the
   * entity status is never updated by Feeds, so reading it would always
   * reflect the entity's current persisted state, not the source value.
   *
   * Supports bidirectional sync: transitions to an unpublished state when the
   * source signals unpublished, and to a published state when the source
   * signals published. The two directions are evaluated in order; the first
   * match wins.
   *
   * @param \Drupal\feeds\Event\EntityEvent $event
   *   The entity event.
   */
  public function onProcessEntityPresave(EntityEvent $event): void {
    $settings = $event->getFeed()->getType()->getThirdPartySettings('feeds_moderation_state');

    $unpublish_enabled = !empty($settings['enabled']) && !empty($settings['moderation_state']);
    $publish_enabled = !empty($settings['publish_enabled']) && !empty($settings['publish_moderation_state']);

    if (!$unpublish_enabled && !$publish_enabled) {
      return;
    }

    $entity = $event->getEntity();

    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    if ($entity->isNew()) {
      return;
    }

    if (!$this->moderationInformation->isModeratedEntity($entity)) {
      return;
    }

    // Read status from the raw parsed item (after feeds_tamper transforms, if
    // any). Compare as strings — PHP's (int) cast silently converts any
    // non-numeric string to 0, which would cause all entities to match.
    $item_status = $event->getItem()->get('status');
    if ($item_status === NULL) {
      return;
    }

    $target_state = NULL;

    if ($unpublish_enabled) {
      $unpublished_value = $settings['source_unpublished_value'] ?? '0';
      if ((string) $item_status === (string) $unpublished_value) {
        $target_state = $settings['moderation_state'];
      }
    }

    if ($target_state === NULL && $publish_enabled) {
      $published_value = $settings['source_published_value'] ?? '1';
      if ((string) $item_status === (string) $published_value) {
        $target_state = $settings['publish_moderation_state'];
      }
    }

    if ($target_state === NULL) {
      return;
    }

    if (!empty($settings['bypass_transitions'])) {
      $entity->setSyncing(TRUE);
    }

    $entity->set('moderation_state', $target_state);
  }

}
