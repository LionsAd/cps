<?php

/**
 * Interface of CPSEntity plugins that handle the published flag of entities.
 */
interface CPSEntityPublishableInterface {
  /**
   * Unset the published flag for the entity.
   *
   * This method does not write to the database; this will just set the flag
   * on the object.
   *
   * @param object $entity
   */
  public function unpublish($entity);

  /**
   * Set the published flag for the entity.
   *
   * This method does not write to the database; this will just set the flag
   * on the object.
   *
   * @param object $entity
   */
  public function publish($entity);

  /**
   * Create and set current an unpublished revision for the $entity.
   *
   * When an entity is created anew, an unpublished live revision must be
   * created for it so that unpublished data does not show up on the live
   * site.
   *
   * @param object $entity
   */
  public function createUnpublishedRevision($entity);

  /**
   * Determine if the entity is published or not.
   *
   * This checks whatever flag the entity has determined works for publishing,
   * and returns TRUE if it is published and FALSE if it is not.
   *
   * @param object $entity
   */
  public function isPublished($entity);
}

/**
 * Provides a class that supports a publishing flag.
 *
 * Automatically creates an unpublished revision when new entities are created.
 */
abstract class CPSEntityPublishableBase extends CPSEntityBase implements CPSEntityPublishableInterface {
  /**
   * @{inheritdoc}
   */
  public function createUnpublishedRevision($entity) {
    // This contains only general code --
    // children will need to implement this and then call up to this.
    list($entity_id, $revision_id,) = entity_extract_ids($this->entity_type, $entity);

    db_insert('cps_entity')
      ->fields(array(
        'entity_type' => $this->entity_type,
        'entity_id' => $entity_id,
        'changeset_id' => 'initial',
        'revision_id' => $revision_id,
      ))
      ->execute();
  }

  /**
   * @{inheritdoc}
   */
  public function hook_entity_insert($entity) {
    parent::hook_entity_insert($entity);
    if ($this->getCurrentChangeset()) {
      $this->createUnpublishedRevision($entity);
    }
  }
}
