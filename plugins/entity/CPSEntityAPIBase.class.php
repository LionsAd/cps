<?php

/**
 * Entity API class for the CPS Entity management system.
 *
 * Entity API entities have some common features we can take advantage of,
 * and some special handling because most of our assumptions in the base
 * were for the node entity.
 */
class CPSEntityAPIBase extends CPSEntityBase {
  /**
   * @{inheritdoc}
   */
  public function setRevisionFlag($entity, $set_log = TRUE) {
    // Entities that uses alternative methods should override this.
    $entity->is_new_revision = TRUE;
    $entity->default_revision = FALSE;

    if ($set_log) {
      $changeset = cps_changeset_load($this->getCurrentChangeset());
      if (is_object($changeset)) {
        $name = $changeset->name;
      }
      else {
        $name = $this->getCurrentChangeset() ? $this->getCurrentChangeset() : 'Published';
      }
      $entity->log = t('Revision for site version %changeset', array('%changeset' => $name));
    }
  }

  /**
   * @{inheritdoc}
   */
  public function unsetRevisionFlag($entity) {
    $entity->is_new_revision = FALSE;
  }

  /**
   * @{inheritdoc}
   */
  public function restoreEntityData($entity) {
    // Entity API will put fields back if editing an existing revision but not
    // if a new revision was created.
    if (isset($entity->published_revision)) {
      // Ensure file fields don't do funny business. It uses $entity->original
      // to determine if it has to add/remove file usages, and NOT setting this
      // confuses it very badly.
      $entity->published_revision->original = $entity->published_revision;

      if (!empty($entity->revision)) {
        field_attach_update($this->entity_type, $entity->published_revision);
      }
      else {
        $entity->original = $entity->published_revision;
      }
    }
  }

  /**
   * Create an unpublished revision.
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

    // Do all work on a clone!
    $entity = clone($original_entity);
    $entity->original = $original_entity;
    $this->unpublish($entity);
    $entity->log = t('Initial live revision');
    $entity->is_new_revision = TRUE;
    $entity->default_revision = TRUE;
    // NOTE: EntityAPIController::saveRevision() is protected, but we need
    // to access it. We use this trick to create a subclass and a static
    // method that can effectively fill the gap left by PHP not having
    // a 'friend' keyword.
    CPSEntityAPIControllerFriend::protectedSaveRevision(entity_get_controller($entity->entityType()), $entity);
    // Make sure the field data for the live revision gets written so that it is not
    // out of sync.

    $entity->revision = TRUE;
    // This is really a special case.
    $entity->cps_initial_revision = TRUE;
    // Remove all field collections from the first unpublished revision.
    $this->fieldCollectionRemove($entity);

    field_attach_update($this->entity_type, $entity);

    // class Entity provides identifier() but does not provide a similar method for
    // accessing the revision id, so we must use the old entity_extract_ids() here.
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
    // This exists to make it simpler for subclasses to support publishing
    // and Entity API without multiple inheritance.
    parent::hook_entity_insert($entity);
    // We use method_exists rather than is_subclass_of because is_subclass_of
    // does not seem to check interfaces on classes inheriting and so that
    // always returns false.
    if (method_exists($this, 'publish') && $this->getCurrentChangeset()) {
      $this->createUnpublishedRevision($entity);
    }
  }

}
