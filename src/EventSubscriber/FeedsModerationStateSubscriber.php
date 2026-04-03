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
use Psr\Log\LoggerInterface;
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
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel for this module.
   */
  public function __construct(
    protected readonly ModerationInformationInterface $moderationInformation,
    protected readonly LoggerInterface $logger,
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
    $feed_type = $event->getFeed()->getType();
    $feed_type_id = $feed_type->id();
    $settings = $feed_type->getThirdPartySettings('feeds_moderation_state');

    $unpublish_enabled = !empty($settings['enabled']) && !empty($settings['moderation_state']);
    $publish_enabled = !empty($settings['publish_enabled']) && !empty($settings['publish_moderation_state']);

    if (!$unpublish_enabled && !$publish_enabled) {
      return;
    }

    $entity = $event->getEntity();

    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    $entity_id = $entity->id() ?? '(new)';
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    if ($entity->isNew()) {
      $this->logger->debug(
        'feeds_moderation_state: skipping new entity [@type:@bundle] on feed type "@feed_type" — initial import, no transition applied.',
        ['@type' => $entity_type, '@bundle' => $bundle, '@feed_type' => $feed_type_id],
      );
      return;
    }

    if (!$this->moderationInformation->isModeratedEntity($entity)) {
      $this->logger->debug(
        'feeds_moderation_state: skipping entity [@type:@bundle id=@id] on feed type "@feed_type" — entity type/bundle is not under Content Moderation.',
        ['@type' => $entity_type, '@bundle' => $bundle, '@id' => $entity_id, '@feed_type' => $feed_type_id],
      );
      return;
    }

    // Read status from the raw parsed item (after feeds_tamper transforms, if
    // any). Compare as strings — PHP's (int) cast silently converts any
    // non-numeric string to 0, which would cause all entities to match.
    $item_status = $event->getItem()->get('status');

    $this->logger->debug(
      'feeds_moderation_state: entity [@type:@bundle id=@id] on feed type "@feed_type" — raw item status value: @status (type: @status_type).',
      [
        '@type' => $entity_type,
        '@bundle' => $bundle,
        '@id' => $entity_id,
        '@feed_type' => $feed_type_id,
        '@status' => var_export($item_status, TRUE),
        '@status_type' => gettype($item_status),
      ],
    );

    if ($item_status === NULL) {
      $this->logger->warning(
        'feeds_moderation_state: entity [@type:@bundle id=@id] on feed type "@feed_type" — status field not present in item. No transition applied. Verify the source field "status" is mapped (even to the no-op Moderation Status Tracker target).',
        ['@type' => $entity_type, '@bundle' => $bundle, '@id' => $entity_id, '@feed_type' => $feed_type_id],
      );
      return;
    }

    $target_state = NULL;

    if ($unpublish_enabled) {
      $unpublished_value = $settings['source_unpublished_value'] ?? '0';
      if ((string) $item_status === (string) $unpublished_value) {
        $target_state = $settings['moderation_state'];
        $this->logger->debug(
          'feeds_moderation_state: entity [@type:@bundle id=@id] on feed type "@feed_type" — status "@status" matches unpublished indicator "@indicator", target state: @state.',
          [
            '@type' => $entity_type,
            '@bundle' => $bundle,
            '@id' => $entity_id,
            '@feed_type' => $feed_type_id,
            '@status' => $item_status,
            '@indicator' => $unpublished_value,
            '@state' => $target_state,
          ],
        );
      }
    }

    if ($target_state === NULL && $publish_enabled) {
      $published_value = $settings['source_published_value'] ?? '1';
      if ((string) $item_status === (string) $published_value) {
        $target_state = $settings['publish_moderation_state'];
        $this->logger->debug(
          'feeds_moderation_state: entity [@type:@bundle id=@id] on feed type "@feed_type" — status "@status" matches published indicator "@indicator", target state: @state.',
          [
            '@type' => $entity_type,
            '@bundle' => $bundle,
            '@id' => $entity_id,
            '@feed_type' => $feed_type_id,
            '@status' => $item_status,
            '@indicator' => $published_value,
            '@state' => $target_state,
          ],
        );
      }
    }

    if ($target_state === NULL) {
      $unpublished_value = $settings['source_unpublished_value'] ?? '0';
      $published_value = $settings['source_published_value'] ?? '1';
      $this->logger->warning(
        'feeds_moderation_state: entity [@type:@bundle id=@id] on feed type "@feed_type" — status "@status" did not match unpublished indicator "@unpublished"@publish_note. No transition applied.',
        [
          '@type' => $entity_type,
          '@bundle' => $bundle,
          '@id' => $entity_id,
          '@feed_type' => $feed_type_id,
          '@status' => $item_status,
          '@unpublished' => $unpublished_value,
          '@publish_note' => $publish_enabled ? ' or published indicator "' . $published_value . '"' : ' (publish direction disabled)',
        ],
      );
      return;
    }

    if (!empty($settings['bypass_transitions'])) {
      $this->logger->debug(
        'feeds_moderation_state: entity [@type:@bundle id=@id] on feed type "@feed_type" — bypass_transitions enabled, calling setSyncing(TRUE).',
        ['@type' => $entity_type, '@bundle' => $bundle, '@id' => $entity_id, '@feed_type' => $feed_type_id],
      );
      $entity->setSyncing(TRUE);
    }

    $this->logger->notice(
      'feeds_moderation_state: transitioning entity [@type:@bundle id=@id] on feed type "@feed_type" to moderation state "@state" (source status: @status).',
      [
        '@type' => $entity_type,
        '@bundle' => $bundle,
        '@id' => $entity_id,
        '@feed_type' => $feed_type_id,
        '@state' => $target_state,
        '@status' => $item_status,
      ],
    );

    $entity->set('moderation_state', $target_state);
  }

}
