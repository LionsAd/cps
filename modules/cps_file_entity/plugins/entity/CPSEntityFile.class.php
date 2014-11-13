<?php

/**
 * File class for the CPS Entity management system.
 */
class CPSEntityFile extends CPSEntityPublishableBase {
  /**
   * @{inheritdoc}
   */
  public function unpublish($entity) {
    $entity->published = FILE_NOT_PUBLISHED;
  }

  /**
   * @{inheritdoc}
   */
  public function publish($entity) {
    $entity->published = FILE_PUBLISHED;
  }

  /**
   * @{inheritdoc}
   */
  public function setRevisionFlag($entity, $set_log = TRUE) {
    $entity->new_revision = TRUE;
  }

  /**
   * @{inheritdoc}
   */
  public function unsetRevisionFlag($entity) {
    $entity->new_revision = FALSE;
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

    drupal_write_record('file_managed', $entity->published_revision, 'fid');
  }

  /**
   * @{inheritdoc}
   */
  public function createUnpublishedRevision($entity) {
    // Ignore temporary files:
    if (!$entity->uri) {
      return;
    }
    $scheme = file_uri_scheme($entity->uri);
    if ($scheme == 'temporary') {
      return;
    }

    // We discounted temporary files, but this can actually be a temporary
    // file because file entity creates files in stages. We make the 'live'
    // revision permanent because when it tries to make it permanent it
    // actually makes the unpublished revision permanent. However, this
    // doesn't make the main entry actually permanent until it is resaved,
    // thus keeping the original intent.
    $entity->status = FILE_STATUS_PERMANENT;
    $this->unpublish($entity);
    $entity->log = t('Initial live revision');
    // Make a copy of the initial file for this revision
    $entity->uri = file_unmanaged_copy($entity->uri, $entity->uri, FILE_EXISTS_RENAME);

    unset($entity->vid);
    drupal_write_record('file_managed_revisions', $entity);

    // And ensure that the status of the live version isn't published.
    db_update('file_managed')
      ->fields(array(
        'published' => FILE_NOT_PUBLISHED,
        'uri' => $entity->uri,
        'vid' => $entity->vid,
      ))
      ->condition('fid', $entity->fid)
      ->execute();
    $entity->published_revision = clone($entity);
    parent::createUnpublishedRevision($entity);
  }

  /**
   * @{inheritdoc}
   */
  public function isPublished($entity) {
    return $entity->published != FILE_NOT_PUBLISHED;
  }

}
