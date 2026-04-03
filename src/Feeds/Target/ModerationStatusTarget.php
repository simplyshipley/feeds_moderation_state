<?php

/**
 * @file
 * Feeds target plugin that registers the source status field in the hash.
 */

declare(strict_types=1);

namespace Drupal\feeds_moderation_state\Feeds\Target;

use Drupal\Core\Entity\EntityInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\FeedTypeInterface;
use Drupal\feeds\Plugin\Type\Target\TargetBase;
use Drupal\feeds\TargetDefinition;

/**
 * Feeds target that maps the source status field without writing to the entity.
 *
 * This plugin exists solely to include the source 'status' field in the Feeds
 * item hash. Feeds only hashes mapped source fields; without a mapping for
 * 'status', a status-only change in the source produces an identical hash and
 * PROCESS_ENTITY_PRESAVE is never dispatched. The setTarget() method is
 * intentionally a no-op — actual moderation state transitions are handled by
 * FeedsModerationStateSubscriber via PROCESS_ENTITY_PRESAVE.
 *
 * @FeedsTarget(
 *   id = "feeds_moderation_state_status"
 * )
 */
class ModerationStatusTarget extends TargetBase {

  /**
   * {@inheritdoc}
   */
  public static function targets(array &$targets, FeedTypeInterface $feed_type, array $definition): void {
    $targets['feeds_moderation_state_status'] = TargetDefinition::create()
      ->setPluginId($definition['id'])
      ->setLabel(t('Moderation status tracker (feeds_moderation_state)'))
      ->addProperty('value', t('Status value'));
  }

  /**
   * {@inheritdoc}
   *
   * Intentional no-op. This target exists only to include the source status
   * field in the Feeds item hash. The actual moderation state transition is
   * handled by FeedsModerationStateSubscriber.
   */
  public function setTarget(FeedInterface $feed, EntityInterface $entity, $target, array $values): void {
    // Intentional no-op — see class docblock.
  }

  /**
   * {@inheritdoc}
   */
  public function isMutable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(FeedInterface $feed, EntityInterface $entity, $target): bool {
    return TRUE;
  }

}
