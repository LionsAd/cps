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
  public function isPublished($entity) {
    return $entity->published != FILE_NOT_PUBLISHED;
  }

}
