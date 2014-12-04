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
  public function hook_entity_insert($entity) {
    parent::hook_entity_insert($entity);
    if ($this->getCurrentChangeset()) {
      $this->createUnpublishedRevision($entity);
    }
  }

  /**
   * Create a published revision in the current changeset.
   *
   * When an new entity is inserted, we actually want two revisions to be saved.
   *  - An unpublished revision in the 'published' changeset, since CPS treats
   *    any entity that's never been published as 'unpublished'.
   *  - A draft revision in the current changeset with the published flag set to
   *    the same as on the node form.
   *
   * This method exists as a parent method for children that will implement
   * CPSEntityPublishableInterface to share common code. It assumes that
   * anything that calls this will inherit that interface, and will crash
   * if that is not true.
   *
   * When PHP 5.4 is reliable, we can use a trait to enforce this instead.
   *
   * See CPSEntityPublishableInterface::createUnpublishedRevision()
   */
  public function createUnpublishedRevision($original_entity) {
    /** @var Entity $entity */
    // This will only get called if an object subclassing this implements
    // CPSEntityPublishableInterface which will make these methods valid.
    $changeset = $this->getCurrentChangeset();
    if (!$changeset) {
      return;
    }
    // Operate on a clone to avoid changing the entity prior to subsequent
    // hook_entity_insert() implementations.
    $published_entity = clone $original_entity;

    // The entity is no longer new.
    unset($published_entity->is_new);

    $this->unpublish($published_entity);
    entity_save($this->entity_type, $published_entity);
  }
}
