<?php

/**
 * Node class for the CPS Entity management system.
 */
class CPSEntityNode extends CPSEntityPublishableBase {
  /**
   * @{inheritdoc}
   */
  public function unpublish($entity) {
    $entity->status = NODE_NOT_PUBLISHED;
  }

  /**
   * @{inheritdoc}
   */
  public function publish($entity) {
    // Entities which have a published flag must implement this specifically.
    $entity->status = NODE_PUBLISHED;
  }

  /**
   * @{inheritdoc}
   */
  public function restoreEntityData($entity) {
    parent::restoreEntityData($entity);
    if (!isset($entity->published_revision)) {
      // This should always have been set on load, if not, just return.
      return;
    }

    drupal_write_record('node', $entity->published_revision, 'nid');
  }

  /**
   * @{inheritdoc}
   */
  public function createUnpublishedRevision($original_entity) {
    // Do all work on a clone!
    $entity = clone $original_entity;
    $entity->original = $original_entity;
    $this->unpublish($entity);
    $entity->log = t('Initial live revision');
    $entity->vid = NULL;
    _node_save_revision($entity, $entity->uid);
    // Make sure the field data for the live revision gets written so that it is
    // not out of sync.
    $entity->revision = TRUE;
    // This is really a special case.
    $entity->cps_initial_revision = TRUE;
    // Remove all field collections from the first unpublished revision.
    $this->fieldCollectionRemove($entity);
    field_attach_update($this->entity_type, $entity);
    db_update('node')
      ->fields(array('vid' => $entity->vid, 'status' => NODE_NOT_PUBLISHED))
      ->condition('nid', $entity->nid)
      ->execute();
    parent::createUnpublishedRevision($entity);
  }

  /**
   * @{inheritdoc}
   */
  public function isPublished($entity) {
    return $entity->status != NODE_NOT_PUBLISHED;
  }
}
