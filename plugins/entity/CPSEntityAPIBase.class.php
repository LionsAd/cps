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
   * Create a published revision in the current changeset.
   *
   * When an new entity is inserted, we actually want two revisions to be saved.
   *  - An unpublished revision in the 'published' changeset, since CPS treats
   *    any entity that's never been published as 'unpublished'.
   *  - A draft revision in the current changeset with the published flag set to
   *  TRUE, this will allow the entity to show up in previews, and for that flag
   *  to be set correctly when the changeset is eventually published.
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

  /**
   * @{inheritdoc}
   */
  public function hook_entity_insert($entity) {
    if ($changeset_id = $this->getCurrentChangeset()) {
      // Store state information.
      // When an entity supports publishing, the first revision has to be stored
      // unpublished so that it does not show up on the live site. However, in
      // the current changeset for previewing and eventual publishing, it should
      // be set to published. Hence for those entity types, store the first
      // revision in the special 'initial' changeset unpublished, then
      // immediately create a draft revision in the current changeset.
      list($entity_id, $revision_id) = entity_extract_ids($this->entity_type, $entity);
      db_insert('cps_entity')
        ->fields(array(
          'entity_type' => $this->entity_type,
          'entity_id' => $entity_id,
          'changeset_id' => $changeset_id,
          'revision_id' => $revision_id,
        ))->execute();
      // does not seem to check interfaces on classes inheriting and so that
      // always returns false.
      if (method_exists($this, 'publish')) {
        $this->createUnPublishedRevision($entity);
      }
    }
  }
}
