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
 * before Feeds saves the entity. Primary use case: forcing existing published
 * nodes back to an unpublished state when re-imported via Feeds.
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
   * @param \Drupal\feeds\Event\EntityEvent $event
   *   The entity event.
   */
  public function onProcessEntityPresave(EntityEvent $event): void {
    $settings = $event->getFeed()->getType()->getThirdPartySettings('feeds_moderation_state');

    if (empty($settings['enabled']) || empty($settings['moderation_state'])) {
      return;
    }

    $entity = $event->getEntity();

    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    if (!$this->moderationInformation->isModeratedEntity($entity)) {
      return;
    }

    // Only force the moderation state when the source item is unpublished.
    // Read status from the raw parsed item rather than the entity, because
    // Feeds has no field mapping for status — the entity field is never
    // updated by Feeds and would reflect the previously saved value instead.
    $item_status = $event->getItem()->get('status');
    if ($item_status === NULL || (int) $item_status !== 0) {
      return;
    }

    if (!empty($settings['bypass_transitions'])) {
      $entity->setSyncing(TRUE);
    }

    $entity->set('moderation_state', $settings['moderation_state']);
  }

}
