<?php

/**
 * Interface to describe how CPSEntity plugin objects are implemented.
 */
interface CPSEntityInterface {
  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_entity_load(&$entities);

  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_entity_presave($entity);

  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_entity_insert($entity);

  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_entity_update($entity);

  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_entity_delete($entity);

  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_field_attach_delete_revision($entity);

  /**
   * Implements a delegated hook_entity_load().
   */
  public function hook_views_query_alter($view, $query);

  /**
   * Set the revision flag forcing the entity to save a new revision.
   *
   * @param object $entity
   *   The entity to work on.
   */
  public function setRevisionFlag($entity);

  /**
   * Unset the revision flag forcing the entity to save an existing revision.
   *
   * @param object $entity
   *   The entity to work on.
   */
  public function unsetRevisionFlag($entity);

  /**
   * Make the specified changeset the current, published revision for the entity.
   *
   * @param object $entity
   *   The entity to work on.
   * @param $changeset_id
   *   The changeset to make published.
   */
  public function makeChangesetRevisionPublished($entity, $changeset_id);

  /**
   * Restore the published data of the entity.
   *
   * When field API writes a revision, it always writes data to the 'current' table.
   * We need to put the correct data back.
   *
   * @param object $entity
   */
  public function restoreEntityData($entity);

  /**
   * Fetch the current changeset.
   *
   * @return string|null
   *   The changeset ID of the current changeset or NULL if there is no changeset
   *   or the current changeset is the 'live' changeset.
   */
  public function getCurrentChangeset();

  /**
   * Render a page that lists revisions.
   *
   * @param object $entity
   *   The entity to render for.
   *
   * @return array
   *   A Drupal renderable array of the page.
   */
  public function revisionsPage($entity);

  /**
   * Reset the entity static cache for this entity type.
   */
  public function resetCache();
}

/**
 * Base class for the CPS Entity management system.
 */
class CPSEntityBase implements CPSEntityInterface {
  /**
   * @var string
   */
  protected $override_changeset = NULL;

  /**
   * @var array
   */
  protected $unpublished_changesets = NULL;

  /**
   * The plugin array from the plugin class's associated .inc file.
   *
   * @var array
   */
  public $plugin = array();

  /**
   * The entity type the plugin is for. This is from the $plugin array.
   *
   * @var string
   */
  public $entity_type = '';

  /**
   * Public constructor.
   *
   * @param $plugin
   */
  public function __construct($plugin) {
    $this->plugin = $plugin;
    $this->entity_type = $plugin['name'];
  }

  // ---------------------------------------------------------------------
  // Entity specific Drupal hooks

