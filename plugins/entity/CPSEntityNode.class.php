<?php

/**
 * Node class for the CPS Entity management system.
 */
class CPSEntityNode extends CPSEntityBase {
  public $supports_publishing_flag = TRUE;

  public function unpublish($entity) {
    $entity->status = NODE_NOT_PUBLISHED;
  }

  public function publish($entity) {
    // Entities which have a published flag must implement this specifically.
    $entity->status = NODE_PUBLISHED;
  }

  public function hook_views_query_alter($view, $query) {
    // Invoke the parent to get any field api changes.
    parent::hook_views_query_alter($view, $query);

    $test_fields = array('status', 'comment', 'sticky', 'promote', 'title');
    // Go through and look to see if we have to modify fields and filters
    // for node.status, node.sticky.
    // but we currently do not use those fields.
    foreach ($query->fields as &$field_info) {
      // Some fields don't actually have tables, meaning they're formulae and
      // whatnot. At this time we are going to ignore those.
      if (empty($field_info['table'])) {
        continue;
      }

      // Dereference the alias into the actual table.
      $table = $query->table_queue[$field_info['table']]['table'];
      if ($table == 'node' && in_array($field_info['field'], $test_fields)) {
        $relationship = $query->table_queue[$field_info['table']]['relationship'];
        $alias = $this->views_ensure_revision_table($query, $relationship);
        if ($alias) {
          // Change the base table to use the revision table instead.
          $field_info['table'] = $alias;
        }
      }
    }

    $relationships = array();
    // Build a list of all relationships that might be for our table.
    foreach ($query->relationships as $relationship => $info) {
      if ($info['base'] == 'node') {
        $relationships[] = $relationship;
      }
    }

    // Now we have to go through our where clauses and modify any of our fields.
    foreach ($query->where as $group => &$clauses) {
      foreach ($clauses['conditions'] as &$where_info) {
        // Build a matrix of our possible relationships against fields we need to
        // switch.
        foreach ($relationships as $relationship) {
          foreach ($test_fields as $field) {
            if (is_string($where_info['field']) && $where_info['field'] == "$relationship.$field") {
              $alias = $this->views_ensure_revision_table($query, $relationship);
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

}