  /**
   * @{inheritdoc}
   */
  public function hook_entity_load(&$entities) {
    $changeset = $this->getCurrentChangeset();

    // During a revision reset, we do not override what entity is actually being loaded nor
    // do we add our data to it.
    if (!empty($this->cps_revision_reset)) {
      return;
    }

    // Fetch entity state information for not published changesets OR the current one.
    // We ignore published changesets because those will accrete over time and are generally irrelevant
    // to our needs.
    $keys = array_keys($entities);

    // @todo: should this include the actual 'published' changeset, that has
    // published = 0 so is included in the query.
    $changesets = $this->getUnpublishedChangesets();
    if ($changeset) {
      $changesets[] = $changeset;
    }
    $result = db_query('SELECT * FROM {cps_entity} WHERE entity_type = :entity_type AND entity_id IN (:ids) AND changeset_id IN (:changesets)', array(':entity_type' => $this->entity_type, ':ids' => $keys, ':changesets' => $changesets));
    foreach ($result as $entity_state) {
      $entities[$entity_state->entity_id]->changeset[$entity_state->changeset_id] = $entity_state;
    }

    // We only care further if the current changeset is not the published one.
    if (!$changeset) {
      return;
    }

    $entity_info = entity_get_info($this->entity_type);
    $revision_key = $entity_info['entity keys']['revision'];

    foreach ($entities as $entity) {
      list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);

      // If this entity has a revision set for the current change set,
      // we need to swap it out for the correct revision.
      if (empty($this->cps_revision_load[$this->entity_type][$entity_id]) &&
          !empty($entity->changeset[$changeset]) &&
          $revision_id != $entity->changeset[$changeset]->revision_id) {

        $swap_revision_id = $entity->changeset[$changeset]->revision_id;
        $this->cps_revision_load[$this->entity_type][$entity_id] = TRUE;
        $draft_revisions = entity_load($this->entity_type, array($entity_id), array($revision_key => $swap_revision_id));
        unset($this->cps_revision_load[$this->entity_type][$entity_id]);

        // Swap the entity!
        if (!empty($draft_revisions[$entity_id])) {
          $new_entity = $entities[$entity_id] = $draft_revisions[$entity_id];
          $new_entity->changeset = $entity->changeset;
          // Store the current revision ID so we can tell later if we need to put it back.
          $new_entity->published_revision_id = $revision_id;
          $new_entity->published_revision = clone($entity);
          // Tell entity API not to save this as the default revision:
          $new_entity->default_revision = FALSE;
          $entities[$entity_id] = $new_entity;
        }
        else {
          // This state is invalid, and all we can do is remove the changeset from the list.
          unset($entity->changeset[$changeset]);
        }
      }
    }
  }

  /**
   * @{inheritdoc}
   */
  public function hook_entity_presave($entity) {
    // During a revision reset, we do not do anything to deal with changes.
    if (!empty($this->cps_revision_reset)) {
      return;
    }

    $changeset = $this->getCurrentChangeset();
    $this->unsetRevisionFlag($entity);

    // If we do not support a publishing flag, there is nothing to do on insert.
    if (!$changeset) {
      return;
    }


    // If creating a new entity and the changeset is set,
    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);
    if (!$entity_id) {
      // Make sure the revision being created gets the appropriate log message.
      $this->setRevisionFlag($entity);
    }
    else {
      // This is never the default revision - if we reach here.
      $entity->default_revision = FALSE;
      // Check to see if the current revision id is the changeset revision id.
      // If so, force creating a new revision.
      $published_revision_id = isset($entity->published_revision_id) ? $entity->published_revision_id : $revision_id;
      if (empty($entity->changeset[$changeset])) {
        $this->setRevisionFlag($entity);
        // And make sure we put it back to this revision id after saving the new one.
        if (!isset($entity->published_revision_id)) {
          // If there isn't a published revision this entity was possibly
          // created during a form submission. If so, try $entity->original
          // where Drupal typically puts it.
          if (isset($entity->original)) {
            $entity->published_revision = $entity->original;
            list(,$entity->published_revision_id) = entity_extract_ids($this->entity_type, $entity->original);
          }
          else {
            $entity->published_revision_id = $published_revision_id;
            $entity_info = entity_get_info($this->entity_type);
            $revision_key = $entity_info['entity keys']['revision'];
            $published = entity_load($this->entity_type, array($entity_id), array($revision_key => $published_revision_id));
            $entity->published_revision = reset($published);
          }
        }
      }
    }
  }

  /**
   * @{inheritdoc}
   */
  public function hook_entity_insert($entity) {
    $changeset = $this->getCurrentChangeset();

    // During a revision reset, we do not do anything to deal with changes.
    if (!empty($this->cps_revision_reset)) {
      return;
    }

    // If we are not writing to a changeset, there is nothing to do here.
    if (!$changeset) {
      return;
    }

    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);

    // Store state information.
    db_insert('cps_entity')
      ->fields(array(
        'entity_type' => $this->entity_type,
        'entity_id' => $entity_id,
        'changeset_id' => $changeset,
        'revision_id' => $revision_id,
      ))
      ->execute();
  }

  /**
   * @{inheritdoc}
   */
  public function hook_entity_update($entity) {
    $changeset = $this->getCurrentChangeset();


    // During a revision reset, we do not do anything to deal with changes.
    // Except clean up field collections.
    if (!empty($this->cps_revision_reset)) {
      $this->fieldCollectionSetArchived($entity, $entity->published_revision);
      return;
    }

    // If we are not on a change set, do nothing.
    if (!$changeset) {
      return;
    }

    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);
    // If $revision_id matches the changeset revision ID, we just need to flag this
    // to have the published revision put back at the end.

    // If $revision_id does not match the changeset, we need to force creating a new revision.
    $published_revision_id = isset($entity->published_revision_id) ? $entity->published_revision_id : $revision_id;
    if (!empty($entity->changeset[$changeset]) && $published_revision_id == $entity->changeset[$changeset]->revision_id) {
      // In general this can only happen if there isn't actually a published revision.
      return;
    }

    // When fields are translatable. Translations from the draft revision can
    // end up in the field_data_* tables because field_sql_storage module always
    // writes to both the 'current' and 'revision' tables, and has no concept of
    // draft revisions. To avoid stale/incorrect data in those tables, delete
    // any values that don't belong to the published revision ID.
    if (!empty($entity->published_revision_id) &&  $entity->published_revision_id != $revision_id) {
      // @todo This is going to be broken with a hardcoded reference to 'node' here.
      foreach (field_info_instances('node', $bundle) as $instance) {
        $field = field_info_field($instance['field_name']);
        if (!empty($field['translatable']) && isset($field['storage']['details']['sql'][FIELD_LOAD_CURRENT])) {
          $table = key($field['storage']['details']['sql'][FIELD_LOAD_CURRENT]);
          db_delete($table)
            ->condition('entity_id', $entity_id)
            ->condition('revision_id', $entity->published_revision_id, '<>')
            ->execute();
        }
      }
    }

    // Store state information.
    db_merge('cps_entity')
      ->key(array(
        'entity_type' => $this->entity_type,
        'entity_id' => $entity_id,
        'changeset_id' => $changeset,
      ))
      ->fields(array(
        'revision_id' => $revision_id,
      ))
      ->execute();

    $this->restoreEntityData($entity);
  }

  /**
   * @{inheritdoc}
   */
  public function hook_entity_delete($entity) {
    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);

    db_delete('cps_entity')
      ->condition('entity_type', $this->entity_type)
      ->condition('entity_id', $entity_id)
      ->execute();
  }

  /**
   * @{inheritdoc}
   */
  public function hook_field_attach_delete_revision($entity) {
    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);
    db_delete('cps_entity')
      ->condition('entity_type', $this->entity_type)
      ->condition('revision_id', $revision_id)
      ->condition('entity_id', $entity_id)
      ->execute();

  }

  /**
   * @{inheritdoc}
   */
  public function restoreEntityData($entity) {
    if (!isset($entity->published_revision)) {
      // Not much we can do if this isn't set -- it should always have been set on load.
      return;
    }

    $published_revision = $entity->published_revision;
    // Make sure that file fields don't try funny business.
    $published_revision->original = $published_revision;
    // Restore field API data.
    $published_revision->revision = FALSE;
    field_attach_update($this->entity_type, $published_revision);

    // If the entity itself has to restore its own data, that will need to happen in an
    // override of this method.
  }

 /**
   * @{inheritdoc}
   */
  public function setRevisionFlag($entity, $set_log = TRUE) {
    // This will have to be overridden for any entity that uses an alternative method.
    $entity->revision = TRUE;

    if ($set_log) {
      $changeset = cps_changeset_load($this->getCurrentChangeset());
      if ($changeset) {
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
    $entity->revision = FALSE;
  }

  /**
   * @{inheritdoc}
   */
  public function makeChangesetRevisionPublished($entity_id, $changeset_id) {
    // Find the revision id.
    $revision_id = db_query('SELECT revision_id FROM {cps_entity} WHERE entity_type = :entity_type AND entity_id = :entity_id AND changeset_id = :changeset',
    array(
      ':entity_type' => $this->entity_type,
      ':entity_id' => $entity_id,
      ':changeset' => $changeset_id,
    ))->fetchField();

    // Load that revision.
    $entity_info = entity_get_info($this->entity_type);
    $revision_key = $entity_info['entity keys']['revision'];
    $changeset_revisions = entity_load($this->entity_type, array($entity_id), array($revision_key => $revision_id));
    $entity = $changeset_revisions[$entity_id];

    // Ensure that $entity->original is set with the same data as the revision
    // being saved. This ensures that modules with references to other entities,
    // such as file or field_collection do not attempt to remove referenced
    // items from the published revision.
    $current = $this->getCurrentChangeset();

    // Add the published version of the entity for hook implementations to
    // inspect.
    cps_override_changeset(CPS_PUBLISHED_CHANGESET);
    if (!isset($entity->published_revision)) {
      $entity->published_revision = entity_load_single($this->entity_type, $entity_id);
    }
    $entity->original = $entity;

    // Set the flag to tell our save not to interfere.
    $this->cps_revision_reset = TRUE;

    // Entity API uses a default_revision keyword that needs to be honored to make this revision default.
    $entity->default_revision = TRUE;

    // Save the entity.
    entity_save($this->entity_type, $entity);
    cps_override_changeset($current);

    $this->cps_revision_reset = FALSE;
  }

  /**
   * @{inheritdoc}
   */
  public function getCurrentChangeset() {
    global $cps_override_changeset;
    $changeset = isset($cps_override_changeset) ? $cps_override_changeset : cps_get_current_changeset(TRUE);
    if ($changeset == CPS_PUBLISHED_CHANGESET) {
      // This one is special, we'll make it NULL to make life easier.
      $changeset = NULL;
    }

    return $changeset;
  }

  /**
   * Fetch a list of fields that exist on both the revision and the main table.
   * @return array
   */
  protected function getRevisionFields() {
    $entity_info = entity_get_info($this->entity_type);
    $base_schema = drupal_get_schema($entity_info['base table']);
    $revision_schema = drupal_get_schema($entity_info['revision table']);

    $fields = array();
    foreach ($revision_schema['fields'] as $name => $info) {
      if (isset($base_schema['fields'][$name]) && $name != $entity_info['entity keys']['id'] && $name != $entity_info['entity keys']['revision']) {
        $fields[] = $name;
      }
    }

    return $fields;
  }

  /**
   * @{inheritdoc}
   */
  public function hook_views_query_alter($view, $query) {
    // This is only called if the system has already determined that this entity type
    // is involved in the query, and that a changeset is in use.

    $move_cps_entities = array();
    // Start $index at -1 so it will immediately be 0.
    $index = -1;
    $entity_info = entity_get_info($this->entity_type);
    foreach ($query->table_queue as $alias => &$table_info) {
      // Update $index early because we might continue later.
      $index++;

      // If we reach the cps_entity array item before any candidates, then we
      // do not need to move it.
      if ($table_info['table'] == 'cps_entity') {
        break;
      }

      // Any table named field_data_* is a candidate.
      if (strpos($table_info['table'], 'field_data_') === 0) {
        $relationship = $table_info['relationship'];
        // See if it is our entity type.
        // While it may seem inefficient to check entity type individually,
        // non-revisioned entities might have field_data_* fields and we do NOT want
        // to muck with those by accident!
        $table_data = views_fetch_data($query->relationships[$relationship]['base']);
        if (empty($table_data['table']['entity type']) || $table_data['table']['entity type'] != $this->entity_type) {
          continue;
        }

        // There can be reverse relationships used; if so, CPS can't do anything with them.
        // Detect this and skip:
        if ($table_info['join']->field != 'entity_id') {
          continue;
        }

        // Calculate the revision table name. field_data_ is 11 characters long.
        $new_table_name = 'field_revision_' . substr($table_info['table'], 11);

        // Now add the cps_entity table.
        $cps_entity_table = $this->viewsEnsureCPSTable($query, $relationship);

        // Update the join to use our coalesce
        $revision_field = $entity_info['entity keys']['revision'];
        $table_info['join']->left_table = NULL;
        $table_info['join']->left_field = "COALESCE($cps_entity_table.revision_id, $relationship.$revision_field)";

        // Update the join and the table info to our new table name, and to join on the revision key.
        $table_info['table'] = $new_table_name;
        $table_info['join']->table = $new_table_name;
        $table_info['join']->field = 'revision_id';

        // Finally, if we added the cps entity table we have to move it in the table_queue so that it
        // comes before this field.
        if (empty($move_cps_entities[$cps_entity_table])) {
          $move_cps_entities[$cps_entity_table] = $alias;
        }
      }
    }

    // JOINs must be in order. i.e, any tables you mention in the ON clause of a JOIN
    // must appear prior to that JOIN. Since we're modifying a JOIN in place,
    // and adding a new table, we must ensure that new table appears prior
    // to this one. So we recorded at what index we saw that table, and then
    // use array_splice() to move the cps_entity table join to the right position.
    foreach ($move_cps_entities as $cps_entity_table => $alias) {
      // Find the index of the $alias in table_queue keys.
      $keys = array_keys($query->table_queue);
      $index = array_search($alias, $keys);
      $splice = array($cps_entity_table => $query->table_queue[$cps_entity_table]);
      unset($query->table_queue[$cps_entity_table]);

      // Now move the item to the proper location in the array. Don't use array_splice
      // because that breaks indices.
      $query->table_queue = array_slice($query->table_queue, 0, $index, TRUE) +
        $splice +
        array_slice($query->table_queue, $index, NULL, TRUE);
    }

    $entity_table = $entity_info['base table'];
    $test_fields = $this->getRevisionFields();
    // Go through and look to see if we have to modify fields and filters
    // for $entity_table.status, $entity_table.sticky.
    // but we currently do not use those fields.
    foreach ($query->fields as &$field_info) {
      // Some fields don't actually have tables, meaning they're formulae and
      // whatnot. At this time we are going to ignore those.
      if (empty($field_info['table'])) {
        continue;
      }

      // Dereference the alias into the actual table.
      $table = $query->table_queue[$field_info['table']]['table'];
      if ($table == $entity_table && in_array($field_info['field'], $test_fields)) {
        $relationship = $query->table_queue[$field_info['table']]['alias'];
        $alias = $this->viewsEnsureRevisionTable($query, $relationship);
        if ($alias) {
          // Change the base table to use the revision table instead.
          $field_info['table'] = $alias;
        }
      }
    }

    $relationships = array();
    // Build a list of all relationships that might be for our table.
    foreach ($query->relationships as $relationship => $info) {
      if ($info['base'] == $entity_table) {
        $relationships[] = $relationship;
      }
    }

    // Now we have to go through our where clauses and modify any of our fields.
    foreach ($query->where as &$clauses) {
      foreach ($clauses['conditions'] as &$where_info) {
        // Build a matrix of our possible relationships against fields we need to
        // switch.
        foreach ($relationships as $relationship) {
          foreach ($test_fields as $field) {
            if (is_string($where_info['field']) && $where_info['field'] == "$relationship.$field") {
              $alias = $this->viewsEnsureRevisionTable($query, $relationship);
              if ($alias) {
                // Change the base table to use the revision table instead.
                $where_info['field'] = "$alias.$field";
              }
            }
          }
        }
      }
    }


  }

  /**
   * Add the cps entity table for the current changeset to a query.
   *
   * @param views_plugin_query $query
   *   The views query plugin.
   * @param string $relationship
   *   The name of the relationship.
   *
   * @return string $alias
   *   The alias of the relationship.
   */
  protected function viewsEnsureCPSTable(views_plugin_query_default $query, $relationship) {
    $changeset_id = $this->getCurrentChangeset();
    $changeset = cps_changeset_load($changeset_id);

    if (isset($query->tables[$relationship]['cps_entity'])) {
      return $query->tables[$relationship]['cps_entity']['alias'];
    }

    $table_data = views_fetch_data($query->relationships[$relationship]['base']);

    // Construct the join.
    $join = new views_join();
    $join->definition = array(
      'table' => 'cps_entity',
      'field' => 'entity_id',
      'left_table' => $relationship,
      'left_field' => $table_data['table']['base']['field'],
      'extra' => array(
        array(
          'field' => 'entity_type',
          'value' => $this->entity_type,
        ),
        array(
          'field' => 'changeset_id',
          'value' => $changeset_id,
        )
      ),
      'type' => $changeset->published ? 'INNER' : 'LEFT',
    );

    $join->construct();
    $join->adjusted = TRUE;

    return $query->queue_table('cps_entity', $relationship, $join);
  }

  /**
   * Add the revision table for the entity type for the current changeset to a query.
   *
   * @param views_plugin_query $query
   *   The views query plugin.
   * @param string $relationship
   *   The name of the relationship.
   *
   * @return string $alias
   *   The alias of the relationship.
   */
  protected function viewsEnsureRevisionTable($query, $relationship) {
    // Get the alias for the {cps_entity} table we chain off of in the COALESCE
    $cps_entity_table = $this->viewsEnsureCPSTable($query, $relationship);

    // Get the name of the revision table and revision key.
    $entity_info = entity_get_info($this->entity_type);
    $table = $entity_info['revision table'];
    $field = $entity_info['entity keys']['revision'];

    // Add this table only once.
    if (isset($query->tables[$relationship][$table])) {
      return $query->tables[$relationship][$table]['alias'];
    }

    // Construct the join.
    $join = new views_join();
    $join->definition = array(
      'table' => $table,
      'field' => $field,
      // Making this explicitly null allows $left_table to be a formula
      'left_table' => NULL,
      'left_field' => "COALESCE($cps_entity_table.revision_id, $relationship.$field)",
    );

    $join->construct();
    $join->adjusted = TRUE;

    return $query->queue_table($table, $relationship, $join);
  }

  /**
   * Return a list of revisions with CPS information for the entity.
   *
   * @param object $entity
   *   The entity to find changesets for.
   * @return CPSChangeset[]
   *   An array of changesets, keyed by the revision ID they apply to..
   */
  protected function entityRevisionList($entity) {
    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);
    $live_revision_id = isset($entity->published_revision_id) ? $entity->published_revision_id : $revision_id;

    $entity_info = entity_get_info($this->entity_type);
    $revision_table = $entity_info['revision table'];
    $id_key = $entity_info['entity keys']['id'];
    $revision_key = $entity_info['entity keys']['revision'];

    $query = db_select('cps_entity', 'c')->extend('PagerDefault');
    $query->limit(25);
    $query->join($revision_table, 'r', "c.revision_id = r.$revision_key");
    $query->join('cps_changeset', 'cc', "cc.changeset_id = c.changeset_id");
    $query->fields('c', array('changeset_id', 'revision_id'))
      ->condition('c.entity_type', $this->entity_type)
      ->condition("r.$id_key", $entity_id)
      // We also only include where c.published IS NULL, because if it's set
      // that means the entry was added when a changeset was published to
      // preserve the site state at the time it was published and is not relevant
      // here.
      ->isNull('c.published')
      // the "initial" revision is a special one created to ensure that content
      // isn't accidentally visible on the site until published, and is not
      // relevant to show.
      ->condition('c.changeset_id', 'initial', '<>')
      // Live revision is always first.
      ->orderBy('CASE WHEN c.revision_id = ' . $live_revision_id . ' THEN 0 ELSE 1 END')
      // Then unpublished revisions.
      ->orderBy('CASE WHEN cc.published = 0 THEN 0 ELSE 1 END')
      // Then order by publish date.
      ->orderBy('cc.published', 'DESC')
      // And finally as a backup, by revision id.
      ->orderBy("r.$revision_key", 'DESC');

    $result = $query->execute();

    $keys = array();
    foreach ($result as $revision) {
      $keys[$revision->changeset_id] = $revision->revision_id;
    }

    $changesets = array();
    foreach (cps_changeset_load_multiple(array_keys($keys)) as $changeset_id => $changeset) {
      $changesets[$keys[$changeset_id]] = $changeset;
    }
    return $changesets;
  }

  /**
   * @{inheritdoc}
   */
  public function revisionsPage($entity) {
    list($entity_id, $revision_id, $bundle) = entity_extract_ids($this->entity_type, $entity);

    $live_revision_id = isset($entity->published_revision_id) ? $entity->published_revision_id : $revision_id;

    $uri = entity_uri($this->entity_type, $entity);
    drupal_set_title(t('History for %title', array('%title' => entity_label($this->entity_type, $entity))), PASS_THROUGH);

    $status_options = cps_changeset_get_states();
    $changesets = $this->entityRevisionList($entity);

    $rows = array();
    // System created entities might not have a live revision. If not,
    // create a fake entry to inform the user.
    if (empty($changesets)) {
      $rows[] = array(
        t('System created'),
        t('System created'),
        '<b>' . t('Live') . '</b>',
        ''
      );
    }

    foreach ($changesets as $changeset_revision_id => $changeset) {
      $row = array();
      $changeset_uri = $changeset->uri();
      $row[] = l($changeset->label(), $changeset_uri['path']);
      $row[] = format_username($changeset);

      if ($changeset_revision_id == $live_revision_id) {
        $status = '<b>' . t('Live') . '</b>';
      }
      else {
        $status = $status_options[$changeset->status];
      }

      if (!empty($changeset->published)) {
        $status .= '<br/>' . t('Published on @date', array('@date' => format_date($changeset->published)));
      }

      $row[] = $status;

      $operations = array(
        '#type' => 'container',
        '#attributes' => array('class' => 'cps-changeset-operations'),
        'site' => array(
          '#theme' => 'link',
          '#text' => t('view'),
          '#path' => $uri['path'],
          '#options' => array(
            'query' => array('changeset_id' => $changeset->changeset_id),
            'html' => TRUE,
            'attributes' => array('class' => array('cps-changeset-operation'))
          ),
          '#access' => user_access('preview changesets') || user_access('view changesets') || user_access('administer changesets'),
        ),
      );

      $row[] = drupal_render($operations);

      $rows[] = $row;
    }

    $header = array(t('Site version'), t('Author'), t('Status'), t('Operations'));

    $build['entity_revisions_table'] = array(
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
    );

    // Attach the pager theme.
    $build['entity_revisions_table_pager'] = array('#theme' => 'pager');

    return $build;
  }

  /**
   * @{inheritdoc}
   */
  public function resetCache() {
    $controller = entity_get_controller($this->entity_type);
    $controller->resetCache();
  }

  /**
   * Removes field collections before creating initial unpublished revision.
   *
   * @param $entity
   */
  protected function fieldCollectionRemove($entity) {
    list($id, $vid, $bundle) = entity_extract_ids($this->entity_type, $entity);
    $instances = field_info_instances($this->entity_type, $bundle);
    foreach ($instances as $field_name => $instance) {
      $field = field_info_field($field_name);
      if ($field['type'] == 'field_collection') {
        $entity->{$field_name} = array();
      }
    }
  }

  /**
   * Set the archived flag for field collections during CPS publishing.
   */
  protected function fieldCollectionSetArchived($entity, $published) {
    // field_collection_field_update() does not run most of its logic when
    // the entity is not a new revision.
    // Additionally even if it did run, since we set $entity->original
    // = $entity when publishing, the logic for setting previously referenced
    // field collections would not fire correctly.
    // @todo: always save a new revision both within changesets and when
    // publishing, which should make this code unnecessary.
    list($id, $vid, $bundle) = entity_extract_ids($this->entity_type, $entity);
    $instances = field_info_instances($this->entity_type, $bundle);
    $archived_ids = array();
    foreach ($instances as $field_name => $instance) {
      $field = field_info_field($field_name);
      if ($field['type'] == 'field_collection') {
        $new_items = field_get_items($this->entity_type, $entity, $field_name);
        $old_items = field_get_items($this->entity_type, $published, $field_name);
        // Account for re-ordering of the deltas.
        if ($old_items) {
          foreach ($old_items as $old_item) {
            $archived_ids[$old_item['value']] = $old_item['value'];
          }
        }
        if ($new_items) {
          foreach ($new_items as $new_item) {
            // If this is already published, the archived flag will be correct.
            if (isset($archived_ids[$new_item['value']])) {
              unset($archived_ids[$new_item['value']]);
            }
            // Save the new fc_item to make it default.
            // FC does not care about re-saves of host entity
            // unless a new revision is specified or $item['entity'] == TRUE.
            $fc_entity = field_collection_field_get_entity($new_item);
            $fc_entity->revision = FALSE;
            $fc_entity->default_revision = TRUE;
            $fc_entity->archived = FALSE;

            // Set the flag to tell our save not to interfere.
            // This ensures this works recursively on FCs of FCs.
            // @todo need the previously published version, for this item, too.
            // @todo If we had more than one level of field collections, that
            //       are editable we would need to ensure
            //       fieldCollectionSetArchived is called also for
            //       field_collection_items, but fortunately we don't so this
            //       is fine. (e.g. field_event_standard_rule cannot be edited)
            $this->cps_revision_reset = TRUE;

            // Setup published_revision and original in the same way as when
            // making published.
            if (!isset($fc_entity->published_revision)) {
              $fc_entity->published_revision = entity_load_single('field_collection_item', $fc_entity->internalIdentifier());
            }
            // This is very important as else FC tries to delete the current revision
            // and with that the FC item itself, which we don't want.
            $fc_entity->original = $fc_entity;

            // Save the field collection recursively.
            $fc_entity->save(TRUE);
          }
        }
      }
    }
    if (!empty($archived_ids)) {
      db_update('field_collection_item')
        ->condition('item_id', $archived_ids)
        ->fields(array('archived' => 1))
        ->execute();
    }
  }

  /**
   * Get pending changeset IDs, including the current changeset if one is set.
   */
  function getUnpublishedChangesets() {
    if (!isset($this->unpublished_changesets)) {
      $this->unpublished_changesets = db_query('SELECT changeset_id FROM {cps_changeset} WHERE published = 0')->fetchCol();
    }
    return $this->unpublished_changesets;
  }

}
